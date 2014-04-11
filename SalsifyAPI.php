<?php


/**
 * This class is the gateway to Salsify. It should be the only class that knows
 * anything about Salsify's API (though other classes know about the Salsify
 * JSON file format).
 */
class SalsifyAPI {

  const API_BASE_URL = 'https://app.salsify.com/api/';

  private $_apiKey;
  private $_channelId;
  private $_channelRunId;


  public function __construct($apiKey, $channelId) {
    $this->_apiKey = $apiKey;
    $this->_channelId = $channelId;
    $this->_channelRunId = null;
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

  private function _channelRunStatusUrl() {
    return $this->_channelRunsBaseUrl() . '/' . $this->_channelRunId;
  }

  private function _apiUrlSuffix() {
    return '?format=json&auth_token=' . $this->_apiKey;
  }

  private function _doSalsifyRequest($url, $method = 'GET', $postBody = null) {
    $defaultCurlOptions = array(
      'CURLOPT_URL' => $url,
      'CURLOPT_HEADER' => false,
      'CURLOPT_TIMEOUT' => 60,
      'CURLOPT_RETURNTRANSFER' => true,

      // seemed reasonable settings
      'CURLOPT_FRESH_CONNECT' => true,
      'CURLOPT_FORBID_REUSE' => true,
    );

    if ($method === 'POST') {
      $defaultCurlOptions['CURLOPT_POST'] = true;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $defaultCurlOptions);

    // FIXME set headers ('Content-Type for example')
    // FIXME exec return value?
    $response = curl_exec($ch);

    curl_close($ch);

    // FIXME parse the JSON in the response
    return $response;
  }


  // FIXME
  private function _startSalsifyExportRun() {
    $request = new HttpRequest($this->_get_start_salsify_export_run_url($id), HTTP_METH_POST);
    $response = $request->send();
    $response_json = json_decode($response->getBody(), true);
    if (!$this->_responseValid($response)) {
      if (array_key_exists('errors', $response_json)) {
        $error = $resopnse_json['errors'][0];
      } else {
        $error = "No details provided by Salsify.";
      }
      throw new Exception("Could not start Salsify export: " . var_export($error, true));
    }

    $id = $response_json['id'];
    self::_log("Export run started. ID: " . $id);
    return $id;
  }


  // FIXME
  // waits until salsify is done preparing the given export, and returns the URL
  // when done. throws an exception if anything funky occurs.
  private function _wait_for_salsify_to_finish_preparing_export() {
    do {
      sleep(5);
      $url = $this->_is_salsify_done_preparing_export($salsify_export_id, $id);
    } while (!$url);
    return $url;
  }


  // FIXME
  // checks whether salsify is done preparing the data export with the given id.
  // return null if not.
  // return the url of the document if it's done.
  // throw an Exception if anything strange occurs.
  private function _is_salsify_done_preparing_export($salsify_export_id, $id) {
    $export = $this->_get_salsify_export_run($salsify_export_id, $id);

    if (!array_key_exists('status', $export)) {
      throw new Exception('Malformed document returned from Salsify: ' . var_export($export,true));
    }
    $status = $export['status'];
    if ($status === 'running') {
      // still going
      return null;
    } elseif ($status === 'failed') {
      // extremely unlikely. this would be an internal error in Salsify
      throw new Exception('Salsify failed to produce an export for Magento.');
    } elseif ($status !== 'completed') {
      throw new Exception('Malformed document returned from Salsify. Unknown status: ' . $export['status']);
    } elseif (!array_key_exists('url', $export)) {
      throw new Exception('Malformed document returned from Salsify. No URL returned for successful Salsify export.');
    }

    $url = $export['url'];
    if (!$url) {
      throw new Exception("Processing done but no public URL. Check for errors with Salsify administrator. Export job ID: " . $id);
    }

    return $url;
  }

}


// FIXME remove when done implementing
$api = new SalsifyAPI(getenv('SALSIFY_API_KEY'), 69);
