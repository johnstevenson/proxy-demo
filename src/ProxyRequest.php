<?php

namespace JohnStevenson\ProxyDemo;

use JohnStevenson\ProxyDemo\Output\ProxyOutput;
use Workerman\Connection\TcpConnection;

class ProxyRequest
{
    private $buffer;
    /** @var TcpConnection */
    private $connection;
    private $headerLines;
    private $errorMessage;
    /** @var ProxyOutput */
    private $output;
    private $values;

    public function __construct(ProxyOutput $output, TcpConnection $connection, $buffer)
    {
        $this->buffer = $buffer;
        unset($buffer);
        $this->output = $output;
        $this->connection = $connection;
        $this->values = $this->checkValues();
    }

    public function getValues()
    {
        if (empty($this->values)) {
            $this->closeConnection();
        } else {
            $this->output->notifyRequest($this->connection, $this->headerLines);
            return $this->values;
        }
    }

    private function checkValues()
    {
        if (!$this->checkMessage($headerLines, $bodyIndex)) {
            return;
        }
        if (!$this->checkRequestLine($headerLines, $method, $uri)) {
            return;
        }

        $headerManager = new ProxyHeaders();
        if (!$headerManager->create($headerLines)) {
            return;
        }

        $hostHeader = $headerManager->getHost();

        if ($method === 'CONNECT') {
            if (!$addr = $this->checkMethodConnect($method, $uri, $bodyIndex)) {
                return;
            }
            $message = null;
        } else {
            if (!$this->checkUri($method, $uri, $hostHeader, $host, $port, $path)) {
                return;
            }

            if (!$this->checkConnection($host, $port)) {
                return;
            }

            $addr = $host.':'.$port;
            $hostHeader = sprintf('Host: %s', $port === 80 ? $host : $addr);
            $headerManager->setHost($hostHeader);

            // We cannot access the piped streams (because they are overwritten)
            // so we use HTTP/1.0 which will not let the connection hang.
            $requestLine = sprintf('%s %s HTTP/1.0', $method, $path);
            $headerLines = $headerManager->getHeaderLines($requestLine);

            $message = $headerLines.substr($this->buffer, $bodyIndex);
            unset($this->buffer);
        }

        return [$method, $addr, $message];
    }

    private function checkMessage(&$headerLines, &$bodyIndex)
    {
        if (false === $headerEnd = strpos($this->buffer, "\r\n\r\n")) {
            return;
        }
        $bodyIndex = $headerEnd + 4;
        $headerLines = explode("\r\n", substr($this->buffer, 0, $headerEnd));
        return !empty($headerLines);
    }

    private function checkMethodConnect($method, $uri, $bodyIndex)
    {
        // We should not have a body
        if ($bodyIndex !== strlen($this->buffer)) {
            return;
        }
        // We should have a host and port
        if (!$this->splitHostPort($uri, $method, $host, $port)) {
            return;
        }

        if ($port === 25 || !$this->checkConnection($host, $port)) {
            return;
        }
        return $host.':'.$port;
    }

    private function checkUri($method, $uri, $hostHeader, &$host, &$port, &$path)
    {
        $parts = parse_url($uri);

        if (isset($parts['scheme'])) {
            // We have a full uri
            $uri = substr($uri, strlen($parts['scheme']) + 3);
            $tmp = explode('/', $uri, 2);
            $host = $tmp[0];
            $path = isset($tmp[1]) ? '/'.$tmp[1] : null;

            if (!$path) {
                // We should use * for OPTIONS, but not all servers like it
                $path = '/';
            }

            if (isset($parts['port'])) {
                $port = (int) $parts['port'];
            } else {
                if (!$this->splitHostPort($host, $method, $unused, $port)) {
                    return false;
                }
            }

        } else {
            $path = $uri;

            if (!$this->splitHostPort($hostHeader, $method, $host, $port)) {
                return false;
            }
        }
        return true;
    }

    private function checkConnection($host, $port)
    {
        $hostIp = ltrim(rtrim($host, ']'), '[');

        if (!filter_var($hostIp, FILTER_VALIDATE_IP)) {
            // Resolve host name
            $hostIp = gethostbyname($host);
            if ($hostIp === $host) {
                // Failed
                return true;
            }
        }

        $proxyIp = ltrim(rtrim($this->connection->getLocalIp(), ']'), '[');
        if ($hostIp !== $proxyIp) {
            return true;
        }
        return $port !== $this->connection->getLocalPort();
    }

    private function checkRequestLine(array $headerLines, &$method, &$uri)
    {
        $method = $uri = null;
        $parts = explode(' ', $headerLines[0]);

        if (count($parts) === 3) {
            $method = $parts[0];
            $uri = $parts[1];
            $httpVersion = substr(strstr($parts[2], 'HTTP/'), 5);
        }

        if (empty($method) || empty($uri) || empty($httpVersion)) {
            return false;
        }

        // Promote headerlines so they can be output
        $this->headerLines = $headerLines;

        if (!preg_match('/^[A-Z]+$/', $method)) {
            $message = sprintf("request method case: '%s'", $method);
            $this->errorMessage = $message;
            return false;
        }
        if (!preg_match('/^\d(?:\.\d)?$/', $httpVersion) || empty((int) $httpVersion)) {
            $message = sprintf("unexpected HTTP version: 'HTTP/%s'", $httpVersion);
            $this->errorMessage = $message;
            return false;
        }
        return true;
    }

    private function closeConnection()
    {
        if ($this->headerLines) {
            $this->output->notifyRequest($this->connection, $this->headerLines);
        }
        if ($this->errorMessage) {
            $this->output->notifyError($this->connection, $this->errorMessage);
        }
        $this->output->notifyBadRequest($this->connection);

        $headers = [
            'HTTP/1.1 400 Bad Request',
            'Connection: close',
        ];

        $this->connection->close(implode("\r\n", $headers)."\r\n\r\n", true);
    }

    private function splitHostPort($uri, $method, &$host, &$port)
    {
        $host = $port = null;
        $forConnect = $method === 'CONNECT';
        $defaultPort = 80;
        $parts = parse_url($uri);
        if (!$parts) {
            return false;
        }

        // parse_url needs a scheme or a port to recognize the host
        if (isset($parts['host'])) {
            $host = $parts['host'];
            $port = isset($parts['port']) ? (int) $parts['port'] : null;

            if ($forConnect) {
                // We shouldn't have a scheme for a connect
                return isset($port) && !isset($parts['scheme']);
            }

            $port = isset($port) ? $port : $defaultPort;
            return true;
        }

        if ($index = strrpos($uri, ':') !== false) {
            // Check for IPv6
            if ($uri[0] === '[') {
                $endIndex = strpos($uri, ']');
                if (!$endIndex || $endIndex > $index) {
                    return false;
                }
            }

            $host = substr($uri, $index - 1);
            $port = (int) substr($uri, $index + 1);
        } else {
            $host = $uri;
        }

        if ($forConnect) {
            // A connect must have a port
            return isset($port);
        }

        $port = isset($port) ? $port : $defaultPort;
        return true;
    }
}
