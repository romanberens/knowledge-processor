<?php

declare(strict_types=1);

function chatgpt_ajax_dispatch(): void
{
    $ajax = trim((string)($_GET['ajax'] ?? ''));
    if ($ajax === '') {
        return;
    }

    // Routing contract for module AJAX handlers.
    // Source of truth is routes/api.php -> ChatApiController::routeMap().
    $routes = chatgpt_module_api_routes();
    if (!isset($routes[$ajax])) {
        return;
    }

    ChatApiController::handle($ajax);
}
