<?php

declare(strict_types=1);

function chatgpt_ajax_dispatch(): void
{
    if (($_GET['ajax'] ?? '') === 'chatgpt_auth') {
        $sessionId = (string)($_SESSION['cgpt_login_session_id'] ?? '');
        $authPayload = $sessionId !== '' ? chatgpt_auth_login_status($sessionId) : chatgpt_auth_status();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($authPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (($_GET['ajax'] ?? '') === 'chatgpt_exchange_start') {
        header('Content-Type: application/json; charset=UTF-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'detail' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    
        $prompt = trim((string)($_POST['chatgpt_prompt'] ?? ''));
        $assistantId = trim((string)($_POST['chatgpt_assistant_id'] ?? 'chatgpt-5.2'));
        $projectId = trim((string)($_POST['chatgpt_project_id'] ?? ''));
        $threadId = trim((string)($_POST['chatgpt_thread_id'] ?? ''));
        $mode = trim((string)($_POST['chatgpt_mode'] ?? 'default'));
        $threadTitle = trim((string)($_POST['chatgpt_thread_title'] ?? ''));
        $comparisonPreference = trim((string)($_POST['chatgpt_comparison_preference'] ?? 'first'));
        if (!in_array($comparisonPreference, ['first', 'second'], true)) {
            $comparisonPreference = 'first';
        }
    
        if ($prompt === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'detail' => 'EMPTY_PROMPT'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    
        if ($threadTitle === '') {
            if (function_exists('mb_substr')) {
                $threadTitle = trim((string)mb_substr($prompt, 0, 72));
            } else {
                $threadTitle = trim((string)substr($prompt, 0, 72));
            }
        }
        if ($threadTitle === '') {
            $threadTitle = 'Nowy wątek';
        }
    
        $threadPayload = [
            'title' => $threadTitle,
            'project_id' => $projectId !== '' ? $projectId : null,
            'assistant_id' => $assistantId !== '' ? $assistantId : null,
            'metadata' => [
                'source' => 'web-panel',
            ],
        ];
        if ($threadId !== '') {
            $threadPayload['thread_id'] = $threadId;
        }
    
        $threadResp = chatgpt_thread_upsert($threadPayload);
        if (!$threadResp['ok'] || !is_array($threadResp['body'])) {
            $detail = '';
            if (is_array($threadResp['body']) && isset($threadResp['body']['detail'])) {
                $detail = (string)$threadResp['body']['detail'];
            }
            http_response_code(502);
            echo json_encode(
                [
                    'ok' => false,
                    'detail' => $detail !== '' ? $detail : 'THREAD_UPSERT_FAILED',
                    'status' => (int)($threadResp['status'] ?? 0),
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            exit;
        }
    
        $resolvedThreadId = trim((string)($threadResp['body']['thread_id'] ?? ''));
        if ($resolvedThreadId === '') {
            $resolvedThreadId = $threadId;
        }
        if ($resolvedThreadId === '') {
            http_response_code(502);
            echo json_encode(['ok' => false, 'detail' => 'THREAD_ID_MISSING'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    
        $exchangePayload = [
            'prompt' => $prompt,
            'mode' => $mode !== '' ? $mode : 'default',
            'source' => 'web_panel',
            'comparison_preference' => $comparisonPreference,
            'metadata' => [
                'composer_mode' => $mode !== '' ? $mode : 'default',
            ],
            'assistant_metadata' => [
                'assistant_id' => $assistantId,
                'project_id' => $projectId,
            ],
        ];
    
        $startResp = chatgpt_thread_exchange_start($resolvedThreadId, $exchangePayload);
        $detail = '';
        if (is_array($startResp['body']) && isset($startResp['body']['detail'])) {
            $detail = (string)$startResp['body']['detail'];
        }
    
        if (!$startResp['ok'] || !is_array($startResp['body'])) {
            $status = (int)($startResp['status'] ?? 0);
            if ($status >= 400) {
                http_response_code($status);
            } else {
                http_response_code(502);
            }
            echo json_encode(
                [
                    'ok' => false,
                    'detail' => $detail !== '' ? $detail : 'EXCHANGE_START_FAILED',
                    'status' => $status,
                    'thread_id' => $resolvedThreadId,
                ],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            exit;
        }
    
        echo json_encode(
            [
                'ok' => true,
                'thread_id' => $resolvedThreadId,
                'thread_title' => $threadTitle,
                'exchange_id' => (string)($startResp['body']['exchange_id'] ?? ''),
                'status' => (string)($startResp['body']['status'] ?? 'queued'),
                'user_message' => $startResp['body']['user_message'] ?? null,
                'assistant_message' => $startResp['body']['assistant_message'] ?? null,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }
    
    if (($_GET['ajax'] ?? '') === 'chatgpt_exchange_status') {
        header('Content-Type: application/json; charset=UTF-8');
        $exchangeId = trim((string)($_GET['exchange_id'] ?? ''));
        if ($exchangeId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'detail' => 'EXCHANGE_ID_REQUIRED'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        $resp = chatgpt_exchange_status($exchangeId);
        $status = (int)($resp['status'] ?? 0);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            $detail = '';
            if (is_array($resp['body']) && isset($resp['body']['detail'])) {
                $detail = (string)$resp['body']['detail'];
            }
            if ($status >= 400) {
                http_response_code($status);
            } else {
                http_response_code(502);
            }
            echo json_encode(
                ['ok' => false, 'detail' => $detail !== '' ? $detail : 'EXCHANGE_STATUS_FAILED', 'status' => $status],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        $payload = $resp['body'];
        $payload['ok'] = true;
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (($_GET['ajax'] ?? '') === 'chatgpt_telemetry') {
        header('Content-Type: application/json; charset=UTF-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'detail' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    
        $eventType = trim((string)($_POST['event_type'] ?? ''));
        if ($eventType === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'detail' => 'EVENT_TYPE_REQUIRED'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    
        $threadId = trim((string)($_POST['thread_id'] ?? ''));
        $payload = [
            'tool_id' => trim((string)($_POST['tool_id'] ?? '')),
            'mode' => trim((string)($_POST['mode'] ?? '')),
            'mode_from' => trim((string)($_POST['mode_from'] ?? '')),
            'mode_to' => trim((string)($_POST['mode_to'] ?? '')),
            'assistant_id' => trim((string)($_POST['assistant_id'] ?? '')),
            'project_id' => trim((string)($_POST['project_id'] ?? '')),
            'source' => trim((string)($_POST['source'] ?? 'web_panel')),
            'ts' => gmdate('c'),
        ];
        $payload = array_filter($payload, static fn($v): bool => $v !== '');
    
        $resp = chatgpt_event_create([
            'event_type' => $eventType,
            'thread_id' => $threadId !== '' ? $threadId : null,
            'source' => 'web_panel',
            'payload' => $payload,
        ]);
    
        if (!$resp['ok']) {
            $detail = '';
            if (is_array($resp['body']) && isset($resp['body']['detail'])) {
                $detail = (string)$resp['body']['detail'];
            }
            http_response_code(502);
            echo json_encode(
                ['ok' => false, 'detail' => $detail !== '' ? $detail : 'TELEMETRY_WRITE_FAILED'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (($_GET['ajax'] ?? '') === 'chatgpt_sync_start') {
        header('Content-Type: application/json; charset=UTF-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'detail' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    
        $kind = trim((string)($_POST['sync_kind'] ?? ''));
        if (!in_array($kind, ['threads_scan', 'messages_pull', 'full_sync'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'detail' => 'SYNC_KIND_REQUIRED'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    
        $projectId = trim((string)($_POST['project_id'] ?? ''));
        $assistantId = trim((string)($_POST['assistant_id'] ?? ''));
        $mode = trim((string)($_POST['mode'] ?? 'default'));
        $mirrorDelete = (string)($_POST['mirror_delete_local'] ?? '1');
        $maxRoundsRaw = trim((string)($_POST['max_rounds'] ?? '4000'));
        $maxThreadsRaw = trim((string)($_POST['max_threads'] ?? '5000'));
        $maxRounds = is_numeric($maxRoundsRaw) ? (int)$maxRoundsRaw : 4000;
        $maxThreads = is_numeric($maxThreadsRaw) ? (int)$maxThreadsRaw : 5000;
    
        $payload = [
            'project_id' => $projectId !== '' ? $projectId : null,
            'assistant_id' => $assistantId !== '' ? $assistantId : null,
            'mode' => $mode !== '' ? $mode : 'default',
            'source' => 'web_panel_sync_job',
            'mirror_delete_local' => !in_array(strtolower($mirrorDelete), ['0', 'false', 'off', 'no'], true),
            'max_rounds' => max(8, min(20000, $maxRounds)),
            'max_threads' => max(1, min(20000, $maxThreads)),
        ];
    
        $resp = chatgpt_sync_start($kind, $payload);
        $status = (int)($resp['status'] ?? 0);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            $detail = '';
            if (is_array($resp['body']) && isset($resp['body']['detail'])) {
                $detail = (string)$resp['body']['detail'];
            }
            if ($status >= 400) {
                http_response_code($status);
            } else {
                http_response_code(502);
            }
            echo json_encode(
                ['ok' => false, 'detail' => $detail !== '' ? $detail : 'SYNC_START_FAILED'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        $out = $resp['body'];
        $out['ok'] = true;
        echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (($_GET['ajax'] ?? '') === 'chatgpt_sync_job_status') {
        header('Content-Type: application/json; charset=UTF-8');
        $jobId = trim((string)($_GET['job_id'] ?? ''));
        if ($jobId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'detail' => 'JOB_ID_REQUIRED'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        $resp = chatgpt_sync_job_status($jobId);
        $status = (int)($resp['status'] ?? 0);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            $detail = '';
            if (is_array($resp['body']) && isset($resp['body']['detail'])) {
                $detail = (string)$resp['body']['detail'];
            }
            if ($status >= 400) {
                http_response_code($status);
            } else {
                http_response_code(502);
            }
            echo json_encode(
                ['ok' => false, 'detail' => $detail !== '' ? $detail : 'SYNC_STATUS_FAILED'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        $out = $resp['body'];
        $out['ok'] = true;
        echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (($_GET['ajax'] ?? '') === 'chatgpt_sync_history') {
        header('Content-Type: application/json; charset=UTF-8');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'detail' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    
        $threadId = trim((string)($_POST['thread_id'] ?? ''));
        if ($threadId === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'detail' => 'THREAD_ID_REQUIRED'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    
        $payload = [
            'conversation_url' => trim((string)($_POST['conversation_url'] ?? '')),
            'mode' => trim((string)($_POST['mode'] ?? 'default')),
            'source' => 'web_panel_sync',
        ];
        $payload = array_filter($payload, static fn($v): bool => $v !== '');
        if (!isset($payload['mode'])) {
            $payload['mode'] = 'default';
        }
    
        $resp = chatgpt_thread_sync_history($threadId, $payload);
        $status = (int)($resp['status'] ?? 0);
        if (!$resp['ok'] || !is_array($resp['body'])) {
            $detail = '';
            if (is_array($resp['body']) && isset($resp['body']['detail'])) {
                $detail = (string)$resp['body']['detail'];
            }
            if ($status >= 400) {
                http_response_code($status);
            } else {
                http_response_code(502);
            }
            echo json_encode(
                ['ok' => false, 'detail' => $detail !== '' ? $detail : 'SYNC_HISTORY_FAILED'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            exit;
        }
    
        $out = $resp['body'];
        $out['ok'] = true;
        echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
