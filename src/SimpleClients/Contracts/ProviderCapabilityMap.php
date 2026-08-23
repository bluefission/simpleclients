<?php

namespace BlueFission\SimpleClients\Contracts;

use BlueFission\Arr;
use BlueFission\Str;

class ProviderCapabilityMap
{
    private const INTEROP_PROTOCOLS = ['annex', 'synematic'];

    private const CAPABILITIES = [
        'claude' => [
            'service' => 'claude',
            'actions' => ['generate', 'complete', 'respond'],
            'auth' => ['api_key'],
            'transports' => ['http'],
            'config' => ['base_url', 'anthropic_version', 'headers', 'model', 'max_tokens'],
        ],
        'grok' => [
            'service' => 'grok',
            'actions' => ['generate', 'complete', 'respond'],
            'auth' => ['api_key'],
            'transports' => ['http'],
            'config' => ['base_url', 'headers', 'model'],
        ],
        'ocr' => [
            'service' => 'ocr',
            'actions' => ['analyze'],
            'auth' => ['api_key', 'token', 'aws_sigv4'],
            'transports' => ['http'],
            'config' => ['provider', 'endpoint', 'query', 'language', 'detect_orientation', 's3_bucket', 's3_key'],
        ],
        'speech' => [
            'service' => 'speech',
            'actions' => ['transcribe'],
            'auth' => ['api_key', 'token', 'aws_sigv4'],
            'transports' => ['http'],
            'config' => ['provider', 'endpoint', 'query', 'language', 'media_uri', 'job_name'],
        ],
        'video' => [
            'service' => 'video',
            'actions' => ['analyze'],
            'auth' => ['api_key', 'token', 'aws_sigv4'],
            'transports' => ['http'],
            'config' => ['provider', 'endpoint', 'query', 'account_id', 'location', 's3_bucket', 's3_key'],
        ],
        'http_json' => [
            'service' => 'http_json',
            'actions' => ['send'],
            'auth' => ['headers'],
            'transports' => ['http'],
            'config' => ['base_url', 'headers', 'options'],
        ],
        'zapier' => [
            'service' => 'zapier',
            'actions' => ['search_zaps', 'create_zap', 'configure_zap', 'send'],
            'auth' => ['oauth_token'],
            'transports' => ['http'],
            'retry' => ['safe_methods' => ['GET']],
            'config' => ['base_url', 'headers', 'options.steps', 'options.search_limit'],
        ],
    ];

    public static function get(string $provider): ClientCapabilities
    {
        $key = Str::lower($provider);
        $capabilities = Arr::make(self::CAPABILITIES)->getPath($key, [
            'service' => $key,
            'actions' => [],
            'auth' => [],
            'transports' => ['http'],
            'config' => [],
        ]);

        return new ClientCapabilities($capabilities);
    }

    public static function all(): array
    {
        $providers = [];

        foreach (self::CAPABILITIES as $provider => $capabilities) {
            $providers[$provider] = new ClientCapabilities($capabilities);
        }

        return $providers;
    }

    public static function interopManifest(string $provider): array
    {
        $capabilities = self::get($provider);

        return [
            'protocols' => self::INTEROP_PROTOCOLS,
            'owner' => 'simpleclients',
            'service' => $capabilities->service(),
            'actions' => $capabilities->actions(),
            'auth' => $capabilities->auth(),
            'transports' => $capabilities->transports(),
            'config' => $capabilities->config(),
        ];
    }
}
