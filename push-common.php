<?php
declare(strict_types=1);

function jeda_push_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $storagePath = getenv('JEDA_STORAGE_PATH');
    $config = [
        'vapid_subject' => 'mailto:admin@example.com',
        'vapid_public_key' => '',
        'vapid_private_key' => '',
        'storage_path' => is_string($storagePath) && $storagePath !== ''
            ? $storagePath
            : __DIR__ . DIRECTORY_SEPARATOR . 'storage',
    ];

    $localConfigPath = (string) $config['storage_path'] . DIRECTORY_SEPARATOR . 'push-config.local.php';
    if (is_file($localConfigPath)) {
        $localConfig = require $localConfigPath;
        if (!is_array($localConfig)) {
            throw new RuntimeException('push-config.local.php harus mengembalikan array konfigurasi.');
        }
        $config = array_merge($config, $localConfig);
    }

    foreach ([
        'JEDA_VAPID_SUBJECT' => 'vapid_subject',
        'JEDA_VAPID_PUBLIC_KEY' => 'vapid_public_key',
        'JEDA_VAPID_PRIVATE_KEY' => 'vapid_private_key',
    ] as $environmentName => $configName) {
        $environmentValue = getenv($environmentName);
        if (is_string($environmentValue) && $environmentValue !== '') {
            $config[$configName] = $environmentValue;
        }
    }

    return $config;
}

function jeda_load_schedule(): array
{
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'schedule.json';
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('schedule.json tidak dapat dibaca.');
    }

    $schedule = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($schedule) || !isset($schedule['times'], $schedule['timezone']) || !is_array($schedule['times'])) {
        throw new RuntimeException('schedule.json harus berisi timezone dan daftar times.');
    }

    new DateTimeZone((string) $schedule['timezone']);
    if ($schedule['times'] === []) {
        throw new RuntimeException('Daftar times di schedule.json tidak boleh kosong.');
    }

    foreach ($schedule['times'] as $time) {
        if (!is_string($time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new RuntimeException('Format waktu di schedule.json harus HH:MM.');
        }
    }
    if (count(array_unique($schedule['times'])) !== count($schedule['times'])) {
        throw new RuntimeException('Waktu di schedule.json tidak boleh duplikat.');
    }

    sort($schedule['times']);
    return $schedule;
}

function jeda_database(): PDO
{
    static $database = null;
    if ($database instanceof PDO) {
        return $database;
    }

    $directory = (string) jeda_push_config()['storage_path'];
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Direktori penyimpanan push tidak dapat dibuat.');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('Direktori penyimpanan push tidak dapat ditulis: ' . $directory);
    }

    $database = new PDO('sqlite:' . $directory . DIRECTORY_SEPARATOR . 'push.sqlite');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec(
        'CREATE TABLE IF NOT EXISTS subscriptions (
            endpoint TEXT PRIMARY KEY,
            subscription_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS deliveries (
            alarm_key TEXT NOT NULL,
            endpoint TEXT NOT NULL,
            sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (alarm_key, endpoint)
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS reminder_state (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            enabled INTEGER NOT NULL DEFAULT 1,
            snooze_until INTEGER
        )'
    );
    $database->exec('INSERT OR IGNORE INTO reminder_state (id, enabled) VALUES (1, 1)');
    $database->exec(
        'CREATE TABLE IF NOT EXISTS window_launches (
            alarm_key TEXT PRIMARY KEY,
            launched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    return $database;
}

function jeda_json_response(array $body, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function jeda_require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $parts = parse_url($origin);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        jeda_json_response(['error' => 'Origin tidak valid.'], 403);
        exit;
    }

    $originHost = strtolower($parts['host']);
    if (isset($parts['port'])) {
        $originHost .= ':' . $parts['port'];
    }
    $expectedScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!hash_equals($host, $originHost) || !hash_equals($expectedScheme, strtolower($parts['scheme']))) {
        jeda_json_response(['error' => 'Permintaan lintas origin ditolak.'], 403);
        exit;
    }
}

function jeda_request_json(): array
{
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 16384) {
        jeda_json_response(['error' => 'Ukuran permintaan terlalu besar.'], 413);
        exit;
    }

    $body = file_get_contents('php://input');
    if ($body === false || $body === '') {
        jeda_json_response(['error' => 'Isi permintaan wajib diisi.'], 400);
        exit;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        jeda_json_response(['error' => 'Format JSON tidak valid.'], 400);
        exit;
    }

    return $decoded;
}
