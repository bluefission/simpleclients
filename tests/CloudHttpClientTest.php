<?php

declare(strict_types=1);

namespace BlueFission\SimpleClients\Tests;

use BlueFission\SimpleClients\Cloud\HttpClient;
use BlueFission\SimpleClients\Tests\Support\CurlStub;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CloudHttpClientTest extends TestCase
{
    public function testRequestResetsCustomMethodAndBodyAcrossReuse(): void
    {
        $curl = new CurlStub();
        $client = new HttpClient($curl);

        $client->request('PATCH', 'https://provider.example/items/1', [], ['name' => 'Updated']);

        $this->assertSame('PATCH', $curl->options[CURLOPT_CUSTOMREQUEST]);
        $this->assertSame('{"name":"Updated"}', $curl->options[CURLOPT_POSTFIELDS]);

        $client->request('GET', 'https://provider.example/items');

        $this->assertSame('GET', $curl->options[CURLOPT_CUSTOMREQUEST]);
        $this->assertNull($curl->options[CURLOPT_POSTFIELDS]);
        $this->assertSame(2, $curl->closeCount);
    }

    public function testRequestClosesTransportAfterException(): void
    {
        $curl = new CurlStub();
        $curl->throwOnQuery = true;
        $client = new HttpClient($curl);

        try {
            $client->request('GET', 'https://provider.example/items');
            $this->fail('Expected transport exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('transport failed', $exception->getMessage());
        }

        $this->assertSame(1, $curl->openCount);
        $this->assertSame(1, $curl->closeCount);
    }
}
