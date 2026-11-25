<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'push-common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jeda_json_response(['error' => 'Gunakan metode POST.'], 405);
    exit;
}

jeda_require_same_origin();
$request = jeda_request_json();
$enabled = $request['enabled'] ?? null;
$snoozeUntil = $request['snoozeUntil'] ?? null;
if (!is_bool($enabled) || ($snoozeUntil !== null && !is_int($snoozeUntil))) {
    jeda_json_response(['error' => 'Status pengingat tidak valid.'], 400);
    exit;
}
$nowMilliseconds = (int) (microtime(true) * 1000);
if ($snoozeUntil !== null && ($snoozeUntil < $nowMilliseconds - 10 * 60_000 || $snoozeUntil > $nowMilliseconds + 31 * 60_000)) {
    jeda_json_response(['error' => 'Waktu tunda tidak valid.'], 400);
    exit;
}

$statement = jeda_database()->prepare(
    'UPDATE reminder_state SET enabled = :enabled, snooze_until = :snooze_until WHERE id = 1'
);
$statement->execute([
    'enabled' => $enabled ? 1 : 0,
    'snooze_until' => $snoozeUntil,
]);

jeda_json_response(['ok' => true]);
