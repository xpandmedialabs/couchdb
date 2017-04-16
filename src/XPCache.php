<?php

namespace Xpandmedialabs\XPCouch;
use Xpandmedialabs\XPCouch\XPException;
use \Exception;

abstract class XPCache {
  private $maxSize;                                     //in bytes

  private $currentSize;                                 //in bytes

  public function __construct() {
    $this->currentSize = 0;
    $this->maxSize = 1000000;
  }

  abstract public function get($url);

  abstract public function set($url, &$item);

  abstract public function remove($url);

  abstract public function clear();

  public function setSize($bytes) {
    if(!is_int($bytes) || $bytes <= 0) {
      throw new Exception("The cache size must be a positive integer (bytes).");
    }

    $this->maxSize = $bytes;
  }

  public function getSize() {
    return $this->maxSize;
  }

  public function getUsage() {
    return $this->currentSize;
  }

  public function makeKey($url) {
    return sha1($url);
  }

  protected function addToSize($amt) {
    if(!is_int($amt) && !is_float($amt)) {
      throw new XPException('Invalid cache size modifier.');
    }

    $this->currentSize += $amt;
  }
  
  protected function mayCache($item) {
    return (
      isset($item) &&
      is_object($item) &&
      isset($item->headers) &&
      is_string($item->headers->etag) &&
      !empty($item->headers->etag) &&
      isset($item->body) &&
      is_object($item->body)
    );
  }
}
