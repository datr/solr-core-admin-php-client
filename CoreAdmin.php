<?php

require_once('SolrPhpClient/Apache/Solr/Service.php');

class Apache_Solr_Core_Admin
{
  /**
   * Response writer we'll request - JSON. See http://code.google.com/p/solr-php-client/issues/detail?id=6#c1 for reasoning
   */
  const SOLR_WRITER = 'json';

  /**
   * NamedList Treatment constants
   */
  const NAMED_LIST_FLAT = 'flat';
  const NAMED_LIST_MAP = 'map';

  /**
   * Search HTTP Methods
   */
  const METHOD_GET = 'GET';
  const METHOD_POST = 'POST';

  /**
   * Server identification strings
   *
   * @var string
   */
  protected $_host, $_port, $path;

  /**
   * Whether {@link Apache_Solr_Response} objects should create {@link Apache_Solr_Document}s in
   * the returned parsed data
   *
   * @var boolean
   */
  protected $_createDocuments = true;

  /**
   * Whether {@link Apache_Solr_Response} objects should have multivalue fields with only a single value
   * collapsed to appear as a single value would.
   *
   * @var boolean
   */
  protected $_collapseSingleValueArrays = true;

  /**
   * How NamedLists should be formatted in the output.  This specifically effects facet counts. Valid values
   * are {@link Apache_Solr_Service::NAMED_LIST_MAP} (default) or {@link Apache_Solr_Service::NAMED_LIST_FLAT}.
   *
   * @var string
   */
  protected $_namedListTreatment = self::NAMED_LIST_MAP;

  /**
   * Query delimiters. Someone might want to be able to change
   * these (to use &amp; instead of & for example), so I've provided them.
   *
   * @var string
   */
  protected $_queryDelimiter = '?', $_queryStringDelimiter = '&', $_queryBracketsEscaped = true;

  /**
   * Constructed servlet full path URLs
   *
   * @var string
   */
  protected $_coreAdminUrl;

  /**
   * Keep track of whether our URLs have been constructed
   *
   * @var boolean
   */
  protected $_urlsInited = false;

  /**
   * HTTP Transport implementation (pluggable)
   *
   * @var Apache_Solr_HttpTransport_Interface
   */
  protected $_httpTransport = false;


  /**
   * Constructor. All parameters are optional and will take on default values
   * if not specified.
   *
   * @param string $host
   * @param string $port
   * @param string $path
   * @param Apache_Solr_HttpTransport_Interface $httpTransport
   *
   * @todo change path to solr path and set admin/cores as a servlet url to more
   *       closely match implementation in solr-php-client.
   */
  public function __construct($host = 'localhost', $port = 8180, $path = '/solr/admin/cores', $httpTransport = false)
  {
    $this->setHost($host);
    $this->setPort($port);
    $this->setPath($path);

    $this->_initUrls();

    if ($httpTransport)
    {
      $this->setHttpTransport($httpTransport);
    }

    // check that our php version is >= 5.1.3 so we can correct for http_build_query behavior later
    $this->_queryBracketsEscaped = version_compare(phpversion(), '5.1.3', '>=');
  }

  /**
   * Return a valid http URL given this server's host, port and path and a provided servlet name
   *
   * @return string
   */
  protected function _constructUrl($params = array())
  {
    if (count($params))
    {
      //escape all parameters appropriately for inclusion in the query string
      $escapedParams = array();

      foreach ($params as $key => $value)
      {
        $escapedParams[] = urlencode($key) . '=' . urlencode($value);
      }

      $queryString = $this->_queryDelimiter . implode($this->_queryStringDelimiter, $escapedParams);
    }
    else
    {
      $queryString = '';
    }

    return 'http://' . $this->_host . ':' . $this->_port . $this->_path . $queryString;
  }

  /**
   * Construct the Full URLs for the three servlets we reference
   */
  protected function _initUrls()
  {
    //Initialize our full servlet URLs now that we have server information
    $this->_coreAdminUrl = $this->_constructUrl();

    $this->_urlsInited = true;
  }

