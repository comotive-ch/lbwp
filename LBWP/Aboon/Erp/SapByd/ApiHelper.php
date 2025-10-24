<?php

namespace LBWP\Aboon\Erp\SapByd;

/**
 * Api Helper for SAPByd Requests
 * @author Michael Sebel <michael@comotive.ch>
 */
class ApiHelper
{

  /**
   * @var string
   */
  protected $user = '';
  /**
   * @var string
   */
  protected $password = '';
  /**
   * @var string
   */
  protected $authType = 'basic';
  /**
   * @var string
   */
  protected $hostName = '';
  /**
   * @var string
   */
  protected $csrfToken = 'JTq1zEiJcGFiW68pkhtR0l27';

  /**
   * @param $host
   * @param $user
   * @param $password
   */
  public function __construct($host, $user, $password)
  {
    $this->hostName = $host;
    $this->user = $user;
    $this->password = $password;
  }

  /**
   * @param $endpoint
   * @param $data
   * @return array
   */
  public function get($endpoint, $data = array(), $timeout = 0)
  {
    $url = 'https://' . $this->hostName . $endpoint;
    if (!isset($data['$format'])) {
      $data['$format'] = 'json';
    }
    $url .= '?' . http_build_query($data);

    // Call and convert
    $res = curl_init($url);
    curl_setopt_array($res, $this->getCurlOptions($timeout));
    $result = json_decode(curl_exec($res), true);
    curl_close($res);

    return $result;
  }

  public function post($endpoint, $data, $timeout = 0)
  {
    $url = 'https://' . $this->hostName . $endpoint;
    $res = curl_init($url);
    $curlopt = $this->getCurlOptions($timeout);
    $curlopt[CURLOPT_CUSTOMREQUEST] = 'POST';
    $curlopt[CURLOPT_HTTPHEADER] = array(
      'Content-Type: application/json',
      'x-csrf-token: ' . $this->csrfToken,
      'Accept: application/json'
    );
    $curlopt[CURLOPT_POSTFIELDS] = json_encode($data);

    curl_setopt_array($res, $curlopt);
    $result = json_decode(curl_exec($res), true);
    curl_close($res);
    return $result;
  }

  /**
   * @param string $endpoint
   * @param string $xml
   * @param string $action
   * @return string response xml
   */
  public function postXml($endpoint, $xml, $action, $timeout = 0)
  {
    $url = 'https://' . $this->hostName . $endpoint;
    $url = str_replace('{{TenantHostname}}', $this->hostName, $url);
    $res = curl_init($url);
    $curlopt = $this->getCurlOptions($timeout);
    $curlopt[CURLOPT_CUSTOMREQUEST] = 'POST';
    $curlopt[CURLOPT_HTTPHEADER] = array(
      'Content-Type: text/xml; charset=utf-8',
      'Connection: keep-alive',
      'x-csrf-token: ' . $this->csrfToken,
      'SOAPAction: ' . $action,
      'Accept: text/xml'
    );
    $curlopt[CURLOPT_POSTFIELDS] = $xml;

    curl_setopt_array($res, $curlopt);
    $result = curl_exec($res);
    curl_close($res);
    return $result;
  }

  /**
   * @return array
   */
  protected function getCurlOptions($timeout)
  {
    $options = array(
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_AUTOREFERER => true,
      CURLOPT_HEADER => array(),
      CURLOPT_USERAGENT => 'Comotive-Webshop-1.0',
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => ($timeout > 0) ? $timeout : LBWP_SAPBYD_API_TIMEOUT_SECONDS,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json'
      )
    );

    // Authenticate with basic auth, if needed
    if ($this->authType == 'basic') {
      $options[CURLOPT_USERPWD] = $this->user . ':' . $this->password;
    }

    return $options;
  }

  /**
   * @param array $data any SAP object with __metadata
   * @param string $type __metadata or __deferred
   * @return string endpoint to be called with get
   */
  public function convertUriEndpoint($data, $type = '__metadata')
  {
    return str_replace('https://' . $this->hostName, '', $data[$type]['uri']);
  }
}