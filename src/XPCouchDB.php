<?php

namespace Xpandmedialabs\XPCouch;
use Xpandmedialabs\XPException;
use Xpandmedialabs\XPCouchException;
use Xpandmedialabs\XPCouch\HTTPAdapters\XPNativeHTTPAdapter;
use Xpandmedialabs\XPCouch\HTTPAdapters\XPCURLHTTPAdapter;

class XPCouchDB {

  public static $AUTH_BASIC = "AUTH_BASIC";

  public static $AUTH_COOKIE = "AUTH_COOKIE";

  public static $HTTP_NATIVE_SOCKETS = 'HTTP_NATIVE_SOCKETS';

  public static $HTTP_CURL = 'HTTP_CURL';

  private $db;                          //Database name to hit.
  private $host;                        //IP or address to connect to.
  private $port;                        //Port to connect to.
  private $pathPrefix = '';             //Prepended to URLs.

  private $user;                        //Username to auth with.
  private $pass;                        //Password to auth with.
  private $authType;                    //One of the DCoudhDB::$AUTH_* variables
  private $authSession;                 //AuthSession cookie value from/for CouchDB

  private $cache;

  private $staleDefault;                //Whether or not to use ?stale=ok on all design doc calls

  private $globalCookies = array();

  private $httpAdapter;
  private $httpAdapterType;

  public function __construct($host = "127.0.0.1", $port = "5984")
  {
    $this->host = $host;
    $this->port = $port;

    //sets to the default by ... default
    $this->setHTTPAdapter();
  }

  public function setHTTPAdapter($type = null) {
    if(!$type) {
      $type = extension_loaded("curl") ? self::$HTTP_CURL : self::$HTTP_NATIVE_SOCKETS;
    }

    // nothing to be done
    if($type === $this->httpAdapterType) {
      return true;
    }

    // remember what was already set (ie., might have called decode() already)
    $prevDecode = null;
    $prevTimeouts = null;
    if($this->httpAdapter) {
      $prevDecode = $this->httpAdapter->decodeResp;
      $prevTimeouts = $this->httpAdapter->getTimeouts();
    }

    // the glue
    switch($type) {
      case self::$HTTP_NATIVE_SOCKETS:
        $this->httpAdapter = new XPNativeHTTPAdapter($this->host, $this->port);
        break;

      case self::$HTTP_CURL:
        $this->httpAdapter = new XPCURLHTTPAdapter($this->host, $this->port);
        break;

      default:
        throw XPException("Invalid XPCouchDB HTTP adapter specified: $type");
    }

    // restore previous decode value, if any
    if(is_bool($prevDecode)) {
      $this->httpAdapter->decodeResp = $prevDecode;
    }

    // restore previous timeout vlaues, if any
    if(is_array($prevTimeouts)) {
      $this->httpAdapter->setTimeoutsFromArray($prevTimeouts);
    }

    $this->httpAdapterType = $type;

    return $this;
  }


  public function currentHTTPAdapter() {
    return $this->httpAdapterType;
  }

  public function login($user, $pass, $type = null) {
    if($type == null) {
      $type = XPCouchDB::$AUTH_BASIC;
    }

    $this->authType = $type;

    switch($type) {
      case XPCouchDB::$AUTH_BASIC:
        //these will end up in a header, so don't URL encode them
        $this->user = $user;
        $this->pass = $pass;

        return true;
        break;

      case XPCouchDB::$AUTH_COOKIE:
        $user = urlencode($user);
        $pass = urlencode($pass);

        $res = $this->procPacket(
          'POST',
          '/_session',
          sprintf('name=%s&password=%s', $user, $pass),
          array('Content-Type' => 'application/x-www-form-urlencoded')
        );

        $this->authSession = $res->cookies->AuthSession;

        return $this->authSession;

        break;
    }

    //should never reach this line
    throw new XPException("Unknown auth type for login().");
  }
  public function getSession() {
    return $this->procPacket('GET', '/_session');
  }


