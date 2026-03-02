<?php

declare(strict_types=1);

final class ChatApiController
{
    public static function routeMap(): array
    {
        return [
            'chatgpt_auth' => [self::class, 'auth'],
            'chatgpt_exchange_start' => [self::class, 'exchangeStart'],
            'chatgpt_exchange_status' => [self::class, 'exchangeStatus'],
            'chatgpt_telemetry' => [self::class, 'telemetry'],
            'chatgpt_sync_start' => [self::class, 'syncStart'],
            'chatgpt_sync_job_status' => [self::class, 'syncJobStatus'],
            'chatgpt_sync_history' => [self::class, 'syncHistory'],
        ];
    }

    public static function handle(string $ajax): bool
    {
        $routes = self::routeMap();
        if (!isset($routes[$ajax])) {
            return false;
        }

        call_user_func($routes[$ajax]);

        return true;
    }

    public static function auth(): void
    {
        $sessionId = (string)($_SESSION['cgpt_login_session_id'] ?? '');
        self::respond(ChatOrchestrator::authPayload($sessionId));
    }

    public static function exchangeStart(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::respondMethodNotAllowed();
        }

        self::respond(ChatOrchestrator::startExchange($_POST));
    }

    public static function exchangeStatus(): void
    {
        $exchangeId = trim((string)($_GET['exchange_id'] ?? ''));
        self::respond(ChatOrchestrator::exchangeStatus($exchangeId));
    }

    public static function telemetry(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::respondMethodNotAllowed();
        }

        self::respond(ChatOrchestrator::telemetry($_POST));
    }

    public static function syncStart(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::respondMethodNotAllowed();
        }

        self::respond(ChatOrchestrator::startSync($_POST));
    }

    public static function syncJobStatus(): void
    {
        $jobId = trim((string)($_GET['job_id'] ?? ''));
        self::respond(ChatOrchestrator::syncJobStatus($jobId));
    }

    public static function syncHistory(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::respondMethodNotAllowed();
        }

        self::respond(ChatOrchestrator::syncHistory($_POST));
    }

    private static function respondMethodNotAllowed(): void
    {
        self::respond([
            'ok' => false,
            'http_status' => 405,
            'body' => [
                'ok' => false,
                'detail' => 'METHOD_NOT_ALLOWED',
            ],
        ]);
    }

    private static function respond(array $result): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code((int)($result['http_status'] ?? 200));
        echo json_encode(
            is_array($result['body'] ?? null) ? $result['body'] : ['ok' => false, 'detail' => 'INVALID_CONTROLLER_RESPONSE'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}
