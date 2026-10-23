<?php
// avatar.php - sirve la imagen de estado (WV-029 StatusContent) de un usuario.
// La imagen llega del movil dentro de UpdatePresence-Request como base64
// (DirectContent) o como URL (ReferredContent). Solo se sirve a usuarios
// autenticados y nunca si el dueno del avatar ha bloqueado al que mira.
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    exit;
}
$uid = (int)$user['id'];

function clean_nick(string $value): string
{
    $value = trim(preg_replace('/^wv:/i', '', $value, 1));
    $parts = preg_split('~/~', (string)$value);
    return trim((string)($parts[0] ?? ''));
}

$nick = clean_nick((string)($_GET['nick'] ?? ''));
if ($nick === '') {
    http_response_code(400);
    exit;
}

// Si el contacto ha bloqueado al que mira, no se muestra su imagen.
if (presence_hidden($uid, $nick)) {
    http_response_code(404);
    exit;
}

$stmt = db()->prepare('SELECT u.id, p.status_content, p.content_type, p.content_kind FROM presence p JOIN users u ON u.id = p.user_id WHERE u.nick = ?');
$stmt->execute([$nick]);
$row = $stmt->fetch() ?: [];
// Autorizacion de presencia (CSP 1.2 §8.2): si el dueno no ha autorizado el
// atributo StatusContent a quien mira, no se sirve la imagen.
$allowed = authorized_attrs((int)($row['id'] ?? 0), (string)$user['nick']);
if ($allowed !== null && !in_array('StatusContent', $allowed, true)) {
    http_response_code(404);
    exit;
}
$content = (string)($row['status_content'] ?? '');
$kind = (string)($row['content_kind'] ?? '');
$type = trim((string)($row['content_type'] ?? ''));
if ($content === '') {
    http_response_code(404);
    exit;
}

// ReferredContent: redirigimos a la URL externa (solo http/https).
if ($kind === 'referred') {
    if (preg_match('~^https?://~i', $content)) {
        header('Cache-Control: private, max-age=3600');
        header('Location: ' . $content, true, 302);
        exit;
    }
    http_response_code(404);
    exit;
}

$data = base64_decode($content, true);
if ($data === false || $data === '') {
    http_response_code(404);
    exit;
}

// Tipo MIME: el declarado si es imagen; si no, se deduce de la cabecera.
if (!preg_match('~^image/[a-z0-9.+-]+$~i', $type)) {
    $type = 'image/jpeg';
    if (substr($data, 0, 8) === "\x89PNG\r\n\x1a\n") {
        $type = 'image/png';
    } elseif (substr($data, 0, 6) === 'GIF87a' || substr($data, 0, 6) === 'GIF89a') {
        $type = 'image/gif';
    }
}

header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($data));
header('Cache-Control: private, max-age=86400');
header('ETag: "' . substr(md5($data), 0, 16) . '"');
echo $data;
