<?php

require 'vendor/autoload.php';

use JohnStevenson\ProxyDemo\Config\ProxyConfig;
use JohnStevenson\ProxyDemo\Output\ProxyOutput;
use JohnStevenson\ProxyDemo\ProxyRequest;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

$doc = <<<DOC
PHP Proxy Server.

Usage:
    server.php --proxy <scheme> [options]

Options:
  -p --proxy <scheme>   Proxy scheme from config [http|https].
  -c --config <file>    Config file other than root config.ini.
  -v --verbose          Show more output.
  -h --help             Show this screen.

Press Ctrl+C to stop the server.
DOC;

$config = new ProxyConfig($doc);
list($addr, $options, $scheme) = $config->getProxyConfig();

$output = new ProxyOutput($config);
unset($config);

$worker = new Worker($addr, $options);
$worker->count = 6;
$worker->name = strtoupper($scheme).'-demo-proxy';
$worker::$processTitle = $worker->name;
$worker->onConnect = $output->onServerConnect();

// Emitted when data is first received from client.
$worker->onMessage = function (TcpConnection $connection, $buffer) use ($output)
{
    $request = new ProxyRequest($output, $connection, $buffer);

    if (!$values = $request->getValues()) {
        // The connection will have been closed
        return;
    }

    list($method, $addr, $message) = $values;

    // Create remote connection.
    $remote_connection = new AsyncTcpConnection("tcp://$addr");

    // Setup listeners
    $remote_connection->onConnect = $output->onRemoteConnect();
    $remote_connection->onError = $output->onRemoteError($connection, $addr);

    // CONNECT.
    if ($method !== 'CONNECT') {
        $remote_connection->send($message);
    // POST GET PUT DELETE etc.
    } else {
        $connection->send("HTTP/1.1 200 OK\r\n\r\n");
    }

    // Setup pipes
    $remote_connection->pipe($connection);
    $connection->pipe($remote_connection);
    @$remote_connection->connect();
};

Worker::runAll();
