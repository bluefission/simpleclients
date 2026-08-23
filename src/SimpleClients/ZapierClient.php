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

class ZapierClient implements ClientInterface
{
    private const BASE_URL = 'https://api.zapier.com/v2';

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
        return ProviderCapabilityMap::get('zapier');
    }

    public function searchZaps(string $query = ''): ClientResponse
    {
        $response = $this->send(new ClientRequest([
            'method' => 'GET',
            'url' => '/zaps',
            'query' => ['limit' => $this->searchLimit()],
        ]));

        if (!$response->ok()) {
            return $response;
        }

        $zaps = $this->zapList($response->data());
        $matches = $zaps;

        if (Val::isNotEmpty($query)) {
            $needle = Str::lower($query);
            $matches = Arr::make($zaps)
                ->filter(static function ($zap) use ($needle): bool {
                    if (!Arr::is($zap)) {
                        return false;
                    }

                    $candidate = Arr::make($zap);
                    $text = Str::lower(
                        (string)$candidate->getPath('title', '') . ' ' .
                        (string)$candidate->getPath('id', '')
                    );

                    return Str::has($text, $needle);
                })
                ->values()
                ->toArray();
        }

        return new ClientResponse([
            'status' => $response->status(),
            'headers' => $response->headers(),
            'body' => $response->body(),
            'data' => $matches,
            'meta' => Arr::make($response->meta())->merge([
                'query' => $query,
                'matched' => Arr::size($matches),
            ])->toArray(),
        ]);
    }

    public function createZap(string $name, array $steps = []): ClientResponse
    {
        if (Val::isEmpty($name)) {
            return $this->failure('A Zap name is required.', 'request_invalid');
        }

        if (Arr::isEmpty($steps)) {
            $steps = Arr::make($this->config->options())->getPath('steps', []);
        }

        if (!Arr::is($steps) || Arr::isEmpty($steps)) {
            return $this->failure('At least one Zap step is required.', 'request_invalid');
        }

        return $this->send(new ClientRequest([
            'method' => 'POST',
            'url' => '/zaps',
            'body' => [
                'data' => [
                    'title' => $name,
                    'steps' => $steps,
                ],
            ],
        ]));
    }

    public function configureZap(string $zapId, string $stepKey, array $config): ClientResponse
    {
        if (Val::isEmpty($zapId) || Val::isEmpty($stepKey) || Arr::isEmpty($config)) {
            return $this->failure('A Zap id, step key, and step configuration are required.', 'request_invalid');
        }

        $lookup = $this->send(new ClientRequest([
            'method' => 'GET',
            'url' => '/zaps',
            'query' => ['limit' => $this->searchLimit()],
        ]));

        if (!$lookup->ok()) {
            return $lookup;
        }

        $zap = $this->findZap($this->zapList($lookup->data()), $zapId);
        if ($zap === null) {
            return $this->failure('The requested Zap was not found in the retrieved page.', 'zap_not_found', 404);
        }

        $steps = Arr::make($zap)->getPath('steps', []);
        if (!Arr::is($steps)) {
            return $this->failure('The Zap response did not contain a configurable step list.', 'response_invalid');
        }

        $stepIndex = $this->findStepIndex($steps, $stepKey);
        if ($stepIndex === null) {
            return $this->failure('The requested Zap step was not found.', 'step_not_found', 404);
        }

        $steps[$stepIndex] = Arr::make($steps[$stepIndex])->merge($config)->toArray();

        return $this->send(new ClientRequest([
            'method' => 'PATCH',
            'url' => '/zaps/' . rawurlencode($zapId),
            'body' => ['data' => ['steps' => $steps]],
        ]));
    }

    public function send(ClientRequest $request): ClientResponse
    {
        $token = (string)Arr::make($this->config->auth())->getPath('token', '');
        if (Val::isEmpty($token)) {
            return $this->failure('A Zapier OAuth access token is required.', 'credentials_unavailable');
        }

        if (!method_exists($this->transport, 'request')) {
            return $this->failure('The configured HTTP transport is invalid.', 'transport_invalid');
        }

        $headers = Arr::make([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/vnd.api+json',
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
            return $this->failure('Zapier transport failed.', 'transport_failure', 0, [
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
            'provider' => 'zapier',
            'method' => $request->method(),
            'url' => $this->url($request),
        ];

        if ($data === $invalid) {
            return new ClientResponse([
                'status' => $status,
                'headers' => Arr::is($headers) ? $headers : [],
                'body' => $body,
                'error' => 'Zapier returned malformed JSON.',
                'meta' => Arr::make($meta)->merge(['code' => 'response_invalid'])->toArray(),
            ]);
        }

        $error = $this->providerError(Arr::is($data) ? $data : []);
        if ($status >= 400 || Val::isNotEmpty($error)) {
            return new ClientResponse([
                'status' => $status,
                'headers' => Arr::is($headers) ? $headers : [],
                'body' => $body,
                'data' => $data,
                'error' => $error ?: 'Zapier request failed.',
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

    private function searchLimit(): int
    {
        $limit = (int)Arr::make($this->config->options())->getPath('search_limit', 100);

        return max(1, $limit);
    }

    private function zapList(mixed $data): array
    {
        if (!Arr::is($data)) {
            return [];
        }

        if (array_is_list($data) && Arr::size($data) === 1 && Arr::is($data[0] ?? null)) {
            $data = $data[0];
        }

        $zaps = Arr::make($data)->getPath('data', []);

        return Arr::is($zaps) ? $zaps : [];
    }

    private function findZap(array $zaps, string $zapId): ?array
    {
        foreach ($zaps as $zap) {
            if (Arr::is($zap) && (string)Arr::make($zap)->getPath('id', '') === $zapId) {
                return $zap;
            }
        }

        return null;
    }

    private function findStepIndex(array $steps, string $stepKey): ?int
    {
        foreach ($steps as $index => $step) {
            if (!Arr::is($step)) {
                continue;
            }

            $step = Arr::make($step);
            foreach (['alias', 'action', 'title'] as $field) {
                if ((string)$step->getPath($field, '') === $stepKey) {
                    return (int)$index;
                }
            }
        }

        return null;
    }

    private function providerError(array $data): string
    {
        $error = Arr::make($data)->getPath('errors.0', []);
        if (!Arr::is($error) || Arr::isEmpty($error)) {
            return '';
        }

        return (string)Arr::make($error)->getPath(
            'detail',
            Arr::make($error)->getPath('title', 'Zapier request failed.')
        );
    }

    private function failure(
        string $message,
        string $code,
        int $status = 0,
        array $meta = []
    ): ClientResponse {
        return ClientResponse::failure($message, $status, Arr::make([
            'provider' => 'zapier',
            'code' => $code,
        ])->merge($meta)->toArray());
    }
}
