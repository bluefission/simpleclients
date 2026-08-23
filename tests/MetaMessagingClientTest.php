<?php

declare(strict_types=1);

namespace BlueFission\SimpleClients\Tests;

use BlueFission\SimpleClients\Contracts\ClientConfig;
use BlueFission\SimpleClients\MetaMessagingClient;
use BlueFission\SimpleClients\Tests\Support\HttpClientStub;
use PHPUnit\Framework\TestCase;

class MetaMessagingClientTest extends TestCase
{
    private HttpClientStub $transport;
    private MetaMessagingClient $client;

    protected function setUp(): void
    {
        $this->transport = new HttpClientStub();
        $this->client = new MetaMessagingClient(new ClientConfig([
            'auth' => [
                'token' => 'access-token',
                'app_secret' => 'app-secret',
                'verify_token' => 'verify-token',
            ],
            'options' => [
                'api_version' => 'v25.0',
                'sender_id' => 'page-1',
                'channel' => 'messenger',
            ],
        ]), $this->transport);
    }

    public function testCapabilitiesDescribeMessagingAndWebhookOperations(): void
    {
        $capabilities = $this->client->capabilities();

        $this->assertSame('meta_messaging', $capabilities->service());
        $this->assertContains('send_message', $capabilities->actions());
        $this->assertContains('verify_signature', $capabilities->actions());
        $this->assertContains('oauth_token', $capabilities->auth());
    }

    public function testSendMessageUsesBearerTransportAndMessengerShape(): void
    {
        $this->transport->response = $this->response([
            'recipient_id' => 'person-1',
            'message_id' => 'message-1',
        ]);

        $response = $this->client->sendMessage('person-1', 'Hello');

        $this->assertTrue($response->ok());
        $this->assertSame('message-1', $response->data()['message_id']);
        $call = $this->transport->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://graph.facebook.com/v25.0/page-1/messages', $call['url']);
        $this->assertSame('Bearer access-token', $call['headers']['Authorization']);
        $this->assertSame('person-1', $call['body']['recipient']['id']);
        $this->assertSame('Hello', $call['body']['message']['text']);
        $this->assertSame('RESPONSE', $call['body']['messaging_type']);
    }

    public function testInstagramMessageOmitsMessengerOnlyType(): void
    {
        $client = new MetaMessagingClient(new ClientConfig([
            'auth' => ['token' => 'access-token'],
            'base_url' => 'https://graph.instagram.com',
            'options' => [
                'api_version' => 'v25.0',
                'sender_id' => 'ig-1',
                'channel' => 'instagram',
            ],
        ]), $this->transport);

        $response = $client->sendMessage('ig-user-1', ['text' => 'Hello']);

        $this->assertTrue($response->ok());
        $call = $this->transport->calls[0];
        $this->assertSame('https://graph.instagram.com/v25.0/ig-1/messages', $call['url']);
        $this->assertArrayNotHasKey('messaging_type', $call['body']);
    }

    public function testReplyUsesResponseMessagingType(): void
    {
        $response = $this->client->reply('person-1', ['text' => 'Reply']);

        $this->assertTrue($response->ok());
        $this->assertSame('RESPONSE', $this->transport->calls[0]['body']['messaging_type']);
    }

    public function testWebhookChallengeAcceptsOnlyConfiguredToken(): void
    {
        $success = $this->client->verifyChallenge([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'verify-token',
            'hub.challenge' => 'challenge-value',
        ]);
        $failure = $this->client->verifyChallenge([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wrong-token',
            'hub.challenge' => 'challenge-value',
        ]);

        $this->assertTrue($success->ok());
        $this->assertSame('challenge-value', $success->data());
        $this->assertFalse($failure->ok());
        $this->assertSame(403, $failure->status());
        $this->assertSame('challenge_invalid', $failure->meta()['code']);
    }

    public function testWebhookSignatureUsesRawPayloadHmac(): void
    {
        $payload = '{"object":"page","entry":[]}';
        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'app-secret');

        $success = $this->client->verifySignature($payload, $signature);
        $failure = $this->client->verifySignature($payload, 'sha256=invalid');

        $this->assertTrue($success->ok());
        $this->assertTrue($success->data());
        $this->assertFalse($failure->ok());
        $this->assertSame(401, $failure->status());
        $this->assertSame('signature_invalid', $failure->meta()['code']);
    }

    public function testProviderErrorsDoNotExposeCredentials(): void
    {
        $this->transport->response = $this->response([
            'error' => [
                'message' => 'Messaging permission is unavailable.',
                'type' => 'OAuthException',
                'code' => 10,
            ],
        ], 403);

        $response = $this->client->sendMessage('person-1', 'Hello');
        $serialized = json_encode($response->toArray());

        $this->assertFalse($response->ok());
        $this->assertSame('Messaging permission is unavailable.', $response->error());
        $this->assertSame('provider_error', $response->meta()['code']);
        $this->assertStringNotContainsString('access-token', $serialized);
        $this->assertStringNotContainsString('app-secret', $serialized);
        $this->assertStringNotContainsString('verify-token', $serialized);
    }

    public function testMissingConfigurationFailsBeforeTransport(): void
    {
        $client = new MetaMessagingClient(new ClientConfig([
            'auth' => ['token' => 'access-token'],
        ]), $this->transport);

        $response = $client->sendMessage('person-1', 'Hello');

        $this->assertFalse($response->ok());
        $this->assertSame('configuration_invalid', $response->meta()['code']);
        $this->assertSame([], $this->transport->calls);
    }

    private function response(array $body, int $status = 200): array
    {
        return [
            'status' => $status,
            'body' => json_encode($body),
            'headers' => ['X-FB-Request-Id' => 'request-1'],
        ];
    }
}
