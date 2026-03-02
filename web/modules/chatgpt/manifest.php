<?php

declare(strict_types=1);

function chatgpt_manifest(): array
{
    return [
        'id' => 'chatgpt',
        'name' => 'ChatGPT',
        'version' => '0.1.0',
        'entry_route' => '/?view=chatgpt&tab=session',
        'description' => 'ChatGPT local session overlay module',
    ];
}
