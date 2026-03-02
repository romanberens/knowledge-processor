<?php

declare(strict_types=1);

final class ChatOrchestrator
{
    public static function authPayload(string $sessionId): array
    {
        $payload = GatewayProvider::authPayload($sessionId);

        return self::success($payload);
    }

    public static function startExchange(array $input): array
    {
        $prompt = trim((string)($input['chatgpt_prompt'] ?? ''));
        if ($prompt === '') {
            return self::failure(400, 'EMPTY_PROMPT');
        }

        $assistantId = trim((string)($input['chatgpt_assistant_id'] ?? 'chatgpt-5.2'));
        $projectId = trim((string)($input['chatgpt_project_id'] ?? ''));
        $threadId = trim((string)($input['chatgpt_thread_id'] ?? ''));
        $mode = trim((string)($input['chatgpt_mode'] ?? 'default'));
        $threadTitle = SessionManager::deriveThreadTitle(
            trim((string)($input['chatgpt_thread_title'] ?? '')),
            $prompt
        );
        $comparisonPreference = SessionManager::normalizeComparisonPreference(
            (string)($input['chatgpt_comparison_preference'] ?? 'first')
        );

        $threadPayload = SessionManager::buildThreadPayload($threadTitle, $assistantId, $projectId, $threadId);
        $threadResp = GatewayProvider::threadUpsert($threadPayload);
        if (!$threadResp['ok'] || !is_array($threadResp['body'])) {
            $detail = self::extractDetail($threadResp['body'] ?? null, 'THREAD_UPSERT_FAILED');
            return self::failure(502, $detail, [
                'status' => (int)($threadResp['status'] ?? 0),
            ]);
        }

        $resolvedThreadId = trim((string)($threadResp['body']['thread_id'] ?? ''));
        if ($resolvedThreadId === '') {
            $resolvedThreadId = $threadId;
        }
        if ($resolvedThreadId === '') {
            return self::failure(502, 'THREAD_ID_MISSING');
        }

        $exchangePayload = SessionManager::buildExchangePayload(
            $prompt,
            $mode,
            $comparisonPreference,
            $assistantId,
            $projectId
        );
        $startResp = GatewayProvider::exchangeStart($resolvedThreadId, $exchangePayload);
        if (!$startResp['ok'] || !is_array($startResp['body'])) {
            $status = (int)($startResp['status'] ?? 0);
            $detail = self::extractDetail($startResp['body'] ?? null, 'EXCHANGE_START_FAILED');

            return self::failure($status >= 400 ? $status : 502, $detail, [
                'status' => $status,
                'thread_id' => $resolvedThreadId,
            ]);
        }

        return self::success([
            'ok' => true,
            'thread_id' => $resolvedThreadId,
            'thread_title' => $threadTitle,
            'exchange_id' => (string)($startResp['body']['exchange_id'] ?? ''),
            'status' => (string)($startResp['body']['status'] ?? 'queued'),
            'user_message' => $startResp['body']['user_message'] ?? null,
            'assistant_message' => $startResp['body']['assistant_message'] ?? null,
        ]);
    }

    public static function exchangeStatus(string $exchangeId): array
    {
        if ($exchangeId === '') {
            return self::failure(400, 'EXCHANGE_ID_REQUIRED');
        }

        $resp = GatewayProvider::exchangeStatus($exchangeId);
        $status = (int)($resp['status'] ?? 0);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            $detail = self::extractDetail($resp['body'] ?? null, 'EXCHANGE_STATUS_FAILED');
            return self::failure($status >= 400 ? $status : 502, $detail, [
                'status' => $status,
            ]);
        }

        $payload = $resp['body'];
        $payload['ok'] = true;

        return self::success($payload);
    }

    public static function telemetry(array $input): array
    {
        $eventType = trim((string)($input['event_type'] ?? ''));
        if ($eventType === '') {
            return self::failure(400, 'EVENT_TYPE_REQUIRED');
        }

        $threadId = trim((string)($input['thread_id'] ?? ''));
        $resp = GatewayProvider::eventCreate([
            'event_type' => $eventType,
            'thread_id' => $threadId !== '' ? $threadId : null,
            'source' => 'web_panel',
            'payload' => SessionManager::buildTelemetryPayload($input),
        ]);

        if (!$resp['ok']) {
            $detail = self::extractDetail($resp['body'] ?? null, 'TELEMETRY_WRITE_FAILED');
            return self::failure(502, $detail);
        }

        return self::success(['ok' => true]);
    }

    public static function startSync(array $input): array
    {
        $kind = trim((string)($input['sync_kind'] ?? ''));
        if (!in_array($kind, ['threads_scan', 'messages_pull', 'full_sync'], true)) {
            return self::failure(400, 'SYNC_KIND_REQUIRED');
        }

        $resp = GatewayProvider::syncStart($kind, SessionManager::buildSyncPayload($input));
        $status = (int)($resp['status'] ?? 0);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            $detail = self::extractDetail($resp['body'] ?? null, 'SYNC_START_FAILED');
            return self::failure($status >= 400 ? $status : 502, $detail);
        }

        $payload = $resp['body'];
        $payload['ok'] = true;

        return self::success($payload);
    }

    public static function syncJobStatus(string $jobId): array
    {
        if ($jobId === '') {
            return self::failure(400, 'JOB_ID_REQUIRED');
        }

        $resp = GatewayProvider::syncJobStatus($jobId);
        $status = (int)($resp['status'] ?? 0);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            $detail = self::extractDetail($resp['body'] ?? null, 'SYNC_STATUS_FAILED');
            return self::failure($status >= 400 ? $status : 502, $detail);
        }

        $payload = $resp['body'];
        $payload['ok'] = true;

        return self::success($payload);
    }

    public static function syncHistory(array $input): array
    {
        $threadId = trim((string)($input['thread_id'] ?? ''));
        if ($threadId === '') {
            return self::failure(400, 'THREAD_ID_REQUIRED');
        }

        $resp = GatewayProvider::syncHistory($threadId, SessionManager::buildSyncHistoryPayload($input));
        $status = (int)($resp['status'] ?? 0);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            $detail = self::extractDetail($resp['body'] ?? null, 'SYNC_HISTORY_FAILED');
            return self::failure($status >= 400 ? $status : 502, $detail, [
                'status' => $status,
                'thread_id' => $threadId,
            ]);
        }

        $payload = $resp['body'];
        $payload['ok'] = true;

        return self::success($payload);
    }

    private static function success(array $payload): array
    {
        return [
            'ok' => true,
            'http_status' => 200,
            'body' => $payload,
        ];
    }

    private static function failure(int $status, string $detail, array $extra = []): array
    {
        return [
            'ok' => false,
            'http_status' => $status,
            'body' => array_merge(
                [
                    'ok' => false,
                    'detail' => $detail,
                ],
                $extra
            ),
        ];
    }

    private static function extractDetail($body, string $fallback): string
    {
        if (is_array($body) && isset($body['detail'])) {
            $detail = trim((string)$body['detail']);
            if ($detail !== '') {
                return $detail;
            }
        }

        return $fallback;
    }
}
