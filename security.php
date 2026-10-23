<?php
// security.php - Anti-bots (captcha simple) y anti fuerza bruta por IP.
// Lo incluyen login.php y register.php. No se sube al repo (privado).

// Solo funciona al ser incluido; si se abre directamente, no hace nada.
if (basename(__FILE__) === basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/db.php';

// --- Captcha simple (pregunta matematica, sin librerias externas) ---------

// Genera una pregunta y guarda la respuesta en la sesion. Devuelve el texto
// de la pregunta para mostrar en el formulario.
function captcha_generate(): string
{
    $a = random_int(2, 9);
    $b = random_int(1, 9);
    $op = random_int(0, 1) === 0 ? '+' : '-';
    if ($op === '-' && $b > $a) {
        [$a, $b] = [$b, $a]; // nunca resultados negativos
    }
    $answer = $op === '+' ? $a + $b : $a - $b;
    $_SESSION['captcha'] = (string)$answer;
    $_SESSION['captcha_time'] = time();
    return $a . ' ' . $op . ' ' . $b . ' = ?';
}

// Comprueba la respuesta del captcha. Borra la pregunta para que no se pueda
// reutilizar. Devuelve true si es correcta.
function captcha_verify(string $value): bool
{
    $ok = isset($_SESSION['captcha']) && hash_equals((string)$_SESSION['captcha'], trim($value));
    unset($_SESSION['captcha'], $_SESSION['captcha_time']);
    return $ok;
}

// --- Anti fuerza bruta por IP ----------------------------------------------

// IP del cliente. REMOTE_ADDR salvo que el hosting este detras de proxy/CDN
// (configurable 'trust_forwarded_for').
function client_ip(): string
{
    $config = require __DIR__ . '/config.php';
    if (!empty($config['trust_forwarded_for']) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

// Devuelve un mensaje de bloqueo si la IP esta bloqueada, o null si puede
// seguir. Aplica la ventana de intentos y el bloqueo temporal.
function throttle_check(string $ip): ?string
{
    $config = require __DIR__ . '/config.php';
    $max = (int)($config['throttle_max_attempts'] ?? 10);
    $window = (int)($config['throttle_window_seconds'] ?? 300);
    $blockHours = (int)($config['throttle_block_hours'] ?? 1);

    try {
        $st = db()->prepare('SELECT attempts, first_attempt, last_attempt, blocked_until FROM login_attempts WHERE ip = ?');
        $st->execute([$ip]);
        $row = $st->fetch();
        $st->closeCursor();
    } catch (Throwable $e) {
        return null;
    }

    $now = time();
    $nowSql = gmdate('Y-m-d H:i:s', $now);

    if ($row) {
        // Bloqueada todavia?
        if (!empty($row['blocked_until']) && strtotime((string)$row['blocked_until'] . ' UTC') > $now) {
            $left = (strtotime((string)$row['blocked_until'] . ' UTC') - $now) / 60;
            $h = floor($left / 60);
            $m = (int)ceil($left - $h * 60);
            return $h > 0
                ? 'Too many attempts. Try again in ' . $h . 'h ' . $m . 'm.'
                : 'Too many attempts. Try again in ' . $m . ' minute' . ($m === 1 ? '' : 's') . '.';
        }
        // Reinicia la ventana si ya paso
        if (strtotime((string)$row['last_attempt'] . ' UTC') < $now - $window) {
            db()->prepare('UPDATE login_attempts SET attempts = 0, first_attempt = ?, last_attempt = ?, blocked_until = NULL WHERE ip = ?')
                ->execute([$nowSql, $nowSql, $ip]);
            return null;
        }
        // Supera el limite?
        if ((int)$row['attempts'] >= $max) {
            $until = gmdate('Y-m-d H:i:s', $now + $blockHours * 3600);
            db()->prepare('UPDATE login_attempts SET blocked_until = ? WHERE ip = ?')->execute([$until, $ip]);
            return 'Too many attempts. Try again in ' . $blockHours . ' hour' . ($blockHours === 1 ? '' : 's') . '.';
        }
    }
    return null;
}

// Registra un intento. $success = true lo limpia; si no, incrementa el
// contador y bloquea si llega al limite.
function throttle_record(string $ip, bool $success): void
{
    $config = require __DIR__ . '/config.php';
    $max = (int)($config['throttle_max_attempts'] ?? 10);
    $window = (int)($config['throttle_window_seconds'] ?? 300);
    $blockHours = (int)($config['throttle_block_hours'] ?? 1);

    $now = time();
    $nowSql = gmdate('Y-m-d H:i:s', $now);

    try {
        if ($success) {
            db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
            return;
        }
        $st = db()->prepare('SELECT attempts, last_attempt FROM login_attempts WHERE ip = ?');
        $st->execute([$ip]);
        $row = $st->fetch();
        $st->closeCursor();
        if (!$row || strtotime((string)$row['last_attempt'] . ' UTC') < $now - $window) {
            db()->prepare('INSERT INTO login_attempts (ip, attempts, first_attempt, last_attempt) VALUES (?, 1, ?, ?)
                ON CONFLICT(ip) DO UPDATE SET attempts = 1, first_attempt = excluded.first_attempt, last_attempt = excluded.last_attempt, blocked_until = NULL')
                ->execute([$ip, $nowSql, $nowSql]);
            return;
        }
        $attempts = (int)$row['attempts'] + 1;
        if ($attempts >= $max) {
            $until = gmdate('Y-m-d H:i:s', $now + $blockHours * 3600);
            db()->prepare('UPDATE login_attempts SET attempts = ?, last_attempt = ?, blocked_until = ? WHERE ip = ?')
                ->execute([$attempts, $nowSql, $until, $ip]);
        } else {
            db()->prepare('UPDATE login_attempts SET attempts = ?, last_attempt = ? WHERE ip = ?')
                ->execute([$attempts, $nowSql, $ip]);
        }
    } catch (Throwable $e) {
    }
}
