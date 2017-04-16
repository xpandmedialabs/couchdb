<?php
namespace CYCouchDB;

class CYCouchException extends \Exception {
  public function __construct($msg = "", $code = 0) {
    parent::__construct("CouchDB Error: $msg", $code);
  }
}
