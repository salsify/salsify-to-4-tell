<?php
require_once dirname(__FILE__).'/lib/Salsify/JsonStreamingParserListener.php';

class Salsify4TellAdapter implements Salsify_JsonStreamingParserListener {

  const FOURTELL_API = 'http://stage.4-tell.net/Boost2.0/upload/xml/stream';

  // stream to which XML will be written
  private $_stream;

  // 4Tell account alias. required for uploading data.
  private $_alias;

  // cached configuration read from the file
  private $_config;
  private $_brandAttributeId;
  private $_categoryAttributeId;

  // cache these values when parsing the attributes section of the Salsify export
  private $_externalIdAttributeId;
  private $_nameAttributeId;

  // hash of ID -> name
  private $_brandAttributeValues;

  // hash of ID -> ('name' => name)
  // hash of hash to make it easier to build hierarchy later (had it in but
  // removed it and didn't want to bother refactoring code)
  private $_categories;


  public function __construct($configFile, $outputStream, $alias) {
    $this->_setConfigFile($configFile);
    $this->_stream = $outputStream;
    $this->_alias = $alias;
  }


  public function uploadTo4Tell($stream) {
    $curlOptions = array(
      CURLOPT_URL => self::FOURTELL_API,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_UPLOAD => true,
      CURLOPT_HTTPHEADER => array('Transfer-Encoding: chunked'),
      CURLOPT_TIMEOUT => 600,
      CURLOPT_HEADER => false,
      CURLOPT_FRESH_CONNECT => true,
      CURLOPT_FORBID_REUSE => true,
    );

    $ch = curl_init();
    curl_setopt_array($ch, $curlOptions);
    curl_setopt($ch, CURLOPT_READFUNCTION, function($ch, $fd, $length) use ($stream) {
      $data = fread($stream, $length);
      if ($data) { return $data; }
      return '';
    });
    curl_exec($ch);
    curl_close($ch);
  }


  // ensures that the configuration file is properly formatted (though not
  // exhaustively; e.g. it doesn't check that all the Category and Brand items
  // have complete information).
  private function _setConfigFile($configFile) {
    if (!file_exists($configFile)) {
      throw new Exception("Config file does not exist: " . $configFile);
    }

    $this->_config = json_decode(file_get_contents($configFile), true);
    if ($this->_config === NULL) {
      throw new Exception("Could not read config file. Not proper JSON: " . $configFile);
    }

    if (!array_key_exists('Brand Attribute ID', $this->_config['Attributes']) ||
        !array_key_exists('Category Attribute ID', $this->_config['Attributes'])) {
      throw new Exception("Missing one or more required options: brand attribute ID, category attribute ID");
    }

    $this->_brandAttributeId = $this->_config['Attributes']['Brand Attribute ID'];
    $this->_categoryAttributeId = $this->_config['Attributes']['Category Attribute ID'];

    if (array_key_exists('Product ID', $this->_config['Attributes'])) {
      $this->_externalIdAttributeId = $this->_config['Attributes']['Product ID'];
    }
  }

  // for the given Salsify property return if a mapping exists to an output XML
  // key
  private function _elementForProperty($property) {
    if (!array_key_exists($property, $this->_config['Attributes']['Mappings'])) {
      return null;
    }
    return $this->_config['Attributes']['Mappings'][$property];
  }

  private function _write($text) {
    fwrite($this->_stream, $text . "\n");
  }

  private function _prepareTag($tagname, $value) {
    return '<' . $tagname . '>' . htmlspecialchars($value) . '</' . $tagname . '>';
  }

  // SalsifyJsonStreamingParserListener
  public function startDocument() {
    # TODO get date from the Salsify export, but this is good enough for now
    $date = new DateTime('NOW', new DateTimeZone('UTC'));
    $dateString = $date->format('Y-m-dTH:i:sP');

    // this first line is required by the feed
    $this->_write($this->_alias . "\t" .
                  "SalsifyFeed-" . $dateString . ".xml" . "\t" .
                  "create" . "\t" .
                  "true");

    $this->_write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
    $this->_write('<Feed extractDate="' . $dateString . '">');
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
      if (!$this->_externalIdAttributeId) {
        // may have been set by config
        $this->_externalIdAttributeId = $attribute['salsify:id'];
      }
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
      // TODO need to use metadata to get the ID that we're sending to 4Tell
      //      see longer note in _writeBrands()
      $this->_brandAttributeValues[$attributeValue['salsify:id']] = $attributeValue['salsify:name'];
    } elseif ($this->_isCategoryAttributeValue($attributeValue)) {
      $id = $attributeValue['salsify:id'];
      $name = $attributeValue['salsify:name'];
      $this->_categories[$id] = array('name' => $name);
      if (array_key_exists('salsify:parent_id', $attributeValue)) {
        $this->_categories[$id]['parent_id'] = $attributeValue['salsify:parent_id'];
      }
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

    // TODO this is how it should work (that is, getting the metadata from Salsify)
    //      but right now we're getting it from a config file since the data in
    //      Salsify is a little messed up
    // foreach($this->_brandAttributeValues as $brandId => $brandName) {
    //   $brandXml = '<Brand>';
    //   $brandXml .= $this->_prepareTag('ExternalId', $brandId);
    //   $brandXml .= $this->_prepareTag('Name', $brandName);
    //   $brandXml .= '</Brand>';
    //   $this->_write($brandXml);
    // }

    foreach ($this->_config["Brands"] as $brand) {
      $brandXml = '<Brand>';
      $brandXml .= $this->_prepareTag('ExternalId', $brand['id']);
      $brandXml .= $this->_prepareTag('Name', $brand['name']);
      $brandXml .= '</Brand>';
      $this->_write($brandXml);
    }

    $this->_write('</Brands>');
  }

  private function _writeCategories() {
    // TODO ideally we'd get the category metadata such as the URL from the
    //      category itself, but we can't right now since we don't allow metadata
    //      on enumerated attribute values in Salsify yet.

    $this->_write('<Categories>');
    foreach($this->_categories as $categoryId => $category) {
      $categoryXml = '<Category>';
      $categoryXml .= $this->_prepareTag('ExternalId', $categoryId);
      $categoryXml .= $this->_prepareTag('Name', $category['name']);
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

    if (array_key_exists($this->_categoryAttributeId, $product)) {
      $productXml .= '<CategoryExternalIds>';

      $categoryIds = $product[$this->_categoryAttributeId];
      if (!is_array($categoryIds)) {
        $categoryIds = array($categoryIds);
      }
      $allIds = $this->_collectAllCategoryIds($categoryIds);
      foreach ($allIds as $categoryId) {
        $productXml .= $this->_prepareTag('CategoryExternalId', $categoryId);
      }

      $productXml .= '</CategoryExternalIds>';
    }

    foreach (array_keys($product) as $productProperty) {
      $element = $this->_elementForProperty($productProperty);
      if ($element) {
        $productXml .= $this->_prepareTag($element, $this->_productValueForProperty($product, $productProperty));
      }
    }
    $productXml .= '</Product>';

    $this->_write($productXml);
  }


  // gets the whole tree of category IDs
  private function _collectAllCategoryIds($categoryIds) {
    $allIds = array();
    foreach ($categoryIds as $categoryId) {
      while ($categoryId !== null) {
        if (!in_array($categoryId, $allIds)) {
          array_push($allIds, $categoryId);
        }

        if (array_key_exists('parent_id', $this->_categories[$categoryId])) {
          $parentId = $this->_categories[$categoryId]['parent_id'];
        } else {
          $parentId = null;
        }
        $categoryId = $parentId;
      }
    }
    return $allIds;
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
?>