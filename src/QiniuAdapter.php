<?php namespace silentred\QiniuStorage;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Adapter\Polyfill\StreamedReadingTrait;
use League\Flysystem\Adapter\Polyfill\StreamedWritingTrait;
use League\Flysystem\Config;
use Qiniu\Auth;
use Qiniu\Http\Error;
use Qiniu\Processing\Operation;
use Qiniu\Processing\PersistentFop;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;

class QiniuAdapter extends AbstractAdapter
{

    use NotSupportingVisibilityTrait, StreamedWritingTrait, StreamedReadingTrait;

    private $access_key = null;
    private $secret_key = null;
    private $bucket = null;
    private $toBucket = null;
    private $buckets = [];
    private $domain = null;
    private $notify_url = null; //持久化处理后的回调地址

    private $auth = null;
    private $upload_manager = null;
    private $bucket_manager = null;
    private $operation = null;

    private $prefixedDomains = [];

    public function __construct($access_key, $secret_key, $buckets, $notify_url = null)
    {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->buckets = $buckets;
        // set bucket and default domain
        $this->resolveBuckets($buckets);

        $this->setPathPrefix('http://'.$this->domain.'/');
        $this->notify_url = $notify_url;
    }

    private function resolveBuckets($buckets){
        if (count($buckets) === 0) {
            throw new \Exception('qiniu buckets is empty');
        }

        $first_bucket = reset($buckets);
        $this->bucket = $first_bucket['name'];
        $this->toBucket = $this->bucket;
        $this->domain = $first_bucket['domain'];
    }

    public function withBucket($key){
        if(array_key_exists($key, $this->buckets)){
            $this->bucket = $this->buckets[$key]['name'];
            $this->toBucket = $this->bucket;
            $this->domain = $this->buckets[$key]['domain'];
            $this->setPathPrefix('http://'.$this->domain);
        }

        return $this;

    }

    public function toBucket($key) {
        if(array_key_exists($key, $this->buckets)){
            $this->toBucket= $this->buckets[$key]['name'];
        }

        return $this;
    }

    private function getAuth()
    {
        if ($this->auth == null) {
            $this->auth = new Auth($this->access_key, $this->secret_key);
        }

        return $this->auth;
    }

    private function getUploadManager()
    {
        if ($this->upload_manager == null) {
            $this->upload_manager = new UploadManager();
        }

        return $this->upload_manager;
    }

    private function getBucketManager()
    {
        if ($this->bucket_manager == null) {
            $this->bucket_manager = new BucketManager($this->getAuth());
        }

        return $this->bucket_manager;
    }

    private function getOperation()
    {
        if ($this->operation == null) {
            $this->operation = new Operation($this->domain);
        }

        return $this->operation;
    }

