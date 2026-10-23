<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

// Al salir de la web, marcar al usuario como desconectado para que el movil
// deje de verlo "online".
$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid > 0) {
    // CSP 1.2 §6.5, igual que el Logout-Request del movil: al desconectar se
    // abandona los grupos en los que estabamos dentro (la pertenencia se
    // mantiene, asi que el grupo sigue "en la agenda" y se reentra con un clic)
    // y se avisa a los demas.
    try {
        group_leave_all($uid);
    } catch (Throwable $e) {
        // ignore
    }
    try {
        db()->prepare('UPDATE presence SET online = 0, updated_at = datetime("now") WHERE user_id = ?')->execute([$uid]);
    } catch (Throwable $e) {
        // ignore
    }
}

session_destroy();
header('Location: login.php');
exit;
