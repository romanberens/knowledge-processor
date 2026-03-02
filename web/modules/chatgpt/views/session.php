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

                    <script src="/modules/chatgpt/assets/js/chatgpt.module.js"></script>
                <?php endif; ?>