    private function logQiniuError(Error $error, $extra = null)
    {
        \Log::error('Qiniu: ' . $error->message() . '. ' . $extra);
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        $auth = $this->getAuth();
        $token = $auth->uploadToken($this->bucket, $path);

        $params = $config->get('params', null);
        $mime = $config->get('mime', 'application/octet-stream');
        $checkCrc = $config->get('checkCrc', false);

        $upload_manager = $this->getUploadManager();
        list($ret, $error) = $upload_manager->put($token, $path, $contents, $params, $mime, $checkCrc);

        if ($error !== null) {
            $this->logQiniuError($error);

            return false;
        } else {
            return $ret;
        }
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        $bucketMgr = $this->getBucketManager();

        if (is_null($this->toBucket)) {
            $this->toBucket = $this->bucket;
        }
        list($ret, $error) = $bucketMgr->move($this->bucket, $path, $this->toBucket, $newpath);
        if ($error !== null) {
            $this->logQiniuError($error);

            return false;
        } else {
            return true;
        }
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $bucketMgr = $this->getBucketManager();

        if (is_null($this->toBucket)) {
            $this->toBucket = $this->bucket;
        }
        list($ret, $error) = $bucketMgr->copy($this->bucket, $path, $this->toBucket, $newpath);
        if ($error !== null) {
            $this->logQiniuError($error);

            return false;
        } else {
            return true;
        }
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $bucketMgr = $this->getBucketManager();

        $error = $bucketMgr->delete($this->bucket, $path);
        if ($error !== null) {
            $this->logQiniuError($error, $this->bucket . '/' . $path);

            return false;
        } else {
            return true;
        }
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $files = $this->listContents($dirname);
        foreach ($files as $file) {
            $this->delete($file['path']);
        }

        return true;
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        return ['path' => $dirname];
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        $meta = $this->getMetadata($path);
        if ($meta) {
            return true;
        }

        return false;
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        $location = $this->applyPathPrefix($path);

        return array('contents' => file_get_contents($location));
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $bucketMgr = $this->getBucketManager();

        list($items, $marker, $error) = $bucketMgr->listFiles($this->bucket, $directory);
        if ($error !== null) {
            $this->logQiniuError($error);

            return array();
        } else {
            $contents = array();
            foreach ($items as $item) {
                $normalized = [
                    'type'      => 'file',
                    'path'      => $item['key'],
                    'timestamp' => $item['putTime']
                ];

                if ($normalized['type'] === 'file') {
                    $normalized['size'] = $item['fsize'];
                }

                array_push($contents, $normalized);
            }

            return $contents;
        }
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        $bucketMgr = $this->getBucketManager();

        list($ret, $error) = $bucketMgr->stat($this->bucket, $path);
        if ($error !== null) {
            $this->logQiniuError($error);

            return false;
        } else {
            return $ret;
        }
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        $stat = $this->getMetadata($path);
        if ($stat) {
            return array('size' => $stat['fsize']);
        }

        return false;
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        $stat = $this->getMetadata($path);
        if ($stat) {
            return array('mimetype' => $stat['mimeType']);
        }

        return false;
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        $stat = $this->getMetadata($path);
        if ($stat) {
            return array('timestamp' => $stat['putTime']);
        }

        return false;
    }

    public function downloadUrl($path = null, $bucket = null)
    {
        if (!is_null($bucket)) {
            $this->withBucket($bucket);
        }
        $location = $this->applyPathPrefix($path);

        return $location;
    }

    public function privateDownloadUrl($path, $bucket = null)
    {
        if (!is_null($bucket)) {
            $this->withBucket($bucket);
        }
        $auth = $this->getAuth();
        $location = $this->applyPathPrefix($path);
        $authUrl = $auth->privateDownloadUrl($location);

        return $authUrl;
    }

    public function persistentFop($path = null, $fops = null, $pipline = null, $force = false)
    {
        $auth = $this->getAuth();

        $pfop = New PersistentFop($auth, $this->bucket, $pipline, $this->notify_url, $force);

        list($id, $error) = $pfop->execute($path, $fops);

        if ($error != null) {
            $this->logQiniuError($error);

            return false;
        } else {
            return $id;
        }
    }

    public function persistentStatus($id)
    {
        return PersistentFop::status($id);
    }

    public function imageInfo($path = null)
    {
        $operation = $this->getOperation();

        list($ret, $error) = $operation->execute($path, 'imageInfo');

        if ($error !== null) {
            $this->logQiniuError($error);

            return false;
        } else {
            return $ret;
        }
    }

    public function imageExif($path = null)
    {
        $operation = $this->getOperation();

        list($ret, $error) = $operation->execute($path, 'exif');

        if ($error !== null) {
            $this->logQiniuError($error);

            return false;
        } else {
            return $ret;
        }
    }

    public function imagePreviewUrl($path = null, $ops = null)
    {
        $operation = $this->getOperation();
        $url = $operation->buildUrl($path, $ops);

        return $url;
    }

    public function uploadToken(
        $path = null,
        $expires = 3600,
        $policy = null,
        $strictPolicy = true
    ) {
        $auth = $this->getAuth();

        $token = $auth->uploadToken(
            $this->bucket,
            $path,
            $expires,
            $policy,
            $strictPolicy
        );

        return $token;
    }
}