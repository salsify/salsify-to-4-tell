<?php

require_once dirname(__FILE__).'/lib/Salsify_API.php';
require_once dirname(__FILE__).'/lib/Salsify_JsonStreamingParser.php';

require_once dirname(__FILE__).'/Salsify4TellAdapter.php';


$publicationNotification = json_decode($_POST, true);
$dataUrl = $publicationNotification['product_feed_export_url'];

$salsify = new SalsifyAPI(getenv('SALSIFY_API_KEY'), getenv('SALSIFY_CHANNEL_ID'));

// TODO everything following would all go into a background job

$salsifyFile = tmpfile();

SalsifyAPI::downloadData($dataUrl, $salsifyFile);
fseek($salsifyFile, 0);

$options = array(
  'brand attribute ID' => 'Brand',
  'category attribute ID' => 'Category',
  'product page URL attribute ID' => 'Product Link',
  'image attribute ID' => 'Image',
  'part number attribute ID' => 'UPC'
);
$fourtellFile = tmpfile();
$adapter = new Salsify4TellAdapter($fourtellFile, $options);
$parser = new SalsifyJsonStreamingParser($salsifyFile, $adapter);
$parser->parse();

// FIXME upload the 4Tell file somewhere...
fseek($fourtellFile, 0);
$text = file_get_contents($fourtellFile);
echo "\n\n" . $text . "\n\n";

fclose($fourtellFile);
fclose($salsifyFile);

?>