  public function decode($decode) {
    if(!is_bool($decode)) {
      throw new XPException('decode() expected a boolean');
    }

    $this->httpAdapter->decodeResp = $decode;

    return $this;
  }

  public function get($url) {
    if(!$this->db) {
      throw new XPException('No database specified');
    }

    //The first char of the URL should be a slash.
    if(strpos($url, '/') !== 0) {
      $url = "/$url";
    }

    $url = "/{$this->db}$url";

    if($this->staleDefault) {
      $url = self::setURLParameter($url, 'stale', 'ok');
    }

    //Deal with cached items
    $response = null;
    if($this->cache) {
      $prevResponse = $this->cache->get($url);

      if($prevResponse) {
        $response = $this->procPacket('GET', $url, null, array('If-None-Match' => $prevResponse->headers->etag));

        if($response->headers->_HTTP->status == 304) {
          //cache hit
          $response->fromCache = true;

          return $prevResponse;
        }

        $this->cache->remove($url);
      }

      unset($prevResponse);
    }

    /*
     * Not caching, or we are caching but there's nothing cached yet, or our
     * cached item is no longer good.
     */
    if(!$response) {
      $response = $this->procPacket('GET', $url);
    }

    if($this->cache) {
      $this->cache->set($url, $response);
    }

    return $response;
  }

  public function head($url) {
    if(!$this->db) {
      throw new XPException('No database specified');
    }

    //The first char of the URL should be a slash.
    if(strpos($url, '/') !== 0) {
      $url = "/$url";
    }

    if($this->staleDefault) {
      $url = self::setURLParameter($url, 'stale', 'ok');
    }

    //we're only asking for the HEAD so no caching is needed
    return $this->procPacket('HEAD', "/{$this->db}$url");
  }

  public function delete($id, $rev)
  {
    if(!$this->db) {
      throw new XPException('No database specified');
    }

    if(!is_string($id) || !is_string($rev) || empty($id) || empty($rev)) {
      throw new XPException('delete() expects two strings.');
    }

    $url = "/{$this->db}/$id";

    if($this->cache) {
      $this->cache->remove($url);
    }

    return $this->procPacket('DELETE', $url.'?rev='.urlencode($rev));
  }


  public function put($id, $data)
  {
    if(!$this->db) {
      throw new XPException('No database specified');
    }

    if(!is_string($id)) {
      throw new XPException('put() expected a string for the doc id.');
    }

    if(!isset($data) || (!is_object($data) && !is_string($data) && !is_array($data))) {
      throw new XPException('put() needs an object for data - are you trying to use delete()?');
    }

    $toSend = (is_string($data)) ? $data : json_encode($data);
    $id = urlencode($id);

    $url = "/{$this->db}/$id";
    $response = $this->procPacket('PUT', $url, $toSend);

    unset($toSend);


    if($this->cache && $response->body->ok) {
      if(is_string($data)) {
        $data = json_decode($data);
      }

      $data->_rev = $response->body->rev;

      $toCache = clone $response;
      $toCache->body = $data;

      $this->cache->set($url, $toCache);

      unset($toCache);
    }

    return $response;
  }

  public function post($data, $path = null) {
    if(!$this->db) {
      throw new XPException('No database specified');
    }

    if(!isset($data) || (!is_string($data) && !is_object($data) && !is_array($data))) {
      throw new XPException('post() needs an object for data.');
    }

    if(!is_string($data)) {
      $data = json_encode($data);
    }

    if(is_string($path) && !empty($path)) {
      if ($path[0] === '/') {
        $path = '/'.urlencode(substr($path, 1));
      } else {
        $path = '/'.urlencode($path);
      }
    }
    else if(isset($path)) {
      throw new XPException('post() needs a string for a path.');
    }

    return $this->procPacket('POST', "/{$this->db}{$path}", $data);
  }


