<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function chatgpt_session_api_base(): string
{
    return rtrim(getenv('CHATGPT_SESSION_API_BASE') ?: 'http://ai_session_gateway:8190', '/');
}

function chatgpt_request(
    string $method,
    string $pathWithQuery = '',
    ?array $payload = null,
    int $timeoutSeconds = 15
): array
{
    $method = strtoupper($method);
    $url = chatgpt_session_api_base() . $pathWithQuery;
    $timeout = max(5, min($timeoutSeconds, 600));
    $body = null;
    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $body = json_encode($payload ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            $body = '{}';
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#HTTP/\d+\.\d+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
    }

    $decoded = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $decoded,
        'raw' => $raw,
    ];
}

function chatgpt_status(): array
{
    $resp = chatgpt_request('GET', '/status');
    if (!$resp['ok'] || !is_array($resp['body'])) {
        return [
            'ok' => false,
            'auth' => [
                'state' => 'AUTH_UNKNOWN',
            ],
        ];
    }
    $payload = $resp['body'];
    $payload['ok'] = true;
    return $payload;
}

function chatgpt_auth_status(): array
{
    $resp = chatgpt_request('GET', '/auth/status');
    if (!$resp['ok'] || !is_array($resp['body'])) {
        return [
            'ok' => false,
            'state' => 'AUTH_UNKNOWN',
        ];
    }

    $payload = $resp['body'];
    $payload['ok'] = true;
    return $payload;
}

function chatgpt_auth_login_start(): array
{
    return chatgpt_request('POST', '/auth/login/start');
}

function chatgpt_auth_login_status(string $sessionId): array
{
    $resp = chatgpt_request('GET', '/auth/login/status?session_id=' . urlencode($sessionId));
    if (!$resp['ok'] || !is_array($resp['body'])) {
        return [
            'ok' => false,
            'state' => 'AUTH_UNKNOWN',
        ];
    }

    $payload = $resp['body'];
    $payload['ok'] = true;
    return $payload;
}

function chatgpt_auth_login_stop(string $sessionId): array
{
    return chatgpt_request('POST', '/auth/login/stop?session_id=' . urlencode($sessionId));
}

function chatgpt_schema(): array
{
    $resp = chatgpt_request('GET', '/v1/schema');
    if (!$resp['ok'] || !is_array($resp['body'])) {
        return ['ok' => false];
    }
    $payload = $resp['body'];
    $payload['ok'] = true;
    return $payload;
}

function chatgpt_threads_list(int $limit = 100, ?string $projectId = null, ?string $assistantId = null, ?string $q = null): array
{
    $params = ['limit' => max(1, min($limit, 500))];
    if ($projectId !== null && $projectId !== '') {
        $params['project_id'] = $projectId;
    }
    if ($assistantId !== null && $assistantId !== '') {
        $params['assistant_id'] = $assistantId;
    }
    if ($q !== null && $q !== '') {
        $params['q'] = $q;
    }
    return chatgpt_request('GET', '/v1/threads?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
}

function chatgpt_thread_upsert(array $payload): array
{
    return chatgpt_request('POST', '/v1/threads', $payload);
}

function chatgpt_thread_get(string $threadId): array
{
    return chatgpt_request('GET', '/v1/threads/' . rawurlencode($threadId));
}

function chatgpt_messages_list(string $threadId, int $limit = 200): array
{
    $limit = max(1, min($limit, 1000));
    return chatgpt_request(
        'GET',
        '/v1/threads/' . rawurlencode($threadId) . '/messages?limit=' . $limit
    );
}

function chatgpt_message_create(string $threadId, array $payload): array
{
    return chatgpt_request(
        'POST',
        '/v1/threads/' . rawurlencode($threadId) . '/messages',
        $payload
    );
}

function chatgpt_thread_exchange(string $threadId, array $payload): array
{
    return chatgpt_request(
        'POST',
        '/v1/threads/' . rawurlencode($threadId) . '/exchange',
        $payload,
        240
    );
}

function chatgpt_thread_exchange_start(string $threadId, array $payload): array
{
    return chatgpt_request(
        'POST',
        '/v1/threads/' . rawurlencode($threadId) . '/exchange/start',
        $payload,
        30
    );
}

function chatgpt_exchange_status(string $exchangeId): array
{
    return chatgpt_request(
        'GET',
        '/v1/exchanges/' . rawurlencode($exchangeId),
        null,
        30
    );
}

function chatgpt_thread_sync_history(string $threadId, array $payload = []): array
{
    return chatgpt_request(
        'POST',
        '/v1/threads/' . rawurlencode($threadId) . '/sync_history',
        $payload,
        240
    );
}

function chatgpt_sync_start(string $kind, array $payload = []): array
{
    $route = match ($kind) {
        'threads_scan' => '/v1/sync/threads_scan/start',
        'messages_pull' => '/v1/sync/messages_pull/start',
        'full_sync' => '/v1/sync/full/start',
        default => '',
    };
    if ($route === '') {
        return [
            'ok' => false,
            'status' => 400,
            'body' => ['detail' => 'SYNC_KIND_NOT_SUPPORTED'],
        ];
    }
    return chatgpt_request('POST', $route, $payload, 240);
}

function chatgpt_sync_job_status(string $jobId): array
{
    return chatgpt_request(
        'GET',
        '/v1/sync/jobs/' . rawurlencode($jobId),
        null,
        30
    );
}

function chatgpt_events_list(int $limit = 100, ?string $threadId = null): array
{
    $params = ['limit' => max(1, min($limit, 1000))];
    if ($threadId !== null && $threadId !== '') {
        $params['thread_id'] = $threadId;
    }
    return chatgpt_request('GET', '/v1/events?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
}

function chatgpt_event_create(array $payload): array
{
    return chatgpt_request('POST', '/v1/events', $payload);
}
