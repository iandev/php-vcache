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
    function flush();
}

interface IBuffer {
    function bufferStart();
    function bufferEnd();
    function bufferGet($key);
    function bufferSave($key);
    function capture($key, $callback);
}

interface ILogger {
    function log($msg, $type);
}

final class LogType {
    private function __construct() {}
    const GENERAL = "GENERAL";
    const NOTICE = "NOTICE";
    const WARNING = "WARNING";
    const ERROR = "ERROR";
    const SEVERE = "SEVERE";
}

class FileLogger implements ILogger {
    private $logfile;

    public function __construct($file) {
        if (is_writable($file))
            $this->logfile = $file;
        else
            $this->logfile = null;
    }

    public function log($msg, $type) {
        if($this->logfile == null) return;

        $msg = date("F j, Y, g:i a") . " --- " . $type . " --- " . $msg . "\n";
        $fp = fopen($this->logfile, 'a');
        fwrite($fp, $msg);
        fclose($fp);
    }
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

        if(function_exists("gzencode") && (function_exists("gzdecode") || function_exists("gzinflate")))
            $value = gzencode($value);
        else
            throw new \Exception("Cannot gzip encode the output, please turn off gzip compression.");

        return $value;
    }

    function decompress($value) {
        if(function_exists("gzdecode"))
            $value = gzdecode($value);
        else if(function_exists("gzinflate"))
            $value = gzinflate(substr($value,10,-8));
        else
            throw new \Exception("Cannot successfully decode/inflate ouput because neither gzdecode or gzinflate are available.  Turn off gzip compression and flush the cache.");

        return $value;
    }

    function create($key, $value) {
        $value = $this->compress($value);
        parent::create($key, $value);
    }

    function read($key) {
        $value = parent::read($key);
        $accept = explode(",", $_SERVER["HTTP_ACCEPT_ENCODING"]);
        if ($value != null && in_array("gzip", $accept)) {
            header('Content-Encoding: gzip');
            header('content-type: text/html; charset: UTF-8');
            header('Content-Length: ' . strlen($value));
            header('Vary: Accept-Encoding');
        }
        else {
            //check if file really is gziped(binary)
            //and if so, decompress it
            $path = $this->getPath($key);
            $finfo = finfo_open(FILEINFO_MIME);
            $mime = finfo_file($finfo, $path);
            if(preg_match("/binary/", $mime) &&
                preg_match("/x-gzip/", $mime)) {

                try {
                    $value = $this->decompress($value);
                } catch (\Exception $e) {
                    //this should not happen
                    throw new Exception("Got gzip compressed file, users browser doesnt support compression, and was unable to decompress it, this should logically not occur. " . $e->getMessage());
                }
            }
        }

        return $value;
    }
}

class Cache implements ICache {

    private $repository;
    private $policy;
    private $logger;

    function __construct($cache_policy, IResourceRepository $resource_repository, ILogger $logger) {
        $this->repository = $resource_repository;
        $this->policy = $cache_policy;
        $this->logger = $logger;
    }

    public function get($key) {
        try {
            //is key in repository? then send it through policy
            //policy says its expired? delete it otherwise get it
            if($this->repository->exists($key)) {

                if($this->check()) {
                    return $this->repository->read($key);
                } else {
                    $this->invalidate($key);
                }
            }
        } catch(\Exception $e) {
            $this->logger->log($e->getMessage(), LogType::ERROR);
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
        try {
            if (!$this->check()) return false;

            //does key exists in repo? do an update, else do an add
            if($this->repository->exists($key))
                $this->repository->update($key, $value);
            else
                $this->repository->create($key, $value);
        } catch(\Exception $e) {
            $this->logger->log($e->getMessage(), LogType::ERROR);
        }
    }

    public function invalidate($key) {
        $this->repository->delete($key);
    }

    public function flush() {
        $this->repository->deleteAll();
    }
}

class BufferedCache extends Cache implements IBuffer {
    private $buffer;

    function __construct(array $cache_policy, IResourceRepository $resource_repository, ILogger $logger) {
        parent::__construct($cache_policy, $resource_repository, $logger);
    }

    public function bufferStart() {
        ob_start();
    }

    public function bufferEnd() {
        ob_end_clean();
    }

    public function bufferGet($key) {
        $this->buffer = ob_get_contents();
        return $this->buffer;
    }

    public function bufferSave($key) {
        $this->set($key, $this->buffer);
    }

    public function bufferGetEndSave($key) {
        $this->bufferGet($key);
        $this->bufferEnd();
        $this->bufferSave($key);
        return $this->buffer;
    }

    public function capture($key, $callback) {
        $this->bufferStart();
        $callback();
        return $this->bufferGet($key);
    }
}

?>
