<?php
require_once dirname(__FILE__).'/lib/Salsify_API.php';
require_once dirname(__FILE__).'/lib/Salsify_JsonStreamingParser.php';

require_once dirname(__FILE__).'/Salsify4TellAdapter.php';

$payload = getPayload();
$dataUrl = $payload('url');

$salsifyFile = tmpfile();
Salsify_API::downloadData($dataUrl, $salsifyFile);
fseek($salsifyFile, 0);

$fourtellFile = tmpfile();
$adapter = new Salsify4TellAdapter($fourtellFile, $options, $payload('alias'));
$parser = new Salsify_JsonStreamingParser($salsifyFile, $adapter);
$parser->parse();
fclose($salsifyFile);

fseek($fourtellFile, 0);
$adapter->uploadTo4Tell($fourtellFile);

fclose($fourtellFile);


?>