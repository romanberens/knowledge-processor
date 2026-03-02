<?php

declare(strict_types=1);

session_start();

date_default_timezone_set(getenv('APP_TZ') ?: 'UTC');

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function short_text(?string $value, int $max = 180): string
{
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        return '';
    }

    // Keep UTF-8 intact without requiring mbstring in the container.
    if (function_exists('iconv_strlen') && function_exists('iconv_substr')) {
        $len = (int)iconv_strlen($text, 'UTF-8');
        if ($len <= $max) {
            return $text;
        }
        return (string)iconv_substr($text, 0, $max - 1, 'UTF-8') . '...';
    }

    if (strlen($text) <= $max) {
        return $text;
    }
    return substr($text, 0, $max - 1) . '...';
}

function format_dt(?string $value): string
{
    if (!$value) {
        return '-';
    }
    try {
        // DB times are stored as naive DATETIME in UTC. Interpret as UTC and render in app TZ.
        $utc = new DateTimeZone('UTC');
        $app = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $dt = new DateTimeImmutable($value, $utc);
        return $dt->setTimezone($app)->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return $value;
    }
}
