<?php

require_once dirname(__FILE__).'/SalsifyJsonStreamingParserListener.php';


class Salsify4TellAdapter implements SalsifyJsonStreamingParserListener {

  // stream to which XML will be written
  private $_stream;


  private $_externalIdAttributeId;
  private $_nameAttributeId;
  private $_brandAttributeId;
  private $_categoryAttributeId;
  private $_productUrlAttributeId;
  private $_imageAttributeId;
  private $_partNumberAttributeId;

  // hash of ID -> name
  private $_brandAttributeValues;

  // hash of ID -> ('name' => name)
  // hash of hash to make it easier to build hierarchy later (had it in but
  // removed it and didn't want to bother refactoring code)
  private $_categories;


  // options: "brand attribute ID", "category attribute ID"
  public function __construct($outputStream, $options) {
    $this->_stream = $outputStream;

    if (!array_key_exists('brand attribute ID', $options) ||
        !array_key_exists('category attribute ID', $options) ||
        !array_key_exists('product page URL attribute ID', $options) ||
        !array_key_exists('image attribute ID', $options) ||
        !array_key_exists('part number attribute ID', $options)) {
      throw new Exception("Missing one or more required options: brand attribute ID, category attribute ID");
    }

    $this->_brandAttributeId = $options['brand attribute ID'];
    $this->_categoryAttributeId = $options['category attribute ID'];
    $this->_productUrlAttributeId = $options['product page URL attribute ID'];
    $this->_imageAttributeId = $options['image attribute ID'];
    $this->_partNumberAttributeId = $options['part number attribute ID'];
  }

  private function _write($text) {
    fwrite($this->_stream, $text . "\n");
  }

  private function _prepareTag($tagname, $value) {
    return '<' . $tagname . '>' . $value . '</' . $tagname . '>';
  }

  // SalsifyJsonStreamingParserListener
  public function startDocument() {
    $this->_write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
    // TODO extractDate="2014-04-05T22:00:34-05:00"
    $this->_write('<Feed>');
  }

  // SalsifyJsonStreamingParserListener
  public function endDocument() {
    $this->_write('</Feed>');
  }

  // SalsifyJsonStreamingParserListener
  public function startAttributes() {
  }

  // SalsifyJsonStreamingParserListener
  public function attribute($attribute) {
    if ($this->_isIdAttribute($attribute)) {
      $this->_externalIdAttributeId = $attribute['salsify:id'];
    } elseif ($this->_isNameAttribute($attribute)) {
      $this->_nameAttributeId = $attribute['salsify:id'];
    } elseif ($this->_isBrandAttribute($attribute) && !$this->_isEnumeratedAttribute($attribute)) {
      throw new Exception("Brand property must be enumerated");
    }
  }

  private function _isIdAttribute($attribute) {
    return array_key_exists('salsify:role', $attribute) &&
           $attribute['salsify:role'] === 'product_id';
  }

  private function _isNameAttribute($attribute) {
    return array_key_exists('salsify:role', $attribute) &&
           $attribute['salsify:role'] === 'product_name';
  }

  private function _isBrandAttribute($attribute) {
    return $attribute['salsify:id'] === $this->_brandAttributeId;
  }

  private function _isEnumeratedAttribute($attribute) {
    return $attribute['salsify:data_type'] === 'enumerated';
  }

  // SalsifyJsonStreamingParserListener
  public function endAttributes() {
  }

  // SalsifyJsonStreamingParserListener
  public function startAttributeValues() {
    $this->_brandAttributeValues = array();
    $this->_categories = array();
  }

  // SalsifyJsonStreamingParserListener
  public function attributeValue($attributeValue) {
    if ($this->_isBrandAttributeValue($attributeValue)) {
      $this->_brandAttributeValues[$attributeValue['salsify:id']] = $attributeValue['salsify:name'];
    } elseif ($this->_isCategoryAttributeValue($attributeValue)) {
      $id = $attributeValue['salsify:id'];
      $name = $attributeValue['salsify:name'];
      $this->_categories[$id] = array('name' => $name);
    }
  }

  private function _isBrandAttributeValue($attributeValue) {
    return $attributeValue['salsify:attribute_id'] === $this->_brandAttributeId;
  }

