<?php

declare(strict_types=1);

final class ChatController
{
    /**
     * Renders ChatGPT session/status module view with explicit context map.
     */
    public static function renderSession(array $context): void
    {
        extract($context, EXTR_SKIP);
        require __DIR__ . '/../views/session.php';
    }
}
