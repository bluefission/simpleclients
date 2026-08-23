# Client Contracts

SimpleClients clients should expose provider access through small, reusable contracts. The contracts are intentionally generic so they can describe record systems, scheduling providers, media services, notification services, intelligence providers, and other external APIs without coupling the package to one consuming application.

## Core Objects

- `ClientConfig`: auth, base URL, headers, and transport options.
- `ClientRequest`: method, URL, headers, query, body, and options.
- `ClientResponse`: status, headers, body, normalized data, error, and metadata.
- `ClientCapabilities`: service name, actions, auth modes, transports, retry metadata, and config keys.
- `ClientInterface`: configure, capabilities, and send methods for clients that need a generic transport contract.

Existing clients may keep their direct public methods. New generic clients should use these contracts when they need a common request/response boundary or when provider capability metadata is useful.

## Auth And Configuration

Credentials should be passed through `ClientConfig` or explicit constructors. Environment fallback is acceptable for convenience, but package code should not require consumer-specific variable names.

Recommended config keys:

- `auth.api_key`
- `auth.token`
- `auth.secret`
- `base_url`
- `headers`
- `options.timeout`
- `options.retry`

## Example Capability Shapes

Record-oriented service:

```php
new ClientCapabilities([
    'service' => 'records',
    'actions' => ['list', 'read', 'create', 'update'],
    'auth' => ['api_key', 'oauth_token'],
    'config' => ['base_url', 'workspace_id'],
]);
```

Scheduling service:

```php
new ClientCapabilities([
    'service' => 'scheduling',
    'actions' => ['availability', 'book', 'cancel'],
    'auth' => ['api_key'],
    'retry' => ['safe_methods' => ['GET']],
]);
```

Media service:

```php
new ClientCapabilities([
    'service' => 'media',
    'actions' => ['upload', 'transcode', 'status'],
    'auth' => ['token'],
    'transports' => ['http', 'signed_http'],
]);
```

Notification service:

```php
new ClientCapabilities([
    'service' => 'notifications',
    'actions' => ['send', 'status'],
    'auth' => ['api_key'],
    'config' => ['from', 'template'],
]);
```

Automation service:

```php
new ClientCapabilities([
    'service' => 'zapier',
    'actions' => ['search_zaps', 'create_zap', 'configure_zap'],
    'auth' => ['oauth_token'],
    'retry' => ['safe_methods' => ['GET']],
]);
```
