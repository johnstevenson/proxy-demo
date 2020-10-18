<?php

namespace JohnStevenson\ProxyDemo;

class ProxyHeaders
{
    private $headers;
    private $headerMap;
    private $hostHeader;

    public function create(array $headerLines)
    {
        $result = true;
        $headers = array_slice($headerLines, 1);
        $this->headerMap = $this->createHeaderMap($headers);

        if (isset($this->headerMap['host'])) {
            // Unlikely to have more than one host header
            if (count($this->headerMap['host']) > 1) {
                $result = false;
            }
            $this->hostHeader = $this->headerMap['host'][0]['value'];
        }

        $removals = $this->getConnectionEntries();
        $removals[] = 'host';
        $this->headers = $this->removeHeaders($headers, $removals);

        return $result;
    }

    public function getHeaderLines($requestLine)
    {
        $headers = $this->headers;
        array_unshift($headers, $requestLine);
        return $headerLines = implode("\r\n", $headers)."\r\n\r\n";
    }

    public function getHostHeader()
    {
        return $this->hostHeader;
    }

    public function setHostHeader($value)
    {
        // Make Host first header line
        array_unshift($this->headers, $value);
        $this->headerMap = $this->createHeaderMap($this->headers);
    }

    private function createHeaderMap(array $headers)
    {
        $headerMap = [];
        $index = -1;

        foreach ($headers as $header) {
            ++$index;

            if (false === $pos = strpos($header, ':')) {
                continue;
            }

            $name = strtolower(rtrim(substr($header, 0, $pos)));
            $value = trim(substr($header, $pos + 1));

            if (!isset($headerMap[$name])) {
                $headerMap[$name] = [];
            }

            $headerMap[$name][] = ['index' => $index, 'value' => $value];
        }

        return $headerMap;
    }

    private function getConnectionEntries()
    {
        $entries = [];

        // Find connection headers to remove
        if (isset($this->headerMap['connection'])) {
            $entries[] = 'connection';

            foreach ($this->headerMap['connection'] as $item) {
                $value = strtolower($item['value']);
                $names = array_map('trim', explode(',', $value));

                foreach ($names as $name) {
                    if (isset($this->headerMap[$name])) {
                        $entries[] = $name;
                    }
                }
            }
        }

        if (isset($this->headerMap['proxy-connection'])) {
            $entries[] = 'proxy-connection';
        }

        return $entries;
    }

    private function removeHeaders(array $headers, array $names)
    {
        $indexes = [];

        foreach ($names as $name) {
            $name = strtolower($name);

            if (isset($this->headerMap[$name])) {
                foreach ($this->headerMap[$name] as $entry) {
                    $indexes[] = $entry['index'];
                }
            }
        }

        $indexes = array_unique($indexes, SORT_NUMERIC);

        if (empty($indexes)) {
            return $headers;
        }

        sort($indexes, SORT_NUMERIC);
        $newHeaders = [];

        foreach ($headers as $index => $value) {
            if ($indexes && $indexes[0] === $index) {
                array_shift($indexes);
                continue;
            }
            $newHeaders[] = $value;
        }

        $this->headerMap = $this->createHeaderMap($newHeaders);
        return $newHeaders;
    }
}
