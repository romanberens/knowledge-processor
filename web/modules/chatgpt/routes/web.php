<?php

declare(strict_types=1);

/**
 * Returns module-scoped SSR route map.
 */
function chatgpt_module_web_routes(): array
{
    return [
        'chatgpt_session_mount' => [ChatController::class, 'renderSession'],
    ];
}
