<?php

namespace Xpandmedialabs\XPCouch;
use Xpandmedialabs\XPCouch\XPCache;
use Xpandmedialabs\XPCouch\XPException;


class XPMemoryCache extends XPCache {
  private $cache;

  public function __construct() {
    parent::__construct();
    $this->cache = array();
  }

  public function set($url, &$item) {
    if(empty($url)) {
      throw new XPException('You need to provide a URL to cache.');
    }

    if(!parent::mayCache($item)) {
      return false;
    }

    if(isset($this->cache[$url])) {
      $oldCopy = json_decode($this->cache[$url]);
      self::remove($url);
    }

    $this->cache[$url] = json_encode($item);

    return (isset($oldCopy) && is_object($oldCopy)) ? $oldCopy : true;
  }

  public function get($url) {
    return (isset($this->cache[$url])) ? json_decode($this->cache[$url]) : null;
  }

  public function remove($url) {
    unset($this->cache[$url]);

    return true;
  }

  public function clear() {
    unset($this->cache);
    $this->cache = array();

    return true;
  }

  public function setSize($bytes) {
    throw new XPException('Cache sizes are not supported in XPMemoryCache - caches have infinite size.');
  }

  public function getSize() {
    throw new XPException('Cache sizes are not supported in XPMemoryCache - caches have infinite size.');
  }

  public function getUsage() {
    throw new XPException('Cache sizes are not supported in XPMemoryCache - caches have infinite size.');
  }
}
