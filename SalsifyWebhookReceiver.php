<?php
require("phar://iron_worker.phar");

// see the comments for why this is necessary here:
// http://stackoverflow.com/questions/21052090/how-to-access-json-post-data-in-php
// PHP... :::sigh:::
$postData = file_get_contents('php://input');

$publicationNotification = json_decode($postData, true);
$dataUrl = $publicationNotification['product_feed_export_url'];

echo var_export($dataUrl, true);

$worker = new IronWorker();
$worker->postTask("adapter", array(
    'url' => $dataUrl,
    'alias' => getenv('FOURTELL_ALIAS')
  )
);
?>