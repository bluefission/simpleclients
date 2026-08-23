<?php

declare(strict_types=1);

namespace BlueFission\SimpleClients;

use BlueFission\Arr;
use BlueFission\Net\HTTP;
use BlueFission\SimpleClients\Cloud\HttpClient;
use BlueFission\SimpleClients\Contracts\ClientCapabilities;
use BlueFission\SimpleClients\Contracts\ClientConfig;
use BlueFission\SimpleClients\Contracts\ClientInterface;
use BlueFission\SimpleClients\Contracts\ClientRequest;
use BlueFission\SimpleClients\Contracts\ClientResponse;
use BlueFission\SimpleClients\Contracts\ProviderCapabilityMap;
use BlueFission\Str;
use BlueFission\Val;
use Throwable;

class MetaMessagingClient implements ClientInterface
{
    private const BASE_URL = 'https://graph.facebook.com';

    private ClientConfig $config;
    private object $transport;

    public function __construct(?ClientConfig $config = null, ?object $transport = null)
    {
        $this->config = $config ?? new ClientConfig();
        $this->transport = $transport ?? new HttpClient();
    }

    public function configure(ClientConfig $config): static
    {
        $this->config = $config;

        return $this;
    }

    public function capabilities(): ClientCapabilities
    {
        return ProviderCapabilityMap::get('meta_messaging');
    }

    public function sendMessage(string $recipientId, array|string $message, array $options = []): ClientResponse
    {
        $configuration = Arr::make($this->config->options())->merge($options);
        $version = (string)$configuration->getPath('api_version', '');
        $senderId = (string)$configuration->getPath('sender_id', '');

        if (Val::isEmpty($recipientId) || Val::isEmpty($message)) {
            return $this->failure('A recipient id and message are required.', 'request_invalid');
        }

        if (Val::isEmpty($version) || Val::isEmpty($senderId)) {
            return $this->failure('Meta API version and sender id configuration are required.', 'configuration_invalid');
        }

        $channel = Str::lower((string)$configuration->getPath('channel', 'messenger'));
        if (!Arr::has(['messenger', 'instagram'], $channel)) {
            return $this->failure('The Meta messaging channel must be messenger or instagram.', 'configuration_invalid');
        }

        $body = [
            'recipient' => ['id' => $recipientId],
            'message' => is_string($message) ? ['text' => $message] : $message,
        ];

        if ($channel === 'messenger') {
            $body['messaging_type'] = (string)$configuration->getPath('messaging_type', 'RESPONSE');
        }

        return $this->send(new ClientRequest([
            'method' => 'POST',
            'url' => '/' . rawurlencode($version) . '/' . rawurlencode($senderId) . '/messages',
            'body' => $body,
            'options' => ['channel' => $channel],
        ]));
    }

    public function reply(string $recipientId, array|string $message, array $options = []): ClientResponse
    {
        return $this->sendMessage(
            $recipientId,
            $message,
            Arr::make(['messaging_type' => 'RESPONSE'])->merge($options)->toArray()
        );
    }

    public function verifyChallenge(array $query): ClientResponse
    {
        $auth = $this->config->auth();
        $expectedToken = (string)($auth['verify_token'] ?? '');
        $mode = (string)($query['hub.mode'] ?? $query['hub_mode'] ?? '');
        $token = (string)($query['hub.verify_token'] ?? $query['hub_verify_token'] ?? '');
        $challenge = (string)($query['hub.challenge'] ?? $query['hub_challenge'] ?? '');

        if (Val::isEmpty($expectedToken)) {
            return $this->failure('A webhook verify token is required.', 'credentials_unavailable');
        }

        if ($mode !== 'subscribe' || Val::isEmpty($challenge) || !hash_equals($expectedToken, $token)) {
            return $this->failure('Meta webhook challenge verification failed.', 'challenge_invalid', 403);
        }

        return ClientResponse::success($challenge, 200, [
            'provider' => 'meta',
            'operation' => 'verify_challenge',
        ]);
    }

