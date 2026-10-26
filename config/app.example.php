<?php
declare(strict_types=1);
// Copy to config/local.php, outside the web root. Never commit real secrets.
return [
    'environment' => 'production',
    'app_url' => 'https://example.invalid/acc',
    'app_key' => '', // Generate 32 random bytes and base64-encode them privately for this installation.
    'db' => ['dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=acc_manager;charset=utf8mb4', 'user' => '', 'password' => ''],
    'session' => ['name' => 'acc_session', 'secure' => true, 'idle_seconds' => 1800, 'absolute_seconds' => 43200],
    'auth_limits' => ['login_failures_email' => 8, 'login_failures_ip' => 1000, 'registration_ip' => 300, 'verification_ip' => 600, 'recovery_ip' => 600, 'recovery_email' => 5, 'reset_ip' => 600, 'window_seconds' => 900],
    'setup' => ['enabled' => false, 'token_hash' => ''],
    // Initial private defaults only. Migration 004 disables automatic delivery;
    // the chief then manages encrypted SMTP/PHP mail settings in the panel.
    'smtp' => [
        'enabled' => false, 'host' => '', 'port' => 587, 'encryption' => 'tls',
        'username' => '', 'password' => '', 'from_email' => 'noreply@example.invalid', 'from_name' => 'Seat Manager',
        'max_attempts' => 3, 'timeout_seconds' => 10,
    ],
];
