<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function is_processed_only(?string $notes): bool
{
    $notes = trim((string)($notes ?? ''));
    if ($notes === '') {
        return false;
    }

    // Example: "✓ przejrzane 2026-02-13"
    // Treat also "✓ przejrzane" (without date) as processed-only.
    return preg_match('/^\\s*✓\\s*przejrzane(?:\\s+\\d{4}-\\d{2}-\\d{2})?\\s*$/iu', $notes) === 1;
}

function is_processed_with_content(?string $notes): bool
{
    $notes = trim((string)($notes ?? ''));
    if ($notes === '') {
        return false;
    }
    return !is_processed_only($notes);
}

function reviewed_pattern_sql(): string
{
    // Must be compatible with MySQL 8 REGEXP (ICU).
    return '^\\s*✓\\s*przejrzane(\\s+[0-9]{4}-[0-9]{2}-[0-9]{2})?\\s*$';
}

function fetch_overview_stats(PDO $pdo): array
{
    $totalPosts = (int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    $totalItems = (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn();
    $inboxItems = (int)$pdo
        ->query('SELECT COUNT(*) FROM items i LEFT JOIN item_user_data ud ON ud.item_id = i.id WHERE ud.item_id IS NULL')
        ->fetchColumn();
    $savedContexts = (int)$pdo->query(
        "SELECT COUNT(*) FROM item_contexts WHERE source IN ('saved_posts','saved_articles') AND activity_kind = 'save'"
    )->fetchColumn();

    $now = new DateTimeImmutable('now');
    $todayStart = $now->setTime(0, 0, 0);
    $tomorrowStart = $todayStart->modify('+1 day');
    $weekStart = $now->sub(new DateInterval('P7D'));
    $utc = new DateTimeZone('UTC');
    $todayStartUtc = $todayStart->setTimezone($utc);
    $tomorrowStartUtc = $tomorrowStart->setTimezone($utc);
    $weekStartUtc = $weekStart->setTimezone($utc);
    $nowUtc = $now->setTimezone($utc);

    // Notes semantics:
    // - reviewed: notes is only "✓ przejrzane [YYYY-MM-DD]"
    // - processed: notes exists and has more content than the marker
    $reviewedPattern = reviewed_pattern_sql();

    $countReviewedStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM item_user_data ud WHERE ud.updated_at >= :start AND ud.updated_at < :end AND ud.notes REGEXP :re'
    );
    $countProcessedStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM item_user_data ud WHERE ud.updated_at >= :start AND ud.updated_at < :end AND ud.notes IS NOT NULL AND NOT (ud.notes REGEXP :re)'
    );

    $countReviewedStmt->execute([
        ':start' => $todayStartUtc->format('Y-m-d H:i:s'),
        ':end' => $tomorrowStartUtc->format('Y-m-d H:i:s'),
        ':re' => $reviewedPattern,
    ]);
    $reviewedToday = (int)$countReviewedStmt->fetchColumn();

    $countReviewedStmt->execute([
        ':start' => $weekStartUtc->format('Y-m-d H:i:s'),
        ':end' => $nowUtc->format('Y-m-d H:i:s'),
        ':re' => $reviewedPattern,
    ]);
    $reviewedWeek = (int)$countReviewedStmt->fetchColumn();

    $countProcessedStmt->execute([
        ':start' => $todayStartUtc->format('Y-m-d H:i:s'),
        ':end' => $tomorrowStartUtc->format('Y-m-d H:i:s'),
        ':re' => $reviewedPattern,
    ]);
    $processedToday = (int)$countProcessedStmt->fetchColumn();

    $countProcessedStmt->execute([
        ':start' => $weekStartUtc->format('Y-m-d H:i:s'),
        ':end' => $nowUtc->format('Y-m-d H:i:s'),
        ':re' => $reviewedPattern,
    ]);
    $processedWeek = (int)$countProcessedStmt->fetchColumn();

    $lastRunStmt = $pdo->query(
        'SELECT id, mode, status, started_at, finished_at, new_posts, total_seen, error_message FROM runs ORDER BY started_at DESC LIMIT 1'
    );
    $lastRun = $lastRunStmt->fetch() ?: null;

    $lastUpdateStmt = $pdo->query(
        "SELECT new_posts FROM runs WHERE mode = 'update' AND status = 'ok' ORDER BY started_at DESC LIMIT 1"
    );
    $lastUpdateGain = $lastUpdateStmt->fetchColumn();

    return [
        'total_posts' => $totalPosts,
        'total_items' => $totalItems,
        'inbox_items' => $inboxItems,
        'saved_contexts' => $savedContexts,
        'reviewed_today' => $reviewedToday,
        'reviewed_week' => $reviewedWeek,
        'processed_today' => $processedToday,
        'processed_week' => $processedWeek,
        'last_run' => $lastRun,
        'last_update_gain' => $lastUpdateGain !== false ? (int)$lastUpdateGain : 0,
    ];
}

function fetch_latest_runs(PDO $pdo, int $limit = 30): array
{
    $stmt = $pdo->prepare(
        'SELECT id, mode, status, started_at, finished_at, new_posts, total_seen, error_message FROM runs ORDER BY started_at DESC LIMIT :lim'
    );
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function fetch_latest_hydration_run(PDO $pdo): ?array
{
    // Latest run where permalink hydration actually attempted any permalink fetches.
    $stmt = $pdo->query(
        "SELECT id, mode, status, started_at, finished_at, details_json
         FROM runs
         WHERE details_json IS NOT NULL
           AND CAST(JSON_EXTRACT(details_json, '$.permalink_hydrate_attempted') AS UNSIGNED) > 0
         ORDER BY started_at DESC
         LIMIT 1"
    );
    $row = $stmt->fetch() ?: null;
    if (!$row) {
        return null;
    }

    $raw = $row['details_json'] ?? null;
    $decoded = null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
    } elseif (is_array($raw)) {
        $decoded = $raw;
    }

    $row['details'] = is_array($decoded) ? $decoded : null;
    unset($row['details_json']);
    return $row;
}

