<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function scraper_api_base(): string
{
    return rtrim(getenv('SCRAPER_API_BASE') ?: 'http://scraper:8090', '/');
}

function call_scraper(string $mode, ?int $scrollLimit = null, ?int $hydrateLimit = null): array
{
    $url = scraper_api_base() . '/scrape?mode=' . urlencode($mode);
    if (is_int($scrollLimit) && $scrollLimit > 0) {
        $url .= '&scroll_limit=' . urlencode((string)$scrollLimit);
    }
    if (is_int($hydrateLimit) && $hydrateLimit >= 0) {
        $url .= '&hydrate_limit=' . urlencode((string)$hydrateLimit);
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n",
            'content' => '{}',
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

function call_hydrate_only(
    int $limit = 20,
    int $maxContentLen = 1200,
    bool $onlyWithoutNotes = false,
    ?string $source = null,
    ?string $activityKind = null
): array
{
    $limit = max(1, min($limit, 200));
    $maxContentLen = max(50, min($maxContentLen, 20000));

    $qs = [
        'limit' => (string)$limit,
        'max_content_len' => (string)$maxContentLen,
    ];
    if ($onlyWithoutNotes) {
        $qs['only_without_notes'] = '1';
    }
    if (is_string($source) && $source !== '') {
        $qs['source'] = $source;
    }
    if (is_string($activityKind) && $activityKind !== '') {
        $qs['kind'] = $activityKind;
    }

    $url = scraper_api_base() . '/hydrate?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n",
            'content' => '{}',
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#HTTP/\\d+\\.\\d+\\s+(\\d{3})#', $line, $m)) {
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

function scraper_status(): array
{
    $url = scraper_api_base() . '/status';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') {
        return [
            'ok' => false,
            'runtime' => [
                'running' => false,
                'mode' => null,
                'progress' => ['message' => 'scraper unreachable'],
            ],
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'runtime' => [
                'running' => false,
                'mode' => null,
                'progress' => ['message' => 'invalid scraper response'],
            ],
        ];
    }

    $decoded['ok'] = true;
    return $decoded;
}

function scraper_auth_status(): array
{
    $url = scraper_api_base() . '/auth/status';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') {
        return [
            'ok' => false,
            'state' => 'AUTH_UNKNOWN',
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'state' => 'AUTH_UNKNOWN',
        ];
    }

    $decoded['ok'] = true;
    return $decoded;
}

function scraper_auth_login_start(): array
{
    $url = scraper_api_base() . '/auth/login/start';
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n",
            'content' => '{}',
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#HTTP/\\d+\\.\\d+\\s+(\\d{3})#', $line, $m)) {
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

function scraper_auth_login_status(string $sessionId): array
{
    $url = scraper_api_base() . '/auth/login/status?session_id=' . urlencode($sessionId);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') {
        return [
            'ok' => false,
            'state' => 'AUTH_UNKNOWN',
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'state' => 'AUTH_UNKNOWN',
        ];
    }

    $decoded['ok'] = true;
    return $decoded;
}

function scraper_auth_login_stop(string $sessionId): array
{
    $url = scraper_api_base() . '/auth/login/stop?session_id=' . urlencode($sessionId);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\n",
            'content' => '{}',
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#HTTP/\\d+\\.\\d+\\s+(\\d{3})#', $line, $m)) {
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
