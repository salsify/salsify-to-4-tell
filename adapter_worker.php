<?php
require_once dirname(__FILE__).'/lib/Salsify_API.php';
require_once dirname(__FILE__).'/lib/Salsify_JsonStreamingParser.php';

require_once dirname(__FILE__).'/Salsify4TellAdapter.php';

$payload = getPayload();
$dataUrl = $payload('url');

$salsifyFile = tmpfile();
Salsify_API::downloadData($dataUrl, $salsifyFile);
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
$parser = new Salsify_JsonStreamingParser($salsifyFile, $adapter);
$parser->parse();

// FIXME upload the 4Tell file
// http://live.4-tell.net/Boost2.0/upload/xml/stream
fseek($fourtellFile, 0);
$text = file_get_contents($fourtellFile);
echo "\n\n" . $text . "\n\n";

fclose($fourtellFile);

fclose($salsifyFile);
?>