<?php

namespace JohnStevenson\ProxyDemo\Output;

use JohnStevenson\ProxyDemo\Config\BaseConfig;

class BaseOutput
{
    protected $verbose;

    public function __construct(BaseConfig $config)
    {
        $this->verbose = $config->getParam('--verbose');
    }

    public function isVerbose()
    {
        return (bool) $this->verbose;
    }

    public function write($message, $newLine = false)
    {
        printf('%s%s', $message, $newLine ? PHP_EOL : '');
    }

    public function writeLn($message)
    {
        $this->write($message, $newLine = true);
    }

    public function writeVerbose($message, $newLine = true)
    {
        if ($this->verbose) {
            $this->write($message, $newLine);
        }
    }
}
