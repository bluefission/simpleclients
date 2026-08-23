<?php

declare(strict_types=1);

namespace BlueFission\SimpleClients\Tests;

use BlueFission\SimpleClients\Contracts\ClientConfig;
use BlueFission\SimpleClients\Tests\Support\HttpClientStub;
use BlueFission\SimpleClients\ZapierClient;
use PHPUnit\Framework\TestCase;

class ZapierClientTest extends TestCase
{
    private HttpClientStub $transport;
    private ZapierClient $client;

    protected function setUp(): void
    {
        $this->transport = new HttpClientStub();
        $this->client = new ZapierClient(new ClientConfig([
            'auth' => ['token' => 'zapier-token'],
        ]), $this->transport);
    }

    public function testCapabilitiesDescribeWorkflowOperations(): void
    {
        $capabilities = $this->client->capabilities();

        $this->assertSame('zapier', $capabilities->service());
        $this->assertContains('configure_zap', $capabilities->actions());
        $this->assertSame(['oauth_token'], $capabilities->auth());
    }

    public function testSearchZapsUsesBearerAuthAndFiltersLocally(): void
    {
        $this->transport->response = $this->response([
            'data' => [
                ['id' => 'zap-1', 'title' => 'Lead Capture', 'steps' => []],
                ['id' => 'zap-2', 'title' => 'Invoice Follow-up', 'steps' => []],
            ],
        ]);

        $response = $this->client->searchZaps('lead');

        $this->assertTrue($response->ok());
        $this->assertSame('zap-1', $response->data()[0]['id']);
        $this->assertCount(1, $response->data());
        $this->assertSame('GET', $this->transport->calls[0]['method']);
        $this->assertStringContainsString('/v2/zaps?limit=100', $this->transport->calls[0]['url']);
        $this->assertSame('Bearer zapier-token', $this->transport->calls[0]['headers']['Authorization']);
        $this->assertSame('application/vnd.api+json', $this->transport->calls[0]['headers']['Accept']);
    }

    public function testSearchWithoutQueryStillReturnsAStableZapList(): void
    {
        $this->transport->response = $this->response([
            'data' => [['id' => 'zap-1', 'title' => 'Lead Capture', 'steps' => []]],
        ]);

        $response = $this->client->searchZaps();

        $this->assertTrue($response->ok());
        $this->assertSame('zap-1', $response->data()[0]['id']);
        $this->assertSame(1, $response->meta()['matched']);
    }

    public function testCreateZapSendsDocumentedJsonApiShape(): void
    {
        $this->transport->response = $this->response([
            'data' => ['id' => 'zap-1', 'title' => 'Lead Capture'],
        ], 201);
        $steps = [['action' => 'core:trigger', 'inputs' => [], 'authentication' => null]];

        $response = $this->client->createZap('Lead Capture', $steps);

        $this->assertTrue($response->ok());
        $this->assertSame(201, $response->status());
        $this->assertSame('POST', $this->transport->calls[0]['method']);
        $this->assertSame([
            'data' => ['title' => 'Lead Capture', 'steps' => $steps],
        ], $this->transport->calls[0]['body']);
    }

    public function testCreateZapRejectsMissingStepsBeforeTransport(): void
    {
        $response = $this->client->createZap('Incomplete Zap');

        $this->assertFalse($response->ok());
        $this->assertSame('request_invalid', $response->meta()['code']);
        $this->assertSame([], $this->transport->calls);
    }

    public function testConfigureZapPreservesAllStepsAndPatchesMatchedStep(): void
    {
        $this->transport->response = $this->response([
            'data' => [[
                'id' => 'zap-1',
                'title' => 'Lead Capture',
                'steps' => [
                    ['action' => 'core:trigger', 'alias' => 'trigger', 'inputs' => ['source' => 'form']],
                    ['action' => 'core:write', 'alias' => 'store', 'inputs' => ['list' => 'old', 'keep' => true]],
                ],
            ]],
        ]);

        $response = $this->client->configureZap('zap-1', 'store', [
            'inputs' => ['list' => 'new'],
        ]);

        $this->assertTrue($response->ok());
        $this->assertCount(2, $this->transport->calls);
        $patch = $this->transport->calls[1];
        $this->assertSame('PATCH', $patch['method']);
        $this->assertSame('https://api.zapier.com/v2/zaps/zap-1', $patch['url']);
        $this->assertSame('form', $patch['body']['data']['steps'][0]['inputs']['source']);
        $this->assertSame('new', $patch['body']['data']['steps'][1]['inputs']['list']);
        $this->assertTrue($patch['body']['data']['steps'][1]['inputs']['keep']);
    }

    public function testProviderAndMalformedResponsesUseStableErrors(): void
    {
        $this->transport->response = $this->response([
            'errors' => [['title' => 'Unavailable', 'detail' => 'Service unavailable.']],
        ], 503);

        $providerFailure = $this->client->searchZaps();

        $this->assertFalse($providerFailure->ok());
        $this->assertSame('Service unavailable.', $providerFailure->error());
        $this->assertSame('provider_error', $providerFailure->meta()['code']);

        $this->transport->response = ['status' => 200, 'body' => '{broken', 'headers' => []];
        $invalidResponse = $this->client->searchZaps();

        $this->assertFalse($invalidResponse->ok());
        $this->assertSame('response_invalid', $invalidResponse->meta()['code']);
    }

    public function testMissingTokenAndTransportExceptionAreNormalized(): void
    {
        $missingToken = new ZapierClient(new ClientConfig(), $this->transport);

        $authFailure = $missingToken->searchZaps();

        $this->assertSame('credentials_unavailable', $authFailure->meta()['code']);
        $this->assertSame([], $this->transport->calls);

        $transport = new class {
            public function request(): array
            {
                throw new \RuntimeException('offline');
            }
        };
        $client = new ZapierClient(new ClientConfig([
            'auth' => ['token' => 'zapier-token'],
        ]), $transport);

        $transportFailure = $client->searchZaps();

        $this->assertSame('transport_failure', $transportFailure->meta()['code']);
        $this->assertSame(\RuntimeException::class, $transportFailure->meta()['exception']);
    }

    private function response(array $body, int $status = 200): array
    {
        return [
            'status' => $status,
            'body' => json_encode($body),
            'headers' => ['X-Request-Id' => 'req-1'],
        ];
    }
}
