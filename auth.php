<?php
// auth.php
if (session_status() !== PHP_SESSION_ACTIVE) {
    // Cookies de sesion web: no accesibles desde JS y no enviadas en peticiones
    // cruzadas. No se marca Secure porque el sitio va por HTTP (el movil IMPS
    // no usa esta sesion).
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, nick, domain, created_at FROM users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function require_login(): void
{
    if (current_user() === null) {
        header('Location: login.php');
        exit;
    }
}
