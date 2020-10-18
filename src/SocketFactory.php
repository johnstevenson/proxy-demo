<?php

namespace JohnStevenson\ProxyDemo;

class SocketFactory
{
    public static function setStreamSocket($socket)
    {
        stream_set_blocking($socket, false);
        stream_set_read_buffer($socket, 0);
        stream_set_write_buffer($socket, 0);
    }

    public function createClient($address, $context, &$error)
    {
        return $this->createClientInternal($address, $context, false, $error);
    }

    public function createPipeSockets($clientContext, &$lastError)
    {
        $retries = 3;
        $lastError = '';

        while ($retries--) {
            if ($sockets = $this->createPipePair($clientContext, $lastError)) {
                break;
            }
        }
        return $sockets;
    }

    private function createPipePair($clientContext, &$lastError)
    {
        // Create server
        $uri = '127.0.0.1:0';

        if (!$server = $this->createServer($uri, $serverAddress, $error)) {
            $lastError = sprintf('Could not create temp server %s', $uri);
            return;
        }

        // Create server client
        if (!$clientSocket = $this->createClientAsync($serverAddress, $clientContext, $error)) {
            $lastError = sprintf('Connection to temp server %s failed', $address);
            fclose($server);
            return;
        }

        // Create server socket
        if (!$serverSocket = $this->serverAccept($server)) {
            $lastError = sprintf('Temp server %s refused connection from %s', $uri, $serverAddress);
            fclose($clientSocket);
            return;
        }

        fclose($server);

        return [$clientSocket, $serverSocket];
    }

    private function createClientAsync($address, $context, &$error)
    {
        return $this->createClientInternal($address, $context, true, $error);
    }

    private function createServer($address, &$localAddress, &$error)
    {
        $this->setErrorHandler($error);
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

        if ($socket = stream_socket_server($address, $errno, $errstr, $flags)) {
            if ($localAddress = $this->getName($socket, false)) {
                stream_set_blocking($socket, false);
            }
        }
        restore_error_handler();
        return $socket;
    }

    private function serverAccept(&$server)
    {
        $this->setErrorHandler($error);
        if (!$socket = stream_socket_accept($server, 0)) {
            @fclose($server);
            $server = false;
        } else {
            self::setStreamSocket($socket);
        }

        restore_error_handler();
        return $socket;
    }

    private function createClientInternal($address, $context, $isAsync, &$error)
    {
        $this->setErrorHandler($error);
        $flags = STREAM_CLIENT_CONNECT;
        $flags |= $isAsync ? STREAM_CLIENT_ASYNC_CONNECT : 0;
        $timeout = $isAsync ? null : 10;

        if ($socket = stream_socket_client($address, $errno, $errstr, $timeout, $flags, $context)) {
            if ($this->getName($socket, true)) {
                self::setStreamSocket($socket);
            }
        }
        restore_error_handler();
        return $socket;
    }

    private function getName(&$socket, $wantPeer)
    {
        if (!$address = stream_socket_get_name($socket, $wantPeer)) {
            @fclose($socket);
            $socket = false;
        }
        return $address;
    }

    private function setErrorHandler(&$error)
    {
        $error = '';
        set_error_handler(function ($code, $msg) use (&$error){
            $error = $msg;
        });
    }
}
