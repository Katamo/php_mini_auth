<?php
/**
 * GET o POST → destruye la sesión y devuelve { success: true }.
 * El cliente debe redirigir al login tras el logout.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$cfgFile = __DIR__ . '/auth.config.json';
if (file_exists($cfgFile) && is_array($cfg = json_decode(file_get_contents($cfgFile), true))) {
    session_save_path('/tmp');
    session_name($cfg['session_key']);
    session_start();
    session_destroy();
}

echo json_encode(['success' => true]);
