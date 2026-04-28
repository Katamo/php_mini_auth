<?php
/**
 * POST { username, password }
 * → 200 { success: true, user: string }
 * → 401 { error: string }
 * → 400 { error: string }
 * → 405 on non-POST
 */

header('Content-Type: application/json');
header('Cache-Control: no-store');

$cfgFile = __DIR__ . '/auth.config.json';
if (!file_exists($cfgFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Auth not configured — create auth/auth.config.json on the server']);
    exit;
}
$cfg = json_decode(file_get_contents($cfgFile), true);
if (!is_array($cfg)) {
    http_response_code(500);
    echo json_encode(['error' => 'Malformed auth.config.json']);
    exit;
}

session_save_path('/tmp');
session_name($cfg['session_key']);
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$username = trim($body['username'] ?? '');
$password = $body['password'] ?? '';

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Usuario y contraseña requeridos']);
    exit;
}

$usersFile = $cfg['users_file'];
if (!file_exists($usersFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Auth no configurado en este servidor']);
    exit;
}

$users = json_decode(file_get_contents($usersFile), true);
if (!is_array($users)) {
    http_response_code(500);
    echo json_encode(['error' => 'Archivo de usuarios malformado']);
    exit;
}

if (!isset($users[$username]) || !password_verify($password, $users[$username])) {
    usleep(300000); // 300ms — ralentiza fuerza bruta
    http_response_code(401);
    echo json_encode(['error' => 'Usuario o contraseña incorrectos']);
    exit;
}

$_SESSION['user'] = $username;
session_regenerate_id(true);

echo json_encode(['success' => true, 'user' => $username]);
