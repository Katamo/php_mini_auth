<?php
/**
 * Llamado internamente por nginx auth_request en cada petición protegida.
 * → 200 { ok: true,  user: string }  si la sesión es válida
 * → 401 { ok: false }                si no hay sesión
 *
 * Este endpoint debe marcarse como 'internal' en nginx para que no sea
 * accesible directamente desde el navegador.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$cfgFile = __DIR__ . '/auth.config.json';
if (!file_exists($cfgFile) || !is_array($cfg = json_decode(file_get_contents($cfgFile), true))) {
    // Sin config → tratar como no autenticado para que nginx redirija al login
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

session_save_path('/tmp');
session_name($cfg['session_key']);
session_start();

if (!empty($_SESSION['user'])) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'user' => $_SESSION['user']]);
} else {
    http_response_code(401);
    echo json_encode(['ok' => false]);
}
