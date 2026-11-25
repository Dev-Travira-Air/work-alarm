<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'push-common.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

function logMessage(string $message): void
{
    $directory = (string) jeda_push_config()['storage_path'];
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Direktori log tidak dapat dibuat.');
    }
    $line = '[' . date(DATE_ATOM) . '] ' . $message . PHP_EOL;
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'scheduler.log', $line, FILE_APPEND | LOCK_EX);
    fwrite(STDOUT, $message . PHP_EOL);
}

function sendToSubscription(
    WebPush $webPush,
    PDO $database,
    string $endpoint,
    string $subscriptionJson,
    array $payload,
    ?string $alarmKey
): bool {
    try {
        $subscriptionData = json_decode($subscriptionJson, true, 512, JSON_THROW_ON_ERROR);
        $report = $webPush->sendOneNotification(
            Subscription::create($subscriptionData),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ['TTL' => 600, 'urgency' => 'high']
        );
    } catch (Throwable $error) {
        logMessage('Push gagal diproses: ' . $error->getMessage());
        return false;
    }

    if ($report->isSuccess()) {
        if ($alarmKey !== null) {
            $statement = $database->prepare(
                'INSERT OR IGNORE INTO deliveries (alarm_key, endpoint) VALUES (:alarm_key, :endpoint)'
            );
            $statement->execute(['alarm_key' => $alarmKey, 'endpoint' => $endpoint]);
        }
        return true;
    }

    if ($report->isSubscriptionExpired()) {
        $statement = $database->prepare('DELETE FROM subscriptions WHERE endpoint = :endpoint');
        $statement->execute(['endpoint' => $endpoint]);
        logMessage('Subscription kedaluwarsa dihapus.');
        return false;
    }

    logMessage('Push gagal: ' . $report->getReason());
    return false;
}

try {
    $config = jeda_push_config();
    if ($config['vapid_public_key'] === '' || $config['vapid_private_key'] === '') {
        throw new RuntimeException('Kunci VAPID belum ada. Jalankan bin/setup-vapid.ps1 pada XAMPP.');
    }

    $schedule = jeda_load_schedule();
    $timezone = new DateTimeZone($schedule['timezone']);
    date_default_timezone_set($schedule['timezone']);
    $database = jeda_database();
    $subscriptions = $database->query(
        'SELECT endpoint, subscription_json FROM subscriptions'
    )->fetchAll(PDO::FETCH_ASSOC);
    $testMode = in_array('--test', $argv, true);

    if ($subscriptions === []) {
        if ($testMode) {
            logMessage('Tes push gagal: belum ada perangkat yang berlangganan notifikasi.');
            exit(1);
        }
        exit(0);
    }

    $webPush = new WebPush([
        'VAPID' => [
            'subject' => $config['vapid_subject'],
            'publicKey' => $config['vapid_public_key'],
            'privateKey' => $config['vapid_private_key'],
        ],
    ]);

    if ($testMode) {
        $payload = [
            'title' => 'Tes notifikasi Jeda',
            'body' => 'Push berhasil terkirim. Notifikasi dapat muncul meski aplikasi ditutup.',
            'url' => './?break=1',
        ];
        $successes = 0;
        foreach ($subscriptions as $subscription) {
            if (sendToSubscription(
                $webPush,
                $database,
                $subscription['endpoint'],
                $subscription['subscription_json'],
                $payload,
                null
            )) {
                $successes++;
            }
        }
        logMessage("Tes push selesai: {$successes} dari " . count($subscriptions) . ' perangkat berhasil.');
        exit($successes > 0 ? 0 : 1);
    }

    $now = new DateTimeImmutable('now', $timezone);
    $graceSeconds = 600;
    $alarms = [];
    $state = $database->query('SELECT enabled, snooze_until FROM reminder_state WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!$state || (int) $state['enabled'] !== 1) {
        exit(0);
    }

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
        if ($age >= 0 && $age <= $graceSeconds) {
            $alarms[] = [
                'key' => 'schedule-' . $alarm->format('Y-m-d_H-i'),
                'timestamp' => $alarm->getTimestamp(),
            ];
            if ($state['snooze_until'] !== null && (int) $state['snooze_until'] > $alarm->getTimestamp() * 1000) {
                $database->exec('UPDATE reminder_state SET snooze_until = NULL WHERE id = 1');
                $state['snooze_until'] = null;
            }
        }
    }

    $snoozeUntil = $state['snooze_until'] === null ? null : (int) $state['snooze_until'];
    $nowMilliseconds = (int) (microtime(true) * 1000);
    if ($snoozeUntil !== null && $snoozeUntil <= $nowMilliseconds && $nowMilliseconds - $snoozeUntil <= $graceSeconds * 1000) {
        $alarms[] = [
            'key' => 'snooze-' . $snoozeUntil,
            'timestamp' => (int) floor($snoozeUntil / 1000),
        ];
    }

    foreach ($alarms as $alarm) {
        foreach ($subscriptions as $subscription) {
            $delivered = $database->prepare(
                'SELECT 1 FROM deliveries WHERE alarm_key = :alarm_key AND endpoint = :endpoint'
            );
            $delivered->execute([
                'alarm_key' => $alarm['key'],
                'endpoint' => $subscription['endpoint'],
            ]);
            if ($delivered->fetchColumn()) {
                continue;
            }

            sendToSubscription(
                $webPush,
                $database,
                $subscription['endpoint'],
                $subscription['subscription_json'],
                [
                    'title' => 'Saatnya istirahat sejenak',
                    'body' => 'Anda sudah cukup lama di depan layar. Alihkan pandangan dan regangkan badan.',
                    'url' => './?break=1',
                ],
                $alarm['key']
            );
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Jeda push error: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
