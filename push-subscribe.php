<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'push-common.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jeda_json_response(['error' => 'Gunakan metode POST.'], 405);
    exit;
}

jeda_require_same_origin();
$subscription = jeda_request_json();
$endpoint = $subscription['endpoint'] ?? null;
$keys = $subscription['keys'] ?? null;

if (!is_string($endpoint) || filter_var($endpoint, FILTER_VALIDATE_URL) === false
    || parse_url($endpoint, PHP_URL_SCHEME) !== 'https'
    || !is_array($keys)
    || !is_string($keys['p256dh'] ?? null)
    || !is_string($keys['auth'] ?? null)
    || strlen($keys['p256dh']) > 256
    || strlen($keys['auth']) > 256
) {
    jeda_json_response(['error' => 'Data subscription push tidak valid.'], 400);
    exit;
}

$storedSubscription = [
    'endpoint' => $endpoint,
    'keys' => [
        'p256dh' => $keys['p256dh'],
        'auth' => $keys['auth'],
    ],
    'contentEncoding' => 'aes128gcm',
];
$encoded = json_encode($storedSubscription, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$statement = jeda_database()->prepare(
    'INSERT INTO subscriptions (endpoint, subscription_json)
     VALUES (:endpoint, :subscription)
     ON CONFLICT(endpoint) DO UPDATE SET subscription_json = excluded.subscription_json'
);
$statement->execute(['endpoint' => $endpoint, 'subscription' => $encoded]);

jeda_json_response(['ok' => true]);