function fetch_feed_items(PDO $pdo, ?string $source = null, int $limit = 100): array
{
    $sql = 'SELECT id, author, content, source_page, activity_type, activity_label, post_url, published_label, collected_at FROM posts';
    $params = [];

    if ($source && in_array($source, ['all', 'reactions', 'comments'], true)) {
        $sql .= ' WHERE source_page = :source';
        $params[':source'] = $source;
    }

    $sql .= ' ORDER BY collected_at DESC LIMIT :lim';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function fetch_post(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, author, content, source_page, activity_type, activity_label, activity_urn, post_url, published_label, collected_at FROM posts WHERE id = :id LIMIT 1'
    );
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_library_items(
    PDO $pdo,
    ?string $source = null,
    ?string $activityKind = null,
    ?string $contentType = null,
    ?string $noteStatus = null,
    ?string $tag = null,
    int $limit = 200
): array
{
    $where = [];
    $params = [];

    if ($source && in_array($source, ['activity_all', 'activity_reactions', 'activity_comments', 'saved_posts', 'saved_articles'], true)) {
        $where[] = 'c.source = :source';
        $params[':source'] = $source;
    }

    if ($activityKind && in_array($activityKind, ['post', 'share', 'reaction', 'comment', 'save'], true)) {
        $where[] = 'c.activity_kind = :kind';
        $params[':kind'] = $activityKind;
    }

    if ($contentType && in_array($contentType, ['text', 'article', 'video', 'image', 'document', 'unknown'], true)) {
        $where[] = 'i.content_type = :ctype';
        $params[':ctype'] = $contentType;
    }

    if ($tag !== null) {
        $tag = trim($tag);
        if ($tag !== '') {
            // Filter by tag without narrowing the displayed tag list.
            $where[] = 'EXISTS (SELECT 1 FROM item_tags itf INNER JOIN tags tf ON tf.id = itf.tag_id WHERE itf.item_id = i.id AND tf.name = :tag)';
            $params[':tag'] = $tag;
        }
    }

    // noteStatus: inbox (no note), reviewed (marker-only), processed (meaningful note)
    if ($noteStatus && in_array($noteStatus, ['inbox', 'reviewed', 'processed'], true)) {
        if ($noteStatus === 'inbox') {
            $where[] = 'ud.item_id IS NULL';
        } else {
            $reviewedPattern = reviewed_pattern_sql();
            $params[':re'] = $reviewedPattern;
            if ($noteStatus === 'reviewed') {
                $where[] = 'ud.item_id IS NOT NULL AND ud.notes REGEXP :re';
            } else {
                $where[] = 'ud.item_id IS NOT NULL AND ud.notes IS NOT NULL AND NOT (ud.notes REGEXP :re)';
            }
        }
    }

    $sql = '
        SELECT
            i.id,
            i.item_urn,
            i.canonical_url,
            i.author,
            i.content,
            i.content_type,
            i.published_label,
            i.collected_last_at,
            MAX(c.collected_at) AS last_context_at,
            GROUP_CONCAT(DISTINCT CONCAT(c.source, ":", c.activity_kind) ORDER BY c.source, c.activity_kind SEPARATOR ", ") AS contexts,
            MAX(ud.item_id IS NOT NULL) AS has_note,
            MAX(ud.notes) AS user_notes,
            GROUP_CONCAT(DISTINCT tg.name ORDER BY tg.name SEPARATOR ", ") AS tags
        FROM items i
        INNER JOIN item_contexts c ON c.item_id = i.id
        LEFT JOIN item_user_data ud ON ud.item_id = i.id
        LEFT JOIN item_tags itag ON itag.item_id = i.id
        LEFT JOIN tags tg ON tg.id = itag.tag_id
    ';

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' GROUP BY i.id ORDER BY last_context_at DESC LIMIT :lim';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function fetch_item(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, item_urn, canonical_url, author, content, content_type, published_label, collected_first_at, collected_last_at FROM items WHERE id = :id LIMIT 1'
    );
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetch_item_contexts(PDO $pdo, int $itemId, int $limit = 200): array
{
    $stmt = $pdo->prepare(
        'SELECT id, source, activity_kind, activity_label, context_text, collected_at FROM item_contexts WHERE item_id = :id ORDER BY collected_at DESC LIMIT :lim'
    );
    $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function fetch_item_user_data(PDO $pdo, int $itemId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT item_id, notes, created_at, updated_at FROM item_user_data WHERE item_id = :id LIMIT 1'
    );
    $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ?: null;
}

function save_item_note(PDO $pdo, int $itemId, string $notes): void
{
    $notes = trim($notes);
    if ($notes === '') {
        $stmt = $pdo->prepare('DELETE FROM item_user_data WHERE item_id = :id');
        $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
        $stmt->execute();
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO item_user_data (item_id, notes) VALUES (:id, :notes) ON DUPLICATE KEY UPDATE notes = VALUES(notes)'
    );
    $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
    $stmt->bindValue(':notes', $notes, PDO::PARAM_STR);
    $stmt->execute();
}

function fetch_item_tags(PDO $pdo, int $itemId): array
{
    $stmt = $pdo->prepare(
        'SELECT t.name FROM item_tags it INNER JOIN tags t ON t.id = it.tag_id WHERE it.item_id = :id ORDER BY t.name ASC'
    );
    $stmt->bindValue(':id', $itemId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function add_item_tag(PDO $pdo, int $itemId, string $tagName): bool
{
    $tagName = trim($tagName);
    if ($tagName === '') {
        return false;
    }
    // Keep UTF-8 intact without requiring mbstring in the container.
    if (function_exists('iconv_strlen') && function_exists('iconv_substr')) {
        $len = (int)iconv_strlen($tagName, 'UTF-8');
        if ($len > 64) {
            $tagName = (string)iconv_substr($tagName, 0, 64, 'UTF-8');
        }
    } elseif (strlen($tagName) > 64) {
        $tagName = substr($tagName, 0, 64);
    }

    // Ensure tag exists and get its id (LAST_INSERT_ID trick returns id both for insert and "duplicate").
    $stmt = $pdo->prepare('INSERT INTO tags (name) VALUES (:name) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)');
    $stmt->bindValue(':name', $tagName, PDO::PARAM_STR);
    $stmt->execute();
    $tagId = (int)$pdo->lastInsertId();

    $stmt2 = $pdo->prepare('INSERT IGNORE INTO item_tags (item_id, tag_id) VALUES (:item_id, :tag_id)');
    $stmt2->bindValue(':item_id', $itemId, PDO::PARAM_INT);
    $stmt2->bindValue(':tag_id', $tagId, PDO::PARAM_INT);
    $stmt2->execute();
    return $stmt2->rowCount() > 0;
}

function remove_item_tag(PDO $pdo, int $itemId, string $tagName): bool
{
    $tagName = trim($tagName);
    if ($tagName === '') {
        return false;
    }

    $stmt = $pdo->prepare(
        'DELETE it FROM item_tags it INNER JOIN tags t ON t.id = it.tag_id WHERE it.item_id = :item_id AND t.name = :name'
    );
    $stmt->bindValue(':item_id', $itemId, PDO::PARAM_INT);
    $stmt->bindValue(':name', $tagName, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->rowCount() > 0;
}

function fetch_tags(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT name FROM tags ORDER BY name ASC');
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function fetch_library_top_tags(PDO $pdo, int $days = 30, int $limit = 12): array
{
    $days = max(1, min($days, 365));
    $limit = max(1, min($limit, 100));
    $since = (new DateTimeImmutable('now'))
        ->sub(new DateInterval('P' . $days . 'D'))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
    $reviewedPattern = reviewed_pattern_sql();

    // "Top tags" = tags on items that have meaningful notes updated in the last N days.
    $sql = '
        SELECT
            t.name AS tag,
            COUNT(DISTINCT it.item_id) AS items
        FROM item_tags it
        INNER JOIN tags t ON t.id = it.tag_id
        INNER JOIN item_user_data ud ON ud.item_id = it.item_id
        WHERE ud.updated_at >= :since
          AND ud.notes IS NOT NULL
          AND NOT (ud.notes REGEXP :re)
        GROUP BY t.id, t.name
        ORDER BY items DESC, tag ASC
        LIMIT :lim
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':since', $since, PDO::PARAM_STR);
    $stmt->bindValue(':re', $reviewedPattern, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function fetch_insights(PDO $pdo, int $days = 7, int $limit = 200): array
{
    $days = max(1, min($days, 30));
    $limit = max(1, min($limit, 500));
    // DB stores DATETIME in UTC; compute "since" in app TZ and convert to UTC for SQL compare.
    $since = (new DateTimeImmutable('now'))
        ->sub(new DateInterval('P' . $days . 'D'))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');

    $sql = '
        SELECT
            i.id,
            i.canonical_url,
            i.author,
            i.content,
            i.content_type,
            MAX(c.collected_at) AS last_context_at,
            MAX(ud.updated_at) AS note_updated_at,
            MAX(ud.notes) AS notes,
            GROUP_CONCAT(DISTINCT tg.name ORDER BY tg.name SEPARATOR ", ") AS tags
        FROM item_user_data ud
        INNER JOIN items i ON i.id = ud.item_id
        LEFT JOIN item_contexts c ON c.item_id = i.id
        LEFT JOIN item_tags itag ON itag.item_id = i.id
        LEFT JOIN tags tg ON tg.id = itag.tag_id
        WHERE ud.updated_at >= :since
        GROUP BY i.id
        ORDER BY note_updated_at DESC
        LIMIT :lim
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':since', $since, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];

    // Exclude "processed-only" markers to keep insights meaningful.
    $out = [];
    foreach ($rows as $row) {
        $notes = (string)($row['notes'] ?? '');
        if ($notes === '' || is_processed_only($notes)) {
            continue;
        }
        $out[] = $row;
    }
    return $out;
}

function fetch_insights_top_authors(PDO $pdo, int $limit = 10): array
{
    $limit = max(1, min($limit, 100));
    $reviewedPattern = reviewed_pattern_sql();

    // Aggregate per item first (to avoid context join multiplying rows), then roll up by author.
    $sql = '
        SELECT
            i.author AS author,
            COUNT(*) AS total_items,
            SUM(COALESCE(ctx.has_save, 0)) AS saved_items,
            SUM(CASE WHEN ud.item_id IS NOT NULL AND NOT (ud.notes REGEXP :re) THEN 1 ELSE 0 END) AS processed_items
        FROM items i
        LEFT JOIN (
            SELECT
                item_id,
                MAX(activity_kind = "save") AS has_save
            FROM item_contexts
            GROUP BY item_id
        ) ctx ON ctx.item_id = i.id
        LEFT JOIN item_user_data ud ON ud.item_id = i.id
        WHERE i.author IS NOT NULL AND i.author <> ""
        GROUP BY i.author
        ORDER BY total_items DESC
        LIMIT :lim
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':re', $reviewedPattern, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function fetch_insights_top_tags(PDO $pdo, int $days = 30, int $limit = 15): array
{
    $days = max(1, min($days, 90));
    $limit = max(1, min($limit, 100));
    $since = (new DateTimeImmutable('now'))
        ->sub(new DateInterval('P' . $days . 'D'))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');

    $sql = '
        SELECT
            t.name AS tag,
            COUNT(DISTINCT it.item_id) AS items
        FROM item_tags it
        INNER JOIN tags t ON t.id = it.tag_id
        INNER JOIN (
            SELECT DISTINCT item_id
            FROM item_contexts
            WHERE collected_at >= :since
        ) recent ON recent.item_id = it.item_id
        GROUP BY t.id, t.name
        ORDER BY items DESC, tag ASC
        LIMIT :lim
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':since', $since, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function fetch_insights_corpus(PDO $pdo, int $days = 30, int $limit = 600): array
{
    $days = max(1, min($days, 90));
    $limit = max(1, min($limit, 2000));
    $since = (new DateTimeImmutable('now'))
        ->sub(new DateInterval('P' . $days . 'D'))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');

    // Get up to N items that had any context in the last X days, sorted by recency of context.
    $sql = '
        SELECT
            i.id,
            i.author,
            i.content,
            ud.notes
        FROM items i
        INNER JOIN (
            SELECT
                item_id,
                MAX(collected_at) AS last_context_at
            FROM item_contexts
            WHERE collected_at >= :since
            GROUP BY item_id
            ORDER BY last_context_at DESC
            LIMIT :lim
        ) recent ON recent.item_id = i.id
        LEFT JOIN item_user_data ud ON ud.item_id = i.id
        ORDER BY recent.last_context_at DESC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':since', $since, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function fetch_insights_velocity(PDO $pdo, int $days = 30): array
{
    $days = max(1, min($days, 90));
    $reviewedPattern = reviewed_pattern_sql();

    $tzUtc = new DateTimeZone('UTC');
    $now = new DateTimeImmutable('now');
    $todayStart = $now->setTime(0, 0, 0);

    $countNewStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM items WHERE collected_first_at >= :start AND collected_first_at < :end'
    );
    $countReviewedStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM item_user_data ud WHERE ud.updated_at >= :start AND ud.updated_at < :end AND ud.notes REGEXP :re'
    );
    $countProcessedStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM item_user_data ud WHERE ud.updated_at >= :start AND ud.updated_at < :end AND ud.notes IS NOT NULL AND NOT (ud.notes REGEXP :re)'
    );

    $series = [];
    // Last N days including today, oldest -> newest.
    for ($i = $days - 1; $i >= 0; $i--) {
        $dayStart = $todayStart->sub(new DateInterval('P' . $i . 'D'));
        $dayEnd = $dayStart->modify('+1 day');
        $startUtc = $dayStart->setTimezone($tzUtc)->format('Y-m-d H:i:s');
        $endUtc = $dayEnd->setTimezone($tzUtc)->format('Y-m-d H:i:s');

        $countNewStmt->execute([':start' => $startUtc, ':end' => $endUtc]);
        $newCount = (int)$countNewStmt->fetchColumn();

        $countReviewedStmt->execute([':start' => $startUtc, ':end' => $endUtc, ':re' => $reviewedPattern]);
        $reviewedCount = (int)$countReviewedStmt->fetchColumn();

        $countProcessedStmt->execute([':start' => $startUtc, ':end' => $endUtc, ':re' => $reviewedPattern]);
        $processedCount = (int)$countProcessedStmt->fetchColumn();

        $series[] = [
            'date' => $dayStart->format('Y-m-d'),
            'new_items' => $newCount,
            'reviewed' => $reviewedCount,
            'processed' => $processedCount,
            'debt' => $newCount - $processedCount,
        ];
    }

    return $series;
}

function fetch_topic_items(PDO $pdo, string $tag, int $limit = 200): array
{
    $tag = trim($tag);
    if ($tag === '') {
        return [];
    }

    $limit = max(1, min($limit, 500));
    $reviewedPattern = reviewed_pattern_sql();

    $sql = '
        SELECT
            i.id,
            i.canonical_url,
            i.author,
            i.content,
            i.content_type,
            MAX(c.collected_at) AS last_context_at,
            MAX(ud.updated_at) AS note_updated_at,
            MAX(ud.notes) AS notes,
            GROUP_CONCAT(DISTINCT tg.name ORDER BY tg.name SEPARATOR ", ") AS tags
        FROM items i
        INNER JOIN item_user_data ud ON ud.item_id = i.id
        LEFT JOIN item_contexts c ON c.item_id = i.id
        LEFT JOIN item_tags itag ON itag.item_id = i.id
        LEFT JOIN tags tg ON tg.id = itag.tag_id
        WHERE ud.notes IS NOT NULL
          AND NOT (ud.notes REGEXP :re)
          AND EXISTS (
            SELECT 1
            FROM item_tags itf
            INNER JOIN tags tf ON tf.id = itf.tag_id
            WHERE itf.item_id = i.id AND tf.name = :tag
          )
        GROUP BY i.id
        ORDER BY note_updated_at DESC
        LIMIT :lim
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':re', $reviewedPattern, PDO::PARAM_STR);
    $stmt->bindValue(':tag', $tag, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function fetch_topic_stats(PDO $pdo, string $tag): array
{
    $tag = trim($tag);
    if ($tag === '') {
        return [
            'total_processed' => 0,
            'last_note_at' => null,
            'processed_7d' => 0,
            'processed_30d' => 0,
        ];
    }

    $reviewedPattern = reviewed_pattern_sql();
    $since7 = (new DateTimeImmutable('now'))
        ->sub(new DateInterval('P7D'))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
    $since30 = (new DateTimeImmutable('now'))
        ->sub(new DateInterval('P30D'))
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');

    $sql = '
        SELECT
            COUNT(*) AS total_processed,
            MAX(ud.updated_at) AS last_note_at,
            SUM(ud.updated_at >= :since7) AS processed_7d,
            SUM(ud.updated_at >= :since30) AS processed_30d
        FROM item_user_data ud
        WHERE ud.notes IS NOT NULL
          AND NOT (ud.notes REGEXP :re)
          AND EXISTS (
            SELECT 1
            FROM item_tags itf
            INNER JOIN tags tf ON tf.id = itf.tag_id
            WHERE itf.item_id = ud.item_id AND tf.name = :tag
          )
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':re', $reviewedPattern, PDO::PARAM_STR);
    $stmt->bindValue(':tag', $tag, PDO::PARAM_STR);
    $stmt->bindValue(':since7', $since7, PDO::PARAM_STR);
    $stmt->bindValue(':since30', $since30, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch() ?: [];

    return [
        'total_processed' => (int)($row['total_processed'] ?? 0),
        'last_note_at' => $row['last_note_at'] ?? null,
        'processed_7d' => (int)($row['processed_7d'] ?? 0),
        'processed_30d' => (int)($row['processed_30d'] ?? 0),
    ];
}

function search_items(
    PDO $pdo,
    string $query,
    ?string $source,
    ?string $activityKind,
    ?string $contentType,
    ?string $dateFrom,
    ?string $dateTo,
    ?string $tag,
    int $limit = 200,
    bool $includeNotes = false,
    bool $onlyWithNotes = false,
    bool $onlyWithoutNotes = false,
    ?string $author = null
): array {
    $joins = [];
    $where = [];
    $params = [];

    // Backward compatible aliases from legacy Search UI.
    $sourceAliases = [
        'all' => 'activity_all',
        'reactions' => 'activity_reactions',
        'comments' => 'activity_comments',
    ];
    if ($source && isset($sourceAliases[$source])) {
        $source = $sourceAliases[$source];
    }

    // Always join user layer to support badges/filters; notes search stays opt-in.
    $joins[] = 'LEFT JOIN item_user_data ud ON ud.item_id = i.id';
    $joins[] = 'LEFT JOIN item_tags itag ON itag.item_id = i.id';
    $joins[] = 'LEFT JOIN tags tg ON tg.id = itag.tag_id';

    if ($query !== '') {
        // With native prepares (ATTR_EMULATE_PREPARES=false), MySQL/PDO cannot reuse the same named placeholder twice.
        $expr = '(MATCH(i.content, i.author) AGAINST (:q IN NATURAL LANGUAGE MODE) OR i.content LIKE :q_like_content OR i.author LIKE :q_like_author';
        $params[':q'] = $query;
        $params[':q_like_content'] = '%' . $query . '%';
        $params[':q_like_author'] = '%' . $query . '%';

        if ($includeNotes) {
            $expr .= ' OR (ud.notes IS NOT NULL AND (MATCH(ud.notes) AGAINST (:q_notes IN NATURAL LANGUAGE MODE) OR ud.notes LIKE :q_like_notes))';
            $params[':q_notes'] = $query;
            $params[':q_like_notes'] = '%' . $query . '%';
        }

        $expr .= ')';
        $where[] = $expr;
    }

    if ($onlyWithNotes) {
        $where[] = 'ud.item_id IS NOT NULL';
    }

    if ($onlyWithoutNotes) {
        $where[] = 'ud.item_id IS NULL';
    }

    if ($source && in_array($source, ['activity_all', 'activity_reactions', 'activity_comments', 'saved_posts', 'saved_articles'], true)) {
        $where[] = 'c.source = :source';
        $params[':source'] = $source;
    }

    if ($activityKind && in_array($activityKind, ['post', 'share', 'reaction', 'comment', 'save'], true)) {
        $where[] = 'c.activity_kind = :kind';
        $params[':kind'] = $activityKind;
    }

    if ($contentType && in_array($contentType, ['text', 'article', 'video', 'image', 'document', 'unknown'], true)) {
        $where[] = 'i.content_type = :ctype';
        $params[':ctype'] = $contentType;
    }

    if ($dateFrom) {
        $where[] = 'c.collected_at >= :date_from';
        try {
            $dt = new DateTimeImmutable($dateFrom . ' 00:00:00');
            $params[':date_from'] = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
    }

    if ($dateTo) {
        $where[] = 'c.collected_at <= :date_to';
        try {
            $dt = new DateTimeImmutable($dateTo . ' 23:59:59');
            $params[':date_to'] = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }
    }

    if ($tag) {
        // Filter by tag without narrowing the displayed tag list.
        $where[] = 'EXISTS (SELECT 1 FROM item_tags itf INNER JOIN tags tf ON tf.id = itf.tag_id WHERE itf.item_id = i.id AND tf.name = :tag)';
        $params[':tag'] = $tag;
    }

    if ($author !== null && $author !== '') {
        $where[] = 'i.author = :author';
        $params[':author'] = $author;
    }

    $sql = '
        SELECT
            i.id,
            i.item_urn,
            i.canonical_url,
            i.author,
            i.content,
            i.content_type,
            i.published_label,
            MAX(c.collected_at) AS last_context_at,
            GROUP_CONCAT(DISTINCT CONCAT(c.source, ":", c.activity_kind) ORDER BY c.source, c.activity_kind SEPARATOR ", ") AS contexts,
            MAX(ud.item_id IS NOT NULL) AS has_note,
            MAX(ud.notes) AS user_notes,
            GROUP_CONCAT(DISTINCT tg.name ORDER BY tg.name SEPARATOR ", ") AS tags
        FROM items i
        INNER JOIN item_contexts c ON c.item_id = i.id
    ';

    if ($joins) {
        $sql .= ' ' . implode(' ', $joins);
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' GROUP BY i.id ORDER BY last_context_at DESC LIMIT :lim';

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function search_posts(
    PDO $pdo,
    string $query,
    ?string $source,
    ?string $dateFrom,
    ?string $dateTo,
    ?string $tag,
    int $limit = 200
): array {
    $joins = [];
    $where = [];
    $params = [];

    if ($query !== '') {
        // With native prepares (ATTR_EMULATE_PREPARES=false), MySQL/PDO cannot reuse the same named placeholder twice.
        $where[] = '(MATCH(p.content, p.author) AGAINST (:q IN NATURAL LANGUAGE MODE) OR p.content LIKE :q_like_content OR p.author LIKE :q_like_author)';
        $params[':q'] = $query;
        $params[':q_like_content'] = '%' . $query . '%';
        $params[':q_like_author'] = '%' . $query . '%';
    }

    if ($source && in_array($source, ['all', 'reactions', 'comments'], true)) {
        $where[] = 'p.source_page = :source';
        $params[':source'] = $source;
    }

    if ($dateFrom) {
        $where[] = 'p.collected_at >= :date_from';
        try {
            $dt = new DateTimeImmutable($dateFrom . ' 00:00:00');
            $params[':date_from'] = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
    }

    if ($dateTo) {
        $where[] = 'p.collected_at <= :date_to';
        try {
            $dt = new DateTimeImmutable($dateTo . ' 23:59:59');
            $params[':date_to'] = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }
    }

    if ($tag) {
        $joins[] = 'INNER JOIN post_tags pt ON pt.post_id = p.id';
        $joins[] = 'INNER JOIN tags t ON t.id = pt.tag_id';
        $where[] = 't.name = :tag';
        $params[':tag'] = $tag;
    }

    $sql = 'SELECT p.id, p.author, p.content, p.source_page, p.activity_type, p.activity_label, p.post_url, p.published_label, p.collected_at FROM posts p';

    if ($joins) {
        $sql .= ' ' . implode(' ', $joins);
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY p.collected_at DESC LIMIT :lim';

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

// ---------------------------
// Editorial (Redakcja) layer
// ---------------------------

function editorial_allowed_statuses(): array
{
    return ['selected', 'draft', 'in_progress', 'ready', 'published', 'archived'];
}

function editorial_allowed_topics(): array
{
    return ['ai', 'oss', 'programming', 'fundamentals', 'other'];
}

function editorial_allowed_formats(): array
{
    return ['curation', 'article', 'tools', 'weekly_digest'];
}

function fetch_editorial_item_by_source(PDO $pdo, int $sourceItemId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT e.id, e.source_item_id, e.editorial_status, e.portal_topic, e.priority, e.selected_at, e.updated_at, d.id AS draft_id
         FROM editorial_items e
         LEFT JOIN editorial_drafts d ON d.editorial_item_id = e.id
         WHERE e.source_item_id = :id
         LIMIT 1'
    );
    $stmt->bindValue(':id', $sourceItemId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_editorial_item_from_source(PDO $pdo, int $sourceItemId, ?string $portalTopic = null, int $priority = 3): array
{
    $topic = (string)($portalTopic ?? '');
    if (!in_array($topic, editorial_allowed_topics(), true)) {
        $topic = 'other';
    }
    $priority = max(1, min($priority, 5));

    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO editorial_items (source_item_id, portal_topic, priority) VALUES (:item_id, :topic, :prio)'
    );
    $stmt->bindValue(':item_id', $sourceItemId, PDO::PARAM_INT);
    $stmt->bindValue(':topic', $topic, PDO::PARAM_STR);
    $stmt->bindValue(':prio', $priority, PDO::PARAM_INT);
    $stmt->execute();
    $created = $stmt->rowCount() > 0;

    $stmt2 = $pdo->prepare('SELECT id FROM editorial_items WHERE source_item_id = :item_id LIMIT 1');
    $stmt2->bindValue(':item_id', $sourceItemId, PDO::PARAM_INT);
    $stmt2->execute();
    $id = (int)($stmt2->fetchColumn() ?: 0);

    return ['id' => $id, 'created' => $created];
}

function update_editorial_item(PDO $pdo, int $id, ?string $status, ?string $topic, ?int $priority): bool
{
    $sets = [];
    $params = [':id' => $id];

    if ($status !== null && $status !== '') {
        if (in_array($status, editorial_allowed_statuses(), true)) {
            $sets[] = 'editorial_status = :st';
            $params[':st'] = $status;
        }
    }

    if ($topic !== null && $topic !== '') {
        if (in_array($topic, editorial_allowed_topics(), true)) {
            $sets[] = 'portal_topic = :tp';
            $params[':tp'] = $topic;
        }
    }

    if (is_int($priority)) {
        $prio = max(1, min($priority, 5));
        $sets[] = 'priority = :pr';
        $params[':pr'] = $prio;
    }

    if (!$sets) {
        return false;
    }

    $sql = 'UPDATE editorial_items SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->rowCount() > 0;
}

function fetch_editorial_inbox(
    PDO $pdo,
    ?string $status = null,
    ?string $topic = null,
    ?int $priority = null,
    bool $onlyWithoutDraft = false,
    int $limit = 200
): array {
    $limit = max(1, min($limit, 500));

    $where = [];
    $params = [];

    if ($status !== null && $status !== '') {
        if ($status === 'active') {
            $where[] = "e.editorial_status IN ('selected','draft','in_progress','ready')";
        } elseif (in_array($status, editorial_allowed_statuses(), true)) {
            $where[] = 'e.editorial_status = :st';
            $params[':st'] = $status;
        }
    } else {
        // Default: active queue.
        $where[] = "e.editorial_status IN ('selected','draft','in_progress','ready')";
    }

    if ($topic !== null && $topic !== '' && in_array($topic, editorial_allowed_topics(), true)) {
        $where[] = 'e.portal_topic = :tp';
        $params[':tp'] = $topic;
    }

    if (is_int($priority)) {
        $prio = max(1, min($priority, 5));
        $where[] = 'e.priority = :pr';
        $params[':pr'] = $prio;
    }

    if ($onlyWithoutDraft) {
        $where[] = 'd.id IS NULL';
    }

    $sql = '
        SELECT
            e.id,
            e.source_item_id,
            e.editorial_status,
            e.portal_topic,
            e.priority,
            e.selected_at,
            e.updated_at,
            MAX(i.author) AS author,
            MAX(i.content) AS content,
            MAX(i.content_type) AS content_type,
            MAX(i.canonical_url) AS canonical_url,
            MAX(ud.notes) AS notes,
            MAX(ud.updated_at) AS note_updated_at,
            MAX(d.id) AS draft_id,
            MAX(d.updated_at) AS draft_updated_at,
            GROUP_CONCAT(DISTINCT tg.name ORDER BY tg.name SEPARATOR ", ") AS tags
        FROM editorial_items e
        INNER JOIN items i ON i.id = e.source_item_id
        LEFT JOIN item_user_data ud ON ud.item_id = i.id
        LEFT JOIN editorial_drafts d ON d.editorial_item_id = e.id
        LEFT JOIN item_tags itag ON itag.item_id = i.id
        LEFT JOIN tags tg ON tg.id = itag.tag_id
    ';

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' GROUP BY e.id ORDER BY e.priority DESC, e.updated_at DESC LIMIT :lim';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function fetch_editorial_queue_counts(PDO $pdo, ?string $topic = null, ?int $priority = null, bool $onlyWithoutDraft = false): array
{
    // Active editorial queue only (the "what are we writing today" slice).
    $where = ["e.editorial_status IN ('selected','draft','in_progress','ready')"];
    $params = [];

    if ($topic !== null && $topic !== '' && in_array($topic, editorial_allowed_topics(), true)) {
        $where[] = 'e.portal_topic = :tp';
        $params[':tp'] = $topic;
    }

    if (is_int($priority)) {
        $prio = max(1, min($priority, 5));
        $where[] = 'e.priority = :pr';
        $params[':pr'] = $prio;
    }

    if ($onlyWithoutDraft) {
        $where[] = 'd.id IS NULL';
    }

    $sql = '
        SELECT e.editorial_status AS st, COUNT(*) AS cnt
        FROM editorial_items e
        LEFT JOIN editorial_drafts d ON d.editorial_item_id = e.id
    ';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY e.editorial_status';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();

    $counts = [
        'selected' => 0,
        'draft' => 0,
        'in_progress' => 0,
        'ready' => 0,
    ];
    foreach ($stmt->fetchAll() as $row) {
        $st = (string)($row['st'] ?? '');
        $cnt = (int)($row['cnt'] ?? 0);
        if (array_key_exists($st, $counts)) {
            $counts[$st] = $cnt;
        }
    }
    $counts['total'] = array_sum($counts);
    return $counts;
}

function fetch_editorial_drafts(PDO $pdo, ?string $status = null, int $limit = 200): array
{
    $limit = max(1, min($limit, 500));
    $where = [];
    $params = [];

    if ($status !== null && $status !== '' && in_array($status, editorial_allowed_statuses(), true)) {
        $where[] = 'e.editorial_status = :st';
        $params[':st'] = $status;
    }

    $sql = '
        SELECT
            d.id,
            d.editorial_item_id,
            MAX(d.title) AS title,
            MAX(d.format) AS format,
            MAX(d.cms_status) AS cms_status,
            MAX(d.updated_at) AS updated_at,
            e.editorial_status,
            e.portal_topic,
            e.priority,
            e.source_item_id,
            MAX(i.author) AS author,
            MAX(i.content_type) AS content_type,
            MAX(i.canonical_url) AS canonical_url
        FROM editorial_drafts d
        INNER JOIN editorial_items e ON e.id = d.editorial_item_id
        INNER JOIN items i ON i.id = e.source_item_id
    ';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' GROUP BY d.id ORDER BY updated_at DESC LIMIT :lim';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function fetch_editorial_draft(PDO $pdo, int $draftId): ?array
{
    $sql = '
        SELECT
            d.id,
            d.editorial_item_id,
            d.title,
            d.lead_text,
            d.body,
            d.format,
            d.seo_title,
            d.seo_description,
            d.cms_status,
            d.cms_external_id,
            d.created_at,
            d.updated_at,
            e.editorial_status,
            e.portal_topic,
            e.priority,
            e.source_item_id,
            MAX(i.author) AS author,
            MAX(i.content_type) AS content_type,
            MAX(i.canonical_url) AS canonical_url,
            MAX(i.content) AS source_content,
            MAX(ud.notes) AS source_notes,
            GROUP_CONCAT(DISTINCT tg.name ORDER BY tg.name SEPARATOR ", ") AS tags
        FROM editorial_drafts d
        INNER JOIN editorial_items e ON e.id = d.editorial_item_id
        INNER JOIN items i ON i.id = e.source_item_id
        LEFT JOIN item_user_data ud ON ud.item_id = i.id
        LEFT JOIN item_tags itag ON itag.item_id = i.id
        LEFT JOIN tags tg ON tg.id = itag.tag_id
        WHERE d.id = :id
        GROUP BY d.id
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $draftId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    return $row ?: null;
}

function update_editorial_draft(PDO $pdo, int $draftId, array $fields): bool
{
    $sets = [];
    $params = [':id' => $draftId];

    if (isset($fields['title'])) {
        $title = trim((string)$fields['title']);
        if ($title === '') {
            $title = 'Roboczy tytuł';
        }
        $sets[] = 'title = :t';
        $params[':t'] = $title;
    }
    if (array_key_exists('lead_text', $fields)) {
        $sets[] = 'lead_text = :l';
        $params[':l'] = (string)$fields['lead_text'];
    }
    if (array_key_exists('body', $fields)) {
        $sets[] = 'body = :b';
        $params[':b'] = (string)$fields['body'];
    }
    if (isset($fields['format'])) {
        $fmt = (string)$fields['format'];
        if (in_array($fmt, editorial_allowed_formats(), true)) {
            $sets[] = 'format = :f';
            $params[':f'] = $fmt;
        }
    }
    if (array_key_exists('seo_title', $fields)) {
        $sets[] = 'seo_title = :st';
        $params[':st'] = (string)$fields['seo_title'];
    }
    if (array_key_exists('seo_description', $fields)) {
        $sets[] = 'seo_description = :sd';
        $params[':sd'] = (string)$fields['seo_description'];
    }

    if (!$sets) {
        return false;
    }

    $sql = 'UPDATE editorial_drafts SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->rowCount() > 0;
}

function update_editorial_draft_cms(PDO $pdo, int $draftId, string $cmsStatus, ?string $cmsExternalId): bool
{
    $allowed = ['local_draft', 'sent_to_cms', 'published'];
    if (!in_array($cmsStatus, $allowed, true)) {
        return false;
    }

    $sql = 'UPDATE editorial_drafts SET cms_status = :st, cms_external_id = :eid WHERE id = :id LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':st', $cmsStatus, PDO::PARAM_STR);
    if ($cmsExternalId === null || trim($cmsExternalId) === '') {
        $stmt->bindValue(':eid', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':eid', trim($cmsExternalId), PDO::PARAM_STR);
    }
    $stmt->bindValue(':id', $draftId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount() > 0;
}

function create_editorial_draft_from_item(PDO $pdo, int $editorialItemId): array
{
    // If draft exists (v1.0: unique per editorial_item), return it.
    $stmt0 = $pdo->prepare('SELECT id FROM editorial_drafts WHERE editorial_item_id = :id LIMIT 1');
    $stmt0->bindValue(':id', $editorialItemId, PDO::PARAM_INT);
    $stmt0->execute();
    $existingId = (int)($stmt0->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return ['id' => $existingId, 'created' => false];
    }

    $stmt = $pdo->prepare(
        'SELECT e.id AS editorial_item_id, e.source_item_id, i.author, i.canonical_url, i.content, ud.notes
         FROM editorial_items e
         INNER JOIN items i ON i.id = e.source_item_id
         LEFT JOIN item_user_data ud ON ud.item_id = i.id
         WHERE e.id = :id
         LIMIT 1'
    );
    $stmt->bindValue(':id', $editorialItemId, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Editorial item not found.');
    }

    $notes = (string)($row['notes'] ?? '');
    if (!is_processed_with_content($notes)) {
        throw new RuntimeException('Wymagana notatka merytoryczna (processed) aby utworzyć szkic.');
    }

    $content = trim((string)($row['content'] ?? ''));
    $author = trim((string)($row['author'] ?? ''));
    $url = trim((string)($row['canonical_url'] ?? ''));

    $titleBase = preg_replace('/\\s+/u', ' ', $content) ?: '';
    $title = trim(short_text((string)$titleBase, 110));
    $title = rtrim($title, " .\t\n\r\0\x0B");
    if ($title === '') {
        $title = 'Roboczy tytuł';
    }

    $lead = trim(short_text($notes, 280));
    $today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
    $body = "# Szkic (auto)\n\n"
        . "## Moje wnioski\n"
        . $notes . "\n\n"
        . "## Źródło\n"
        . "- Autor: " . ($author !== '' ? $author : '-') . "\n"
        . "- Link: " . ($url !== '' ? $url : '-') . "\n"
        . "- Data: {$today}\n\n"
        . "## Treść źródła (snapshot)\n"
        . $content . "\n";

    $stmt2 = $pdo->prepare(
        'INSERT INTO editorial_drafts (editorial_item_id, title, lead_text, body, format) VALUES (:eid, :t, :l, :b, :f)'
    );
    $stmt2->bindValue(':eid', $editorialItemId, PDO::PARAM_INT);
    $stmt2->bindValue(':t', $title, PDO::PARAM_STR);
    $stmt2->bindValue(':l', $lead, PDO::PARAM_STR);
    $stmt2->bindValue(':b', $body, PDO::PARAM_STR);
    $stmt2->bindValue(':f', 'curation', PDO::PARAM_STR);
    $stmt2->execute();
    $draftId = (int)$pdo->lastInsertId();

    // Move from selected -> draft if still in the initial state.
    $stmt3 = $pdo->prepare(
        "UPDATE editorial_items SET editorial_status = IF(editorial_status = 'selected', 'draft', editorial_status) WHERE id = :id LIMIT 1"
    );
    $stmt3->bindValue(':id', $editorialItemId, PDO::PARAM_INT);
    $stmt3->execute();

    return ['id' => $draftId, 'created' => true];
}

// CMS integrations (runtime config stored in DB).

function get_cms_integration(PDO $pdo, string $type): ?array
{
    $type = trim($type);
    if (!in_array($type, ['strapi'], true)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT id, type, base_url, content_type, api_token_enc, enabled, updated_at
             FROM cms_integrations
             WHERE type = :t
             LIMIT 1'
        );
        $stmt->bindValue(':t', $type, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        // Table might not exist yet on an existing volume; fallback to ENV in that case.
        if ($e->getCode() === '42S02') {
            return null;
        }
        throw $e;
    }
}

function upsert_cms_integration(PDO $pdo, string $type, array $data): bool
{
    $type = trim($type);
    if (!in_array($type, ['strapi'], true)) {
        return false;
    }

    $existing = get_cms_integration($pdo, $type);
    $baseUrl = rtrim(trim((string)($data['base_url'] ?? ($existing['base_url'] ?? ''))), '/');
    $contentType = trim((string)($data['content_type'] ?? ($existing['content_type'] ?? '')));
    $enabled = array_key_exists('enabled', $data) ? ((int)((bool)$data['enabled'])) : (int)($existing['enabled'] ?? 1);
    $apiTokenEnc = array_key_exists('api_token_enc', $data)
        ? (string)$data['api_token_enc']
        : (string)($existing['api_token_enc'] ?? '');

    $stmt = $pdo->prepare(
        'INSERT INTO cms_integrations (type, base_url, content_type, api_token_enc, enabled)
         VALUES (:t, :bu, :ct, :tok, :en)
         ON DUPLICATE KEY UPDATE
           base_url = VALUES(base_url),
           content_type = VALUES(content_type),
           api_token_enc = VALUES(api_token_enc),
           enabled = VALUES(enabled)'
    );
    $stmt->bindValue(':t', $type, PDO::PARAM_STR);
    $stmt->bindValue(':bu', $baseUrl, PDO::PARAM_STR);
    $stmt->bindValue(':ct', $contentType, PDO::PARAM_STR);
    $stmt->bindValue(':tok', $apiTokenEnc, PDO::PARAM_STR);
    $stmt->bindValue(':en', $enabled, PDO::PARAM_INT);
    $stmt->execute();

    return true;
}

function encrypt_token(string $plain): string
{
    $plain = (string)$plain;
    if (trim($plain) === '') {
        return '';
    }

    $secret = trim((string)(getenv('APP_SECRET') ?: ''));
    if ($secret === '') {
        throw new RuntimeException('APP_SECRET is not set (cannot encrypt API token).');
    }
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL extension is required to encrypt API token.');
    }

    $key = hash('sha256', $secret, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipher) || $cipher === '' || !is_string($tag) || strlen($tag) !== 16) {
        throw new RuntimeException('openssl_encrypt failed.');
    }

    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function decrypt_token(string $enc): string
{
    $enc = trim((string)$enc);
    if ($enc === '') {
        return '';
    }

    $secret = trim((string)(getenv('APP_SECRET') ?: ''));
    if ($secret === '') {
        throw new RuntimeException('APP_SECRET is not set (cannot decrypt API token).');
    }
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL extension is required to decrypt API token.');
    }

    $rawB64 = $enc;
    if (str_starts_with($enc, 'v1:')) {
        $rawB64 = substr($enc, 3);
    }
    $buf = base64_decode($rawB64, true);
    if (!is_string($buf) || strlen($buf) < (12 + 16 + 1)) {
        throw new RuntimeException('Invalid encrypted token format.');
    }

    $iv = substr($buf, 0, 12);
    $tag = substr($buf, 12, 16);
    $cipher = substr($buf, 28);
    $key = hash('sha256', $secret, true);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($plain)) {
        throw new RuntimeException('Failed to decrypt API token (wrong APP_SECRET?).');
    }
    return $plain;
}

function mask_api_token(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return 'brak';
    }

    $suffix = strlen($token) >= 4 ? substr($token, -4) : $token;
    $prefix = str_starts_with($token, 'sk_') ? 'sk_' : '';
    return $prefix . '****' . $suffix;
}

/**
 * Resolve Strapi runtime config.
 * - If DB row exists: DB is the source of truth.
 *   - enabled=0 disables integration (no ENV fallback).
 * - If DB row does not exist: fall back to ENV (bootstrap).
 */
function get_strapi_config(PDO $pdo): array
{
    $envBase = rtrim((string)(getenv('STRAPI_BASE_URL') ?: ''), '/');
    $envCt = trim((string)(getenv('STRAPI_CONTENT_TYPE') ?: ''));
    $envTok = trim((string)(getenv('STRAPI_API_TOKEN') ?: ''));

    $row = get_cms_integration($pdo, 'strapi');
    if (!$row) {
        $base = $envBase;
        $ct = $envCt;
        $tok = $envTok;
        $enabled = true;
        $tokenEnc = '';
        $source = 'env';
        $updatedAt = null;
        $hasDbRow = false;
        $errors = [];
    } else {
        $base = rtrim((string)($row['base_url'] ?? ''), '/');
        $ct = trim((string)($row['content_type'] ?? ''));
        $enabled = (int)($row['enabled'] ?? 1) === 1;
        $tokenEnc = (string)($row['api_token_enc'] ?? '');
        $source = 'db';
        $updatedAt = (string)($row['updated_at'] ?? '');
        $hasDbRow = true;
        $errors = [];

        $tok = '';
        if ($enabled && $tokenEnc !== '') {
            try {
                $tok = decrypt_token($tokenEnc);
            } catch (RuntimeException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'APP_SECRET')) {
                    $msg = 'Brak APP_SECRET – ustaw APP_SECRET i zrestartuj kontener web: docker compose up -d --force-recreate web';
                }
                $errors[] = $msg;
                $tok = '';
            }
        }
    }

    $baseOk = $base !== '' && filter_var($base, FILTER_VALIDATE_URL);
    $ctOk = $ct !== '';
    $tokOk = $tok !== '';
    $ready = $enabled && $baseOk && $ctOk && $tokOk;
    $disabled = $hasDbRow && !$enabled;
    $partial = !$ready && !$disabled && ($base !== '' || $ct !== '' || $tok !== '' || $tokenEnc !== '');

    $endpointBase = '';
    if ($base !== '' && $ct !== '') {
        $endpointBase = rtrim($base, '/') . '/api/' . trim($ct, '/');
    }

    $secretOk = trim((string)(getenv('APP_SECRET') ?: '')) !== '';
    $tokenMask = $tokOk ? mask_api_token($tok) : ($tokenEnc !== '' ? 'zaszyfrowany' : 'brak');

    return [
        'source' => $source, // env|db
        'has_db_row' => $hasDbRow,
        'enabled' => $enabled,
        'disabled' => $disabled,
        'base_url' => $base,
        'content_type' => $ct,
        'api_token' => $tok,
        'api_token_enc_present' => $tokenEnc !== '',
        'api_token_mask' => $tokenMask,
        'endpoint_base' => $endpointBase,
        'ready' => $ready,
        'partial' => $partial,
        'secret_ok' => $secretOk,
        'updated_at' => $updatedAt,
        'errors' => $errors,
        'env' => [
            'base_url' => $envBase,
            'content_type' => $envCt,
            'api_token_present' => $envTok !== '',
        ],
    ];
}
