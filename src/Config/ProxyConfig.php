<?php

namespace JohnStevenson\ProxyDemo\Config;

class ProxyConfig extends BaseConfig
{
    public function __construct($doc)
    {
        parent::__construct($doc);

        // Remove args so that Worker does not treat them as files to run
        $args = [$GLOBALS['argv'][0], 'start'];
        $GLOBALS['argv'] = $_SERVER['argv'] = $args;
        $GLOBALS['argc'] = $_SERVER['argc'] = count($args);
    }

    public function getProxyConfig()
    {
        try {
            return $this->getConfig();
        } catch (\Exception $e) {
            $this->halt($e);
        }
    }

    private function getConfig()
    {
        $scheme = $this->getParam('--proxy');
        $isHttps = $this->checkScheme($scheme);

        list($proxyUrl, $config) = $this->getProxySettings($scheme);
        $addr = $this->getProxyUrlForStream($proxyUrl);

        $options['http']['timeout'] = $this->get('timeout', $config);

        if (!$isHttps) {
            return [$addr, $options, $scheme];
        }

        $keys = ['local_cert', 'local_pk', 'passphrase'];

        foreach ($keys as $key) {
            $options['ssl'][$key] = $this->get($key, $config);
        }

        return [$addr, $options, $scheme];
    }
}

