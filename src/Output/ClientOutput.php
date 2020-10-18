<?php

namespace JohnStevenson\ProxyDemo\Output;

class ClientOutput extends BaseOutput
{
    public function trying($message)
    {
        $this->writeVerbose($message.'...', $newLine = false);
        return $this;
    }

    public function action($message)
    {
        $this->writeLn($message.'...');
        return $this;
    }

    public function connection($caption, $socket)
    {
        if ($this->verbose) {
            $local = stream_socket_get_name($socket, false);
            $remote = stream_socket_get_name($socket, true);
            $this->writeLn(sprintf('%s: %s => %s', $caption, $local, $remote));
        }
        return $this;
    }

    public function curlHttpsWarning(array $curlVersion)
    {
        $curl = 'curl '.$curlVersion['version'];
        $message = sprintf('WARNING %s is built without HTTPS-proxy support', $curl);
        $this->writeLn($message);
    }

    public function fail($error)
    {
        if (is_array($error)) {
            $this->writeLn(PHP_EOL.implode(PHP_EOL, $error));
            $error = '';
        }
        $this->writeLn(sprintf('%sFAILED %s', PHP_EOL, $error));

        exit(1);
    }

    public function info($proxyUrl, $targetUrl)
    {
        $proxyUrl = str_replace(['tcp://', 'ssl://'], ['http://', 'https://'], $proxyUrl);
        $secure = (strpos($proxyUrl, 'https') === 0) ? 'Secure ' : '';

        $this->writeLn(sprintf('%sProxy: %s', $secure, $proxyUrl));
        $this->writeLn(sprintf('Target url: %s', $targetUrl));
        $this->writeLn('');
        return $this;
    }

    public function ok()
    {
        $this->writeVerbose('OK');
        return $this;
    }

    public function success(array $headers, $content)
    {
        if (!empty($headers)) {
            $this->writeVerbose(PHP_EOL.implode(PHP_EOL, $headers));
        }

        $bytes = strlen($content);
        $this->writeLn(sprintf('%s%d bytes returned', PHP_EOL, $bytes));
        exit(0);
    }
}
