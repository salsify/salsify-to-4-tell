<?php
require_once dirname(__FILE__).'/lib/JsonStreamingParser/Listener.php';
require_once dirname(__FILE__).'/lib/JsonStreamingParser/Parser.php';


# FIXME need to add listener implementation that puts out attributes,
#       attribute values, and products
class SalsifyJsonParser implements JsonStreamingParser_Listener {


  // Current keys and values that we're building up. We have to do it this way
  // vs. just having a current object stack because php deals with arrays as
  // pass-by-value.
  private $_key_stack;
  private $_value_stack;
  private $_type_stack; // since php doesn't have a separate hash
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


  // JsonStreamingParser_Listener
  public function file_position($line, $char) {}


  // JsonStreamingParser_Listener
  public function start_document() {
    self::_log("Starting document load.");

    $this->_key_stack = array();
    $this->_value_stack = array();
    $this->_type_stack = array();

    $this->_nesting_level = 0;
    $this->_in_attributes = false;
    $this->_in_attribute_values = false;
    $this->_in_products = false;
  }

  // JsonStreamingParser_Listener
  public function end_document() {
    // FIXME anything to do here?
  }


  // JsonStreamingParser_Listener
  public function start_object() {
    $this->_nesting_level++;

    if ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      if ($this->_in_attributes) {
        $this->_start_attribute();
      } elseif ($this->_in_attribute_values) {
        $this->_start_category();
      } elseif ($this->_in_products) {
        $this->_start_product();
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_start_nested_thing(self::OBJECT_TYPE);
    }
  }


  // JsonStreamingParser_Listener
  public function end_object() {
    if ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      if ($this->_in_attributes) {
        $this->_end_attribute();
      } elseif ($this->_in_attribute_values) {
        $this->_end_category();
      } elseif ($this->_in_products) {
        $this->_end_product();
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_end_nested_thing();
    } elseif ($this->_nesting_level === self::HEADER_NESTING_LEVEL) {
        if ($this->_in_attribute_values) {
          $this->_import_categories();
        }

        $this->_in_attributes = false;
        $this->_in_attribute_values = false;
        $this->_in_products = false;
    }

    $this->_nesting_level--;
  }


  // JsonStreamingParser_Listener
  public function start_array() {
    $this->_nesting_level++;

    if ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_start_nested_thing(self::ARRAY_TYPE);
    }
  }


  // JsonStreamingParser_Listener
  public function end_array() {
    if ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_end_nested_thing();
    }

    $this->_nesting_level--;
  }


  // JsonStreamingParser_Listener
  // Key will always be a string
  public function key($key) {
    array_push($this->_key_stack, $key);

    if ($this->_nesting_level === self::HEADER_NESTING_LEVEL) {
      if ($key === 'attributes') {
        // starting to parse attribute section of import document

        self::_log("Starting to parse attributes.");
        $this->_in_attributes = true;
        $this->_attributes = array();
        $this->_relationship_attributes = array();

        // create the attributes to store the salsify ID for all object types.
        $this->_create_salsify_id_attributes_if_needed();

      } elseif ($key === 'attribute_values') {
        // starting to parse attribute_values (e.g. categories) section of
        // import document

        self::_log("Starting to parse categories (attribute_values).");
        $this->_in_attribute_values = true;
        $this->_categories = array();

      } elseif ($key === 'products') {
        // starting to parse products section of import document

        self::_log("Starting to parse products.");
        $this->_in_products = true;
        $this->_batch = array();
        $this->_batch_accessories = array();
        $this->_digital_assets = array();
      }
    }
  }


  // JsonStreamingParser_Listener
  // Note that value may be a string, integer, boolean, array, etc.
  public function value($value) {
    if ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      $key = array_pop($this->_key_stack);

      if ($this->_in_attributes) {
        $this->_attribute[$key] = $value;
      } elseif ($this->_in_attribute_values) {
        $this->_category[$key] = $value;
      } elseif ($this->_in_products) {
        if (array_key_exists($key, $this->_categories)) {
          $this->_add_category_to_product($key, $value);
        } elseif (array_key_exists($key, $this->_attributes)) {
          $attribute = $this->_attributes[$key];
          $code = $this->_get_attribute_code($attribute);

          // make sure to skip attributes that are owned by Magento
          if (!Salsify_Connect_Model_AttributeMapping::isAttributeMagentoOwned($code)) {
            $value = Salsify_Connect_Model_AttributeMapping::castValueByBackendType($value, $attribute['__backend_type']);
            $this->_product[$code] = $value;
          }
        } else {
          self::_log('WARNING: skipping unrecognized attribute id on product: ' . $key);
        }
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_add_nested_value($value);
    }
  }


  private function _start_nested_thing($type) {
    array_push($this->_value_stack, array());
    array_push($this->_type_stack, $type);
  }


  private function _end_nested_thing() {
    $value = array_pop($this->_value_stack);
    $type = array_pop($this->_type_stack);

    if (empty($this->_value_stack)) {
      // at the root of an object, whether product, attribute, category, etc.

      $key = array_pop($this->_key_stack);

      if ($this->_in_attributes) {
        $this->_attribute[$key] = $value;
      } elseif ($this->_in_attribute_values) {
        self::_log("ERROR: in a nested object in attribute_values, but shouldn't be: " . var_export($this->_category, true));
        self::_log("ERROR: nested thing for above error: " . var_export($value, true));
      } elseif ($this->_in_products) {
        if (array_key_exists($key, $this->_attributes)) {
          $code = $this->_get_attribute_code($this->_attributes[$key]);
          $this->_product[$code] = $value;
        } elseif ($key === 'salsify:relations') {
          $this->_product[$key] = $value;
        } elseif ($key === 'salsify:digital_assets') {
          $this->_product[$key] = $value;
        } elseif (array_key_exists($key, $this->_categories)) {
          // multiple categories
          foreach ($value as $catid) {
            $this->_add_category_to_product($key, $catid);
          }
        } else {
          self::_log("ERROR: product has key of undeclared attribute. skipping attribute: " . $key);
        }
      }
    } else {
      // within a nested object of some kind
      $this->_add_nested_value($value);
    }
  }

}

?>