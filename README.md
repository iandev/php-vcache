php-vcache
==========

PHP caching tool for generating flat view files.  Serve static files as much as possible.

## Usage:

```php
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
```

## The MIT License (MIT)

Copyright (c) 2013 Ian Herbert

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
