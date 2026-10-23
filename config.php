<?php
// config.php
return [
    'app_dir' => __DIR__,
    'db_path' => __DIR__ . '/wv.sqlite',
    'schema_path' => __DIR__ . '/schema.sql',
    'log_path' => __DIR__ . '/csp.log',
    'server_url_path' => '', // URL publica base
    'poll_mode' => 'http',
    // Depuracion: si es true, se registra en el log cada respuesta XML completa
    // (linea "RESP:"). Por defecto false para no duplicar el tamano del log.
    'debug' => false,
    // Anti fuerza bruta (security.php). Intentos antes de bloquear, ventana de
    // tiempo y duracion del bloqueo.
    'throttle_max_attempts' => 10,
    'throttle_window_seconds' => 300, // 5 minutos
    'throttle_block_hours' => 1,
    // El hosting esta detras de proxy/CDN? Entonces REMOTE_ADDR es la del proxy.
    'trust_forwarded_for' => false,
];
