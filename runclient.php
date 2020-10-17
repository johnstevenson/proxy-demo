<?php

require 'vendor/autoload.php';

use JohnStevenson\ProxyDemo\Config\ClientConfig;
use JohnStevenson\ProxyDemo\Output\ClientOutput;

$doc = <<<DOC
PHP Proxy Client.

Usage:
    runclient.php --proxy=<scheme|url> [options]

Options:
  -p --proxy=<scheme|url>   Proxy url from config [http|https], or a specific url.
  -t --target=<scheme|url>  Target url from config [default: http], or a specific url.
  -c --config=<file>        Config file other than root config.ini.
  -v --verbose              Show more output.
  -h --help                 Show this screen.
DOC;

$config = new ClientConfig($doc);
list($proxyUrl, $targetUrl, $options) = $config->getRequestConfig($forStreams = false);

$output = new ClientOutput($config);

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

$errMsg = '';
set_error_handler(function ($code, $msg) use (&$errMsg){
    $errMsg = $msg;
});

$context = stream_context_create($options);
$proxySocket = stream_socket_client($proxy, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
restore_error_handler();

if (!$proxySocket) {
    $output->fail('Cannot connect to proxy: '.$errMsg);
}

$output->ok()->connection('Proxy connection', $proxySocket);

prepareStreamSocket($proxySocket);

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

        try {
            list($clientPipe, $serverPipe) = createPipeSockets($context);
        } catch (RuntimeException $e) {
            $output->fail($e->getMessage());
        }

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
    $output->action('GET request through https tunnel for '.$targetUrl);
    $headerLine = formatGetRequest($options, $targetUrl, $host);

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

function formatGetRequest($options, $targetUrl, $host)
{
    if (isset($options['http']['protocol_version'])) {
        $proto = $options['http']['protocol_version'];
    }

    $proto = !empty($proto) ? $proto : 1.0;

    $headers = [sprintf('GET %s HTTP/%d', $targetUrl, $proto)];
    $headers[] = 'Host: '.$host;

    if (!empty($options['http']['user_agent'])) {
        $headers[] = 'User-Agent: '.$options['http']['user_agent'];
    }

    return implode("\r\n", $headers)."\r\n\r\n";
}

function prepareStreamSocket($socket)
{
    stream_set_blocking($socket, false);
    stream_set_read_buffer($socket, 0);
    stream_set_write_buffer($socket, 0);
}

function createPipeSockets($clientContext)
{
    $sockets = [];
    $attempts = 0;
    $lastError = '';

    while ($attempts++ < 3) {
        if ($sockets = createPair($clientContext, $lastError)) {
            break;
        }
    }

    if (!$sockets) {
        throw new RuntimeException($lastError);
    }

    return $sockets;
}

function createPair($clientContext, &$lastError)
{
    // Create server
    $uri = '127.0.0.1:0';
    $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

    $server = @stream_socket_server($uri, $errno, $errstr, $flags);
    if ($server && !$errno) {
        stream_set_blocking($server, false);

        if (!$address = @stream_socket_get_name($server, false)) {
            fclose($server);
            $server = null;
        }
    }

    if (!$server) {
        $lastError = sprintf('Could not create temp server %s', $uri);
        return;
    }

    // Create server client
    $flags = STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT;

    if ($clientSocket = @stream_socket_client($address, $errno, $errstr, null, $flags, $clientContext)) {
        prepareStreamSocket($clientSocket);

        if (false === @stream_socket_get_name($clientSocket, true)) {
            fclose($clientSocket);
            $clientSocket = null;
        }
    }

    if (!$clientSocket) {
        $lastError = sprintf('Connection to temp server %s failed', $address);
        fclose($server);
        return;
    }

    // Create

    if (!$serverSocket = @stream_socket_accept($server, 0)) {
        $lastError = sprintf('Temp server %s refused connection from %s', $uri, $address);
        fclose($clientSocket);
        fclose($server);
        return;
    }

    prepareStreamSocket($serverSocket);
    fclose($server);

    return [$clientSocket, $serverSocket];
}

function readStream($fd, &$data)
{
    $data = '';
    $read = [$fd];
    $write = null;
    $except = null;

    $timeout = 200000;

    while ($read) {
        $ret = @stream_select($read, $write, $except, 0, $timeout);

        if (!$ret) {
            continue;
        }

        if ($read) {
            $buffer = @fread($fd, 8192);
            if (!$buffer && @feof($fd)) {
                return empty($data) ? false : true;
            }
            $data .= $buffer;
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

        $ret = stream_select($read, $write, $except, 0, $timeout);

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

        $ret = stream_select($read, $write, $except, 0, $timeout);

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
