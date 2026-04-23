<?php

function jsonResponse($data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function cleanDate(?string $date): ?string
{
    if (!$date) {
        return null;
    }

    $date = preg_replace('/[^0-9]/', '', $date);

    return strlen($date) === 8 ? $date : null;
}

function saveRawResponse(string $prefix, string $content): ?string
{
    $dir = defined('DATA_PATH') ? DATA_PATH : (__DIR__ . '/../data/');

    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $filename = $prefix . '_' . date('Ymd_His') . '.json';
    $fullPath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    $ok = @file_put_contents($fullPath, $content);

    return ($ok !== false) ? $fullPath : null;
}