  public function bulk($docs, $allOrNothing = false) {
    if(!$this->db) {
      throw new XPException('No database specified');
    }

    if(!is_array($docs)) {
      throw new XPException('bulk() expects an array for its first argument');
    }

    if(!is_bool($allOrNothing)) {
      throw new XPException('bulk() expects a boolean for its second argument');
    }

    $data = new \StdClass();

    //Only send all_or_nothing if it's non-default (true), saving bandwidth.
    if($allOrNothing) {
      $data->all_or_nothing = $allOrNothing;
    }

    $data->docs = $docs;

    return $this->procPacket("POST", "/{$this->db}/_bulk_docs", json_encode($data));
  }

  public function copy($srcID, $dstID, $dstRev = null) {
    if(!$this->db) {
      throw new XPException('No database specified');
    }

    if(empty($srcID) || !is_string($srcID)) {
      throw new XPException('copy() got an invalid source ID');
    }

    if(empty($dstID) || !is_string($dstID)) {
      throw new XPException('copy() got an invalid destination ID');
    }

    if($dstRev != null && (empty($dstRev) || !is_string($dstRev))) {
      throw new XPException('copy() got an invalid source revision');
    }

    $headers = array(
      "Destination" => "$dstID".(($dstRev) ? "?rev=$dstRev" : "")
    );

    $srcID = urlencode($srcID);
    $response = $this->procPacket('COPY', "/{$this->db}/$srcID", null, $headers);

    return $response;
  }


  public function setDatabase($db, $createIfNotFound = false) {
    if($this->db != $db || $createIfNotFound) {
      if(!is_string($db)) {
        throw new XPException('setDatabase() expected a string.');
      }

      $db = urlencode($db);

      if($createIfNotFound) {
        try {
          self::procPacket('HEAD', "/{$db}");
        }
        catch(XPCouchException $e) {
          if($e->getCode() != 404) {
            throw $e; //these are not the errors that we are looking for
          }

          self::createDatabase($db);
        }
      }

      $this->db = $db;
    }

    return $this;
  }

  public function getAllDocs($incDocs = false, $limit = null, $startKey = null, $endKey = null, $keys = null, $descending = false, $skip = 0) {
    if(!$this->db) {
      throw new XPException('No database specified.');
    }

    $qry = array();

    if($incDocs !== false) {
      if(!is_bool($incDocs)) {
        throw new XPException('getAllDocs() expected a boolean for include_docs.');
      }

      $qry[] = "include_docs=true";
    }

    if(isset($startKey)) {
      if(!is_string($startKey)) {
        throw new XPException('getAllDocs() expected a string for startkey.');
      }

      $qry[] = 'startkey='.urlencode($startKey);
    }

    if(isset($endKey)) {
      if(!is_string($endKey)) {
        throw new XPException('getAllDocs() expected a string for endkey.');
      }

      $qry[] = 'endkey='.urlencode($endKey);
    }

    if(isset($limit)) {
      if(!is_int($limit) || $limit < 0) {
        throw new XPException('getAllDocs() expected a positive integeter for limit.');
      }

      $qry[] = 'limit='.urlencode($limit);
    }

    if($descending !== false) {
      if(!is_bool($descending)) {
        throw new XPException('getAllDocs() expected a boolean for descending.');
      }

      $qry[] = "descending=true";
    }

    if(isset($skip)) {
      if(!is_int($skip) || $skip < 0) {
        throw new XPException('getAllDocs() expected a non-negative integer for skip');
      }

      $qry[] = 'skip=' . urlencode($skip);
    }

    $qry = '?'.implode('&', $qry);

    if(isset($keys))
    {
      if(!is_array($keys)) {
        throw new XPException('getAllDocs() expected an array for the keys.');
      }

      $data = new \StdClass();
      $data->keys = $keys;

      return $this->procPacket('POST', "/{$this->db}/_all_docs$qry", json_encode($data));
    }

    return $this->procPacket('GET', "/{$this->db}/_all_docs$qry");
  }


  public function getAllDatabases() {
    return $this->procPacket('GET', '/_all_dbs');
  }

