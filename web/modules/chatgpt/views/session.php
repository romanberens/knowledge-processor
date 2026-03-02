                <?php if ($view === 'chatgpt'): ?>
                    <?php
                        $cgptLatest = is_array($chatgptGatewayState['latest_session'] ?? null)
                            ? $chatgptGatewayState['latest_session']
                            : null;
                        $cgptEvents = is_array($chatgptGatewayState['recent_events'] ?? null)
                            ? $chatgptGatewayState['recent_events']
                            : [];
                        $chatgptContractOk = !empty($chatgptSchema['ok']);
                        $chatgptSchemaVersion = $chatgptContractOk ? (string)($chatgptSchema['version'] ?? '1.0') : 'n/a';
                        $chatgptSelectedModelName = 'ChatGPT 5.2';
                        foreach ($chatgptModels as $m) {
                            if ((string)$m['id'] === $chatgptAssistantId) {
                                $chatgptSelectedModelName = (string)$m['name'];
                                break;
                            }
                        }
                        $chatgptSelectedProjectName = '';
                        foreach ($chatgptProjects as $p) {
                            if ((string)$p['id'] === $chatgptProjectId) {
                                $chatgptSelectedProjectName = (string)$p['name'];
                                break;
                            }
                        }
                        $chatgptSelectedThreadName = '';
                        foreach ($chatgptThreads as $th) {
                            if ((string)$th['id'] === $chatgptThreadId) {
                                $chatgptSelectedThreadName = (string)$th['name'];
                                break;
                            }
                        }
                    ?>
                    <div class="chatgpt-shell" id="chatgpt-shell">
                        <aside class="chatgpt-rail" aria-label="ChatGPT Sidebar">
                            <div class="chatgpt-rail__inner">
                                <div class="chatgpt-brand">
                                    <span class="chatgpt-brand__icon">ON</span>
                                    <span>ChatGPT Local</span>
                                </div>

                                <div class="chatgpt-quick">
                                    <a
                                        id="chatgpt-new-chat-trigger"
                                        class="primary"
                                        href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'new_chat' => '1'], '', '&', PHP_QUERY_RFC3986)) ?>"
                                    >+ Nowy czat</a>
                                    <button type="button" id="chatgpt-more-open">Więcej</button>
                                </div>

                                <section class="chatgpt-group">
                                    <button type="button" class="chatgpt-group__toggle" data-collapse-target="cgpt-models" aria-expanded="true">
                                        <span>Modele GPT</span><span class="chatgpt-group__chevron">▸</span>
                                    </button>
                                    <div class="chatgpt-group__body" id="cgpt-models">
                                        <?php foreach ($chatgptModels as $m): ?>
                                            <a
                                                class="chatgpt-link <?= (string)$m['id'] === $chatgptAssistantId ? 'is-active' : '' ?>"
                                                href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => $chatgptTab, 'assistant' => $m['id'], 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId], '', '&', PHP_QUERY_RFC3986)) ?>"
                                            >
                                                <span class="chatgpt-link__icon"><?= h((string)$m['icon']) ?></span>
                                                <span class="chatgpt-link__label"><?= h((string)$m['name']) ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </section>

                                <section class="chatgpt-group">
                                    <button type="button" class="chatgpt-group__toggle" data-collapse-target="cgpt-projects" aria-expanded="true">
                                        <span>Projekty</span><span class="chatgpt-group__chevron">▸</span>
                                    </button>
                                    <div class="chatgpt-group__body" id="cgpt-projects">
                                        <?php foreach ($chatgptProjects as $p): ?>
                                            <a
                                                class="chatgpt-link <?= (string)$p['id'] === $chatgptProjectId ? 'is-active' : '' ?>"
                                                href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => $chatgptTab, 'assistant' => $chatgptAssistantId, 'project' => $p['id'], 'thread' => $chatgptThreadId], '', '&', PHP_QUERY_RFC3986)) ?>"
                                            >
                                                <span class="chatgpt-link__icon">PR</span>
                                                <span class="chatgpt-link__label"><?= h((string)$p['name']) ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </section>

                                <section class="chatgpt-group">
                                    <button type="button" class="chatgpt-group__toggle" data-collapse-target="cgpt-groups" aria-expanded="false">
                                        <span>Czaty grupowe</span><span class="chatgpt-group__chevron">▸</span>
                                    </button>
                                    <div class="chatgpt-group__body" id="cgpt-groups" hidden>
                                        <?php foreach ($chatgptGroups as $g): ?>
                                            <a class="chatgpt-link" href="#">
                                                <span class="chatgpt-link__icon">GR</span>
                                                <span class="chatgpt-link__label"><?= h((string)$g['name']) ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                        <a class="chatgpt-link" href="#">
                                            <span class="chatgpt-link__icon">+</span>
                                            <span class="chatgpt-link__label">Nowy czat grupowy</span>
                                        </a>
                                    </div>
                                </section>

                                <section class="chatgpt-group">
                                    <div class="chatgpt-group__head">
                                        <button type="button" class="chatgpt-group__toggle" data-collapse-target="cgpt-history" aria-expanded="false" id="chatgpt-history-toggle">
                                            <span>Twoje czaty</span><span class="chatgpt-group__chevron">▸</span>
                                        </button>
                                        <button type="button" class="chatgpt-group__history-btn" id="chatgpt-history-open">Historia</button>
                                    </div>
                                    <div class="chatgpt-group__body" id="cgpt-history" hidden>
                                        <div class="chatgpt-history-list" id="chatgpt-history-list-recent">
                                            <?php if (!$chatgptThreadsRecent): ?>
                                                <p class="chatgpt-history-empty">Brak rozmów dla wybranego projektu i modelu.</p>
                                            <?php else: ?>
                                                <?php foreach ($chatgptThreadsRecent as $th): ?>
                                                    <a
                                                        class="chatgpt-link <?= (string)$th['id'] === $chatgptThreadId ? 'is-active' : '' ?>"
                                                        href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $th['id']], '', '&', PHP_QUERY_RFC3986)) ?>"
                                                        data-chat-title="<?= h(strtolower((string)$th['name'])) ?>"
                                                        data-thread-id="<?= h((string)$th['id']) ?>"
                                                    >
                                                        <span class="chatgpt-link__icon">CH</span>
                                                        <span class="chatgpt-link__label"><?= h((string)$th['name']) ?></span>
                                                        <span class="chatgpt-link__more">...</span>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </section>

                                <button
                                    type="button"
                                    class="chatgpt-account"
                                    id="chatgpt-account-trigger"
                                    title="Panel systemu"
                                    aria-haspopup="dialog"
                                    aria-controls="chatgpt-ops-backdrop"
                                    aria-expanded="false"
                                >
                                    <span class="chatgpt-account__avatar">R</span>
                                    <span class="chatgpt-account__meta">
                                        <strong>Roman Ber</strong>
                                        <span class="chatgpt-account__plan">Plan: Plus</span>
                                    </span>
                                    <span class="chatgpt-account__gear">SYS</span>
                                </button>
                            </div>
                        </aside>

                        <section class="chatgpt-stage" aria-label="ChatGPT Main">
                            <div class="chatgpt-stage__inner">
                                <header class="chatgpt-stage-top">
                                    <div class="chatgpt-stage-top__left">
                                        <button class="chatgpt-model-btn" type="button"><?= h($chatgptSelectedModelName) ?> ▾</button>
                                        <?php if ($chatgptSelectedProjectName !== ''): ?>
                                            <span class="status-pill">Projekt: <?= h($chatgptSelectedProjectName) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="chatgpt-stage-icons" aria-label="Skróty">
                                        <span>S</span>
                                        <span>U</span>
                                    </div>
                                </header>

                                <?php if ($chatgptTab === 'session'): ?>
                                    <section class="chatgpt-home" aria-label="Welcome">
                                        <h3><?= $chatgptNewChat ? 'Co dzisiaj w programie?' : 'Cześć, Roman. Zaczynamy?' ?></h3>
                                        <p>
                                            Kontekst: <strong><?= h($chatgptSelectedModelName) ?></strong>
                                            <?php if ($chatgptSelectedProjectName !== ''): ?>
                                                | projekt <strong><?= h($chatgptSelectedProjectName) ?></strong>
                                            <?php endif; ?>
                                        </p>
                                    </section>

                                    <?php if ($chatgptThreadId !== ''): ?>
                                        <section class="chatgpt-thread-panel" aria-label="Wątek wiadomości">
                                            <h6>Wątek: <code><?= h($chatgptThreadId) ?></code></h6>
                                            <div class="chatgpt-thread-log" id="chatgpt-thread-log">
                                                <?php if (!$chatgptMessages): ?>
                                                    <div class="chatgpt-msg">
                                                        <span class="chatgpt-msg__meta">system</span>
                                                        <p class="chatgpt-msg__text">Brak wiadomości w tym wątku.</p>
                                                    </div>
                                                <?php else: ?>
                                                    <?php foreach ($chatgptMessages as $msg): ?>
                                                        <?php
                                                            $msgRole = trim((string)($msg['role'] ?? 'unknown'));
                                                            $msgText = trim((string)($msg['content_text'] ?? ''));
                                                            $msgClass = $msgRole === 'assistant' ? 'chatgpt-msg chatgpt-msg--assistant' : 'chatgpt-msg chatgpt-msg--user';
                                                            $msgMeta = is_array($msg['metadata_json'] ?? null) ? $msg['metadata_json'] : [];
                                                            $exchangeMeta = is_array($msgMeta['exchange'] ?? null) ? $msgMeta['exchange'] : [];
                                                            $comparisonRaw = is_array($exchangeMeta['comparison_options'] ?? null) ? $exchangeMeta['comparison_options'] : [];
                                                            $msgAttachmentsRaw = is_array($msg['attachments'] ?? null) ? $msg['attachments'] : [];
                                                            $msgAttachments = [];
                                                            foreach ($msgAttachmentsRaw as $attRaw) {
                                                                if (!is_array($attRaw)) {
                                                                    continue;
                                                                }
                                                                $attMeta = is_array($attRaw['metadata_json'] ?? null) ? $attRaw['metadata_json'] : [];
                                                                $previewKind = trim((string)($attMeta['preview_kind'] ?? ''));
                                                                if ($previewKind === '') {
                                                                    $previewKind = trim((string)($attRaw['mime_type'] ?? ''));
                                                                    if ($previewKind !== '') {
                                                                        if (str_starts_with($previewKind, 'image/')) {
                                                                            $previewKind = 'image';
                                                                        } elseif (str_starts_with($previewKind, 'video/')) {
                                                                            $previewKind = 'video';
                                                                        } elseif (str_starts_with($previewKind, 'audio/')) {
                                                                            $previewKind = 'audio';
                                                                        } elseif ($previewKind === 'application/pdf') {
                                                                            $previewKind = 'pdf';
                                                                        } else {
                                                                            $previewKind = 'file';
                                                                        }
                                                                    }
                                                                }
                                                                if ($previewKind === '') {
                                                                    $previewKind = 'file';
                                                                }
                                                                $storageRef = trim((string)($attRaw['storage_ref'] ?? ''));
                                                                if ($storageRef === '') {
                                                                    continue;
                                                                }
                                                                $msgAttachments[] = [
                                                                    'name' => trim((string)($attRaw['file_name'] ?? '')) ?: 'attachment',
                                                                    'url' => $storageRef,
                                                                    'kind' => $previewKind,
                                                                ];
                                                            }
                                                            $comparisonOptions = [];
                                                            foreach ($comparisonRaw as $optRaw) {
                                                                if (!is_array($optRaw)) {
                                                                    continue;
                                                                }
                                                                $optText = trim((string)($optRaw['text'] ?? ''));
                                                                if ($optText === '') {
                                                                    continue;
                                                                }
                                                                $optLabel = trim((string)($optRaw['label'] ?? ''));
                                                                $optIndex = is_numeric($optRaw['index'] ?? null)
                                                                    ? (int)$optRaw['index']
                                                                    : count($comparisonOptions);
                                                                if ($optLabel === '') {
                                                                    $optLabel = 'Odpowiedź ' . ($optIndex + 1);
                                                                }
                                                                $comparisonOptions[] = [
                                                                    'index' => $optIndex,
                                                                    'label' => $optLabel,
                                                                    'text' => $optText,
                                                                ];
                                                            }
                                                            $comparisonSelectedIndex = is_numeric($exchangeMeta['comparison_selected_index'] ?? null)
                                                                ? (int)$exchangeMeta['comparison_selected_index']
                                                                : -1;
                                                            $hasComparison = $msgRole === 'assistant' && count($comparisonOptions) >= 2;
                                                        ?>
                                                        <?php $articleClass = $msgClass . ($hasComparison ? ' chatgpt-msg--compare' : ''); ?>
                                                        <article class="<?= h($articleClass) ?>" data-message-id="<?= h((string)($msg['message_id'] ?? '')) ?>">
                                                            <?php if (!$hasComparison): ?>
                                                                <span class="chatgpt-msg__meta"><?= h($msgRole) ?> · <?= h((string)($msg['mode'] ?? 'default')) ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($hasComparison): ?>
                                                                <div class="chatgpt-compare">
                                                                    <p class="chatgpt-compare__title">Przekazujesz opinię na temat nowej wersji ChatGPT.</p>
                                                                    <p class="chatgpt-compare__subtitle">Którą odpowiedź wybierasz? Wczytywanie odpowiedzi może chwilę potrwać.</p>
                                                                    <div class="chatgpt-compare__grid">
                                                                        <?php foreach ($comparisonOptions as $opt): ?>
                                                                            <?php $isSelected = (int)$opt['index'] === $comparisonSelectedIndex; ?>
                                                                            <?php $prefChoice = ((int)$opt['index'] === 1) ? 'second' : 'first'; ?>
                                                                            <article class="chatgpt-compare-card <?= $isSelected ? 'is-selected' : '' ?>">
                                                                                <span class="chatgpt-compare-card__label"><?= h((string)$opt['label']) ?></span>
                                                                                <p class="chatgpt-compare-card__text"><?= h((string)$opt['text']) ?></p>
                                                                                <button
                                                                                    type="button"
                                                                                    class="chatgpt-compare-card__btn chatgpt-compare-select <?= $isSelected ? 'is-selected' : '' ?>"
                                                                                    data-comparison-choice="<?= h($prefChoice) ?>"
                                                                                >
                                                                                    Wolę tę odpowiedź<?= $isSelected ? ' ✓' : '' ?>
                                                                                </button>
                                                                            </article>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                            <?php else: ?>
                                                                <p class="chatgpt-msg__text"><?= h($msgText !== '' ? $msgText : '[pusta wiadomość]') ?></p>
                                                            <?php endif; ?>
                                                            <?php if ($msgAttachments): ?>
                                                                <div class="chatgpt-msg__attachments">
                                                                    <?php foreach ($msgAttachments as $att): ?>
                                                                        <?php
                                                                            $attName = (string)($att['name'] ?? 'attachment');
                                                                            $attUrl = (string)($att['url'] ?? '');
                                                                            $attKind = (string)($att['kind'] ?? 'file');
                                                                        ?>
                                                                        <article class="chatgpt-msg-attachment">
                                                                            <span class="chatgpt-msg-attachment__name"><?= h($attName) ?> · <?= h($attKind) ?></span>
                                                                            <?php if ($attKind === 'image'): ?>
                                                                                <img class="chatgpt-msg-attachment__preview" src="<?= h($attUrl) ?>" alt="<?= h($attName) ?>" loading="lazy">
                                                                            <?php elseif ($attKind === 'video'): ?>
                                                                                <video class="chatgpt-msg-attachment__preview" controls preload="metadata" src="<?= h($attUrl) ?>"></video>
                                                                            <?php elseif ($attKind === 'audio'): ?>
                                                                                <audio class="chatgpt-msg-attachment__preview" controls preload="none" src="<?= h($attUrl) ?>"></audio>
                                                                            <?php endif; ?>
                                                                            <a class="chatgpt-msg-attachment__link" href="<?= h($attUrl) ?>" target="_blank" rel="noopener">Otwórz plik</a>
                                                                        </article>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </article>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </section>
                                    <?php endif; ?>

                                    <form method="post" id="chatgpt-send-form">
                                        <input type="hidden" name="action" value="chatgpt_send_message">
                                        <input type="hidden" name="chatgpt_assistant_id" value="<?= h($chatgptAssistantId) ?>">
                                        <input type="hidden" name="chatgpt_project_id" value="<?= h($chatgptProjectId) ?>">
                                        <input type="hidden" name="chatgpt_thread_id" value="<?= h($chatgptThreadId) ?>">
                                        <input type="hidden" name="chatgpt_thread_title" value="<?= h($chatgptNewChat ? '' : ($chatgptSelectedThreadName !== '' ? $chatgptSelectedThreadName : 'Nowy wątek')) ?>">
                                        <input type="hidden" name="chatgpt_mode" id="chatgpt-composer-mode" value="default">
                                        <input type="hidden" name="chatgpt_comparison_preference" id="chatgpt-comparison-preference" value="first">

                                        <section class="chatgpt-composer-wrap" aria-label="Composer">
                                            <div class="chatgpt-attachments" id="chatgpt-attachments" hidden></div>
                                            <div class="chatgpt-composer" id="chatgpt-composer">
                                                <button type="button" id="chatgpt-tools-toggle" class="chatgpt-plus-btn" aria-label="Narzędzia">+</button>
                                                <textarea
                                                    id="chatgpt-composer-input"
                                                    name="chatgpt_prompt"
                                                    rows="1"
                                                    placeholder="Zapytaj o cokolwiek"
                                                    aria-label="Wiadomość"
                                                    required
                                                ></textarea>
                                                <div class="chatgpt-composer-actions">
                                                    <button type="button" class="chatgpt-icon-btn" aria-label="Mikrofon">Mic</button>
                                                    <button type="button" class="chatgpt-icon-btn voice" aria-label="Tryb głosowy">V</button>
                                                    <button type="submit" class="chatgpt-icon-btn" aria-label="Wyślij">Send</button>
                                                </div>
                                            </div>
                                            <div class="chatgpt-mode-pill" id="chatgpt-mode-pill">Tryb: standard</div>
                                            <div class="chatgpt-comparison-picker" id="chatgpt-comparison-picker" aria-label="Preferencja wyboru odpowiedzi">
                                                <span>Wybór odpowiedzi:</span>
                                                <button type="button" class="chatgpt-comparison-picker__btn is-active" data-pref="first">1</button>
                                                <button type="button" class="chatgpt-comparison-picker__btn" data-pref="second">2</button>
                                            </div>

                                            <div class="chatgpt-tools" id="chatgpt-tools" hidden>
                                                <div class="chatgpt-tools__menu">
                                                    <button type="button" class="chatgpt-tools__item" data-tool="attach_files">Dodaj zdjęcia i pliki</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="google_drive">Dodaj z Google Drive</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="image">Stwórz obraz</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="deep_research">Głębokie badanie</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="shopping">Asystent zakupowy</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="web_search">Wyszukiwanie w sieci</button>
                                                    <button type="button" class="chatgpt-tools__item" id="chatgpt-tools-more" data-open-submenu="more">Więcej <span>▸</span></button>
                                                </div>
                                                <div class="chatgpt-tools__submenu" id="chatgpt-tools-submenu" hidden>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="learn">Ucz się i przyswajaj wiedzę</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="agent">Tryb agenta</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="canvas">Kanwa</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="photoshop">Adobe Photoshop</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="network">Network Solutions</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="quiz">Quizy</button>
                                                    <button type="button" class="chatgpt-tools__item" data-tool="discover">Odkryj aplikacje</button>
                                                </div>
                                            </div>
                                            <input type="file" id="chatgpt-file-input" hidden multiple>
                                        </section>
                                    </form>

                                <?php endif; ?>

                                <?php if ($chatgptTab === 'status'): ?>
                                    <section class="panel chatgpt-session-panel" aria-label="ChatGPT gateway status">
                                        <h5 style="margin-top:0; color:#f4f9ff;">Status gatewaya i sesji</h5>
                                        <p style="color:#abc0d5;">
                                            Diagnostyka warstwy sesyjnej: stan auth, ostatnie sesje i log zdarzeń.
                                        </p>

                                        <div class="chatgpt-status-grid" aria-label="Status gateway">
                                            <div class="chatgpt-status-card">
                                                <div class="kpi-title">Gateway</div>
                                                <div class="kpi-value"><?= $chatgptGatewayOk ? 'OK' : 'DOWN' ?></div>
                                            </div>
                                            <div class="chatgpt-status-card">
                                                <div class="kpi-title">Auth State</div>
                                                <div class="kpi-value"><?= h($chatgptAuthState) ?></div>
                                            </div>
                                            <div class="chatgpt-status-card">
                                                <div class="kpi-title">API Base</div>
                                                <div style="font-size:0.88rem; color:#b8cde2;"><code><?= h(chatgpt_session_api_base()) ?></code></div>
                                            </div>
                                            <div class="chatgpt-status-card">
                                                <div class="kpi-title">Contract /v1</div>
                                                <div class="kpi-value"><?= (!empty($chatgptSchema['ok']) ? h((string)($chatgptSchema['version'] ?? '1.0')) : 'DOWN') ?></div>
                                            </div>
                                        </div>

                                        <div class="callout">
                                            <div class="kpi-title" style="margin-bottom:0.4rem;">Model wymiany danych</div>
                                            <?php if (empty($chatgptSchema['ok'])): ?>
                                                <div style="color:#b8cde2;">Brak odpowiedzi z <code>/v1/schema</code>.</div>
                                            <?php else: ?>
                                                <div>
                                                    encje:
                                                    <code><?= h(implode(', ', (array)($chatgptSchema['entities'] ?? []))) ?></code>
                                                </div>
                                                <?php
                                                    $threadItems = is_array($chatgptThreadIndex['body']['items'] ?? null)
                                                        ? $chatgptThreadIndex['body']['items']
                                                        : [];
                                                    $threadCount = is_int($chatgptThreadIndex['body']['count'] ?? null)
                                                        ? (int)$chatgptThreadIndex['body']['count']
                                                        : count($threadItems);
                                                ?>
                                                <div style="margin-top:0.25rem;">
                                                    wątki (filtr projektu/modelu): <strong><?= (int)$threadCount ?></strong>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="callout">
                                            <div class="kpi-title" style="margin-bottom:0.4rem;">Ostatnia sesja</div>
                                            <?php if (!$cgptLatest): ?>
                                                <div style="color:#b8cde2;">Brak sesji w gatewayu.</div>
                                            <?php else: ?>
                                                <div><strong>Session ID:</strong> <code><?= h((string)($cgptLatest['session_id'] ?? '-')) ?></code></div>
                                                <div><strong>Status:</strong> <?= h((string)($cgptLatest['state'] ?? '-')) ?></div>
                                                <div><strong>Start:</strong> <?= h((string)($cgptLatest['started_at'] ?? '-')) ?></div>
                                                <div><strong>Stop:</strong> <?= h((string)($cgptLatest['stopped_at'] ?? '-')) ?></div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="table-wrap chatgpt-event-table">
                                            <table>
                                                <thead>
                                                <tr>
                                                    <th>Time</th>
                                                    <th>Session</th>
                                                    <th>Event</th>
                                                </tr>
                                                </thead>
                                                <tbody>
                                                <?php if (count($cgptEvents) === 0): ?>
                                                    <tr><td colspan="3" style="color:#b8cde2;">Brak zdarzeń.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach ($cgptEvents as $evt): ?>
                                                        <tr>
                                                            <td><?= h((string)($evt['created_at'] ?? '-')) ?></td>
                                                            <td><code><?= h((string)($evt['session_id'] ?? '-')) ?></code></td>
                                                            <td><?= h((string)($evt['event_type'] ?? '-')) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                    <div class="chatgpt-modal-backdrop" id="chatgpt-ops-backdrop" hidden>
                        <section class="chatgpt-modal" id="chatgpt-ops-modal" role="dialog" aria-modal="true" aria-labelledby="chatgpt-ops-title">
                            <header class="chatgpt-modal__header">
                                <h5 class="chatgpt-modal__title" id="chatgpt-ops-title">Panel Sesji i Systemu</h5>
                                <button type="button" class="chatgpt-modal__close" id="chatgpt-ops-close" aria-label="Zamknij panel">×</button>
                            </header>
                            <div class="chatgpt-modal__body">
                                <section class="chatgpt-modal__section" aria-label="Konto">
                                    <h6>Konto</h6>
                                    <div class="chatgpt-modal__kv">
                                        <div>Użytkownik: <strong>Roman Ber</strong></div>
                                        <div>Plan: <strong>Plus</strong></div>
                                    </div>
                                </section>

                                <section class="chatgpt-modal__section" aria-label="Status systemu">
                                    <h6>Status systemu</h6>
                                    <div class="chatgpt-modal__kv">
                                        <div>Gateway: <strong id="cgpt-modal-gateway"><?= $chatgptGatewayOk ? 'OK' : 'DOWN' ?></strong></div>
                                        <div>Model: <strong id="cgpt-modal-contract"><?= $chatgptContractOk ? ('OK v' . h($chatgptSchemaVersion)) : 'DOWN' ?></strong></div>
                                        <div>Sesja: <strong id="cgpt-state"><?= h($chatgptAuthState) ?></strong></div>
                                        <div>Thread ID: <code id="cgpt-modal-thread-id"><?= h($chatgptThreadId !== '' ? $chatgptThreadId : 'new_chat') ?></code></div>
                                        <div>Project ID: <code id="cgpt-modal-project-id"><?= h($chatgptProjectId) ?></code></div>
                                        <div>Session ID: <code id="cgpt-modal-session-id"><?= h($chatgptEffectiveSessionId !== '' ? $chatgptEffectiveSessionId : '-') ?></code></div>
                                    </div>
                                    <p class="chatgpt-modal__hint" id="cgpt-hint">
                                        <?php if ($chatgptAuthState === 'AUTH_OK' && $chatgptHasLoginSession): ?>
                                            Zalogowano. Zamknij okno logowania, żeby zwolnić profil.
                                        <?php elseif ($chatgptAuthState === 'AUTH_OK'): ?>
                                            Sesja aktywna.
                                        <?php elseif ($chatgptAuthState === 'LOGIN_RUNNING'): ?>
                                            Okno logowania działa. Dokończ logowanie w noVNC.
                                        <?php else: ?>
                                            Sesja nieaktywna. Uruchom okno logowania.
                                        <?php endif; ?>
                                    </p>
                                </section>

                                <section class="chatgpt-modal__section" aria-label="Kontrola sesji">
                                    <h6>Kontrola sesji</h6>
                                    <div class="chatgpt-modal__actions">
                                        <?php if (!$chatgptHasLoginSession): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="start_chatgpt_login">
                                                <button type="submit" class="button primary">Uruchom okno logowania</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($chatgptHasLoginSession && $chatgptEffectiveNovncUrl !== ''): ?>
                                            <a
                                                class="button secondary"
                                                id="cgpt-modal-novnc-link"
                                                href="<?= h($chatgptEffectiveNovncUrl) ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >Otwórz noVNC</a>
                                        <?php else: ?>
                                            <a class="button secondary" id="cgpt-modal-novnc-link" href="#" target="_blank" rel="noopener" hidden>Otwórz noVNC</a>
                                        <?php endif; ?>
                                        <?php if ($chatgptHasLoginSession): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="stop_chatgpt_login">
                                                <input type="hidden" name="session_id" value="<?= h($chatgptEffectiveSessionId) ?>">
                                                <button type="submit" class="button hollow">Zamknij sesję</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post">
                                            <input type="hidden" name="action" value="reset_chatgpt_session">
                                            <button type="submit" class="button warning">Reset sesji</button>
                                        </form>
                                        <button type="button" class="button hollow" id="cgpt-refresh-status">Odśwież status</button>
                                    </div>
                                </section>

                                <section class="chatgpt-modal__section chatgpt-modal__diag" aria-label="Diagnostyka">
                                    <h6>Diagnostyka</h6>
                                    <details>
                                        <summary>Szczegóły systemowe</summary>
                                        <div class="chatgpt-modal__kv" style="margin-top:0.55rem;">
                                            <div>Gateway URL: <code><?= h(chatgpt_session_api_base()) ?></code></div>
                                            <div>Aktywny thread: <code><?= h($chatgptThreadId !== '' ? $chatgptThreadId : 'new_chat') ?></code></div>
                                            <div>Token usage: <code>n/a</code></div>
                                            <div>Ostatni błąd: <code id="cgpt-modal-last-error">-</code></div>
                                            <div>Heartbeat: <code id="cgpt-heartbeat"><?= h((string)gmdate('c')) ?></code></div>
                                        </div>
                                    </details>
                                    <?php if ($chatgptHasLoginSession && $chatgptEffectiveNovncUrl !== '' && $chatgptAuthState !== 'AUTH_OK'): ?>
                                        <iframe
                                            class="chatgpt-modal__iframe"
                                            title="noVNC ChatGPT Login"
                                            src="<?= h($chatgptEffectiveNovncUrl) ?>"
                                            allow="clipboard-read; clipboard-write"
                                        ></iframe>
                                    <?php endif; ?>
                                </section>
                            </div>
                        </section>
                    </div>
                    <div class="chatgpt-modal-backdrop" id="chatgpt-history-backdrop" hidden>
                        <section class="chatgpt-modal chatgpt-modal--narrow" role="dialog" aria-modal="true" aria-labelledby="chatgpt-history-title">
                            <header class="chatgpt-modal__header">
                                <h5 class="chatgpt-modal__title" id="chatgpt-history-title">Historia rozmów</h5>
                                <button type="button" class="chatgpt-modal__close" id="chatgpt-history-close" aria-label="Zamknij historię">×</button>
                            </header>
                            <div class="chatgpt-modal__body">
                                <section class="chatgpt-modal__section" aria-label="Lista wszystkich rozmów">
                                    <h6>Wszystkie rozmowy (<?= (int)count($chatgptThreads) ?>)</h6>
                                    <div class="chatgpt-history-actions">
                                        <button type="button" class="button secondary small" id="chatgpt-sync-threads">Skanuj listę wątków</button>
                                        <button type="button" class="button secondary small" id="chatgpt-sync-messages">Dociągnij komplet rozmów</button>
                                        <button type="button" class="button primary small" id="chatgpt-sync-full">Pełna synchronizacja</button>
                                        <span class="chatgpt-history-sync-status" id="chatgpt-sync-history-status" role="status" aria-live="polite"></span>
                                    </div>
                                    <div class="chatgpt-history-sync-progress" id="chatgpt-sync-history-progress" hidden>
                                        <div class="chatgpt-history-sync-progress__bar" id="chatgpt-sync-history-progress-bar"></div>
                                    </div>
                                    <pre class="chatgpt-history-sync-live" id="chatgpt-sync-history-live" hidden></pre>
                                    <input
                                        id="chatgpt-history-filter"
                                        class="chatgpt-history-search"
                                        type="search"
                                        placeholder="Szukaj po tytule rozmowy..."
                                        aria-label="Szukaj rozmów"
                                    >
                                    <div class="chatgpt-history-list" id="chatgpt-history-list-modal">
                                        <?php if (!$chatgptThreads): ?>
                                            <p class="chatgpt-history-empty">Brak rozmów do wyświetlenia.</p>
                                        <?php else: ?>
                                            <?php foreach ($chatgptThreads as $th): ?>
                                                <a
                                                    class="chatgpt-link <?= (string)$th['id'] === $chatgptThreadId ? 'is-active' : '' ?>"
                                                    href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $th['id']], '', '&', PHP_QUERY_RFC3986)) ?>"
                                                    data-chat-title="<?= h(strtolower((string)$th['name'])) ?>"
                                                    data-thread-id="<?= h((string)$th['id']) ?>"
                                                >
                                                    <span class="chatgpt-link__icon">CH</span>
                                                    <span class="chatgpt-link__label"><?= h((string)$th['name']) ?></span>
                                                    <span class="chatgpt-link__more">...</span>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </section>
                            </div>
                        </section>
                    </div>
                    <div class="chatgpt-modal-backdrop" id="chatgpt-more-backdrop" hidden>
                        <section class="chatgpt-modal chatgpt-modal--narrow" role="dialog" aria-modal="true" aria-labelledby="chatgpt-more-title">
                            <header class="chatgpt-modal__header">
                                <h5 class="chatgpt-modal__title" id="chatgpt-more-title">Więcej funkcji</h5>
                                <button type="button" class="chatgpt-modal__close" id="chatgpt-more-close" aria-label="Zamknij więcej">×</button>
                            </header>
                            <div class="chatgpt-modal__body">
                                <section class="chatgpt-modal__section" aria-label="Funkcje dodatkowe">
                                    <h6>Skróty narzędziowe</h6>
                                    <div class="chatgpt-history-list">
                                        <a class="chatgpt-link" href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId, 'mode' => 'image'], '', '&', PHP_QUERY_RFC3986)) ?>">
                                            <span class="chatgpt-link__icon">IM</span><span class="chatgpt-link__label">Obrazy</span>
                                        </a>
                                        <a class="chatgpt-link" href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId, 'mode' => 'apps'], '', '&', PHP_QUERY_RFC3986)) ?>">
                                            <span class="chatgpt-link__icon">AP</span><span class="chatgpt-link__label">Aplikacje</span>
                                        </a>
                                        <a class="chatgpt-link" href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId, 'mode' => 'deep_research'], '', '&', PHP_QUERY_RFC3986)) ?>">
                                            <span class="chatgpt-link__icon">DR</span><span class="chatgpt-link__label">Głębokie badanie</span>
                                        </a>
                                        <a class="chatgpt-link" href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'session', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId, 'mode' => 'codex'], '', '&', PHP_QUERY_RFC3986)) ?>">
                                            <span class="chatgpt-link__icon">CX</span><span class="chatgpt-link__label">Codex</span>
                                        </a>
                                        <a class="chatgpt-link" href="/?<?= h(http_build_query(['view' => 'chatgpt', 'tab' => 'status', 'assistant' => $chatgptAssistantId, 'project' => $chatgptProjectId, 'thread' => $chatgptThreadId], '', '&', PHP_QUERY_RFC3986)) ?>">
                                            <span class="chatgpt-link__icon">ST</span><span class="chatgpt-link__label">Status Gateway</span>
                                        </a>
                                    </div>
                                </section>
                            </div>
                        </section>
                    </div>

                    <script>
                        (function () {
                            const shell = document.getElementById('chatgpt-shell');
                            if (!shell) return;

                            const collapseButtons = shell.querySelectorAll('[data-collapse-target]');
                            collapseButtons.forEach((btn) => {
                                const targetId = btn.getAttribute('data-collapse-target');
                                const body = targetId ? document.getElementById(targetId) : null;
                                if (body) {
                                    const expandedAtInit = btn.getAttribute('aria-expanded') !== 'false';
                                    body.hidden = !expandedAtInit;
                                }
                                btn.addEventListener('click', () => {
                                    const targetId = btn.getAttribute('data-collapse-target');
                                    if (!targetId) return;
                                    const body = document.getElementById(targetId);
                                    if (!body) return;
                                    const expanded = btn.getAttribute('aria-expanded') !== 'false';
                                    btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                                    body.hidden = expanded;
                                });
                            });

                            const composer = document.getElementById('chatgpt-composer');
                            const composerInput = document.getElementById('chatgpt-composer-input');
                            const composerForm = document.getElementById('chatgpt-send-form');
                            const threadIdInput = composerForm ? composerForm.querySelector('input[name="chatgpt_thread_id"]') : null;
                            const threadTitleInput = composerForm ? composerForm.querySelector('input[name="chatgpt_thread_title"]') : null;
                            const assistantIdInput = composerForm ? composerForm.querySelector('input[name="chatgpt_assistant_id"]') : null;
                            const projectIdInput = composerForm ? composerForm.querySelector('input[name="chatgpt_project_id"]') : null;
                            const sendButton = composerForm ? composerForm.querySelector('button[type="submit"]') : null;
                            const toolsToggle = document.getElementById('chatgpt-tools-toggle');
                            const tools = document.getElementById('chatgpt-tools');
                            const toolsMore = document.getElementById('chatgpt-tools-more');
                            const submenu = document.getElementById('chatgpt-tools-submenu');
                            const modePill = document.getElementById('chatgpt-mode-pill');
                            const modeInput = document.getElementById('chatgpt-composer-mode');
                            const comparisonInput = document.getElementById('chatgpt-comparison-preference');
                            const comparisonPicker = document.getElementById('chatgpt-comparison-picker');
                            const fileInput = document.getElementById('chatgpt-file-input');
                            const attachments = document.getElementById('chatgpt-attachments');
                            const newChatTrigger = document.getElementById('chatgpt-new-chat-trigger');
                            const moreOpenBtn = document.getElementById('chatgpt-more-open');
                            const accountTrigger = document.getElementById('chatgpt-account-trigger');
                            const opsBackdrop = document.getElementById('chatgpt-ops-backdrop');
                            const opsClose = document.getElementById('chatgpt-ops-close');
                            const historyOpenBtn = document.getElementById('chatgpt-history-open');
                            const historyBackdrop = document.getElementById('chatgpt-history-backdrop');
                            const historyClose = document.getElementById('chatgpt-history-close');
                            const moreBackdrop = document.getElementById('chatgpt-more-backdrop');
                            const moreClose = document.getElementById('chatgpt-more-close');
                            const syncThreadsBtn = document.getElementById('chatgpt-sync-threads');
                            const syncMessagesBtn = document.getElementById('chatgpt-sync-messages');
                            const syncFullBtn = document.getElementById('chatgpt-sync-full');
                            const syncHistoryStatus = document.getElementById('chatgpt-sync-history-status');
                            const syncProgressWrap = document.getElementById('chatgpt-sync-history-progress');
                            const syncProgressBar = document.getElementById('chatgpt-sync-history-progress-bar');
                            const syncLive = document.getElementById('chatgpt-sync-history-live');
                            const refreshStatusBtn = document.getElementById('cgpt-refresh-status');
                            const topAuthPill = document.getElementById('chatgpt-top-auth-pill');
                            const historySearchInput = document.getElementById('chatgpt-history-filter');
                            const modalThreadIdEl = document.getElementById('cgpt-modal-thread-id');
                            const modalProjectIdEl = document.getElementById('cgpt-modal-project-id');
                            const modalSessionIdEl = document.getElementById('cgpt-modal-session-id');
                            const modalHeartbeatEl = document.getElementById('cgpt-heartbeat');
                            const modalNovncLink = document.getElementById('cgpt-modal-novnc-link');
                            const modalLastErrorEl = document.getElementById('cgpt-modal-last-error');

                            let threadLog = document.getElementById('chatgpt-thread-log');
                            let threadPanel = shell.querySelector('.chatgpt-thread-panel');
                            let threadCode = threadPanel ? threadPanel.querySelector('h6 code') : null;
                            let scrollFrame = 0;
                            let autoFollow = true;
                            let forceFollowUntil = Date.now() + 2200;
                            let submitBusy = false;
                            let syncHistoryBusy = false;
                            let activeSyncJobId = '';
                            let syncPollTimer = 0;
                            let activeExchangeId = '';
                            let activeAssistantNode = null;
                            let exchangePollTimer = 0;
                            let applyComparisonPreference = null;

                            if (historySearchInput) {
                                historySearchInput.addEventListener('input', () => {
                                    const q = historySearchInput.value.trim().toLowerCase();
                                    const rows = document.querySelectorAll('#chatgpt-history-list-modal [data-chat-title]');
                                    rows.forEach((row) => {
                                        const title = row.getAttribute('data-chat-title') || '';
                                        row.style.display = (q === '' || title.indexOf(q) !== -1) ? '' : 'none';
                                    });
                                });
                            }

                            const setHistorySyncStatus = (message, state = '') => {
                                if (!syncHistoryStatus) {
                                    return;
                                }
                                syncHistoryStatus.textContent = message || '';
                                if (state === '') {
                                    delete syncHistoryStatus.dataset.state;
                                } else {
                                    syncHistoryStatus.dataset.state = state;
                                }
                            };

                            const setSyncButtonsDisabled = (disabled) => {
                                [syncThreadsBtn, syncMessagesBtn, syncFullBtn].forEach((btn) => {
                                    if (btn) {
                                        btn.disabled = disabled;
                                    }
                                });
                            };

                            const setSyncProgress = (done, total) => {
                                if (!syncProgressWrap || !syncProgressBar) {
                                    return;
                                }
                                const t = Number(total || 0);
                                const d = Number(done || 0);
                                if (t <= 0) {
                                    syncProgressWrap.hidden = true;
                                    syncProgressBar.style.width = '0%';
                                    return;
                                }
                                const pct = Math.max(0, Math.min(100, Math.round((d / t) * 100)));
                                syncProgressWrap.hidden = false;
                                syncProgressBar.style.width = pct + '%';
                            };

                            const setSyncLive = (text) => {
                                if (!syncLive) {
                                    return;
                                }
                                const value = String(text || '').trim();
                                if (value === '') {
                                    syncLive.hidden = true;
                                    syncLive.textContent = '';
                                    return;
                                }
                                syncLive.hidden = false;
                                syncLive.textContent = value;
                            };

                            const nearBottom = () => {
                                if (!threadLog) {
                                    return true;
                                }
                                const delta = threadLog.scrollHeight - threadLog.scrollTop - threadLog.clientHeight;
                                return delta < 88;
                            };

                            const scheduleStickToBottom = (force = false) => {
                                if (!threadLog) {
                                    return;
                                }
                                if (!(force || autoFollow || Date.now() < forceFollowUntil)) {
                                    return;
                                }
                                if (scrollFrame !== 0) {
                                    return;
                                }
                                scrollFrame = window.requestAnimationFrame(() => {
                                    scrollFrame = 0;
                                    if (!threadLog) {
                                        return;
                                    }
                                    threadLog.scrollTop = threadLog.scrollHeight;
                                });
                            };

                            const bindThreadScroll = () => {
                                if (!threadLog) {
                                    return;
                                }
                                if (threadLog.dataset.scrollBound === '1') {
                                    return;
                                }
                                threadLog.dataset.scrollBound = '1';
                                threadLog.addEventListener('scroll', () => {
                                    autoFollow = nearBottom();
                                });
                            };

                            const revealLatest = () => {
                                bindThreadScroll();
                                autoFollow = true;
                                forceFollowUntil = Date.now() + 2500;
                                scheduleStickToBottom(true);
                                window.requestAnimationFrame(() => scheduleStickToBottom(true));
                                window.setTimeout(() => scheduleStickToBottom(true), 180);
                            };

                            const ensureThreadPanel = (threadId = '') => {
                                if (threadLog && threadPanel) {
                                    return threadLog;
                                }
                                if (!composerForm) {
                                    return null;
                                }
                                const stageInner = shell.querySelector('.chatgpt-stage__inner');
                                if (!stageInner) {
                                    return null;
                                }
                                threadPanel = document.createElement('section');
                                threadPanel.className = 'chatgpt-thread-panel';
                                threadPanel.setAttribute('aria-label', 'Wątek wiadomości');
                                const heading = document.createElement('h6');
                                heading.appendChild(document.createTextNode('Wątek: '));
                                threadCode = document.createElement('code');
                                threadCode.textContent = threadId || 'pending-thread';
                                heading.appendChild(threadCode);
                                threadPanel.appendChild(heading);
                                threadLog = document.createElement('div');
                                threadLog.className = 'chatgpt-thread-log';
                                threadLog.id = 'chatgpt-thread-log';
                                threadPanel.appendChild(threadLog);
                                stageInner.insertBefore(threadPanel, composerForm);
                                bindThreadScroll();
                                revealLatest();
                                return threadLog;
                            };

                            const ensureThreadHeader = (threadId) => {
                                if (!threadId) {
                                    return;
                                }
                                ensureThreadPanel(threadId);
                                if (threadCode) {
                                    threadCode.textContent = threadId;
                                }
                            };

                            const removeEmptyThreadPlaceholder = () => {
                                if (!threadLog) {
                                    return;
                                }
                                if (threadLog.children.length !== 1) {
                                    return;
                                }
                                const first = threadLog.firstElementChild;
                                if (!first || !first.classList.contains('chatgpt-msg')) {
                                    return;
                                }
                                const meta = first.querySelector('.chatgpt-msg__meta');
                                if (!meta || meta.textContent !== 'system') {
                                    return;
                                }
                                first.remove();
                            };

                            const normalizeComparisonOptions = (raw) => {
                                if (!Array.isArray(raw)) {
                                    return [];
                                }
                                const out = [];
                                raw.forEach((item, index) => {
                                    if (!item || typeof item !== 'object') {
                                        return;
                                    }
                                    const text = String(item.text || '').trim();
                                    if (text === '') {
                                        return;
                                    }
                                    const idx = Number.isInteger(item.index) ? Number(item.index) : index;
                                    const label = String(item.label || '').trim() || ('Odpowiedź ' + (idx + 1));
                                    out.push({ index: idx, label: label, text: text });
                                });
                                return out;
                            };

                            const buildTextMessage = ({ role, mode, text, messageId, streaming }) => {
                                const article = document.createElement('article');
                                article.className = 'chatgpt-msg ' + (role === 'assistant' ? 'chatgpt-msg--assistant' : 'chatgpt-msg--user');
                                if (streaming) {
                                    article.classList.add('is-streaming');
                                }
                                if (messageId) {
                                    article.dataset.messageId = messageId;
                                }
                                const meta = document.createElement('span');
                                meta.className = 'chatgpt-msg__meta';
                                meta.textContent = role + ' · ' + (mode || 'default');
                                article.appendChild(meta);
                                const paragraph = document.createElement('p');
                                paragraph.className = 'chatgpt-msg__text';
                                paragraph.textContent = text !== '' ? text : '[pusta wiadomość]';
                                article.appendChild(paragraph);
                                return article;
                            };

                            const applyChoiceVisualState = (button) => {
                                const grid = button.closest('.chatgpt-compare__grid');
                                if (!grid) {
                                    return;
                                }
                                grid.querySelectorAll('.chatgpt-compare-card').forEach((card) => {
                                    card.classList.remove('is-selected');
                                });
                                grid.querySelectorAll('.chatgpt-compare-card__btn').forEach((btn) => {
                                    btn.classList.remove('is-selected');
                                    btn.textContent = 'Wolę tę odpowiedź';
                                });
                                const card = button.closest('.chatgpt-compare-card');
                                if (card) {
                                    card.classList.add('is-selected');
                                }
                                button.classList.add('is-selected');
                                button.textContent = 'Wolę tę odpowiedź ✓';
                            };

                            const bindComparisonButtons = (scope) => {
                                if (!scope) {
                                    return;
                                }
                                scope.querySelectorAll('.chatgpt-compare-select[data-comparison-choice]').forEach((btn) => {
                                    if (btn.dataset.bound === '1') {
                                        return;
                                    }
                                    btn.dataset.bound = '1';
                                    btn.addEventListener('click', () => {
                                        const pref = btn.getAttribute('data-comparison-choice') || 'first';
                                        if (typeof applyComparisonPreference === 'function') {
                                            applyComparisonPreference(pref);
                                        } else if (comparisonInput) {
                                            comparisonInput.value = pref === 'second' ? 'second' : 'first';
                                        }
                                        applyChoiceVisualState(btn);
                                        if (composerInput) {
                                            composerInput.focus();
                                        }
                                    });
                                });
                            };

                            const buildComparisonMessage = ({ messageId, options, selectedIndex, streaming }) => {
                                const article = document.createElement('article');
                                article.className = 'chatgpt-msg chatgpt-msg--assistant chatgpt-msg--compare';
                                if (streaming) {
                                    article.classList.add('is-streaming');
                                }
                                if (messageId) {
                                    article.dataset.messageId = messageId;
                                }
                                const wrap = document.createElement('div');
                                wrap.className = 'chatgpt-compare';
                                const title = document.createElement('p');
                                title.className = 'chatgpt-compare__title';
                                title.textContent = 'Przekazujesz opinię na temat nowej wersji ChatGPT.';
                                wrap.appendChild(title);
                                const subtitle = document.createElement('p');
                                subtitle.className = 'chatgpt-compare__subtitle';
                                subtitle.textContent = 'Którą odpowiedź wybierasz? Wczytywanie odpowiedzi może chwilę potrwać.';
                                wrap.appendChild(subtitle);
                                const grid = document.createElement('div');
                                grid.className = 'chatgpt-compare__grid';
                                options.forEach((opt) => {
                                    const isSelected = Number(opt.index) === Number(selectedIndex);
                                    const prefChoice = Number(opt.index) === 1 ? 'second' : 'first';
                                    const card = document.createElement('article');
                                    card.className = 'chatgpt-compare-card' + (isSelected ? ' is-selected' : '');
                                    const label = document.createElement('span');
                                    label.className = 'chatgpt-compare-card__label';
                                    label.textContent = String(opt.label || '');
                                    card.appendChild(label);
                                    const text = document.createElement('p');
                                    text.className = 'chatgpt-compare-card__text';
                                    text.textContent = String(opt.text || '');
                                    card.appendChild(text);
                                    const button = document.createElement('button');
                                    button.type = 'button';
                                    button.className = 'chatgpt-compare-card__btn chatgpt-compare-select' + (isSelected ? ' is-selected' : '');
                                    button.setAttribute('data-comparison-choice', prefChoice);
                                    button.textContent = 'Wolę tę odpowiedź' + (isSelected ? ' ✓' : '');
                                    card.appendChild(button);
                                    grid.appendChild(card);
                                });
                                wrap.appendChild(grid);
                                article.appendChild(wrap);
                                bindComparisonButtons(article);
                                return article;
                            };

                            const appendToThread = (node) => {
                                if (!node) {
                                    return null;
                                }
                                const currentThread = threadIdInput ? String(threadIdInput.value || '').trim() : '';
                                const log = ensureThreadPanel(currentThread);
                                if (!log) {
                                    return null;
                                }
                                removeEmptyThreadPlaceholder();
                                log.appendChild(node);
                                autoFollow = true;
                                forceFollowUntil = Date.now() + 4500;
                                scheduleStickToBottom(true);
                                return node;
                            };

                            const updateAssistantNode = (node, payload) => {
                                if (!node) {
                                    return null;
                                }
                                const messageId = String(payload.messageId || '');
                                const mode = String(payload.mode || 'default');
                                const status = String(payload.status || 'running');
                                const streaming = status !== 'completed' && status !== 'failed';
                                const comparisonOptions = normalizeComparisonOptions(payload.comparisonOptions);
                                const selectedIndex = Number.isInteger(payload.selectedIndex) ? payload.selectedIndex : -1;
                                const textValue = String(payload.text || '').trim();
                                const fallbackText = streaming ? '...' : '[pusta wiadomość]';
                                if (comparisonOptions.length >= 2) {
                                    const compareNode = buildComparisonMessage({
                                        messageId: messageId,
                                        options: comparisonOptions,
                                        selectedIndex: selectedIndex,
                                        streaming: streaming,
                                    });
                                    if (node !== compareNode && node.parentNode) {
                                        node.parentNode.replaceChild(compareNode, node);
                                    }
                                    scheduleStickToBottom(true);
                                    return compareNode;
                                }
                                let targetNode = node;
                                if (node.classList.contains('chatgpt-msg--compare')) {
                                    const replacement = buildTextMessage({
                                        role: 'assistant',
                                        mode: mode,
                                        text: textValue || fallbackText,
                                        messageId: messageId,
                                        streaming: streaming,
                                    });
                                    if (node.parentNode) {
                                        node.parentNode.replaceChild(replacement, node);
                                    }
                                    targetNode = replacement;
                                } else {
                                    targetNode.classList.toggle('is-streaming', streaming);
                                    if (messageId) {
                                        targetNode.dataset.messageId = messageId;
                                    }
                                    const meta = targetNode.querySelector('.chatgpt-msg__meta');
                                    if (meta) {
                                        meta.textContent = 'assistant · ' + mode;
                                    }
                                    const textEl = targetNode.querySelector('.chatgpt-msg__text');
                                    if (textEl) {
                                        textEl.textContent = textValue || fallbackText;
                                    }
                                }
                                scheduleStickToBottom(true);
                                return targetNode;
                            };

                            const setComposerBusy = (busy) => {
                                submitBusy = busy;
                                if (sendButton) {
                                    sendButton.disabled = busy;
                                }
                            };

                            const updateModalBodyState = () => {
                                const anyOpen = (opsBackdrop && !opsBackdrop.hidden)
                                    || (historyBackdrop && !historyBackdrop.hidden)
                                    || (moreBackdrop && !moreBackdrop.hidden);
                                document.body.classList.toggle('chatgpt-modal-open', Boolean(anyOpen));
                            };

                            const openOpsModal = () => {
                                if (!opsBackdrop) {
                                    return;
                                }
                                opsBackdrop.hidden = false;
                                if (accountTrigger) {
                                    accountTrigger.setAttribute('aria-expanded', 'true');
                                }
                                updateModalBodyState();
                            };

                            const closeOpsModal = () => {
                                if (!opsBackdrop) {
                                    return;
                                }
                                opsBackdrop.hidden = true;
                                if (accountTrigger) {
                                    accountTrigger.setAttribute('aria-expanded', 'false');
                                }
                                updateModalBodyState();
                            };

                            const openHistoryModal = () => {
                                if (!historyBackdrop) {
                                    return;
                                }
                                historyBackdrop.hidden = false;
                                updateModalBodyState();
                                if (historySearchInput) {
                                    historySearchInput.focus();
                                }
                            };

                            const closeHistoryModal = () => {
                                if (!historyBackdrop) {
                                    return;
                                }
                                historyBackdrop.hidden = true;
                                updateModalBodyState();
                            };

                            const openMoreModal = () => {
                                if (!moreBackdrop) {
                                    return;
                                }
                                moreBackdrop.hidden = false;
                                updateModalBodyState();
                            };

                            const closeMoreModal = () => {
                                if (!moreBackdrop) {
                                    return;
                                }
                                moreBackdrop.hidden = true;
                                updateModalBodyState();
                            };

                            if (accountTrigger) {
                                accountTrigger.addEventListener('click', () => {
                                    openOpsModal();
                                });
                            }

                            if (opsClose) {
                                opsClose.addEventListener('click', () => {
                                    closeOpsModal();
                                });
                            }

                            if (opsBackdrop) {
                                opsBackdrop.addEventListener('click', (event) => {
                                    if (event.target === opsBackdrop) {
                                        closeOpsModal();
                                    }
                                });
                            }

                            if (historyOpenBtn) {
                                historyOpenBtn.addEventListener('click', () => {
                                    openHistoryModal();
                                });
                            }

                            if (historyClose) {
                                historyClose.addEventListener('click', () => {
                                    closeHistoryModal();
                                });
                            }

                            if (historyBackdrop) {
                                historyBackdrop.addEventListener('click', (event) => {
                                    if (event.target === historyBackdrop) {
                                        closeHistoryModal();
                                    }
                                });
                            }

                            if (moreOpenBtn) {
                                moreOpenBtn.addEventListener('click', () => {
                                    openMoreModal();
                                });
                            }

                            if (moreClose) {
                                moreClose.addEventListener('click', () => {
                                    closeMoreModal();
                                });
                            }

                            if (moreBackdrop) {
                                moreBackdrop.addEventListener('click', (event) => {
                                    if (event.target === moreBackdrop) {
                                        closeMoreModal();
                                    }
                                });
                            }

                            if (newChatTrigger) {
                                newChatTrigger.addEventListener('click', () => {
                                    stopPolling();
                                    activeExchangeId = '';
                                    activeAssistantNode = null;
                                    setComposerBusy(false);
                                });
                            }

                            const syncThreadUrl = (threadId) => {
                                if (!threadId) {
                                    return;
                                }
                                if (modalThreadIdEl) {
                                    modalThreadIdEl.textContent = threadId;
                                }
                                try {
                                    const url = new URL(window.location.href);
                                    url.searchParams.set('view', 'chatgpt');
                                    url.searchParams.set('tab', 'session');
                                    if (assistantIdInput && assistantIdInput.value) {
                                        url.searchParams.set('assistant', assistantIdInput.value);
                                    }
                                    if (projectIdInput && projectIdInput.value) {
                                        url.searchParams.set('project', projectIdInput.value);
                                    }
                                    url.searchParams.set('thread', threadId);
                                    url.searchParams.delete('new_chat');
                                    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
                                } catch (e) {
                                    // ignore
                                }
                            };

                            const fetchJson = async (url, options) => {
                                const res = await fetch(url, options);
                                let data = null;
                                try {
                                    data = await res.json();
                                } catch (e) {
                                    data = null;
                                }
                                if (!res.ok || !data || data.ok === false) {
                                    const detail = data && data.detail ? String(data.detail) : ('HTTP_' + res.status);
                                    throw new Error(detail);
                                }
                                return data;
                            };

                            const stopSyncPolling = () => {
                                if (syncPollTimer) {
                                    clearTimeout(syncPollTimer);
                                    syncPollTimer = 0;
                                }
                            };

                            const pollSyncJob = (jobId) => {
                                if (!jobId) {
                                    return;
                                }
                                stopSyncPolling();
                                activeSyncJobId = jobId;
                                const actionLabelMap = {
                                    reading_visible: 'czyta listę widocznych rozmów',
                                    scrolling: 'przewija listę rozmów',
                                    waiting_lazy_load: 'czeka na dociągnięcie lazy-load',
                                    rechecking_visible: 'ponownie sprawdza listę',
                                    new_threads_detected: 'wykrył nowe rozmowy',
                                    new_threads_detected_after_wait: 'dociągnął nowe po odczekaniu',
                                    no_growth_cycle: 'brak nowych po pełnym cyklu',
                                    finished: 'zakończył skan',
                                };
                                const tick = async () => {
                                    if (activeSyncJobId !== jobId) {
                                        return;
                                    }
                                    try {
                                        const data = await fetchJson(
                                            '/?view=chatgpt&tab=session&ajax=chatgpt_sync_job_status&job_id=' + encodeURIComponent(jobId),
                                            { cache: 'no-store' }
                                        );
                                        const status = String(data.status || 'running');
                                        const phase = String(data.phase || '');
                                        const done = Number(data.progress_done || 0);
                                        const total = Number(data.progress_total || 0);
                                        setSyncProgress(done, total);

                                        if (status === 'queued' || status === 'running') {
                                            const phaseLabel = phase !== '' ? (' | etap: ' + phase) : '';
                                            const progressLabel = total > 0 ? (' | ' + done + '/' + total) : '';
                                            const runtime = (data.scan_runtime && typeof data.scan_runtime === 'object')
                                                ? data.scan_runtime
                                                : null;
                                            if (phase === 'scan_threads' && runtime) {
                                                const actionKey = String(runtime.action || '');
                                                const actionLabel = actionLabelMap[actionKey] || actionKey || 'skanuje';
                                                const found = Number(runtime.total_found || 0);
                                                const visible = Number(runtime.visible_count || 0);
                                                const added = Number(runtime.added || 0);
                                                const round = Number(runtime.round || 0);
                                                const maxRounds = Number(runtime.max_rounds || total || 0);
                                                const stableNow = Number(runtime.stable_rounds || 0);
                                                const stableTarget = Number(runtime.stable_target || 0);
                                                const scrollOps = Number(runtime.scroll_ops || 0);
                                                const scrollMoved = Number(runtime.scroll_moved_total || 0);
                                                const waitMs = Number(runtime.wait_ms || 0);
                                                const elapsedMs = Number(runtime.elapsed_ms || 0);

                                                const headline = 'Skan listy: ' + actionLabel
                                                    + ' | znalezione=' + found
                                                    + ' | widoczne=' + visible
                                                    + ' | nowe=' + added
                                                    + ' | przewinięcia=' + scrollOps;
                                                setHistorySyncStatus(headline, '');

                                                const live = [
                                                    'Job: ' + jobId,
                                                    'Status: ' + status,
                                                    'Etap: ' + phase,
                                                    'Akcja: ' + actionLabel,
                                                    'Iteracja: ' + round + (maxRounds > 0 ? ('/' + maxRounds) : ''),
                                                    'Znalezione łącznie: ' + found,
                                                    'Widzoczne obecnie: ' + visible,
                                                    'Nowe w aktualnym kroku: ' + added,
                                                    'Przewinięcia łącznie: ' + scrollOps,
                                                    'Ruch skuteczny scrolla: ' + scrollMoved,
                                                    'No-growth: ' + stableNow + '/' + stableTarget,
                                                    'Wait lazy-load (ms): ' + waitMs,
                                                    'Czas pracy (s): ' + (elapsedMs > 0 ? (elapsedMs / 1000).toFixed(1) : '0.0'),
                                                ].join('\n');
                                                setSyncLive(live);
                                            } else {
                                                setHistorySyncStatus('Synchronizacja trwa...' + phaseLabel + progressLabel, '');
                                            }
                                            syncPollTimer = window.setTimeout(tick, 900);
                                            return;
                                        }

                                        syncHistoryBusy = false;
                                        setSyncButtonsDisabled(false);
                                        activeSyncJobId = '';
                                        stopSyncPolling();

                                        if (status === 'completed' || status === 'completed_with_errors') {
                                            const result = (data.result && typeof data.result === 'object') ? data.result : {};
                                            const scanned = Number(result.scanned_threads || 0);
                                            const deletedThreads = Number(result.deleted_threads || 0);
                                            const inserted = Number(result.inserted_messages || 0);
                                            const updated = Number(result.updated_messages || 0);
                                            const deleted = Number(result.deleted_messages || 0);
                                            const atts = Number(result.attachments_inserted || 0);
                                            const scanEndReason = String(result.scan_end_reason || '');
                                            const scanStableRounds = Number(result.scan_stable_rounds || 0);
                                            const scanStableTarget = Number(result.scan_stable_target || 0);
                                            const scanScrollOps = Number(result.scan_scroll_ops || 0);
                                            const scanScrollMoved = Number(result.scan_scroll_moved_total || 0);
                                            const scanPart = scanEndReason !== ''
                                                ? (', scan=' + scanEndReason + ' (' + scanStableRounds + '/' + scanStableTarget + ')'
                                                    + ', scrolls=' + scanScrollOps + ', moved=' + scanScrollMoved)
                                                : '';
                                            const msg = 'Sync zakończony: threads=' + scanned
                                                + ', deleted_threads=' + deletedThreads
                                                + ', inserted=' + inserted
                                                + ', updated=' + updated
                                                + ', deleted=' + deleted
                                                + ', attachments=' + atts
                                                + scanPart
                                                + (status === 'completed_with_errors' ? ' (z błędami).' : '.');
                                            setHistorySyncStatus(msg, status === 'completed_with_errors' ? 'warn' : 'ok');
                                            setSyncLive([
                                                'Status końcowy: ' + status,
                                                'Threads: ' + scanned + ' | deleted_threads: ' + deletedThreads,
                                                'Messages: +' + inserted + ' / ~' + updated + ' / -' + deleted,
                                                'Attachments: ' + atts,
                                                'Skan end_reason: ' + (scanEndReason || '-'),
                                                'No-growth: ' + scanStableRounds + '/' + scanStableTarget,
                                                'Przewinięcia: ' + scanScrollOps + ' | moved: ' + scanScrollMoved,
                                            ].join('\n'));
                                            window.setTimeout(() => window.location.reload(), 900);
                                            return;
                                        }

                                        const detail = String(data.error || 'SYNC_JOB_FAILED');
                                        setHistorySyncStatus('Błąd synchronizacji: ' + detail, 'error');
                                        setSyncLive('Błąd synchronizacji:\n' + detail);
                                    } catch (error) {
                                        syncHistoryBusy = false;
                                        setSyncButtonsDisabled(false);
                                        activeSyncJobId = '';
                                        stopSyncPolling();
                                        const message = error instanceof Error ? error.message : 'SYNC_JOB_STATUS_FAILED';
                                        setHistorySyncStatus('Błąd odczytu statusu synchronizacji: ' + message, 'error');
                                        setSyncLive('Błąd odczytu statusu synchronizacji:\n' + message);
                                    }
                                };
                                tick();
                            };

                            const startSyncJob = async (kind) => {
                                if (!kind || syncHistoryBusy) {
                                    return;
                                }
                                syncHistoryBusy = true;
                                setSyncButtonsDisabled(true);
                                setSyncProgress(0, 0);
                                setHistorySyncStatus('Uruchamiam zadanie synchronizacji (' + kind + ')...', '');
                                setSyncLive('Inicjalizacja joba: ' + kind);

                                try {
                                    const form = new FormData();
                                    form.set('sync_kind', String(kind));
                                    if (projectIdInput && projectIdInput.value) {
                                        form.set('project_id', String(projectIdInput.value));
                                    }
                                    if (assistantIdInput && assistantIdInput.value) {
                                        form.set('assistant_id', String(assistantIdInput.value));
                                    }
                                    if (modeInput && modeInput.value) {
                                        form.set('mode', String(modeInput.value));
                                    }
                                    form.set('mirror_delete_local', '1');
                                    form.set('max_rounds', '12000');
                                    form.set('max_threads', '20000');
                                    const started = await fetchJson('/?view=chatgpt&tab=session&ajax=chatgpt_sync_start', {
                                        method: 'POST',
                                        body: form,
                                        cache: 'no-store',
                                    });
                                    const jobId = String(started.job_id || '');
                                    if (!jobId) {
                                        throw new Error('SYNC_JOB_ID_MISSING');
                                    }
                                    setHistorySyncStatus('Synchronizacja wystartowała. Job: ' + jobId, '');
                                    pollSyncJob(jobId);
                                } catch (error) {
                                    syncHistoryBusy = false;
                                    setSyncButtonsDisabled(false);
                                    const message = error instanceof Error ? error.message : 'SYNC_START_FAILED';
                                    setHistorySyncStatus('Nie udało się uruchomić synchronizacji: ' + message, 'error');
                                }
                            };

                            if (syncThreadsBtn) {
                                syncThreadsBtn.addEventListener('click', () => {
                                    startSyncJob('threads_scan');
                                });
                            }
                            if (syncMessagesBtn) {
                                syncMessagesBtn.addEventListener('click', () => {
                                    startSyncJob('messages_pull');
                                });
                            }
                            if (syncFullBtn) {
                                syncFullBtn.addEventListener('click', () => {
                                    startSyncJob('full_sync');
                                });
                            }

                            const stopPolling = () => {
                                if (exchangePollTimer) {
                                    clearTimeout(exchangePollTimer);
                                    exchangePollTimer = 0;
                                }
                            };

                            const pollExchangeStatus = (exchangeId) => {
                                if (!exchangeId) {
                                    return;
                                }
                                stopPolling();
                                activeExchangeId = exchangeId;
                                const tick = async () => {
                                    if (activeExchangeId !== exchangeId) {
                                        return;
                                    }
                                    try {
                                        const data = await fetchJson(
                                            '/?view=chatgpt&tab=session&ajax=chatgpt_exchange_status&exchange_id=' + encodeURIComponent(exchangeId),
                                            { cache: 'no-store' }
                                        );
                                        const assistantMessage = data.assistant_message && typeof data.assistant_message === 'object'
                                            ? data.assistant_message
                                            : null;
                                        const exchangeMeta = data.exchange && typeof data.exchange === 'object'
                                            ? data.exchange
                                            : (
                                                assistantMessage
                                                && assistantMessage.metadata_json
                                                && typeof assistantMessage.metadata_json === 'object'
                                                && assistantMessage.metadata_json.exchange
                                                && typeof assistantMessage.metadata_json.exchange === 'object'
                                                    ? assistantMessage.metadata_json.exchange
                                                    : {}
                                            );
                                        const status = String(data.status || 'running');
                                        activeAssistantNode = updateAssistantNode(activeAssistantNode, {
                                            messageId: assistantMessage ? String(assistantMessage.message_id || '') : '',
                                            mode: assistantMessage ? String(assistantMessage.mode || (modeInput ? modeInput.value : 'default')) : (modeInput ? modeInput.value : 'default'),
                                            text: assistantMessage ? String(assistantMessage.content_text || '') : String(data.assistant_text || ''),
                                            status: status,
                                            comparisonOptions: exchangeMeta && exchangeMeta.comparison_options ? exchangeMeta.comparison_options : [],
                                            selectedIndex: Number.isInteger(exchangeMeta && exchangeMeta.comparison_selected_index)
                                                ? exchangeMeta.comparison_selected_index
                                                : -1,
                                        });

                                        if (status === 'completed') {
                                            activeExchangeId = '';
                                            activeAssistantNode = null;
                                            setComposerBusy(false);
                                            if (composerInput) {
                                                composerInput.focus();
                                            }
                                            return;
                                        }
                                        if (status === 'failed') {
                                            throw new Error(String(data.error || 'EXCHANGE_FAILED'));
                                        }
                                        exchangePollTimer = window.setTimeout(tick, 700);
                                    } catch (error) {
                                        const message = error instanceof Error ? error.message : 'EXCHANGE_FAILED';
                                        activeAssistantNode = updateAssistantNode(activeAssistantNode, {
                                            mode: modeInput ? modeInput.value : 'default',
                                            text: 'Błąd wymiany z ChatGPT: ' + message,
                                            status: 'failed',
                                            comparisonOptions: [],
                                            selectedIndex: -1,
                                        });
                                        activeExchangeId = '';
                                        activeAssistantNode = null;
                                        setComposerBusy(false);
                                    }
                                };
                                tick();
                            };

                            bindThreadScroll();
                            revealLatest();

                            if (composer && composerInput) {
                                const autoSize = () => {
                                    composerInput.style.height = 'auto';
                                    composerInput.style.height = Math.min(composerInput.scrollHeight, 144) + 'px';
                                };
                                composerInput.addEventListener('focus', () => composer.classList.add('is-focused'));
                                composerInput.addEventListener('blur', () => composer.classList.remove('is-focused'));
                                composerInput.addEventListener('input', autoSize);
                                composerInput.addEventListener('keydown', (event) => {
                                    if (event.key === 'Enter' && !event.shiftKey && composerForm) {
                                        event.preventDefault();
                                        composerForm.requestSubmit();
                                    }
                                });
                                autoSize();
                                if (composerForm) {
                                    composerForm.addEventListener('submit', async (event) => {
                                        event.preventDefault();
                                        if (submitBusy) {
                                            return;
                                        }
                                        const prompt = String(composerInput.value || '').trim();
                                        if (prompt === '') {
                                            return;
                                        }
                                        const threadTitle = prompt.length > 72 ? prompt.slice(0, 72).trim() : prompt;
                                        if (threadTitleInput && String(threadTitleInput.value || '').trim() === '' && threadTitle !== '') {
                                            threadTitleInput.value = threadTitle;
                                        }
                                        const mode = modeInput ? String(modeInput.value || 'default') : 'default';
                                        const userNode = buildTextMessage({
                                            role: 'user',
                                            mode: mode,
                                            text: prompt,
                                            streaming: false,
                                        });
                                        appendToThread(userNode);
                                        composerInput.value = '';
                                        autoSize();
                                        setComposerBusy(true);

                                        const waitingNode = buildTextMessage({
                                            role: 'assistant',
                                            mode: mode,
                                            text: '...',
                                            streaming: true,
                                        });
                                        activeAssistantNode = appendToThread(waitingNode);

                                        const payload = new FormData(composerForm);
                                        payload.set('chatgpt_prompt', prompt);
                                        try {
                                            const started = await fetchJson('/?view=chatgpt&tab=session&ajax=chatgpt_exchange_start', {
                                                method: 'POST',
                                                body: payload,
                                                cache: 'no-store',
                                            });
                                            const nextThreadId = String(started.thread_id || '');
                                            if (threadIdInput && nextThreadId !== '') {
                                                threadIdInput.value = nextThreadId;
                                            }
                                            ensureThreadHeader(nextThreadId);
                                            syncThreadUrl(nextThreadId);
                                            if (threadTitleInput && started.thread_title) {
                                                threadTitleInput.value = String(started.thread_title);
                                            }
                                            if (started.exchange_id) {
                                                pollExchangeStatus(String(started.exchange_id));
                                            } else {
                                                throw new Error('EXCHANGE_ID_MISSING');
                                            }
                                        } catch (error) {
                                            const message = error instanceof Error ? error.message : 'EXCHANGE_START_FAILED';
                                            activeAssistantNode = updateAssistantNode(activeAssistantNode, {
                                                mode: mode,
                                                text: 'Błąd uruchomienia wymiany: ' + message,
                                                status: 'failed',
                                                comparisonOptions: [],
                                                selectedIndex: -1,
                                            });
                                            activeAssistantNode = null;
                                            setComposerBusy(false);
                                        }
                                    });
                                }
                            }

                            const modeLabel = {
                                attach_files: 'załączniki',
                                google_drive: 'dysk zewnętrzny',
                                image: 'tworzenie obrazów',
                                deep_research: 'głębokie badanie',
                                shopping: 'asystent zakupowy',
                                web_search: 'wyszukiwanie w sieci',
                                learn: 'learning',
                                agent: 'tryb agenta',
                                canvas: 'kanwa',
                                photoshop: 'integracja Photoshop',
                                network: 'network solutions',
                                quiz: 'quiz',
                                discover: 'odkrywanie aplikacji'
                            };
                            const toolModeMap = {
                                attach_files: 'default',
                                google_drive: 'default',
                                image: 'image',
                                deep_research: 'deep_research',
                                shopping: 'shopping',
                                web_search: 'web_search',
                                learn: 'learn',
                                agent: 'agent',
                                canvas: 'canvas',
                                photoshop: 'integration',
                                network: 'integration',
                                quiz: 'quiz',
                                discover: 'apps',
                            };
                            let lastComposerMode = modeInput ? String(modeInput.value || 'default') : 'default';

                            const emitTelemetry = (eventType, payload = {}) => {
                                if (!eventType) {
                                    return;
                                }
                                const form = new FormData();
                                form.set('event_type', String(eventType));
                                if (threadIdInput && threadIdInput.value) {
                                    form.set('thread_id', String(threadIdInput.value));
                                }
                                if (assistantIdInput && assistantIdInput.value) {
                                    form.set('assistant_id', String(assistantIdInput.value));
                                }
                                if (projectIdInput && projectIdInput.value) {
                                    form.set('project_id', String(projectIdInput.value));
                                }
                                Object.keys(payload).forEach((key) => {
                                    const val = payload[key];
                                    if (val === null || val === undefined) {
                                        return;
                                    }
                                    form.set(String(key), String(val));
                                });
                                fetch('/?view=chatgpt&tab=session&ajax=chatgpt_telemetry', {
                                    method: 'POST',
                                    body: form,
                                    cache: 'no-store',
                                }).catch(() => {
                                    // telemetry failures are non-blocking for chat UX
                                });
                            };

                            const closeTools = () => {
                                if (tools) tools.hidden = true;
                                if (submenu) submenu.hidden = true;
                            };

                            if (comparisonPicker && comparisonInput) {
                                const prefButtons = comparisonPicker.querySelectorAll('[data-pref]');
                                const setPreference = (pref) => {
                                    const next = pref === 'second' ? 'second' : 'first';
                                    comparisonInput.value = next;
                                    prefButtons.forEach((btn) => {
                                        const val = btn.getAttribute('data-pref');
                                        btn.classList.toggle('is-active', val === next);
                                    });
                                };
                                prefButtons.forEach((btn) => {
                                    btn.addEventListener('click', () => {
                                        const pref = btn.getAttribute('data-pref') || 'first';
                                        setPreference(pref);
                                    });
                                });
                                setPreference(comparisonInput.value || 'first');
                                applyComparisonPreference = setPreference;
                            }

                            bindComparisonButtons(shell);

                            if (toolsToggle && tools) {
                                toolsToggle.addEventListener('click', (event) => {
                                    event.stopPropagation();
                                    const next = tools.hidden;
                                    tools.hidden = !next;
                                    if (!next && submenu) submenu.hidden = true;
                                });
                            }

                            if (toolsMore && submenu) {
                                const openSubmenu = () => {
                                    submenu.hidden = false;
                                };
                                toolsMore.addEventListener('mouseenter', openSubmenu);
                                toolsMore.addEventListener('click', (event) => {
                                    event.stopPropagation();
                                    submenu.hidden = !submenu.hidden;
                                });
                            }

                            shell.querySelectorAll('[data-tool]').forEach((btn) => {
                                btn.addEventListener('click', (event) => {
                                    event.stopPropagation();
                                    const tool = btn.getAttribute('data-tool') || 'standard';
                                    const mappedMode = toolModeMap[tool] || 'default';
                                    const modeFrom = modeInput ? String(modeInput.value || lastComposerMode || 'default') : lastComposerMode;
                                    if (modePill) {
                                        modePill.textContent = 'Tryb: ' + (modeLabel[tool] || tool);
                                    }
                                    if (modeInput) {
                                        modeInput.value = mappedMode;
                                    }
                                    emitTelemetry('tool_selected', {
                                        tool_id: tool,
                                        mode: mappedMode,
                                        mode_from: modeFrom,
                                    });
                                    if (modeFrom !== mappedMode) {
                                        emitTelemetry('composer_mode_changed', {
                                            mode_from: modeFrom,
                                            mode_to: mappedMode,
                                            tool_id: tool,
                                        });
                                    }
                                    lastComposerMode = mappedMode;
                                    if (tool === 'attach_files' && fileInput) {
                                        fileInput.click();
                                    }
                                    closeTools();
                                });
                            });

                            if (fileInput && attachments) {
                                fileInput.addEventListener('change', () => {
                                    const files = Array.from(fileInput.files || []);
                                    attachments.innerHTML = '';
                                    if (files.length === 0) {
                                        attachments.hidden = true;
                                        return;
                                    }
                                    files.forEach((file) => {
                                        const chip = document.createElement('span');
                                        chip.className = 'chatgpt-chip';
                                        chip.textContent = file.name;
                                        attachments.appendChild(chip);
                                    });
                                    attachments.hidden = false;
                                });
                            }

                            document.addEventListener('click', (event) => {
                                if (!tools || tools.hidden) return;
                                if (tools.contains(event.target) || (toolsToggle && toolsToggle.contains(event.target))) return;
                                closeTools();
                            });

                            document.addEventListener('keydown', (event) => {
                                if (event.key === 'Escape') {
                                    closeTools();
                                    closeOpsModal();
                                    closeHistoryModal();
                                    closeMoreModal();
                                }
                            });

                            const stateEl = document.getElementById('cgpt-state');
                            const hintEl = document.getElementById('cgpt-hint');
                            let pollAuthStatus = null;
                            if (stateEl && hintEl) {
                                let last = stateEl.textContent || '';
                                const toAuthLabel = (state) => {
                                    if (state === 'AUTH_OK') return 'OK';
                                    if (state === 'LOGIN_RUNNING') return 'LOGIN';
                                    return 'REQUIRED';
                                };
                                pollAuthStatus = async () => {
                                    try {
                                        const res = await fetch('/?view=chatgpt&tab=session&ajax=chatgpt_auth', { cache: 'no-store' });
                                        if (!res.ok) return;
                                        const data = await res.json();
                                        const state = data && data.state ? String(data.state) : 'AUTH_UNKNOWN';
                                        const sessionId = data && data.login_session_id ? String(data.login_session_id) : '';
                                        const hasSession = sessionId !== '';
                                        const novncUrl = data && data.novnc_url ? String(data.novnc_url) : '';
                                        stateEl.textContent = state;
                                        if (modalSessionIdEl) {
                                            modalSessionIdEl.textContent = hasSession ? sessionId : '-';
                                        }
                                        if (modalProjectIdEl && projectIdInput) {
                                            modalProjectIdEl.textContent = String(projectIdInput.value || '-');
                                        }
                                        if (modalNovncLink) {
                                            if (novncUrl !== '') {
                                                modalNovncLink.href = novncUrl;
                                                modalNovncLink.hidden = false;
                                            } else {
                                                modalNovncLink.hidden = true;
                                            }
                                        }
                                        if (topAuthPill) {
                                            topAuthPill.classList.toggle('ok', state === 'AUTH_OK');
                                            topAuthPill.classList.toggle('running', state !== 'AUTH_OK');
                                            topAuthPill.textContent = 'ChatGPT: ' + toAuthLabel(state);
                                        }
                                        if (modalHeartbeatEl) {
                                            modalHeartbeatEl.textContent = new Date().toISOString();
                                        }
                                        if (modalLastErrorEl) {
                                            modalLastErrorEl.textContent = '-';
                                        }

                                        if (state === 'AUTH_OK') {
                                            hintEl.textContent = hasSession
                                                ? 'Zalogowano. Zamknij okno logowania, żeby zwolnić profil.'
                                                : 'Sesja aktywna.';
                                        } else if (state === 'LOGIN_RUNNING') {
                                            hintEl.textContent = 'Okno logowania działa. Dokończ logowanie w noVNC.';
                                        } else {
                                            hintEl.textContent = 'Sesja nieaktywna. Uruchom okno logowania.';
                                        }

                                        if (state !== last) {
                                            last = state;
                                            if (state === 'AUTH_OK') {
                                                setTimeout(() => window.location.reload(), 800);
                                            }
                                        }
                                    } catch (e) {
                                        if (modalLastErrorEl) {
                                            modalLastErrorEl.textContent = 'AUTH_POLL_FAILED';
                                        }
                                    }
                                };
                                setInterval(pollAuthStatus, 2500);
                                pollAuthStatus();
                            }

                            if (refreshStatusBtn) {
                                refreshStatusBtn.addEventListener('click', () => {
                                    if (typeof pollAuthStatus === 'function') {
                                        pollAuthStatus();
                                    }
                                });
                            }
                        })();
                    </script>
                <?php endif; ?>
