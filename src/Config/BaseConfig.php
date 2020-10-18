<?php

namespace JohnStevenson\ProxyDemo\Config;

use Composer\CaBundle\CaBundle;
use Docopt;
use Exception;

class BaseConfig
{
    private $settings;
    private $params;

    public function __construct($doc)
    {
        $docOpt = Docopt::handle($doc, ['exitFullUsage' => true]);
        $this->params = $docOpt->args;

        try {
            $rootDir = realpath(__DIR__.'/../../');
            if ($rootDir !== getcwd()) {
                @chdir($rootDir);
            }

            $this->settings = $this->readConfiguration($rootDir);
            $this->setUserAgent($doc);

        } catch (Exception $e) {
            $this->halt($e);
        }
    }

    public function getParam($name)
    {
        return isset($this->params[$name]) ? $this->params[$name] : null;
    }

    protected function addCaBundle(&$options)
    {
        if (!$options['ssl']['capath'] && !$options['ssl']['cafile']) {
            $caBundle = CaBundle::getSystemCaRootBundlePath();

            if (is_dir($caBundle)) {
                $options['ssl']['capath'] = $caBundle;
                unset($options['ssl']['cafile']);
            } else {
                $options['ssl']['cafile'] = $caBundle;
                unset($options['ssl']['capath']);
            }
        }
    }

    protected function halt(Exception $e)
    {
        die('Configuration Error: '.$e->getMessage().PHP_EOL);
    }

    protected function get($key, array $items = null, $default = null)
    {
        $items = $items !== null ? $items : $this->settings;
        $result = isset($items[$key]) ? $items[$key] : $default;

        if ($result === null && func_num_args() < 3) {
            throw new \RuntimeException($key.' is missing');
        }

        return $result;
    }

    protected function checkScheme($scheme)
    {
        if (!in_array($scheme, ['http', 'https'])) {
            throw new \RuntimeException('Invalid scheme: '.$scheme);
        }

        return $scheme === 'https' ? true : false;
    }

    protected function getProxySettings($key)
    {
        $settings = $this->get($key.'-proxy');
        $host = $this->get('host', $settings);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException('Proxy host must be a domain name');
        }

        $port = $this->get('port', $settings);
        $proxyAddr = sprintf('%s://%s:%s', $key, $host, $port);

        return [$proxyAddr, $settings];
    }

    protected function getProxyUrlForStream($url)
    {
        if (strpos($url, '://') === false) {
            return 'tcp://'.$url;
        }

        return str_replace(['http://', 'https://'], ['tcp://', 'ssl://'], $url);
    }

    private function readConfiguration($rootDir)
    {
        $defaultPath = $rootDir.'/default.conf';
        $defaultSettings = parse_ini_file($defaultPath, true, INI_SCANNER_TYPED);

        if ($configPath = $this->getParam('--config')) {
            if (!file_exists($configPath)) {
                throw new \RuntimeException('Config file missing: '.$configPath);
            }
        } else {
            $configPath = $rootDir.'/settings.conf';
            if (!file_exists($configPath)) {
                $configPath = null;
            }
        }

        if ($configPath) {
            $configSettings = parse_ini_file($configPath, true, INI_SCANNER_TYPED);
            return array_replace_recursive($defaultSettings, $configSettings);
        }

        return $defaultSettings;
    }

    private function setUserAgent($doc)
    {
        if (!preg_match('/(client|curl|streams)\.php/',$doc, $match)) {
            return;
        }

        if (!$context = $this->get('context-http', null, null)) {
            return;
        }

        if ($userAgent = $this->get('user_agent', $context, null)) {
            return;
        }

        $value = sprintf('PHP/%s (proxy-demo-%s)', PHP_VERSION, $match[1]);
        $this->settings['context-http']['user_agent'] = $value;
    }
}