  public function generateIDs($num = 10) {
    if(!is_int($num) || $num < 0) {
      throw new XPException('generateIDs() expected an integer >= 0.');
    }

    return $this->procPacket('GET', "/_uuids?count=$num");
  }

  public function createDatabase($name) {
    if(empty($name) || !is_string($name)) {
      throw new XPException('createDatabase() expected a valid database name');
    }

    return $this->procPacket('PUT', "/$name");
  }

  public function deleteDatabase($name) {
    if(empty($name) || !is_string($name)) {
      throw new XPException('deleteDatabase() expected a valid database name');
    }

    return $this->procPacket('DELETE', "/$name");
  }

  public function replicate($src, $target, $continuous = false, $createTarget = null, $filter = null, $filterQueryParams = null) {
    if(empty($src) || !is_string($src)) {
      throw new XPException('replicate() is missing a source to replicate from.');
    }

    if(empty($target) || !is_string($target)) {
      throw new XPException('replicate() is missing a target to replicate to.');
    }

    if(!is_bool($continuous)) {
      throw new XPException('replicate() expected a boolean for its third argument.');
    }

    if(isset($createTarget) && !is_bool($createTarget)) {
      throw new XPException('createTarget needs to be a boolean.');
    }

    if(isset($filter)) {
      if(!is_string($filter)) {
        throw new XPException('filter must be the name of a design doc\'s filter function: ddoc/filter');
      }

      if(isset($filterQueryParams) && !is_object($filterQueryParams) && !is_array($filterQueryParams)) {
        throw new XPException('filterQueryParams needs to be an object or an array');
      }
    }

    $data = new \StdClass();
    $data->source = $src;
    $data->target = $target;

    if($continuous) {
      $data->continuous = true;
    }

    if($createTarget) {
      $data->create_target = true;
    }

    if($filter) {
      $data->filter = $filter;

      if($filterQueryParams) {
        $data->query_params = $filterQueryParams;
      }
    }

    return $this->procPacket('POST', '/_replicate', json_encode($data));
  }


  public function compact($viewName = null) {
    return $this->procPacket('POST', "/{$this->db}/_compact".((empty($viewName)) ? '' : "/$viewName"));
  }

  public function setAttachment($name, $data, $contentType, $docID, $rev = null) {
    if(empty($docID)) {
      throw new XPException('You need to provide a document ID.');
    }

    if(empty($name)) {
      throw new XPException('You need to provide the attachment\'s name.');
    }

    if(empty($data)) {
      throw new XPException('You need to provide the attachment\'s data.');
    }

    if(!is_string($data)) {
      throw new XPException('You need to provide the attachment\'s data as a string.');
    }

    if(empty($contentType)) {
      throw new XPException('You need to provide the data\'s Content-Type.');
    }

    return $this->procPacket('PUT', "/{$this->db}/{$docID}/{$name}".(($rev) ? "?rev=".urlencode($rev) : ""), $data, array("Content-Type" => $contentType));
  }


  public function setOpenTimeout($seconds) {
    //the adapter will take care of the validation for us
    $this->httpAdapter->setOpenTimeout($seconds);

    return $this;
  }

  public function setRWTimeout($seconds, $microseconds = 0) {
    $this->httpAdapter->setRWTimeout($seconds, $microseconds);

    return $this;
  }

  public function setCache(&$cacheImpl) {
    if(!($cacheImpl instanceof XPCache)) {
      throw new XPException('That is not a valid cache.');
    }

    $this->cache = $cacheImpl;

    return $this;
  }

  public function getCache() {
    return $this->cache;
  }


  public function currentDatabase() {
    return $this->db;
  }

  public function getStats() {
    return $this->procPacket('GET', '/_stats');
  }

  public function setStaleDefault($stale) {
    if(!is_bool($stale)) {
      throw new XPException('setStaleDefault() expected a boolean argument.');
    }

    $this->staleDefault = $stale;

    return $this;
  }

