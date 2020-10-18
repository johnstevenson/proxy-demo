<?php

require 'vendor/autoload.php';

use JohnStevenson\ProxyDemo\Config\ClientConfig;
use JohnStevenson\ProxyDemo\Output\ClientOutput;
use JohnStevenson\ProxyDemo\SocketFactory;

$doc = <<<DOC
PHP Proxy Client.

Usage:
    runclient.php --proxy=<scheme|url> [options]

Options:
  -p --proxy=<scheme|url>   Proxy url from config [http|https], or a specific url.
  -t --target=<scheme|url>  Target url from config [default: http], or a specific url.
  -c --config=<file>        Config file other than settings.conf.
  -v --verbose              Show more output.
  -h --help                 Show this screen.
DOC;

$config = new ClientConfig($doc);
list($proxyUrl, $targetUrl, $options) = $config->getRequestConfig($forStreams = false);

$output = new ClientOutput($config);
$socketFactory = new SocketFactory();

// Check and parse target url
try {
    list($host, $path) = checkTargetUrl($targetUrl);
} catch (RuntimeException $e) {
    $output->fail($e->getMessage());
}

// Format proxy url and get flags
list($proxy, $secureProxy, $secureHttp) = formatProxyUrl($proxyUrl, $targetUrl);
$output->info($proxyUrl, $targetUrl);

// Connect to proxy
$output->trying('Connect to proxy');

$context = stream_context_create($options);

if (!$proxySocket = $socketFactory->createClient($proxy, $context, $error)) {
    $output->fail('Cannot connect to proxy: '.$error);
}

$output->ok()->connection('Proxy connection', $proxySocket);

if (!$secureHttp) {
    // GET request
    $output->action('GET request for '.$targetUrl);

    $headerLine = formatGetRequest($options, $targetUrl, $host);
    fwrite($proxySocket, $headerLine);
    $result = readStream($proxySocket, $contents);

} else {
    // CONNECT request
    $output->trying('CONNECT request for '.$host);

    $headers = [sprintf('CONNECT %s HTTP/1.0', $host), 'Host: '.$host];
    $headerLine = implode("\r\n", $headers)."\r\n\r\n";
    fwrite($proxySocket, $headerLine);

    if (!readStream($proxySocket, $response)) {
        @fclose($proxySocket);
        $output->fail('No response from CONNECT request to proxy');
    }

    if (!preg_match('{^HTTP/\d\.\d\s+200\s+}', $response)) {
        @fclose($proxySocket);
        $output->fail('Unexpected response from CONNECT request to proxy: '.PHP_EOL.$response);
    }

    $output->ok();

    if ($secureProxy) {
        $output->trying('Create pipe sockets for https tunnel');

        if (!$pipes = $socketFactory->createPipeSockets($context, $error)) {
            $output->fail($error);
        }
        list($clientPipe, $serverPipe) = $pipes;

        $output->ok()->
            connection('  Client pipe', $clientPipe)->
            connection('  Server pipe', $serverPipe);
    }

    // Set up crypto
    $peerName = parse_url($targetUrl, PHP_URL_HOST);
    $output->trying('Enable TLS on tunnel to '.$peerName);

    if ($secureProxy) {
        $tls = enableCrypto($clientPipe, $peerName, $proxySocket, $serverPipe);
    } else {
        $tls = enableCrypto($proxySocket, $peerName, $proxySocket);
    }

    if (!$tls) {
        $output->fail('Cannot enable crypto to '.$peerName);
    }

    $output->ok();

    // GET request through connect tunnel
    $output->action('GET request through tunnel for '.$path);
    $headerLine = formatGetRequest($options, $path, $host);

    if ($secureProxy) {
        fwrite($clientPipe, $headerLine);
        pipeTransaction($proxySocket, $serverPipe);
        $result = readStream($clientPipe, $contents);
    } else {
        fwrite($proxySocket, $headerLine);
        $result = readStream($proxySocket, $contents);
    }
}

