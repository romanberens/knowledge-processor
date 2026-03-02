<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/repository.php';
require_once __DIR__ . '/includes/scraper_api.php';
require_once __DIR__ . '/includes/chatgpt_api.php';
require_once __DIR__ . '/includes/strapi_api.php';
require_once __DIR__ . '/modules/chatgpt/module.php';

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

// [REF-MOD-CHATGPT]
// ChatGPT AJAX surface was extracted to web/modules/chatgpt/http/ajax.php.
chatgpt_module_handle_ajax_request();


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

// [REF-MOD-CHATGPT]
// ChatGPT view context is prepared in module service layer.
$chatgptContext = chatgpt_module_build_view_context($view, $chatgptTab, $_GET, $_SESSION);
$chatgptGatewayState = $chatgptContext['chatgptGatewayState'];
$chatgptGatewayOk = $chatgptContext['chatgptGatewayOk'];
$chatgptLoginSessionId = $chatgptContext['chatgptLoginSessionId'];
$chatgptNovncUrl = $chatgptContext['chatgptNovncUrl'];
$chatgptAuthInfo = $chatgptContext['chatgptAuthInfo'];
$chatgptAuthState = $chatgptContext['chatgptAuthState'];
$chatgptEffectiveSessionId = $chatgptContext['chatgptEffectiveSessionId'];
$chatgptEffectiveNovncUrl = $chatgptContext['chatgptEffectiveNovncUrl'];
$chatgptHasLoginSession = $chatgptContext['chatgptHasLoginSession'];
$chatgptAssistantId = $chatgptContext['chatgptAssistantId'];
$chatgptProjectId = $chatgptContext['chatgptProjectId'];
$chatgptThreadId = $chatgptContext['chatgptThreadId'];
$chatgptNewChat = $chatgptContext['chatgptNewChat'];
$chatgptCatalog = $chatgptContext['chatgptCatalog'];
$chatgptModels = $chatgptContext['chatgptModels'];
$chatgptProjects = $chatgptContext['chatgptProjects'];
$chatgptGroups = $chatgptContext['chatgptGroups'];
$chatgptSchema = $chatgptContext['chatgptSchema'];
$chatgptThreadIndex = $chatgptContext['chatgptThreadIndex'];
$chatgptThreads = $chatgptContext['chatgptThreads'];
$chatgptThreadsRecent = $chatgptContext['chatgptThreadsRecent'];
$chatgptMessagesPayload = $chatgptContext['chatgptMessagesPayload'];
$chatgptMessages = $chatgptContext['chatgptMessages'];

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

	    </style>
        <?php if ($view === 'chatgpt'): ?>
            <link rel="stylesheet" href="/modules/chatgpt/assets/css/chatgpt.module.css?v=1">
        <?php endif; ?>
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

                <?php
                // [REF-MOD-CHATGPT]
                // ChatGPT session UI/runtime extracted from monolithic index.php.
                require __DIR__ . '/modules/chatgpt/views/session.php';
                ?>

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