  protected function _generateQueryString($params)
  {
    // use http_build_query to encode our arguments because its faster
    // than urlencoding all the parts ourselves in a loop
    //
    // because http_build_query treats arrays differently than we want to, correct the query
    // string by changing foo[#]=bar (# being an actual number) parameter strings to just
    // multiple foo=bar strings. This regex should always work since '=' will be urlencoded
    // anywhere else the regex isn't expecting it
    //
    // NOTE: before php 5.1.3 brackets were not url encoded by http_build query - we've checked
    // the php version in the constructor and put the results in the instance variable. Also, before
    // 5.1.2 the arg_separator parameter was not available, so don't use it
    if ($this->_queryBracketsEscaped)
    {
      $queryString = http_build_query($params, null, $this->_queryStringDelimiter);
      return preg_replace('/%5B(?:[0-9]|[1-9][0-9]+)%5D=/', '=', $queryString);
    }
    else
    {
      $queryString = http_build_query($params);
      return preg_replace('/\\[(?:[0-9]|[1-9][0-9]+)\\]=/', '=', $queryString);
    }
  }

  /**
   * Returns the set host
   *
   * @return string
   */
  public function getHost()
  {
    return $this->_host;
  }

  /**
   * Set the host used. If empty will fallback to constants
   *
   * @param string $host
   *
   * @throws Apache_Solr_InvalidArgumentException If the host parameter is empty
   */
  public function setHost($host)
  {
    //Use the provided host or use the default
    if (empty($host))
    {
      throw new Apache_Solr_InvalidArgumentException('Host parameter is empty');
    }
    else
    {
      $this->_host = $host;
    }

    if ($this->_urlsInited)
    {
      $this->_initUrls();
    }
  }

  /**
   * Get the set port
   *
   * @return integer
   */
  public function getPort()
  {
    return $this->_port;
  }

  /**
   * Set the port used. If empty will fallback to constants
   *
   * @param integer $port
   *
   * @throws Apache_Solr_InvalidArgumentException If the port parameter is empty
   */
  public function setPort($port)
  {
    //Use the provided port or use the default
    $port = (int) $port;

    if ($port <= 0)
    {
      throw new Apache_Solr_InvalidArgumentException('Port is not a valid port number');
    }
    else
    {
      $this->_port = $port;
    }

    if ($this->_urlsInited)
    {
      $this->_initUrls();
    }
  }

  /**
   * Get the set path.
   *
   * @return string
   */
  public function getPath()
  {
    return $this->_path;
  }

  /**
   * Set the path used. If empty will fallback to constants
   *
   * @param string $path
   */
  public function setPath($path)
  {
    $path = trim($path, '/');

    $this->_path = '/' . $path;

    if ($this->_urlsInited)
    {
      $this->_initUrls();
    }
  }

  /**
   * Get the current configured HTTP Transport
   *
   * @return HttpTransportInterface
   */
  public function getHttpTransport()
  {
    // lazy load a default if one has not be set
    if ($this->_httpTransport === false)
    {
      require_once('SolrPhpClient/Apache/Solr/HttpTransport/FileGetContents.php');

      $this->_httpTransport = new Apache_Solr_HttpTransport_FileGetContents();
    }

    return $this->_httpTransport;
  }

  /**
   * Set the HTTP Transport implemenation that will be used for all HTTP requests
   *
   * @param Apache_Solr_HttpTransport_Interface
   */
  public function setHttpTransport(Apache_Solr_HttpTransport_Interface $httpTransport)
  {
    $this->_httpTransport = $httpTransport;
  }

  /**
   * Set the create documents flag. This determines whether {@link Apache_Solr_Response} objects will
   * parse the response and create {@link Apache_Solr_Document} instances in place.
   *
   * @param boolean $createDocuments
   */
  public function setCreateDocuments($createDocuments)
  {
    $this->_createDocuments = (bool) $createDocuments;
  }

  /**
   * Get the current state of teh create documents flag.
   *
   * @return boolean
   */
  public function getCreateDocuments()
  {
    return $this->_createDocuments;
  }
  
  /**
   * Set the collapse single value arrays flag.
   *
   * @param boolean $collapseSingleValueArrays
   */
  public function setCollapseSingleValueArrays($collapseSingleValueArrays)
  {
    $this->_collapseSingleValueArrays = (bool) $collapseSingleValueArrays;
  }

  /**
   * Get the current state of the collapse single value arrays flag.
   *
   * @return boolean
   */
  public function getCollapseSingleValueArrays()
  {
    return $this->_collapseSingleValueArrays;
  }

