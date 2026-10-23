<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/security.php';
require __DIR__ . '/imps_crypto.php';

if (current_user()) {
    header('Location: talk.php');
    exit;
}

$errors = [];
$ip = client_ip();
$block = throttle_check($ip);
if ($block !== null) {
    $errors[] = $block;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $nick = trim((string)($_POST['nick'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');
    $captcha = (string)($_POST['captcha'] ?? '');
    if ($nick === '' || $password === '') {
        $errors[] = 'Missing data.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{2,32}$/', $nick)) {
        $errors[] = 'Nick can only contain letters, numbers, dot, dash or underscore (2-32).';
    } elseif ($password !== $password2) {
        $errors[] = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif (!captcha_verify($captcha)) {
        $errors[] = 'Incorrect captcha answer.';
    } else {
        try {
            // La contraseña web se guarda con hash irreversible y, como el
            // movil necesita la misma contraseña para su digest CSP, se guarda
            // tambien cifrada. Asi el usuario solo recuerda una contraseña.
            $enc = imps_encrypt($password);
            if ($enc === '') {
                throw new RuntimeException('Could not encrypt the mobile password.');
            }
            $stmt = db()->prepare('INSERT INTO users (nick, domain, password_hash, imps_password) VALUES (?, ?, ?, ?)');
            $stmt->execute([$nick, 'village', password_hash($password, PASSWORD_DEFAULT), $enc]);
            $userId = (int)db()->lastInsertId();
            db()->prepare('INSERT OR IGNORE INTO presence (user_id) VALUES (?)')->execute([$userId]);
            throttle_record($ip, true);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $userId;
            header('Location: talk.php');
            exit;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                $errors[] = 'That nick is already registered.';
            } else {
                error_log('register error: ' . $e->getMessage());
                $errors[] = 'Could not register. Please try again.';
            }
        }
    }
}

$captcha_q = captcha_generate();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Register - IMPS / Wireless Village</title>
<style>*{box-sizing:border-box}body{font-family:system-ui,sans-serif;background:#eef2f7;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.box{width:100%;max-width:460px;background:#fff;padding:26px;border-radius:14px;box-shadow:0 10px 24px rgba(0,0,0,.08)}h1{margin:0 0 4px;font-size:1.4rem}.sub{margin:0 0 14px;font-size:.82rem;color:#7a8296}label{display:block;margin:14px 0 5px;font-size:.9rem;font-weight:600}input,button{width:100%;padding:11px;border:1px solid #d3d9e4;border-radius:8px;font-size:1rem}button{background:#2f6fed;color:#fff;border:0;margin-top:16px;cursor:pointer}.captcha{background:#f7f9fd;border:1px dashed #c7d0e0;border-radius:8px;padding:9px 11px;margin-top:14px;font-size:.95rem}.msg{padding:10px;background:#fdecea;color:#b00020;border-radius:8px;margin:12px 0}a{color:#2f6fed}
</style>
</head><body><div class="box">
<h1>Register</h1>
<p class="sub">IMPS / Wireless Village (CSP) server</p>
<?php foreach ($errors as $e) echo '<div class="msg">'.h($e).'</div>'; ?>
<form method="post">
<label>Nick</label><input name="nick" value="<?= h($_POST['nick'] ?? '') ?>" autofocus>
<label>Password</label><input type="password" name="password">
<label>Repeat password</label><input type="password" name="password2">
<div class="captcha">Anti-bot: <b><?= h($captcha_q) ?></b><br><input name="captcha" placeholder="Answer" autocomplete="off" style="margin-top:6px"></div>
<button type="submit">Register</button>
</form>
<p>The nick will also work as <code>wv:nick</code> on mobile.</p>
<p><a href="login.php">I Have an account.</a></p>
</div></body></html>
