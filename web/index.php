<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/repository.php';
require_once __DIR__ . '/includes/scraper_api.php';
require_once __DIR__ . '/includes/chatgpt_api.php';
require_once __DIR__ . '/includes/strapi_api.php';

$allowedViews = [
    'overview',
    'feed',
    'library',
    'inbox',
    'insights',
    'topic',
    'item',
    'search',
    'runs',
    'login',
    'chatgpt',
    'post',
    'editorial',
];
$view = $_GET['view'] ?? 'overview';
if (!in_array($view, $allowedViews, true)) {
    $view = 'overview';
}

// Application context (UI mode): Knowledge OS vs ChatGPT vs Editorial.
// Context is derived from the view to keep deep links stable.
$appContext = 'knowledge';
if ($view === 'chatgpt') {
    $appContext = 'chatgpt';
} elseif ($view === 'editorial') {
    $appContext = 'editorial';
}

$allowedOverviewTabs = ['archive', 'knowledge', 'ingest', 'quality', 'runs'];
$overviewTab = (string)($_GET['tab'] ?? 'archive');
if (!in_array($overviewTab, $allowedOverviewTabs, true)) {
    $overviewTab = 'archive';
}

$allowedEditorialTabs = ['inbox', 'drafts', 'draft', 'config'];
$editorialTab = 'inbox';
if ($view === 'editorial') {
    $editorialTab = (string)($_GET['tab'] ?? 'inbox');
    if (!in_array($editorialTab, $allowedEditorialTabs, true)) {
        $editorialTab = 'inbox';
    }
}

$allowedChatgptTabs = ['session', 'status'];
$chatgptTab = 'session';
if ($view === 'chatgpt') {
    $chatgptTab = (string)($_GET['tab'] ?? 'session');
    if (!in_array($chatgptTab, $allowedChatgptTabs, true)) {
        $chatgptTab = 'session';
    }
}