  public function setCookie($key, $value) {
    if(!$key || !is_string($key)) {
      throw new XPException('Unexpected cookie key.');
    }

    if($value && !is_string($value)) {
        throw new XPException('Unexpected cookie value.');
    }

    if($value) {
      $this->globalCookies[$key] = $value;
    }
    else {
      unset($this->globalCookies[$key]);
    }

    return $this;
  }

  public function getCookie($key) {
    return (!empty($this->globalCookies[$key])) ? $this->globalCookies[$key] : null;
  }


  public function useSSL($use) {
    if(!is_bool($use)) {
      throw new XPException('Excepted a boolean, but got something else.');
    }

    if($use !== $this->usingSSL()) {
      $this->httpAdapter->useSSL($use);
    }

    return $this;
  }


  public function usingSSL() {
    return $this->httpAdapter->usingSSL();
  }


  public function setSSLCert($path) {
    if($path !== null) {
      if(!is_string($path) || !$path) {
        throw new XPException('Invalid file path provided.');
      }

      if(!is_file($path)) {
        throw new XPException('That path does not point to a file.');
      }

      if(!is_readable($path)) {
        throw new XPException('PHP does not have read privileges with that file.');
      }
    }

    $this->httpAdapter->setSSLCert($path);

    return $this;
  }

  public function setPathPrefix($path) {
    if(!is_string($path)) {
      throw new XPException('Invalid URL path prefix - must be a string.');
    }

    $this->pathPrefix = $path;

    return $this;
  }

  public function getPathPrefix() {
    return $this->pathPrefix;
  }

  private function procPacket($method, $url, $data = null, $headers = array()) {


    if($data && !is_string($data)) {
      throw new XPException('Unexpected data format. Please report this bug.');
    }

    if($this->pathPrefix && is_string($this->pathPrefix)) {
      $url = $this->pathPrefix . $url;
    }

    $headers['Expect'] = isset($headers['Expect']) ? $headers['Expect'] : null;
    if(!$headers['Expect']){


        $headers['Expect'] = (isset($headers['expect']) && $headers['expect']) ? $headers['expect'] : ' '; //1 char string, so it's == to true
    }

    if(strtolower($headers['Expect']) === '100-continue') {
      throw new XPException('XP does not support HTTP/1.1\'s Continue.');
    }


    $headers["Host"] = "{$this->host}:{$this->port}";
    $headers["User-Agent"] = "XP/%VERSION%";


    $headers['Accept'] = 'application/json';


    if($this->authType == XPCouchDB::$AUTH_BASIC && (isset($this->user) || isset($this->pass))) {
      $headers["Authorization"] = 'Basic '.base64_encode("{$this->user}:{$this->pass}");
    }
    elseif($this->authType == XPCouchDB::$AUTH_COOKIE && isset($this->authSession)) {
      $headers['Cookie'] = array( 'AuthSession' => $this->authSession );
      $headers['X-CouchDB-WWW-Authenticate'] = 'Cookie';
    }

    if(is_array($this->globalCookies) && sizeof($this->globalCookies)) {

      if($headers['Cookie']) {
        $headers['Cookie'] = array_merge($headers['Cookie'], $this->globalCookies);
      }
      else {
        $headers['Cookie'] = $this->globalCookies;
      }
    }

    if(!empty($headers['Cookie'])) {
      $buff = '';

      foreach($headers['Cookie'] as $k => $v) {
        $buff = (($buff) ? ' ' : '') . "$k=$v;";
      }

      $headers['Cookie'] = $buff;
      unset($buff);
    }

    if(!isset($headers['Content-Type'])) {
      $headers['Content-Type'] = 'application/json';
    }

    if($data) {
      $headers['Content-Length'] = strlen($data);
    }

    return $this->httpAdapter->procPacket($method, $url, $data, $headers);
  }

  private function setURLParameter($url, $key, $value) {
    $url = parse_url($url);

    if(!empty($url['query'])) {
      parse_str($url['query'], $params);
    }
    $params[$key] = $value;

    return $url = $url['path'].'?'.http_build_query($params);
  }
}
