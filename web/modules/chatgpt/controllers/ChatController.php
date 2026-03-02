<?php

declare(strict_types=1);

final class ChatController
{
    /**
     * Renders ChatGPT session/status module view with explicit context map.
     */
    public static function renderSession(array $context): void
    {
        // Strict DTO bridge between core shell and module view.
        // Only whitelisted keys are exposed to the template.
        $viewModel = self::buildViewModel($context);
        extract($viewModel, EXTR_SKIP);
        require __DIR__ . '/../views/session.php';
    }

    private static function buildViewModel(array $context): array
    {
        return [
            'view' => trim((string)($context['view'] ?? '')),
            'chatgptTab' => self::normalizeTab((string)($context['chatgptTab'] ?? 'session')),
            'chatgptGatewayState' => self::normalizeArray($context['chatgptGatewayState'] ?? []),
            'chatgptSchema' => self::normalizeArray($context['chatgptSchema'] ?? ['ok' => false]),
            'chatgptModels' => self::normalizeArray($context['chatgptModels'] ?? []),
            'chatgptProjects' => self::normalizeArray($context['chatgptProjects'] ?? []),
            'chatgptGroups' => self::normalizeArray($context['chatgptGroups'] ?? []),
            'chatgptThreads' => self::normalizeArray($context['chatgptThreads'] ?? []),
            'chatgptThreadsRecent' => self::normalizeArray($context['chatgptThreadsRecent'] ?? []),
            'chatgptMessages' => self::normalizeArray($context['chatgptMessages'] ?? []),
            'chatgptThreadIndex' => self::normalizeArray($context['chatgptThreadIndex'] ?? ['ok' => false]),
            'chatgptAssistantId' => trim((string)($context['chatgptAssistantId'] ?? 'chatgpt-5.2')),
            'chatgptProjectId' => trim((string)($context['chatgptProjectId'] ?? '')),
            'chatgptThreadId' => trim((string)($context['chatgptThreadId'] ?? '')),
            'chatgptAuthState' => trim((string)($context['chatgptAuthState'] ?? 'AUTH_UNKNOWN')),
            'chatgptEffectiveSessionId' => trim((string)($context['chatgptEffectiveSessionId'] ?? '')),
            'chatgptEffectiveNovncUrl' => trim((string)($context['chatgptEffectiveNovncUrl'] ?? '')),
            'chatgptGatewayOk' => (bool)($context['chatgptGatewayOk'] ?? false),
            'chatgptHasLoginSession' => (bool)($context['chatgptHasLoginSession'] ?? false),
            'chatgptNewChat' => (bool)($context['chatgptNewChat'] ?? false),
        ];
    }

    private static function normalizeTab(string $tab): string
    {
        if (!in_array($tab, ['session', 'status'], true)) {
            return 'session';
        }

        return $tab;
    }

    private static function normalizeArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
