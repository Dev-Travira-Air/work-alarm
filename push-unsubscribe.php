<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'push-common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jeda_json_response(['error' => 'Gunakan metode POST.'], 405);
    exit;
}

jeda_require_same_origin();
$request = jeda_request_json();
$endpoint = $request['endpoint'] ?? null;
if (!is_string($endpoint) || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
    jeda_json_response(['error' => 'Endpoint subscription tidak valid.'], 400);
    exit;
}

$statement = jeda_database()->prepare('DELETE FROM subscriptions WHERE endpoint = :endpoint');
$statement->execute(['endpoint' => $endpoint]);
jeda_json_response(['ok' => true]);
