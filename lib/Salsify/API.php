<?php

/**
 * This class is the gateway to Salsify. It should be the only class that knows
 * anything about Salsify's API (though other classes know about the Salsify
 * JSON file format).
 */
class Salsify_API {

  const API_BASE_URL = 'https://app.salsify.com/api/';

  private $_apiKey;
  private $_channelId;
  private $_channelRunId;
  private $_channelRunDataUrl;


  public function __construct($apiKey, $channelId) {
    $this->_apiKey = $apiKey;
    $this->_channelId = $channelId;
  }


  // downloads the output file to the given stream
  public function downloadChannelData($outputStream) {
    $this->_startSalsifyExportRun();
    $this->_waitForExportRunToComplete();
    self::downloadData($this->_channelRunDataUrl, $outputStream);
    return $this;
  }


  private function _responseValid($response) {
    $response_code = $response->getResponseCode();
    return ($response_code >= 200 && $response_code <= 299);
  }


  private function _channelUrl() {
    return self::API_BASE_URL . 'channels/' . $this->_channelId;
  }

  private function _channelRunsBaseUrl() {
    return $this->_channelUrl() . '/runs';
  }

  private function _createChannelRunUrl() {
    return $this->_channelRunsBaseUrl();
  }

  private function _channelRunUrl() {
    return $this->_channelRunsBaseUrl() . '/' . $this->_channelRunId;
  }

  private function _apiUrlSuffix() {
    return '?format=json&auth_token=' . $this->_apiKey;
  }

  private function _doSalsifyRequest($url, $method = 'GET', $postBody = null) {
    $defaultCurlOptions = array(
      CURLOPT_URL => $url . $this->_apiUrlSuffix(),
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HEADER => false,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER, array('Content-Type: application/json'),

      // seemed reasonable settings
      CURLOPT_TIMEOUT => 60,
      CURLOPT_FRESH_CONNECT => true,
      CURLOPT_FORBID_REUSE => true,
    );

    if ($method === 'POST' && is_array($postBody)) {
      $postBody = json_encode($postBody);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $defaultCurlOptions);

    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response && !empty($response)) {
      $response = json_decode($response, true);
    }
    return $response;
  }


  private function _startSalsifyExportRun() {
    $response = $this->_doSalsifyRequest($this->_createChannelRunUrl(), 'POST');
    $this->_channelRunId = $response['id'];
    return $this;
  }


  // waits until salsify is done preparing the given export, and returns the URL
  // when done. throws an exception if anything funky occurs.
  private function _waitForExportRunToComplete() {
    $this->_channelRunDataUrl = null;

    do {
      sleep(5);
      $exportRun = $this->_doSalsifyRequest($this->_channelRunUrl());
      $status = $exportRun['status'];
      if ($status === 'completed') {
        $this->_channelRunDataUrl = $exportRun['product_export_url'];
      } elseif ($status === 'failed') {
        // this would be an internal error in Salsify
        throw new Exception('Salsify failed to produce an export.');
      }
    } while (!$this->_channelRunDataUrl);

    return $this;
  }


  // helper method for downloading things from Salsify
  public static function downloadData($url, $outputStream) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    curl_setopt($ch, CURLOPT_FILE, $outputStream);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    curl_close($ch);
    return $outputStream;
  }
}