if (($_GET['ajax'] ?? '') === 'auth') {
    $sessionId = (string)($_SESSION['li_login_session_id'] ?? '');
    $authPayload = $sessionId !== '' ? scraper_auth_login_status($sessionId) : scraper_auth_status();
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($authPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectView = $view;
    $redirectUrl = null;
    $returnTab = (string)($_POST['return_tab'] ?? '');
    if ($returnTab !== '' && !in_array($returnTab, $allowedOverviewTabs, true)) {
        $returnTab = '';
    }

    if ($action === 'run_scrape') {
        $mode = $_POST['mode'] ?? 'update';
        if (!in_array($mode, ['deep', 'update'], true)) {
            $mode = 'update';
        }

            $scrollLimit = null;
            $scrollRaw = (string)($_POST['scroll_limit'] ?? '');
            if ($scrollRaw !== '') {
                $n = (int)$scrollRaw;
                if ($n > 0) {
                    $scrollLimit = $n;
                }
            }

            $hydrateLimit = null;
            $hydrateRaw = (string)($_POST['hydrate_limit'] ?? '');
            if ($hydrateRaw !== '') {
                $n = (int)$hydrateRaw;
                if ($n >= 0) {
                    $hydrateLimit = $n;
                }
            }

	        $response = call_scraper($mode, $scrollLimit, $hydrateLimit);
	        if ($response['ok']) {
                $params = [];
                if (is_int($scrollLimit)) {
                    $params[] = 'scroll_limit=' . $scrollLimit;
                }
                if (is_int($hydrateLimit)) {
                    $params[] = 'hydrate_limit=' . $hydrateLimit;
                }
                $paramStr = $params ? ' (' . implode(', ', $params) . ')' : '';
	            $_SESSION['flash'] = [
	                'type' => 'success',
	                'text' => sprintf(
                        'Uruchomiono scraper w trybie: %s%s.',
                        $mode,
                        $paramStr
                    ),
	            ];
	        } else {
	            $detail = '';
	            if (is_array($response['body']) && isset($response['body']['detail'])) {
	                $detail = (string)$response['body']['detail'];
	            }
	            if ($response['status'] === 412 || $detail === 'AUTH_REQUIRED') {
	                $_SESSION['flash'] = [
	                    'type' => 'warning',
	                    'text' => 'Wymagane logowanie do LinkedIn. Przejdź do zakładki Login i zaloguj sesję.',
	                ];
	            } elseif ($response['status'] === 409 && $detail === 'LOGIN_SESSION_OPEN') {
	                $_SESSION['flash'] = [
	                    'type' => 'warning',
	                    'text' => 'Masz otwarte okno logowania (noVNC). Zamknij je w zakładce Login, a potem uruchom scraping.',
	                ];
	            } else {
	                $_SESSION['flash'] = [
	                    'type' => 'alert',
	                    'text' => sprintf(
	                        'Nie udało się uruchomić scrapera (HTTP %d)%s',
	                        $response['status'],
	                        $detail ? ': ' . $detail : '.'
	                    ),
	                ];
	            }
	        }
	    }

    if ($action === 'hydrate_only') {
        $limit = 20;
        $limitRaw = (string)($_POST['limit'] ?? '');
        if ($limitRaw !== '') {
            $n = (int)$limitRaw;
            if ($n > 0) {
                $limit = $n;
            }
        }

        $maxLen = 1200;
        $maxLenRaw = (string)($_POST['max_content_len'] ?? '');
        if ($maxLenRaw !== '') {
            $n = (int)$maxLenRaw;
            if ($n > 0) {
                $maxLen = $n;
            }
        }

        $onlyWithoutNotes = (string)($_POST['only_without_notes'] ?? '') === '1';
        $source = (string)($_POST['source'] ?? '');
        $kind = (string)($_POST['kind'] ?? '');

        $response = call_hydrate_only(
            $limit,
            $maxLen,
            $onlyWithoutNotes,
            $source !== '' ? $source : null,
            $kind !== '' ? $kind : null
        );
        if ($response['ok']) {
            $_SESSION['flash'] = [
                'type' => 'success',
                'text' => sprintf(
                    'Uruchomiono hydrate-only (limit=%d, max_content_len=%d).',
                    $limit,
                    $maxLen
                ),
            ];
        } else {
            $detail = '';
            if (is_array($response['body']) && isset($response['body']['detail'])) {
                $detail = (string)$response['body']['detail'];
            }
            if ($response['status'] === 412 || $detail === 'AUTH_REQUIRED') {
                $_SESSION['flash'] = [
                    'type' => 'warning',
                    'text' => 'Wymagane logowanie do LinkedIn. Przejdź do zakładki Login i zaloguj sesję.',
                ];
            } elseif ($response['status'] === 409 && $detail === 'LOGIN_SESSION_OPEN') {
                $_SESSION['flash'] = [
                    'type' => 'warning',
                    'text' => 'Masz otwarte okno logowania (noVNC). Zamknij je w zakładce Login, a potem uruchom hydrate-only.',
                ];
            } else {
                $_SESSION['flash'] = [
                    'type' => 'alert',
                    'text' => sprintf(
                        'Nie udało się uruchomić hydrate-only (HTTP %d)%s',
                        $response['status'],
                        $detail ? ': ' . $detail : '.'
                    ),
                ];
            }
        }
        $redirectView = 'overview';
    }

    if ($action === 'start_login') {
        $resp = scraper_auth_login_start();
        if ($resp['ok'] && is_array($resp['body'])) {
            $_SESSION['li_login_session_id'] = (string)($resp['body']['login_session_id'] ?? '');
            $_SESSION['li_novnc_url'] = (string)($resp['body']['novnc_url'] ?? '');
            $_SESSION['flash'] = [
                'type' => 'success',
                'text' => 'Uruchomiono okno logowania LinkedIn (noVNC).',
            ];
            $redirectView = 'login';
        } else {
            $detail = '';
            if (is_array($resp['body']) && isset($resp['body']['detail'])) {
                $detail = (string)$resp['body']['detail'];
            }
            $_SESSION['flash'] = [
                'type' => 'alert',
                'text' => sprintf(
                    'Nie udało się uruchomić logowania (HTTP %d)%s',
                    $resp['status'],
                    $detail ? ': ' . $detail : '.'
                ),
            ];
        }
    }

	    if ($action === 'stop_login') {
	        $sessionId = (string)($_SESSION['li_login_session_id'] ?? '');
	        if ($sessionId === '') {
	            $sessionId = (string)($_POST['session_id'] ?? '');
	        }
	        if ($sessionId !== '') {
	            $resp = scraper_auth_login_stop($sessionId);
	            unset($_SESSION['li_login_session_id'], $_SESSION['li_novnc_url']);
	            if ($resp['ok']) {
	                $_SESSION['flash'] = [
                    'type' => 'success',
                    'text' => 'Zamknięto okno logowania.',
                ];
            } else {
                $_SESSION['flash'] = [
                    'type' => 'warning',
                    'text' => 'Zamknięto okno logowania (z błędami).',
                ];
            }
        }
    }

    if ($action === 'save_item_note') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $notes = (string)($_POST['notes'] ?? '');
        if ($itemId > 0) {
            $pdo = db();
            save_item_note($pdo, $itemId, $notes);
            $_SESSION['flash'] = [
                'type' => 'success',
                'text' => 'Zapisano notatkę.',
            ];
            $redirectUrl = '/?view=item&id=' . urlencode((string)$itemId);
        }
    }

    if ($action === 'mark_inbox_processed') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $returnQ = trim((string)($_POST['return_q'] ?? ''));
        $returnKind = (string)($_POST['return_kind'] ?? '');
        $returnMode = (string)($_POST['return_mode'] ?? '');
        $returnFocusIds = trim((string)($_POST['return_focus_ids'] ?? ''));
        $returnFocusIdxRaw = trim((string)($_POST['return_focus_idx'] ?? ''));
        if ($itemId > 0) {
            $pdo = db();
            $today = (new DateTimeImmutable('now'))->format('Y-m-d');
            save_item_note($pdo, $itemId, "✓ przejrzane {$today}");
            $_SESSION['flash'] = [
                'type' => 'success',
                'text' => 'Oznaczono jako przejrzane.',
            ];
            $qs = ['view' => 'inbox'];
            if ($returnMode !== '') {
                $qs['mode'] = $returnMode;
            }
            if ($returnKind !== '') {
                $qs['kind'] = $returnKind;
            }
            if ($returnQ !== '') {
                $qs['q'] = $returnQ;
            }
            if ($returnFocusIds !== '' && preg_match('/^[0-9]+(?:,[0-9]+){0,4}$/', $returnFocusIds)) {
                $qs['focus_ids'] = $returnFocusIds;
                if ($returnFocusIdxRaw !== '' && ctype_digit($returnFocusIdxRaw)) {
                    $qs['focus_idx'] = (string)(((int)$returnFocusIdxRaw) + 1);
                }
            }
            $redirectUrl = '/?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
        }
    }

    if ($action === 'add_item_tag') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $tagName = (string)($_POST['tag_name'] ?? '');
        if ($itemId > 0) {
            $pdo = db();
            $added = add_item_tag($pdo, $itemId, $tagName);
            $_SESSION['flash'] = [
                'type' => $added ? 'success' : 'warning',
                'text' => $added ? 'Dodano tag.' : 'Nie dodano tagu (pusty lub już istnieje).',
            ];
            $redirectUrl = '/?view=item&id=' . urlencode((string)$itemId);
        }
    }

	    if ($action === 'remove_item_tag') {
	        $itemId = (int)($_POST['item_id'] ?? 0);
	        $tagName = (string)($_POST['tag_name'] ?? '');
	        if ($itemId > 0) {
	            $pdo = db();
	            $removed = remove_item_tag($pdo, $itemId, $tagName);
	            $_SESSION['flash'] = [
	                'type' => $removed ? 'success' : 'warning',
	                'text' => $removed ? 'Usunięto tag.' : 'Nie usunięto tagu.',
	            ];
	            $redirectUrl = '/?view=item&id=' . urlencode((string)$itemId);
	        }
	    }

    if ($action === 'start_chatgpt_login') {
        $resp = chatgpt_auth_login_start();
        if ($resp['ok'] && is_array($resp['body'])) {
            $_SESSION['cgpt_login_session_id'] = (string)($resp['body']['login_session_id'] ?? '');
            $_SESSION['cgpt_novnc_url'] = (string)($resp['body']['novnc_url'] ?? '');
            $_SESSION['flash'] = [
                'type' => 'success',
                'text' => 'Uruchomiono okno logowania ChatGPT (noVNC).',
            ];
            $redirectUrl = '/?view=chatgpt&tab=session';
        } else {
            $detail = '';
            if (is_array($resp['body']) && isset($resp['body']['detail'])) {
                $detail = (string)$resp['body']['detail'];
            }
            $_SESSION['flash'] = [
                'type' => 'alert',
                'text' => sprintf(
                    'Nie udało się uruchomić logowania ChatGPT (HTTP %d)%s',
                    $resp['status'],
                    $detail ? ': ' . $detail : '.'
                ),
            ];
        }
    }

    if ($action === 'stop_chatgpt_login') {
        $sessionId = (string)($_SESSION['cgpt_login_session_id'] ?? '');
        if ($sessionId === '') {
            $sessionId = (string)($_POST['session_id'] ?? '');
        }
        if ($sessionId !== '') {
            $resp = chatgpt_auth_login_stop($sessionId);
            unset($_SESSION['cgpt_login_session_id'], $_SESSION['cgpt_novnc_url']);
            if ($resp['ok']) {
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'text' => 'Zamknięto okno logowania ChatGPT.',
                ];
            } else {
                $_SESSION['flash'] = [
                    'type' => 'warning',
                    'text' => 'Zamknięto okno logowania ChatGPT (z błędami).',
                ];
            }
            $redirectUrl = '/?view=chatgpt&tab=session';
        }
    }

    if ($action === 'reset_chatgpt_session') {
        $sessionId = (string)($_SESSION['cgpt_login_session_id'] ?? '');
        if ($sessionId === '') {
            $sessionId = (string)($_POST['session_id'] ?? '');
        }
        if ($sessionId !== '') {
            chatgpt_auth_login_stop($sessionId);
        }
        unset($_SESSION['cgpt_login_session_id'], $_SESSION['cgpt_novnc_url']);
        $resp = chatgpt_auth_login_start();
        if ($resp['ok'] && is_array($resp['body'])) {
            $_SESSION['cgpt_login_session_id'] = (string)($resp['body']['login_session_id'] ?? '');
            $_SESSION['cgpt_novnc_url'] = (string)($resp['body']['novnc_url'] ?? '');
            $_SESSION['flash'] = [
                'type' => 'success',
                'text' => 'Zresetowano sesję i uruchomiono nowe okno logowania ChatGPT.',
            ];
        } else {
            $detail = '';
            if (is_array($resp['body']) && isset($resp['body']['detail'])) {
                $detail = (string)$resp['body']['detail'];
            }
            $_SESSION['flash'] = [
                'type' => 'alert',
                'text' => sprintf(
                    'Nie udało się zresetować sesji ChatGPT (HTTP %d)%s',
                    (int)($resp['status'] ?? 0),
                    $detail !== '' ? ': ' . $detail : '.'
                ),
            ];
        }
        $redirectUrl = '/?view=chatgpt&tab=session';
    }

    if ($action === 'chatgpt_send_message') {
        $prompt = trim((string)($_POST['chatgpt_prompt'] ?? ''));
        $assistantId = trim((string)($_POST['chatgpt_assistant_id'] ?? 'chatgpt-5.2'));
        $projectId = trim((string)($_POST['chatgpt_project_id'] ?? ''));
        $threadId = trim((string)($_POST['chatgpt_thread_id'] ?? ''));
        $mode = trim((string)($_POST['chatgpt_mode'] ?? 'default'));
        $comparisonPreference = trim((string)($_POST['chatgpt_comparison_preference'] ?? 'first'));
        $threadTitle = trim((string)($_POST['chatgpt_thread_title'] ?? ''));
        if (!in_array($comparisonPreference, ['first', 'second'], true)) {
            $comparisonPreference = 'first';
        }

        if ($prompt === '') {
            $_SESSION['flash'] = [
                'type' => 'warning',
                'text' => 'Wiadomość jest pusta. Wpisz treść przed wysłaniem.',
            ];
            $qs = [
                'view' => 'chatgpt',
                'tab' => 'session',
                'assistant' => $assistantId,
                'project' => $projectId,
            ];
            if ($threadId !== '') {
                $qs['thread'] = $threadId;
            } else {
                $qs['new_chat'] = '1';
            }
            $redirectUrl = '/?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
        } else {
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
                $_SESSION['flash'] = [
                    'type' => 'alert',
                    'text' => sprintf(
                        'Nie udało się zapisać wątku (HTTP %d)%s',
                        (int)($threadResp['status'] ?? 0),
                        $detail !== '' ? ': ' . $detail : '.'
                    ),
                ];
                $redirectUrl = '/?' . http_build_query(
                    [
                        'view' => 'chatgpt',
                        'tab' => 'session',
                        'assistant' => $assistantId,
                        'project' => $projectId,
                        'new_chat' => '1',
                    ],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
            } else {
                $resolvedThreadId = trim((string)($threadResp['body']['thread_id'] ?? ''));
                if ($resolvedThreadId === '') {
                    $resolvedThreadId = $threadId;
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

                $exchangeResp = chatgpt_thread_exchange($resolvedThreadId, $exchangePayload);
                if ($exchangeResp['ok'] && is_array($exchangeResp['body'])) {
                    $elapsedMs = 0;
                    if (
                        is_array($exchangeResp['body']['exchange'] ?? null)
                        && is_numeric($exchangeResp['body']['exchange']['elapsed_ms'] ?? null)
                    ) {
                        $elapsedMs = (int)$exchangeResp['body']['exchange']['elapsed_ms'];
                    }
                    chatgpt_event_create([
                        'event_type' => 'composer_message_submitted',
                        'thread_id' => $resolvedThreadId,
                        'source' => 'web_panel',
                        'payload' => [
                            'mode' => $mode !== '' ? $mode : 'default',
                            'chars' => strlen($prompt),
                            'elapsed_ms' => $elapsedMs,
                        ],
                    ]);
                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'text' => sprintf(
                            'Wysłano do ChatGPT i zapisano odpowiedź lokalnie (czas: %d ms).',
                            $elapsedMs
                        ),
                    ];
                } else {
                    $detail = '';
                    if (is_array($exchangeResp['body']) && isset($exchangeResp['body']['detail'])) {
                        $detail = (string)$exchangeResp['body']['detail'];
                    }
                    $statusCode = (int)($exchangeResp['status'] ?? 0);
                    if ($statusCode === 412 || $detail === 'AUTH_REQUIRED') {
                        $_SESSION['flash'] = [
                            'type' => 'warning',
                            'text' => 'Sesja ChatGPT wymaga logowania. Otwórz zakładkę Sesja i zaloguj się ponownie.',
                        ];
                    } elseif ($statusCode === 503 && $detail === 'CHALLENGE_PAGE') {
                        $_SESSION['flash'] = [
                            'type' => 'warning',
                            'text' => 'ChatGPT zwrócił stronę ochronną. Odśwież sesję w noVNC i wyślij ponownie.',
                        ];
                    } elseif ($statusCode === 409 && ($detail === 'PROFILE_BUSY' || $detail === 'LOGIN_SESSION_OPEN')) {
                        $_SESSION['flash'] = [
                            'type' => 'warning',
                            'text' => 'Profil jest zajęty przez okno logowania. Zamknij noVNC i spróbuj ponownie.',
                        ];
                    } else {
                        $_SESSION['flash'] = [
                            'type' => 'alert',
                            'text' => sprintf(
                                'Nie udało się wymienić wiadomości z ChatGPT (HTTP %d)%s',
                                $statusCode,
                                $detail !== '' ? ': ' . $detail : '.'
                            ),
                        ];
                    }
                }

                $redirectUrl = '/?' . http_build_query(
                    [
                        'view' => 'chatgpt',
                        'tab' => 'session',
                        'assistant' => $assistantId,
                        'project' => $projectId,
                        'thread' => $resolvedThreadId,
                    ],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
            }
        }
    }

	    if ($action === 'editorial_add_source') {
	        $itemId = (int)($_POST['item_id'] ?? 0);
	        $topic = trim((string)($_POST['portal_topic'] ?? ''));
	        $prioRaw = trim((string)($_POST['priority'] ?? ''));
	        $prio = $prioRaw !== '' ? (int)$prioRaw : 3;
	        if ($itemId > 0) {
	            $pdo = db();
	            $ud = fetch_item_user_data($pdo, $itemId);
	            $notes = (string)($ud['notes'] ?? '');
	            if (!is_processed_with_content($notes)) {
	                $_SESSION['flash'] = [
	                    'type' => 'warning',
	                    'text' => 'Do Redakcji wpuszczamy tylko opracowane merytorycznie itemy. Najpierw dodaj notatkę własnymi słowami.',
	                ];
	                $redirectUrl = '/?view=item&id=' . urlencode((string)$itemId);
	            } else {
	                try {
	                    $res = create_editorial_item_from_source($pdo, $itemId, $topic !== '' ? $topic : null, $prio);
	                    $_SESSION['flash'] = [
	                        'type' => 'success',
	                        'text' => ($res['created'] ?? false) ? 'Dodano do Redakcji (selected).' : 'Ten item już jest w Redakcji.',
	                    ];
	                    $redirectUrl = '/?view=editorial&tab=inbox';
	                } catch (Throwable $e) {
	                    $_SESSION['flash'] = [
	                        'type' => 'alert',
	                        'text' => 'Nie udało się dodać do Redakcji: ' . $e->getMessage(),
	                    ];
	                    $redirectUrl = '/?view=item&id=' . urlencode((string)$itemId);
	                }
	            }
	        }
	    }

	    if ($action === 'editorial_update_item') {
	        $eid = (int)($_POST['editorial_item_id'] ?? 0);
	        $st = trim((string)($_POST['editorial_status'] ?? ''));
	        $tp = trim((string)($_POST['portal_topic'] ?? ''));
	        $prioRaw = trim((string)($_POST['priority'] ?? ''));
	        $prio = $prioRaw !== '' ? (int)$prioRaw : null;

            $returnTo = trim((string)($_POST['return_to'] ?? ''));
            $returnDraftId = (int)($_POST['return_draft_id'] ?? 0);
	
	        // Preserve current filters in redirect.
	        $retStatus = trim((string)($_POST['return_e_status'] ?? ''));
	        $retTopic = trim((string)($_POST['return_e_topic'] ?? ''));
	        $retPrio = trim((string)($_POST['return_e_prio'] ?? ''));
	        $retNoDraft = trim((string)($_POST['return_e_nodraft'] ?? ''));

	        if ($eid > 0) {
	            $pdo = db();
	            $ok = update_editorial_item($pdo, $eid, $st !== '' ? $st : null, $tp !== '' ? $tp : null, is_int($prio) ? $prio : null);
	            $_SESSION['flash'] = [
	                'type' => $ok ? 'success' : 'warning',
	                'text' => $ok ? 'Zapisano zmiany.' : 'Brak zmian do zapisania.',
	            ];
	        }

            if ($returnTo === 'draft' && $returnDraftId > 0) {
                $redirectUrl = '/?' . http_build_query(
                    ['view' => 'editorial', 'tab' => 'draft', 'draft_id' => $returnDraftId],
                    '',
                    '&',
                    PHP_QUERY_RFC3986
                );
            } else {
                $qs = ['view' => 'editorial', 'tab' => 'inbox'];
                if ($retStatus !== '') {
                    $qs['e_status'] = $retStatus;
                }
                if ($retTopic !== '') {
                    $qs['e_topic'] = $retTopic;
                }
                if ($retPrio !== '') {
                    $qs['e_prio'] = $retPrio;
                }
                if ($retNoDraft !== '') {
                    $qs['e_nodraft'] = $retNoDraft;
                }
                $redirectUrl = '/?' . http_build_query($qs, '', '&', PHP_QUERY_RFC3986);
            }
	    }

	    if ($action === 'editorial_create_draft') {
	        $eid = (int)($_POST['editorial_item_id'] ?? 0);
	        if ($eid > 0) {
	            $pdo = db();
	            try {
	                $res = create_editorial_draft_from_item($pdo, $eid);
	                $_SESSION['flash'] = [
	                    'type' => 'success',
	                    'text' => ($res['created'] ?? false) ? 'Utworzono szkic.' : 'Szkic już istnieje.',
	                ];
	                $draftId = (int)($res['id'] ?? 0);
	                if ($draftId > 0) {
	                    $redirectUrl = '/?' . http_build_query(
	                        ['view' => 'editorial', 'tab' => 'draft', 'draft_id' => $draftId],
	                        '',
	                        '&',
	                        PHP_QUERY_RFC3986
	                    );
	                } else {
	                    $redirectUrl = '/?view=editorial&tab=inbox';
	                }
	            } catch (Throwable $e) {
	                $_SESSION['flash'] = [
	                    'type' => 'alert',
	                    'text' => 'Nie udało się utworzyć szkicu: ' . $e->getMessage(),
	                ];
	                $redirectUrl = '/?view=editorial&tab=inbox';
	            }
	        }
	    }

	    if ($action === 'editorial_save_draft') {
	        $draftId = (int)($_POST['draft_id'] ?? 0);
	        if ($draftId > 0) {
	            $fields = [
	                'title' => (string)($_POST['title'] ?? ''),
	                'lead_text' => (string)($_POST['lead_text'] ?? ''),
	                'body' => (string)($_POST['body'] ?? ''),
	                'format' => (string)($_POST['format'] ?? ''),
	                'seo_title' => (string)($_POST['seo_title'] ?? ''),
	                'seo_description' => (string)($_POST['seo_description'] ?? ''),
	            ];
	            $pdo = db();
	            $before = fetch_editorial_draft($pdo, $draftId);
	            $ok = update_editorial_draft($pdo, $draftId, $fields);

	            // Auto-promotion: if user starts writing (lead/body change) while status is selected/draft,
	            // switch the editorial item to in_progress to reflect actual work.
	            $autoPromoted = false;
	            if ($before) {
	                $beforeStatus = (string)($before['editorial_status'] ?? '');
	                if (in_array($beforeStatus, ['selected', 'draft'], true)) {
	                    $oldLead = trim((string)($before['lead_text'] ?? ''));
	                    $oldBody = trim((string)($before['body'] ?? ''));
	                    $newLead = trim((string)($fields['lead_text'] ?? ''));
	                    $newBody = trim((string)($fields['body'] ?? ''));

	                    $hasContent = $newLead !== '' || $newBody !== '';
	                    $leadChanged = $newLead !== $oldLead;
	                    $bodyChanged = $newBody !== $oldBody;
	                    $itemId = (int)($before['editorial_item_id'] ?? 0);
	                    if ($hasContent && ($leadChanged || $bodyChanged) && $itemId > 0) {
	                        $autoPromoted = update_editorial_item($pdo, $itemId, 'in_progress', null, null);
	                    }
	                }
	            }
	            $_SESSION['flash'] = [
	                'type' => ($ok || $autoPromoted) ? 'success' : 'warning',
	                'text' => $autoPromoted
	                    ? 'Zapisano szkic. Status automatycznie ustawiono na in_progress.'
	                    : ($ok ? 'Zapisano szkic.' : 'Brak zmian do zapisania.'),
	            ];
	            $redirectUrl = '/?' . http_build_query(
	                ['view' => 'editorial', 'tab' => 'draft', 'draft_id' => $draftId],
	                '',
	                '&',
	                PHP_QUERY_RFC3986
	            );
	        }
	    }

	    if ($action === 'cms_config_save') {
	        $pdo = db();
	        $existing = get_cms_integration($pdo, 'strapi');
	        $secretOk = trim((string)(getenv('APP_SECRET') ?: '')) !== '';
	        $secretHelp = 'Brak APP_SECRET – ustaw zmienną środowiskową APP_SECRET i zrestartuj kontener web: docker compose up -d --force-recreate web';

	        $baseUrl = rtrim(trim((string)($_POST['cms_base_url'] ?? '')), '/');
	        $contentType = trim((string)($_POST['cms_content_type'] ?? ''));
	        $contentType = trim($contentType);
	        $contentType = trim($contentType, "/ \t\n\r\0\x0B");
	        if (str_starts_with(strtolower($contentType), 'api/')) {
	            $contentType = trim(substr($contentType, 4), '/');
	        }

	        $tokenInput = trim((string)($_POST['cms_api_token'] ?? ''));
	        $enabled = isset($_POST['cms_enabled']) ? 1 : 0;

	        $errors = [];
	        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
	            $errors[] = 'Nieprawidłowy Strapi Base URL.';
	        }
	        if ($contentType === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $contentType) !== 1) {
	            $errors[] = 'Nieprawidłowy Content Type (np. articles).';
	        }

	        $data = [
	            'base_url' => $baseUrl,
	            'content_type' => $contentType,
	            'enabled' => $enabled,
	        ];

	        if ($tokenInput !== '') {
	            if (!$secretOk) {
	                $errors[] = $secretHelp;
	            } else {
	                try {
	                    $data['api_token_enc'] = encrypt_token($tokenInput);
	                } catch (RuntimeException $e) {
	                    $errors[] = $e->getMessage();
	                }
	            }
	        } elseif (!$existing) {
	            // Bootstrap from ENV to avoid breaking integration on first save.
	            if ($enabled === 1) {
	                $bootstrapTok = trim((string)(getenv('STRAPI_API_TOKEN') ?: ''));
	                if ($bootstrapTok === '') {
	                    $errors[] = !$secretOk
	                        ? $secretHelp
	                        : 'Brak API Token. Wklej token w formularzu (lub ustaw STRAPI_API_TOKEN jako bootstrap).';
	                } elseif (!$secretOk) {
	                    $errors[] = $secretHelp;
	                } else {
	                    try {
	                        $data['api_token_enc'] = encrypt_token($bootstrapTok);
	                    } catch (RuntimeException $e) {
	                        $errors[] = $e->getMessage();
	                    }
	                }
	            } else {
	                // Disabled integration can be stored without a token.
	                $data['api_token_enc'] = '';
	            }
	        }

	        if ($errors) {
	            $_SESSION['flash'] = [
	                'type' => 'alert',
	                'text' => implode(' ', $errors),
	            ];
	        } else {
	            $ok = upsert_cms_integration($pdo, 'strapi', $data);
	            $_SESSION['flash'] = [
	                'type' => $ok ? 'success' : 'alert',
	                'text' => $ok ? 'Zapisano konfigurację CMS (Strapi).' : 'Nie udało się zapisać konfiguracji CMS.',
	            ];
	        }
	        $redirectUrl = '/?view=editorial&tab=config';
	    }

	    if ($action === 'editorial_push_to_cms') {
	        $draftId = (int)($_POST['draft_id'] ?? 0);
	        if ($draftId > 0) {
	            $pdo = db();
	            $cfg = get_strapi_config($pdo);
	            $errors = is_array($cfg['errors'] ?? null) ? $cfg['errors'] : [];

	            if (!empty($cfg['disabled'])) {
	                $_SESSION['flash'] = [
	                    'type' => 'warning',
	                    'text' => 'Integracja CMS jest wyłączona (Redakcja → Konfiguracja CMS).',
	                ];
	                $redirectUrl = '/?' . http_build_query(
	                    ['view' => 'editorial', 'tab' => 'draft', 'draft_id' => $draftId],
	                    '',
	                    '&',
	                    PHP_QUERY_RFC3986
	                );
	            } elseif (!$cfg['ready']) {
	                $msg = $cfg['has_db_row']
	                    ? 'Integracja CMS nie jest gotowa. Uzupełnij konfigurację w Redakcja → Konfiguracja CMS.'
	                    : 'Integracja CMS nie jest gotowa. Skonfiguruj w Redakcja → Konfiguracja CMS (lub użyj ENV jako bootstrap).';
	                if ($errors) {
	                    $msg .= ' ' . implode(' ', array_map('strval', $errors));
	                }
	                $_SESSION['flash'] = [
	                    'type' => 'warning',
	                    'text' => $msg,
	                ];
	                $redirectUrl = '/?' . http_build_query(
	                    ['view' => 'editorial', 'tab' => 'draft', 'draft_id' => $draftId],
	                    '',
	                    '&',
	                    PHP_QUERY_RFC3986
	                );
	            } else {
	                $draft = fetch_editorial_draft($pdo, $draftId);
	                if (!$draft) {
	                    $_SESSION['flash'] = [
	                        'type' => 'warning',
	                        'text' => 'Nie znaleziono szkicu.',
	                    ];
	                    $redirectUrl = '/?view=editorial&tab=drafts';
	                } else {
	                    $title = trim((string)($draft['title'] ?? ''));
	                    $lead = trim((string)($draft['lead_text'] ?? ''));
	                    $body = trim((string)($draft['body'] ?? ''));
	                    if ($title === '' || ($lead === '' && $body === '')) {
	                        $_SESSION['flash'] = [
	                            'type' => 'warning',
	                            'text' => 'Nie wysłano do CMS: uzupełnij tytuł oraz excerpt lub content.',
	                        ];
	                    } else {
	                        $payload = [
	                            // Strapi Article model (v5): align field names with the CMS schema.
	                            'title' => $title,
	                            'slug' => '',
	                            'excerpt' => $lead,
	                            'content' => $body,
	                            'authorName' => 'Redakcja OneNetworks',
	                            'publishOn' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.v\\Z'),
	                        ];
	                        $slug = slugify($title);
	                        if ($slug === '') {
	                            $slug = 'article-' . $draftId;
	                        }
	                        $payload['slug'] = $slug;

	                        $base = (string)($cfg['endpoint_base'] ?? '');
	                        $apiToken = (string)($cfg['api_token'] ?? '');
	                        $existingId = trim((string)($draft['cms_external_id'] ?? ''));
	                        $method = $existingId !== '' ? 'PUT' : 'POST';
	                        $url = $existingId !== '' ? ($base . '/' . rawurlencode($existingId)) : $base;

	                        $resp = $base !== '' ? strapi_request($method, $url, $payload, $apiToken) : ['ok' => false, 'status' => 0, 'body' => null, 'raw' => null];
	                        if ($resp['ok'] && is_array($resp['body'])) {
	                            $newId = (string)(($resp['body']['data']['id'] ?? '') ?: $existingId);
	                            $newId = trim($newId);
	                            if ($newId === '') {
	                                $_SESSION['flash'] = [
	                                    'type' => 'alert',
	                                    'text' => 'CMS zwrócił odpowiedź bez ID. Sprawdź logi/konfigurację modelu Strapi.',
	                                ];
	                            } else {
	                                // Save CMS linkage and mark as ready (ready to publish in CMS).
	                                update_editorial_draft_cms($pdo, $draftId, 'sent_to_cms', $newId);
	                                $itemId = (int)($draft['editorial_item_id'] ?? 0);
	                                $curStatus = (string)($draft['editorial_status'] ?? '');
	                                if ($itemId > 0 && in_array($curStatus, ['selected', 'draft', 'in_progress'], true)) {
	                                    update_editorial_item($pdo, $itemId, 'ready', null, null);
	                                }
	                                $_SESSION['flash'] = [
	                                    'type' => 'success',
	                                    'text' => sprintf('Wysłano do CMS jako draft (id=%s).', $newId),
	                                ];
	                            }
	                        } else {
	                            $msg = '';
	                            if (is_array($resp['body']) && isset($resp['body']['error']) && is_array($resp['body']['error'])) {
	                                $msg = (string)($resp['body']['error']['message'] ?? '');
	                            }
	                            if ($msg === '' && is_string($resp['raw']) && $resp['raw'] !== '') {
	                                $msg = substr($resp['raw'], 0, 400);
	                            }
	                            $msg = trim($msg);
	                            $_SESSION['flash'] = [
	                                'type' => 'alert',
	                                'text' => sprintf(
	                                    'Błąd wysyłki do CMS (HTTP %d)%s',
	                                    (int)($resp['status'] ?? 0),
	                                    $msg !== '' ? ': ' . $msg : '.'
	                                ),
	                            ];
	                        }
	                    }

	                    $redirectUrl = '/?' . http_build_query(
	                        ['view' => 'editorial', 'tab' => 'draft', 'draft_id' => $draftId],
	                        '',
	                        '&',
	                        PHP_QUERY_RFC3986
	                    );
	                }
	            }
	        }
	    }

	    if ($action === 'strapi_healthcheck') {
	        $pdo = db();
	        $cfg = get_strapi_config($pdo);
	        $errors = is_array($cfg['errors'] ?? null) ? $cfg['errors'] : [];

	        if (!empty($cfg['disabled'])) {
	            $_SESSION['flash'] = [
	                'type' => 'warning',
	                'text' => 'Integracja CMS jest wyłączona.',
	            ];
	        } elseif (!empty($errors)) {
	            $_SESSION['flash'] = [
	                'type' => 'alert',
	                'text' => implode(' ', array_map('strval', $errors)),
	            ];
	        } else {
	            $base = (string)($cfg['endpoint_base'] ?? '');
	            $ct = trim((string)($cfg['content_type'] ?? ''));
	            $ctPath = $ct !== '' ? ('/api/' . trim($ct, '/')) : '/api/<content-type>';
	            if ($base === '') {
	                $_SESSION['flash'] = [
	                    'type' => 'warning',
	                    'text' => 'Brak konfiguracji Strapi (base URL / content-type).',
	                ];
	            } else {
	                $url = $base . '?pagination[pageSize]=1';
	                $resp = strapi_request('GET', $url, [], (string)($cfg['api_token'] ?? ''));
	                if ($resp['ok']) {
	                    $_SESSION['strapi_last_ok_at'] = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
	                    $_SESSION['flash'] = [
	                        'type' => 'success',
	                        'text' => sprintf(
	                            'Połączenie OK (HTTP %d). Content-type: %s.',
	                            (int)($resp['status'] ?? 0),
	                            $ct !== '' ? $ct : '-'
	                        ),
	                    ];
	                } else {
	                    $code = (int)($resp['status'] ?? 0);
	                    $msg = '';
	                    if (is_array($resp['body']) && isset($resp['body']['error']) && is_array($resp['body']['error'])) {
	                        $msg = (string)($resp['body']['error']['message'] ?? '');
	                    }
	                    $msg = trim($msg);

	                    if ($code === 401 || $code === 403) {
	                        $_SESSION['flash'] = [
	                            'type' => 'alert',
	                            'text' => sprintf('Błąd autoryzacji Strapi (HTTP %d)%s', $code, $msg !== '' ? ': ' . $msg : '.'),
	                        ];
	                    } elseif ($code === 404) {
	                        $_SESSION['flash'] = [
	                            'type' => 'alert',
	                            'text' => sprintf('Endpoint %s nie istnieje (HTTP 404). Sprawdź content-type.', $ctPath),
	                        ];
	                    } elseif ($code === 0) {
	                        $_SESSION['flash'] = [
	                            'type' => 'alert',
	                            'text' => 'Nie można połączyć się ze Strapi (HTTP 0). Sprawdź Base URL oraz sieć.',
	                        ];
	                    } else {
	                        $_SESSION['flash'] = [
	                            'type' => 'alert',
	                            'text' => sprintf('Błąd połączenia ze Strapi (HTTP %d)%s', $code, $msg !== '' ? ': ' . $msg : '.'),
	                        ];
	                    }
	                }
	            }
	        }
	        $redirectUrl = '/?view=editorial&tab=config';
	    }

	    if ($redirectUrl === null) {
	        if ($redirectView === 'overview' && $returnTab !== '') {
	            $redirectUrl = '/?' . http_build_query(
                ['view' => 'overview', 'tab' => $returnTab],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
        } else {
            $redirectUrl = '/?view=' . urlencode($redirectView);
        }
    }
    header('Location: ' . $redirectUrl);
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$pdo = db();
$strapiCfg = $view === 'editorial' ? get_strapi_config($pdo) : null;
$overview = fetch_overview_stats($pdo);
$latestRuns = fetch_latest_runs($pdo, 40);
$hydrationRun = fetch_latest_hydration_run($pdo);
$scraperState = scraper_status();
$runtime = $scraperState['runtime'] ?? [];
$isRunning = (bool)($runtime['running'] ?? false);
$loginSessionId = (string)($_SESSION['li_login_session_id'] ?? '');
$novncUrl = (string)($_SESSION['li_novnc_url'] ?? '');
$authInfo = $loginSessionId !== '' ? scraper_auth_login_status($loginSessionId) : scraper_auth_status();
$authState = (string)($authInfo['state'] ?? 'AUTH_UNKNOWN');
$effectiveSessionId = $loginSessionId !== '' ? $loginSessionId : (string)($authInfo['login_session_id'] ?? '');
$effectiveNovncUrl = $novncUrl !== '' ? $novncUrl : (string)($authInfo['novnc_url'] ?? '');
$hasLoginSession = $effectiveSessionId !== '';
$canScrape = !$isRunning && $authState === 'AUTH_OK' && !$hasLoginSession;

$chatgptGatewayState = chatgpt_status();
$chatgptGatewayOk = (bool)($chatgptGatewayState['ok'] ?? false);
$chatgptLoginSessionId = (string)($_SESSION['cgpt_login_session_id'] ?? '');
$chatgptNovncUrl = (string)($_SESSION['cgpt_novnc_url'] ?? '');
$chatgptAuthInfo = $chatgptLoginSessionId !== '' ? chatgpt_auth_login_status($chatgptLoginSessionId) : chatgpt_auth_status();
$chatgptAuthState = (string)($chatgptAuthInfo['state'] ?? 'AUTH_UNKNOWN');
$chatgptEffectiveSessionId = $chatgptLoginSessionId !== ''
    ? $chatgptLoginSessionId
    : (string)($chatgptAuthInfo['login_session_id'] ?? '');
$chatgptEffectiveNovncUrl = $chatgptNovncUrl !== ''
    ? $chatgptNovncUrl
    : (string)($chatgptAuthInfo['novnc_url'] ?? '');
$chatgptHasLoginSession = $chatgptEffectiveSessionId !== '';
$chatgptAssistantId = trim((string)($_GET['assistant'] ?? 'chatgpt-5.2'));
$chatgptProjectId = trim((string)($_GET['project'] ?? 'lab-onenetworks'));
$chatgptThreadId = trim((string)($_GET['thread'] ?? ''));
$chatgptNewChat = ((string)($_GET['new_chat'] ?? '') === '1');
$chatgptModels = [
    ['id' => 'chatgpt-5.2', 'name' => 'ChatGPT 5.2', 'icon' => 'C5'],
    ['id' => 'react-roadmap', 'name' => 'React Roadmap', 'icon' => 'RR'],
    ['id' => 'linux-server', 'name' => 'Linux Server Expert', 'icon' => 'LX'],
    ['id' => 'nodejs-copilot', 'name' => 'NodeJS Copilot', 'icon' => 'NJ'],
];
$chatgptProjects = [
    ['id' => 'lab-onenetworks', 'name' => 'lab.onenetworks.pl'],
    ['id' => 'ai-elinfost', 'name' => 'ai.elinfost.pl'],
    ['id' => 'elektrykzmianowy', 'name' => 'elektrykzmianowy.pl'],
    ['id' => 'wirtualnaredakcja', 'name' => 'wirtualnaredakcja.pl'],
];
$chatgptGroups = [
    ['id' => 'speech-support', 'name' => 'Trudności w mowie dziecka'],
    ['id' => 'ur-procedury', 'name' => 'UR - procedury serwisowe'],
];
if (!in_array($chatgptAssistantId, array_column($chatgptModels, 'id'), true)) {
    $chatgptAssistantId = (string)$chatgptModels[0]['id'];
}
if (!in_array($chatgptProjectId, array_column($chatgptProjects, 'id'), true)) {
    $chatgptProjectId = (string)$chatgptProjects[0]['id'];
}
$chatgptSchema = $view === 'chatgpt' ? chatgpt_schema() : ['ok' => false];
$chatgptThreadIndex = $view === 'chatgpt'
    ? chatgpt_threads_list(120, $chatgptProjectId, $chatgptAssistantId, null)
    : ['ok' => false];
$chatgptThreads = [];
if (
    !empty($chatgptThreadIndex['ok'])
    && is_array($chatgptThreadIndex['body'] ?? null)
    && is_array($chatgptThreadIndex['body']['items'] ?? null)
) {
    foreach ($chatgptThreadIndex['body']['items'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tid = trim((string)($row['thread_id'] ?? ''));
        $ttl = trim((string)($row['title'] ?? ''));
        if ($tid === '' || $ttl === '') {
            continue;
        }
        $chatgptThreads[] = ['id' => $tid, 'name' => $ttl];
    }
}
$chatgptThreadsRecent = array_slice($chatgptThreads, 0, 10);

if ($chatgptNewChat) {
    $chatgptThreadId = '';
} elseif (!in_array($chatgptThreadId, array_column($chatgptThreads, 'id'), true) && $chatgptThreads) {
    $chatgptThreadId = (string)$chatgptThreads[0]['id'];
} elseif (!$chatgptThreads) {
    $chatgptThreadId = '';
}

$chatgptMessagesPayload = ['ok' => false];
$chatgptMessages = [];
if ($view === 'chatgpt' && $chatgptTab === 'session' && $chatgptThreadId !== '') {
    $chatgptMessagesPayload = chatgpt_messages_list($chatgptThreadId, 200);
    if (
        !empty($chatgptMessagesPayload['ok'])
        && is_array($chatgptMessagesPayload['body'] ?? null)
        && is_array($chatgptMessagesPayload['body']['items'] ?? null)
    ) {
        $chatgptMessages = $chatgptMessagesPayload['body']['items'];
    }
}

$feedSource = $_GET['feed_source'] ?? '';
$feedItems = $view === 'feed' ? fetch_feed_items($pdo, $feedSource ?: null, 150) : [];

$libSource = $_GET['lib_source'] ?? '';
$libKind = $_GET['lib_kind'] ?? '';
$libType = $_GET['lib_type'] ?? '';
$libStatus = $_GET['lib_status'] ?? '';
$libTag = $_GET['lib_tag'] ?? '';
	$libraryItems = $view === 'library'
	    ? fetch_library_items($pdo, $libSource ?: null, $libKind ?: null, $libType ?: null, $libStatus ?: null, $libTag ?: null, 200)
	    : [];
	$libraryTopTagsDays = 30;
	$libraryTopTags = $view === 'library' ? fetch_library_top_tags($pdo, $libraryTopTagsDays, 12) : [];

	$editorialStatus = $view === 'editorial' ? trim((string)($_GET['e_status'] ?? 'active')) : '';
	$editorialTopic = $view === 'editorial' ? trim((string)($_GET['e_topic'] ?? '')) : '';
	$editorialPrioRaw = $view === 'editorial' ? trim((string)($_GET['e_prio'] ?? '')) : '';
	$editorialPrio = ($editorialPrioRaw !== '' && ctype_digit($editorialPrioRaw)) ? (int)$editorialPrioRaw : null;
	$editorialNoDraft = $view === 'editorial' ? ((string)($_GET['e_nodraft'] ?? '') === '1') : false;
	$editorialDraftId = $view === 'editorial' ? (int)($_GET['draft_id'] ?? 0) : 0;

	$editorialInboxItems = ($view === 'editorial' && $editorialTab === 'inbox')
	    ? fetch_editorial_inbox(
	        $pdo,
	        $editorialStatus !== '' ? $editorialStatus : null,
	        $editorialTopic !== '' ? $editorialTopic : null,
	        is_int($editorialPrio) ? $editorialPrio : null,
	        (bool)$editorialNoDraft,
	        200
	    )
	    : [];
	$editorialQueueCounts = ($view === 'editorial' && $editorialTab === 'inbox')
	    ? fetch_editorial_queue_counts(
	        $pdo,
	        $editorialTopic !== '' ? $editorialTopic : null,
	        is_int($editorialPrio) ? $editorialPrio : null,
	        (bool)$editorialNoDraft
	    )
	    : ['selected' => 0, 'draft' => 0, 'in_progress' => 0, 'ready' => 0, 'total' => 0];
	$editorialDrafts = ($view === 'editorial' && $editorialTab === 'drafts')
	    ? fetch_editorial_drafts($pdo, null, 200)
	    : [];
	$editorialDraft = ($view === 'editorial' && $editorialTab === 'draft' && $editorialDraftId > 0)
	    ? fetch_editorial_draft($pdo, $editorialDraftId)
	    : null;

	$query = trim((string)($_GET['q'] ?? ''));
$searchSource = $_GET['source'] ?? '';
$searchKind = $_GET['kind'] ?? '';
$searchType = $_GET['type'] ?? '';
$searchAuthor = trim((string)($_GET['author'] ?? ''));
$searchNotes = (string)($_GET['notes'] ?? '') === '1';
$searchOnlyNotes = (string)($_GET['only_notes'] ?? '') === '1';
$searchOnlyWithoutNotes = (string)($_GET['only_without_notes'] ?? '') === '1';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$tag = $_GET['tag'] ?? '';
$tags = fetch_tags($pdo);
$searchResults = [];
$inboxKind = $_GET['kind'] ?? '';
$inboxMode = (string)($_GET['mode'] ?? '');
$inboxResults = [];
$inboxFocusIds = [];
$inboxFocusIdx = 0;
$inboxFocusItem = null;
$insightsDays = 7;
$allowedInsightsTabs = ['notes', 'authors', 'topics', 'velocity'];
$insightsTab = (string)($_GET['tab'] ?? 'notes');
if (!in_array($insightsTab, $allowedInsightsTabs, true)) {
    $insightsTab = 'notes';
}
$insightsResults = [];
$insightsAuthors = [];
$insightsTopics7 = [];
$insightsTopics30 = [];
$insightsTopTags = [];
$insightsVelocityDays = 30;
$insightsVelocity = [];
$topicTag = trim((string)($_GET['tag'] ?? ''));
$topicResults = [];
$topicStats = null;

if ($view === 'search') {
    if ($searchOnlyNotes && $searchOnlyWithoutNotes) {
        // Resolve conflicting filters deterministically. Prefer "only with notes".
        $searchOnlyWithoutNotes = false;
        $flash = [
            'type' => 'warning',
            'text' => 'Wybrano jednocześnie: tylko z notatkami i tylko bez notatek. Zastosowano: tylko z notatkami.',
        ];
    }
    $searchResults = search_items(
        $pdo,
        $query,
        $searchSource ?: null,
        $searchKind ?: null,
        $searchType ?: null,
        $dateFrom ?: null,
        $dateTo ?: null,
        $tag ?: null,
        200,
        $searchNotes,
        $searchOnlyNotes,
        $searchOnlyWithoutNotes,
        $searchAuthor !== '' ? $searchAuthor : null
    );
}

if ($view === 'inbox') {
    // Inbox is a 1-click preset: items without user notes (triage queue).
    $inboxResults = search_items(
        $pdo,
        $query,
        null,
        $inboxKind ?: null,
        null,
        null,
        null,
        null,
        200,
        false,
        false,
        true
    );

    if ($inboxMode === 'focus') {
        $byId = [];
        foreach ($inboxResults as $r) {
            $byId[(int)$r['id']] = $r;
        }

        $idsRaw = trim((string)($_GET['focus_ids'] ?? ''));
        $idxRaw = trim((string)($_GET['focus_idx'] ?? ''));
        $idx = $idxRaw !== '' ? (int)$idxRaw : 0;

        $ids = [];
        if ($idsRaw !== '' && preg_match('/^[0-9]+(?:,[0-9]+){0,4}$/', $idsRaw)) {
            foreach (explode(',', $idsRaw) as $p) {
                $id = (int)trim($p);
                if ($id > 0 && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        // Keep only ids that exist in the current (filtered) inbox result set.
        if ($ids) {
            $ids = array_values(array_filter($ids, static fn(int $id): bool => isset($byId[$id])));
        }

        // If no focus_ids provided (or they no longer match), pick up to 5 random items.
        if (!$ids && $inboxResults) {
            $sampleSize = min(5, count($inboxResults));
            $keys = array_rand($inboxResults, $sampleSize);
            if (!is_array($keys)) {
                $keys = [$keys];
            }
            foreach ($keys as $k) {
                $ids[] = (int)$inboxResults[(int)$k]['id'];
            }
            $ids = array_values(array_unique($ids));
        }

        $inboxFocusIds = $ids;
        if ($ids) {
            $idx = max(0, $idx);
            $idx = $idx % count($ids);
            $inboxFocusIdx = $idx;
            $focusId = $ids[$idx];
            $inboxFocusItem = $byId[$focusId] ?? null;
        }
    }
}

if ($view === 'topic') {
    if ($topicTag === '') {
        $flash = [
            'type' => 'warning',
            'text' => 'Widok tematu wymaga parametru tag (np. /?view=topic&tag=ksef).',
        ];
    } else {
        $topicResults = fetch_topic_items($pdo, $topicTag, 200);
        $topicStats = fetch_topic_stats($pdo, $topicTag);
    }
}

if ($view === 'insights') {
    if ($insightsTab === 'authors') {
        $insightsAuthors = fetch_insights_top_authors($pdo, 15);
    } elseif ($insightsTab === 'topics') {
        $corpus7 = fetch_insights_corpus($pdo, 7, 800);
        $corpus30 = fetch_insights_corpus($pdo, 30, 1200);
        $insightsTopics7 = extract_top_keywords($corpus7, 12);
        $insightsTopics30 = extract_top_keywords($corpus30, 12);
        $insightsTopTags = fetch_insights_top_tags($pdo, 30, 18);
    } elseif ($insightsTab === 'velocity') {
        $insightsVelocity = fetch_insights_velocity($pdo, $insightsVelocityDays);
    } else {
        $insightsResults = fetch_insights($pdo, $insightsDays, 200);
    }
}

$postId = (int)($_GET['id'] ?? 0);
$post = $view === 'post' && $postId > 0 ? fetch_post($pdo, $postId) : null;

$itemId = (int)($_GET['id'] ?? 0);
	$item = $view === 'item' && $itemId > 0 ? fetch_item($pdo, $itemId) : null;
	$itemContexts = $view === 'item' && $item ? fetch_item_contexts($pdo, $itemId, 200) : [];
	$itemUserData = $view === 'item' && $item ? fetch_item_user_data($pdo, $itemId) : null;
	$itemTags = $view === 'item' && $item ? fetch_item_tags($pdo, $itemId) : [];
	$itemEditorial = $view === 'item' && $item ? fetch_editorial_item_by_source($pdo, $itemId) : null;

function extract_top_keywords(array $rows, int $limit = 12): array
{
    $limit = max(1, min($limit, 50));

    // Minimal stopwords set (PL + EN) + UI noise.
    $stop = [
        // PL
        'a','aby','albo','ale','bez','bo','by','byc','być','ci','co','czy','dla','do','gdy','gdzie','go','i','ich','ja','jak','je','jego','jej','jest','juz','już','każdy','kiedy','ktora','która','ktore','które','ktory','który','ma','mam','mi','mnie','moje','na','nad','nam','nas','nasz','nie','nim','niz','niż','o','od','on','ona','one','oni','oraz','po','pod','poniewaz','ponieważ','przed','przez','przy','sa','są','sie','się','ta','tak','tam','ten','też','to','tu','ty','u','w','we','wiec','więc','z','za','ze','że',
        // EN
        'an','and','are','as','at','be','been','but','by','can','could','did','do','does','for','from','had','has','have','he','her','his','how','if','in','into','is','it','its','just','me','my','no','not','of','on','or','our','she','so','that','the','their','them','then','there','they','this','to','up','us','was','we','were','what','when','where','who','will','with','you','your',
        // Noise / UI
        'więcej','wiecej','pokaż','pokaz','see','more','share','post','repost','linkedin','http','https','www','com','pl',
        // Marker words
        'przejrzane',
    ];
    $stopSet = array_fill_keys($stop, true);

    $counts = [];
    foreach ($rows as $row) {
        $content = (string)($row['content'] ?? '');
        $notes = (string)($row['notes'] ?? '');
        if ($notes !== '' && is_processed_only($notes)) {
            $notes = '';
        }
        $text = trim($content . "\n" . $notes);
        if ($text === '') {
            continue;
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = (string)preg_replace('/https?:\\/\\/\\S+/u', ' ', $text);

        if (preg_match_all('/[\\p{L}\\p{N}]+(?:-[\\p{L}\\p{N}]+)*/u', $text, $m) !== 1) {
            continue;
        }

        foreach ($m[0] as $tok) {
            if (isset($stopSet[$tok])) {
                continue;
            }
            if (preg_match('/^[0-9]+$/', $tok) === 1) {
                continue;
            }
            $len = (int)mb_strlen($tok, 'UTF-8');
            if ($len < 2 || $len > 32) {
                continue;
            }
            $counts[$tok] = ($counts[$tok] ?? 0) + 1;
        }
    }

    if (!$counts) {
        return [];
    }

    arsort($counts);
    $out = [];
    foreach (array_slice($counts, 0, $limit, true) as $tok => $cnt) {
        $out[] = ['token' => (string)$tok, 'count' => (int)$cnt];
    }
    return $out;
}

function nav_active(string $current, string $target): string
{
    return $current === $target ? 'is-active' : '';
}

function slugify(string $title): string
{
    $s = trim($title);
    if ($s === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        $s = mb_strtolower($s, 'UTF-8');
    } else {
        $s = strtolower($s);
    }

    // Polish diacritics + common accents (fallback-safe).
    $s = strtr($s, [
        'ą' => 'a',
        'ć' => 'c',
        'ę' => 'e',
        'ł' => 'l',
        'ń' => 'n',
        'ó' => 'o',
        'ś' => 's',
        'ż' => 'z',
        'ź' => 'z',
    ]);

    // Best-effort transliteration to ASCII.
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($t) && $t !== '') {
            $s = $t;
        }
    }

    $s = (string)preg_replace('/[^a-z0-9]+/i', '-', $s);
    $s = (string)preg_replace('/-+/', '-', $s);
    $s = trim($s, '-');

    // Keep it reasonably short for UID fields.
    if (strlen($s) > 120) {
        $s = substr($s, 0, 120);
        $s = trim($s, '-');
    }

    return $s;
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LinkedIn Archive Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/foundation-sites@6.8.1/dist/css/foundation.min.css">
    <style>
        :root {
            --topbar-h: 57px;
            --bg: #f4f7fb;
            --panel: #ffffff;
            --line: #dbe3ee;
            --ink: #1a2a3a;
            --muted: #5e6d7f;
            --accent: #0067c5;
            --ok: #107c41;
            --warn: #c24f00;
        }

        html,
        body {
            background: radial-gradient(circle at top right, #e4f1ff 0, #f4f7fb 55%);
            color: var(--ink);
            overflow-x: hidden;
        }

	        .topbar {
	            background: #ffffff;
	            border-bottom: 1px solid var(--line);
	            position: sticky;
	            top: 0;
	            z-index: 10;
	        }

	        .app-switch {
	            display: inline-flex;
	            align-items: center;
	            border: 1px solid var(--line);
	            border-radius: 999px;
	            overflow: hidden;
	            background: #ffffff;
	        }

	        .app-switch a {
	            display: inline-flex;
	            align-items: center;
	            padding: 0.35rem 0.7rem;
	            font-weight: 800;
	            color: var(--muted);
	            text-decoration: none;
	            white-space: nowrap;
	        }

	        .app-switch a:hover,
	        .app-switch a:focus {
	            background: #eaf3ff;
	            color: var(--accent);
	            text-decoration: none;
	        }

	        .app-switch a.is-active {
	            background: #edf5ff;
	            color: #1e5da5;
	        }

	        .app-switch a + a {
	            border-left: 1px solid var(--line);
	        }

	        .layout {
	            min-height: calc(100vh - var(--topbar-h));
	        }

            .layout > .cell {
                min-width: 0;
            }

	        .sidebar {
	            background: var(--panel);
	            border-right: 1px solid var(--line);
	            padding: 1rem 0.5rem;
	            box-sizing: border-box;
	            height: calc(100vh - var(--topbar-h));
	            overflow-y: auto;
	        }

        .menu a {
            border-radius: 10px;
            margin: 0.25rem 0;
            color: var(--ink);
        }

        .menu .is-active > a,
        .menu a:hover,
        .menu a:focus {
            background: #eaf3ff;
            color: var(--accent);
        }

	        .content-wrap {
	            padding: 1rem;
	            box-sizing: border-box;
	            min-width: 0; /* prevent horizontal overflow in flex layouts (e.g. wide tables) */
                overflow-x: hidden; /* prevents subpixel grid overflow from showing a horizontal scrollbar */
	        }

	        .panel {
	            background: var(--panel);
	            border: 1px solid var(--line);
	            border-radius: 14px;
	            padding: 1rem;
	            margin-bottom: 1rem;
	        }

	        .kpi-grid {
	            display: grid;
	            gap: 0.85rem;
	            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	            align-items: stretch;
	        }

		        .kpi-grid > .panel {
		            min-width: 0; /* allow grid items to shrink without forcing horizontal scroll */
		            margin-bottom: 0;
		        }

	        .kpi-title {
	            color: var(--muted);
	            font-size: 0.8rem;
	            text-transform: uppercase;
	            letter-spacing: 0.04em;
        }

        .kpi-value {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1.1;
        }

        .help {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.1rem;
            height: 1.1rem;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #eef2f7;
            color: var(--muted);
            font-size: 0.75rem;
            margin-left: 0.35rem;
            cursor: help;
            user-select: none;
        }

        .status-pill {
            display: inline-block;
            border-radius: 999px;
            padding: 0.25rem 0.65rem;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid var(--line);
            background: #eef2f7;
            color: var(--muted);
        }

        .status-pill.running {
            background: #fff4e5;
            color: var(--warn);
            border-color: #ffd5a8;
        }

        .status-pill.ok {
            background: #e8f8ee;
            color: var(--ok);
            border-color: #c9edd6;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            margin-bottom: 0;
        }

        .source-badge {
            display: inline-block;
            border-radius: 6px;
            background: #edf5ff;
            color: #1e5da5;
            border: 1px solid #cfe2ff;
            font-size: 0.72rem;
            padding: 0.1rem 0.45rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .meta-row {
            margin-top: 0.35rem;
            display: flex;
            gap: 0.35rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.15rem 0.55rem;
            font-size: 0.72rem;
            line-height: 1.2;
            border: 1px solid var(--line);
            background: #f0f4fa;
            color: var(--muted);
            text-decoration: none;
            white-space: nowrap;
        }

        .pill:hover,
        .pill:focus {
            border-color: #cfe2ff;
            color: var(--accent);
            background: #edf5ff;
            text-decoration: none;
        }

        .pill-note {
            background: #edf5ff;
            color: #1e5da5;
            border-color: #cfe2ff;
            font-weight: 600;
        }

	        .pill-reviewed {
	            background: #e8f8ee;
	            color: var(--ok);
	            border-color: #c9edd6;
	            font-weight: 600;
	        }

	        .pill-inbox {
	            background: #eef2f7;
	            border-color: var(--line);
	            color: var(--muted);
	            font-weight: 600;
	        }

	        .pill-tag {
	            background: #fff4e5;
	            border-color: #ffd5a8;
	            color: #7a3600;
	        }

	        .pill-ed-selected {
	            background: #eef2f7;
	            border-color: var(--line);
	            color: var(--muted);
	            font-weight: 700;
	        }

	        .pill-ed-draft {
	            background: #edf5ff;
	            border-color: #cfe2ff;
	            color: #1e5da5;
	            font-weight: 700;
	        }

	        .pill-ed-inprogress {
	            background: #fff4e5;
	            border-color: #ffd5a8;
	            color: #7a3600;
	            font-weight: 700;
	        }

	        .pill-ed-ready {
	            background: #e8f8ee;
	            color: var(--ok);
	            border-color: #c9edd6;
	            font-weight: 700;
	        }

        .pill-more {
            background: #eef2f7;
            border-color: var(--line);
            color: var(--muted);
        }

        .actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .search-grid {
            display: grid;
            gap: 0.65rem;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        }

        .off-canvas {
            background: #ffffff;
            color: var(--ink);
        }

        .mobile-nav-link {
            font-weight: 600;
        }

        /* Overview tabs (product workspace) */
        .overview-workspace {
            height: calc(100vh - var(--topbar-h) - 2rem);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .overview-tabs {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
            margin-bottom: 0.75rem;
        }

        .overview-tab {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.65rem;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #ffffff;
            color: var(--muted);
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .overview-tab:hover,
        .overview-tab:focus {
            background: #eaf3ff;
            color: var(--accent);
            border-color: #cfe2ff;
            text-decoration: none;
        }

        .overview-tab.is-active {
            background: #edf5ff;
            border-color: #cfe2ff;
            color: #1e5da5;
        }

        .overview-pane {
            flex: 1;
            overflow: hidden;
            min-height: 0;
        }

        .overview-pane-inner {
            height: 100%;
            overflow-y: auto;
            overflow-x: hidden;
            padding-right: 0.25rem;
            min-height: 0;
        }

        .overview-pane-inner--no-scroll {
            overflow: hidden;
        }

        .runs-panel {
            height: 100%;
            margin-bottom: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

	        .runs-table-wrap {
	            flex: 1;
	            min-height: 0;
	            overflow: auto; /* only the list scrolls */
	        }

	        .runs-table-wrap thead th {
	            position: sticky;
	            top: 0;
	            z-index: 1;
	            background: #f8fafc;
	            box-shadow: 0 1px 0 var(--line);
	        }

	        /* Editorial: keep long config/checklists scrolling inside the panel (no page scroll). */
	        .editorial-workspace {
	            height: calc(100vh - var(--topbar-h) - 2rem);
	            display: flex;
	            flex-direction: column;
	            gap: 1rem;
	            overflow: hidden;
	        }

	        .editorial-workspace > .panel {
	            margin-bottom: 0;
	        }

	        .cms-config {
	            flex: 1;
	            display: flex;
	            flex-direction: column;
	            overflow: hidden;
	            min-height: 0;
	        }

	        .cms-config__scroll {
	            flex: 1;
	            min-height: 0;
	            overflow-y: auto;
	            overflow-x: hidden;
	            padding-right: 0.5rem; /* keep content clear of the scrollbar */
	        }

        .content-wrap--chatgpt {
            padding: 0;
            min-height: calc(100vh - var(--topbar-h));
            background:
                radial-gradient(circle at 14% 10%, #143347 0, rgba(20, 51, 71, 0.2) 36%, rgba(20, 51, 71, 0) 56%),
                radial-gradient(circle at 83% 12%, #2a2e4c 0, rgba(42, 46, 76, 0.2) 33%, rgba(42, 46, 76, 0) 56%),
                #0f141b;
        }

        .chatgpt-shell {
            height: calc(100vh - var(--topbar-h));
            display: grid;
            grid-template-columns: 304px minmax(0, 1fr);
            color: #dbe5f1;
            overflow: hidden;
        }

        .chatgpt-rail {
            border-right: 1px solid rgba(171, 191, 212, 0.22);
            background: linear-gradient(180deg, #111a26 0, #0d141f 100%);
            min-width: 0;
            overflow: hidden;
        }

        .chatgpt-rail__inner {
            height: 100%;
            overflow-y: auto;
            padding: 0.95rem 0.75rem 0.85rem;
            display: flex;
            flex-direction: column;
            gap: 0.85rem;
        }

        .chatgpt-brand {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.25rem 0.3rem;
            color: #f5f9ff;
            font-weight: 800;
            letter-spacing: 0.02em;
        }

        .chatgpt-brand__icon {
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 0.52rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, #1f334c 0, #2f4d74 100%);
            color: #dfeeff;
            border: 1px solid rgba(186, 211, 236, 0.25);
            font-size: 0.88rem;
            font-weight: 800;
        }

        .chatgpt-quick {
            display: grid;
            gap: 0.45rem;
        }

        .chatgpt-quick a,
        .chatgpt-quick button {
            width: 100%;
            text-align: left;
            border-radius: 12px;
            padding: 0.5rem 0.62rem;
            border: 1px solid rgba(171, 191, 212, 0.26);
            background: rgba(22, 34, 48, 0.9);
            color: #dbe5f1;
            font-weight: 650;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .chatgpt-quick a:hover,
        .chatgpt-quick a:focus,
        .chatgpt-quick button:hover,
        .chatgpt-quick button:focus {
            background: rgba(36, 56, 80, 0.9);
            border-color: rgba(188, 211, 235, 0.45);
            color: #f3f9ff;
            text-decoration: none;
        }

        .chatgpt-quick a.primary {
            border-color: rgba(140, 196, 255, 0.58);
            background: linear-gradient(145deg, #1b446c 0, #25598e 100%);
            font-weight: 800;
        }

        .chatgpt-quick a.primary:hover,
        .chatgpt-quick a.primary:focus {
            background: linear-gradient(145deg, #20537f 0, #2e6da8 100%);
        }

        .chatgpt-group {
            border: 1px solid rgba(171, 191, 212, 0.2);
            border-radius: 14px;
            background: rgba(17, 26, 38, 0.72);
            overflow: hidden;
        }

        .chatgpt-group__head {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.45rem 0;
        }

        .chatgpt-group__toggle {
            width: 100%;
            background: transparent;
            border: 0;
            color: #c9d6e5;
            text-align: left;
            padding: 0.52rem 0.65rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.82rem;
            letter-spacing: 0.02em;
            font-weight: 780;
            cursor: pointer;
        }

        .chatgpt-group__head .chatgpt-group__toggle {
            flex: 1;
            border-radius: 10px;
            padding: 0.45rem 0.52rem;
        }

        .chatgpt-group__chevron {
            display: inline-block;
            transform: rotate(0deg);
            transition: transform 0.14s ease;
            color: #9bb1c7;
        }

        .chatgpt-group__toggle[aria-expanded="true"] .chatgpt-group__chevron {
            transform: rotate(90deg);
        }

        .chatgpt-group__history-btn {
            border: 1px solid rgba(171, 191, 212, 0.28);
            background: rgba(20, 31, 43, 0.88);
            color: #d6e3f2;
            border-radius: 10px;
            padding: 0.3rem 0.52rem;
            font-size: 0.72rem;
            letter-spacing: 0.02em;
            font-weight: 700;
            cursor: pointer;
            flex: 0 0 auto;
        }

        .chatgpt-group__history-btn:hover,
        .chatgpt-group__history-btn:focus {
            background: rgba(35, 57, 82, 0.9);
            border-color: rgba(186, 211, 236, 0.46);
            color: #f3f9ff;
        }

        .chatgpt-group__toggle:hover,
        .chatgpt-group__toggle:focus {
            color: #edf4ff;
            background: rgba(42, 60, 84, 0.34);
        }

        .chatgpt-group__body {
            padding: 0 0.45rem 0.45rem;
            display: grid;
            gap: 0.35rem;
        }

        .chatgpt-history-search {
            margin: 0;
            border-radius: 10px;
            border: 1px solid rgba(171, 191, 212, 0.34);
            background: rgba(15, 24, 35, 0.92);
            color: #d9e7f5;
            font-size: 0.85rem;
            padding: 0.42rem 0.56rem;
            box-shadow: none;
        }

        .chatgpt-history-search::placeholder {
            color: #8fa5bc;
        }

        .chatgpt-history-list {
            display: grid;
            gap: 0.3rem;
            max-height: 16rem;
            overflow-y: auto;
            padding-right: 0.12rem;
        }

        .chatgpt-history-actions {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin: 0.5rem 0 0.6rem;
            flex-wrap: wrap;
        }

        .chatgpt-history-actions .button.small {
            margin: 0;
            padding: 0.38rem 0.56rem;
            border-radius: 10px;
        }

        .chatgpt-history-sync-status {
            color: #9db4c9;
            font-size: 0.78rem;
            line-height: 1.3;
        }

        .chatgpt-history-sync-status[data-state="ok"] {
            color: #8fe1a4;
        }

        .chatgpt-history-sync-status[data-state="warn"] {
            color: #ffd78f;
        }

        .chatgpt-history-sync-status[data-state="error"] {
            color: #ffb7b7;
        }

        .chatgpt-history-sync-progress {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            border: 1px solid rgba(171, 191, 212, 0.24);
            background: rgba(12, 20, 30, 0.7);
            overflow: hidden;
            margin-bottom: 0.65rem;
        }

        .chatgpt-history-sync-progress__bar {
            width: 0%;
            height: 100%;
            background: linear-gradient(90deg, #3e8de9 0, #69b5ff 100%);
            transition: width 0.18s ease;
        }

        .chatgpt-history-sync-live {
            margin: 0 0 0.6rem;
            border: 1px solid rgba(171, 191, 212, 0.22);
            border-radius: 10px;
            padding: 0.55rem;
            background: rgba(10, 18, 28, 0.78);
            color: #b9d1e9;
            font-size: 0.74rem;
            line-height: 1.35;
            white-space: pre-wrap;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            max-height: 12rem;
            overflow: auto;
        }

        .chatgpt-history-empty {
            margin: 0;
            border: 1px dashed rgba(171, 191, 212, 0.26);
            border-radius: 10px;
            padding: 0.48rem 0.55rem;
            color: #9ab0c5;
            font-size: 0.8rem;
        }

        .chatgpt-link {
            display: flex;
            align-items: center;
            gap: 0.52rem;
            border-radius: 10px;
            padding: 0.45rem 0.52rem;
            color: #d3dfed;
            text-decoration: none;
            border: 1px solid transparent;
            min-width: 0;
            line-height: 1.2;
        }

        .chatgpt-link:hover,
        .chatgpt-link:focus {
            background: rgba(39, 57, 81, 0.72);
            border-color: rgba(171, 191, 212, 0.32);
            color: #f2f8ff;
            text-decoration: none;
        }

        .chatgpt-link.is-active {
            background: rgba(46, 96, 144, 0.48);
            border-color: rgba(154, 203, 251, 0.52);
            color: #f4f9ff;
        }

        .chatgpt-link__icon {
            width: 1.45rem;
            height: 1.45rem;
            border-radius: 999px;
            background: rgba(53, 79, 111, 0.85);
            border: 1px solid rgba(171, 191, 212, 0.34);
            color: #dce9f8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.63rem;
            font-weight: 800;
            flex: 0 0 auto;
        }

        .chatgpt-link__label {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .chatgpt-link__more {
            margin-left: auto;
            border: 0;
            background: transparent;
            color: #9db1c7;
            font-size: 0.95rem;
            line-height: 1;
            padding: 0;
            opacity: 0;
            transition: opacity 0.15s ease;
        }

        .chatgpt-link:hover .chatgpt-link__more,
        .chatgpt-link:focus .chatgpt-link__more,
        .chatgpt-link.is-active .chatgpt-link__more {
            opacity: 1;
        }

        .chatgpt-account {
            margin-top: auto;
            border: 1px solid rgba(171, 191, 212, 0.26);
            border-radius: 14px;
            background: rgba(19, 31, 45, 0.9);
            padding: 0.62rem;
            display: flex;
            gap: 0.55rem;
            align-items: center;
            color: #e6effa;
            width: 100%;
            text-align: left;
            cursor: pointer;
            appearance: none;
            transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
        }

        .chatgpt-account:hover,
        .chatgpt-account:focus {
            border-color: rgba(173, 207, 241, 0.55);
            background: rgba(26, 41, 59, 0.96);
            box-shadow: 0 0 0 1px rgba(126, 184, 236, 0.22);
        }

        .chatgpt-account__avatar {
            width: 2rem;
            height: 2rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, #385176 0, #26405f 100%);
            border: 1px solid rgba(171, 191, 212, 0.4);
            font-weight: 800;
            font-size: 0.83rem;
        }

        .chatgpt-account__meta {
            display: flex;
            flex-direction: column;
            line-height: 1.12;
        }

        .chatgpt-account__plan {
            color: #94abbe;
            font-size: 0.78rem;
        }

        .chatgpt-account__gear {
            margin-left: auto;
            border: 1px solid rgba(171, 191, 212, 0.32);
            border-radius: 999px;
            padding: 0.14rem 0.45rem;
            font-size: 0.66rem;
            letter-spacing: 0.05em;
            color: #b5c9de;
            background: rgba(30, 49, 70, 0.9);
        }

        .chatgpt-stage {
            min-width: 0;
            overflow: hidden;
        }

        .chatgpt-stage__inner {
            height: 100%;
            overflow-y: auto;
            padding: 1.05rem 1.1rem 1.2rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .chatgpt-stage-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .chatgpt-stage-top__left {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            flex-wrap: wrap;
        }

        .chatgpt-model-btn {
            border: 1px solid rgba(171, 191, 212, 0.4);
            background: rgba(20, 31, 44, 0.9);
            border-radius: 999px;
            color: #f2f8ff;
            padding: 0.45rem 0.8rem;
            font-weight: 700;
            cursor: default;
        }

        .chatgpt-stage-icons {
            display: inline-flex;
            gap: 0.35rem;
        }

        .chatgpt-stage-icons span {
            width: 1.85rem;
            height: 1.85rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(171, 191, 212, 0.34);
            background: rgba(22, 36, 52, 0.92);
            color: #d7e5f4;
            font-size: 0.85rem;
        }

        .chatgpt-home {
            border: 1px solid rgba(171, 191, 212, 0.24);
            border-radius: 18px;
            background: linear-gradient(150deg, rgba(33, 52, 74, 0.75) 0, rgba(21, 31, 45, 0.85) 100%);
            padding: 1.35rem 1.1rem;
            box-shadow: inset 0 0 0 1px rgba(171, 191, 212, 0.07);
        }

        .chatgpt-home h3 {
            margin: 0;
            color: #f4f9ff;
            font-size: 1.58rem;
            line-height: 1.18;
        }

        .chatgpt-home p {
            margin: 0.45rem 0 0;
            color: #afc1d4;
        }

        .chatgpt-thread-panel {
            border: 1px solid rgba(171, 191, 212, 0.24);
            border-radius: 16px;
            background: rgba(15, 23, 34, 0.85);
            padding: 0.8rem;
        }

        .chatgpt-thread-panel h6 {
            margin: 0 0 0.55rem;
            color: #f2f8ff;
            font-weight: 750;
        }

        .chatgpt-thread-log {
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
            max-height: 18rem;
            overflow-y: auto;
            padding-right: 0.2rem;
            padding-bottom: 0.1rem;
            scroll-behavior: auto;
            overscroll-behavior: contain;
        }

        .chatgpt-msg {
            border: 1px solid rgba(171, 191, 212, 0.24);
            border-radius: 12px;
            padding: 0.55rem 0.62rem;
            background: rgba(24, 39, 56, 0.65);
        }

        .chatgpt-msg--assistant {
            background: rgba(28, 58, 88, 0.55);
            border-color: rgba(148, 200, 252, 0.35);
        }

        .chatgpt-msg--user {
            background: rgba(37, 65, 93, 0.6);
            border-color: rgba(166, 202, 239, 0.34);
        }

        .chatgpt-msg.is-streaming {
            border-style: dashed;
            border-color: rgba(130, 192, 249, 0.5);
        }

        .chatgpt-msg__meta {
            color: #9db3c9;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 0.25rem;
            display: inline-block;
        }

        .chatgpt-msg__text {
            margin: 0;
            color: #e2edf9;
            white-space: pre-wrap;
            line-height: 1.35;
        }

        .chatgpt-msg__attachments {
            margin-top: 0.55rem;
            display: grid;
            gap: 0.45rem;
        }

        .chatgpt-msg-attachment {
            border: 1px solid rgba(171, 191, 212, 0.22);
            border-radius: 10px;
            background: rgba(16, 27, 40, 0.72);
            padding: 0.45rem;
            display: grid;
            gap: 0.35rem;
        }

        .chatgpt-msg-attachment__name {
            color: #b8cee3;
            font-size: 0.74rem;
            line-height: 1.25;
        }

        .chatgpt-msg-attachment__preview {
            width: 100%;
            border-radius: 8px;
            border: 1px solid rgba(171, 191, 212, 0.2);
            background: rgba(8, 14, 22, 0.76);
            max-height: 220px;
            object-fit: contain;
        }

        .chatgpt-msg-attachment__link {
            font-size: 0.78rem;
            color: #b6d9ff;
            text-decoration: none;
        }

        .chatgpt-msg-attachment__link:hover,
        .chatgpt-msg-attachment__link:focus {
            color: #e6f3ff;
            text-decoration: underline;
        }

        .chatgpt-msg.is-streaming .chatgpt-msg__text::after {
            content: " ▍";
            color: #9bcfff;
            animation: cgpt-stream-caret 0.9s steps(1, end) infinite;
        }

        @keyframes cgpt-stream-caret {
            0%, 45% {
                opacity: 1;
            }
            46%, 100% {
                opacity: 0;
            }
        }

        .chatgpt-msg--compare {
            background: transparent;
            border-color: rgba(171, 191, 212, 0.2);
            padding: 0.3rem 0.35rem;
        }

        .chatgpt-compare {
            display: grid;
            gap: 0.6rem;
        }

        .chatgpt-compare__title {
            margin: 0;
            color: #dbe8f6;
            font-size: 0.92rem;
            line-height: 1.35;
        }

        .chatgpt-compare__subtitle {
            margin: 0;
            color: #a8bfd5;
            font-size: 0.82rem;
            line-height: 1.35;
        }

        .chatgpt-compare__grid {
            display: grid;
            gap: 0.65rem;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        }

        .chatgpt-compare-card {
            border: 1px solid rgba(171, 191, 212, 0.28);
            border-radius: 12px;
            background: rgba(20, 34, 49, 0.88);
            padding: 0.6rem;
            display: grid;
            gap: 0.45rem;
        }

        .chatgpt-compare-card.is-selected {
            border-color: rgba(110, 192, 250, 0.62);
            box-shadow: 0 0 0 1px rgba(110, 192, 250, 0.28) inset;
        }

        .chatgpt-compare-card__label {
            font-size: 0.76rem;
            color: #a8c1d9;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .chatgpt-compare-card__text {
            margin: 0;
            color: #e4eef9;
            white-space: pre-wrap;
            line-height: 1.38;
            max-height: 12rem;
            overflow-y: auto;
        }

        .chatgpt-compare-card__btn {
            border: 1px solid rgba(171, 191, 212, 0.34);
            background: rgba(17, 28, 41, 0.9);
            color: #d8e8f7;
            border-radius: 999px;
            padding: 0.25rem 0.75rem;
            font-size: 0.78rem;
            justify-self: start;
            cursor: pointer;
        }

        .chatgpt-compare-card__btn.is-selected {
            border-color: rgba(94, 182, 245, 0.72);
            color: #eff8ff;
            background: rgba(35, 73, 103, 0.9);
        }

        .chatgpt-compare-card__btn:hover,
        .chatgpt-compare-card__btn:focus {
            border-color: rgba(124, 194, 245, 0.68);
            background: rgba(32, 58, 83, 0.95);
        }

        .chatgpt-composer-wrap {
            position: relative;
            max-width: 920px;
        }

        .chatgpt-composer {
            display: flex;
            align-items: flex-end;
            gap: 0.52rem;
            padding: 0.52rem 0.62rem;
            border-radius: 999px;
            border: 1px solid rgba(171, 191, 212, 0.4);
            background: rgba(19, 31, 46, 0.92);
            box-shadow: 0 12px 30px rgba(6, 9, 12, 0.26);
        }

        .chatgpt-composer.is-focused {
            border-color: rgba(150, 205, 255, 0.7);
            box-shadow: 0 0 0 2px rgba(106, 171, 233, 0.2), 0 12px 30px rgba(6, 9, 12, 0.26);
        }

        .chatgpt-plus-btn {
            width: 2rem;
            height: 2rem;
            border-radius: 999px;
            border: 1px solid rgba(171, 191, 212, 0.4);
            background: rgba(31, 48, 67, 0.95);
            color: #f3f9ff;
            font-size: 1.1rem;
            font-weight: 700;
            line-height: 1;
            flex: 0 0 auto;
            cursor: pointer;
        }

        .chatgpt-plus-btn:hover,
        .chatgpt-plus-btn:focus {
            background: rgba(48, 76, 109, 0.95);
            border-color: rgba(186, 212, 239, 0.58);
        }

        .chatgpt-composer textarea {
            margin: 0;
            min-height: 2rem;
            max-height: 9rem;
            border: 0;
            background: transparent;
            color: #edf5ff;
            resize: none;
            box-shadow: none;
            flex: 1;
            padding: 0.2rem 0;
        }

        .chatgpt-composer textarea::placeholder {
            color: #94a9bf;
        }

        .chatgpt-composer-actions {
            display: flex;
            align-items: center;
            gap: 0.32rem;
            flex: 0 0 auto;
        }

        .chatgpt-icon-btn {
            width: 2rem;
            height: 2rem;
            border-radius: 999px;
            border: 1px solid rgba(171, 191, 212, 0.34);
            background: rgba(24, 38, 54, 0.95);
            color: #d8e6f5;
            font-size: 0.87rem;
            line-height: 1;
            cursor: pointer;
        }

        .chatgpt-icon-btn.voice {
            background: linear-gradient(145deg, #3b4fd9 0, #734af5 100%);
            border-color: rgba(198, 195, 255, 0.72);
            color: #f4f4ff;
        }

        .chatgpt-icon-btn:hover,
        .chatgpt-icon-btn:focus {
            border-color: rgba(188, 211, 235, 0.54);
            background: rgba(43, 62, 84, 0.95);
        }

        .chatgpt-icon-btn.voice:hover,
        .chatgpt-icon-btn.voice:focus {
            background: linear-gradient(145deg, #4b5fe6 0, #8460ff 100%);
        }

        .chatgpt-icon-btn:disabled {
            opacity: 0.55;
            cursor: wait;
        }

        .chatgpt-attachments {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
            margin: 0 0 0.45rem;
        }

        .chatgpt-chip {
            border-radius: 999px;
            border: 1px solid rgba(171, 191, 212, 0.44);
            padding: 0.2rem 0.56rem;
            font-size: 0.76rem;
            color: #d7e4f1;
            background: rgba(25, 42, 61, 0.92);
        }

        .chatgpt-mode-pill {
            margin-top: 0.52rem;
            display: inline-flex;
            border: 1px solid rgba(171, 191, 212, 0.42);
            border-radius: 999px;
            padding: 0.2rem 0.62rem;
            color: #d6e5f6;
            font-size: 0.79rem;
            background: rgba(22, 36, 52, 0.9);
        }

        .chatgpt-comparison-picker {
            margin-top: 0.38rem;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.74rem;
            color: #a8c0d8;
        }

        .chatgpt-comparison-picker__btn {
            border: 1px solid rgba(171, 191, 212, 0.3);
            background: rgba(18, 28, 39, 0.86);
            color: #d8e6f5;
            border-radius: 999px;
            min-width: 1.8rem;
            padding: 0.12rem 0.45rem;
            cursor: pointer;
        }

        .chatgpt-comparison-picker__btn.is-active {
            background: rgba(44, 85, 118, 0.96);
            border-color: rgba(113, 191, 248, 0.74);
            color: #f2f8ff;
        }

        .chatgpt-tools {
            position: absolute;
            left: 0.52rem;
            bottom: calc(100% + 0.45rem);
            display: grid;
            grid-template-columns: minmax(230px, 1fr);
            gap: 0.45rem;
            z-index: 30;
        }

        .chatgpt-tools[hidden] {
            display: none;
        }

        .chatgpt-tools__menu,
        .chatgpt-tools__submenu {
            border-radius: 14px;
            border: 1px solid rgba(171, 191, 212, 0.4);
            background: rgba(15, 24, 35, 0.98);
            box-shadow: 0 18px 36px rgba(1, 4, 7, 0.5);
            padding: 0.42rem;
            min-width: 250px;
        }

        .chatgpt-tools__item {
            width: 100%;
            border: 0;
            background: transparent;
            color: #dbe8f6;
            text-align: left;
            border-radius: 9px;
            padding: 0.45rem 0.6rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            font-size: 0.88rem;
        }

        .chatgpt-tools__item:hover,
        .chatgpt-tools__item:focus {
            background: rgba(49, 72, 99, 0.65);
            color: #f4f9ff;
        }

        .chatgpt-tools__submenu {
            position: absolute;
            left: calc(100% + 0.35rem);
            bottom: 0;
        }

        .chatgpt-tools__submenu[hidden] {
            display: none;
        }

        .chatgpt-session-panel {
            background: linear-gradient(160deg, rgba(18, 30, 43, 0.85) 0, rgba(19, 28, 41, 0.95) 100%);
            border-color: rgba(171, 191, 212, 0.3);
            color: #d4e3f1;
        }

        .chatgpt-session-panel .callout {
            border-radius: 12px;
            background: rgba(18, 30, 43, 0.78);
            border-color: rgba(171, 191, 212, 0.3);
            color: #c5d6e8;
        }

        .chatgpt-status-grid {
            display: grid;
            gap: 0.65rem;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            margin-bottom: 0.8rem;
        }

        .chatgpt-status-card {
            border: 1px solid rgba(171, 191, 212, 0.3);
            background: rgba(18, 30, 43, 0.88);
            border-radius: 12px;
            padding: 0.7rem;
        }

        .chatgpt-status-card .kpi-title {
            color: #9eb2c6;
        }

        .chatgpt-status-card .kpi-value {
            color: #f2f8ff;
            font-size: 1rem;
        }

        .chatgpt-session-panel table,
        .chatgpt-session-panel table th,
        .chatgpt-session-panel table td {
            color: #d4e3f2;
            border-color: rgba(171, 191, 212, 0.24);
            background: transparent;
        }

        .chatgpt-session-panel table thead th {
            background: rgba(22, 34, 48, 0.88);
        }

        .chatgpt-event-table code {
            color: #dbe9f7;
            background: rgba(53, 79, 111, 0.35);
            border: 1px solid rgba(171, 191, 212, 0.28);
            border-radius: 6px;
            padding: 0.05rem 0.35rem;
        }

        .chatgpt-modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 220;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(5, 10, 15, 0.62);
            backdrop-filter: blur(3px);
        }

        .chatgpt-modal-backdrop[hidden] {
            display: none;
        }

        .chatgpt-modal {
            width: min(920px, 96vw);
            max-height: 92vh;
            overflow-y: auto;
            border-radius: 16px;
            border: 1px solid rgba(171, 191, 212, 0.34);
            background: linear-gradient(165deg, rgba(17, 28, 40, 0.97) 0, rgba(13, 23, 35, 0.98) 100%);
            box-shadow: 0 22px 48px rgba(3, 7, 12, 0.56);
            color: #d8e6f5;
        }

        .chatgpt-modal--narrow {
            width: min(680px, 96vw);
        }

        .chatgpt-modal__header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.8rem;
            padding: 0.95rem 1rem;
            border-bottom: 1px solid rgba(171, 191, 212, 0.22);
        }

        .chatgpt-modal__title {
            margin: 0;
            color: #f1f7ff;
            font-size: 1.05rem;
        }

        .chatgpt-modal__close {
            width: 2rem;
            height: 2rem;
            border-radius: 999px;
            border: 1px solid rgba(171, 191, 212, 0.34);
            background: rgba(28, 44, 61, 0.94);
            color: #d8e5f4;
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
        }

        .chatgpt-modal__body {
            padding: 0.95rem 1rem 1rem;
            display: grid;
            gap: 0.85rem;
        }

        .chatgpt-modal__section {
            border: 1px solid rgba(171, 191, 212, 0.24);
            border-radius: 12px;
            background: rgba(17, 29, 42, 0.82);
            padding: 0.78rem;
            display: grid;
            gap: 0.58rem;
        }

        .chatgpt-modal__section h6 {
            margin: 0;
            color: #eff7ff;
            font-weight: 750;
            font-size: 0.9rem;
        }

        .chatgpt-modal__kv {
            display: grid;
            gap: 0.3rem;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            font-size: 0.84rem;
            color: #b6cadf;
        }

        .chatgpt-modal__kv code {
            color: #dbe9f7;
            background: rgba(53, 79, 111, 0.35);
            border: 1px solid rgba(171, 191, 212, 0.28);
            border-radius: 6px;
            padding: 0.05rem 0.3rem;
        }

        .chatgpt-modal__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
            align-items: center;
        }

        .chatgpt-modal__actions form {
            margin: 0;
        }

        .chatgpt-modal__actions .button,
        .chatgpt-modal__actions a.button {
            margin: 0;
        }

        .chatgpt-modal__hint {
            margin: 0;
            color: #abc0d5;
            font-size: 0.82rem;
            line-height: 1.35;
        }

        .chatgpt-modal__diag details {
            border: 1px solid rgba(171, 191, 212, 0.24);
            border-radius: 10px;
            background: rgba(13, 23, 34, 0.75);
            padding: 0.5rem 0.65rem;
        }

        .chatgpt-modal__diag summary {
            cursor: pointer;
            color: #d7e6f5;
            font-size: 0.85rem;
        }

        .chatgpt-modal__iframe {
            width: 100%;
            height: min(64vh, 620px);
            border: 1px solid rgba(171, 191, 212, 0.3);
            border-radius: 10px;
            background: #0b1020;
            margin-top: 0.6rem;
        }

        body.chatgpt-modal-open {
            overflow: hidden;
        }

        @media screen and (max-width: 78em) {
            .chatgpt-shell {
                grid-template-columns: 276px minmax(0, 1fr);
            }
        }

        @media screen and (max-width: 63.9375em) {
            .content-wrap--chatgpt {
                min-height: auto;
            }

            .chatgpt-shell {
                height: auto;
                min-height: calc(100vh - var(--topbar-h));
                grid-template-columns: 1fr;
            }

            .chatgpt-rail {
                border-right: 0;
                border-bottom: 1px solid rgba(171, 191, 212, 0.24);
            }

            .chatgpt-stage__inner {
                min-height: 40vh;
            }

            .chatgpt-tools {
                left: 0;
                right: 0;
                grid-template-columns: 1fr;
            }

            .chatgpt-tools__menu {
                min-width: 0;
            }

            .chatgpt-tools__submenu {
                position: static;
            }

            .chatgpt-modal {
                width: 100%;
                max-height: 96vh;
            }
        }
	    </style>
	</head>
<body>
<div class="off-canvas-wrapper">
    <div class="off-canvas position-left" id="mobile-nav" data-off-canvas>
        <div class="grid-x grid-padding-x">
            <div class="cell small-12">
	                    <h5 style="margin-top: 1rem;">Nawigacja</h5>
                <ul class="vertical menu">
                    <?php if ($appContext === 'knowledge'): ?>
                        <li class="<?= nav_active($view, 'overview') ?>"><a class="mobile-nav-link" href="/?view=overview">Overview</a></li>
                        <li class="<?= nav_active($view, 'library') ?>"><a class="mobile-nav-link" href="/?view=library">Archiwum LinkedIn</a></li>
                        <li class="<?= ($view === 'inbox' && $inboxMode !== 'focus') ? 'is-active' : '' ?>"><a class="mobile-nav-link" href="/?view=inbox">Inbox</a></li>
                        <li class="<?= ($view === 'inbox' && $inboxMode === 'focus') ? 'is-active' : '' ?>"><a class="mobile-nav-link" href="/?view=inbox&mode=focus">Inbox Focus</a></li>
                        <li class="<?= nav_active($view, 'insights') ?>"><a class="mobile-nav-link" href="/?view=insights">Insights</a></li>
                        <li class="<?= nav_active($view, 'search') ?>"><a class="mobile-nav-link" href="/?view=search">Search</a></li>
                        <li class="<?= nav_active($view, 'runs') ?>"><a class="mobile-nav-link" href="/?view=runs">Runs</a></li>
                        <li class="<?= nav_active($view, 'feed') ?>"><a class="mobile-nav-link" href="/?view=feed">Logi scrapera</a></li>
                        <li class="<?= nav_active($view, 'login') ?>"><a class="mobile-nav-link" href="/?view=login">Login</a></li>
                    <?php elseif ($appContext === 'chatgpt'): ?>
                        <li class="<?= ($view === 'chatgpt' && $chatgptTab === 'session') ? 'is-active' : '' ?>"><a class="mobile-nav-link" href="/?view=chatgpt&tab=session">Sesja</a></li>
                        <li class="<?= ($view === 'chatgpt' && $chatgptTab === 'status') ? 'is-active' : '' ?>"><a class="mobile-nav-link" href="/?view=chatgpt&tab=status">Status</a></li>
                    <?php else: ?>
                        <?php $edActive = $editorialTab === 'draft' ? 'drafts' : $editorialTab; ?>
                        <li class="<?= ($view === 'editorial' && $edActive === 'inbox') ? 'is-active' : '' ?>"><a class="mobile-nav-link" href="/?view=editorial&tab=inbox">Inbox redakcyjny</a></li>
                        <li class="<?= ($view === 'editorial' && $edActive === 'drafts') ? 'is-active' : '' ?>"><a class="mobile-nav-link" href="/?view=editorial&tab=drafts">Drafty</a></li>
                        <li class="<?= ($view === 'editorial' && $edActive === 'config') ? 'is-active' : '' ?>"><a class="mobile-nav-link" href="/?view=editorial&tab=config">Konfiguracja CMS</a></li>
                    <?php endif; ?>
                </ul>
	            </div>
	        </div>
	    </div>

    <div class="off-canvas-content" data-off-canvas-content>
	        <div class="top-bar topbar">
	            <div class="top-bar-left">
	                <ul class="menu">
	                    <li class="hide-for-medium">
	                        <button class="button hollow" type="button" data-toggle="mobile-nav" aria-label="Otwórz menu">Menu</button>
	                    </li>
	                    <li>
	                        <div class="app-switch" aria-label="Kontekst aplikacji">
                            <a
                                class="<?= $appContext === 'knowledge' ? 'is-active' : '' ?>"
                                href="/?view=overview"
                                title="Knowledge OS (LinkedIn Archive)"
                            >LinkedIn Archive</a>
                            <a
                                class="<?= $appContext === 'chatgpt' ? 'is-active' : '' ?>"
                                href="/?view=chatgpt&tab=session"
                                title="ChatGPT Session"
                            >ChatGPT</a>
                            <a
                                class="<?= $appContext === 'editorial' ? 'is-active' : '' ?>"
                                href="/?view=editorial&tab=inbox"
                                title="Panel Redakcyjny"
                            >Redakcja</a>
	                        </div>
	                    </li>
	                </ul>
	            </div>
	            <div class="top-bar-right">
                <?php if ($appContext === 'knowledge'): ?>
                    <span class="status-pill <?= $isRunning ? 'running' : 'ok' ?>">
                        Scraper: <?= $isRunning ? 'RUNNING' : 'IDLE' ?>
                    </span>
                    <span class="status-pill <?= $authState === 'AUTH_OK' ? 'ok' : 'running' ?>" style="margin-left:0.4rem;">
                        LinkedIn: <?= $authState === 'AUTH_OK' ? 'OK' : ($authState === 'LOGIN_RUNNING' ? 'LOGIN' : 'REQUIRED') ?>
                    </span>
                <?php elseif ($appContext === 'chatgpt'): ?>
                    <span class="status-pill <?= $chatgptGatewayOk ? 'ok' : 'running' ?>" id="chatgpt-top-gateway-pill">
                        Gateway: <?= $chatgptGatewayOk ? 'OK' : 'DOWN' ?>
                    </span>
                    <span class="status-pill <?= $chatgptAuthState === 'AUTH_OK' ? 'ok' : 'running' ?>" id="chatgpt-top-auth-pill" style="margin-left:0.4rem;">
                        ChatGPT: <?= $chatgptAuthState === 'AUTH_OK' ? 'OK' : ($chatgptAuthState === 'LOGIN_RUNNING' ? 'LOGIN' : 'REQUIRED') ?>
                    </span>
                <?php else: ?>
                    <span class="status-pill ok">Tryb: Redakcja</span>
                <?php endif; ?>
	            </div>
	        </div>

        <div class="grid-x layout">
            <?php if ($appContext !== 'chatgpt'): ?>
            <aside class="cell medium-2 large-2 show-for-medium sidebar">
                <ul class="vertical menu">
                    <?php if ($appContext === 'knowledge'): ?>
                        <li class="<?= nav_active($view, 'overview') ?>"><a href="/?view=overview">Overview</a></li>
                        <li class="<?= nav_active($view, 'library') ?>"><a href="/?view=library">Archiwum LinkedIn</a></li>
		                        <li class="<?= ($view === 'inbox' && $inboxMode !== 'focus') ? 'is-active' : '' ?>"><a href="/?view=inbox">Inbox</a></li>
		                        <li class="<?= ($view === 'inbox' && $inboxMode === 'focus') ? 'is-active' : '' ?>"><a href="/?view=inbox&mode=focus">Inbox Focus</a></li>
		                        <li class="<?= nav_active($view, 'insights') ?>"><a href="/?view=insights">Insights</a></li>
                        <li class="<?= nav_active($view, 'search') ?>"><a href="/?view=search">Search</a></li>
                        <li class="<?= nav_active($view, 'runs') ?>"><a href="/?view=runs">Runs</a></li>
                        <li class="<?= nav_active($view, 'feed') ?>"><a href="/?view=feed">Logi scrapera</a></li>
                        <li class="<?= nav_active($view, 'login') ?>"><a href="/?view=login">Login</a></li>
                    <?php elseif ($appContext === 'chatgpt'): ?>
                        <li class="<?= ($view === 'chatgpt' && $chatgptTab === 'session') ? 'is-active' : '' ?>"><a href="/?view=chatgpt&tab=session">Sesja</a></li>
                        <li class="<?= ($view === 'chatgpt' && $chatgptTab === 'status') ? 'is-active' : '' ?>"><a href="/?view=chatgpt&tab=status">Status</a></li>
                    <?php else: ?>
                        <?php $edActive = $editorialTab === 'draft' ? 'drafts' : $editorialTab; ?>
                        <li class="<?= ($view === 'editorial' && $edActive === 'inbox') ? 'is-active' : '' ?>"><a href="/?view=editorial&tab=inbox">Inbox redakcyjny</a></li>
		                        <li class="<?= ($view === 'editorial' && $edActive === 'drafts') ? 'is-active' : '' ?>"><a href="/?view=editorial&tab=drafts">Drafty</a></li>
		                        <li class="<?= ($view === 'editorial' && $edActive === 'config') ? 'is-active' : '' ?>"><a href="/?view=editorial&tab=config">Konfiguracja CMS</a></li>
		                    <?php endif; ?>
		                </ul>
	            </aside>
            <?php endif; ?>

            <main class="<?= $appContext === 'chatgpt' ? 'cell small-12 content-wrap content-wrap--chatgpt' : 'cell small-12 medium-10 large-10 content-wrap' ?>">
                <?php if (is_array($flash)): ?>
                    <div class="callout <?= h($flash['type']) ?>" role="alert"><?= h($flash['text']) ?></div>
                <?php endif; ?>

                <?php if ($view === 'overview'): ?>
                    <?php
                        $overviewTabs = [
                            'archive' => 'Archiwum',
                            'knowledge' => 'Wiedza',
                            'ingest' => 'Ingest',
                            'quality' => 'Jakość treści',
                            'runs' => 'Runy',
                        ];
                    ?>
                    <div class="overview-workspace" aria-label="Overview">
                        <nav class="overview-tabs" aria-label="Zakładki Overview">
                            <?php foreach ($overviewTabs as $k => $label): ?>
                                <a
                                    class="overview-tab <?= $overviewTab === $k ? 'is-active' : '' ?>"
                                    href="/?<?= h(http_build_query(['view' => 'overview', 'tab' => $k], '', '&', PHP_QUERY_RFC3986)) ?>"
                                ><?= h($label) ?></a>
                            <?php endforeach; ?>
                        </nav>

                        <div class="overview-pane">
                            <div class="overview-pane-inner <?= $overviewTab === 'runs' ? 'overview-pane-inner--no-scroll' : '' ?>">

	                                <?php if ($overviewTab === 'archive'): ?>
	                                    <div class="kpi-grid" aria-label="KPI archiwum">
	                                        <section class="panel" aria-label="Liczba itemów">
	                                            <div class="kpi-title">Archiwum: items</div>
	                                            <div class="kpi-value"><?= (int)($overview['total_items'] ?? 0) ?></div>
	                                        </section>
	                                        <section class="panel" aria-label="Inbox (bez notatek)">
	                                            <div class="kpi-title">Inbox: bez notatek</div>
	                                            <div class="kpi-value"><?= (int)($overview['inbox_items'] ?? 0) ?></div>
	                                        </section>
	                                        <section class="panel" aria-label="Zapisane elementy">
	                                            <div class="kpi-title">
	                                                Saved: konteksty
	                                                <span
	                                                    class="help"
	                                                    title="Liczba kontekstów 'save' zapisanych w archiwum (item_contexts). To nie jest liczba unikalnych postów: jeden item może mieć kilka kontekstów (np. saved_posts + saved_articles)."
	                                                >?</span>
	                                            </div>
	                                            <div class="kpi-value"><?= (int)($overview['saved_contexts'] ?? 0) ?></div>
	                                        </section>
	                                    </div>
	                                <?php endif; ?>

	                                <?php if ($overviewTab === 'knowledge'): ?>
	                                    <?php $topThemes = fetch_library_top_tags($pdo, 30, 10); ?>
	                                    <div class="grid-x grid-padding-x">
	                                        <div class="cell small-12 large-6">
	                                            <section class="panel" aria-label="Metryki pracy z wiedzą">
	                                                <div class="kpi-title">
	                                                    Metryki pracy z wiedzą
	                                                    <span
	                                                        class="help"
	                                                        title="To są Twoje realne akcje przetwarzania wiedzy (notatki), a nie liczba zapisanych linków. Przejrzane = tylko znacznik '✓ przejrzane ...'. Opracowane = notatka własnymi słowami."
	                                                    >?</span>
	                                                </div>
	                                                <div class="grid-x grid-padding-x" style="margin-top:0.6rem;">
	                                                    <div class="cell small-6 medium-3">
	                                                        <div class="kpi-value"><?= (int)($overview['processed_today'] ?? 0) ?></div>
	                                                        <div class="kpi-title">Opracowane dziś</div>
	                                                    </div>
	                                                    <div class="cell small-6 medium-3">
	                                                        <div class="kpi-value"><?= (int)($overview['processed_week'] ?? 0) ?></div>
	                                                        <div class="kpi-title">Opracowane (7 dni)</div>
	                                                    </div>
	                                                    <div class="cell small-6 medium-3">
	                                                        <div class="kpi-value"><?= (int)($overview['reviewed_today'] ?? 0) ?></div>
	                                                        <div class="kpi-title">Przejrzane dziś</div>
	                                                    </div>
	                                                    <div class="cell small-6 medium-3">
	                                                        <div class="kpi-value"><?= (int)($overview['reviewed_week'] ?? 0) ?></div>
	                                                        <div class="kpi-title">Przejrzane (7 dni)</div>
	                                                    </div>
	                                                </div>
	                                            </section>
	                                        </div>
	                                        <div class="cell small-12 large-6">
	                                            <section class="panel" aria-label="Top tematy (30 dni)">
	                                                <div class="kpi-title">
	                                                    Top tematy (30 dni)
	                                                    <span
	                                                        class="help"
	                                                        title="Tagi na elementach opracowanych merytorycznie w ostatnich 30 dniach (wg daty aktualizacji notatki). Kliknij, aby przejść do Biblioteki: opracowane + ten tag."
	                                                    >?</span>
	                                                </div>

	                                                <?php if (!empty($topThemes)): ?>
	                                                    <div class="meta-row" style="margin-top:0.6rem;">
	                                                        <?php foreach ($topThemes as $row): ?>
	                                                            <?php
	                                                                $tag = (string)($row['tag'] ?? '');
	                                                                $cnt = (int)($row['items'] ?? 0);
	                                                            ?>
	                                                            <a
	                                                                class="pill pill-tag"
	                                                                href="/?<?= h(http_build_query(['view' => 'library', 'lib_status' => 'processed', 'lib_tag' => $tag], '', '&', PHP_QUERY_RFC3986)) ?>"
	                                                            ><?= h($tag) ?> <span style="opacity:0.8;">(<?= $cnt ?>)</span></a>
	                                                        <?php endforeach; ?>
	                                                    </div>
	                                                <?php else: ?>
	                                                    <p style="color: var(--muted); margin-top:0.6rem; margin-bottom:0;">
	                                                        Brak danych. Dodaj pierwszą notatkę merytoryczną i przypisz tag.
	                                                    </p>
	                                                <?php endif; ?>
	                                            </section>
	                                        </div>
	                                    </div>
	                                <?php endif; ?>

                                <?php if ($overviewTab === 'ingest'): ?>
                                    <div class="grid-x grid-padding-x">
                                        <div class="cell small-12 medium-4">
                                            <section class="panel" aria-label="Nowe wpisy z ostatniego update">
                                                <div class="kpi-title">Ostatni update: nowe wpisy</div>
                                                <div class="kpi-value"><?= (int)$overview['last_update_gain'] ?></div>
                                            </section>
                                        </div>
                                        <div class="cell small-12 medium-4">
                                            <section class="panel" aria-label="Ostatni run">
                                                <div class="kpi-title">Ostatni run</div>
                                                <div class="kpi-value" style="font-size:1rem; font-weight:600;">
                                                    <?= h(($overview['last_run']['mode'] ?? '-') . ' / ' . ($overview['last_run']['status'] ?? '-')) ?>
                                                </div>
                                                <div style="color: var(--muted)"><?= h(format_dt($overview['last_run']['started_at'] ?? null)) ?></div>
                                            </section>
                                        </div>
                                        <div class="cell small-12 medium-4">
                                            <section class="panel" aria-label="Status scrapera">
                                                <div class="kpi-title">Scraper</div>
                                                <div class="kpi-value" style="font-size:1rem; font-weight:700;">
                                                    <?= $isRunning ? 'RUNNING' : 'IDLE' ?>
                                                </div>
                                                <div style="color: var(--muted)"><?= h((string)($runtime['progress']['message'] ?? 'ready')) ?></div>
                                            </section>
                                        </div>
                                    </div>

                                    <section class="panel" aria-label="Kontrola scrapera">
                                        <h5 style="margin-top:0;">Operacje ETL</h5>
                                        <p style="margin-bottom: 1rem; color: var(--muted);">
                                            Tryb: <strong><?= h((string)($runtime['mode'] ?? '-')) ?></strong>
                                            | Run ID: <strong><?= h((string)($runtime['run_id'] ?? '-')) ?></strong>
                                        </p>

                                        <div class="grid-x grid-padding-x">
                                            <div class="cell small-12 medium-6">
                                                <p style="margin-bottom: 0.5rem; color: var(--muted);">
                                                    LinkedIn session: <strong><?= h($authState) ?></strong>
                                                </p>
                                                <?php if ($hasLoginSession): ?>
                                                    <div class="callout warning" style="margin-bottom: 0.7rem;">
                                                        Okno logowania jest nadal otwarte. Kliknij <strong>Zamknij okno</strong>, żeby uruchomić scraping.
                                                    </div>
                                                <?php elseif ($authState !== 'AUTH_OK'): ?>
                                                    <div class="callout warning" style="margin-bottom: 0.7rem;">
                                                        Żeby scrapować, musisz się zalogować do LinkedIn w zakładce <strong>Login</strong>.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="cell small-12 medium-6">
                                                <div class="actions" style="justify-content: flex-end;">
                                                    <?php if ($hasLoginSession): ?>
                                                        <a class="button secondary" href="/?view=login">Otwórz okno logowania</a>
                                                        <?php if ($effectiveNovncUrl !== ''): ?>
                                                            <a class="button secondary" href="<?= h($effectiveNovncUrl) ?>" target="_blank" rel="noopener">Otwórz noVNC</a>
                                                        <?php endif; ?>
                                                        <form method="post" style="display:inline;">
                                                            <input type="hidden" name="action" value="stop_login">
                                                            <input type="hidden" name="session_id" value="<?= h($effectiveSessionId) ?>">
                                                            <input type="hidden" name="return_tab" value="ingest">
                                                            <button type="submit" class="button hollow">Zamknij okno</button>
                                                        </form>
                                                    <?php elseif (!$isRunning && $authState !== 'AUTH_OK'): ?>
                                                        <form method="post" style="display:inline;">
                                                            <input type="hidden" name="action" value="start_login">
                                                            <button type="submit" class="button primary">Zaloguj LinkedIn</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <form method="post" class="actions">
                                            <input type="hidden" name="action" value="run_scrape">
                                            <input type="hidden" name="return_tab" value="ingest">
                                            <button type="submit" name="mode" value="update" class="button success" <?= $canScrape ? '' : 'disabled' ?>>Szybka aktualizacja</button>
                                            <button
                                                type="submit"
                                                name="mode"
                                                value="deep"
                                                class="button warning"
                                                <?= $canScrape ? '' : 'disabled' ?>
                                                onclick="return confirm('Uruchomić głęboki skan? To może potrwać długo.');"
                                            >Głęboki skan</button>
                                        </form>

                                        <form method="post" class="actions" style="margin-top: 0.6rem;">
                                            <input type="hidden" name="action" value="run_scrape">
                                            <input type="hidden" name="return_tab" value="ingest">
                                            <input type="hidden" name="mode" value="deep">
                                            <input type="hidden" name="scroll_limit" value="200">
                                            <button
                                                type="submit"
                                                class="button secondary"
                                                <?= $canScrape ? '' : 'disabled' ?>
                                                title="Re-scan bez pełnego deep-scan (wypełnia content_type i rozwija treści gdy się da)"
                                            >Backfill content_type (200 scrolli)</button>
                                        </form>
                                    </section>
                                <?php endif; ?>

                                <?php if ($overviewTab === 'quality'): ?>
                                    <?php
                                        $hydr = is_array($hydrationRun) ? ($hydrationRun['details'] ?? null) : null;
                                        $hAttempted = is_array($hydr) ? (int)($hydr['permalink_hydrate_attempted'] ?? 0) : 0;
                                        $hOk = is_array($hydr) ? (int)($hydr['permalink_hydrate_ok'] ?? 0) : 0;
                                        $hUpgraded = is_array($hydr) ? (int)($hydr['permalink_hydrate_upgraded'] ?? 0) : 0;
                                        $hFailed = is_array($hydr) ? (int)($hydr['permalink_hydrate_failed'] ?? 0) : 0;
                                        $hCoverage = $hAttempted > 0 ? (int)round(($hUpgraded / $hAttempted) * 100.0) : 0;
                                    ?>
                                    <section class="panel" aria-label="Jakość treści">
                                        <h5 style="margin-top:0;">Jakość treści</h5>
                                        <div class="callout" style="border-radius: 12px;">
                                            <div class="kpi-title" style="margin-bottom:0.4rem;">
                                                Permalink hydration (ostatni run z permalinks)
                                                <span
                                                    class="help"
                                                    title="Mechanizm dogrywania pełnej treści: gdy karta w feedzie jest ucięta ('... więcej/see more'), scraper (w limicie hydrate_limit) otwiera permalink i próbuje pobrać pełniejszą treść do items.content."
                                                >?</span>
                                            </div>
                                            <?php if (!$hydrationRun): ?>
                                                <div style="color: var(--muted);">Brak runów z permalink hydration.</div>
                                            <?php else: ?>
                                                <div style="color: var(--muted); margin-bottom:0.35rem;">
                                                    Run #<strong><?= (int)$hydrationRun['id'] ?></strong>
                                                    | <?= h(format_dt($hydrationRun['started_at'] ?? null)) ?>
                                                    | status: <strong><?= h((string)($hydrationRun['status'] ?? '-')) ?></strong>
                                                </div>
                                                <div class="grid-x grid-padding-x">
                                                    <div class="cell small-6 medium-3">
                                                        <strong><?= $hAttempted ?></strong>
                                                        <div class="kpi-title" title="Ile permalinków otwarto w tym runie (limit hydrate_limit).">attempted</div>
                                                    </div>
                                                    <div class="cell small-6 medium-3">
                                                        <strong><?= $hOk ?></strong>
                                                        <div class="kpi-title" title="Ile z prób zakończyło się poprawnym odczytem treści z permalinku.">ok</div>
                                                    </div>
                                                    <div class="cell small-6 medium-3">
                                                        <strong><?= $hUpgraded ?></strong>
                                                        <div class="kpi-title" title="Ile razy treść w items.content została realnie ulepszona (dłuższa/pełniejsza).">upgraded</div>
                                                    </div>
                                                    <div class="cell small-6 medium-3">
                                                        <strong><?= $hFailed ?></strong>
                                                        <div class="kpi-title" title="Ile prób zakończyło się błędem (timeout, brak treści, błąd DOM).">failed</div>
                                                    </div>
                                                </div>
                                                <div style="margin-top:0.35rem; color: var(--muted);">
                                                    Coverage (upgraded/attempted): <strong><?= $hCoverage ?>%</strong>
                                                    <span class="help" title="Skuteczność ulepszeń: upgraded / attempted.">?</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <form method="post" class="actions" style="margin-top: 0.6rem;">
                                            <input type="hidden" name="action" value="run_scrape">
                                            <input type="hidden" name="return_tab" value="quality">
                                            <input type="hidden" name="mode" value="update">
                                            <input type="hidden" name="scroll_limit" value="120">
                                            <input type="hidden" name="hydrate_limit" value="25">
                                            <button
                                                type="submit"
                                                class="button secondary"
                                                <?= $canScrape ? '' : 'disabled' ?>
                                                title="Dogrywa pełną treść z permalinków, gdy w karcie zostaje '...więcej/see more' (limit 25 na run)."
                                            >Backfill pełna treść (25 permalinks)</button>
                                        </form>

                                        <form method="post" class="actions" style="margin-top: 0.6rem;">
                                            <input type="hidden" name="action" value="hydrate_only">
                                            <input type="hidden" name="return_tab" value="quality">
                                            <input type="hidden" name="limit" value="20">
                                            <input type="hidden" name="max_content_len" value="1200">
                                            <input type="hidden" name="only_without_notes" value="1">
                                            <button
                                                type="submit"
                                                class="button secondary"
                                                <?= $canScrape ? '' : 'disabled' ?>
                                                title="Hydrate-only: bez scrollowania sekcji. Wybiera kandydatów z DB (Inbox: bez notatek) i dogrywa z permalinku."
                                            >Hydrate-only (Inbox 20)</button>
                                        </form>

                                        <form method="post" class="actions" style="margin-top: 0.6rem;">
                                            <input type="hidden" name="action" value="hydrate_only">
                                            <input type="hidden" name="return_tab" value="quality">
                                            <input type="hidden" name="limit" value="20">
                                            <input type="hidden" name="max_content_len" value="1200">
                                            <button
                                                type="submit"
                                                class="button secondary"
                                                <?= $canScrape ? '' : 'disabled' ?>
                                                title="Hydrate-only: bez scrollowania sekcji. Wybiera kandydatów z DB (wszystkie) i dogrywa z permalinku."
                                            >Hydrate-only (All 20)</button>
                                        </form>

                                        <form method="post" class="actions" style="margin-top: 0.6rem;">
                                            <input type="hidden" name="action" value="hydrate_only">
                                            <input type="hidden" name="return_tab" value="quality">
                                            <input type="hidden" name="limit" value="20">
                                            <input type="hidden" name="max_content_len" value="1200">
                                            <input type="hidden" name="only_without_notes" value="1">
                                            <input type="hidden" name="kind" value="save">
                                            <button
                                                type="submit"
                                                class="button secondary"
                                                <?= $canScrape ? '' : 'disabled' ?>
                                                title="Hydrate-only: bez scrollowania sekcji. Kandydaci z DB (Inbox + Saved) i dogrywanie z permalinku."
                                            >Hydrate-only (Inbox + Saved 20)</button>
                                        </form>
                                    </section>
                                <?php endif; ?>

                                <?php if ($overviewTab === 'runs'): ?>
                                    <section class="panel runs-panel" aria-label="Ostatnie uruchomienia">
                                        <div class="grid-x grid-padding-x align-middle">
                                            <div class="cell small-12 medium-8">
                                                <h5 style="margin-top:0;">Ostatnie uruchomienia</h5>
                                            </div>
                                            <div class="cell small-12 medium-4">
                                                <div class="actions" style="justify-content:flex-end;">
                                                    <a class="button secondary" href="/?view=runs">Pełna lista</a>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="table-wrap runs-table-wrap">
                                            <table>
                                                <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Tryb</th>
                                                    <th>Status</th>
                                                    <th>Start</th>
                                                    <th>Koniec</th>
                                                    <th>Nowe</th>
                                                    <th>Seen</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach (array_slice($latestRuns, 0, 30) as $run): ?>
                                                    <tr>
                                                        <td><?= (int)$run['id'] ?></td>
                                                        <td><?= h($run['mode']) ?></td>
                                                        <td><?= h($run['status']) ?></td>
                                                        <td><?= h(format_dt($run['started_at'])) ?></td>
                                                        <td><?= h(format_dt($run['finished_at'])) ?></td>
                                                        <td><?= (int)$run['new_posts'] ?></td>
                                                        <td><?= (int)$run['total_seen'] ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($view === 'feed'): ?>
                    <section class="panel" aria-label="Scraper logs">
                        <h5 style="margin-top:0;">Logi scrapera (legacy)</h5>
                        <p style="color: var(--muted); margin-top: -0.4rem;">
                            To jest techniczny podgląd rekordów z tabeli <code>posts</code> (historyczny model: <code>all/reactions/comments</code>).
                            Do pracy z archiwum używaj zakładki <strong>Archiwum LinkedIn</strong>.
                        </p>
                        <form method="get" class="grid-x grid-margin-x align-bottom">
                            <input type="hidden" name="view" value="feed">
                            <div class="cell small-12 medium-4">
                                <label>Sekcja scrapera (source_page)
                                    <select name="feed_source">
                                        <option value="">Wszystkie</option>
                                        <option value="all" <?= $feedSource === 'all' ? 'selected' : '' ?>>all</option>
                                        <option value="reactions" <?= $feedSource === 'reactions' ? 'selected' : '' ?>>reactions</option>
                                        <option value="comments" <?= $feedSource === 'comments' ? 'selected' : '' ?>>comments</option>
                                    </select>
                                </label>
                            </div>
                            <div class="cell small-12 medium-2">
                                <button type="submit" class="button primary">Filtruj</button>
                            </div>
                        </form>

                        <div class="table-wrap">
                            <table>
                                <thead>
	                                <tr>
	                                    <th>Data</th>
	                                    <th>Autor</th>
	                                    <th>Treść</th>
	                                    <th>Typ</th>
	                                    <th>Źródło</th>
	                                    <th>Link</th>
	                                </tr>
                                </thead>
                                <tbody>
	                                <?php foreach ($feedItems as $item): ?>
	                                    <tr>
	                                        <td><?= h(format_dt($item['collected_at'])) ?></td>
	                                        <td><?= h($item['author'] ?? '-') ?></td>
	                                        <td>
	                                            <a href="/?view=post&id=<?= (int)$item['id'] ?>"><?= h(short_text($item['content'] ?? '', 220)) ?></a>
	                                        </td>
	                                        <td><?= h($item['activity_type'] ?? '-') ?></td>
	                                        <td><span class="source-badge"><?= h($item['source_page']) ?></span></td>
	                                        <td>
	                                            <?php if (!empty($item['post_url'])): ?>
	                                                <a href="<?= h($item['post_url']) ?>" target="_blank" rel="noopener">Otwórz</a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
	                    </section>
	                <?php endif; ?>

		                <?php if ($view === 'library'): ?>
		                    <section class="panel" aria-label="Library">
		                        <h5 style="margin-top:0;">Archiwum LinkedIn (Items + Contexts)</h5>

		                        <form method="get">
		                            <input type="hidden" name="view" value="library">
		                            <div class="search-grid">
		                                <label>Źródło (source)
		                                    <select name="lib_source">
		                                        <option value="">Wszystkie</option>
		                                        <option value="activity_all" <?= $libSource === 'activity_all' ? 'selected' : '' ?>>activity_all</option>
		                                        <option value="activity_reactions" <?= $libSource === 'activity_reactions' ? 'selected' : '' ?>>activity_reactions</option>
		                                        <option value="activity_comments" <?= $libSource === 'activity_comments' ? 'selected' : '' ?>>activity_comments</option>
		                                        <option value="saved_posts" <?= $libSource === 'saved_posts' ? 'selected' : '' ?>>saved_posts</option>
		                                        <option value="saved_articles" <?= $libSource === 'saved_articles' ? 'selected' : '' ?>>saved_articles</option>
		                                    </select>
		                                </label>
		                                <label>Akcja (activity_kind)
		                                    <select name="lib_kind">
		                                        <option value="">Wszystkie</option>
		                                        <option value="post" <?= $libKind === 'post' ? 'selected' : '' ?>>post</option>
		                                        <option value="share" <?= $libKind === 'share' ? 'selected' : '' ?>>share</option>
		                                        <option value="reaction" <?= $libKind === 'reaction' ? 'selected' : '' ?>>reaction</option>
		                                        <option value="comment" <?= $libKind === 'comment' ? 'selected' : '' ?>>comment</option>
		                                        <option value="save" <?= $libKind === 'save' ? 'selected' : '' ?>>save</option>
		                                    </select>
		                                </label>
		                                <label>Typ treści (content_type)
		                                    <select name="lib_type">
		                                        <option value="">Wszystkie</option>
		                                        <option value="text" <?= $libType === 'text' ? 'selected' : '' ?>>text</option>
		                                        <option value="article" <?= $libType === 'article' ? 'selected' : '' ?>>article</option>
		                                        <option value="video" <?= $libType === 'video' ? 'selected' : '' ?>>video</option>
		                                        <option value="image" <?= $libType === 'image' ? 'selected' : '' ?>>image</option>
		                                        <option value="document" <?= $libType === 'document' ? 'selected' : '' ?>>document</option>
		                                        <option value="unknown" <?= $libType === 'unknown' ? 'selected' : '' ?>>unknown</option>
		                                    </select>
		                                </label>
		                                <label>Status wiedzy
		                                    <select name="lib_status">
		                                        <option value="">Wszystkie</option>
		                                        <option value="inbox" <?= $libStatus === 'inbox' ? 'selected' : '' ?>>Inbox (bez notatki)</option>
		                                        <option value="reviewed" <?= $libStatus === 'reviewed' ? 'selected' : '' ?>>Przejrzane (✓)</option>
		                                        <option value="processed" <?= $libStatus === 'processed' ? 'selected' : '' ?>>Opracowane (notatka)</option>
		                                    </select>
		                                </label>
		                                <label>Tag
		                                    <select name="lib_tag">
		                                        <option value="">Dowolny</option>
		                                        <?php foreach ($tags as $tagName): ?>
		                                            <option value="<?= h($tagName) ?>" <?= $libTag === $tagName ? 'selected' : '' ?>><?= h($tagName) ?></option>
		                                        <?php endforeach; ?>
		                                    </select>
		                                </label>
		                            </div>
		                            <div class="actions" style="margin-top: 0.8rem;">
		                                <button type="submit" class="button primary">Filtruj</button>
		                                <a class="button secondary" href="/?view=library">Wyczyść</a>
		                            </div>
		                        </form>

		                        <?php
		                            $libPresetBase = [
		                                'view' => 'library',
		                                'lib_source' => $libSource,
		                                'lib_kind' => $libKind,
		                                'lib_type' => $libType,
		                                'lib_status' => $libStatus,
		                                'lib_tag' => $libTag,
		                            ];
		                        ?>
		                        <div class="actions" style="margin-top: 0.4rem;">
		                            <a class="button secondary" href="/?<?= h(http_build_query(array_merge($libPresetBase, ['lib_status' => 'inbox']), '', '&', PHP_QUERY_RFC3986)) ?>">Inbox</a>
		                            <a class="button secondary" href="/?<?= h(http_build_query(array_merge($libPresetBase, ['lib_status' => 'reviewed']), '', '&', PHP_QUERY_RFC3986)) ?>">Przejrzane</a>
		                            <a class="button secondary" href="/?<?= h(http_build_query(array_merge($libPresetBase, ['lib_status' => 'processed']), '', '&', PHP_QUERY_RFC3986)) ?>">Opracowane</a>
		                            <?php if (trim((string)$libTag) !== ''): ?>
		                                <a class="button hollow" href="/?<?= h(http_build_query(['view' => 'topic', 'tag' => $libTag], '', '&', PHP_QUERY_RFC3986)) ?>">Opracowane w tym temacie</a>
		                            <?php endif; ?>
		                            <a class="button secondary" href="/?view=library&lib_source=saved_posts&lib_kind=save">Saved posts</a>
		                            <a class="button secondary" href="/?view=library&lib_source=saved_articles&lib_kind=save">Saved articles</a>
		                        </div>

		                        <?php if ($libraryTopTags): ?>
		                            <div class="callout" style="border-radius: 12px; margin-top:0.8rem;">
		                                <div class="kpi-title">
		                                    Top tagi (ostatnie <?= (int)$libraryTopTagsDays ?> dni)
		                                    <span class="help" title="Tagi z elementów opracowanych merytorycznie (notatka != '✓ przejrzane ...') w ostatnich <?= (int)$libraryTopTagsDays ?> dniach. Klik = przejście do Biblioteki: status=processed + tag.">?</span>
		                                </div>
		                                <div class="meta-row" style="margin-top:0.6rem;">
		                                    <?php foreach ($libraryTopTags as $r): ?>
		                                        <?php $tg = (string)($r['tag'] ?? ''); $cnt = (int)($r['items'] ?? 0); ?>
		                                        <a
		                                            class="pill pill-tag"
		                                            href="/?<?= h(http_build_query(['view' => 'library', 'lib_status' => 'processed', 'lib_tag' => $tg], '', '&', PHP_QUERY_RFC3986)) ?>"
		                                            title="Opracowane (30 dni): <?= $cnt ?>"
		                                        ><?= h($tg) ?><span style="opacity:0.75; margin-left:0.35rem;"><?= $cnt ?></span></a>
		                                    <?php endforeach; ?>
		                                </div>
		                            </div>
		                        <?php endif; ?>

		                        <div style="color: var(--muted); margin: 0.5rem 0 0.7rem;">
		                            Wyników: <strong><?= count($libraryItems) ?></strong>
		                        </div>

	                        <div class="table-wrap">
	                            <table>
	                                <thead>
	                                <tr>
	                                    <th>Ostatnio</th>
	                                    <th>Autor</th>
	                                    <th>Treść</th>
	                                    <th>Typ</th>
	                                    <th>Konteksty</th>
	                                    <th>Link</th>
	                                </tr>
	                                </thead>
	                                <tbody>
	                                <?php foreach ($libraryItems as $row): ?>
	                                    <tr>
	                                        <td><?= h(format_dt($row['last_context_at'] ?? null)) ?></td>
	                                        <td><?= h($row['author'] ?? '-') ?></td>
	                                        <td>
	                                            <a href="/?view=item&id=<?= (int)$row['id'] ?>"><?= h(short_text($row['content'] ?? '', 240)) ?></a>

	                                                <?php
	                                                    $rawTags = (string)($row['tags'] ?? '');
	                                                    $notes = (string)($row['user_notes'] ?? '');
	                                                    $isReviewed = is_processed_only($notes);
	                                                    $hasNote = is_processed_with_content($notes);
	                                                    $allTags = [];
	                                                    if ($rawTags !== '') {
	                                                        foreach (explode(',', $rawTags) as $t) {
	                                                            $t = trim($t);
	                                                            if ($t !== '') {
                                                                $allTags[] = $t;
                                                            }
                                                        }
                                                    }
	                                                    $shownTags = array_slice($allTags, 0, 3);
	                                                    $moreTags = max(0, count($allTags) - count($shownTags));
	                                                ?>

		                                                <div class="meta-row">
		                                                    <?php if ($isReviewed): ?>
		                                                        <span class="pill pill-reviewed">przejrzane</span>
		                                                    <?php elseif ($hasNote): ?>
		                                                        <span class="pill pill-note">notatka</span>
		                                                    <?php else: ?>
		                                                        <span class="pill pill-inbox">inbox</span>
		                                                    <?php endif; ?>

		                                                    <?php foreach ($shownTags as $t): ?>
		                                                        <a class="pill pill-tag" href="/?<?= h(http_build_query(['view' => 'search', 'tag' => $t], '', '&', PHP_QUERY_RFC3986)) ?>"><?= h($t) ?></a>
		                                                    <?php endforeach; ?>

		                                                    <?php if ($moreTags > 0): ?>
		                                                        <span class="pill pill-more">+<?= (int)$moreTags ?></span>
		                                                    <?php endif; ?>
		                                                </div>
		                                        </td>
	                                        <td><?= h($row['content_type'] ?? '-') ?></td>
	                                        <td style="color: var(--muted)"><?= h($row['contexts'] ?? '-') ?></td>
	                                        <td>
	                                            <?php if (!empty($row['canonical_url'])): ?>
	                                                <a href="<?= h($row['canonical_url']) ?>" target="_blank" rel="noopener">Otwórz</a>
	                                            <?php else: ?>
	                                                -
	                                            <?php endif; ?>
	                                        </td>
	                                    </tr>
	                                <?php endforeach; ?>
	                                </tbody>
	                            </table>
	                        </div>
		                    </section>
		                <?php endif; ?>

		                    <?php if ($view === 'editorial'): ?>
		                    <?php
		                        $edTabs = [
		                            'inbox' => 'Inbox',
		                            'drafts' => 'Drafty',
		                            'config' => 'Konfiguracja CMS',
		                        ];
		                        // `tab=draft` is an internal "edit mode" (a concrete draft); highlight Drafty in navigation.
		                        $edNavActive = $editorialTab === 'draft' ? 'drafts' : $editorialTab;
		                        $edStatusOptions = [
		                            'active' => 'Aktywne (selected/draft/in_progress/ready)',
		                            'selected' => 'selected',
		                            'draft' => 'draft',
		                            'in_progress' => 'in_progress',
		                            'ready' => 'ready',
		                            'published' => 'published',
		                            'archived' => 'archived',
		                        ];
		                        $edTopicLabels = [
		                            '' => 'Wszystkie',
		                            'ai' => 'AI',
		                            'oss' => 'OSS',
		                            'programming' => 'Programowanie',
		                            'fundamentals' => 'Fundamenty',
		                            'other' => 'Inne',
		                        ];
		                        // Keep CMS config tab self-contained (scroll inside panel) by using a workspace wrapper.
		                        $edUseWorkspace = $editorialTab === 'config';
		                    ?>
		                    <?php if ($edUseWorkspace): ?>
		                        <div class="editorial-workspace" aria-label="Redakcja (workspace)">
		                    <?php endif; ?>
		                    <section class="panel" aria-label="Redakcja">
		                        <div class="grid-x grid-padding-x align-middle">
		                            <div class="cell small-12">
		                                <h5 style="margin-top:0; margin-bottom:0.4rem;">Redakcja</h5>
		                                <div style="color: var(--muted);">
		                                    Pipeline publikacji. Źródło prawdy o treści to <code>items</code>, a praca redakcyjna jest w <code>editorial_*</code>.
		                                </div>
		                            </div>
		                        </div>
		                        <nav class="overview-tabs" aria-label="Zakładki Redakcja" style="margin-top:0.7rem;">
		                            <?php foreach ($edTabs as $k => $label): ?>
		                                <a
		                                    class="overview-tab <?= $edNavActive === $k ? 'is-active' : '' ?>"
		                                    href="/?<?= h(http_build_query(['view' => 'editorial', 'tab' => $k], '', '&', PHP_QUERY_RFC3986)) ?>"
		                                ><?= h($label) ?></a>
		                            <?php endforeach; ?>
		                        </nav>
		                    </section>

		                    <?php if ($editorialTab === 'inbox'): ?>
		                        <section class="panel" aria-label="Inbox redakcyjny">
		                            <h5 style="margin-top:0; margin-bottom:0.4rem;">Inbox redakcyjny</h5>
		                            <div style="color: var(--muted);">
		                                Domyślnie pokazuje aktywne elementy. Dodajesz je z <code>view=item</code> (tylko opracowane merytorycznie).
		                            </div>

		                            <?php
		                                $queueBaseQs = ['view' => 'editorial', 'tab' => 'inbox'];
		                                if ($editorialTopic !== '') {
		                                    $queueBaseQs['e_topic'] = $editorialTopic;
		                                }
		                                if ($editorialPrioRaw !== '') {
		                                    $queueBaseQs['e_prio'] = $editorialPrioRaw;
		                                }
		                                if ($editorialNoDraft) {
		                                    $queueBaseQs['e_nodraft'] = '1';
		                                }
		                            ?>
		                            <div class="meta-row" style="margin-top:0.65rem;">
		                                <span style="color: var(--muted); font-weight:800;">Aktywna kolejka:</span>
		                                <a
		                                    class="pill pill-ed-selected"
		                                    href="/?<?= h(http_build_query(array_merge($queueBaseQs, ['e_status' => 'selected']), '', '&', PHP_QUERY_RFC3986)) ?>"
		                                >selected: <strong><?= (int)($editorialQueueCounts['selected'] ?? 0) ?></strong></a>
		                                <a
		                                    class="pill pill-ed-draft"
		                                    href="/?<?= h(http_build_query(array_merge($queueBaseQs, ['e_status' => 'draft']), '', '&', PHP_QUERY_RFC3986)) ?>"
		                                >draft: <strong><?= (int)($editorialQueueCounts['draft'] ?? 0) ?></strong></a>
		                                <a
		                                    class="pill pill-ed-inprogress"
		                                    href="/?<?= h(http_build_query(array_merge($queueBaseQs, ['e_status' => 'in_progress']), '', '&', PHP_QUERY_RFC3986)) ?>"
		                                >in_progress: <strong><?= (int)($editorialQueueCounts['in_progress'] ?? 0) ?></strong></a>
		                                <a
		                                    class="pill pill-ed-ready"
		                                    href="/?<?= h(http_build_query(array_merge($queueBaseQs, ['e_status' => 'ready']), '', '&', PHP_QUERY_RFC3986)) ?>"
		                                >ready: <strong><?= (int)($editorialQueueCounts['ready'] ?? 0) ?></strong></a>
		                                <a
		                                    class="pill"
		                                    href="/?<?= h(http_build_query(array_merge($queueBaseQs, ['e_status' => 'active']), '', '&', PHP_QUERY_RFC3986)) ?>"
		                                    title="selected + draft + in_progress + ready"
		                                >razem: <strong><?= (int)($editorialQueueCounts['total'] ?? 0) ?></strong></a>
		                            </div>

		                            <form method="get" style="margin-top:0.8rem;">
		                                <input type="hidden" name="view" value="editorial">
		                                <input type="hidden" name="tab" value="inbox">
		                                <div class="search-grid">
		                                    <label>Status (editorial_status)
		                                        <select name="e_status">
		                                            <?php foreach ($edStatusOptions as $v => $lbl): ?>
		                                                <option value="<?= h($v) ?>" <?= ($editorialStatus !== '' ? $editorialStatus : 'active') === $v ? 'selected' : '' ?>><?= h($lbl) ?></option>
		                                            <?php endforeach; ?>
		                                        </select>
		                                    </label>
		                                    <label>Temat portalu
		                                        <select name="e_topic">
		                                            <?php foreach ($edTopicLabels as $v => $lbl): ?>
		                                                <option value="<?= h($v) ?>" <?= $editorialTopic === $v ? 'selected' : '' ?>><?= h($lbl) ?></option>
		                                            <?php endforeach; ?>
		                                        </select>
		                                    </label>
		                                    <label>Priorytet
		                                        <select name="e_prio">
		                                            <option value="" <?= $editorialPrioRaw === '' ? 'selected' : '' ?>>Dowolny</option>
		                                            <?php for ($p = 5; $p >= 1; $p--): ?>
		                                                <option value="<?= $p ?>" <?= $editorialPrioRaw === (string)$p ? 'selected' : '' ?>><?= $p ?></option>
		                                            <?php endfor; ?>
		                                        </select>
		                                    </label>
		                                    <label style="display:flex; align-items:flex-end; gap:0.4rem;">
		                                        <input type="checkbox" name="e_nodraft" value="1" <?= $editorialNoDraft ? 'checked' : '' ?>>
		                                        <span>Tylko bez szkicu</span>
		                                    </label>
		                                </div>
		                                <div class="actions" style="margin-top: 0.8rem;">
		                                    <button type="submit" class="button primary">Filtruj</button>
		                                    <a class="button secondary" href="/?view=editorial&tab=inbox">Wyczyść</a>
		                                </div>
		                            </form>

		                            <div style="color: var(--muted); margin: 0.6rem 0 0.7rem;">
		                                W kolejce: <strong><?= count($editorialInboxItems) ?></strong>
		                            </div>

		                            <?php if (!$editorialInboxItems): ?>
		                                <div class="callout warning" style="border-radius:12px;">
		                                    Brak elementów w kolejce dla wybranych filtrów.
		                                </div>
		                            <?php else: ?>
		                                <div class="table-wrap">
		                                    <table>
		                                        <thead>
		                                        <tr>
		                                            <th>Ostatnio</th>
		                                            <th>Źródło</th>
		                                            <th>Redakcja</th>
		                                            <th>Szkic</th>
		                                        </tr>
		                                        </thead>
		                                        <tbody>
		                                        <?php foreach ($editorialInboxItems as $r): ?>
		                                            <?php
		                                                $eid = (int)($r['id'] ?? 0);
		                                                $sid = (int)($r['source_item_id'] ?? 0);
		                                                $draftId = (int)($r['draft_id'] ?? 0);
		                                                $edSt = (string)($r['editorial_status'] ?? '');
		                                                $stPill = 'pill-more';
		                                                if ($edSt === 'selected') {
		                                                    $stPill = 'pill-ed-selected';
		                                                } elseif ($edSt === 'draft') {
		                                                    $stPill = 'pill-ed-draft';
		                                                } elseif ($edSt === 'in_progress') {
		                                                    $stPill = 'pill-ed-inprogress';
		                                                } elseif ($edSt === 'ready') {
		                                                    $stPill = 'pill-ed-ready';
		                                                }
		                                                $rawTags = (string)($r['tags'] ?? '');
		                                                $allTags = [];
		                                                if ($rawTags !== '') {
		                                                    foreach (explode(',', $rawTags) as $t) {
		                                                        $t = trim($t);
		                                                        if ($t !== '') {
		                                                            $allTags[] = $t;
		                                                        }
		                                                    }
		                                                }
		                                                $shownTags = array_slice($allTags, 0, 3);
		                                                $moreTags = max(0, count($allTags) - count($shownTags));
		                                            ?>
		                                            <tr>
		                                                <td><?= h(format_dt($r['updated_at'] ?? null)) ?></td>
		                                                <td>
		                                                    <div style="font-weight:700;"><?= h($r['author'] ?? '-') ?></div>
		                                                    <div style="margin-top:0.35rem;">
		                                                        <a href="/?view=item&id=<?= $sid ?>"><?= h(short_text($r['content'] ?? '', 220)) ?></a>
		                                                    </div>
		                                                    <?php if ($shownTags): ?>
		                                                        <div class="meta-row" style="margin-top:0.45rem;">
		                                                            <?php foreach ($shownTags as $t): ?>
		                                                                <a class="pill pill-tag" href="/?<?= h(http_build_query(['view' => 'search', 'tag' => $t], '', '&', PHP_QUERY_RFC3986)) ?>"><?= h($t) ?></a>
		                                                            <?php endforeach; ?>
		                                                            <?php if ($moreTags > 0): ?>
		                                                                <span class="pill pill-more">+<?= (int)$moreTags ?></span>
		                                                            <?php endif; ?>
		                                                        </div>
		                                                    <?php endif; ?>
		                                                    <div class="meta-row" style="margin-top:0.45rem;">
		                                                        <?php if (!empty($r['canonical_url'])): ?>
		                                                            <a class="pill" href="<?= h($r['canonical_url']) ?>" target="_blank" rel="noopener">LinkedIn</a>
		                                                        <?php endif; ?>
		                                                        <span class="pill"><?= h((string)($r['content_type'] ?? '-')) ?></span>
		                                                    </div>
		                                                </td>
		                                                <td style="min-width: 260px;">
		                                                    <div class="meta-row" style="margin-top:0; margin-bottom:0.45rem;">
		                                                        <span class="pill <?= h($stPill) ?>"><?= h($edSt !== '' ? $edSt : '-') ?></span>
		                                                        <span class="pill" title="Priorytet (5 = najwyższy)">prio: <strong><?= (int)($r['priority'] ?? 0) ?></strong></span>
		                                                    </div>
		                                                    <form method="post" class="actions" style="gap:0.35rem; flex-wrap:wrap;">
		                                                        <input type="hidden" name="action" value="editorial_update_item">
		                                                        <input type="hidden" name="editorial_item_id" value="<?= $eid ?>">
		                                                        <input type="hidden" name="return_e_status" value="<?= h($editorialStatus !== '' ? $editorialStatus : 'active') ?>">
		                                                        <input type="hidden" name="return_e_topic" value="<?= h($editorialTopic) ?>">
		                                                        <input type="hidden" name="return_e_prio" value="<?= h($editorialPrioRaw) ?>">
		                                                        <input type="hidden" name="return_e_nodraft" value="<?= $editorialNoDraft ? '1' : '' ?>">

		                                                        <select name="portal_topic" style="margin:0; min-width: 150px;">
		                                                            <?php foreach ($edTopicLabels as $v => $lbl): ?>
		                                                                <?php if ($v === '') { continue; } ?>
		                                                                <option value="<?= h($v) ?>" <?= (string)($r['portal_topic'] ?? '') === $v ? 'selected' : '' ?>><?= h($lbl) ?></option>
		                                                            <?php endforeach; ?>
		                                                        </select>
		                                                        <select name="editorial_status" style="margin:0; min-width: 140px;">
		                                                            <?php foreach (editorial_allowed_statuses() as $st): ?>
		                                                                <option value="<?= h($st) ?>" <?= (string)($r['editorial_status'] ?? '') === $st ? 'selected' : '' ?>><?= h($st) ?></option>
		                                                            <?php endforeach; ?>
		                                                        </select>
		                                                        <select name="priority" style="margin:0; width: 80px;">
		                                                            <?php for ($p = 5; $p >= 1; $p--): ?>
		                                                                <option value="<?= $p ?>" <?= (int)($r['priority'] ?? 3) === $p ? 'selected' : '' ?>><?= $p ?></option>
		                                                            <?php endfor; ?>
		                                                        </select>
		                                                        <button type="submit" class="button tiny" style="margin:0;">Zapisz</button>
		                                                    </form>
		                                                </td>
		                                                <td style="min-width: 180px;">
		                                                    <?php if ($draftId > 0): ?>
		                                                        <a class="button tiny secondary" href="/?<?= h(http_build_query(['view' => 'editorial', 'tab' => 'draft', 'draft_id' => $draftId], '', '&', PHP_QUERY_RFC3986)) ?>">Otwórz szkic</a>
		                                                        <?php if ($edSt !== 'ready' && $edSt !== 'published' && $edSt !== 'archived'): ?>
		                                                            <form method="post" style="display:block; margin-top:0.35rem;">
		                                                                <input type="hidden" name="action" value="editorial_update_item">
		                                                                <input type="hidden" name="editorial_item_id" value="<?= $eid ?>">
		                                                                <input type="hidden" name="editorial_status" value="ready">
		                                                                <input type="hidden" name="return_e_status" value="<?= h($editorialStatus !== '' ? $editorialStatus : 'active') ?>">
		                                                                <input type="hidden" name="return_e_topic" value="<?= h($editorialTopic) ?>">
		                                                                <input type="hidden" name="return_e_prio" value="<?= h($editorialPrioRaw) ?>">
		                                                                <input type="hidden" name="return_e_nodraft" value="<?= $editorialNoDraft ? '1' : '' ?>">
		                                                                <button type="submit" class="button tiny success" style="margin:0;">Oznacz jako gotowe</button>
		                                                            </form>
		                                                        <?php endif; ?>
		                                                    <?php else: ?>
		                                                        <form method="post" style="display:inline;">
		                                                            <input type="hidden" name="action" value="editorial_create_draft">
		                                                            <input type="hidden" name="editorial_item_id" value="<?= $eid ?>">
		                                                            <button type="submit" class="button tiny success" style="margin:0;">Utwórz szkic</button>
		                                                        </form>
		                                                    <?php endif; ?>
		                                                </td>
		                                            </tr>
		                                        <?php endforeach; ?>
		                                        </tbody>
		                                    </table>
		                                </div>
		                            <?php endif; ?>
		                        </section>
		                    <?php endif; ?>

		                    <?php if ($editorialTab === 'drafts'): ?>
		                        <section class="panel" aria-label="Drafty">
		                            <h5 style="margin-top:0; margin-bottom:0.4rem;">Drafty</h5>
		                            <div style="color: var(--muted);">
		                                Lista szkiców lokalnych (warsztat). Wysyłka do CMS dojdzie w kolejnym sprincie.
		                            </div>
		                            <div style="color: var(--muted); margin: 0.6rem 0 0.7rem;">
		                                Szkiców: <strong><?= count($editorialDrafts) ?></strong>
		                            </div>
		                            <div class="table-wrap">
		                                <table>
		                                    <thead>
		                                    <tr>
		                                        <th>Ostatnio</th>
		                                        <th>Tytuł</th>
		                                        <th>Status</th>
		                                        <th>Temat</th>
		                                        <th>Priorytet</th>
		                                        <th>Źródło</th>
		                                        <th>Akcja</th>
		                                    </tr>
		                                    </thead>
		                                    <tbody>
		                                    <?php foreach ($editorialDrafts as $d): ?>
		                                        <?php $did = (int)($d['id'] ?? 0); ?>
		                                        <tr>
		                                            <td><?= h(format_dt($d['updated_at'] ?? null)) ?></td>
		                                            <td><?= h($d['title'] ?? '-') ?></td>
		                                            <td><?= h($d['editorial_status'] ?? '-') ?></td>
		                                            <td><?= h($d['portal_topic'] ?? '-') ?></td>
		                                            <td><?= (int)($d['priority'] ?? 0) ?></td>
		                                            <td><?= h($d['author'] ?? '-') ?></td>
		                                            <td>
		                                                <a class="button tiny secondary" href="/?<?= h(http_build_query(['view' => 'editorial', 'tab' => 'draft', 'draft_id' => $did], '', '&', PHP_QUERY_RFC3986)) ?>">Otwórz</a>
		                                            </td>
		                                        </tr>
		                                    <?php endforeach; ?>
		                                    </tbody>
		                                </table>
		                            </div>
		                        </section>
		                    <?php endif; ?>

		                    <?php if ($editorialTab === 'config'): ?>
		                        <?php
		                            $cfg = is_array($strapiCfg) ? $strapiCfg : get_strapi_config($pdo);
		                            $cmsBase = (string)($cfg['base_url'] ?? '');
		                            $cmsCt = (string)($cfg['content_type'] ?? '');
		                            $cmsEnabled = (bool)($cfg['enabled'] ?? true);
		                            $cmsDisabled = (bool)($cfg['disabled'] ?? false);
		                            $cmsReady = (bool)($cfg['ready'] ?? false);
		                            $cmsPartial = (bool)($cfg['partial'] ?? false);
		                            $cmsSource = (string)($cfg['source'] ?? 'env');
		                            $cmsUpdatedAt = (string)($cfg['updated_at'] ?? '');
		                            $cmsTokenMask = (string)($cfg['api_token_mask'] ?? 'brak');
		                            $cmsSecretOk = (bool)($cfg['secret_ok'] ?? false);
		                            $cmsErrors = is_array($cfg['errors'] ?? null) ? $cfg['errors'] : [];

		                            $cmsCallout = $cmsDisabled ? 'secondary' : ($cmsReady ? 'success' : ($cmsPartial ? 'warning' : 'alert'));
		                        ?>
		                        <section class="panel cms-config" aria-label="Konfiguracja CMS">
		                            <h5 style="margin-top:0; margin-bottom:0.4rem;">Konfiguracja CMS (Strapi)</h5>
		                            <div style="color: var(--muted);">
		                                Panel kontrolny integracji: runtime konfiguracja (DB), test połączenia i runtime‑dokumentacja mapowania pól.
		                            </div>

		                            <div class="cms-config__scroll">
		                                <div class="callout <?= h($cmsCallout) ?>" style="border-radius:12px; margin-top:0.8rem;">
		                                    <?php if ($cmsDisabled): ?>
		                                        <strong>Status:</strong> ⚫ Integracja wyłączona
		                                    <?php elseif ($cmsReady): ?>
		                                        <strong>Status:</strong> 🟢 Połączenie gotowe
		                                    <?php elseif ($cmsPartial): ?>
		                                        <strong>Status:</strong> 🟡 Skonfigurowane częściowo
		                                    <?php else: ?>
		                                        <strong>Status:</strong> 🔴 Brak konfiguracji
		                                    <?php endif; ?>
		                                </div>

		                                <?php if ($cmsErrors): ?>
		                                    <div class="callout alert" style="border-radius:12px; margin-top:0.6rem;">
		                                        <strong>Problem:</strong> <?= h(implode(' ', array_map('strval', $cmsErrors))) ?>
		                                    </div>
		                                <?php endif; ?>

		                                <div class="grid-x grid-padding-x" style="margin-top:0.2rem;">
		                                    <div class="cell small-12 large-6">
		                                        <div class="kpi-title" style="margin-bottom:0.35rem;">Ustawienia (runtime)</div>
		                                        <div class="meta-row" style="margin-top:0; margin-bottom:0.6rem;">
		                                            <span class="pill">Źródło: <?= $cmsSource === 'db' ? 'DB' : 'ENV (fallback)' ?></span>
		                                            <?php if ($cmsSource === 'db' && trim($cmsUpdatedAt) !== ''): ?>
		                                                <span class="pill">aktualizacja: <?= h($cmsUpdatedAt) ?></span>
		                                            <?php endif; ?>
		                                            <span class="pill <?= $cmsSecretOk ? 'pill-ed-ready' : 'pill-ed-selected' ?>" title="APP_SECRET jest wymagany do szyfrowania/odszyfrowania tokena w DB.">
		                                                APP_SECRET: <?= $cmsSecretOk ? 'OK' : 'brak' ?>
		                                            </span>
		                                        </div>
		                                        <?php if (!$cmsSecretOk): ?>
		                                            <div class="callout warning" style="border-radius:12px; margin-top:0; margin-bottom:0.7rem;">
		                                                <strong>Wymagane:</strong> <code>APP_SECRET</code> (szyfrowanie tokena API w DB).
		                                                Po ustawieniu wykonaj:
		                                                <code>docker compose up -d --force-recreate web</code>.
		                                            </div>
		                                        <?php endif; ?>
		                                        <form method="post" style="margin-bottom:0;">
		                                            <input type="hidden" name="action" value="cms_config_save">
		                                            <label>Strapi Base URL
		                                                <input type="url" name="cms_base_url" value="<?= h($cmsBase) ?>" placeholder="https://cms.twojadomena.pl" required>
		                                            </label>
		                                            <label>Content Type
		                                                <input type="text" name="cms_content_type" value="<?= h($cmsCt !== '' ? $cmsCt : 'articles') ?>" placeholder="articles" required>
		                                            </label>
		                                            <label>API Token
		                                                <input type="password" name="cms_api_token" placeholder="<?= h($cmsTokenMask !== 'brak' ? $cmsTokenMask : 'sk_********') ?>" <?= $cmsSecretOk ? '' : 'disabled' ?>>
		                                                <?php if ($cmsSecretOk): ?>
		                                                    <small>Pozostaw puste, aby nie zmieniać (aktualny: <code><?= h($cmsTokenMask) ?></code>).</small>
		                                                <?php else: ?>
		                                                    <small>Ustaw <code>APP_SECRET</code>, aby móc zapisać token w DB.</small>
		                                                <?php endif; ?>
		                                            </label>
		                                            <label style="display:flex; align-items:center; gap:0.5rem; margin-top:0.4rem;">
		                                                <input type="checkbox" name="cms_enabled" value="1" <?= $cmsEnabled ? 'checked' : '' ?>>
		                                                Integracja aktywna
		                                            </label>
		                                            <div class="actions" style="margin-top:0.7rem;">
		                                                <button type="submit" class="button primary" style="margin:0;">Zapisz konfigurację</button>
		                                            </div>
		                                        </form>
		                                    </div>
		                                    <div class="cell small-12 large-6">
		                                        <div class="kpi-title" style="margin-bottom:0.35rem;">Test połączenia</div>
		                                        <div style="color: var(--muted); margin-bottom:0.6rem;">
		                                            Wykonuje GET do <code>/api/&lt;content-type&gt;</code> z <code>pagination[pageSize]=1</code>.
		                                        </div>
		                                        <?php $lastOkAt = trim((string)($_SESSION['strapi_last_ok_at'] ?? '')); ?>
		                                        <?php if ($lastOkAt !== ''): ?>
		                                            <div class="meta-row" style="margin-top:0; margin-bottom:0.6rem;">
		                                                <span class="pill pill-reviewed">Ostatni udany test: <?= h($lastOkAt) ?></span>
		                                            </div>
		                                        <?php endif; ?>
		                                        <form method="post" class="actions">
		                                            <input type="hidden" name="action" value="strapi_healthcheck">
		                                            <button type="submit" class="button secondary" <?= $cmsDisabled ? 'disabled' : '' ?>>Testuj połączenie ze Strapi</button>
		                                        </form>
		                                    </div>
		                                </div>

		                                <details style="margin-top: 1rem;">
		                                    <summary style="cursor:pointer; font-weight:700;">Mapowanie pól (Redakcja → Strapi)</summary>
		                                    <div class="callout" style="border-radius:12px; margin-top:0.6rem;">
		                                        <div style="color: var(--muted); margin-bottom:0.6rem;">
		                                            To jest aktualne mapowanie oczekiwane przez kod wysyłki dla modelu <strong>Article</strong> w Strapi (API IDs muszą się zgadzać).
		                                        </div>
		                                        <table style="margin-bottom:0;">
		                                            <thead>
		                                            <tr>
		                                                <th>Strapi field</th>
		                                                <th>Źródło</th>
		                                            </tr>
		                                            </thead>
		                                            <tbody>
		                                            <tr><td><code>title</code></td><td><code>editorial_drafts.title</code></td></tr>
		                                            <tr><td><code>slug</code></td><td>generowany z <code>title</code> (slugify)</td></tr>
		                                            <tr><td><code>excerpt</code></td><td><code>editorial_drafts.lead_text</code></td></tr>
		                                            <tr><td><code>content</code></td><td><code>editorial_drafts.body</code></td></tr>
		                                            <tr><td><code>authorName</code></td><td>stała wartość (<code>Redakcja OneNetworks</code>)</td></tr>
		                                            <tr><td><code>publishOn</code></td><td>NOW() w UTC (ISO 8601)</td></tr>
		                                            </tbody>
		                                        </table>
		                                    </div>
		                                </details>

		                                <details style="margin-top: 0.9rem;">
		                                    <summary style="cursor:pointer; font-weight:700;">Checklist: co ustawić w Strapi</summary>
		                                    <div class="callout" style="border-radius:12px; margin-top:0.6rem;">
		                                        <div class="kpi-title" style="margin-bottom:0.4rem;">Strapi (Content Type)</div>
		                                        <ul style="margin-bottom:0.6rem;">
		                                            <li><strong>Draft &amp; Publish</strong> włączone.</li>
		                                            <li>Content Type: <code><?= h(trim($cmsCt) !== '' ? $cmsCt : 'articles') ?></code>.</li>
		                                            <li>Pola (API IDs): <code>title</code>, <code>slug</code>, <code>excerpt</code>, <code>content</code>, <code>authorName</code>, <code>publishOn</code>.</li>
		                                        </ul>
		                                        <div class="kpi-title" style="margin-bottom:0.4rem;">API Token</div>
		                                        <ul style="margin-bottom:0.6rem;">
		                                            <li>Uprawnienia: <code>create</code>, <code>update</code> (opcjonalnie <code>findOne</code>).</li>
		                                        </ul>
		                                        <div class="kpi-title" style="margin-bottom:0.4rem;">ENV (kontener web)</div>
		                                        <ul style="margin-bottom:0;">
		                                            <li><code>APP_SECRET</code> (klucz szyfrowania tokena w DB).</li>
		                                            <li><code>STRAPI_BASE_URL</code></li>
		                                            <li><code>STRAPI_API_TOKEN</code></li>
		                                            <li><code>STRAPI_CONTENT_TYPE</code></li>
		                                        </ul>
		                                    </div>
		                                </details>
		                            </div>
		                        </section>
		                    <?php endif; ?>

		                    <?php if ($editorialTab === 'draft'): ?>
		                        <section class="panel" aria-label="Szkic">
		                            <?php if (!$editorialDraft): ?>
		                                <div class="callout warning" style="border-radius:12px;">
		                                    Brak szkicu lub brak parametru <code>draft_id</code>.
		                                </div>
		                            <?php else: ?>
		                                <div class="grid-x grid-padding-x align-middle">
		                                    <div class="cell small-12 medium-8">
		                                        <h5 style="margin-top:0; margin-bottom:0.4rem;">Szkic</h5>
		                                        <?php
		                                            $edSt = (string)($editorialDraft['editorial_status'] ?? '');
		                                            $stPill = 'pill-more';
		                                            if ($edSt === 'selected') {
		                                                $stPill = 'pill-ed-selected';
		                                            } elseif ($edSt === 'draft') {
		                                                $stPill = 'pill-ed-draft';
		                                            } elseif ($edSt === 'in_progress') {
		                                                $stPill = 'pill-ed-inprogress';
		                                            } elseif ($edSt === 'ready') {
		                                                $stPill = 'pill-ed-ready';
		                                            }
		                                            $tpKey = (string)($editorialDraft['portal_topic'] ?? '');
		                                            $tpLabel = $edTopicLabels[$tpKey] ?? ($tpKey !== '' ? $tpKey : '-');
		                                            $prio = (int)($editorialDraft['priority'] ?? 0);
		                                            $inboxLink = '/?' . http_build_query(
		                                                [
		                                                    'view' => 'editorial',
		                                                    'tab' => 'inbox',
		                                                    'e_status' => $edSt !== '' ? $edSt : 'active',
		                                                ],
		                                                '',
		                                                '&',
		                                                PHP_QUERY_RFC3986
		                                            );
		                                        ?>
		                                        <div class="meta-row" style="margin-top:0.35rem;">
		                                            <span class="pill pill-more">draft #<?= (int)$editorialDraft['id'] ?></span>
		                                            <span class="pill <?= h($stPill) ?>">status: <?= h($edSt !== '' ? $edSt : '-') ?></span>
		                                            <span class="pill">temat: <?= h($tpLabel) ?></span>
		                                            <span class="pill">prio: <?= $prio > 0 ? (int)$prio : '-' ?></span>
		                                            <span class="pill">ostatnio: <?= h(format_dt((string)($editorialDraft['updated_at'] ?? null))) ?></span>
		                                            <a class="pill" href="<?= h($inboxLink) ?>">Zobacz w Inboxie</a>
		                                        </div>
		                                    </div>
		                                    <div class="cell small-12 medium-4">
		                                        <div class="actions" style="justify-content:flex-end;">
		                                            <a class="button secondary" href="/?view=editorial&tab=inbox">Inbox</a>
		                                            <a class="button secondary" href="/?view=editorial&tab=drafts">Drafty</a>
		                                            <a class="button secondary" href="/?view=item&id=<?= (int)$editorialDraft['source_item_id'] ?>">Źródło</a>
		                                            <?php if (!empty($editorialDraft['canonical_url'])): ?>
		                                                <a class="button hollow" href="<?= h($editorialDraft['canonical_url']) ?>" target="_blank" rel="noopener">LinkedIn</a>
		                                            <?php endif; ?>
		                                            <?php
		                                                $cmsCfg = is_array($strapiCfg) ? $strapiCfg : get_strapi_config($pdo);
		                                                $cmsReadyHere = (bool)($cmsCfg['ready'] ?? false);
		                                                $cmsDisabledHere = (bool)($cmsCfg['disabled'] ?? false);
		                                            ?>
		                                            <?php if ($cmsReadyHere): ?>
		                                                <form method="post" style="display:inline;">
		                                                    <input type="hidden" name="action" value="editorial_push_to_cms">
		                                                    <input type="hidden" name="draft_id" value="<?= (int)$editorialDraft['id'] ?>">
		                                                    <button type="submit" class="button success" style="margin:0;">Wyślij do CMS</button>
		                                                </form>
		                                            <?php endif; ?>
		                                        </div>
		                                        <div class="meta-row" style="margin-top:0.45rem; justify-content:flex-end;">
		                                            <?php
		                                                $cmsSt = (string)($editorialDraft['cms_status'] ?? 'local_draft');
		                                                $cmsId = trim((string)($editorialDraft['cms_external_id'] ?? ''));
		                                            ?>
		                                            <span class="pill">CMS: <?= h($cmsSt) ?></span>
		                                            <?php if ($cmsId !== ''): ?>
		                                                <span class="pill">cms_id: <?= h($cmsId) ?></span>
		                                            <?php endif; ?>
		                                            <?php if (!$cmsReadyHere): ?>
		                                                <?php if ($cmsDisabledHere): ?>
		                                                    <span class="pill pill-more" title="Integracja CMS jest wyłączona w Redakcja → Konfiguracja CMS.">CMS wyłączony</span>
		                                                <?php else: ?>
		                                                    <span class="pill pill-more" title="Skonfiguruj integrację w Redakcja → Konfiguracja CMS.">CMS niegotowy</span>
		                                                <?php endif; ?>
		                                            <?php endif; ?>
		                                        </div>
		                                    </div>
		                                </div>

		                                <div class="callout" style="border-radius:12px; margin-top:0.8rem;">
		                                    <div class="grid-x grid-padding-x align-middle">
		                                        <div class="cell small-12 medium-8">
		                                            <div class="kpi-title" style="margin-bottom:0.35rem;">Pipeline (status / temat / priorytet)</div>
		                                            <form method="post">
		                                                <input type="hidden" name="action" value="editorial_update_item">
		                                                <input type="hidden" name="editorial_item_id" value="<?= (int)($editorialDraft['editorial_item_id'] ?? 0) ?>">
		                                                <input type="hidden" name="return_to" value="draft">
		                                                <input type="hidden" name="return_draft_id" value="<?= (int)($editorialDraft['id'] ?? 0) ?>">
		                                                <div class="search-grid">
		                                                    <label style="margin:0;">Status
		                                                        <select name="editorial_status">
		                                                            <?php foreach (editorial_allowed_statuses() as $st): ?>
		                                                                <option value="<?= h($st) ?>" <?= (string)($editorialDraft['editorial_status'] ?? '') === $st ? 'selected' : '' ?>><?= h($st) ?></option>
		                                                            <?php endforeach; ?>
		                                                        </select>
		                                                    </label>
		                                                    <label style="margin:0;">Temat portalu
		                                                        <select name="portal_topic">
		                                                            <?php foreach ($edTopicLabels as $v => $lbl): ?>
		                                                                <?php if ($v === '') { continue; } ?>
		                                                                <option value="<?= h($v) ?>" <?= (string)($editorialDraft['portal_topic'] ?? '') === $v ? 'selected' : '' ?>><?= h($lbl) ?></option>
		                                                            <?php endforeach; ?>
		                                                        </select>
		                                                    </label>
		                                                    <label style="margin:0;">Priorytet
		                                                        <select name="priority">
		                                                            <?php for ($p = 5; $p >= 1; $p--): ?>
		                                                                <option value="<?= $p ?>" <?= (int)($editorialDraft['priority'] ?? 3) === $p ? 'selected' : '' ?>><?= $p ?></option>
		                                                            <?php endfor; ?>
		                                                        </select>
		                                                    </label>
		                                                </div>
		                                                <div class="actions" style="margin-top:0.6rem;">
		                                                    <button type="submit" class="button secondary">Zapisz ustawienia</button>
		                                                </div>
		                                            </form>
		                                        </div>
		                                        <div class="cell small-12 medium-4">
		                                            <div class="kpi-title" style="margin-bottom:0.35rem;">Szybkie akcje</div>
		                                            <div class="actions" style="gap:0.35rem;">
		                                                <form method="post" style="display:inline;">
		                                                    <input type="hidden" name="action" value="editorial_update_item">
		                                                    <input type="hidden" name="editorial_item_id" value="<?= (int)($editorialDraft['editorial_item_id'] ?? 0) ?>">
		                                                    <input type="hidden" name="editorial_status" value="in_progress">
		                                                    <input type="hidden" name="return_to" value="draft">
		                                                    <input type="hidden" name="return_draft_id" value="<?= (int)($editorialDraft['id'] ?? 0) ?>">
		                                                    <button type="submit" class="button tiny warning" style="margin:0;">W trakcie</button>
		                                                </form>
		                                                <form method="post" style="display:inline;">
		                                                    <input type="hidden" name="action" value="editorial_update_item">
		                                                    <input type="hidden" name="editorial_item_id" value="<?= (int)($editorialDraft['editorial_item_id'] ?? 0) ?>">
		                                                    <input type="hidden" name="editorial_status" value="ready">
		                                                    <input type="hidden" name="return_to" value="draft">
		                                                    <input type="hidden" name="return_draft_id" value="<?= (int)($editorialDraft['id'] ?? 0) ?>">
		                                                    <button type="submit" class="button tiny success" style="margin:0;">Gotowe</button>
		                                                </form>
		                                            </div>
		                                            <div style="color: var(--muted); margin-top:0.5rem;">
		                                                Inbox to kolejka. Draft to miejsce pracy.
		                                            </div>
		                                        </div>
		                                    </div>
		                                </div>

		                                <form method="post" style="margin-top:0.8rem;">
		                                    <input type="hidden" name="action" value="editorial_save_draft">
		                                    <input type="hidden" name="draft_id" value="<?= (int)$editorialDraft['id'] ?>">
		                                    <label>Tytuł
		                                        <input type="text" name="title" value="<?= h((string)($editorialDraft['title'] ?? '')) ?>">
		                                    </label>
		                                    <label>Lead
		                                        <textarea name="lead_text" rows="3" placeholder="2–3 zdania wprowadzające"><?= h((string)($editorialDraft['lead_text'] ?? '')) ?></textarea>
		                                    </label>
		                                    <label>Body
		                                        <textarea name="body" rows="14" placeholder="Treść artykułu / konspekt (Markdown)"><?= h((string)($editorialDraft['body'] ?? '')) ?></textarea>
		                                    </label>
		                                    <div class="search-grid">
		                                        <label>Format
		                                            <select name="format">
		                                                <?php foreach (editorial_allowed_formats() as $fmt): ?>
		                                                    <option value="<?= h($fmt) ?>" <?= (string)($editorialDraft['format'] ?? '') === $fmt ? 'selected' : '' ?>><?= h($fmt) ?></option>
		                                                <?php endforeach; ?>
		                                            </select>
		                                        </label>
		                                        <label>SEO title (opcjonalnie)
		                                            <input type="text" name="seo_title" value="<?= h((string)($editorialDraft['seo_title'] ?? '')) ?>">
		                                        </label>
		                                        <label>SEO description (opcjonalnie)
		                                            <input type="text" name="seo_description" value="<?= h((string)($editorialDraft['seo_description'] ?? '')) ?>">
		                                        </label>
		                                    </div>
		                                    <div class="actions" style="margin-top:0.6rem;">
		                                        <button type="submit" class="button primary">Zapisz szkic</button>
		                                        <span style="color: var(--muted); font-size:0.9rem;">
		                                            Ostatnia zmiana: <?= h(format_dt((string)($editorialDraft['updated_at'] ?? null))) ?>
		                                        </span>
		                                    </div>
		                                </form>

		                                <details style="margin-top: 0.9rem;">
		                                    <summary style="cursor:pointer; font-weight:700;">Kontekst ze źródła (notatka + snapshot)</summary>
		                                    <div class="callout" style="border-radius:12px; margin-top:0.6rem;">
		                                        <div class="kpi-title" style="margin-bottom:0.4rem;">Notatka ze źródła</div>
		                                        <div style="white-space: pre-wrap; line-height:1.45;"><?= h((string)($editorialDraft['source_notes'] ?? '')) ?></div>
		                                    </div>
		                                    <div class="callout" style="border-radius:12px;">
		                                        <div class="kpi-title" style="margin-bottom:0.4rem;">Treść źródła (snapshot)</div>
		                                        <div style="white-space: pre-wrap; line-height:1.45;"><?= h(short_text((string)($editorialDraft['source_content'] ?? ''), 2200)) ?></div>
		                                    </div>
		                                </details>
		                            <?php endif; ?>
		                        </section>
		                    <?php endif; ?>

		                    <?php if ($edUseWorkspace): ?>
		                        </div>
		                    <?php endif; ?>
		                <?php endif; ?>

		                <?php if ($view === 'inbox'): ?>
		                    <section class="panel" aria-label="Inbox">
		                        <?php
		                            $inboxTitle = $inboxMode === 'focus'
	                                ? 'Inbox Focus' . ($inboxKind === 'save' ? ' + Saved' : '')
	                                : 'Inbox' . ($inboxKind === 'save' ? ' + Saved' : ' (do opracowania)');
	                            $inboxDesc = $inboxMode === 'focus'
	                                ? 'Tryb 5 minut: losuje 5 elementów z Inbox (pokazuje je po jednym). Dodaj notatkę (merytoryczną) albo oznacz jako przejrzane.'
	                                : 'Elementy z Archiwum bez notatki (do przetworzenia). Dodaj notatkę w widoku itemu, a wpis zniknie z Inbox.';
	                        ?>
	                        <div class="grid-x grid-margin-x align-middle">
	                            <div class="cell small-12 medium-8">
	                                <h5 style="margin-top:0; margin-bottom:0.4rem;">
	                                    <?= h($inboxTitle) ?>
	                                </h5>
	                                <div style="color: var(--muted);">
	                                    <?= h($inboxDesc) ?>
	                                </div>
	                            </div>
	                            <div class="cell small-12 medium-4">
	                                <div class="actions" style="justify-content:flex-end;">
	                                    <a class="button secondary" href="/?view=inbox">Inbox</a>
	                                    <a class="button secondary" href="/?view=inbox&kind=save">Inbox + Saved</a>
	                                    <a class="button hollow" href="/?<?= h(http_build_query(['view' => 'search', 'q' => $query, 'kind' => $inboxKind, 'only_without_notes' => '1'], '', '&', PHP_QUERY_RFC3986)) ?>">Zaawansowane filtry</a>
	                                </div>
	                            </div>
	                        </div>

	                        <form method="get" class="actions" style="margin-top: 0.8rem;">
	                            <input type="hidden" name="view" value="inbox">
	                            <?php if ($inboxMode !== ''): ?>
	                                <input type="hidden" name="mode" value="<?= h($inboxMode) ?>">
	                            <?php endif; ?>
	                            <input type="hidden" name="kind" value="<?= h($inboxKind) ?>">
	                            <label style="margin:0; flex:1; min-width: 260px;">
	                                Fraza (w Inbox)
	                                <input type="text" name="q" value="<?= h($query) ?>" placeholder="np. ksef, faktury, sd-wan">
	                            </label>
	                            <button type="submit" class="button primary">Filtruj</button>
	                            <?php
	                                $inboxClearQs = ['view' => 'inbox'];
	                                if ($inboxMode !== '') {
	                                    $inboxClearQs['mode'] = $inboxMode;
	                                }
	                                if ($inboxKind !== '') {
	                                    $inboxClearQs['kind'] = $inboxKind;
	                                }
	                            ?>
	                            <a class="button secondary" href="/?<?= h(http_build_query($inboxClearQs, '', '&', PHP_QUERY_RFC3986)) ?>">Wyczyść</a>
	                        </form>

	                        <div style="color: var(--muted); margin: 0.6rem 0 0.7rem;">
	                            W Inbox: <strong><?= count($inboxResults) ?></strong>
	                            <?php if ($inboxMode === 'focus' && $inboxFocusIds): ?>
	                                | Focus: <strong><?= count($inboxFocusIds) ?></strong>
	                            <?php endif; ?>
	                        </div>

	                        <?php if ($inboxMode === 'focus'): ?>
	                            <?php if (!$inboxFocusItem): ?>
	                                <div class="callout warning" style="border-radius: 12px;">
	                                    Brak elementów w Inbox dla wybranych filtrów.
	                                </div>
	                            <?php else: ?>
	                                <?php
	                                    $focusIdsStr = $inboxFocusIds ? implode(',', $inboxFocusIds) : '';
	                                    $focusBase = ['view' => 'inbox', 'mode' => 'focus'];
	                                    if ($inboxKind !== '') {
	                                        $focusBase['kind'] = $inboxKind;
	                                    }
	                                    if ($query !== '') {
	                                        $focusBase['q'] = $query;
	                                    }
	                                    if ($focusIdsStr !== '') {
	                                        $focusBase['focus_ids'] = $focusIdsStr;
	                                    }

	                                    $focusNext = $focusBase;
	                                    $focusNext['focus_idx'] = (string)($inboxFocusIdx + 1);
	                                    $focusNextUrl = '/?' . http_build_query($focusNext, '', '&', PHP_QUERY_RFC3986);

	                                    // Refresh = pick a new random 5 (drop focus_ids/focus_idx).
	                                    $focusRefresh = ['view' => 'inbox', 'mode' => 'focus'];
	                                    if ($inboxKind !== '') {
	                                        $focusRefresh['kind'] = $inboxKind;
	                                    }
	                                    if ($query !== '') {
	                                        $focusRefresh['q'] = $query;
	                                    }
	                                    $focusRefreshUrl = '/?' . http_build_query($focusRefresh, '', '&', PHP_QUERY_RFC3986);

	                                    $rawTags = (string)($inboxFocusItem['tags'] ?? '');
	                                    $allTags = [];
	                                    if ($rawTags !== '') {
	                                        foreach (explode(',', $rawTags) as $t) {
	                                            $t = trim($t);
	                                            if ($t !== '') {
	                                                $allTags[] = $t;
	                                            }
	                                        }
	                                    }
	                                    $shownTags = array_slice($allTags, 0, 3);
	                                    $moreTags = max(0, count($allTags) - count($shownTags));
	                                ?>

	                                <div class="callout" style="border-radius: 12px;">
	                                    <div style="color: var(--muted); margin-bottom:0.45rem;">
	                                        Element <strong><?= (int)($inboxFocusIdx + 1) ?></strong> / <strong><?= (int)count($inboxFocusIds) ?></strong>
	                                        (losowa piątka)
	                                    </div>

	                                    <div style="display:flex; justify-content:space-between; gap:0.6rem; flex-wrap:wrap;">
	                                        <div>
	                                            <strong><?= h($inboxFocusItem['author'] ?? '-') ?></strong>
	                                            <span style="color: var(--muted);">
	                                                | <?= h(format_dt($inboxFocusItem['last_context_at'] ?? null)) ?>
	                                                | <?= h($inboxFocusItem['content_type'] ?? '-') ?>
	                                            </span>
	                                        </div>
	                                        <div class="actions" style="gap:0.35rem;">
	                                            <?php if (!empty($inboxFocusItem['canonical_url'])): ?>
	                                                <a class="pill" href="<?= h($inboxFocusItem['canonical_url']) ?>" target="_blank" rel="noopener">LinkedIn</a>
	                                            <?php endif; ?>
	                                            <span class="pill"><?= h($inboxFocusItem['contexts'] ?? '-') ?></span>
	                                        </div>
	                                    </div>

	                                    <div style="margin-top:0.65rem; line-height:1.45;">
	                                        <a href="/?view=item&id=<?= (int)$inboxFocusItem['id'] ?>" style="text-decoration:none;">
	                                            <?= h(short_text($inboxFocusItem['content'] ?? '', 720)) ?>
	                                        </a>
	                                    </div>

	                                    <?php if ($shownTags): ?>
	                                        <div class="meta-row" style="margin-top:0.55rem;">
	                                            <?php foreach ($shownTags as $t): ?>
	                                                <a class="pill pill-tag" href="/?<?= h(http_build_query(['view' => 'search', 'tag' => $t], '', '&', PHP_QUERY_RFC3986)) ?>"><?= h($t) ?></a>
	                                            <?php endforeach; ?>

	                                            <?php if ($moreTags > 0): ?>
	                                                <span class="pill pill-more">+<?= (int)$moreTags ?></span>
	                                            <?php endif; ?>
	                                        </div>
	                                    <?php endif; ?>

	                                    <div class="actions" style="margin-top:0.85rem;">
	                                        <a class="button secondary" href="<?= h($focusNextUrl) ?>">Następny</a>
	                                        <a class="button hollow" href="<?= h($focusRefreshUrl) ?>">Losuj nowe 5</a>
	                                        <a class="button primary" href="/?view=item&id=<?= (int)$inboxFocusItem['id'] ?>">Dodaj notatkę</a>
	                                        <form method="post" style="display:inline;">
	                                            <input type="hidden" name="action" value="mark_inbox_processed">
	                                            <input type="hidden" name="item_id" value="<?= (int)$inboxFocusItem['id'] ?>">
	                                            <input type="hidden" name="return_mode" value="focus">
	                                            <input type="hidden" name="return_kind" value="<?= h($inboxKind) ?>">
	                                            <input type="hidden" name="return_q" value="<?= h($query) ?>">
	                                            <input type="hidden" name="return_focus_ids" value="<?= h($focusIdsStr) ?>">
	                                            <input type="hidden" name="return_focus_idx" value="<?= (int)$inboxFocusIdx ?>">
	                                            <button type="submit" class="button success">Oznacz jako przejrzane</button>
	                                        </form>
	                                    </div>
	                                </div>
	                            <?php endif; ?>
	                        <?php else: ?>
	                            <div class="table-wrap">
	                                <table>
	                                    <thead>
	                                    <tr>
	                                        <th>Ostatnio</th>
	                                        <th>Autor</th>
	                                        <th>Treść</th>
	                                        <th>Typ</th>
	                                        <th>Konteksty</th>
	                                        <th>Link</th>
	                                        <th>Akcja</th>
	                                    </tr>
	                                    </thead>
	                                    <tbody>
	                                    <?php foreach ($inboxResults as $item): ?>
	                                        <tr>
	                                            <td><?= h(format_dt($item['last_context_at'] ?? null)) ?></td>
	                                            <td><?= h($item['author'] ?? '-') ?></td>
	                                            <td>
	                                                <a href="/?view=item&id=<?= (int)$item['id'] ?>"><?= h(short_text($item['content'] ?? '', 260)) ?></a>

	                                                <?php
	                                                    $rawTags = (string)($item['tags'] ?? '');
	                                                    $allTags = [];
	                                                    if ($rawTags !== '') {
	                                                        foreach (explode(',', $rawTags) as $t) {
	                                                            $t = trim($t);
	                                                            if ($t !== '') {
	                                                                $allTags[] = $t;
	                                                            }
	                                                        }
	                                                    }
	                                                    $shownTags = array_slice($allTags, 0, 3);
	                                                    $moreTags = max(0, count($allTags) - count($shownTags));
	                                                ?>

	                                                <?php if ($shownTags): ?>
	                                                    <div class="meta-row">
	                                                        <?php foreach ($shownTags as $t): ?>
	                                                            <a class="pill pill-tag" href="/?<?= h(http_build_query(['view' => 'search', 'tag' => $t], '', '&', PHP_QUERY_RFC3986)) ?>"><?= h($t) ?></a>
	                                                        <?php endforeach; ?>

	                                                        <?php if ($moreTags > 0): ?>
	                                                            <span class="pill pill-more">+<?= (int)$moreTags ?></span>
	                                                        <?php endif; ?>
	                                                    </div>
	                                                <?php endif; ?>
	                                            </td>
	                                            <td><?= h($item['content_type'] ?? '-') ?></td>
	                                            <td style="color: var(--muted)"><?= h($item['contexts'] ?? '-') ?></td>
	                                            <td>
	                                                <?php if (!empty($item['canonical_url'])): ?>
	                                                    <a href="<?= h($item['canonical_url']) ?>" target="_blank" rel="noopener">Otwórz</a>
	                                                <?php else: ?>
	                                                    -
	                                                <?php endif; ?>
	                                            </td>
	                                            <td>
	                                                <form method="post" style="display:inline;">
	                                                    <input type="hidden" name="action" value="mark_inbox_processed">
	                                                    <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
	                                                    <input type="hidden" name="return_mode" value="<?= h($inboxMode) ?>">
	                                                    <input type="hidden" name="return_kind" value="<?= h($inboxKind) ?>">
	                                                    <input type="hidden" name="return_q" value="<?= h($query) ?>">
	                                                    <button type="submit" class="button success small">Oznacz jako przejrzane</button>
	                                                </form>
	                                            </td>
	                                        </tr>
	                                    <?php endforeach; ?>
	                                    </tbody>
	                                </table>
	                            </div>
	                        <?php endif; ?>
	                    </section>
	                <?php endif; ?>

	                <?php if ($view === 'insights'): ?>
	                    <?php
	                        $insTabs = [
	                            'notes' => 'Wnioski',
	                            'authors' => 'Autorzy',
	                            'topics' => 'Tematy',
	                            'velocity' => 'Tempo',
	                        ];
	                    ?>
	                    <section class="panel" aria-label="Insights">
	                        <div class="grid-x grid-padding-x align-middle">
	                            <div class="cell small-12 medium-8">
	                                <h5 style="margin-top:0; margin-bottom:0.4rem;">Insights / Trendy</h5>
	                                <div style="color: var(--muted);">
	                                    Dashboard decyzyjny: kto dostarcza Ci wiedzę, co czytasz i czy domykasz pętle poznawcze.
	                                </div>
	                            </div>
	                            <div class="cell small-12 medium-4">
	                                <div class="actions" style="justify-content:flex-end;">
	                                    <a class="button secondary" href="/?view=inbox">Inbox</a>
	                                    <a class="button secondary" href="/?view=search">Search</a>
	                                </div>
	                            </div>
	                        </div>
	                        <nav class="overview-tabs" aria-label="Zakładki Insights" style="margin-top:0.7rem;">
	                            <?php foreach ($insTabs as $k => $label): ?>
	                                <a
	                                    class="overview-tab <?= $insightsTab === $k ? 'is-active' : '' ?>"
	                                    href="/?<?= h(http_build_query(['view' => 'insights', 'tab' => $k], '', '&', PHP_QUERY_RFC3986)) ?>"
	                                ><?= h($label) ?></a>
	                            <?php endforeach; ?>
	                        </nav>
	                    </section>

	                    <?php if ($insightsTab === 'notes'): ?>
	                        <section class="panel" aria-label="Insights notes">
	                            <h5 style="margin-top:0; margin-bottom:0.4rem;">
	                                Twoje wnioski (ostatnie <?= (int)$insightsDays ?> dni)
	                            </h5>
	                            <div style="color: var(--muted);">
	                                Elementy, w których dodałeś notatkę merytoryczną (nie tylko znacznik „✓ przejrzane …”) w ostatnich <?= (int)$insightsDays ?> dniach.
	                            </div>

	                            <div style="color: var(--muted); margin: 0.6rem 0 0.7rem;">
	                                Wyników: <strong><?= count($insightsResults) ?></strong>
	                            </div>

	                            <div class="table-wrap">
	                                <table>
	                                    <thead>
	                                    <tr>
	                                        <th>Data opracowania</th>
	                                        <th>Autor</th>
	                                        <th>Treść</th>
	                                        <th>Notatka (preview)</th>
	                                        <th>Link</th>
	                                    </tr>
	                                    </thead>
	                                    <tbody>
	                                    <?php foreach ($insightsResults as $row): ?>
	                                        <?php
	                                            $rawTags = (string)($row['tags'] ?? '');
	                                            $allTags = [];
	                                            if ($rawTags !== '') {
	                                                foreach (explode(',', $rawTags) as $t) {
	                                                    $t = trim($t);
	                                                    if ($t !== '') {
	                                                        $allTags[] = $t;
	                                                    }
	                                                }
	                                            }
	                                            $shownTags = array_slice($allTags, 0, 3);
	                                            $moreTags = max(0, count($allTags) - count($shownTags));
	                                        ?>
	                                        <tr>
	                                            <td><?= h(format_dt($row['note_updated_at'] ?? null)) ?></td>
	                                            <td><?= h($row['author'] ?? '-') ?></td>
	                                            <td>
	                                                <a href="/?view=item&id=<?= (int)$row['id'] ?>"><?= h(short_text($row['content'] ?? '', 240)) ?></a>

	                                                <?php if ($shownTags): ?>
	                                                    <div class="meta-row">
	                                                        <span class="pill pill-note">notatka</span>
	                                                        <?php foreach ($shownTags as $t): ?>
	                                                            <a class="pill pill-tag" href="/?<?= h(http_build_query(['view' => 'search', 'tag' => $t], '', '&', PHP_QUERY_RFC3986)) ?>"><?= h($t) ?></a>
	                                                        <?php endforeach; ?>
	                                                        <?php if ($moreTags > 0): ?>
	                                                            <span class="pill pill-more">+<?= (int)$moreTags ?></span>
	                                                        <?php endif; ?>
	                                                    </div>
	                                                <?php else: ?>
	                                                    <div class="meta-row">
	                                                        <span class="pill pill-note">notatka</span>
	                                                    </div>
	                                                <?php endif; ?>
	                                            </td>
	                                            <td style="white-space: pre-wrap; line-height:1.35;">
	                                                <?= h(short_text((string)($row['notes'] ?? ''), 360)) ?>
	                                            </td>
	                                            <td>
	                                                <?php if (!empty($row['canonical_url'])): ?>
	                                                    <a href="<?= h($row['canonical_url']) ?>" target="_blank" rel="noopener">Otwórz</a>
	                                                <?php else: ?>
	                                                    -
	                                                <?php endif; ?>
	                                            </td>
	                                        </tr>
	                                    <?php endforeach; ?>
	                                    </tbody>
	                                </table>
	                            </div>

	                            <?php if (count($insightsResults) === 0): ?>
	                                <div class="callout warning" style="border-radius: 12px; margin-top: 0.9rem;">
	                                    Brak wniosków z ostatnich <?= (int)$insightsDays ?> dni. Dodaj merytoryczną notatkę w widoku itemu, a pojawi się tutaj.
	                                </div>
	                            <?php endif; ?>
	                        </section>
	                    <?php endif; ?>

	                    <?php if ($insightsTab === 'authors'): ?>
	                        <section class="panel" aria-label="Insights authors">
	                            <h5 style="margin-top:0; margin-bottom:0.4rem;">Top autorzy w archiwum</h5>
	                            <div style="color: var(--muted);">
	                                W archiwum = unikalne itemy. Saved = itemy z kontekstem <code>save</code>. Opracowane = merytoryczne notatki (nie tylko „✓ przejrzane …”).
	                            </div>
	                            <div style="color: var(--muted); margin: 0.6rem 0 0.7rem;">
	                                Wyników: <strong><?= count($insightsAuthors) ?></strong>
	                            </div>
	                            <div class="table-wrap">
	                                <table>
	                                    <thead>
	                                    <tr>
	                                        <th>Autor</th>
	                                        <th>W archiwum</th>
	                                        <th>Saved</th>
	                                        <th>Opracowane</th>
	                                    </tr>
	                                    </thead>
	                                    <tbody>
	                                    <?php foreach ($insightsAuthors as $r): ?>
	                                        <?php
	                                            $author = (string)($r['author'] ?? '');
	                                            $total = (int)($r['total_items'] ?? 0);
	                                            $saved = (int)($r['saved_items'] ?? 0);
	                                            $processed = (int)($r['processed_items'] ?? 0);
	                                            $linkBase = ['view' => 'search', 'author' => $author];
	                                        ?>
	                                        <tr>
	                                            <td>
	                                                <a href="/?<?= h(http_build_query($linkBase, '', '&', PHP_QUERY_RFC3986)) ?>"><?= h($author !== '' ? $author : '-') ?></a>
	                                            </td>
	                                            <td><?= $total ?></td>
	                                            <td>
	                                                <a href="/?<?= h(http_build_query($linkBase + ['kind' => 'save'], '', '&', PHP_QUERY_RFC3986)) ?>"><?= $saved ?></a>
	                                            </td>
	                                            <td>
	                                                <a href="/?<?= h(http_build_query($linkBase + ['only_notes' => '1'], '', '&', PHP_QUERY_RFC3986)) ?>"><?= $processed ?></a>
	                                            </td>
	                                        </tr>
	                                    <?php endforeach; ?>
	                                    </tbody>
	                                </table>
	                            </div>
	                        </section>
	                    <?php endif; ?>

	                    <?php if ($insightsTab === 'topics'): ?>
	                        <section class="panel" aria-label="Insights topics">
	                            <h5 style="margin-top:0; margin-bottom:0.4rem;">Tematy (MVP)</h5>
	                            <div style="color: var(--muted);">
	                                Prosta ekstrakcja fraz z treści + Twoich notatek (bez ML). Kliknij frazę, żeby przejść do Search.
	                            </div>

	                            <div class="grid-x grid-padding-x" style="margin-top:0.8rem;">
	                                <div class="cell small-12 medium-6">
	                                    <div class="callout" style="border-radius: 12px;">
	                                        <div class="kpi-title">Top frazy (7 dni)</div>
	                                        <div class="meta-row" style="margin-top:0.6rem;">
	                                            <?php if (!$insightsTopics7): ?>
	                                                <span style="color: var(--muted);">Brak danych.</span>
	                                            <?php else: ?>
	                                                <?php foreach ($insightsTopics7 as $t): ?>
	                                                    <?php $tok = (string)($t['token'] ?? ''); $cnt = (int)($t['count'] ?? 0); ?>
	                                                    <a class="pill" href="/?<?= h(http_build_query(['view' => 'search', 'q' => $tok], '', '&', PHP_QUERY_RFC3986)) ?>">
	                                                        <?= h($tok) ?>
	                                                        <span style="opacity:0.7; margin-left:0.35rem;"><?= $cnt ?></span>
	                                                    </a>
	                                                <?php endforeach; ?>
	                                            <?php endif; ?>
	                                        </div>
	                                    </div>
	                                </div>
	                                <div class="cell small-12 medium-6">
	                                    <div class="callout" style="border-radius: 12px;">
	                                        <div class="kpi-title">Top frazy (30 dni)</div>
	                                        <div class="meta-row" style="margin-top:0.6rem;">
	                                            <?php if (!$insightsTopics30): ?>
	                                                <span style="color: var(--muted);">Brak danych.</span>
	                                            <?php else: ?>
	                                                <?php foreach ($insightsTopics30 as $t): ?>
	                                                    <?php $tok = (string)($t['token'] ?? ''); $cnt = (int)($t['count'] ?? 0); ?>
	                                                    <a class="pill" href="/?<?= h(http_build_query(['view' => 'search', 'q' => $tok], '', '&', PHP_QUERY_RFC3986)) ?>">
	                                                        <?= h($tok) ?>
	                                                        <span style="opacity:0.7; margin-left:0.35rem;"><?= $cnt ?></span>
	                                                    </a>
	                                                <?php endforeach; ?>
	                                            <?php endif; ?>
	                                        </div>
	                                    </div>
	                                </div>
	                            </div>

	                            <div class="callout" style="border-radius: 12px; margin-top:0.8rem;">
	                                <div class="kpi-title">Top tagi (ostatnie 30 dni)</div>
	                                <div class="meta-row" style="margin-top:0.6rem;">
	                                    <?php if (!$insightsTopTags): ?>
	                                        <span style="color: var(--muted);">Brak tagów.</span>
	                                    <?php else: ?>
	                                        <?php foreach ($insightsTopTags as $r): ?>
	                                            <?php $tg = (string)($r['tag'] ?? ''); $cnt = (int)($r['items'] ?? 0); ?>
	                                            <a class="pill pill-tag" href="/?<?= h(http_build_query(['view' => 'search', 'tag' => $tg], '', '&', PHP_QUERY_RFC3986)) ?>">
	                                                <?= h($tg) ?>
	                                                <span style="opacity:0.75; margin-left:0.35rem;"><?= $cnt ?></span>
	                                            </a>
	                                        <?php endforeach; ?>
	                                    <?php endif; ?>
	                                </div>
	                            </div>
	                        </section>
	                    <?php endif; ?>

	                    <?php if ($insightsTab === 'velocity'): ?>
	                        <?php
	                            $totalNew = 0;
	                            $totalProcessed = 0;
	                            $totalReviewed = 0;
	                            $maxNew = 0;
	                            $maxProc = 0;
	                            foreach ($insightsVelocity as $d) {
	                                $n = (int)($d['new_items'] ?? 0);
	                                $p = (int)($d['processed'] ?? 0);
	                                $r = (int)($d['reviewed'] ?? 0);
	                                $totalNew += $n;
	                                $totalProcessed += $p;
	                                $totalReviewed += $r;
	                                $maxNew = max($maxNew, $n);
	                                $maxProc = max($maxProc, $p);
	                            }
	                            $debt = $totalNew - $totalProcessed;
	                        ?>
	                        <section class="panel" aria-label="Insights velocity">
	                            <h5 style="margin-top:0; margin-bottom:0.4rem;">Tempo: ingest vs opracowania</h5>
	                            <div style="color: var(--muted);">
	                                Nowe = itemy dodane do archiwum (first seen). Opracowane = merytoryczne notatki. Przejrzane = tylko „✓ przejrzane …”.
	                            </div>

	                            <div class="grid-x grid-padding-x" style="margin-top:0.8rem;">
	                                <div class="cell small-6 medium-3">
	                                    <section class="panel" style="margin-bottom:0;">
	                                        <div class="kpi-title">Nowe (<?= (int)$insightsVelocityDays ?> dni)</div>
	                                        <div class="kpi-value"><?= (int)$totalNew ?></div>
	                                    </section>
	                                </div>
	                                <div class="cell small-6 medium-3">
	                                    <section class="panel" style="margin-bottom:0;">
	                                        <div class="kpi-title">Opracowane</div>
	                                        <div class="kpi-value"><?= (int)$totalProcessed ?></div>
	                                    </section>
	                                </div>
	                                <div class="cell small-6 medium-3">
	                                    <section class="panel" style="margin-bottom:0;">
	                                        <div class="kpi-title">Przejrzane</div>
	                                        <div class="kpi-value"><?= (int)$totalReviewed ?></div>
	                                    </section>
	                                </div>
	                                <div class="cell small-6 medium-3">
	                                    <section class="panel" style="margin-bottom:0;">
	                                        <div class="kpi-title">Saldo wiedzy</div>
	                                        <div class="kpi-value"><?= (int)$debt ?></div>
	                                    </section>
	                                </div>
	                            </div>

	                            <div class="table-wrap" style="margin-top:0.9rem; max-height: 60vh; overflow:auto;">
	                                <table>
	                                    <thead>
	                                    <tr>
	                                        <th>Data</th>
	                                        <th>Nowe</th>
	                                        <th>Opracowane</th>
	                                        <th>Przejrzane</th>
	                                        <th>Debt</th>
	                                    </tr>
	                                    </thead>
	                                    <tbody>
	                                    <?php foreach ($insightsVelocity as $d): ?>
	                                        <tr>
	                                            <td><?= h((string)($d['date'] ?? '')) ?></td>
	                                            <td><?= (int)($d['new_items'] ?? 0) ?></td>
	                                            <td><?= (int)($d['processed'] ?? 0) ?></td>
	                                            <td><?= (int)($d['reviewed'] ?? 0) ?></td>
	                                            <td><?= (int)($d['debt'] ?? 0) ?></td>
	                                        </tr>
	                                    <?php endforeach; ?>
	                                    </tbody>
	                                </table>
	                            </div>
	                        </section>
		                    <?php endif; ?>
		                <?php endif; ?>

		                <?php if ($view === 'topic'): ?>
		                    <section class="panel" aria-label="Topic">
		                        <div class="grid-x grid-padding-x align-middle">
		                            <div class="cell small-12 medium-8">
		                                <h5 style="margin-top:0; margin-bottom:0.4rem;">
		                                    Temat: <?= h($topicTag !== '' ? $topicTag : '—') ?>
		                                </h5>
		                                <div style="color: var(--muted);">
		                                    Widok kolekcji tematycznej: tylko elementy <strong>opracowane merytorycznie</strong> (notatka != „✓ przejrzane …”), posortowane po dacie notatki.
		                                </div>
		                            </div>
		                            <div class="cell small-12 medium-4">
		                                <div class="actions" style="justify-content:flex-end;">
		                                    <?php if ($topicTag !== ''): ?>
		                                        <a class="button secondary" href="/?<?= h(http_build_query(['view' => 'library', 'lib_status' => 'processed', 'lib_tag' => $topicTag], '', '&', PHP_QUERY_RFC3986)) ?>">Biblioteka (processed)</a>
		                                        <a class="button secondary" href="/?<?= h(http_build_query(['view' => 'search', 'tag' => $topicTag], '', '&', PHP_QUERY_RFC3986)) ?>">Search (tag)</a>
		                                    <?php endif; ?>
		                                    <a class="button secondary" href="/?view=insights&tab=topics">Insights</a>
		                                </div>
		                            </div>
		                        </div>

			                        <?php if ($topicTag === ''): ?>
			                            <div class="callout warning" style="border-radius: 12px; margin-top:0.9rem;">
			                                Podaj tag w URL, np. <code>/?view=topic&amp;tag=ksef</code>.
			                            </div>
			                        <?php else: ?>
			                            <?php
			                                $ts = is_array($topicStats) ? $topicStats : [];
			                                $tTotal = (int)($ts['total_processed'] ?? count($topicResults));
			                                $tLast = $ts['last_note_at'] ?? null;
			                                $t7 = (int)($ts['processed_7d'] ?? 0);
			                                $t30 = (int)($ts['processed_30d'] ?? 0);
			                                $shown = count($topicResults);
			                            ?>

			                            <div class="kpi-grid" style="margin-top:0.9rem;">
			                                <section class="panel" style="padding:0.85rem;" aria-label="Opracowane (łącznie)">
			                                    <div class="kpi-title">Opracowane (łącznie)</div>
			                                    <div class="kpi-value"><?= $tTotal ?></div>
			                                </section>
			                                <section class="panel" style="padding:0.85rem;" aria-label="Ostatnia notatka">
			                                    <div class="kpi-title">Ostatnia notatka</div>
			                                    <div class="kpi-value" style="font-size:1rem; font-weight:700;"><?= h(format_dt(is_string($tLast) ? $tLast : null)) ?></div>
			                                </section>
			                                <section class="panel" style="padding:0.85rem;" aria-label="Opracowane (7 dni)">
			                                    <div class="kpi-title">Opracowane (7 dni)</div>
			                                    <div class="kpi-value"><?= $t7 ?></div>
			                                </section>
			                                <section class="panel" style="padding:0.85rem;" aria-label="Opracowane (30 dni)">
			                                    <div class="kpi-title">Opracowane (30 dni)</div>
			                                    <div class="kpi-value"><?= $t30 ?></div>
			                                </section>
			                            </div>

			                            <div style="color: var(--muted); margin: 0.6rem 0 0.7rem;">
			                                Wyświetlono: <strong><?= $shown ?></strong>
			                                <?php if ($tTotal > $shown): ?>
			                                    (limit listy: <?= $shown ?> / <?= $tTotal ?>)
			                                <?php endif; ?>
			                            </div>

			                            <div class="table-wrap">
			                                <table>
		                                    <thead>
		                                    <tr>
		                                        <th>Data opracowania</th>
		                                        <th>Autor</th>
		                                        <th>Treść</th>
		                                        <th>Notatka (preview)</th>
		                                        <th>Link</th>
		                                    </tr>
		                                    </thead>
		                                    <tbody>
		                                    <?php foreach ($topicResults as $row): ?>
		                                        <?php
		                                            $rawTags = (string)($row['tags'] ?? '');
		                                            $allTags = [];
		                                            if ($rawTags !== '') {
		                                                foreach (explode(',', $rawTags) as $t) {
		                                                    $t = trim($t);
		                                                    if ($t !== '') {
		                                                        $allTags[] = $t;
		                                                    }
		                                                }
		                                            }
		                                            $shownTags = array_slice($allTags, 0, 3);
		                                            $moreTags = max(0, count($allTags) - count($shownTags));
		                                        ?>
		                                        <tr>
		                                            <td><?= h(format_dt($row['note_updated_at'] ?? null)) ?></td>
		                                            <td><?= h($row['author'] ?? '-') ?></td>
		                                            <td>
		                                                <a href="/?view=item&id=<?= (int)$row['id'] ?>"><?= h(short_text($row['content'] ?? '', 240)) ?></a>
		                                                <div class="meta-row">
		                                                    <span class="pill pill-note">notatka</span>
		                                                    <?php foreach ($shownTags as $t): ?>
		                                                        <a class="pill pill-tag" href="/?<?= h(http_build_query(['view' => 'topic', 'tag' => $t], '', '&', PHP_QUERY_RFC3986)) ?>"><?= h($t) ?></a>
		                                                    <?php endforeach; ?>
		                                                    <?php if ($moreTags > 0): ?>
		                                                        <span class="pill pill-more">+<?= (int)$moreTags ?></span>
		                                                    <?php endif; ?>
		                                                </div>
		                                            </td>
		                                            <td style="white-space: pre-wrap; line-height:1.35;">
		                                                <?= h(short_text((string)($row['notes'] ?? ''), 360)) ?>
		                                            </td>
		                                            <td>
		                                                <?php if (!empty($row['canonical_url'])): ?>
		                                                    <a href="<?= h($row['canonical_url']) ?>" target="_blank" rel="noopener">Otwórz</a>
		                                                <?php else: ?>
		                                                    -
		                                                <?php endif; ?>
		                                            </td>
		                                        </tr>
		                                    <?php endforeach; ?>
		                                    </tbody>
		                                </table>
		                            </div>

		                            <?php if (count($topicResults) === 0): ?>
		                                <div class="callout warning" style="border-radius: 12px; margin-top: 0.9rem;">
		                                    Brak opracowanych wpisów dla tego tagu. Dodaj merytoryczne notatki w widoku itemu i przypisz tag.
		                                </div>
		                            <?php endif; ?>
		                        <?php endif; ?>
		                    </section>
		                <?php endif; ?>

	                <?php if ($view === 'search'): ?>
		                    <section class="panel" aria-label="Wyszukiwarka">
		                        <form method="get">
		                            <input type="hidden" name="view" value="search">
                            <div class="search-grid">
                                <label>Fraza
                                    <input type="text" name="q" value="<?= h($query) ?>" placeholder="np. sd-wan, observability">
                                </label>
                                <label>Autor
                                    <input type="text" name="author" value="<?= h($searchAuthor) ?>" placeholder="dokładnie (jak w archiwum)">
                                </label>
                                <label>Źródło (source)
                                    <select name="source">
                                        <option value="">Wszystkie</option>
                                        <option value="activity_all" <?= $searchSource === 'activity_all' ? 'selected' : '' ?>>activity_all</option>
                                        <option value="activity_reactions" <?= $searchSource === 'activity_reactions' ? 'selected' : '' ?>>activity_reactions</option>
                                        <option value="activity_comments" <?= $searchSource === 'activity_comments' ? 'selected' : '' ?>>activity_comments</option>
                                        <option value="saved_posts" <?= $searchSource === 'saved_posts' ? 'selected' : '' ?>>saved_posts</option>
                                        <option value="saved_articles" <?= $searchSource === 'saved_articles' ? 'selected' : '' ?>>saved_articles</option>
                                    </select>
                                </label>
                                <label>Akcja (activity_kind)
                                    <select name="kind">
                                        <option value="">Wszystkie</option>
                                        <option value="post" <?= $searchKind === 'post' ? 'selected' : '' ?>>post</option>
                                        <option value="share" <?= $searchKind === 'share' ? 'selected' : '' ?>>share</option>
                                        <option value="reaction" <?= $searchKind === 'reaction' ? 'selected' : '' ?>>reaction</option>
                                        <option value="comment" <?= $searchKind === 'comment' ? 'selected' : '' ?>>comment</option>
                                        <option value="save" <?= $searchKind === 'save' ? 'selected' : '' ?>>save</option>
                                    </select>
                                </label>
                                <label>Typ treści (content_type)
                                    <select name="type">
                                        <option value="">Wszystkie</option>
                                        <option value="text" <?= $searchType === 'text' ? 'selected' : '' ?>>text</option>
                                        <option value="article" <?= $searchType === 'article' ? 'selected' : '' ?>>article</option>
                                        <option value="video" <?= $searchType === 'video' ? 'selected' : '' ?>>video</option>
                                        <option value="image" <?= $searchType === 'image' ? 'selected' : '' ?>>image</option>
                                        <option value="document" <?= $searchType === 'document' ? 'selected' : '' ?>>document</option>
                                        <option value="unknown" <?= $searchType === 'unknown' ? 'selected' : '' ?>>unknown</option>
                                    </select>
                                </label>
                                <label>Od daty (aktywności)
                                    <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
                                </label>
                                <label>Do daty (aktywności)
                                    <input type="date" name="date_to" value="<?= h($dateTo) ?>">
                                </label>
                                <label>Tag
                                    <select name="tag">
                                        <option value="">Dowolny</option>
                                        <?php foreach ($tags as $tagName): ?>
                                            <option value="<?= h($tagName) ?>" <?= $tag === $tagName ? 'selected' : '' ?>><?= h($tagName) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                            <div style="margin-top: 0.8rem;" class="actions">
                                <button type="submit" class="button primary">Szukaj</button>
                                <a class="button secondary" href="/?view=search">Wyczyść</a>
                            </div>

	                            <div style="margin-top: 0.6rem;">
	                                <div style="display:flex; gap:1.2rem; flex-wrap:wrap; align-items:center;">
	                                    <label style="display:flex; gap:0.5rem; align-items:center; margin:0;">
	                                        <input type="checkbox" name="notes" value="1" <?= $searchNotes ? 'checked' : '' ?>>
	                                        <span>Szukaj także w moich notatkach</span>
	                                    </label>
	                                    <label style="display:flex; gap:0.5rem; align-items:center; margin:0;">
	                                        <input type="checkbox" name="only_notes" value="1" <?= $searchOnlyNotes ? 'checked' : '' ?>>
	                                        <span>Tylko wpisy z notatkami</span>
	                                    </label>
	                                    <label style="display:flex; gap:0.5rem; align-items:center; margin:0;">
	                                        <input type="checkbox" name="only_without_notes" value="1" <?= $searchOnlyWithoutNotes ? 'checked' : '' ?>>
	                                        <span>Tylko bez notatek (Inbox)</span>
	                                    </label>
	                                </div>
	                            </div>

                            <div style="margin-top: 0.6rem;" class="actions">
	                                <?php
	                                    $searchPresetBase = [
	                                        'view' => 'search',
	                                        'q' => $query,
	                                        'author' => $searchAuthor,
	                                        'notes' => $searchNotes ? '1' : '',
	                                        'only_notes' => $searchOnlyNotes ? '1' : '',
	                                        'only_without_notes' => $searchOnlyWithoutNotes ? '1' : '',
	                                        'date_from' => $dateFrom,
	                                        'date_to' => $dateTo,
	                                        'tag' => $tag,
	                                    ];
	                                ?>
	                                <a class="button secondary" href="/?<?= h(http_build_query(array_merge($searchPresetBase, ['only_without_notes' => '1', 'only_notes' => '']), '', '&', PHP_QUERY_RFC3986)) ?>">Inbox (do opracowania)</a>
	                                <a class="button secondary" href="/?<?= h(http_build_query(array_merge($searchPresetBase, ['only_without_notes' => '1', 'only_notes' => '', 'kind' => 'save']), '', '&', PHP_QUERY_RFC3986)) ?>">Inbox + Saved</a>
		                                <a class="button secondary" href="/?<?= h(http_build_query(array_merge($searchPresetBase, ['only_notes' => '1', 'only_without_notes' => '']), '', '&', PHP_QUERY_RFC3986)) ?>">Tylko z notatkami</a>
		                                <a class="button secondary" href="/?<?= h(http_build_query(array_merge($searchPresetBase, ['kind' => 'save']), '', '&', PHP_QUERY_RFC3986)) ?>">Tylko zapisane</a>
		                                <a class="button secondary" href="/?<?= h(http_build_query(array_merge($searchPresetBase, ['source' => 'saved_posts', 'kind' => 'save']), '', '&', PHP_QUERY_RFC3986)) ?>">Saved posts</a>
		                                <a class="button secondary" href="/?<?= h(http_build_query(array_merge($searchPresetBase, ['source' => 'saved_articles', 'kind' => 'save']), '', '&', PHP_QUERY_RFC3986)) ?>">Saved articles</a>
		                                <?php if (trim((string)$tag) !== ''): ?>
		                                    <a class="button hollow" href="/?<?= h(http_build_query(['view' => 'topic', 'tag' => $tag], '', '&', PHP_QUERY_RFC3986)) ?>">Opracowane w tym temacie</a>
		                                <?php endif; ?>
		                            </div>

	                            <div style="margin-top: 0.4rem; color: var(--muted);">
	                                Inbox = elementy z Archiwum bez notatki (do przetworzenia).
	                            </div>

                            <div style="margin-top: 0.4rem; color: var(--muted);">
                                Daty dotyczą momentu aktywności/zapisania w archiwum (kontekstów), a nie daty publikacji na LinkedIn.
                            </div>
                        </form>
                    </section>

                    <section class="panel" aria-label="Wyniki wyszukiwania">
                        <h5 style="margin-top:0;">Wyniki: <?= count($searchResults) ?></h5>
                        <div class="table-wrap">
                            <table>
                                <thead>
	                                <tr>
	                                    <th>Ostatnio</th>
	                                    <th>Autor</th>
	                                    <th>Treść</th>
	                                    <th>Typ</th>
	                                    <th>Konteksty</th>
	                                    <th>Link</th>
	                                </tr>
                                </thead>
                                <tbody>
	                                <?php foreach ($searchResults as $item): ?>
	                                    <tr>
	                                        <td><?= h(format_dt($item['last_context_at'] ?? null)) ?></td>
	                                        <td><?= h($item['author'] ?? '-') ?></td>
	                                        <td>
	                                            <a href="/?view=item&id=<?= (int)$item['id'] ?>"><?= h(short_text($item['content'] ?? '', 260)) ?></a>

                                                <?php
                                                    $rawTags = (string)($item['tags'] ?? '');
                                                    $notes = (string)($item['user_notes'] ?? '');
                                                    $isReviewed = is_processed_only($notes);
                                                    $hasNote = is_processed_with_content($notes);
                                                    $allTags = [];
                                                    if ($rawTags !== '') {
                                                        foreach (explode(',', $rawTags) as $t) {
                                                            $t = trim($t);
                                                            if ($t !== '') {
                                                                $allTags[] = $t;
                                                            }
                                                        }
                                                    }
	                                                    $shownTags = array_slice($allTags, 0, 3);
	                                                    $moreTags = max(0, count($allTags) - count($shownTags));
	                                                ?>

		                                                <div class="meta-row">
		                                                    <?php if ($isReviewed): ?>
		                                                        <span class="pill pill-reviewed">przejrzane</span>
		                                                    <?php elseif ($hasNote): ?>
		                                                        <span class="pill pill-note">notatka</span>
		                                                    <?php else: ?>
		                                                        <span class="pill pill-inbox">inbox</span>
		                                                    <?php endif; ?>

		                                                    <?php foreach ($shownTags as $t): ?>
		                                                        <a class="pill pill-tag" href="/?<?= h(http_build_query(['view' => 'search', 'tag' => $t], '', '&', PHP_QUERY_RFC3986)) ?>"><?= h($t) ?></a>
		                                                    <?php endforeach; ?>

		                                                    <?php if ($moreTags > 0): ?>
		                                                        <span class="pill pill-more">+<?= (int)$moreTags ?></span>
		                                                    <?php endif; ?>
		                                                </div>
		                                        </td>
	                                        <td><?= h($item['content_type'] ?? '-') ?></td>
	                                        <td style="color: var(--muted)"><?= h($item['contexts'] ?? '-') ?></td>
	                                        <td>
	                                            <?php if (!empty($item['canonical_url'])): ?>
	                                                <a href="<?= h($item['canonical_url']) ?>" target="_blank" rel="noopener">Otwórz</a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
	                    </section>
		                <?php endif; ?>

		                <?php if ($view === 'item'): ?>
		                    <section class="panel" aria-label="Item details">
		                        <div class="grid-x grid-margin-x align-middle">
		                            <div class="cell small-12 medium-8">
		                                <h5 style="margin-top:0; margin-bottom:0.4rem;">Item (canonical)</h5>
		                                <div style="color: var(--muted);">
		                                    ID: <strong><?= (int)$itemId ?></strong>
		                                    <?php if ($item): ?>
		                                        | <?= h(format_dt($item['collected_last_at'] ?? null)) ?>
		                                    <?php endif; ?>
		                                </div>
		                            </div>
			                            <div class="cell small-12 medium-4">
			                                <div class="actions" style="justify-content:flex-end;">
			                                    <a class="button secondary" href="javascript:history.back()">Wróć</a>
			                                    <a class="button secondary" href="/?view=library">Archiwum</a>
			                                    <?php
			                                        $notesForEditorial = (string)($itemUserData['notes'] ?? '');
			                                        $canEditorialAdd = is_processed_with_content($notesForEditorial);
			                                    ?>
			                                    <?php if (is_array($itemEditorial)): ?>
			                                        <?php
			                                            $edSt = (string)($itemEditorial['editorial_status'] ?? '-');
			                                            $edDraftId = (int)($itemEditorial['draft_id'] ?? 0);
			                                        ?>
			                                        <?php if ($edDraftId > 0): ?>
			                                            <a class="button warning" href="/?<?= h(http_build_query(['view' => 'editorial', 'tab' => 'draft', 'draft_id' => $edDraftId], '', '&', PHP_QUERY_RFC3986)) ?>">
			                                                Szkic (<?= h($edSt) ?>)
			                                            </a>
			                                        <?php else: ?>
			                                            <a class="button secondary" href="/?<?= h(http_build_query(['view' => 'editorial', 'tab' => 'inbox'], '', '&', PHP_QUERY_RFC3986)) ?>">
			                                                W Redakcji: <?= h($edSt) ?>
			                                            </a>
			                                        <?php endif; ?>
			                                    <?php else: ?>
			                                        <form method="post" style="display:inline;">
			                                            <input type="hidden" name="action" value="editorial_add_source">
			                                            <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
			                                            <input type="hidden" name="portal_topic" value="other">
			                                            <input type="hidden" name="priority" value="3">
			                                            <button
			                                                type="submit"
			                                                class="button success"
			                                                <?= $canEditorialAdd ? '' : 'disabled' ?>
			                                                title="<?= $canEditorialAdd ? 'Dodaj do kolejki redakcyjnej.' : 'Wymagana notatka merytoryczna (opracowane). Najpierw dodaj notatkę własnymi słowami.' ?>"
			                                            >Dodaj do Redakcji</button>
			                                        </form>
			                                    <?php endif; ?>
			                                </div>
			                            </div>
			                        </div>

		                        <?php if (!$item): ?>
		                            <div class="callout alert" style="margin-top:0.8rem;">Nie znaleziono itemu.</div>
		                        <?php else: ?>
		                            <div class="grid-x grid-margin-x" style="margin-top:0.8rem;">
		                                <div class="cell small-12 medium-4">
		                                    <div class="callout" style="border-radius: 12px;">
		                                        <div><strong>Autor:</strong> <?= h($item['author'] ?? '-') ?></div>
		                                        <div><strong>Content type:</strong> <?= h($item['content_type'] ?? '-') ?></div>
		                                        <div><strong>URN:</strong> <code><?= h($item['item_urn'] ?? '-') ?></code></div>
		                                        <div><strong>Published:</strong> <?= h($item['published_label'] ?? '-') ?></div>
		                                        <div><strong>First seen:</strong> <?= h(format_dt($item['collected_first_at'] ?? null)) ?></div>
		                                        <div><strong>Last seen:</strong> <?= h(format_dt($item['collected_last_at'] ?? null)) ?></div>
		                                        <div style="margin-top:0.6rem;">
		                                            <strong>Link:</strong>
		                                            <a href="<?= h($item['canonical_url']) ?>" target="_blank" rel="noopener">Otwórz na LinkedIn</a>
		                                        </div>
		                                    </div>

		                                    <div class="callout" style="border-radius: 12px; margin-top: 0.9rem;">
		                                        <div class="kpi-title" style="margin-bottom:0.4rem;">Konteksty</div>
		                                        <div class="table-wrap">
		                                            <table>
		                                                <thead>
		                                                <tr>
		                                                    <th>Kiedy</th>
		                                                    <th>Source</th>
		                                                    <th>Kind</th>
		                                                </tr>
		                                                </thead>
		                                                <tbody>
		                                                <?php foreach ($itemContexts as $ctx): ?>
		                                                    <tr>
		                                                        <td><?= h(format_dt($ctx['collected_at'] ?? null)) ?></td>
		                                                        <td><?= h($ctx['source'] ?? '-') ?></td>
		                                                        <td><?= h($ctx['activity_kind'] ?? '-') ?></td>
		                                                    </tr>
		                                                <?php endforeach; ?>
		                                                </tbody>
		                                            </table>
		                                        </div>
			                                        <?php if (count($itemContexts) === 0): ?>
			                                            <div style="color: var(--muted);">Brak kontekstów.</div>
			                                        <?php endif; ?>
			                                    </div>

			                                    <div class="callout" style="border-radius: 12px; margin-top: 0.9rem;">
			                                        <div class="kpi-title" style="margin-bottom:0.4rem;">Tagi</div>
			                                        <?php if (is_array($itemTags) && count($itemTags) > 0): ?>
			                                            <div style="margin-bottom:0.55rem; display:flex; flex-wrap:wrap; gap:0.35rem;">
			                                                <?php foreach ($itemTags as $t): ?>
			                                                    <form method="post" style="display:inline;">
			                                                        <input type="hidden" name="action" value="remove_item_tag">
			                                                        <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
			                                                        <input type="hidden" name="tag_name" value="<?= h((string)$t) ?>">
			                                                        <button type="submit" class="button tiny hollow" style="padding:0.25rem 0.45rem; margin:0;">
			                                                            <?= h((string)$t) ?> ×
			                                                        </button>
			                                                    </form>
			                                                <?php endforeach; ?>
			                                            </div>
			                                        <?php else: ?>
			                                            <div style="color: var(--muted); margin-bottom:0.55rem;">Brak tagów.</div>
			                                        <?php endif; ?>

			                                        <form method="post" class="grid-x grid-margin-x align-middle">
			                                            <input type="hidden" name="action" value="add_item_tag">
			                                            <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
			                                            <div class="cell auto">
			                                                <input type="text" name="tag_name" placeholder="Dodaj tag (np. ksef)" maxlength="64">
			                                            </div>
			                                            <div class="cell shrink">
			                                                <button type="submit" class="button small">Dodaj</button>
			                                            </div>
			                                        </form>
			                                    </div>
			                                </div>
			                                <div class="cell small-12 medium-8">
			                                    <div class="callout" style="border-radius: 12px;">
			                                        <div class="kpi-title" style="margin-bottom:0.4rem;">Moja notatka</div>
			                                        <?php
			                                            $notesRaw = (string)($itemUserData['notes'] ?? '');
			                                            $statusReviewed = is_processed_only($notesRaw);
			                                            $statusHasNote = is_processed_with_content($notesRaw);
			                                            $statusText = $statusReviewed
			                                                ? 'przejrzane'
			                                                : ($statusHasNote ? 'opracowane merytorycznie' : 'Inbox (bez notatki)');
			                                            $statusClass = $statusReviewed
			                                                ? 'pill-reviewed'
			                                                : ($statusHasNote ? 'pill-note' : 'pill-more');
			                                        ?>
			                                        <div style="margin-bottom:0.6rem; color: var(--muted);">
			                                            Status: <span class="pill <?= h($statusClass) ?>"><?= h($statusText) ?></span>
			                                        </div>
			                                        <form method="post">
			                                            <input type="hidden" name="action" value="save_item_note">
			                                            <input type="hidden" name="item_id" value="<?= (int)$itemId ?>">
			                                            <textarea name="notes" rows="6" placeholder="Dlaczego to jest ważne? Co z tym zrobić?"><?= h((string)($itemUserData['notes'] ?? '')) ?></textarea>
			                                            <div class="actions" style="margin-top:0.6rem;">
			                                                <button type="submit" class="button primary">Zapisz</button>
			                                                <span style="color: var(--muted); font-size:0.9rem;">
			                                                    (puste zapisze jako brak notatki)
			                                                </span>
			                                            </div>
			                                        </form>
			                                    </div>

			                                    <div class="callout" style="border-radius: 12px;">
			                                        <div class="kpi-title" style="margin-bottom:0.4rem;">Treść</div>
			                                        <div style="white-space: pre-wrap; line-height:1.45;">
			                                            <?= nl2br(h((string)($item['content'] ?? ''))) ?>
			                                        </div>
		                                    </div>
		                                </div>
		                            </div>
		                        <?php endif; ?>
		                    </section>
		                <?php endif; ?>

		                <?php if ($view === 'post'): ?>
		                    <section class="panel" aria-label="Szczegóły wpisu">
		                        <div class="grid-x grid-margin-x align-middle">
	                            <div class="cell small-12 medium-8">
	                                <h5 style="margin-top:0; margin-bottom:0.4rem;">Szczegóły wpisu</h5>
	                                <div style="color: var(--muted);">
	                                    ID: <strong><?= (int)$postId ?></strong>
	                                    <?php if ($post): ?>
	                                        | <?= h(format_dt($post['collected_at'] ?? null)) ?>
	                                    <?php endif; ?>
	                                </div>
	                            </div>
	                            <div class="cell small-12 medium-4">
	                                <div class="actions" style="justify-content:flex-end;">
	                                    <a class="button secondary" href="javascript:history.back()">Wróć</a>
	                                    <a class="button secondary" href="/?view=feed">Feed</a>
	                                </div>
	                            </div>
	                        </div>

	                        <?php if (!$post): ?>
	                            <div class="callout alert" style="margin-top:0.8rem;">Nie znaleziono wpisu.</div>
	                        <?php else: ?>
	                            <div class="grid-x grid-margin-x" style="margin-top:0.8rem;">
	                                <div class="cell small-12 medium-4">
	                                    <div class="callout" style="border-radius: 12px;">
	                                        <div><strong>Autor:</strong> <?= h($post['author'] ?? '-') ?></div>
	                                        <div><strong>Typ:</strong> <?= h($post['activity_type'] ?? '-') ?></div>
	                                        <div><strong>Źródło:</strong> <?= h($post['source_page'] ?? '-') ?></div>
	                                        <div><strong>Label:</strong> <?= h($post['activity_label'] ?? '-') ?></div>
	                                        <div><strong>URN:</strong> <code><?= h($post['activity_urn'] ?? '-') ?></code></div>
	                                        <div><strong>Published:</strong> <?= h($post['published_label'] ?? '-') ?></div>
	                                        <div style="margin-top:0.6rem;">
	                                            <strong>Link:</strong>
	                                            <?php if (!empty($post['post_url'])): ?>
	                                                <a href="<?= h($post['post_url']) ?>" target="_blank" rel="noopener">Otwórz na LinkedIn</a>
	                                            <?php else: ?>
	                                                -
	                                            <?php endif; ?>
	                                        </div>
	                                    </div>
	                                </div>
	                                <div class="cell small-12 medium-8">
	                                    <div class="callout" style="border-radius: 12px;">
	                                        <div class="kpi-title" style="margin-bottom:0.4rem;">Treść</div>
	                                        <div style="white-space: pre-wrap; line-height:1.45;">
	                                            <?= nl2br(h((string)($post['content'] ?? ''))) ?>
	                                        </div>
	                                    </div>
	                                </div>
	                            </div>
	                        <?php endif; ?>
	                    </section>
	                <?php endif; ?>

		                <?php if ($view === 'login'): ?>
		                    <section class="panel" aria-label="LinkedIn login">
		                        <h5 style="margin-top:0;">Login (LinkedIn)</h5>
		                        <p style="color: var(--muted);">
	                            To okno służy tylko do ręcznego zalogowania sesji scrapera. Hasło nie jest zapisywane.
	                        </p>

                        <div class="grid-x grid-margin-x align-middle">
                            <div class="cell small-12 medium-6">
                                <p style="margin-bottom: 0.3rem;">
                                    Status: <strong id="li-state"><?= h($authState) ?></strong>
	                                </p>
	                                <p style="margin-bottom: 0.8rem; color: var(--muted);" id="li-hint">
	                                    <?php if ($authState === 'AUTH_OK' && $hasLoginSession): ?>
	                                        Zalogowano. Zamknij okno logowania (żeby zwolnić profil), a potem uruchom scraping w Overview.
	                                    <?php elseif ($authState === 'AUTH_OK'): ?>
	                                        Sesja aktywna. Możesz wrócić do Overview i uruchomić scraping.
	                                    <?php elseif ($authState === 'LOGIN_RUNNING'): ?>
	                                        Okno logowania działa. Zaloguj się w podglądzie poniżej.
	                                    <?php else: ?>
	                                        Sesja nieaktywna. Uruchom okno logowania.
                                    <?php endif; ?>
                                </p>
	                            </div>
	                            <div class="cell small-12 medium-6">
	                                <div class="actions" style="justify-content: flex-end;">
	                                    <?php if (!$hasLoginSession && !$isRunning): ?>
	                                        <form method="post" style="display:inline;">
	                                            <input type="hidden" name="action" value="start_login">
	                                            <button type="submit" class="button primary">Uruchom okno logowania</button>
	                                        </form>
	                                    <?php endif; ?>

	                                    <?php if ($hasLoginSession): ?>
	                                        <?php if ($effectiveNovncUrl !== ''): ?>
	                                            <a class="button secondary" href="<?= h($effectiveNovncUrl) ?>" target="_blank" rel="noopener">Otwórz noVNC</a>
	                                        <?php endif; ?>
	                                        <form method="post" style="display:inline;">
	                                            <input type="hidden" name="action" value="stop_login">
	                                            <input type="hidden" name="session_id" value="<?= h($effectiveSessionId) ?>">
	                                            <button type="submit" class="button hollow">Zamknij okno</button>
	                                        </form>
                                    <?php endif; ?>
                                </div>
	                            </div>
	                        </div>

	                        <?php if ($hasLoginSession && $effectiveNovncUrl !== '' && $authState !== 'AUTH_OK'): ?>
	                            <div style="margin-top: 0.5rem;">
	                                <div style="color: var(--muted); font-size: 0.85rem; margin-bottom: 0.4rem;">
	                                    Session ID: <code><?= h($effectiveSessionId) ?></code>
	                                </div>
	                                <iframe
                                    title="noVNC LinkedIn Login"
                                    src="<?= h($effectiveNovncUrl) ?>"
                                    style="width:100%; height: 740px; border: 1px solid var(--line); border-radius: 12px; background: #0b1020;"
                                    allow="clipboard-read; clipboard-write"
                                ></iframe>
                            </div>
                        <?php endif; ?>
                    </section>

                    <script>
                        (function () {
                            const stateEl = document.getElementById('li-state');
                            const hintEl = document.getElementById('li-hint');
                            let last = stateEl ? stateEl.textContent : '';

                            async function poll() {
                                try {
	                                    const res = await fetch('/?view=login&ajax=auth', {cache: 'no-store'});
	                                    if (!res.ok) return;
	                                    const data = await res.json();
	                                    const state = (data && data.state) ? String(data.state) : 'AUTH_UNKNOWN';
	                                    const hasSession = Boolean(data && data.login_session_id);
	                                    if (stateEl) stateEl.textContent = state;

	                                    if (hintEl) {
	                                        if (state === 'AUTH_OK') {
	                                            hintEl.textContent = hasSession
	                                                ? 'Zalogowano. Zamknij okno logowania (żeby zwolnić profil), a potem uruchom scraping w Overview.'
	                                                : 'Sesja aktywna. Możesz wrócić do Overview i uruchomić scraping.';
	                                        } else if (state === 'LOGIN_RUNNING') {
	                                            hintEl.textContent = 'Okno logowania działa. Zaloguj się w podglądzie poniżej.';
	                                        } else {
	                                            hintEl.textContent = 'Sesja nieaktywna. Uruchom okno logowania.';
	                                        }
                                    }

                                    if (state !== last) {
                                        last = state;
                                        if (state === 'AUTH_OK') {
                                            // Reload to remove iframe and show success state.
                                            setTimeout(() => window.location.reload(), 800);
                                        }
                                    }
                                } catch (e) {
                                    // ignore
                                }
                            }

                            setInterval(poll, 2500);
                            poll();
                        })();
                    </script>
                <?php endif; ?>

                <?php if ($view === 'chatgpt'): ?>
                    <?php
                        $cgptLatest = is_array($chatgptGatewayState['latest_session'] ?? null)
                            ? $chatgptGatewayState['latest_session']
                            : null;
                        $cgptEvents = is_array($chatgptGatewayState['recent_events'] ?? null)
                            ? $chatgptGatewayState['recent_events']
                            : [];
                        $chatgptContractOk = !empty($chatgptSchema['ok']);
                        $chatgptSchemaVersion = $chatgptContractOk ? (string)($chatgptSchema['version'] ?? '1.0') : 'n/a';
                        $chatgptSelectedModelName = 'ChatGPT 5.2';
                        foreach ($chatgptModels as $m) {
                            if ((string)$m['id'] === $chatgptAssistantId) {
                                $chatgptSelectedModelName = (string)$m['name'];
                                break;
                            }
                        }
                        $chatgptSelectedProjectName = '';
                        foreach ($chatgptProjects as $p) {
                            if ((string)$p['id'] === $chatgptProjectId) {
                                $chatgptSelectedProjectName = (string)$p['name'];
                                break;
                            }
                        }
                        $chatgptSelectedThreadName = '';
                        foreach ($chatgptThreads as $th) {
                            if ((string)$th['id'] === $chatgptThreadId) {
                                $chatgptSelectedThreadName = (string)$th['name'];
                                break;
                            }
                        }
                    ?>
                    <div class="chatgpt-shell" id="chatgpt-shell">
                        <aside class="chatgpt-rail" aria-label="ChatGPT Sidebar">
                            <div class="chatgpt-rail__inner">
                                <div class="chatgpt-brand">
                                    <span class="chatgpt-brand__icon">ON</span>
                                    <span>ChatGPT Local</span>
                                </div>

                                <div class="chatgpt-quick">
                                    <a
                                        id="chatgpt-new-chat-trigger"
                                        class="primary"
                                        href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'new_chat' => '1'], '', '&', PHP_QUERY_RFC3986)) ?>"
                                    >+ Nowy czat</a>
                                    <button type="button" id="chatgpt-more-open">Więcej</button>
                                </div>

                                <section class="chatgpt-group">
                                    <button type="button" class="chatgpt-group__toggle" data-collapse-target="cgpt-models" aria-expanded="true">
                                        <span>Modele GPT</span><span class="chatgpt-group__chevron">▸</span>
                                    </button>
                                    <div class="chatgpt-group__body" id="cgpt-models">
                                        <?php foreach ($chatgptModels as $m): ?>
                                            <a
                                                class="chatgpt-link <?= (string)$m['id'] === $chatgptAssistantId ? 'is-active' : '' ?>"
                                                href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => $chatgptTab, 'assistant' => $m['id'], 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId], '', '&', PHP_QUERY_RFC3986)) ?>"
                                            >
                                                <span class="chatgpt-link__icon"><?= h((string)$m['icon']) ?></span>
                                                <span class="chatgpt-link__label"><?= h((string)$m['name']) ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </section>

                                <section class="chatgpt-group">
                                    <button type="button" class="chatgpt-group__toggle" data-collapse-target="cgpt-projects" aria-expanded="true">
                                        <span>Projekty</span><span class="chatgpt-group__chevron">▸</span>
                                    </button>
                                    <div class="chatgpt-group__body" id="cgpt-projects">
                                        <?php foreach ($chatgptProjects as $p): ?>
                                            <a
                                                class="chatgpt-link <?= (string)$p['id'] === $chatgptProjectId ? 'is-active' : '' ?>"
                                                href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => $chatgptTab, 'assistant' => $chatgptAssistantId, 'project' => $p['id'], 'thread' => $chatgptThreadId], '', '&', PHP_QUERY_RFC3986)) ?>"
                                            >
                                                <span class="chatgpt-link__icon">PR</span>
                                                <span class="chatgpt-link__label"><?= h((string)$p['name']) ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </section>

                                <section class="chatgpt-group">
                                    <button type="button" class="chatgpt-group__toggle" data-collapse-target="cgpt-groups" aria-expanded="false">
                                        <span>Czaty grupowe</span><span class="chatgpt-group__chevron">▸</span>
                                    </button>
                                    <div class="chatgpt-group__body" id="cgpt-groups" hidden>
                                        <?php foreach ($chatgptGroups as $g): ?>
                                            <a class="chatgpt-link" href="#">
                                                <span class="chatgpt-link__icon">GR</span>
                                                <span class="chatgpt-link__label"><?= h((string)$g['name']) ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                        <a class="chatgpt-link" href="#">
                                            <span class="chatgpt-link__icon">+</span>
                                            <span class="chatgpt-link__label">Nowy czat grupowy</span>
                                        </a>
                                    </div>
                                </section>

                                <section class="chatgpt-group">
                                    <div class="chatgpt-group__head">
                                        <button type="button" class="chatgpt-group__toggle" data-collapse-target="cgpt-history" aria-expanded="false" id="chatgpt-history-toggle">
                                            <span>Twoje czaty</span><span class="chatgpt-group__chevron">▸</span>
                                        </button>
                                        <button type="button" class="chatgpt-group__history-btn" id="chatgpt-history-open">Historia</button>
                                    </div>
                                    <div class="chatgpt-group__body" id="cgpt-history" hidden>
                                        <div class="chatgpt-history-list" id="chatgpt-history-list-recent">
                                            <?php if (!$chatgptThreadsRecent): ?>
                                                <p class="chatgpt-history-empty">Brak rozmów dla wybranego projektu i modelu.</p>
                                            <?php else: ?>
                                                <?php foreach ($chatgptThreadsRecent as $th): ?>
                                                    <a
                                                        class="chatgpt-link <?= (string)$th['id'] === $chatgptThreadId ? 'is-active' : '' ?>"
                                                        href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $th['id']], '', '&', PHP_QUERY_RFC3986)) ?>"
                                                        data-chat-title="<?= h(strtolower((string)$th['name'])) ?>"
                                                        data-thread-id="<?= h((string)$th['id']) ?>"
                                                    >
                                                        <span class="chatgpt-link__icon">CH</span>
                                                        <span class="chatgpt-link__label"><?= h((string)$th['name']) ?></span>
                                                        <span class="chatgpt-link__more">...</span>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </section>

                                <button
                                    type="button"
                                    class="chatgpt-account"
                                    id="chatgpt-account-trigger"
                                    title="Panel systemu"
                                    aria-haspopup="dialog"
                                    aria-controls="chatgpt-ops-backdrop"
                                    aria-expanded="false"
                                >
                                    <span class="chatgpt-account__avatar">R</span>
                                    <span class="chatgpt-account__meta">
                                        <strong>Roman Ber</strong>
                                        <span class="chatgpt-account__plan">Plan: Plus</span>
                                    </span>
                                    <span class="chatgpt-account__gear">SYS</span>
                                </button>
                            </div>
                        </aside>

                        <section class="chatgpt-stage" aria-label="ChatGPT Main">
                            <div class="chatgpt-stage__inner">
                                <header class="chatgpt-stage-top">
                                    <div class="chatgpt-stage-top__left">
                                        <button class="chatgpt-model-btn" type="button"><?= h($chatgptSelectedModelName) ?> ▾</button>
                                        <?php if ($chatgptSelectedProjectName !== ''): ?>
                                            <span class="status-pill">Projekt: <?= h($chatgptSelectedProjectName) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="chatgpt-stage-icons" aria-label="Skróty">
                                        <span>S</span>
                                        <span>U</span>
                                    </div>
                                </header>

                                <?php if ($chatgptTab === 'session'): ?>
                                    <section class="chatgpt-home" aria-label="Welcome">
                                        <h3><?= $chatgptNewChat ? 'Co dzisiaj w programie?' : 'Cześć, Roman. Zaczynamy?' ?></h3>
                                        <p>
                                            Kontekst: <strong><?= h($chatgptSelectedModelName) ?></strong>
                                            <?php if ($chatgptSelectedProjectName !== ''): ?>
                                                | projekt <strong><?= h($chatgptSelectedProjectName) ?></strong>
                                            <?php endif; ?>
                                        </p>
                                    </section>

                                    <?php if ($chatgptThreadId !== ''): ?>
                                        <section class="chatgpt-thread-panel" aria-label="Wątek wiadomości">
                                            <h6>Wątek: <code><?= h($chatgptThreadId) ?></code></h6>
                                            <div class="chatgpt-thread-log" id="chatgpt-thread-log">
                                                <?php if (!$chatgptMessages): ?>
                                                    <div class="chatgpt-msg">
                                                        <span class="chatgpt-msg__meta">system</span>
                                                        <p class="chatgpt-msg__text">Brak wiadomości w tym wątku.</p>
                                                    </div>
                                                <?php else: ?>
                                                    <?php foreach ($chatgptMessages as $msg): ?>
                                                        <?php
                                                            $msgRole = trim((string)($msg['role'] ?? 'unknown'));
                                                            $msgText = trim((string)($msg['content_text'] ?? ''));
                                                            $msgClass = $msgRole === 'assistant' ? 'chatgpt-msg chatgpt-msg--assistant' : 'chatgpt-msg chatgpt-msg--user';
                                                            $msgMeta = is_array($msg['metadata_json'] ?? null) ? $msg['metadata_json'] : [];
                                                            $exchangeMeta = is_array($msgMeta['exchange'] ?? null) ? $msgMeta['exchange'] : [];
                                                            $comparisonRaw = is_array($exchangeMeta['comparison_options'] ?? null) ? $exchangeMeta['comparison_options'] : [];
                                                            $msgAttachmentsRaw = is_array($msg['attachments'] ?? null) ? $msg['attachments'] : [];
                                                            $msgAttachments = [];
                                                            foreach ($msgAttachmentsRaw as $attRaw) {
                                                                if (!is_array($attRaw)) {
                                                                    continue;
                                                                }
                                                                $attMeta = is_array($attRaw['metadata_json'] ?? null) ? $attRaw['metadata_json'] : [];
                                                                $previewKind = trim((string)($attMeta['preview_kind'] ?? ''));
                                                                if ($previewKind === '') {
                                                                    $previewKind = trim((string)($attRaw['mime_type'] ?? ''));
                                                                    if ($previewKind !== '') {
                                                                        if (str_starts_with($previewKind, 'image/')) {
                                                                            $previewKind = 'image';
                                                                        } elseif (str_starts_with($previewKind, 'video/')) {
                                                                            $previewKind = 'video';
                                                                        } elseif (str_starts_with($previewKind, 'audio/')) {
                                                                            $previewKind = 'audio';
                                                                        } elseif ($previewKind === 'application/pdf') {
                                                                            $previewKind = 'pdf';
                                                                        } else {
                                                                            $previewKind = 'file';
                                                                        }
                                                                    }
                                                                }
                                                                if ($previewKind === '') {
                                                                    $previewKind = 'file';
                                                                }
                                                                $storageRef = trim((string)($attRaw['storage_ref'] ?? ''));
                                                                if ($storageRef === '') {
                                                                    continue;
                                                                }
                                                                $msgAttachments[] = [
                                                                    'name' => trim((string)($attRaw['file_name'] ?? '')) ?: 'attachment',
                                                                    'url' => $storageRef,
                                                                    'kind' => $previewKind,
                                                                ];
                                                            }
                                                            $comparisonOptions = [];
                                                            foreach ($comparisonRaw as $optRaw) {
                                                                if (!is_array($optRaw)) {
                                                                    continue;
                                                                }
                                                                $optText = trim((string)($optRaw['text'] ?? ''));
                                                                if ($optText === '') {
                                                                    continue;
                                                                }
                                                                $optLabel = trim((string)($optRaw['label'] ?? ''));
                                                                $optIndex = is_numeric($optRaw['index'] ?? null)
                                                                    ? (int)$optRaw['index']
                                                                    : count($comparisonOptions);
                                                                if ($optLabel === '') {
                                                                    $optLabel = 'Odpowiedź ' . ($optIndex + 1);
                                                                }
                                                                $comparisonOptions[] = [
                                                                    'index' => $optIndex,
                                                                    'label' => $optLabel,
                                                                    'text' => $optText,
                                                                ];
                                                            }
                                                            $comparisonSelectedIndex = is_numeric($exchangeMeta['comparison_selected_index'] ?? null)
                                                                ? (int)$exchangeMeta['comparison_selected_index']
                                                                : -1;
                                                            $hasComparison = $msgRole === 'assistant' && count($comparisonOptions) >= 2;
                                                        ?>
                                                        <?php $articleClass = $msgClass . ($hasComparison ? ' chatgpt-msg--compare' : ''); ?>
                                                        <article class="<?= h($articleClass) ?>" data-message-id="<?= h((string)($msg['message_id'] ?? '')) ?>">
                                                            <?php if (!$hasComparison): ?>
                                                                <span class="chatgpt-msg__meta"><?= h($msgRole) ?> · <?= h((string)($msg['mode'] ?? 'default')) ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($hasComparison): ?>
                                                                <div class="chatgpt-compare">
                                                                    <p class="chatgpt-compare__title">Przekazujesz opinię na temat nowej wersji ChatGPT.</p>
                                                                    <p class="chatgpt-compare__subtitle">Którą odpowiedź wybierasz? Wczytywanie odpowiedzi może chwilę potrwać.</p>
                                                                    <div class="chatgpt-compare__grid">
                                                                        <?php foreach ($comparisonOptions as $opt): ?>
                                                                            <?php $isSelected = (int)$opt['index'] === $comparisonSelectedIndex; ?>
                                                                            <?php $prefChoice = ((int)$opt['index'] === 1) ? 'second' : 'first'; ?>
                                                                            <article class="chatgpt-compare-card <?= $isSelected ? 'is-selected' : '' ?>">
                                                                                <span class="chatgpt-compare-card__label"><?= h((string)$opt['label']) ?></span>
                                                                                <p class="chatgpt-compare-card__text"><?= h((string)$opt['text']) ?></p>
                                                                                <button
                                                                                    type="button"
                                                                                    class="chatgpt-compare-card__btn chatgpt-compare-select <?= $isSelected ? 'is-selected' : '' ?>"
                                                                                    data-comparison-choice="<?= h($prefChoice) ?>"
                                                                                >
                                                                                    Wolę tę odpowiedź<?= $isSelected ? ' ✓' : '' ?>
                                                                                </button>
                                                                            </article>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                            <?php else: ?>
                                                                <p class="chatgpt-msg__text"><?= h($msgText !== '' ? $msgText : '[pusta wiadomość]') ?></p>
                                                            <?php endif; ?>
                                                            <?php if ($msgAttachments): ?>
                                                                <div class="chatgpt-msg__attachments">
                                                                    <?php foreach ($msgAttachments as $att): ?>
                                                                        <?php
                                                                            $attName = (string)($att['name'] ?? 'attachment');
                                                                            $attUrl = (string)($att['url'] ?? '');
                                                                            $attKind = (string)($att['kind'] ?? 'file');
                                                                        ?>
                                                                        <article class="chatgpt-msg-attachment">
                                                                            <span class="chatgpt-msg-attachment__name"><?= h($attName) ?> · <?= h($attKind) ?></span>
                                                                            <?php if ($attKind === 'image'): ?>
                                                                                <img class="chatgpt-msg-attachment__preview" src="<?= h($attUrl) ?>" alt="<?= h($attName) ?>" loading="lazy">
                                                                            <?php elseif ($attKind === 'video'): ?>
                                                                                <video class="chatgpt-msg-attachment__preview" controls preload="metadata" src="<?= h($attUrl) ?>"></video>
                                                                            <?php elseif ($attKind === 'audio'): ?>
                                                                                <audio class="chatgpt-msg-attachment__preview" controls preload="none" src="<?= h($attUrl) ?>"></audio>
                                                                            <?php endif; ?>
                                                                            <a class="chatgpt-msg-attachment__link" href="<?= h($attUrl) ?>" target="_blank" rel="noopener">Otwórz plik</a>
                                                                        </article>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </article>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </section>
                                    <?php endif; ?>

                                    <form method="post" id="chatgpt-send-form">
                                        <input type="hidden" name="action" value="chatgpt_send_message">
                                        <input type="hidden" name="chatgpt_assistant_id" value="<?= h($chatgptAssistantId) ?>">
                                        <input type="hidden" name="chatgpt_project_id" value="<?= h($chatgptProjectId) ?>">
                                        <input type="hidden" name="chatgpt_thread_id" value="<?= h($chatgptThreadId) ?>">
                                        <input type="hidden" name="chatgpt_thread_title" value="<?= h($chatgptNewChat ? '' : ($chatgptSelectedThreadName !== '' ? $chatgptSelectedThreadName : 'Nowy wątek')) ?>">
                                        <input type="hidden" name="chatgpt_mode" id="chatgpt-composer-mode" value="default">
                                        <input type="hidden" name="chatgpt_comparison_preference" id="chatgpt-comparison-preference" value="first">

                                        <section class="chatgpt-composer-wrap" aria-label="Composer">
                                            <div class="chatgpt-attachments" id="chatgpt-attachments" hidden></div>
                                            <div class="chatgpt-composer" id="chatgpt-composer">
                                                <button type="button" id="chatgpt-tools-toggle" class="chatgpt-plus-btn" aria-label="Narzędzia">+</button>
                                                <textarea
                                                    id="chatgpt-composer-input"
                                                    name="chatgpt_prompt"
                                                    rows="1"
                                                    placeholder="Zapytaj o cokolwiek"
                                                    aria-label="Wiadomość"
                                                    required
                                                ></textarea>
                                                <div class="chatgpt-composer-actions">
                                                    <button type="button" class="chatgpt-icon-btn" aria-label="Mikrofon">Mic</button>
                                                    <button type="button" class="chatgpt-icon-btn voice" aria-label="Tryb głosowy">V</button>
                                                    <button type="submit" class="chatgpt-icon-btn" aria-label="Wyślij">Send</button>
                                                </div>
                                            </div>
                                            <div class="chatgpt-mode-pill" id="chatgpt-mode-pill">Tryb: standard</div>
                                            <div class="chatgpt-comparison-picker" id="chatgpt-comparison-picker" aria-label="Preferencja wyboru odpowiedzi">
                                                <span>Wybór odpowiedzi:</span>
                                                <button type="button" class="chatgpt-comparison-picker__btn is-active" data-pref="first">1</button>
                                                <button type="button" class="chatgpt-comparison-picker__btn" data-pref="second">2</button>
                                            </div>

                                            <div class="chatgpt-tools" id="chatgpt-tools" hidden>
                                                <div class="chatgpt-tools__menu">
                                                    <button type="button" class="chatgpt-tools__item" data-tool="attach_files">Dodaj zdjęcia i pliki</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="google_drive">Dodaj z Google Drive</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="image">Stwórz obraz</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="deep_research">Głębokie badanie</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="shopping">Asystent zakupowy</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="web_search">Wyszukiwanie w sieci</button>
                                                    <button type="button" class="chatgpt-tools__item" id="chatgpt-tools-more" data-open-submenu="more">Więcej <span>▸</span></button>
                                                </div>
                                                <div class="chatgpt-tools__submenu" id="chatgpt-tools-submenu" hidden>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="learn">Ucz się i przyswajaj wiedzę</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="agent">Tryb agenta</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="canvas">Kanwa</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="photoshop">Adobe Photoshop</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="network">Network Solutions</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="quiz">Quizy</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="discover">Odkryj aplikacje</button>
                                                </div>
                                            </div>
                                            <input type="file" id="chatgpt-file-input" hidden multiple>
                                        </section>
                                    </form>

                                <?php endif; ?>

                                <?php if ($chatgptTab === 'status'): ?>
                                    <section class="panel chatgpt-session-panel" aria-label="ChatGPT gateway status">
                                        <h5 style="margin-top:0; color:#f4f9ff;">Status gatewaya i sesji</h5>
                                        <p style="color:#abc0d5;">
                                            Diagnostyka warstwy sesyjnej: stan auth, ostatnie sesje i log zdarzeń.
                                        </p>

                                        <div class="chatgpt-status-grid" aria-label="Status gateway">
                                            <div class="chatgpt-status-card">
                                                <div class="kpi-title">Gateway</div>
                                                <div class="kpi-value"><?= $chatgptGatewayOk ? 'OK' : 'DOWN' ?></div>
                                            </div>
                                            <div class="chatgpt-status-card">
                                                <div class="kpi-title">Auth State</div>
                                                <div class="kpi-value"><?= h($chatgptAuthState) ?></div>
                                            </div>
                                            <div class="chatgpt-status-card">
                                                <div class="kpi-title">API Base</div>
                                                <div style="font-size:0.88rem; color:#b8cde2;"><code><?= h(chatgpt_session_api_base()) ?></code></div>
                                            </div>
                                            <div class="chatgpt-status-card">
                                                <div class="kpi-title">Contract /v1</div>
                                                <div class="kpi-value"><?= (!empty($chatgptSchema['ok']) ? h((string)($chatgptSchema['version'] ?? '1.0')) : 'DOWN') ?></div>
                                            </div>
                                        </div>

                                        <div class="callout">
                                            <div class="kpi-title" style="margin-bottom:0.4rem;">Model wymiany danych</div>
                                            <?php if (empty($chatgptSchema['ok'])): ?>
                                                <div style="color:#b8cde2;">Brak odpowiedzi z <code>/v1/schema</code>.</div>
                                            <?php else: ?>
                                                <div>
                                                    encje:
                                                    <code><?= h(implode(', ', (array)($chatgptSchema['entities'] ?? []))) ?></code>
                                                </div>
                                                <?php
                                                    $threadItems = is_array($chatgptThreadIndex['body']['items'] ?? null)
                                                        ? $chatgptThreadIndex['body']['items']
                                                        : [];
                                                    $threadCount = is_int($chatgptThreadIndex['body']['count'] ?? null)
                                                        ? (int)$chatgptThreadIndex['body']['count']
                                                        : count($threadItems);
                                                ?>
                                                <div style="margin-top:0.25rem;">
                                                    wątki (filtr projektu/modelu): <strong><?= (int)$threadCount ?></strong>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="callout">
                                            <div class="kpi-title" style="margin-bottom:0.4rem;">Ostatnia sesja</div>
                                            <?php if (!$cgptLatest): ?>
                                                <div style="color:#b8cde2;">Brak sesji w gatewayu.</div>
                                            <?php else: ?>
                                                <div><strong>Session ID:</strong> <code><?= h((string)($cgptLatest['session_id'] ?? '-')) ?></code></div>
                                                <div><strong>Status:</strong> <?= h((string)($cgptLatest['state'] ?? '-')) ?></div>
                                                <div><strong>Start:</strong> <?= h((string)($cgptLatest['started_at'] ?? '-')) ?></div>
                                                <div><strong>Stop:</strong> <?= h((string)($cgptLatest['stopped_at'] ?? '-')) ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="table-wrap chatgpt-event-table">
                                            <table>
                                                <thead>
                                                <tr>
                                                    <th>Time</th>
                                                    <th>Session</th>
                                                    <th>Event</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php if (count($cgptEvents) === 0): ?>
                                                    <tr><td colspan="3" style="color:#b8cde2;">Brak zdarzeń.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($cgptEvents as $evt): ?>
                                                        <tr>
                                                            <td><?= h((string)($evt['created_at'] ?? '-')) ?></td>
                                                            <td><code><?= h((string)($evt['session_id'] ?? '-')) ?></code></td>
                                                            <td><?= h((string)($evt['event_type'] ?? '-')) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                    <div class="chatgpt-modal-backdrop" id="chatgpt-ops-backdrop" hidden>
                        <section class="chatgpt-modal" id="chatgpt-ops-modal" role="dialog" aria-modal="true" aria-labelledby="chatgpt-ops-title">
                            <header class="chatgpt-modal__header">
                                <h5 class="chatgpt-modal__title" id="chatgpt-ops-title">Panel Sesji i Systemu</h5>
                                <button type="button" class="chatgpt-modal__close" id="chatgpt-ops-close" aria-label="Zamknij panel">×</button>
                            </header>
                            <div class="chatgpt-modal__body">
                                <section class="chatgpt-modal__section" aria-label="Konto">
                                    <h6>Konto</h6>
                                    <div class="chatgpt-modal__kv">
                                        <div>Użytkownik: <strong>Roman Ber</strong></div>
                                        <div>Plan: <strong>Plus</strong></div>
                                    </div>
                                </section>

                                <section class="chatgpt-modal__section" aria-label="Status systemu">
                                    <h6>Status systemu</h6>
                                    <div class="chatgpt-modal__kv">
                                        <div>Gateway: <strong id="cgpt-modal-gateway"><?= $chatgptGatewayOk ? 'OK' : 'DOWN' ?></strong></div>
                                        <div>Model: <strong id="cgpt-modal-contract"><?= $chatgptContractOk ? ('OK v' . h($chatgptSchemaVersion)) : 'DOWN' ?></strong></div>
                                        <div>Sesja: <strong id="cgpt-state"><?= h($chatgptAuthState) ?></strong></div>
                                        <div>Thread ID: <code id="cgpt-modal-thread-id"><?= h($chatgptThreadId !== '' ? $chatgptThreadId : 'new_chat') ?></code></div>
                                        <div>Project ID: <code id="cgpt-modal-project-id"><?= h($chatgptProjectId) ?></code></div>
                                        <div>Session ID: <code id="cgpt-modal-session-id"><?= h($chatgptEffectiveSessionId !== '' ? $chatgptEffectiveSessionId : '-') ?></code></div>
                                    </div>
                                    <p class="chatgpt-modal__hint" id="cgpt-hint">
                                        <?php if ($chatgptAuthState === 'AUTH_OK' && $chatgptHasLoginSession): ?>
                                            Zalogowano. Zamknij okno logowania, żeby zwolnić profil.
                                        <?php elseif ($chatgptAuthState === 'AUTH_OK'): ?>
                                            Sesja aktywna.
                                        <?php elseif ($chatgptAuthState === 'LOGIN_RUNNING'): ?>
                                            Okno logowania działa. Dokończ logowanie w noVNC.
                                        <?php else: ?>
                                            Sesja nieaktywna. Uruchom okno logowania.
                                        <?php endif; ?>
                                    </p>
                                </section>

                                <section class="chatgpt-modal__section" aria-label="Kontrola sesji">
                                    <h6>Kontrola sesji</h6>
                                    <div class="chatgpt-modal__actions">
                                        <?php if (!$chatgptHasLoginSession): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="start_chatgpt_login">
                                                <button type="submit" class="button primary">Uruchom okno logowania</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($chatgptHasLoginSession && $chatgptEffectiveNovncUrl !== ''): ?>
                                            <a
                                                class="button secondary"
                                                id="cgpt-modal-novnc-link"
                                                href="<?= h($chatgptEffectiveNovncUrl) ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >Otwórz noVNC</a>
                                        <?php else: ?>
                                            <a class="button secondary" id="cgpt-modal-novnc-link" href="#" target="_blank" rel="noopener" hidden>Otwórz noVNC</a>
                                        <?php endif; ?>
                                        <?php if ($chatgptHasLoginSession): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="stop_chatgpt_login">
                                                <input type="hidden" name="session_id" value="<?= h($chatgptEffectiveSessionId) ?>">
                                                <button type="submit" class="button hollow">Zamknij sesję</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="reset_chatgpt_session">
                                            <button type="submit" class="button warning">Reset sesji</button>
                                        </form>
                                        <button type="button" class="button hollow" id="cgpt-refresh-status">Odśwież status</button>
                                    </div>
                                </section>

                                <section class="chatgpt-modal__section chatgpt-modal__diag" aria-label="Diagnostyka">
                                    <h6>Diagnostyka</h6>
                                    <details>
                                        <summary>Szczegóły systemowe</summary>
                                        <div class="chatgpt-modal__kv" style="margin-top:0.55rem;">
                                            <div>Gateway URL: <code><?= h(chatgpt_session_api_base()) ?></code></div>
                                            <div>Aktywny thread: <code><?= h($chatgptThreadId !== '' ? $chatgptThreadId : 'new_chat') ?></code></div>
                                            <div>Token usage: <code>n/a</code></div>
                                            <div>Ostatni błąd: <code id="cgpt-modal-last-error">-</code></div>
                                            <div>Heartbeat: <code id="cgpt-heartbeat"><?= h((string)gmdate('c')) ?></code></div>
                                        </div>
                                    </details>
                                    <?php if ($chatgptHasLoginSession && $chatgptEffectiveNovncUrl !== '' && $chatgptAuthState !== 'AUTH_OK'): ?>
                                        <iframe
                                            class="chatgpt-modal__iframe"
                                            title="noVNC ChatGPT Login"
                                            src="<?= h($chatgptEffectiveNovncUrl) ?>"
                                            allow="clipboard-read; clipboard-write"
                                        ></iframe>
                                    <?php endif; ?>
                                </section>
                            </div>
                        </section>
                    </div>
                    <div class="chatgpt-modal-backdrop" id="chatgpt-history-backdrop" hidden>
                        <section class="chatgpt-modal chatgpt-modal--narrow" role="dialog" aria-modal="true" aria-labelledby="chatgpt-history-title">
                            <header class="chatgpt-modal__header">
                                <h5 class="chatgpt-modal__title" id="chatgpt-history-title">Historia rozmów</h5>
                                <button type="button" class="chatgpt-modal__close" id="chatgpt-history-close" aria-label="Zamknij historię">×</button>
                            </header>
                            <div class="chatgpt-modal__body">
                                <section class="chatgpt-modal__section" aria-label="Lista wszystkich rozmów">
                                    <h6>Wszystkie rozmowy (<?= (int)count($chatgptThreads) ?>)</h6>
                                    <div class="chatgpt-history-actions">
                                        <button type="button" class="button secondary small" id="chatgpt-sync-threads">Skanuj listę wątków</button>
                                        <button type="button" class="button secondary small" id="chatgpt-sync-messages">Dociągnij komplet rozmów</button>
                                        <button type="button" class="button primary small" id="chatgpt-sync-full">Pełna synchronizacja</button>
                                        <span class="chatgpt-history-sync-status" id="chatgpt-sync-history-status" role="status" aria-live="polite"></span>
                                    </div>
                                    <div class="chatgpt-history-sync-progress" id="chatgpt-sync-history-progress" hidden>
                                        <div class="chatgpt-history-sync-progress__bar" id="chatgpt-sync-history-progress-bar"></div>
                                    </div>
                                    <pre class="chatgpt-history-sync-live" id="chatgpt-sync-history-live" hidden></pre>
                                    <input
                                        id="chatgpt-history-filter"
                                        class="chatgpt-history-search"
                                        type="search"
                                        placeholder="Szukaj po tytule rozmowy..."
                                        aria-label="Szukaj rozmów"
                                    >
                                    <div class="chatgpt-history-list" id="chatgpt-history-list-modal">
                                        <?php if (!$chatgptThreads): ?>
                                            <p class="chatgpt-history-empty">Brak rozmów do wyświetlenia.</p>
                                        <?php else: ?>
                                            <?php foreach ($chatgptThreads as $th): ?>
                                                <a
                                                    class="chatgpt-link <?= (string)$th['id'] === $chatgptThreadId ? 'is-active' : '' ?>"
                                                    href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $th['id']], '', '&', PHP_QUERY_RFC3986)) ?>"
                                                    data-chat-title="<?= h(strtolower((string)$th['name'])) ?>"
                                                    data-thread-id="<?= h((string)$th['id']) ?>"
                                                >
                                                    <span class="chatgpt-link__icon">CH</span>
                                                    <span class="chatgpt-link__label"><?= h((string)$th['name']) ?></span>
                                                    <span class="chatgpt-link__more">...</span>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </section>
                            </div>
                        </section>
                    </div>
                    <div class="chatgpt-modal-backdrop" id="chatgpt-more-backdrop" hidden>
                        <section class="chatgpt-modal chatgpt-modal--narrow" role="dialog" aria-modal="true" aria-labelledby="chatgpt-more-title">
                            <header class="chatgpt-modal__header">
                                <h5 class="chatgpt-modal__title" id="chatgpt-more-title">Więcej funkcji</h5>
                                <button type="button" class="chatgpt-modal__close" id="chatgpt-more-close" aria-label="Zamknij więcej">×</button>
                            </header>
                            <div class="chatgpt-modal__body">
                                <section class="chatgpt-modal__section" aria-label="Funkcje dodatkowe">
                                    <h6>Skróty narzędziowe</h6>
                                    <div class="chatgpt-history-list">
                                        <a class="chatgpt-link" href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId, 'mode' => 'image'], '', '&', PHP_QUERY_RFC3986)) ?>">
                                            <span class="chatgpt-link__icon">IM</span><span class="chatgpt-link__label">Obrazy</span>
                                        </a>
                                        <a class="chatgpt-link" href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId, 'mode' => 'apps'], '', '&', PHP_QUERY_RFC3986)) ?>">
                                            <span class="chatgpt-link__icon">AP</span><span class="chatgpt-link__label">Aplikacje</span>
                                        </a>
                                        <a class="chatgpt-link" href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId, 'mode' => 'deep_research'], '', '&', PHP_QUERY_RFC3986)) ?>">
                                            <span class="chatgpt-link__icon">DR</span><span class="chatgpt-link__label">Głębokie badanie</span>
                                        </a>
                                        <a class="chatgpt-link" href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId, 'mode' => 'codex'], '', '&', PHP_QUERY_RFC3986)) ?>">
                                            <span class="chatgpt-link__icon">CX</span><span class="chatgpt-link__label">Codex</span>
                                        </a>
                                        <a class="chatgpt-link" href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'status', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId], '', '&', PHP_QUERY_RFC3986)) ?>">
                                            <span class="chatgpt-link__icon">ST</span><span class="chatgpt-link__label">Status Gateway</span>
                                        </a>
                                    </div>
                                </section>
                            </div>
                        </section>
                    </div>

                    <script>
                        (function () {
                            const shell = document.getElementById('chatgpt-shell');
                            if (!shell) return;

                            const collapseButtons = shell.querySelectorAll('[data-collapse-target]');
                            collapseButtons.forEach((btn) => {
                                const targetId = btn.getAttribute('data-collapse-target');
                                const body = targetId ? document.getElementById(targetId) : null;
                                if (body) {
                                    const expandedAtInit = btn.getAttribute('aria-expanded') !== 'false';
                                    body.hidden = !expandedAtInit;
                                }
                                btn.addEventListener('click', () => {
                                    const targetId = btn.getAttribute('data-collapse-target');
                                    if (!targetId) return;
                                    const body = document.getElementById(targetId);
                                    if (!body) return;
                                    const expanded = btn.getAttribute('aria-expanded') !== 'false';
                                    btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                                    body.hidden = expanded;
                                });
                            });

                            const composer = document.getElementById('chatgpt-composer');
                            const composerInput = document.getElementById('chatgpt-composer-input');
                            const composerForm = document.getElementById('chatgpt-send-form');
                            const threadIdInput = composerForm ? composerForm.querySelector('input[name="chatgpt_thread_id"]') : null;
                            const threadTitleInput = composerForm ? composerForm.querySelector('input[name="chatgpt_thread_title"]') : null;
                            const assistantIdInput = composerForm ? composerForm.querySelector('input[name="chatgpt_assistant_id"]') : null;
                            const projectIdInput = composerForm ? composerForm.querySelector('input[name="chatgpt_project_id"]') : null;
                            const sendButton = composerForm ? composerForm.querySelector('button[type="submit"]') : null;
                            const toolsToggle = document.getElementById('chatgpt-tools-toggle');
                            const tools = document.getElementById('chatgpt-tools');
                            const toolsMore = document.getElementById('chatgpt-tools-more');
                            const submenu = document.getElementById('chatgpt-tools-submenu');
                            const modePill = document.getElementById('chatgpt-mode-pill');
                            const modeInput = document.getElementById('chatgpt-composer-mode');
                            const comparisonInput = document.getElementById('chatgpt-comparison-preference');
                            const comparisonPicker = document.getElementById('chatgpt-comparison-picker');
                            const fileInput = document.getElementById('chatgpt-file-input');
                            const attachments = document.getElementById('chatgpt-attachments');
                            const newChatTrigger = document.getElementById('chatgpt-new-chat-trigger');
                            const moreOpenBtn = document.getElementById('chatgpt-more-open');
                            const accountTrigger = document.getElementById('chatgpt-account-trigger');
                            const opsBackdrop = document.getElementById('chatgpt-ops-backdrop');
                            const opsClose = document.getElementById('chatgpt-ops-close');
                            const historyOpenBtn = document.getElementById('chatgpt-history-open');
                            const historyBackdrop = document.getElementById('chatgpt-history-backdrop');
                            const historyClose = document.getElementById('chatgpt-history-close');
                            const moreBackdrop = document.getElementById('chatgpt-more-backdrop');
                            const moreClose = document.getElementById('chatgpt-more-close');
                            const syncThreadsBtn = document.getElementById('chatgpt-sync-threads');
                            const syncMessagesBtn = document.getElementById('chatgpt-sync-messages');
                            const syncFullBtn = document.getElementById('chatgpt-sync-full');
                            const syncHistoryStatus = document.getElementById('chatgpt-sync-history-status');
                            const syncProgressWrap = document.getElementById('chatgpt-sync-history-progress');
                            const syncProgressBar = document.getElementById('chatgpt-sync-history-progress-bar');
                            const syncLive = document.getElementById('chatgpt-sync-history-live');
                            const refreshStatusBtn = document.getElementById('cgpt-refresh-status');
                            const topAuthPill = document.getElementById('chatgpt-top-auth-pill');
                            const historySearchInput = document.getElementById('chatgpt-history-filter');
                            const modalThreadIdEl = document.getElementById('cgpt-modal-thread-id');
                            const modalProjectIdEl = document.getElementById('cgpt-modal-project-id');
                            const modalSessionIdEl = document.getElementById('cgpt-modal-session-id');
                            const modalHeartbeatEl = document.getElementById('cgpt-heartbeat');
                            const modalNovncLink = document.getElementById('cgpt-modal-novnc-link');
                            const modalLastErrorEl = document.getElementById('cgpt-modal-last-error');

                            let threadLog = document.getElementById('chatgpt-thread-log');
                            let threadPanel = shell.querySelector('.chatgpt-thread-panel');
                            let threadCode = threadPanel ? threadPanel.querySelector('h6 code') : null;
                            let scrollFrame = 0;
                            let autoFollow = true;
                            let forceFollowUntil = Date.now() + 2200;
                            let submitBusy = false;
                            let syncHistoryBusy = false;
                            let activeSyncJobId = '';
                            let syncPollTimer = 0;
                            let activeExchangeId = '';
                            let activeAssistantNode = null;
                            let exchangePollTimer = 0;
                            let applyComparisonPreference = null;

                            if (historySearchInput) {
                                historySearchInput.addEventListener('input', () => {
                                    const q = historySearchInput.value.trim().toLowerCase();
                                    const rows = document.querySelectorAll('#chatgpt-history-list-modal [data-chat-title]');
                                    rows.forEach((row) => {
                                        const title = row.getAttribute('data-chat-title') || '';
                                        row.style.display = (q === '' || title.indexOf(q) !== -1) ? '' : 'none';
                                    });
                                });
                            }

                            const setHistorySyncStatus = (message, state = '') => {
                                if (!syncHistoryStatus) {
                                    return;
                                }
                                syncHistoryStatus.textContent = message || '';
                                if (state === '') {
                                    delete syncHistoryStatus.dataset.state;
                                } else {
                                    syncHistoryStatus.dataset.state = state;
                                }
                            };

                            const setSyncButtonsDisabled = (disabled) => {
                                [syncThreadsBtn, syncMessagesBtn, syncFullBtn].forEach((btn) => {
                                    if (btn) {
                                        btn.disabled = disabled;
                                    }
                                });
                            };

                            const setSyncProgress = (done, total) => {
                                if (!syncProgressWrap || !syncProgressBar) {
                                    return;
                                }
                                const t = Number(total || 0);
                                const d = Number(done || 0);
                                if (t <= 0) {
                                    syncProgressWrap.hidden = true;
                                    syncProgressBar.style.width = '0%';
                                    return;
                                }
                                const pct = Math.max(0, Math.min(100, Math.round((d / t) * 100)));
                                syncProgressWrap.hidden = false;
                                syncProgressBar.style.width = pct + '%';
                            };

                            const setSyncLive = (text) => {
                                if (!syncLive) {
                                    return;
                                }
                                const value = String(text || '').trim();
                                if (value === '') {
                                    syncLive.hidden = true;
                                    syncLive.textContent = '';
                                    return;
                                }
                                syncLive.hidden = false;
                                syncLive.textContent = value;
                            };

                            const nearBottom = () => {
                                if (!threadLog) {
                                    return true;
                                }
                                const delta = threadLog.scrollHeight - threadLog.scrollTop - threadLog.clientHeight;
                                return delta < 88;
                            };

                            const scheduleStickToBottom = (force = false) => {
                                if (!threadLog) {
                                    return;
                                }
                                if (!(force || autoFollow || Date.now() < forceFollowUntil)) {
                                    return;
                                }
                                if (scrollFrame !== 0) {
                                    return;
                                }
                                scrollFrame = window.requestAnimationFrame(() => {
                                    scrollFrame = 0;
                                    if (!threadLog) {
                                        return;
                                    }
                                    threadLog.scrollTop = threadLog.scrollHeight;
                                });
                            };

                            const bindThreadScroll = () => {
                                if (!threadLog) {
                                    return;
                                }
                                if (threadLog.dataset.scrollBound === '1') {
                                    return;
                                }
                                threadLog.dataset.scrollBound = '1';
                                threadLog.addEventListener('scroll', () => {
                                    autoFollow = nearBottom();
                                });
                            };

                            const revealLatest = () => {
                                bindThreadScroll();
                                autoFollow = true;
                                forceFollowUntil = Date.now() + 2500;
                                scheduleStickToBottom(true);
                                window.requestAnimationFrame(() => scheduleStickToBottom(true));
                                window.setTimeout(() => scheduleStickToBottom(true), 180);
                            };

                            const ensureThreadPanel = (threadId = '') => {
                                if (threadLog && threadPanel) {
                                    return threadLog;
                                }
                                if (!composerForm) {
                                    return null;
                                }
                                const stageInner = shell.querySelector('.chatgpt-stage__inner');
                                if (!stageInner) {
                                    return null;
                                }
                                threadPanel = document.createElement('section');
                                threadPanel.className = 'chatgpt-thread-panel';
                                threadPanel.setAttribute('aria-label', 'Wątek wiadomości');
                                const heading = document.createElement('h6');
                                heading.appendChild(document.createTextNode('Wątek: '));
                                threadCode = document.createElement('code');
                                threadCode.textContent = threadId || 'pending-thread';
                                heading.appendChild(threadCode);
                                threadPanel.appendChild(heading);
                                threadLog = document.createElement('div');
                                threadLog.className = 'chatgpt-thread-log';
                                threadLog.id = 'chatgpt-thread-log';
                                threadPanel.appendChild(threadLog);
                                stageInner.insertBefore(threadPanel, composerForm);
                                bindThreadScroll();
                                revealLatest();
                                return threadLog;
                            };

                            const ensureThreadHeader = (threadId) => {
                                if (!threadId) {
                                    return;
                                }
                                ensureThreadPanel(threadId);
                                if (threadCode) {
                                    threadCode.textContent = threadId;
                                }
                            };

                            const removeEmptyThreadPlaceholder = () => {
                                if (!threadLog) {
                                    return;
                                }
                                if (threadLog.children.length !== 1) {
                                    return;
                                }
                                const first = threadLog.firstElementChild;
                                if (!first || !first.classList.contains('chatgpt-msg')) {
                                    return;
                                }
                                const meta = first.querySelector('.chatgpt-msg__meta');
                                if (!meta || meta.textContent !== 'system') {
                                    return;
                                }
                                first.remove();
                            };

                            const normalizeComparisonOptions = (raw) => {
                                if (!Array.isArray(raw)) {
                                    return [];
                                }
                                const out = [];
                                raw.forEach((item, index) => {
                                    if (!item || typeof item !== 'object') {
                                        return;
                                    }
                                    const text = String(item.text || '').trim();
                                    if (text === '') {
                                        return;
                                    }
                                    const idx = Number.isInteger(item.index) ? Number(item.index) : index;
                                    const label = String(item.label || '').trim() || ('Odpowiedź ' + (idx + 1));
                                    out.push({ index: idx, label: label, text: text });
                                });
                                return out;
                            };

                            const buildTextMessage = ({ role, mode, text, messageId, streaming }) => {
                                const article = document.createElement('article');
                                article.className = 'chatgpt-msg ' + (role === 'assistant' ? 'chatgpt-msg--assistant' : 'chatgpt-msg--user');
                                if (streaming) {
                                    article.classList.add('is-streaming');
                                }
                                if (messageId) {
                                    article.dataset.messageId = messageId;
                                }
                                const meta = document.createElement('span');
                                meta.className = 'chatgpt-msg__meta';
                                meta.textContent = role + ' · ' + (mode || 'default');
                                article.appendChild(meta);
                                const paragraph = document.createElement('p');
                                paragraph.className = 'chatgpt-msg__text';
                                paragraph.textContent = text !== '' ? text : '[pusta wiadomość]';
                                article.appendChild(paragraph);
                                return article;
                            };

                            const applyChoiceVisualState = (button) => {
                                const grid = button.closest('.chatgpt-compare__grid');
                                if (!grid) {
                                    return;
                                }
                                grid.querySelectorAll('.chatgpt-compare-card').forEach((card) => {
                                    card.classList.remove('is-selected');
                                });
                                grid.querySelectorAll('.chatgpt-compare-card__btn').forEach((btn) => {
                                    btn.classList.remove('is-selected');
                                    btn.textContent = 'Wolę tę odpowiedź';
                                });
                                const card = button.closest('.chatgpt-compare-card');
                                if (card) {
                                    card.classList.add('is-selected');
                                }
                                button.classList.add('is-selected');
                                button.textContent = 'Wolę tę odpowiedź ✓';
                            };

                            const bindComparisonButtons = (scope) => {
                                if (!scope) {
                                    return;
                                }
                                scope.querySelectorAll('.chatgpt-compare-select[data-comparison-choice]').forEach((btn) => {
                                    if (btn.dataset.bound === '1') {
                                        return;
                                    }
                                    btn.dataset.bound = '1';
                                    btn.addEventListener('click', () => {
                                        const pref = btn.getAttribute('data-comparison-choice') || 'first';
                                        if (typeof applyComparisonPreference === 'function') {
                                            applyComparisonPreference(pref);
                                        } else if (comparisonInput) {
                                            comparisonInput.value = pref === 'second' ? 'second' : 'first';
                                        }
                                        applyChoiceVisualState(btn);
                                        if (composerInput) {
                                            composerInput.focus();
                                        }
                                    });
                                });
                            };

                            const buildComparisonMessage = ({ messageId, options, selectedIndex, streaming }) => {
                                const article = document.createElement('article');
                                article.className = 'chatgpt-msg chatgpt-msg--assistant chatgpt-msg--compare';
                                if (streaming) {
                                    article.classList.add('is-streaming');
                                }
                                if (messageId) {
                                    article.dataset.messageId = messageId;
                                }
                                const wrap = document.createElement('div');
                                wrap.className = 'chatgpt-compare';
                                const title = document.createElement('p');
                                title.className = 'chatgpt-compare__title';
                                title.textContent = 'Przekazujesz opinię na temat nowej wersji ChatGPT.';
                                wrap.appendChild(title);
                                const subtitle = document.createElement('p');
                                subtitle.className = 'chatgpt-compare__subtitle';
                                subtitle.textContent = 'Którą odpowiedź wybierasz? Wczytywanie odpowiedzi może chwilę potrwać.';
                                wrap.appendChild(subtitle);
                                const grid = document.createElement('div');
                                grid.className = 'chatgpt-compare__grid';
                                options.forEach((opt) => {
                                    const isSelected = Number(opt.index) === Number(selectedIndex);
                                    const prefChoice = Number(opt.index) === 1 ? 'second' : 'first';
                                    const card = document.createElement('article');
                                    card.className = 'chatgpt-compare-card' + (isSelected ? ' is-selected' : '');
                                    const label = document.createElement('span');
                                    label.className = 'chatgpt-compare-card__label';
                                    label.textContent = String(opt.label || '');
                                    card.appendChild(label);
                                    const text = document.createElement('p');
                                    text.className = 'chatgpt-compare-card__text';
                                    text.textContent = String(opt.text || '');
                                    card.appendChild(text);
                                    const button = document.createElement('button');
                                    button.type = 'button';
                                    button.className = 'chatgpt-compare-card__btn chatgpt-compare-select' + (isSelected ? ' is-selected' : '');
                                    button.setAttribute('data-comparison-choice', prefChoice);
                                    button.textContent = 'Wolę tę odpowiedź' + (isSelected ? ' ✓' : '');
                                    card.appendChild(button);
                                    grid.appendChild(card);
                                });
                                wrap.appendChild(grid);
                                article.appendChild(wrap);
                                bindComparisonButtons(article);
                                return article;
                            };

                            const appendToThread = (node) => {
                                if (!node) {
                                    return null;
                                }
                                const currentThread = threadIdInput ? String(threadIdInput.value || '').trim() : '';
                                const log = ensureThreadPanel(currentThread);
                                if (!log) {
                                    return null;
                                }
                                removeEmptyThreadPlaceholder();
                                log.appendChild(node);
                                autoFollow = true;
                                forceFollowUntil = Date.now() + 4500;
                                scheduleStickToBottom(true);
                                return node;
                            };

                            const updateAssistantNode = (node, payload) => {
                                if (!node) {
                                    return null;
                                }
                                const messageId = String(payload.messageId || '');
                                const mode = String(payload.mode || 'default');
                                const status = String(payload.status || 'running');
                                const streaming = status !== 'completed' && status !== 'failed';
                                const comparisonOptions = normalizeComparisonOptions(payload.comparisonOptions);
                                const selectedIndex = Number.isInteger(payload.selectedIndex) ? payload.selectedIndex : -1;
                                const textValue = String(payload.text || '').trim();
                                const fallbackText = streaming ? '...' : '[pusta wiadomość]';
                                if (comparisonOptions.length >= 2) {
                                    const compareNode = buildComparisonMessage({
                                        messageId: messageId,
                                        options: comparisonOptions,
                                        selectedIndex: selectedIndex,
                                        streaming: streaming,
                                    });
                                    if (node !== compareNode && node.parentNode) {
                                        node.parentNode.replaceChild(compareNode, node);
                                    }
                                    scheduleStickToBottom(true);
                                    return compareNode;
                                }
                                let targetNode = node;
                                if (node.classList.contains('chatgpt-msg--compare')) {
                                    const replacement = buildTextMessage({
                                        role: 'assistant',
                                        mode: mode,
                                        text: textValue || fallbackText,
                                        messageId: messageId,
                                        streaming: streaming,
                                    });
                                    if (node.parentNode) {
                                        node.parentNode.replaceChild(replacement, node);
                                    }
                                    targetNode = replacement;
                                } else {
                                    targetNode.classList.toggle('is-streaming', streaming);
                                    if (messageId) {
                                        targetNode.dataset.messageId = messageId;
                                    }
                                    const meta = targetNode.querySelector('.chatgpt-msg__meta');
                                    if (meta) {
                                        meta.textContent = 'assistant · ' + mode;
                                    }
                                    const textEl = targetNode.querySelector('.chatgpt-msg__text');
                                    if (textEl) {
                                        textEl.textContent = textValue || fallbackText;
                                    }
                                }
                                scheduleStickToBottom(true);
                                return targetNode;
                            };

                            const setComposerBusy = (busy) => {
                                submitBusy = busy;
                                if (sendButton) {
                                    sendButton.disabled = busy;
                                }
                            };

                            const updateModalBodyState = () => {
                                const anyOpen = (opsBackdrop && !opsBackdrop.hidden)
                                    || (historyBackdrop && !historyBackdrop.hidden)
                                    || (moreBackdrop && !moreBackdrop.hidden);
                                document.body.classList.toggle('chatgpt-modal-open', Boolean(anyOpen));
                            };

                            const openOpsModal = () => {
                                if (!opsBackdrop) {
                                    return;
                                }
                                opsBackdrop.hidden = false;
                                if (accountTrigger) {
                                    accountTrigger.setAttribute('aria-expanded', 'true');
                                }
                                updateModalBodyState();
                            };

                            const closeOpsModal = () => {
                                if (!opsBackdrop) {
                                    return;
                                }
                                opsBackdrop.hidden = true;
                                if (accountTrigger) {
                                    accountTrigger.setAttribute('aria-expanded', 'false');
                                }
                                updateModalBodyState();
                            };

                            const openHistoryModal = () => {
                                if (!historyBackdrop) {
                                    return;
                                }
                                historyBackdrop.hidden = false;
                                updateModalBodyState();
                                if (historySearchInput) {
                                    historySearchInput.focus();
                                }
                            };

                            const closeHistoryModal = () => {
                                if (!historyBackdrop) {
                                    return;
                                }
                                historyBackdrop.hidden = true;
                                updateModalBodyState();
                            };

                            const openMoreModal = () => {
                                if (!moreBackdrop) {
                                    return;
                                }
                                moreBackdrop.hidden = false;
                                updateModalBodyState();
                            };

                            const closeMoreModal = () => {
                                if (!moreBackdrop) {
                                    return;
                                }
                                moreBackdrop.hidden = true;
                                updateModalBodyState();
                            };

                            if (accountTrigger) {
                                accountTrigger.addEventListener('click', () => {
                                    openOpsModal();
                                });
                            }

                            if (opsClose) {
                                opsClose.addEventListener('click', () => {
                                    closeOpsModal();
                                });
                            }

                            if (opsBackdrop) {
                                opsBackdrop.addEventListener('click', (event) => {
                                    if (event.target === opsBackdrop) {
                                        closeOpsModal();
                                    }
                                });
                            }

                            if (historyOpenBtn) {
                                historyOpenBtn.addEventListener('click', () => {
                                    openHistoryModal();
                                });
                            }

                            if (historyClose) {
                                historyClose.addEventListener('click', () => {
                                    closeHistoryModal();
                                });
                            }

                            if (historyBackdrop) {
                                historyBackdrop.addEventListener('click', (event) => {
                                    if (event.target === historyBackdrop) {
                                        closeHistoryModal();
                                    }
                                });
                            }

                            if (moreOpenBtn) {
                                moreOpenBtn.addEventListener('click', () => {
                                    openMoreModal();
                                });
                            }

                            if (moreClose) {
                                moreClose.addEventListener('click', () => {
                                    closeMoreModal();
                                });
                            }

                            if (moreBackdrop) {
                                moreBackdrop.addEventListener('click', (event) => {
                                    if (event.target === moreBackdrop) {
                                        closeMoreModal();
                                    }
                                });
                            }

                            if (newChatTrigger) {
                                newChatTrigger.addEventListener('click', () => {
                                    stopPolling();
                                    activeExchangeId = '';
                                    activeAssistantNode = null;
                                    setComposerBusy(false);
                                });
                            }

                            const syncThreadUrl = (threadId) => {
                                if (!threadId) {
                                    return;
                                }
                                if (modalThreadIdEl) {
                                    modalThreadIdEl.textContent = threadId;
                                }
                                try {
                                    const url = new URL(window.location.href);
                                    url.searchParams.set('view', 'chatgpt');
                                    url.searchParams.set('tab', 'session');
                                    if (assistantIdInput && assistantIdInput.value) {
                                        url.searchParams.set('assistant', assistantIdInput.value);
                                    }
                                    if (projectIdInput && projectIdInput.value) {
                                        url.searchParams.set('project', projectIdInput.value);
                                    }
                                    url.searchParams.set('thread', threadId);
                                    url.searchParams.delete('new_chat');
                                    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
                                } catch (e) {
                                    // ignore
                                }
                            };

                            const fetchJson = async (url, options) => {
                                const res = await fetch(url, options);
                                let data = null;
                                try {
                                    data = await res.json();
                                } catch (e) {
                                    data = null;
                                }
                                if (!res.ok || !data || data.ok === false) {
                                    const detail = data && data.detail ? String(data.detail) : ('HTTP_' + res.status);
                                    throw new Error(detail);
                                }
                                return data;
                            };

                            const stopSyncPolling = () => {
                                if (syncPollTimer) {
                                    clearTimeout(syncPollTimer);
                                    syncPollTimer = 0;
                                }
                            };

                            const pollSyncJob = (jobId) => {
                                if (!jobId) {
                                    return;
                                }
                                stopSyncPolling();
                                activeSyncJobId = jobId;
                                const actionLabelMap = {
                                    reading_visible: 'czyta listę widocznych rozmów',
                                    scrolling: 'przewija listę rozmów',
                                    waiting_lazy_load: 'czeka na dociągnięcie lazy-load',
                                    rechecking_visible: 'ponownie sprawdza listę',
                                    new_threads_detected: 'wykrył nowe rozmowy',
                                    new_threads_detected_after_wait: 'dociągnął nowe po odczekaniu',
                                    no_growth_cycle: 'brak nowych po pełnym cyklu',
                                    finished: 'zakończył skan',
                                };
                                const tick = async () => {
                                    if (activeSyncJobId !== jobId) {
                                        return;
                                    }
                                    try {
                                        const data = await fetchJson(
                                            '/?view=chatgpt&tab=session&ajax=chatgpt_sync_job_status&job_id=' + encodeURIComponent(jobId),
                                            { cache: 'no-store' }
                                        );
                                        const status = String(data.status || 'running');
                                        const phase = String(data.phase || '');
                                        const done = Number(data.progress_done || 0);
                                        const total = Number(data.progress_total || 0);
                                        setSyncProgress(done, total);

                                        if (status === 'queued' || status === 'running') {
                                            const phaseLabel = phase !== '' ? (' | etap: ' + phase) : '';
                                            const progressLabel = total > 0 ? (' | ' + done + '/' + total) : '';
                                            const runtime = (data.scan_runtime && typeof data.scan_runtime === 'object')
                                                ? data.scan_runtime
                                                : null;
                                            if (phase === 'scan_threads' && runtime) {
                                                const actionKey = String(runtime.action || '');
                                                const actionLabel = actionLabelMap[actionKey] || actionKey || 'skanuje';
                                                const found = Number(runtime.total_found || 0);
                                                const visible = Number(runtime.visible_count || 0);
                                                const added = Number(runtime.added || 0);
                                                const round = Number(runtime.round || 0);
                                                const maxRounds = Number(runtime.max_rounds || total || 0);
                                                const stableNow = Number(runtime.stable_rounds || 0);
                                                const stableTarget = Number(runtime.stable_target || 0);
                                                const scrollOps = Number(runtime.scroll_ops || 0);
                                                const scrollMoved = Number(runtime.scroll_moved_total || 0);
                                                const waitMs = Number(runtime.wait_ms || 0);
                                                const elapsedMs = Number(runtime.elapsed_ms || 0);

                                                const headline = 'Skan listy: ' + actionLabel
                                                    + ' | znalezione=' + found
                                                    + ' | widoczne=' + visible
                                                    + ' | nowe=' + added
                                                    + ' | przewinięcia=' + scrollOps;
                                                setHistorySyncStatus(headline, '');

                                                const live = [
                                                    'Job: ' + jobId,
                                                    'Status: ' + status,
                                                    'Etap: ' + phase,
                                                    'Akcja: ' + actionLabel,
                                                    'Iteracja: ' + round + (maxRounds > 0 ? ('/' + maxRounds) : ''),
                                                    'Znalezione łącznie: ' + found,
                                                    'Widzoczne obecnie: ' + visible,
                                                    'Nowe w aktualnym kroku: ' + added,
                                                    'Przewinięcia łącznie: ' + scrollOps,
                                                    'Ruch skuteczny scrolla: ' + scrollMoved,
                                                    'No-growth: ' + stableNow + '/' + stableTarget,
                                                    'Wait lazy-load (ms): ' + waitMs,
                                                    'Czas pracy (s): ' + (elapsedMs > 0 ? (elapsedMs / 1000).toFixed(1) : '0.0'),
                                                ].join('\n');
                                                setSyncLive(live);
                                            } else {
                                                setHistorySyncStatus('Synchronizacja trwa...' + phaseLabel + progressLabel, '');
                                            }
                                            syncPollTimer = window.setTimeout(tick, 900);
                                            return;
                                        }

                                        syncHistoryBusy = false;
                                        setSyncButtonsDisabled(false);
                                        activeSyncJobId = '';
                                        stopSyncPolling();

                                        if (status === 'completed' || status === 'completed_with_errors') {
                                            const result = (data.result && typeof data.result === 'object') ? data.result : {};
                                            const scanned = Number(result.scanned_threads || 0);
                                            const deletedThreads = Number(result.deleted_threads || 0);
                                            const inserted = Number(result.inserted_messages || 0);
                                            const updated = Number(result.updated_messages || 0);
                                            const deleted = Number(result.deleted_messages || 0);
                                            const atts = Number(result.attachments_inserted || 0);
                                            const scanEndReason = String(result.scan_end_reason || '');
                                            const scanStableRounds = Number(result.scan_stable_rounds || 0);
                                            const scanStableTarget = Number(result.scan_stable_target || 0);
                                            const scanScrollOps = Number(result.scan_scroll_ops || 0);
                                            const scanScrollMoved = Number(result.scan_scroll_moved_total || 0);
                                            const scanPart = scanEndReason !== ''
                                                ? (', scan=' + scanEndReason + ' (' + scanStableRounds + '/' + scanStableTarget + ')'
                                                    + ', scrolls=' + scanScrollOps + ', moved=' + scanScrollMoved)
                                                : '';
                                            const msg = 'Sync zakończony: threads=' + scanned
                                                + ', deleted_threads=' + deletedThreads
                                                + ', inserted=' + inserted
                                                + ', updated=' + updated
                                                + ', deleted=' + deleted
                                                + ', attachments=' + atts
                                                + scanPart
                                                + (status === 'completed_with_errors' ? ' (z błędami).' : '.');
                                            setHistorySyncStatus(msg, status === 'completed_with_errors' ? 'warn' : 'ok');
                                            setSyncLive([
                                                'Status końcowy: ' + status,
                                                'Threads: ' + scanned + ' | deleted_threads: ' + deletedThreads,
                                                'Messages: +' + inserted + ' / ~' + updated + ' / -' + deleted,
                                                'Attachments: ' + atts,
                                                'Skan end_reason: ' + (scanEndReason || '-'),
                                                'No-growth: ' + scanStableRounds + '/' + scanStableTarget,
                                                'Przewinięcia: ' + scanScrollOps + ' | moved: ' + scanScrollMoved,
                                            ].join('\n'));
                                            window.setTimeout(() => window.location.reload(), 900);
                                            return;
                                        }

                                        const detail = String(data.error || 'SYNC_JOB_FAILED');
                                        setHistorySyncStatus('Błąd synchronizacji: ' + detail, 'error');
                                        setSyncLive('Błąd synchronizacji:\n' + detail);
                                    } catch (error) {
                                        syncHistoryBusy = false;
                                        setSyncButtonsDisabled(false);
                                        activeSyncJobId = '';
                                        stopSyncPolling();
                                        const message = error instanceof Error ? error.message : 'SYNC_JOB_STATUS_FAILED';
                                        setHistorySyncStatus('Błąd odczytu statusu synchronizacji: ' + message, 'error');
                                        setSyncLive('Błąd odczytu statusu synchronizacji:\n' + message);
                                    }
                                };
                                tick();
                            };

                            const startSyncJob = async (kind) => {
                                if (!kind || syncHistoryBusy) {
                                    return;
                                }
                                syncHistoryBusy = true;
                                setSyncButtonsDisabled(true);
                                setSyncProgress(0, 0);
                                setHistorySyncStatus('Uruchamiam zadanie synchronizacji (' + kind + ')...', '');
                                setSyncLive('Inicjalizacja joba: ' + kind);

                                try {
                                    const form = new FormData();
                                    form.set('sync_kind', String(kind));
                                    if (projectIdInput && projectIdInput.value) {
                                        form.set('project_id', String(projectIdInput.value));
                                    }
                                    if (assistantIdInput && assistantIdInput.value) {
                                        form.set('assistant_id', String(assistantIdInput.value));
                                    }
                                    if (modeInput && modeInput.value) {
                                        form.set('mode', String(modeInput.value));
                                    }
                                    form.set('mirror_delete_local', '1');
                                    form.set('max_rounds', '12000');
                                    form.set('max_threads', '20000');
                                    const started = await fetchJson('/?view=chatgpt&tab=session&ajax=chatgpt_sync_start', {
                                        method: 'POST',
                                        body: form,
                                        cache: 'no-store',
                                    });
                                    const jobId = String(started.job_id || '');
                                    if (!jobId) {
                                        throw new Error('SYNC_JOB_ID_MISSING');
                                    }
                                    setHistorySyncStatus('Synchronizacja wystartowała. Job: ' + jobId, '');
                                    pollSyncJob(jobId);
                                } catch (error) {
                                    syncHistoryBusy = false;
                                    setSyncButtonsDisabled(false);
                                    const message = error instanceof Error ? error.message : 'SYNC_START_FAILED';
                                    setHistorySyncStatus('Nie udało się uruchomić synchronizacji: ' + message, 'error');
                                }
                            };

                            if (syncThreadsBtn) {
                                syncThreadsBtn.addEventListener('click', () => {
                                    startSyncJob('threads_scan');
                                });
                            }
                            if (syncMessagesBtn) {
                                syncMessagesBtn.addEventListener('click', () => {
                                    startSyncJob('messages_pull');
                                });
                            }
                            if (syncFullBtn) {
                                syncFullBtn.addEventListener('click', () => {
                                    startSyncJob('full_sync');
                                });
                            }

                            const stopPolling = () => {
                                if (exchangePollTimer) {
                                    clearTimeout(exchangePollTimer);
                                    exchangePollTimer = 0;
                                }
                            };

                            const pollExchangeStatus = (exchangeId) => {
                                if (!exchangeId) {
                                    return;
                                }
                                stopPolling();
                                activeExchangeId = exchangeId;
                                const tick = async () => {
                                    if (activeExchangeId !== exchangeId) {
                                        return;
                                    }
                                    try {
                                        const data = await fetchJson(
                                            '/?view=chatgpt&tab=session&ajax=chatgpt_exchange_status&exchange_id=' + encodeURIComponent(exchangeId),
                                            { cache: 'no-store' }
                                        );
                                        const assistantMessage = data.assistant_message && typeof data.assistant_message === 'object'
                                            ? data.assistant_message
                                            : null;
                                        const exchangeMeta = data.exchange && typeof data.exchange === 'object'
                                            ? data.exchange
                                            : (
                                                assistantMessage
                                                && assistantMessage.metadata_json
                                                && typeof assistantMessage.metadata_json === 'object'
                                                && assistantMessage.metadata_json.exchange
                                                && typeof assistantMessage.metadata_json.exchange === 'object'
                                                    ? assistantMessage.metadata_json.exchange
                                                    : {}
                                            );
                                        const status = String(data.status || 'running');
                                        activeAssistantNode = updateAssistantNode(activeAssistantNode, {
                                            messageId: assistantMessage ? String(assistantMessage.message_id || '') : '',
                                            mode: assistantMessage ? String(assistantMessage.mode || (modeInput ? modeInput.value : 'default')) : (modeInput ? modeInput.value : 'default'),
                                            text: assistantMessage ? String(assistantMessage.content_text || '') : String(data.assistant_text || ''),
                                            status: status,
                                            comparisonOptions: exchangeMeta && exchangeMeta.comparison_options ? exchangeMeta.comparison_options : [],
                                            selectedIndex: Number.isInteger(exchangeMeta && exchangeMeta.comparison_selected_index)
                                                ? exchangeMeta.comparison_selected_index
                                                : -1,
                                        });

                                        if (status === 'completed') {
                                            activeExchangeId = '';
                                            activeAssistantNode = null;
                                            setComposerBusy(false);
                                            if (composerInput) {
                                                composerInput.focus();
                                            }
                                            return;
                                        }
                                        if (status === 'failed') {
                                            throw new Error(String(data.error || 'EXCHANGE_FAILED'));
                                        }
                                        exchangePollTimer = window.setTimeout(tick, 700);
                                    } catch (error) {
                                        const message = error instanceof Error ? error.message : 'EXCHANGE_FAILED';
                                        activeAssistantNode = updateAssistantNode(activeAssistantNode, {
                                            mode: modeInput ? modeInput.value : 'default',
                                            text: 'Błąd wymiany z ChatGPT: ' + message,
                                            status: 'failed',
                                            comparisonOptions: [],
                                            selectedIndex: -1,
                                        });
                                        activeExchangeId = '';
                                        activeAssistantNode = null;
                                        setComposerBusy(false);
                                    }
                                };
                                tick();
                            };

                            bindThreadScroll();
                            revealLatest();

                            if (composer && composerInput) {
                                const autoSize = () => {
                                    composerInput.style.height = 'auto';
                                    composerInput.style.height = Math.min(composerInput.scrollHeight, 144) + 'px';
                                };
                                composerInput.addEventListener('focus', () => composer.classList.add('is-focused'));
                                composerInput.addEventListener('blur', () => composer.classList.remove('is-focused'));
                                composerInput.addEventListener('input', autoSize);
                                composerInput.addEventListener('keydown', (event) => {
                                    if (event.key === 'Enter' && !event.shiftKey && composerForm) {
                                        event.preventDefault();
                                        composerForm.requestSubmit();
                                    }
                                });
                                autoSize();
                                if (composerForm) {
                                    composerForm.addEventListener('submit', async (event) => {
                                        event.preventDefault();
                                        if (submitBusy) {
                                            return;
                                        }
                                        const prompt = String(composerInput.value || '').trim();
                                        if (prompt === '') {
                                            return;
                                        }
                                        const threadTitle = prompt.length > 72 ? prompt.slice(0, 72).trim() : prompt;
                                        if (threadTitleInput && String(threadTitleInput.value || '').trim() === '' && threadTitle !== '') {
                                            threadTitleInput.value = threadTitle;
                                        }
                                        const mode = modeInput ? String(modeInput.value || 'default') : 'default';
                                        const userNode = buildTextMessage({
                                            role: 'user',
                                            mode: mode,
                                            text: prompt,
                                            streaming: false,
                                        });
                                        appendToThread(userNode);
                                        composerInput.value = '';
                                        autoSize();
                                        setComposerBusy(true);

                                        const waitingNode = buildTextMessage({
                                            role: 'assistant',
                                            mode: mode,
                                            text: '...',
                                            streaming: true,
                                        });
                                        activeAssistantNode = appendToThread(waitingNode);

                                        const payload = new FormData(composerForm);
                                        payload.set('chatgpt_prompt', prompt);
                                        try {
                                            const started = await fetchJson('/?view=chatgpt&tab=session&ajax=chatgpt_exchange_start', {
                                                method: 'POST',
                                                body: payload,
                                                cache: 'no-store',
                                            });
                                            const nextThreadId = String(started.thread_id || '');
                                            if (threadIdInput && nextThreadId !== '') {
                                                threadIdInput.value = nextThreadId;
                                            }
                                            ensureThreadHeader(nextThreadId);
                                            syncThreadUrl(nextThreadId);
                                            if (threadTitleInput && started.thread_title) {
                                                threadTitleInput.value = String(started.thread_title);
                                            }
                                            if (started.exchange_id) {
                                                pollExchangeStatus(String(started.exchange_id));
                                            } else {
                                                throw new Error('EXCHANGE_ID_MISSING');
                                            }
                                        } catch (error) {
                                            const message = error instanceof Error ? error.message : 'EXCHANGE_START_FAILED';
                                            activeAssistantNode = updateAssistantNode(activeAssistantNode, {
                                                mode: mode,
                                                text: 'Błąd uruchomienia wymiany: ' + message,
                                                status: 'failed',
                                                comparisonOptions: [],
                                                selectedIndex: -1,
                                            });
                                            activeAssistantNode = null;
                                            setComposerBusy(false);
                                        }
                                    });
                                }
                            }

                            const modeLabel = {
                                attach_files: 'załączniki',
                                google_drive: 'dysk zewnętrzny',
                                image: 'tworzenie obrazów',
                                deep_research: 'głębokie badanie',
                                shopping: 'asystent zakupowy',
                                web_search: 'wyszukiwanie w sieci',
                                learn: 'learning',
                                agent: 'tryb agenta',
                                canvas: 'kanwa',
                                photoshop: 'integracja Photoshop',
                                network: 'network solutions',
                                quiz: 'quiz',
                                discover: 'odkrywanie aplikacji'
                            };
                            const toolModeMap = {
                                attach_files: 'default',
                                google_drive: 'default',
                                image: 'image',
                                deep_research: 'deep_research',
                                shopping: 'shopping',
                                web_search: 'web_search',
                                learn: 'learn',
                                agent: 'agent',
                                canvas: 'canvas',
                                photoshop: 'integration',
                                network: 'integration',
                                quiz: 'quiz',
                                discover: 'apps',
                            };
                            let lastComposerMode = modeInput ? String(modeInput.value || 'default') : 'default';

                            const emitTelemetry = (eventType, payload = {}) => {
                                if (!eventType) {
                                    return;
                                }
                                const form = new FormData();
                                form.set('event_type', String(eventType));
                                if (threadIdInput && threadIdInput.value) {
                                    form.set('thread_id', String(threadIdInput.value));
                                }
                                if (assistantIdInput && assistantIdInput.value) {
                                    form.set('assistant_id', String(assistantIdInput.value));
                                }
                                if (projectIdInput && projectIdInput.value) {
                                    form.set('project_id', String(projectIdInput.value));
                                }
                                Object.keys(payload).forEach((key) => {
                                    const val = payload[key];
                                    if (val === null || val === undefined) {
                                        return;
                                    }
                                    form.set(String(key), String(val));
                                });
                                fetch('/?view=chatgpt&tab=session&ajax=chatgpt_telemetry', {
                                    method: 'POST',
                                    body: form,
                                    cache: 'no-store',
                                }).catch(() => {
                                    // telemetry failures are non-blocking for chat UX
                                });
                            };

                            const closeTools = () => {
                                if (tools) tools.hidden = true;
                                if (submenu) submenu.hidden = true;
                            };

                            if (comparisonPicker && comparisonInput) {
                                const prefButtons = comparisonPicker.querySelectorAll('[data-pref]');
                                const setPreference = (pref) => {
                                    const next = pref === 'second' ? 'second' : 'first';
                                    comparisonInput.value = next;
                                    prefButtons.forEach((btn) => {
                                        const val = btn.getAttribute('data-pref');
                                        btn.classList.toggle('is-active', val === next);
                                    });
                                };
                                prefButtons.forEach((btn) => {
                                    btn.addEventListener('click', () => {
                                        const pref = btn.getAttribute('data-pref') || 'first';
                                        setPreference(pref);
                                    });
                                });
                                setPreference(comparisonInput.value || 'first');
                                applyComparisonPreference = setPreference;
                            }

                            bindComparisonButtons(shell);

                            if (toolsToggle && tools) {
                                toolsToggle.addEventListener('click', (event) => {
                                    event.stopPropagation();
                                    const next = tools.hidden;
                                    tools.hidden = !next;
                                    if (!next && submenu) submenu.hidden = true;
                                });
                            }

                            if (toolsMore && submenu) {
                                const openSubmenu = () => {
                                    submenu.hidden = false;
                                };
                                toolsMore.addEventListener('mouseenter', openSubmenu);
                                toolsMore.addEventListener('click', (event) => {
                                    event.stopPropagation();
                                    submenu.hidden = !submenu.hidden;
                                });
                            }

                            shell.querySelectorAll('[data-tool]').forEach((btn) => {
                                btn.addEventListener('click', (event) => {
                                    event.stopPropagation();
                                    const tool = btn.getAttribute('data-tool') || 'standard';
                                    const mappedMode = toolModeMap[tool] || 'default';
                                    const modeFrom = modeInput ? String(modeInput.value || lastComposerMode || 'default') : lastComposerMode;
                                    if (modePill) {
                                        modePill.textContent = 'Tryb: ' + (modeLabel[tool] || tool);
                                    }
                                    if (modeInput) {
                                        modeInput.value = mappedMode;
                                    }
                                    emitTelemetry('tool_selected', {
                                        tool_id: tool,
                                        mode: mappedMode,
                                        mode_from: modeFrom,
                                    });
                                    if (modeFrom !== mappedMode) {
                                        emitTelemetry('composer_mode_changed', {
                                            mode_from: modeFrom,
                                            mode_to: mappedMode,
                                            tool_id: tool,
                                        });
                                    }
                                    lastComposerMode = mappedMode;
                                    if (tool === 'attach_files' && fileInput) {
                                        fileInput.click();
                                    }
                                    closeTools();
                                });
                            });

                            if (fileInput && attachments) {
                                fileInput.addEventListener('change', () => {
                                    const files = Array.from(fileInput.files || []);
                                    attachments.innerHTML = '';
                                    if (files.length === 0) {
                                        attachments.hidden = true;
                                        return;
                                    }
                                    files.forEach((file) => {
                                        const chip = document.createElement('span');
                                        chip.className = 'chatgpt-chip';
                                        chip.textContent = file.name;
                                        attachments.appendChild(chip);
                                    });
                                    attachments.hidden = false;
                                });
                            }

                            document.addEventListener('click', (event) => {
                                if (!tools || tools.hidden) return;
                                if (tools.contains(event.target) || (toolsToggle && toolsToggle.contains(event.target))) return;
                                closeTools();
                            });

                            document.addEventListener('keydown', (event) => {
                                if (event.key === 'Escape') {
                                    closeTools();
                                    closeOpsModal();
                                    closeHistoryModal();
                                    closeMoreModal();
                                }
                            });

                            const stateEl = document.getElementById('cgpt-state');
                            const hintEl = document.getElementById('cgpt-hint');
                            let pollAuthStatus = null;
                            if (stateEl && hintEl) {
                                let last = stateEl.textContent || '';
                                const toAuthLabel = (state) => {
                                    if (state === 'AUTH_OK') return 'OK';
                                    if (state === 'LOGIN_RUNNING') return 'LOGIN';
                                    return 'REQUIRED';
                                };
                                pollAuthStatus = async () => {
                                    try {
                                        const res = await fetch('/?view=chatgpt&tab=session&ajax=chatgpt_auth', { cache: 'no-store' });
                                        if (!res.ok) return;
                                        const data = await res.json();
                                        const state = data && data.state ? String(data.state) : 'AUTH_UNKNOWN';
                                        const sessionId = data && data.login_session_id ? String(data.login_session_id) : '';
                                        const hasSession = sessionId !== '';
                                        const novncUrl = data && data.novnc_url ? String(data.novnc_url) : '';
                                        stateEl.textContent = state;
                                        if (modalSessionIdEl) {
                                            modalSessionIdEl.textContent = hasSession ? sessionId : '-';
                                        }
                                        if (modalProjectIdEl && projectIdInput) {
                                            modalProjectIdEl.textContent = String(projectIdInput.value || '-');
                                        }
                                        if (modalNovncLink) {
                                            if (novncUrl !== '') {
                                                modalNovncLink.href = novncUrl;
                                                modalNovncLink.hidden = false;
                                            } else {
                                                modalNovncLink.hidden = true;
                                            }
                                        }
                                        if (topAuthPill) {
                                            topAuthPill.classList.toggle('ok', state === 'AUTH_OK');
                                            topAuthPill.classList.toggle('running', state !== 'AUTH_OK');
                                            topAuthPill.textContent = 'ChatGPT: ' + toAuthLabel(state);
                                        }
                                        if (modalHeartbeatEl) {
                                            modalHeartbeatEl.textContent = new Date().toISOString();
                                        }
                                        if (modalLastErrorEl) {
                                            modalLastErrorEl.textContent = '-';
                                        }

                                        if (state === 'AUTH_OK') {
                                            hintEl.textContent = hasSession
                                                ? 'Zalogowano. Zamknij okno logowania, żeby zwolnić profil.'
                                                : 'Sesja aktywna.';
                                        } else if (state === 'LOGIN_RUNNING') {
                                            hintEl.textContent = 'Okno logowania działa. Dokończ logowanie w noVNC.';
                                        } else {
                                            hintEl.textContent = 'Sesja nieaktywna. Uruchom okno logowania.';
                                        }

                                        if (state !== last) {
                                            last = state;
                                            if (state === 'AUTH_OK') {
                                                setTimeout(() => window.location.reload(), 800);
                                            }
                                        }
                                    } catch (e) {
                                        if (modalLastErrorEl) {
                                            modalLastErrorEl.textContent = 'AUTH_POLL_FAILED';
                                        }
                                    }
                                };
                                setInterval(pollAuthStatus, 2500);
                                pollAuthStatus();
                            }

                            if (refreshStatusBtn) {
                                refreshStatusBtn.addEventListener('click', () => {
                                    if (typeof pollAuthStatus === 'function') {
                                        pollAuthStatus();
                                    }
                                });
                            }
                        })();
                    </script>
                <?php endif; ?>

                <?php if ($view === 'runs'): ?>
                    <section class="panel" aria-label="Run history">
                        <h5 style="margin-top:0;">Historia uruchomień</h5>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tryb</th>
                                    <th>Status</th>
                                    <th>Start</th>
                                    <th>Koniec</th>
                                    <th>Nowe</th>
                                    <th>Seen</th>
                                    <th>Błąd</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($latestRuns as $run): ?>
                                    <tr>
                                        <td><?= (int)$run['id'] ?></td>
                                        <td><?= h($run['mode']) ?></td>
                                        <td><?= h($run['status']) ?></td>
                                        <td><?= h(format_dt($run['started_at'])) ?></td>
                                        <td><?= h(format_dt($run['finished_at'])) ?></td>
                                        <td><?= (int)$run['new_posts'] ?></td>
                                        <td><?= (int)$run['total_seen'] ?></td>
                                        <td><?= h(short_text($run['error_message'] ?? '', 80)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/foundation-sites@6.8.1/dist/js/foundation.min.js"></script>
<script>
    $(document).foundation();
</script>
</body>
</html>
