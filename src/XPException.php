<?php

namespace Xpandmedialabs\XPCouch;

class XPException extends \Exception {
  public function __construct($msg = "", $code = 0) {
    parent::__construct("XP Error: $msg", $code);
  }
}
