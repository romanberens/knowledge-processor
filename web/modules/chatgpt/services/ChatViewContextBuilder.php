<?php

declare(strict_types=1);

final class ChatViewContextBuilder
{
    public static function build(string $view, string $chatgptTab, array $query, array $session): array
    {
        $chatgptGatewayState = chatgpt_status();
        $chatgptGatewayOk = (bool)($chatgptGatewayState['ok'] ?? false);

        $chatgptLoginSessionId = (string)($session['cgpt_login_session_id'] ?? '');
        $chatgptNovncUrl = (string)($session['cgpt_novnc_url'] ?? '');
        $chatgptAuthInfo = $chatgptLoginSessionId !== ''
            ? chatgpt_auth_login_status($chatgptLoginSessionId)
            : chatgpt_auth_status();

        $chatgptAuthState = (string)($chatgptAuthInfo['state'] ?? 'AUTH_UNKNOWN');
        $chatgptEffectiveSessionId = $chatgptLoginSessionId !== ''
            ? $chatgptLoginSessionId
            : (string)($chatgptAuthInfo['login_session_id'] ?? '');
        $chatgptEffectiveNovncUrl = $chatgptNovncUrl !== ''
            ? $chatgptNovncUrl
            : (string)($chatgptAuthInfo['novnc_url'] ?? '');
        $chatgptHasLoginSession = $chatgptEffectiveSessionId !== '';

        $chatgptAssistantId = trim((string)($query['assistant'] ?? 'chatgpt-5.2'));
        $chatgptProjectId = trim((string)($query['project'] ?? 'lab-onenetworks'));
        $chatgptThreadId = trim((string)($query['thread'] ?? ''));
        $chatgptNewChat = ((string)($query['new_chat'] ?? '') === '1');

        $chatgptCatalog = chatgpt_module_catalog();
        $chatgptModels = is_array($chatgptCatalog['models'] ?? null) ? $chatgptCatalog['models'] : [];
        $chatgptProjects = is_array($chatgptCatalog['projects'] ?? null) ? $chatgptCatalog['projects'] : [];
        $chatgptGroups = is_array($chatgptCatalog['groups'] ?? null) ? $chatgptCatalog['groups'] : [];

        if (self::hasIds($chatgptModels) && !in_array($chatgptAssistantId, array_column($chatgptModels, 'id'), true)) {
            $chatgptAssistantId = (string)($chatgptModels[0]['id'] ?? $chatgptAssistantId);
        }
        if (self::hasIds($chatgptProjects) && !in_array($chatgptProjectId, array_column($chatgptProjects, 'id'), true)) {
            $chatgptProjectId = (string)($chatgptProjects[0]['id'] ?? $chatgptProjectId);
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

        return compact(
            'chatgptGatewayState',
            'chatgptGatewayOk',
            'chatgptLoginSessionId',
            'chatgptNovncUrl',
            'chatgptAuthInfo',
            'chatgptAuthState',
            'chatgptEffectiveSessionId',
            'chatgptEffectiveNovncUrl',
            'chatgptHasLoginSession',
            'chatgptAssistantId',
            'chatgptProjectId',
            'chatgptThreadId',
            'chatgptNewChat',
            'chatgptCatalog',
            'chatgptModels',
            'chatgptProjects',
            'chatgptGroups',
            'chatgptSchema',
            'chatgptThreadIndex',
            'chatgptThreads',
            'chatgptThreadsRecent',
            'chatgptMessagesPayload',
            'chatgptMessages'
        );
    }

    private static function hasIds(array $rows): bool
    {
        if (!$rows) {
            return false;
        }

        return isset($rows[0]) && is_array($rows[0]) && array_key_exists('id', $rows[0]);
    }
}
