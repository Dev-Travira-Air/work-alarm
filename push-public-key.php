<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'push-common.php';

$publicKey = (string) jeda_push_config()['vapid_public_key'];
if ($publicKey === '') {
    jeda_json_response(['error' => 'Push belum dikonfigurasi. Jalankan bin/setup-vapid.ps1 pada XAMPP.'], 503);
    exit;
}

jeda_json_response(['publicKey' => $publicKey]);
