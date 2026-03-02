<?php

declare(strict_types=1);

/**
 * Returns module-scoped AJAX route map for ChatGPT API handlers.
 */
function chatgpt_module_api_routes(): array
{
    return ChatApiController::routeMap();
}