    public function verifySignature(string $payload, string $signature): ClientResponse
    {
        $secret = (string)($this->config->auth()['app_secret'] ?? '');
        if (Val::isEmpty($secret)) {
            return $this->failure('A Meta app secret is required.', 'credentials_unavailable');
        }

        if (!Str::make($signature)->startsWith('sha256=')) {
            return $this->failure('Meta webhook signature verification failed.', 'signature_invalid', 401);
        }

        $provided = substr($signature, 7);
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $provided)) {
            return $this->failure('Meta webhook signature verification failed.', 'signature_invalid', 401);
        }

        return ClientResponse::success(true, 200, [
            'provider' => 'meta',
            'operation' => 'verify_signature',
        ]);
    }

    public function send(ClientRequest $request): ClientResponse
    {
        $token = (string)($this->config->auth()['token'] ?? '');
        if (Val::isEmpty($token)) {
            return $this->failure('A Meta access token is required.', 'credentials_unavailable');
        }

        if (!method_exists($this->transport, 'request')) {
            return $this->failure('The configured HTTP transport is invalid.', 'transport_invalid');
        }

        $headers = Arr::make([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->merge($this->config->headers())->merge($request->headers())->toArray();

        try {
            $result = $this->transport->request(
                $request->method(),
                $this->url($request),
                $headers,
                $request->body()
            );
        } catch (Throwable $exception) {
            return $this->failure('Meta messaging transport failed.', 'transport_failure', 0, [
                'exception' => $exception::class,
            ]);
        }

        if (!Arr::is($result)) {
            return $this->failure('The HTTP transport returned an invalid result.', 'transport_invalid');
        }

        return $this->normalizeResponse($result, $request);
    }

    private function normalizeResponse(array $result, ClientRequest $request): ClientResponse
    {
        $result = Arr::make($result);
        $status = (int)$result->getPath('status', 0);
        $headers = $result->getPath('headers', []);
        $body = $result->getPath('body', '');
        $invalid = new \stdClass();
        $data = Arr::is($body) ? $body : HTTP::jsonDecode((string)$body, true, $invalid);
        $meta = [
            'provider' => 'meta',
            'method' => $request->method(),
            'url' => $this->url($request),
        ];

        if ($data === $invalid) {
            return new ClientResponse([
                'status' => $status,
                'headers' => Arr::is($headers) ? $headers : [],
                'body' => $body,
                'error' => 'Meta returned malformed JSON.',
                'meta' => Arr::make($meta)->merge(['code' => 'response_invalid'])->toArray(),
            ]);
        }

        $error = Arr::is($data) ? (string)Arr::make($data)->getPath('error.message', '') : '';
        if ($status >= 400 || Val::isNotEmpty($error)) {
            return new ClientResponse([
                'status' => $status,
                'headers' => Arr::is($headers) ? $headers : [],
                'body' => $body,
                'data' => $data,
                'error' => $error ?: 'Meta messaging request failed.',
                'meta' => Arr::make($meta)->merge(['code' => 'provider_error'])->toArray(),
            ]);
        }

        return new ClientResponse([
            'status' => $status,
            'headers' => Arr::is($headers) ? $headers : [],
            'body' => $body,
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    private function url(ClientRequest $request): string
    {
        $baseUrl = Str::trim($this->config->baseUrl() ?: self::BASE_URL, '/');
        $url = $baseUrl . '/' . Str::trim($request->url(), '/');

        if (Arr::isNotEmpty($request->query())) {
            $url .= '?' . HTTP::query($request->query());
        }

        return $url;
    }

    private function failure(
        string $message,
        string $code,
        int $status = 0,
        array $meta = []
    ): ClientResponse {
        return ClientResponse::failure($message, $status, Arr::make([
            'provider' => 'meta',
            'code' => $code,
        ])->merge($meta)->toArray());
    }
}
