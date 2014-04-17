<?php
interface Salsify_JsonStreamingParserListener {
  public function startDocument();
  public function endDocument();

  public function startAttributes();
  public function attribute($attribute);
  public function endAttributes();

  public function startAttributeValues();
  public function attributeValue($attributeValue);
  public function endAttributeValues();

  public function startProducts();
  public function product($product);
  public function endProducts();
}
