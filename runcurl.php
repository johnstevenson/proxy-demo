<?php

require 'vendor/autoload.php';

use JohnStevenson\ProxyDemo\Config\ClientConfig;
use JohnStevenson\ProxyDemo\Output\ClientOutput;

$doc = <<<DOC
PHP Curl Client.

Usage:
    runcurl.php --proxy=<scheme|url> [options]

Options:
  -p --proxy=<scheme|url>   Proxy url from config [http|https], or a specific url.
  -t --target=<scheme|url>  Target url from config [default: http], or a specific url.
  -c --config=<file>        Config file other than root config.ini.
  -v --verbose              Show more output.
  -h --help                 Show this screen.
DOC;

$config = new ClientConfig($doc);
$output = new ClientOutput($config);

list($proxyUrl, $targetUrl, $options) = $config->getRequestConfig($forStreams = false);
$cafile = $options['ssl']['cafile'];

$output->info($proxyUrl, $targetUrl);

$curlHandle = curl_init();

curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curlHandle, CURLOPT_HEADER, true);
curl_setopt($curlHandle, CURLOPT_URL, $targetUrl);
curl_setopt($curlHandle, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($curlHandle, CURLOPT_TIMEOUT, 30);

curl_setopt($curlHandle, CURLOPT_CAINFO, $cafile);
curl_setopt($curlHandle, CURLOPT_PROXY, $proxyUrl);

if (strpos($proxyUrl, 'https') === 0) {
    if (defined('CURLOPT_PROXY_CAINFO')) {
        curl_setopt($curlHandle, CURLOPT_PROXY_CAINFO, $cafile);
    } else {
        $output->curlHttpsWarning(curl_version());
    }
}

if (!empty($options['http']['user_agent'])) {
    $userAgent = $options['http']['user_agent'];
    curl_setopt($curlHandle, CURLOPT_USERAGENT, $userAgent);
}

if ($output->isVerbose()) {
    curl_setopt($curlHandle, CURLOPT_VERBOSE, true);
}

$output->action('Downloading '. $targetUrl, true);
$response = curl_exec($curlHandle);
$headerSize = curl_getinfo($curlHandle, CURLINFO_HEADER_SIZE);
$headers = substr($response, 0, $headerSize);
$content = substr($response, $headerSize);

if ($errno = curl_errno($curlHandle)) {
    $output->fail(curl_strerror($errno));
    curl_close($curlHandle);
}

if ($errno = curl_errno($curlHandle)) {
    $error_message = curl_strerror($errno);
    echo "cURL error ({$errno}):\n {$error_message}", PHP_EOL;
} else {
    echo 'SUCCESS', PHP_EOL;
}
curl_close($curlHandle);
