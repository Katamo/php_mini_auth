<?php
/**
 * CLI-only. Enables or disables authentication for this project.
 *
 * Usage:
 *   php toggle.php          # flip current state
 *   php toggle.php --on     # force enable
 *   php toggle.php --off    # force disable
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

$cfgFile = __DIR__ . '/auth.config.json';

if (!file_exists($cfgFile)) {
    fwrite(STDERR, "Error: auth.config.json not found at {$cfgFile}\n");
    exit(1);
}

$cfg = json_decode(file_get_contents($cfgFile), true);
if (!is_array($cfg)) {
    fwrite(STDERR, "Error: could not parse auth.config.json\n");
    exit(1);
}

$current = $cfg['enabled'] ?? true;

if (in_array('--on', $argv)) {
    $next = true;
} elseif (in_array('--off', $argv)) {
    $next = false;
} else {
    $next = !$current;
}

if ($next === $current) {
    echo "Auth is already " . ($current ? "ENABLED" : "DISABLED") . " — no change.\n";
    exit(0);
}

$cfg['enabled'] = $next;

$json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
if (file_put_contents($cfgFile, $json) === false) {
    fwrite(STDERR, "Error: could not write auth.config.json\n");
    exit(1);
}

$label = $next ? "ENABLED" : "DISABLED";
echo "Auth {$label} for project \"{$cfg['project_name']}\".\n";
