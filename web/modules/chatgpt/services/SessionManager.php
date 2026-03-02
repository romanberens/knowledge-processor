<?php

declare(strict_types=1);

final class SessionManager
{
    public static function normalizeComparisonPreference(string $raw): string
    {
        $value = trim($raw);
        if (!in_array($value, ['first', 'second'], true)) {
            return 'first';
        }

        return $value;
    }

    public static function deriveThreadTitle(string $title, string $prompt): string
    {
        $resolved = trim($title);
        if ($resolved === '') {
            if (function_exists('mb_substr')) {
                $resolved = trim((string)mb_substr($prompt, 0, 72));
            } else {
                $resolved = trim((string)substr($prompt, 0, 72));
            }
        }

        if ($resolved === '') {
            return 'Nowy wątek';
        }

        return $resolved;
    }

    public static function buildThreadPayload(
        string $threadTitle,
        string $assistantId,
        string $projectId,
        string $threadId
    ): array {
        $payload = [
            'title' => $threadTitle,
            'project_id' => $projectId !== '' ? $projectId : null,
            'assistant_id' => $assistantId !== '' ? $assistantId : null,
            'metadata' => [
                'source' => 'web-panel',
            ],
        ];

        if ($threadId !== '') {
            $payload['thread_id'] = $threadId;
        }

        return $payload;
    }

    public static function buildExchangePayload(
        string $prompt,
        string $mode,
        string $comparisonPreference,
        string $assistantId,
        string $projectId
    ): array {
        $resolvedMode = $mode !== '' ? $mode : 'default';

        return [
            'prompt' => $prompt,
            'mode' => $resolvedMode,
            'source' => 'web_panel',
            'comparison_preference' => $comparisonPreference,
            'metadata' => [
                'composer_mode' => $resolvedMode,
            ],
            'assistant_metadata' => [
                'assistant_id' => $assistantId,
                'project_id' => $projectId,
            ],
        ];
    }

    public static function buildSyncPayload(array $input): array
    {
        $mirrorDelete = strtolower((string)($input['mirror_delete_local'] ?? '1'));
        $maxRoundsRaw = trim((string)($input['max_rounds'] ?? '4000'));
        $maxThreadsRaw = trim((string)($input['max_threads'] ?? '5000'));
        $maxRounds = is_numeric($maxRoundsRaw) ? (int)$maxRoundsRaw : 4000;
        $maxThreads = is_numeric($maxThreadsRaw) ? (int)$maxThreadsRaw : 5000;
        $mode = trim((string)($input['mode'] ?? 'default'));
        $projectId = trim((string)($input['project_id'] ?? ''));
        $assistantId = trim((string)($input['assistant_id'] ?? ''));

        return [
            'project_id' => $projectId !== '' ? $projectId : null,
            'assistant_id' => $assistantId !== '' ? $assistantId : null,
            'mode' => $mode !== '' ? $mode : 'default',
            'source' => 'web_panel_sync_job',
            'mirror_delete_local' => !in_array($mirrorDelete, ['0', 'false', 'off', 'no'], true),
            'max_rounds' => max(8, min(20000, $maxRounds)),
            'max_threads' => max(1, min(20000, $maxThreads)),
        ];
    }

    public static function buildSyncHistoryPayload(array $input): array
    {
        $payload = [
            'conversation_url' => trim((string)($input['conversation_url'] ?? '')),
            'mode' => trim((string)($input['mode'] ?? 'default')),
            'source' => 'web_panel_sync',
        ];
        $payload = array_filter($payload, static fn($value): bool => $value !== '');

        if (!isset($payload['mode'])) {
            $payload['mode'] = 'default';
        }

        return $payload;
    }

    public static function buildTelemetryPayload(array $input): array
    {
        $payload = [
            'tool_id' => trim((string)($input['tool_id'] ?? '')),
            'mode' => trim((string)($input['mode'] ?? '')),
            'mode_from' => trim((string)($input['mode_from'] ?? '')),
            'mode_to' => trim((string)($input['mode_to'] ?? '')),
            'assistant_id' => trim((string)($input['assistant_id'] ?? '')),
            'project_id' => trim((string)($input['project_id'] ?? '')),
            'source' => trim((string)($input['source'] ?? 'web_panel')),
            'ts' => gmdate('c'),
        ];

        return array_filter($payload, static fn($value): bool => $value !== '');
    }
}
