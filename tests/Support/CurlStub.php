<?php

declare(strict_types=1);

namespace BlueFission\SimpleClients\Tests\Support;

class CurlStub
{
    public array $config = [];
    public array $options = [];
    public array $queries = [];
    public string $result = '';
    public int $openCount = 0;
    public int $closeCount = 0;
    public bool $throwOnQuery = false;

    public function config($key, $value = null): self
    {
        if (is_array($key)) {
            $this->config = array_merge($this->config, $key);
        } else {
            $this->config[$key] = $value;
        }
        return $this;
    }

    public function open(): self
    {
        $this->openCount++;
        return $this;
    }

    public function option($key, $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    public function query($data = null): self
    {
        if ($this->throwOnQuery) {
            throw new \RuntimeException('transport failed');
        }

        $this->queries[] = $data;
        return $this;
    }

    public function result(): string
    {
        return $this->result;
    }

    public function connection()
    {
        return null;
    }

    public function close(): self
    {
        $this->closeCount++;
        return $this;
    }
}