fclose($proxySocket);

if ($secureHttp && $secureProxy) {
    fclose($clientPipe);
    fclose($serverPipe);
}

if (!$result) {
    $output->fail('No data was returned');
}

echo PHP_EOL.$contents, PHP_EOL;
exit(0);

function checkTargetUrl($targetUrl)
{
    $parts = parse_url($targetUrl);
    if (!isset($parts['scheme'], $parts['host'])) {
        throw new RuntimeException('Invalid url: '.$targetUrl);
    }

    $host = $parts['host'];
    if (isset($parts['port'])) {
        $host .= ':' . $parts['port'];
    } elseif ($parts['scheme'] === 'http') {
        $host .= ':80';
    } elseif ($parts['scheme'] === 'https') {
        $host .= ':443';
    } else {
        throw new RuntimeException('Invalid url: '.$targetUrl);
    }

    $noScheme = substr($targetUrl, strpos($targetUrl, '://') + 3);
    $path = ($pos = strpos($noScheme, '/')) ? substr($noScheme, $pos) : '/';
    return [$host, $path];
}

function formatProxyUrl($proxyUrl, $targetUrl)
{
    $proxyUrl = str_replace(['http://', 'https://'], ['tcp://', 'ssl://'], $proxyUrl);
    return [
        $proxyUrl,
        strpos($proxyUrl, 'ssl') === 0,
        strpos($targetUrl, 'https') === 0,
    ];
}

function formatGetRequest($options, $uri, $host)
{
    $headers = [sprintf('GET %s HTTP/1.0', $uri)];
    $headers[] = 'Host: '.$host;

    if (!empty($options['http']['user_agent'])) {
        $headers[] = 'User-Agent: '.$options['http']['user_agent'];
    }

    return implode("\r\n", $headers)."\r\n\r\n";
}

function readStream($fd, &$data)
{
    $data = '';
    $timeout = 200000;
    $retries = 10;

    while (1) {
        $read = [$fd];
        $write = null;
        $except = null;

        if (false === @stream_select($read, $write, $except, 0, $timeout)) {
             break;
        }

        if (!$read) {
            if (!$retries-- || $data) {
                break;
            }
            continue;
        }

        while ($buffer = fread($fd, 8192)) {
            $data .= $buffer;
        }

        if (feof($fd)) {
            break;
        }
    }

    return empty($data) ? false : true;
}

function enableCrypto($fdTls, $peerName, $fd, $fdPipe = null)
{
    $timeout = 200000;
    $readFds = $fdPipe ? [$fd, $fdPipe] : [$fd];
    stream_context_set_option($fdTls, 'ssl', 'peer_name', $peerName);

    while (1) {
        $read = $readFds;
        $write = null;
        $except = null;

        if (0 !== $tls = stream_socket_enable_crypto($fdTls, true)) {
            return $tls;
        }

        $ret = @stream_select($read, $write, $except, 0, $timeout);

        if (false === $ret || ($fdPipe && !$read)) {
            return $tls;
        }

        if ($ret && $fdPipe) {
            if (!pipeData($read, $fd, $fdPipe)) {
                return $tls;
            }
        }
    }
    return $tls;
}

function pipeTransaction($fd1, $fd2)
{
    $timeout = 200000;

    while (1) {
        $read = [$fd1, $fd2];
        $write = null;
        $except = null;

        $ret = @stream_select($read, $write, $except, 0, $timeout);

        if (false === $ret || !$read || !pipeData($read, $fd1, $fd2)) {
            return;
        }
    }
}

function pipeData(array $read, $fd1, $fd2)
{
    $writes = 0;

    foreach ($read as $fd) {
        $buffer = fread($fd, 8192);

        if ($buffer) {
            $fdWrite = $fd !== $fd1 ? $fd1 : $fd2;
            fwrite($fdWrite, $buffer, strlen($buffer));
            ++$writes;
        }
    }
    return !empty($writes);
}
