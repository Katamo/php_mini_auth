#!/usr/bin/env php
<?php
/**
 * add-user.php — Gestión de usuarios (solo CLI, nunca vía HTTP)
 *
 * Uso (SSH al servidor):
 *   php /ruta/auth/add-user.php <usuario>           Añadir o actualizar usuario
 *   php /ruta/auth/add-user.php --list              Listar usuarios
 *   php /ruta/auth/add-user.php --remove <usuario>  Eliminar usuario
 *
 * Las credenciales se almacenan con bcrypt en la ruta definida en auth.config.json
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('Este script solo puede ejecutarse desde la línea de comandos.');
}

$cfgFile = __DIR__ . '/auth.config.json';
if (!file_exists($cfgFile)) {
    echo "Error: no se encuentra auth.config.json en " . __DIR__ . "\n";
    exit(1);
}
$cfg = json_decode(file_get_contents($cfgFile), true);
if (!is_array($cfg)) {
    echo "Error: auth.config.json malformado\n";
    exit(1);
}

$usersFile = $cfg['users_file'];
$projectName = $cfg['project_name'] ?? 'proyecto';

function loadUsers(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveUsers(string $file, array $users): void {
    file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT) . "\n");
}

$args = array_slice($argv, 1);

if (empty($args)) {
    echo "Uso:\n";
    echo "  php add-user.php <usuario>           Añadir o actualizar usuario\n";
    echo "  php add-user.php --list              Listar usuarios\n";
    echo "  php add-user.php --remove <usuario>  Eliminar usuario\n";
    exit(1);
}

if ($args[0] === '--list') {
    $users = loadUsers($usersFile);
    if (empty($users)) {
        echo "No hay usuarios configurados para '$projectName'.\n";
    } else {
        echo "Usuarios de '$projectName':\n";
        foreach (array_keys($users) as $u) {
            echo "  · $u\n";
        }
    }
    exit(0);
}

if ($args[0] === '--remove') {
    $username = trim($args[1] ?? '');
    if (!$username) { echo "Error: se requiere el nombre de usuario\n"; exit(1); }
    $users = loadUsers($usersFile);
    if (!isset($users[$username])) { echo "Usuario '$username' no encontrado.\n"; exit(1); }
    unset($users[$username]);
    saveUsers($usersFile, $users);
    echo "Usuario '$username' eliminado.\n";
    exit(0);
}

$username = trim($args[0]);
if (!$username) { echo "Error: se requiere el nombre de usuario\n"; exit(1); }

echo "Contraseña para '$username': ";
if (PHP_OS_FAMILY !== 'Windows') system('stty -echo');
$password = trim(fgets(STDIN));
if (PHP_OS_FAMILY !== 'Windows') system('stty echo');
echo "\n";

if (!$password) { echo "Error: la contraseña no puede estar vacía.\n"; exit(1); }

$hash  = password_hash($password, PASSWORD_BCRYPT);
$users = loadUsers($usersFile);
$isNew = !isset($users[$username]);

$users[$username] = $hash;
saveUsers($usersFile, $users);

echo $isNew
    ? "Usuario '$username' añadido a '$projectName'.\n"
    : "Contraseña de '$username' actualizada.\n";
