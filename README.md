php-vcache
==========

Generic light weight PHP caching tool for generating flat view files. For generating and serving static HTML documents. Perfect for high traffic blogging websites or for websites that don't have to serve constantly updating or time critical content.

## Usage:

Place this in your index.php or wherever your application root is.  This was tested with wordpress by entering this code in the index.php file and substituting the commented section below (/*your PHP application starting point here*/) with the original wordpress index.php code.

```php
include "cache.php";

//for gzip support, change this to GzipFileRepository
use \ViewCache\FileRepository as Repository;
//for gzip support, change this to GzipBufferedCache
use \ViewCache\BufferedCache as Cache;
use \ViewCache\FileExpirationCachePolicy as CachePolicy1;
use \ViewCache\HttpMethodCachePolicy as CachePolicy2;
use \ViewCache\UriRegexCachePolicy as CachePolicy3;

$key = $_SERVER["REQUEST_URI"];
$expiration_seconds = 30;
$cache_dir = "cachedir";

$repository = new Repository($cache_dir);
$cache = new Cache(
  array(
    //cache pages for specific amount of seconds
    new CachePolicy1($repository, $expiration_seconds), 
    //only cache pages using certain http method calls
    new CachePolicy2(array("GET"=>array("allowed"=>true))),
    //only cache pages that match the request uri
    new CachePolicy3(array("/p=1/", "/p=2/"))
  ), 
  $repository
);

$page_contents = $cache->get($key);
if($page_contents == null) {
  $cache->bufferStart();
  
  /*
    your PHP application starting point here
  */
  
  $page_contents = $vcache->bufferGet($key);
}

echo $page_contents;
```

## The MIT License (MIT)

Copyright (c) 2013 Ian Herbert

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
