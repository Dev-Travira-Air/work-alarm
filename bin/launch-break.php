<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'push-common.php';

function logLaunchError(string $message): void
{
    $directory = (string) jeda_push_config()['storage_path'];
    file_put_contents(
        $directory . DIRECTORY_SEPARATOR . 'scheduler.log',
        '[' . date(DATE_ATOM) . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

try {
    $schedule = jeda_load_schedule();
    $timezone = new DateTimeZone($schedule['timezone']);
    $now = new DateTimeImmutable('now', $timezone);
    $database = jeda_database();
    $state = $database->query('SELECT enabled, snooze_until FROM reminder_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!$state || (int) $state['enabled'] !== 1) {
        exit(0);
    }

    $dueAlarms = [];
    foreach ($schedule['times'] as $time) {
        $alarm = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $now->format('Y-m-d') . ' ' . $time,
            $timezone
        );
        if (!$alarm) {
            throw new RuntimeException('Jadwal tidak dapat dibaca: ' . $time);
        }
        $age = $now->getTimestamp() - $alarm->getTimestamp();
        if ($age >= 0 && $age <= 120) {
            $dueAlarms[] = [
                'key' => 'schedule-' . $alarm->format('Y-m-d_H-i'),
                'timestamp' => $alarm->getTimestamp(),
                'type' => 'schedule',
            ];
        }
    }

    $snoozeUntil = $state['snooze_until'] === null ? null : (int) $state['snooze_until'];
    $nowMilliseconds = (int) (microtime(true) * 1000);
    if ($snoozeUntil !== null && $snoozeUntil <= $nowMilliseconds && $nowMilliseconds - $snoozeUntil <= 10 * 60_000) {
        $dueAlarms[] = [
            'key' => 'snooze-' . $snoozeUntil,
            'timestamp' => (int) floor($snoozeUntil / 1000),
            'type' => 'snooze',
        ];
    }

    if ($dueAlarms === []) {
        exit(0);
    }
    usort($dueAlarms, static fn (array $left, array $right): int => $left['timestamp'] <=> $right['timestamp']);
    $alarm = $dueAlarms[0];

    $alreadyLaunched = $database->prepare('SELECT 1 FROM window_launches WHERE alarm_key = :alarm_key');
    $alreadyLaunched->execute(['alarm_key' => $alarm['key']]);
    if ($alreadyLaunched->fetchColumn()) {
        exit(0);
    }

    $configPath = (string) jeda_push_config()['storage_path'] . DIRECTORY_SEPARATOR . 'windows-pwa.json';
    if (!is_file($configPath)) {
        throw new RuntimeException('Konfigurasi Windows PWA belum dibuat. Jalankan bin/install-scheduler.ps1.');
    }
    $windowConfig = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
    $chromePath = $windowConfig['chrome_path'] ?? '';
    $profile = $windowConfig['profile'] ?? 'Default';
    $appUrl = $windowConfig['app_url'] ?? '';
    if (!is_string($chromePath) || !is_file($chromePath) || !is_string($appUrl) || filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException('Path Chrome atau URL PWA di storage/windows-pwa.json tidak valid.');
    }

    $targetUrl = rtrim($appUrl, '/') . '/?break=1&windows-popup=1';
    $command = 'powershell.exe -NoProfile -NonInteractive -WindowStyle Hidden -File '
        . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'open-break-window.ps1')
        . ' -ChromePath ' . escapeshellarg($chromePath)
        . ' -ChromeProfile ' . escapeshellarg($profile)
        . ' -TargetUrl ' . escapeshellarg($targetUrl);
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Chrome gagal dibuka untuk menampilkan popup Jeda.');
    }
    if (in_array('WINDOW_NOT_MAXIMIZED', $output, true)) {
        logLaunchError('Jendela pengingat Chrome tidak ditemukan untuk dimaksimalkan.');
    }

    $insert = $database->prepare('INSERT OR IGNORE INTO window_launches (alarm_key) VALUES (:alarm_key)');
    $insert->execute(['alarm_key' => $alarm['key']]);
    if ($alarm['type'] === 'snooze') {
        $database->exec('UPDATE reminder_state SET snooze_until = NULL WHERE id = 1');
    } elseif ($snoozeUntil !== null && $snoozeUntil >= $alarm['timestamp'] * 1000) {
        $database->exec('UPDATE reminder_state SET snooze_until = NULL WHERE id = 1');
    }
} catch (Throwable $error) {
    logLaunchError('Popup Windows gagal dibuka: ' . $error->getMessage());
    exit(1);
}
