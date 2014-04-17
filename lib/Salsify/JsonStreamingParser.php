<?php
require_once dirname(__FILE__).'/../JsonStreamingParser/Listener.php';
require_once dirname(__FILE__).'/../JsonStreamingParser/Parser.php';

// Streams data from a Salsify JSON export, sending events to a listener.
// Objects sent to the listener are PHP arrays using the exact info given in the
// JSON export; it's up to the listener to provide more structure or do anything
// else with the data.
class Salsify_JsonStreamingParser implements JsonStreamingParser_Listener {

  // generic JSON streaming parser that does most of the work.
  private $_parser;

  // listener to receive the parsing events.
  private $_listener;


  // Current keys and values that we're building up. We have to do it this way
  // vs. just having a current object stack because php deals with arrays as
  // pass-by-value.
  private $_keyStack;
  private $_valueStack;
  private $_typeStack; // since php doesn't have a separate hash
  const ARRAY_TYPE  = 1;
  const OBJECT_TYPE = 2;


  // keep track of nesting level during parsing. this is handy to know whether
  // the object you're leaving is nested, etc.
  private $_nesting_level;
  const HEADER_NESTING_LEVEL  = 2;
  const ITEM_NESTING_LEVEL = 4;


  // keeps track of current parsing state.
  private $_in_attributes;
  private $_in_attribute_values;
  private $_in_products;

  // current object. attribute, attribute value, or product. logic in the parser
  // is about the same for all 3, so we only really separate the for easier
  // reading.
  private $_attribute;
  private $_attributeValue;
  private $_product;


  public function __construct($stream, $listener) {
    $this->_parser = new JsonStreamingParser_Parser($stream, $this);
    $this->_listener = $listener;
  }


  public function parse() {
    $this->_parser->parse();
  }


  // JsonStreamingParser_Listener
  public function file_position($line, $char) {}


  // JsonStreamingParser_Listener
  public function start_document() {
    $this->_keyStack = array();
    $this->_valueStack = array();
    $this->_typeStack = array();

    $this->_nesting_level = 0;

    $this->_in_attributes = false;
    $this->_in_attribute_values = false;
    $this->_in_products = false;

    $this->_listener->startDocument();
  }

  // JsonStreamingParser_Listener
  public function end_document() {
    $this->_listener->endDocument();
  }


  // JsonStreamingParser_Listener
  public function start_object() {
    $this->_nesting_level++;

    if ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      if ($this->_in_attributes) {
        $this->_startAttribute();
      } elseif ($this->_in_attribute_values) {
        $this->_startAttributeValue();
      } elseif ($this->_in_products) {
        $this->_startProduct();
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_startNestedThing(self::OBJECT_TYPE);
    }
  }


  // JsonStreamingParser_Listener
  public function end_object() {
    if ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_endNestedThing();
    } elseif ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      if ($this->_in_attributes) {
        $this->_end_attribute();
      } elseif ($this->_in_attribute_values) {
        $this->_endAttributeValue();
      } elseif ($this->_in_products) {
        $this->_end_product();
      }
    } elseif ($this->_nesting_level === self::HEADER_NESTING_LEVEL) {
      if ($this->_in_attributes) {
        $this->_in_attributes = false;
        $this->_listener->endAttributes();
      } elseif ($this->_in_attribute_values) {
        $this->_in_attribute_values = false;
        $this->_listener->endAttributeValues();
      } elseif ($this->_in_products) {
        $this->_in_products = false;
        $this->_listener->endProducts();
      }
    }

    $this->_nesting_level--;
  }


  // JsonStreamingParser_Listener
  public function start_array() {
    $this->_nesting_level++;

    if ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_startNestedThing(self::ARRAY_TYPE);
    }
  }


  // JsonStreamingParser_Listener
  public function end_array() {
    if ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_endNestedThing();
    }

    $this->_nesting_level--;
  }


  // JsonStreamingParser_Listener
  // Key will always be a string
  public function key($key) {
    array_push($this->_keyStack, $key);

    if ($this->_nesting_level === self::HEADER_NESTING_LEVEL) {
      if ($key === 'attributes') {
        $this->_in_attributes = true;
        $this->_listener->startAttributes();
      } elseif ($key === 'attribute_values') {
        $this->_in_attribute_values = true;
        $this->_listener->startAttributeValues();
      } elseif ($key === 'products') {
        $this->_in_products = true;
        $this->_listener->startProducts();
      }
    }
  }


  // JsonStreamingParser_Listener
  // Note that value may be a string, integer, boolean, array, etc.
  public function value($value) {
    if ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_addNestedValue($value);
    } elseif ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      $key = array_pop($this->_keyStack);
      if ($this->_in_attributes) {
        $this->_attribute[$key] = $value;
      } elseif ($this->_in_attribute_values) {
        $this->_attributeValue[$key] = $value;
      } elseif ($this->_in_products) {
        $this->_product[$key] = $value;
      }
    }
  }


  private function _startNestedThing($type) {
    array_push($this->_valueStack, array());
    array_push($this->_typeStack, $type);
  }


  private function _endNestedThing() {
    $value = array_pop($this->_valueStack);
    $type = array_pop($this->_typeStack);

    if (empty($this->_valueStack)) {
      // at the root of an object, whether product, attribute, etc.
      $key = array_pop($this->_keyStack);

      // NOTE no nesting in attribute_values
      if ($this->_in_attributes) {
        $this->_attribute[$key] = $value;
      } elseif ($this->_in_products) {
        $this->_product[$key] = $value;
      }
    } else {
      // within a nested object of some kind
      $this->_addNestedValue($value);
    }
  }

  // nice helper method that adds the given value to the top of the nested
  // stack of objects, whether that nested thing be an array or object (which,
  // in both cases, is a PHP array).
  private function _addNestedValue($value) {
    // unbelievable how PHP doesn't have array_peek...
    $parent_value = array_pop($this->_valueStack);
    $parent_type = array_pop($this->_typeStack);
    if ($parent_type === self::ARRAY_TYPE) {
      array_push($parent_value, $value);
    } elseif ($parent_type === self::OBJECT_TYPE) {
      $key = array_pop($this->_keyStack);
      $parent_value[$key] = $value;
    }
    array_push($this->_valueStack, $parent_value);
    array_push($this->_typeStack, $parent_type);
  }


  private function _startAttribute() {
    $this->_attribute = array();
  }

  private function _end_attribute() {
    $this->_listener->attribute($this->_attribute);
    unset($this->_attribute);
  }


  private function _startAttributeValue() {
    $this->_attributeValue = array();
  }

  private function _endAttributeValue() {
    $this->_listener->attributeValue($this->_attributeValue);
    unset($this->_attributeValue);
  }


  private function _startProduct() {
    $this->_product = array();
  }

  private function _end_product() {
    $this->_listener->product($this->_product);
    unset($this->_product);
  }

}

?>
