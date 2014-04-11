<?php

require_once dirname(__FILE__).'/SalsifyJsonStreamingParserListener.php';

class Salsify4TellAdapter implements SalsifyJsonStreamingParserListener {


  // stream to which XML will be written
  private $_stream;


  public function __construct($outputStream) {
    $this->_stream = $outputStream;
  }

  public function startAttributes() {
  }

  public function attribute($attribute) {
  }

  public function endAttributes() {
  }

  public function startAttributeValues() {

  }

  public function attributeValue($attributeValue) {
  }

  public function endAttributeValues() {

  }

  public function startProducts() {

  }

  public function product($product) {
  }

  public function endProducts() {

  }

}

?>