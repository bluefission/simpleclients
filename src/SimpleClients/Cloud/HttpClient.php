<?php

namespace BlueFission\SimpleClients\Cloud;

use BlueFission\Connections\Curl;
use BlueFission\Net\HTTP;
use BlueFission\Str;
use BlueFission\Val;

class HttpClient
{
    private object $curl;

    public function __construct(?object $curl = null)
    {
        $this->curl = $curl ?? new Curl();
    }

    public function request(string $method, string $url, array $headers = [], $body = null): array
    {
        $method = Str::upper($method);
        $headers = $this->headers($headers, $body);
        $this->curl->config([
            'target' => $url,
            'method' => '',
            'headers' => $headers,
        ]);
        $this->curl->option(CURLOPT_CUSTOMREQUEST, $method);
        $this->curl->option(
            CURLOPT_POSTFIELDS,
            Val::isNull($body) ? null : (is_string($body) ? $body : (string)HTTP::jsonEncode($body))
        );

        try {
            $this->curl->open();
            $this->curl->query();

            $status = 0;
            $connection = $this->curl->connection();
            if ($connection) {
                $info = curl_getinfo($connection);
                $status = $info['http_code'] ?? 0;
            }

            $body = (string)$this->curl->result();
        } finally {
            $this->curl->close();
        }

        return [
            'status' => $status,
            'body' => $body,
            'headers' => [],
        ];
    }

    private function headers(array $headers, mixed $body): array
    {
        if (!is_string($body)) {
            return $headers;
        }

        foreach ($headers as $name => $value) {
            if (is_string($name) && Str::lower($name) === 'content-length') {
                return $headers;
            }

            if (is_int($name) && Str::make((string)$value)->lower()->startsWith('content-length:')) {
                return $headers;
            }
        }

        if (array_is_list($headers)) {
            $headers[] = 'Content-Length: ' . Str::size($body);
        } else {
            $headers['Content-Length'] = Str::size($body);
        }

        return $headers;
    }
}