  /**
   * Central method for making a get operation against this Solr Server
   *
   * @param string $url
   * @param float $timeout Read timeout in seconds
   * @return Apache_Solr_Response
   *
   * @throws Apache_Solr_HttpTransportException If a non 200 response status is returned
   */
  protected function _sendRawGet($url, $timeout = FALSE)
  {
    $httpTransport = $this->getHttpTransport();

    $httpResponse = $httpTransport->performGetRequest($url, $timeout);
    $solrResponse = new Apache_Solr_Response($httpResponse, $this->_createDocuments, $this->_collapseSingleValueArrays);

    if ($solrResponse->getHttpStatus() != 200)
    {
      throw new Apache_Solr_HttpTransportException($solrResponse);
    }

    return $solrResponse;
  }
  
  /**
   * Central method for making a post operation against this Solr Server
   *
   * @param string $url
   * @param string $rawPost
   * @param float $timeout Read timeout in seconds
   * @param string $contentType
   * @return Apache_Solr_Response
   *
   * @throws Apache_Solr_HttpTransportException If a non 200 response status is returned
   */
  protected function _sendRawPost($url, $rawPost, $timeout = FALSE, $contentType = 'text/xml; charset=UTF-8')
  {
    $httpTransport = $this->getHttpTransport();

    $httpResponse = $httpTransport->performPostRequest($url, $rawPost, $contentType, $timeout);
    $solrResponse = new Apache_Solr_Response($httpResponse, $this->_createDocuments, $this->_collapseSingleValueArrays);

    if ($solrResponse->getHttpStatus() != 200)
    {
      throw new Apache_Solr_HttpTransportException($solrResponse);
    }

    return $solrResponse;
  }

  /**
   * Get the status of a given core or all cores if no core is specified.
   *
   * @param $core
   *
   * @todo If a core is specified return just the core object not an array
   *       of it.
   */
  public function status($core = '', $method = self::METHOD_GET) {
    // common parameters in this interface
    $params['wt'] = self::SOLR_WRITER;
    $params['json.nl'] = $this->_namedListTreatment;

    $params['action'] = 'STATUS';
    $params['core'] = $core;

    $queryString = $this->_generateQueryString($params);

    if ($method == self::METHOD_GET)
    {
      $response = $this->_sendRawGet($this->_coreAdminUrl . $this->_queryDelimiter . $queryString);
    }
    else if ($method == self::METHOD_POST)
    {
      $response = $this->_sendRawPost($this->_coreAdminUrl, $queryString, FALSE, 'application/x-www-form-urlencoded; charset=UTF-8');
    }
    else
    {
      throw new Apache_Solr_InvalidArgumentException("Unsupported method '$method', please use the Apache_Solr_Service::METHOD_* constants");
    }

    return (array) $response->__get('status');
  }

  public function create($params = array()) {
    if (empty($params['name'])) {
      throw new Apache_Solr_InvalidArgumentException("name is a required parameter for creating a new core.");
    }

    if (empty($params['instanceDir'])) {
      throw new Apache_Solr_InvalidArgumentException("instanceDir is a required parameter for creating a new core.");
    }

    $params['action'] = 'CREATE';

    $queryString = $this->_generateQueryString($params);

    if ($method == self::METHOD_GET)
    {
      $this->_sendRawGet($this->_coreAdminUrl . $this->_queryDelimiter . $queryString);
    }
    else if ($method == self::METHOD_POST)
    {
      $this->_sendRawPost($this->_coreAdminUrl, $queryString, FALSE, 'application/x-www-form-urlencoded; charset=UTF-8');
    }
    else
    {
      throw new Apache_Solr_InvalidArgumentException("Unsupported method '$method', please use the Apache_Solr_Service::METHOD_* constants");
    }
  }

  public function reload($core) {
    $params['action'] = 'RELOAD';
    $params['core'] = $core;

    $queryString = $this->_generateQueryString($params);

    if ($method == self::METHOD_GET)
    {
      $this->_sendRawGet($this->_coreAdminUrl . $this->_queryDelimiter . $queryString);
    }
    else if ($method == self::METHOD_POST)
    {
      $this->_sendRawPost($this->_coreAdminUrl, $queryString, FALSE, 'application/x-www-form-urlencoded; charset=UTF-8');
    }
    else
    {
      throw new Apache_Solr_InvalidArgumentException("Unsupported method '$method', please use the Apache_Solr_Service::METHOD_* constants");
    }
  }

