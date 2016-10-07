<?php

/**
 * @file
 * Defines a class for consuming the Avatax API.
 */

/**
 * Defines the Avatax class.
 *
 * A modern PHP library would namespace its classes under a package name, which
 * in this case would mean using the Percolate namespace and instantiating new
 * objects of this class via:
 *
 * $avatax = new Avalara\Avatax(...);
 *
 * Unfortunately, Drupal 7 does not support namespaces in its autoloader, as it
 * maintains compatibility with previous versions of PHP that did not support
 * namespaces. Thus this library does not currently use a namespace.
 */
class Avatax {

  // Defines the test API url of the REST API V2.
  const BASE_DEV_URL_V2 = 'https://rest-sbx-preview.avalara.net/api/v2/';

  // Defines the production API url of the REST API V2.
  const BASE_URL_V2 = 'https://rest-sbx-preview.avalara.net/api/v2/';

  // Defines the base URL of the API.
  protected $baseUrl;

  // The Avalara account number that is used to authenticate against the API.
  protected $accountNumber;

  // The Avalara account number that is used to authenticate against the API.
  protected $licenseKey;

  // The API mode (dev|prod).
  protected $apiMode;

  // Reference the logger callable.
  protected $logger;

  // Manage a single cURL handle used to submit API requests.
  protected $ch;

  /**
   * Initializes the API credential properties and cURL handle.
   *
   * @param string $accountNumber
   *   The Avalara account number that is used to authenticate against the API.
   * @param string $licenseKey
   *   The Avalara license key that is used to authenticate against the API.
   * @param string $apiMode
   *   The API mode (dev|prod), used to determine the endpoint to call.
   * @param string $logger
   *   A callable used to log API request / response messages. Leave empty if
   *   logging is not needed.
   */
  public function __construct($accountNumber, $licenseKey, $apiMode = 'dev', $logger = NULL) {
    // Initialize the API credential properties.
    $this->accountNumber = $accountNumber;
    $this->licenseKey = $licenseKey;
    $this->apiMode = $apiMode;
    $this->setBaseUrl();

    // Initialize the cURL handle.
    $this->ch = curl_init();
    $this->setDefaultCurlOptions();
  }

  /**
   * Returns the base URL for the API.
   *
   * @return string
   *   The base URL for the API that query parameters will be appended to when
   *   submitting API requests.
   */
  public function baseUrl() {
    return $this->baseUrl;
  }

  /**
   * Sets the API base url.
   *
   * @return string
   *   The base URL for the API that query parameters will be appended to when
   *   submitting API requests.
   */
  public function setBaseUrl($baseUrl = '') {
    if ($baseUrl) {
      $this->baseUrl = $baseUrl;
    }
    else {
      if ($this->apiMode == 'dev') {
        $this->baseUrl = self::BASE_DEV_URL_V2;
      }
      else {
        $this->baseUrl = self::BASE_URL_V2;
      }
    }
  }

  /**
   * Returns the object's account number.
   *
   * @return string
   *   The Account number.
   */
  public function getAccountNumber() {
    return $this->accountNumber;
  }

  /**
   * Returns the object's license key.
   *
   * @return string
   *   The License key.
   */
  public function getLicenseKey() {
    return $this->licenseKey;
  }

  /**
   * Closes the cURL handle when the object is destroyed.
   */
  public function __destruct() {
    if (is_resource($this->ch)) {
      curl_close($this->ch);
    }
  }

  /**
   * Tests connectivity and version of the service.
   */
  public function ping() {
    return $this->doRequest('GET', 'utilities/ping');
  }

  /**
   * Sets the default cURL options.
   */
  public function setDefaultCurlOptions() {
    curl_setopt($this->ch, CURLOPT_HEADER, FALSE);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, array(
      'Authorization: Basic ' . base64_encode($this->getAccountNumber() . ':' . $this->getLicenseKey()),
    ));
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, TRUE);
    curl_setopt($this->ch, CURLOPT_VERBOSE, FALSE);
    curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($this->ch, CURLOPT_TIMEOUT, 180);
  }

  /**
   * Send a message to the logger.
   *
   * @param string $message
   *   The message to log.
   * @param int $severity
   *   The severity of the message; one of the following values:
   *   - WATCHDOG_EMERGENCY: Emergency, system is unusable.
   *   - WATCHDOG_ALERT: Alert, action must be taken immediately.
   *   - WATCHDOG_CRITICAL: Critical conditions.
   *   - WATCHDOG_ERROR: Error conditions.
   *   - WATCHDOG_WARNING: Warning conditions.
   *   - WATCHDOG_NOTICE: (default) Normal but significant conditions.
   *   - WATCHDOG_INFO: Informational messages.
   *   - WATCHDOG_DEBUG: Debug-level messages.
   *
   * @see http://www.faqs.org/rfcs/rfc3164.html
   */
  public function logMessage($message, $severity = WATCHDOG_NOTICE) {
    if (is_callable($this->logger)) {
      call_user_func($this->logger, $message, $severity);
    }
  }

  /**
   * Performs a request.
   *
   * @param string $method
   *   The HTTP method to use. One of: 'GET', 'POST', 'PUT', 'DELETE'.
   * @param string $path
   *   The remote path. The base URL will be automatically appended.
   * @param array $fields
   *   An array of fields to include with the request. Optional.
   *
   * @return array
   *   An array with the 'success' boolean and the result. If 'success' is FALSE
   *   the result will be an error message. Otherwise it will be an array
   *   of returned data.
   */
  protected function doRequest($method, $path, array $fields = array()) {
    $url = $this->baseUrl() . $path;
    // Set the request URL and method.
    curl_setopt($this->ch, CURLOPT_URL, $url);
    curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, $method);
    if (!empty($fields)) {
      // JSON encode the fields and set them to the request body.
      $fields = json_encode($fields);
      curl_setopt($this->ch, CURLOPT_POSTFIELDS, $fields);
      // Log the API request with the JSON encoded fields.
      $this->logMessage($method . ' ' . $url . "\n\n" . $fields);
    }
    else {
      // Log the API request without fields.
      $this->logMessage($method . ' ' . $url);
    }
    $result = curl_exec($this->ch);
    $status_code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
    $success = $status_code === 200;
    $this->logMessage(t("@code response\n\n@result", array('@code' => $status_code, '@result' => $result)));
    if ($status_code != '200') {
      $result = 'Error ' . $status_code;
    }
    elseif ($status_code == '200' && !empty($result)) {
      $result = json_decode($result, TRUE);
    }
    return array('success' => $success, 'result' => $result);
  }

}