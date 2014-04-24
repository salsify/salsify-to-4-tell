<?php
require("phar://iron_worker.phar");

$publicationNotification = json_decode($_POST, true);
$dataUrl = $publicationNotification['product_feed_export_url'];

$worker = new IronWorker();
$worker->postTask("adapter", array(
    'url' => $dataUrl,
    'alias' => getenv('FOURTELL_ALIAS')
  )
);
?>