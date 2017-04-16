<?php

namespace Xpandmedialabs\XPCouch;

use Xpandmedialabs\XPCouch\XPCache;
use Xpandmedialabs\XPCouch\XPException;


class XPFileCache extends XPCache {
  private static $fileExt = ".xp";

  private $fsLocation;


  public function __construct($location) {
    if(!is_dir($location)) {
      throw new CYException("The provided cache location is not a directory.");
    }

    if(!is_readable($location) || !is_writable($location)) {
      throw new CYException("Insufficient privileges to the supplied cache directory.");
    }

    parent::__construct();

    $this->fsLocation = rtrim($location, "/ \t\n\r\0\x0B");

    foreach(glob($this->fsLocation."/*".self::$fileExt) as $file) {
      self::addToSize(filesize($file));
    }
  }

  public function makeFilename($url) {
    return "$this->fsLocation/".self::makeKey($url).self::$fileExt;
  }

  public function set($url, &$item) {
    if(empty($url)) {
      throw new CYException('You need to provide a URL to cache.');
    }

    if(!parent::mayCache($item)) {
      return false;
    }

    $serialized = json_encode($item);
    $target = self::makeFilename($url);

    if(is_file($target)) {
      $oldCopy = self::get($url);
      self::remove($url);
    }

    $fh = fopen($target, "w");

    fwrite($fh, $serialized, strlen($serialized));
    self::addToSize(filesize($target));

    fclose($fh);

    // Only return the $oldCopy if it exists
    return (isset($oldCopy) && is_object($oldCopy)) ? $oldCopy : true;
  }

  public function get($url) {
    $target = self::makeFilename($url);

    if(!is_file($target)) {
      return null;
    }

    if(!is_readable($target)) {
      throw new CYException("Could not read the cache file for $url at $target - please check its permissions.");
    }

    return json_decode(file_get_contents($target));
  }

  public function remove($url) {
    $target = $this->makeFilename($url);
    return self::removeFile($target);
  }

  public function clear() {
    $part = false;

    foreach(glob($this->fsLocation."/*".self::$fileExt) as $file) {
      if(!self::removeFile($file)) {
        $part = true;
      }
    }

    return !$part;
  }

  private function removeFile($path) {
    $size = filesize($path);

    if(!unlink($path)) {
      return false;
    }

    self::addToSize(-$size);

    return true;
  }
}