  public function rename($oldcore, $newcore) {
    $params['action'] = 'RENAME';
    $params['core'] = $oldcore;
    $params['other'] = $newcore;

    $queryString = $this->_generateQueryString($params);

    if ($method == self::METHOD_GET)
    {
      $this->_sendRawGet($this->_coreAdminUrl . $this->_queryDelimiter . $queryString);
    }
    else if ($method == self::METHOD_POST)
    {
      $this->_sendRawPost($this->_coreAdminUrl, $queryString, FALSE, 'application/x-www-form-urlencoded; charset=UTF-8');
    }
    else
    {
      throw new Apache_Solr_InvalidArgumentException("Unsupported method '$method', please use the Apache_Solr_Service::METHOD_* constants");
    } 
  }

  public function alias($core, $alias) {
    $params['action'] = 'ALIAS';
    $params['core'] = $core;
    $params['other'] = $alias;

    $queryString = $this->_generateQueryString($params);

    if ($method == self::METHOD_GET)
    {
      $this->_sendRawGet($this->_coreAdminUrl . $this->_queryDelimiter . $queryString);
    }
    else if ($method == self::METHOD_POST)
    {
      $this->_sendRawPost($this->_coreAdminUrl, $queryString, FALSE, 'application/x-www-form-urlencoded; charset=UTF-8');
    }
    else
    {
      throw new Apache_Solr_InvalidArgumentException("Unsupported method '$method', please use the Apache_Solr_Service::METHOD_* constants");
    } 
  }

  public function swap($core1, $core2) {
    $params['action'] = 'SWAP';
    $params['core'] = $core1;
    $params['other'] = $core2;

    $queryString = $this->_generateQueryString($params);

    if ($method == self::METHOD_GET)
    {
      $this->_sendRawGet($this->_coreAdminUrl . $this->_queryDelimiter . $queryString);
    }
    else if ($method == self::METHOD_POST)
    {
      $this->_sendRawPost($this->_coreAdminUrl, $queryString, FALSE, 'application/x-www-form-urlencoded; charset=UTF-8');
    }
    else
    {
      throw new Apache_Solr_InvalidArgumentException("Unsupported method '$method', please use the Apache_Solr_Service::METHOD_* constants");
    }
  }

  public function unload($core, $deleteIndex = FALSE) {
    $params['action'] = 'UNLOAD';
    $params['core'] = $core;
    $params['deleteIndex'] = $deleteIndex ? 'true' : 'false';

    $queryString = $this->_generateQueryString($params);

    if ($method == self::METHOD_GET)
    {
      $this->_sendRawGet($this->_coreAdminUrl . $this->_queryDelimiter . $queryString);
    }
    else if ($method == self::METHOD_POST)
    {
      $this->_sendRawPost($this->_coreAdminUrl, $queryString, FALSE, 'application/x-www-form-urlencoded; charset=UTF-8');
    }
    else
    {
      throw new Apache_Solr_InvalidArgumentException("Unsupported method '$method', please use the Apache_Solr_Service::METHOD_* constants");
    }
  }

  public function mergeIndexes($core, $indexes) {
    $params['action'] = 'mergeindexes';
    $params['core'] = $core;
    $params['indexDir'] = $indexes;

    $queryString = $this->_generateQueryString($params);

    if ($method == self::METHOD_GET)
    {
      $this->_sendRawGet($this->_coreAdminUrl . $this->_queryDelimiter . $queryString);
    }
    else if ($method == self::METHOD_POST)
    {
      $this->_sendRawPost($this->_coreAdminUrl, $queryString, FALSE, 'application/x-www-form-urlencoded; charset=UTF-8');
    }
    else
    {
      throw new Apache_Solr_InvalidArgumentException("Unsupported method '$method', please use the Apache_Solr_Service::METHOD_* constants");
    }
  }

  public function mergeCores($core, $sources) {
    $params['action'] = 'mergeindexes';
    $params['core'] = $core;
    $params['srcCore'] = $sources;

    $queryString = $this->_generateQueryString($params);

    if ($method == self::METHOD_GET)
    {
      $this->_sendRawGet($this->_coreAdminUrl . $this->_queryDelimiter . $queryString);
    }
    else if ($method == self::METHOD_POST)
    {
      $this->_sendRawPost($this->_coreAdminUrl, $queryString, FALSE, 'application/x-www-form-urlencoded; charset=UTF-8');
    }
    else
    {
      throw new Apache_Solr_InvalidArgumentException("Unsupported method '$method', please use the Apache_Solr_Service::METHOD_* constants");
    }
  }
}