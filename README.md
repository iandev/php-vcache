php-vcache
==========

PHP caching tool for generating flat view files.  Serve static files as much as possible.

Usage:

<pre>
include "cache.php";

use \ViewCache\FileExpirationCachePolicy as CachePolicy1;
use \ViewCache\FileRepository as Repository;
use \ViewCache\BufferedCache as Cache;
use \ViewCache\HttpMethodCachePolicy as CachePolicy2;

$key = $_SERVER["REQUEST_URI"];
$expiration_seconds = 30;
$cache_dir = "cachedir";

$repository = new Repository($cache_dir);
$cache = new Cache(
  array(
    new CachePolicy1($repository, $expiration_seconds), 
    new CachePolicy2(array("GET"=>array("allowed"=>true)))
  ), 
  $repository
);

$page_content = $cache->get($key);
if($page_content == null) {
  $cache->bufferStart();
  
  /*
    your PHP application starting point here
  */
  
  $page_content = $cache->bufferGetEnd($key);
}

echo $page_content;
</pre>
