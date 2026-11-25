<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

$storagePath = getenv('JEDA_STORAGE_PATH') ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
if (!is_dir($storagePath) && !mkdir($storagePath, 0700, true) && !is_dir($storagePath)) {
    throw new RuntimeException('Direktori penyimpanan push tidak dapat dibuat.');
}

$configPath = $storagePath . DIRECTORY_SEPARATOR . 'push-config.local.php';
if (is_file($configPath)) {
    fwrite(STDERR, "storage/push-config.local.php sudah ada; file tidak ditimpa.\n");
    exit(1);
}

$keys = Minishlink\WebPush\VAPID::createVapidKeys();
$config = [
    'vapid_subject' => 'mailto:admin@example.com',
    'vapid_public_key' => $keys['publicKey'],
    'vapid_private_key' => $keys['privateKey'],
];
$contents = "<?php\nreturn " . var_export($config, true) . ";\n";
if (file_put_contents($configPath, $contents, LOCK_EX) === false) {
    throw new RuntimeException('File konfigurasi VAPID tidak dapat ditulis.');
}

fwrite(STDOUT, "Kunci VAPID berhasil dibuat di storage/push-config.local.php. Simpan file privat ini dengan aman.\n");
