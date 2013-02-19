<?php

namespace ViewCache;

interface ICachePolicy {
    function check();
}

interface IResourceRepository {
    function exists($key);
    function create($key, $value);
    function read($key);
    function update($key, $value);
    function delete($key);
    function deleteAll();
    function getPath($file);
}

interface ICache {
    function set($key, $value);
    function get($key);
    function invalidate($key);
    function invalidateAll();
}

interface IBuffer {
    function bufferStart();
    function bufferEnd();
    function bufferGet($key);
    function capture($key, $callback);
}

class FileExpirationCachePolicy implements ICachePolicy {
    private $expiration_seconds;
    private $repository;

    function __construct(IResourceRepository $repo, $exp_s) {
        $this->expiration_seconds = $exp_s;
        $this->repository = $repo;
    }

    function check() {
        $file_path = $this->repository->get_last_path();

        //check if difference between file edit time and current time is greater than expiration
        $mtime = null;

        if(file_exists($file_path)) {
            $mtime = filemtime($file_path);
            $now = time();

            if($now - $mtime >= $this->expiration_seconds)
                return false;
        }

        return true;
    }
}

class HttpMethodCachePolicy implements ICachePolicy {
    private $config;

    function __construct($conf) {
        $this->config = $conf;
    }

    public function check() {
        if(isset($this->config[$_SERVER["REQUEST_METHOD"]])) {
            $conf = $this->config[$_SERVER["REQUEST_METHOD"]];
            if(isset($conf["allowed"]) && $conf["allowed"])
                return true;
        }

        return false;
    }
}

class UriRegexCachePolicy implements ICachePolicy {
    private $config;

    function __construct($conf) {
        $this->config = $conf;
    }

    public function check() {
        if(!is_array($this->config)) {
            $tmp = $this->config;
            $this->config = array();
            array_push($this->config, $tmp);
        }

        foreach($this->config as $conf) {
            if(preg_match($conf, $_SERVER["REQUEST_URI"]))
                return true;
        }
        return false;
    }
}

class FileRepository implements IResourceRepository {
    private $repo_dir;
    private $last_path;

    function __construct($dir) {
        if($dir[strlen($dir)-1] == "/")
            $dir = substr($dir, 0, strlen($dir)-1);

        if(is_writable($dir)) {
            $this->repo_dir = $dir;
        } else {
            throw new \Exception("Repository directory is not writeable.");
        }
    }

    function getPath($filename) {
        $this->last_path = $this->repo_dir."/".$this->hash_key($filename);
        return $this->last_path;
    }

    function get_last_path() {
        return $this->last_path;
    }

    function hash_key($key) {
        return md5($key);
    }

    function exists($key) {
        if(file_exists($this->getPath($key)))
            return true;

        return false;
    }

    function create($key, $value) {
        $fp = fopen($this->getPath($key), 'w');
        fwrite($fp, $value);
        fclose($fp);
    }

    function read($key) {
        $path = $this->getPath($key);
        if(file_exists($path)) {
            $fp = fopen($path, 'r');
            $value = fread($fp, filesize($path));
            return $value;
        }

        return null;
    }

    function update($key, $value) {
        $this->create($key, value);
    }

    function delete($key) {
        $path = $this->getPath($key);
        if(strlen($key) > 0 && file_exists($path)) {
            unlink($path);
        }
    }

    function deleteAll() {
        $files = scandir($this->repo_dir);
        $files = array_diff($files, array(".",".."));
        foreach($files as $file) {
            if(strlen($file) > 0) {
                unlink($this->repo_dir."/".$file);
            }
        }
    }
}

abstract class CompressedFileRepository extends FileRepository {
    abstract function compress($value);
    abstract function decompress($value);
}

class GzipFileRepository extends CompressedFileRepository {
    function __construct($dir) {
        parent::__construct($dir);
    }

    function compress($value) {
        $accept = explode(",", $_SERVER["HTTP_ACCEPT_ENCODING"]);

        if(in_array("gzip", $accept) && function_exists("gzencode")) {
            $value = gzencode($value);
        }

        return $value;
    }

    function decompress($value) {
        if(function_exists("gzdecode"))
            $value = gzdecode($value);
        else if(function_exists("gzinflate"))
            $value = gzinflate(substr($value,10,-8));
        else
            throw new \Exception("Browser does not support gzip compression and cannot successfully decode/inflate ouput because neither gzdecode or gzinflate are available.");

        return $value;
    }

    function create($key, $value) {
        $value = $this->compress($value);
        parent::create($key, $value);
    }

    function read($key) {
        $value = parent::read($key);
        $accept = explode(",", $_SERVER["HTTP_ACCEPT_ENCODING"]);
        if(in_array("gzip", $accept)) {
            if($value != null) {
                header('Content-Encoding: gzip');
                header('content-type: text/html; charset: UTF-8');
                header('Content-Length: ' . strlen($value));
                header('Vary: Accept-Encoding');
            }
        }

        return $value;
    }
}

class Cache implements ICache {

    private $repository;
    private $policy;

    function __construct($cache_policy, IResourceRepository $resource_repository) {
        $this->repository = $resource_repository;
        $this->policy = $cache_policy;
    }

    public function get($key) {
        //is key in repository? then send it through policy
        //policy says its expired? delete it otherwise get it
        if($this->repository->exists($key)) {

            if($this->check()) {
                return $this->repository->read($key);
            } else {
                $this->repository->delete($key);
            }
        }

        return null;
    }

    public function check() {
        $check = true;

        if(is_array($this->policy)) {
            foreach($this->policy as $policy) {
                if(!$policy->check()) {
                    $check = false;
                    break;
                }
            }
        } else {
            if(!$this->policy->check()) {
                $check = false;
            }
        }

        return $check;
    }

    public function set($key, $value) {
        $e = $this->repository->exists($key);

        if (!$this->check()) return false;

        //does key exists in repo? do an update, else do an add
        if($e)
            $this->repository->update($key, $value);
        else
            $this->repository->create($key, $value);
    }

    public function invalidate($key) {
        $this->repository->delete($key);
    }

    public function invalidateAll() {
        $this->repository->deleteAll();
    }
}

class BufferedCache extends Cache implements IBuffer {
    private $buffer;

    function __construct(array $cache_policy, IResourceRepository $resource_repository) {
        parent::__construct($cache_policy, $resource_repository);
    }

    public function bufferStart() {
        ob_start();
    }

    public function bufferEnd() {
        ob_end_clean();
    }

    public function bufferGet($key) {
        $this->buffer = ob_get_contents();
        $this->bufferEnd();
        $this->set($key, $this->buffer);
        return $this->buffer;
    }

    public function capture($key, $callback) {
        $this->bufferStart();
        $callback();
        return $this->bufferGet($key);
    }
}

?>
