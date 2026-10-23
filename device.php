<?php
// device.php - contrasena propia del cliente IMPS (el "My Friends" del movil).
//
// El movil no necesita nada especial: en su pantalla de login se escribe el
// nick y esta contrasena, y el propio cliente hace el digest MD5(nonce+pass)
// que ya implementa imps.php. Es independiente de la contrasena web.
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lang.php';
require __DIR__ . '/imps_crypto.php';
require_login();

$user = current_user();
$uid = (int)$user['id'];
$nick = (string)$user['nick'];
$embed = isset($_GET['embed']) || (($_POST['embed'] ?? '') === '1');
$partial = isset($_GET['partial']) || (($_POST['partial'] ?? '') === '1');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['csrf'];

$errors = [];
$flash = $_SESSION['imps_flash'] ?? null;
unset($_SESSION['imps_flash']);

$stmt = db()->prepare('SELECT imps_password FROM users WHERE id = ?');
$stmt->execute([$uid]);
$storedPassword = trim((string)$stmt->fetchColumn());
$stmt->closeCursor();
$hasPassword = $storedPassword !== '';
// Si hay contrasena guardada pero la clave no la descifra, el movil no podra
// entrar aunque la web diga que esta configurada.
$keyOk = !$hasPassword || imps_decrypt($storedPassword) !== '';

function device_redirect(): void
{
    global $embed;
    header('Location: ' . ($embed ? 'device.php?embed=1' : 'device.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $token)) {
        $errors[] = t('device_csrf');
    } else {
        $action = (string)($_POST['action'] ?? '');
        $newPassword = '';
        $notice = '';

        if ($action === 'set') {
            $candidate = trim((string)($_POST['password'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._-]{8,50}$/', $candidate)) {
                $errors[] = t('device_invalid');
            } else {
                $newPassword = $candidate;
                $notice = t('device_saved');
            }
        } else {
            $errors[] = t('invalid_action');
        }

        if ($errors === [] && $newPassword !== '') {
            $enc = imps_encrypt($newPassword);
            if ($enc === '') {
                $errors[] = t('device_key_error');
            } else {
                // La contraseña web y la del móvil se mantienen iguales: al
                // cambiar una se cambia la otra.
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET password_hash = ?, imps_password = ? WHERE id = ?')
                    ->execute([$hash, $enc, $uid]);
                // Al cambiar la contrasena se cierran las sesiones abiertas: si
                // el movil estaba dentro, tendra que volver a entrar.
                db()->prepare('DELETE FROM wv_sessions WHERE user_id = ?')->execute([$uid]);
                db()->prepare('DELETE FROM imps_challenges WHERE nick = ?')->execute([$nick]);
                if ($partial) {
                    $flash = ['password' => $newPassword, 'notice' => $notice];
                } else {
                    $_SESSION['imps_flash'] = ['password' => $newPassword, 'notice' => $notice];
                    device_redirect();
                }
            }
        }
    }
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 19 Nov 1981 08:52:00 GMT');
?>
<?php
ob_start();
?>
<div class="<?= $partial ? 'device-panel' : 'box' ?>">
<h1><?= h(t('device_title')) ?></h1>
<p class="sub"><?= h(t('device_sub')) ?> · <?= h($nick) ?></p>
<p><?= h(t('device_intro')) ?></p>

<?php foreach ($errors as $e): ?>
  <div class="msg"><?= h($e) ?></div>
<?php endforeach; ?>

<?php if ($hasPassword && !$keyOk): ?>
  <div class="msg"><?= h(t('device_key_broken')) ?></div>
<?php endif; ?>

<?php if (is_array($flash) && ($flash['password'] ?? '') !== ''): ?>
  <div class="ok"><?= h($flash['notice'] ?? '') ?></div>
  <p><strong><?= h(t('device_new')) ?></strong></p>
  <code class="password"><?= h($flash['password']) ?></code>
<?php elseif (is_array($flash) && ($flash['notice'] ?? '') !== ''): ?>
  <div class="ok"><?= h($flash['notice']) ?></div>
<?php endif; ?>

<p class="state <?= $hasPassword ? 'on' : 'off' ?>">
  <?= h($hasPassword ? t('device_configured') : t('device_not_configured')) ?>
</p>

<?php if (!(is_array($flash) && ($flash['password'] ?? '') !== '')): ?>
<form method="post" id="password-form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="set">
  <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
  <label for="password"><?= h(t('device_custom')) ?></label>
  <input id="password" name="password" autocomplete="new-password" maxlength="50" placeholder="K7M2QP9X4RTW" value="<?= h(is_array($flash) ? ($flash['password'] ?? '') : '') ?>">
  <div class="actions">
    <button type="button" class="secondary" onclick="generatePassword()"><?= h(t('device_generate')) ?></button>
    <button type="submit"><?= h(t('device_save')) ?></button>
  </div>
</form>
<?php endif; ?>





</div>
<?php
$panel = ob_get_clean();
if ($partial) { echo $panel; exit; }
?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(t('device_title')) ?> - IMPS / Wireless Village</title>
<style>
*{box-sizing:border-box}
body{font-family:system-ui,sans-serif;background:#eef2f7;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.box{width:100%;max-width:520px;background:#fff;padding:26px;border-radius:14px;box-shadow:0 10px 24px rgba(0,0,0,.08);color:#333}
h1{margin:0 0 4px;font-size:1.4rem}
.sub{margin:0 0 14px;font-size:.82rem;color:#7a8296}
p{line-height:1.5;font-size:.92rem}
label{display:block;margin:14px 0 5px;font-size:.9rem;font-weight:600}
input,button{width:100%;padding:11px;border:1px solid #d3d9e4;border-radius:8px;font-size:1rem}
button{background:#2f6fed;color:#fff;border:0;margin-top:12px;cursor:pointer}
button.danger{background:#b00020}
button.secondary{background:#e9eefb;color:#2f489f}
.msg{padding:10px;background:#fdecea;color:#b00020;border-radius:8px;margin:12px 0}
.ok{padding:12px;background:#e8f6ec;color:#1d6b32;border-radius:8px;margin:12px 0}
.password{display:block;font-family:ui-monospace,monospace;font-size:1.35rem;letter-spacing:2px;background:#f7f9fd;border:1px dashed #c7d0e0;border-radius:8px;padding:14px;text-align:center;margin:8px 0;user-select:all}
.state{font-size:.85rem;margin:10px 0}
.state.on{color:#1d6b32}
.state.off{color:#b00020}
a{color:#2f6fed}
.note{font-size:.8rem;color:#7a8296}
</style>
</head>
<body>
<?= $panel ?>

<script>
function generatePassword() {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const out = new Uint32Array(12);
  (window.crypto || window.msCrypto).getRandomValues(out);
  let password = '';
  for (let i = 0; i < out.length; i++) {
    password += alphabet[out[i] % alphabet.length];
  }
  document.getElementById('password').value = password;
}
</script>

</body>
</html>
