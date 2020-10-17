<?php

namespace JohnStevenson\ProxyDemo\Output;

use JohnStevenson\ProxyDemo\ProxyRequest;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;

class ProxyOutput extends BaseOutput
{
    public function onRemoteConnect()
    {
        if (!$this->verbose) {
            return null;
        }

        return function (TcpConnection $connection) {
            $this->notifyConnection($connection);
        };
    }

    public function onRemoteError(TcpConnection $parent, $addr)
    {
        return function (TcpConnection $connection, $code, $msg) use ($parent, $addr) {
            $id = $parent->id;
            $address = $parent->getLocalAddress();
            $message = 'Internal - unable to connect to '.$addr;
            $this->write($this->formatMessage($id, $address, $message));
        };
    }

    public function onServerConnect()
    {
        if (!$this->verbose) {
            return null;
        }

        return function(TcpConnection $connection) {
            $this->notifyConnection($connection);

            $connection->onSslHandshake = function ($connection) {
                $this->notify($connection, 'Completed SSL handshake');
            };
        };
    }

    public function notify(TcpConnection $connection, $message)
    {
        if ($parent = $this->getParentConnection($connection)) {
            $id = $parent->id;
            $address = $connection->getLocalAddress();
        } else {
            $id = $connection->id;
            $address = $connection->getRemoteAddress();
        }

        $this->write($this->formatMessage($id, $address, $message));
    }

    public function notifyConnection(TcpConnection $connection)
    {
        if ($connection instanceof AsyncTcpConnection) {
            $message = sprintf('Internal - connection to %s', $connection->getRemoteAddress());
        } else {
            $message = 'New connection';
        }

        $this->notify($connection, $message);
    }

    public function notifyBadRequest(TcpConnection $connection)
    {
        $this->notify($connection, 'Bad Request: Connection closed');
    }

    public function notifyRequest(TcpConnection $connection, array $headerLines)
    {
        if (!$this->verbose) {
            $message = $headerLines[0];
        } else {
            $padding = str_repeat(' ', strlen($connection->id) + 5);
            $message = trim(implode("\n".$padding, $headerLines));
        }

        $this->notify($connection, $message);
    }

    public function notifyError(TcpConnection $connection, $message)
    {
        $this->notify($connection, 'Error - '.$message);
    }

    private function formatMessage($id, $address, $message)
    {
        return sprintf('[%s] %s %s%s', $id, $address, $message, PHP_EOL);
    }

    private function getParentConnection(TcpConnection $connection)
    {
        if (count($connection::$connections) > 1) {
            return $connection::$connections[$connection->id - 1];
        }
    }
}
