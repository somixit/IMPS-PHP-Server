<?php
// raw_input.php
function read_raw_http_body(): string
{
    $raw = file_get_contents('php://input');
    return is_string($raw) ? $raw : '';
}

function request_content_type(): string
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    return strtolower(trim(explode(';', (string)$ct)[0]));
}

function is_wbxml(string $raw, string $contentType): bool
{
    if (str_contains($contentType, 'wbxml')) {
        return true;
    }
    return $raw !== '' && ord($raw[0]) >= 1 && ord($raw[0]) <= 3 && !str_starts_with(ltrim($raw), '<');
}
