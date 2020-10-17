<?php

namespace JohnStevenson\ProxyDemo\Config;

class ClientConfig extends BaseConfig
{
    public function getRequestConfig($forStreams)
    {
        try {
            return $this->getConfig($forStreams);
        } catch (\Exception $e) {
            $this->halt($e);
        }
    }

    private function getConfig($forStreams)
    {
        $proxy = $this->getParam('--proxy');

        if ($proxyScheme = $this->getSchemeParam($proxy)) {
            $this->checkScheme($proxyScheme);
            list($proxyUrl,) = $this->getProxySettings($proxyScheme);
        } else {
            $proxyUrl = $proxy;
        }

        $target = $this->getParam('--target');

        if ($scheme = $this->getSchemeParam($target)) {
            $request = $this->get('request');
            $targetUrl = $scheme.'://'.$this->get($scheme, $request);
        } else {
            $targetUrl = $target;
        }

        $options = [];

        foreach (['http', 'ssl'] as $key) {
            $items = $this->get('context-'.$key);
            foreach ($items as $name => $value) {
                $options[$key][$name] = $value;
            }
        }

        if ($forStreams) {
            $options['http']['proxy'] = $this->getProxyUrlForStream($proxyUrl);
            $options['http']['ignore_errors'] = true;
        }

        $options['ssl']['crypto_method'] = STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
        $this->addCaBundle($options);

        return [$proxyUrl, $targetUrl, $options];
    }

    private function getSchemeParam($value)
    {
        return (strpbrk($value, ':/.') === false) ? $value : null;
    }
}

