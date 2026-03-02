<?php

declare(strict_types=1);

require_once __DIR__ . '/manifest.php';
require_once __DIR__ . '/http/ajax.php';

function chatgpt_module_manifest(): array
{
    return chatgpt_manifest();
}

function chatgpt_module_catalog(): array
{
    return [
        'models' => [
            ['id' => 'chatgpt-5.2', 'name' => 'ChatGPT 5.2', 'icon' => 'C5'],
            ['id' => 'react-roadmap', 'name' => 'React Roadmap', 'icon' => 'RR'],
            ['id' => 'linux-server', 'name' => 'Linux Server Expert', 'icon' => 'LX'],
            ['id' => 'nodejs-copilot', 'name' => 'NodeJS Copilot', 'icon' => 'NJ'],
        ],
        'projects' => [
            ['id' => 'lab-onenetworks', 'name' => 'lab.onenetworks.pl'],
            ['id' => 'ai-elinfost', 'name' => 'ai.elinfost.pl'],
            ['id' => 'elektrykzmianowy', 'name' => 'elektrykzmianowy.pl'],
            ['id' => 'wirtualnaredakcja', 'name' => 'wirtualnaredakcja.pl'],
        ],
        'groups' => [
            ['id' => 'speech-support', 'name' => 'Trudności w mowie dziecka'],
            ['id' => 'ur-procedury', 'name' => 'UR - procedury serwisowe'],
        ],
    ];
}

function chatgpt_module_handle_ajax_request(): void
{
    chatgpt_ajax_dispatch();
}
