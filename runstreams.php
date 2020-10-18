<?php

require 'vendor/autoload.php';

use JohnStevenson\ProxyDemo\Config\ClientConfig;
use JohnStevenson\ProxyDemo\Output\ClientOutput;

$doc = <<<DOC
PHP Stream Client.

Usage:
    runstreams.php --proxy=<scheme|url> [options]

Options:
  -p --proxy=<scheme|url>   Proxy url from config [http|https], or a specific url.
  -t --target=<scheme|url>  Target url from config [default: http], or a specific url.
  -c --config=<file>        Config file other than settings.conf.
  -v --verbose              Show more output.
  -h --help                 Show this screen.
DOC;

$config = new ClientConfig($doc);
$output = new ClientOutput($config);

list($proxyUrl, $targetUrl, $options) = $config->getRequestConfig($forStreams = true);

$context = stream_context_create($options);
$output->info($proxyUrl, $targetUrl);

// error handler
error_reporting(-1);
$errors = [];
set_error_handler(function ($errno, $errstr) use (&$errors) {
    $errors[] = $errstr;
});

$output->action('Downloading '. $targetUrl);
$result = file_get_contents($targetUrl, false, $context);

if ($errors) {
    $output->fail($errors);
}

if (!isset($http_response_header)) {
    $output->fail('No data was returned');
}

if (false === strpos($http_response_header[0], ' 200 ')) {
    $output->fail($http_response_header);
}

$output->success($http_response_header, $result);
