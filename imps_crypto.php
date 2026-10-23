<?php
// imps_crypto.php - cifrado de la contrasena del cliente IMPS.
//
// El protocolo CSP hace un digest MD5(nonce + password), asi que el servidor
// necesita la contrasena en claro para verificarlo. No es la contrasena web
// (esa sigue en password_hash(), irreversible): es una contrasena aparte,
// revocable, que el usuario genera en device.php y escribe en el movil.
//
// Se guarda cifrada con AES-256-GCM. La clave vive en imps.key (0600, fuera de
// git y denegada por .htaccess). Si el fichero no existe se crea solo.

function imps_key_path(): string
{
    return __DIR__ . '/imps.key';
}

function imps_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $path = imps_key_path();
    if (!is_file($path)) {
        $raw = random_bytes(32);
        if (@file_put_contents($path, base64_encode($raw), LOCK_EX) === false) {
            $key = '';
            return $key;
        }
        @chmod($path, 0600);
        $key = $raw;
        return $key;
    }
    $b64 = trim((string)@file_get_contents($path));
    $raw = base64_decode($b64, true);
    if ($raw === false || strlen($raw) !== 32) {
        // Clave corrupta: no se puede descifrar nada. device.php avisa.
        $key = '';
        return $key;
    }
    $key = $raw;
    return $key;
}

// Devuelve base64(iv . tag . ciphertext), o '' si no se pudo cifrar.
function imps_encrypt(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        return '';
    }
    $key = imps_key();
    if ($key === '') {
        return '';
    }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false || $tag === '') {
        return '';
    }
    return base64_encode($iv . $tag . $ct);
}

function imps_decrypt(string $blob): string
{
    if ($blob === '') {
        return '';
    }
    if (!function_exists('openssl_decrypt')) {
        return '';
    }
    $key = imps_key();
    if ($key === '') {
        return '';
    }
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

// 12 caracteres sin letras/numeros ambiguos (0/O, 1/I). Entra de sobra en el
// campo Password del CSP (max 50) y se teclea una sola vez en el movil.
function imps_generate_password(int $len = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}
