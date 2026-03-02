<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function strapi_base_url(): string
{
    return rtrim((string)(getenv('STRAPI_BASE_URL') ?: ''), '/');
}

function strapi_api_token(): string
{
    return trim((string)(getenv('STRAPI_API_TOKEN') ?: ''));
}

function strapi_content_type(): string
{
    // Example: "articles" -> /api/articles
    return trim((string)(getenv('STRAPI_CONTENT_TYPE') ?: ''));
}

function strapi_is_configured(): bool
{
    return strapi_base_url() !== '' && strapi_api_token() !== '' && strapi_content_type() !== '';
}

function strapi_endpoint_base(): string
{
    $base = strapi_base_url();
    $ct = trim(strapi_content_type(), '/');
    return $base !== '' && $ct !== '' ? ($base . '/api/' . $ct) : '';
}

/**
 * Strapi v4 request helper.
 * Returns ['ok'=>bool,'status'=>int,'body'=>array|null,'raw'=>string|null]
 */
function strapi_request(string $method, string $url, array $payload = [], ?string $apiToken = null): array
{
    $token = $apiToken !== null ? trim($apiToken) : strapi_api_token();
    $method = strtoupper($method);
    $sendBody = !in_array($method, ['GET', 'HEAD'], true);

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    $json = null;
    if ($sendBody) {
        $json = json_encode(['data' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => null,
                'raw' => null,
            ];
        }
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ];
    if ($sendBody && is_string($json)) {
        $opts[CURLOPT_POSTFIELDS] = $json;
    }
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => $code > 0 ? $code : 0,
            'body' => ['error' => ['message' => $err ?: 'curl error']],
            'raw' => null,
        ];
    }

    $decoded = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
    }

    return [
        'ok' => $code >= 200 && $code < 300,
        'status' => $code,
        'body' => is_array($decoded) ? $decoded : null,
        'raw' => is_string($raw) ? $raw : null,
    ];
}