  private function _isCategoryAttributeValue($attributeValue) {
    return $attributeValue['salsify:attribute_id'] === $this->_categoryAttributeId;
  }

  // SalsifyJsonStreamingParserListener
  public function endAttributeValues() {
    $this->_writeBrands();
    $this->_writeCategories();
  }

  private function _writeBrands() {
    $this->_write('<Brands>');
    foreach($this->_brandAttributeValues as $brandId => $brandName) {
      $brandXml = '<Brand>';
      $brandXml .= $this->_prepareTag('Brand', $brandId);
      $brandXml .= $this->_prepareTag('Name', $brandName);
      $brandXml .= '</Brand>';
      $this->_write($brandXml);
    }
    $this->_write('</Brands>');
  }

  private function _writeCategories() {
    $this->_write('<Categories>');
    foreach($this->_categories as $categoryId => $category) {
      $categoryXml = '<Category>';
      $categoryXml .= $this->_prepareTag('ExternalId', $categoryId);
      $categoryXml .= $this->_prepareTag('Nmae', $category['name']);
      // FIXME need to add CategoryPageUrl from metadata on the category itself
      $categoryXml .= '</Category>';
      $this->_write($categoryXml);
    }
    $this->_write('</Categories>');
  }

  // SalsifyJsonStreamingParserListener
  public function startProducts() {
    $this->_write('<Products>');
  }

  // SalsifyJsonStreamingParserListener
  public function product($product) {
    $productXml = '<Product>';
    $productXml .= $this->_prepareTag('ExternalId', $this->_productValueForProperty($product, $this->_externalIdAttributeId));
    $productXml .= $this->_prepareTag('Name', $this->_productValueForProperty($product, $this->_nameAttributeId));
    $productXml .= $this->_prepareTag('CategoryExternalId', $this->_productValueForProperty($product, $this->_categoryAttributeId));
    $productXml .= $this->_prepareTag('ProductPageUrl', $this->_productValueForProperty($product, $this->_productUrlAttributeId));
    $productXml .= $this->_prepareTag('ImageUrl', $this->_productImageUrl($product));
    
    $productXml .= '<ManufacturerPartNumbers>';
    $productXml .= $this->_prepareTag('ManufacturerPartNumber', $this->_productValueForProperty($product, $this->_partNumberAttributeId));
    $productXml .= '</ManufacturerPartNumbers>';    

    $productXml .= $this->_prepareTag('BrandExternalId', $this->_productValueForProperty($product, $this->_brandAttributeId));
    $productXml .= '</Product>';

    $this->_write($productXml);
  }

  // an attribute such as brand can have multiple values in theory coming from
  // Salsify. This takes the first one.
  private function _productValueForProperty($product, $attributeId) {
    if (!array_key_exists($attributeId, $product)) {
      return null;
    } elseif (is_array($product[$attributeId])) {
      return $product[$attributeId][0];
    } else {
      return $product[$attributeId];
    }
  }

  private function _productImageUrl($product) {
    $imageId = $this->_productValueForProperty($product, $this->_imageAttributeId);
    foreach ($product['salsify:digital_assets'] as $digitalAsset) {
      if ($digitalAsset['salsify:id'] === $imageId) {
        return $digitalAsset['salsify:url'];
      }
    }
    return null;
  }

  // SalsifyJsonStreamingParserListener
  public function endProducts() {
    $this->_write('</Products>');
  }

}

// FIXME remove
require_once dirname(__FILE__).'/SalsifyJsonStreamingParser.php';

$testfile = dirname(__FILE__).'/tmp/test.json';
$outputfile = dirname(__FILE__).'/tmp/4Tell-TEST.xml';

$options = array(
  'brand attribute ID' => 'Brand',
  'category attribute ID' => 'Category',
  'product page URL attribute ID' => 'Product Link',
  'image attribute ID' => 'Image',
  'part number attribute ID' => 'UPC'
);

$stream = fopen($testfile, 'r');
try {
  if (file_exists($outputfile)) {
    unlink($outputfile);
  }
  $ostream = fopen($outputfile, 'w');
  $adapter = new Salsify4TellAdapter($ostream, $options);
  $parser = new SalsifyJsonStreamingParser($stream, $adapter);
  $parser->parse();
  fclose($ostream);
  fclose($stream);
} catch (Exception $e) {
  fclose($stream);
  throw $e;
}

?>