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
    function flush();
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

class Cache implements ICache {

    private $repository;
    private $policy;
    private $flushed;

    function __construct($cache_policy, IResourceRepository $resource_repository) {
        $this->repository = $resource_repository;
        $this->policy = $cache_policy;
        $this->flushed = false;
    }

    public function get($key) {
        //is key in repository? then send it through policy
        //policy says its expired? delete it otherwise get it
        if($this->repository->exists($key)) {
            $val = $this->repository->read($key);
            if(strlen($val) > 0) {
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

                if($check || $this->flushed) {
                    return $val;
                } else {
                    $this->repository->delete($key);
                    $this->flushed = true;
                }
            }
        } else {
            $this->flushed = true;
        }

        return null;
    }

    public function set($key, $value) {
        //does key exists in repo? do an update, else do an add
        if($this->repository->exists($key))
            $this->repository->update($key, $value);
        else
            $this->repository->create($key, $value);
    }
    
    public function flush() {
        $this->repository->deleteAll();
        $this->flushed = true;
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
        $this->set($key, $this->buffer);
        return $this->get($key);
    }

    public function bufferGetEnd($key) {
        $out = $this->bufferGet($key);
        $this->bufferEnd();
        return $out;
    }

    public function capture($key, $callback) {
        $this->bufferStart();
        $callback();
        return $this->bufferGetEnd($key);
    }
}

?>
