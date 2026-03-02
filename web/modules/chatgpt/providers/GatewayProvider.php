<?php

declare(strict_types=1);

final class GatewayProvider
{
    public static function authPayload(string $sessionId): array
    {
        if ($sessionId !== '') {
            return chatgpt_auth_login_status($sessionId);
        }

        return chatgpt_auth_status();
    }

    public static function threadUpsert(array $payload): array
    {
        return chatgpt_thread_upsert($payload);
    }

    public static function exchangeStart(string $threadId, array $payload): array
    {
        return chatgpt_thread_exchange_start($threadId, $payload);
    }

    public static function exchangeStatus(string $exchangeId): array
    {
        return chatgpt_exchange_status($exchangeId);
    }

    public static function eventCreate(array $payload): array
    {
        return chatgpt_event_create($payload);
    }

    public static function syncStart(string $kind, array $payload): array
    {
        return chatgpt_sync_start($kind, $payload);
    }

    public static function syncJobStatus(string $jobId): array
    {
        return chatgpt_sync_job_status($jobId);
    }

    public static function syncHistory(string $threadId, array $payload): array
    {
        return chatgpt_thread_sync_history($threadId, $payload);
    }
}
