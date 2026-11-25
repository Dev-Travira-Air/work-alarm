<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'push-common.php';

$scheduleConfig = ['timezone' => 'Asia/Jakarta', 'times' => []];
$scheduleErrorMessage = null;
$windowsPopupEnabled = is_file(__DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'windows-pwa.json');
$isWindowsPopup = $windowsPopupEnabled && ($_GET['windows-popup'] ?? null) === '1' && ($_GET['break'] ?? null) === '1';
try {
    $scheduleConfig = jeda_load_schedule();
} catch (Throwable $error) {
    error_log('Jeda schedule error: ' . $error->getMessage());
    $scheduleErrorMessage = 'Jadwal tidak valid. Periksa schedule.json.';
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f8fc">
    <meta name="description" content="Pengingat istirahat singkat untuk menjaga kesehatan mata dan tubuh saat bekerja di depan layar.">
    <title><?= $isWindowsPopup ? 'Jeda - Saatnya istirahat' : 'Jeda — Pengingat istirahat' ?></title>
    <link rel="icon" href="./app-icon.svg" type="image/svg+xml">
    <link rel="manifest" href="./manifest.webmanifest">
    <link rel="stylesheet" href="./style.css">
</head>
<body>
    <main class="app-shell">
        <header class="topbar">
            <a class="brand" href="./" aria-label="Jeda — beranda">
                <img src="./logo-travira.png" alt="Travira Air">
                <span class="brand-divider" aria-hidden="true"></span>
                <span class="brand-name">Jeda</span>
            </a>
            <button class="install-button" id="install-button" type="button" hidden>Pasang aplikasi</button>
        </header>

        <section class="dashboard" aria-labelledby="page-title">
            <div class="intro">
                <p class="eyebrow"><span class="status-dot" aria-hidden="true"></span> WAKTUNYA JAGA KESEIMBANGAN</p>
                <h1 id="page-title">Kerja fokus,<br><span>jangan lupa jeda.</span></h1>
                <p class="intro-copy">Pengingat kecil untuk mengistirahatkan mata dan tubuh di sela kesibukan.</p>
            </div>

            <section class="timer-card" aria-label="Pengatur pengingat istirahat">
                <div class="card-heading">
                    <div>
                        <p class="card-kicker">PENGINGAT BERIKUTNYA</p>
                        <h2 id="timer-display" aria-live="polite">--:--:--</h2>
                    </div>
                    <div class="timer-icon" aria-hidden="true">
                        <svg viewBox="0 0 48 48" fill="none">
                            <circle cx="24" cy="25" r="15" stroke="currentColor" stroke-width="2.5"/>
                            <path d="M24 16v9l6 4M18 5h12M24 5v5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                        </svg>
                    </div>
                </div>

                <div class="progress-track" aria-hidden="true"><span id="timer-progress"></span></div>
                <p class="timer-caption" id="timer-caption">Atur jeda agar tetap nyaman bekerja.</p>

                <section class="schedule-field" aria-labelledby="schedule-title">
                    <h3 id="schedule-title">Jadwal harian</h3>
                    <div class="daily-schedule" id="daily-schedule"></div>
                    <p class="schedule-error" id="schedule-error" role="alert" hidden></p>
                    <p class="schedule-note" id="schedule-note">Mengikuti zona waktu jadwal.</p>
                </section>

                <div class="actions">
                    <button class="primary-button" id="start-button" type="button">
                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6 4.5h3v11H6zM11 4.5h3v11h-3z" fill="currentColor"/></svg>
                        Jeda pengingat
                    </button>
                </div>
                <button class="notification-link" id="notification-button" type="button">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M15.5 8a5.5 5.5 0 0 0-11 0c0 6-2 6-2 7.5h15C17.5 14 15.5 14 15.5 8ZM8 18h4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>Aktifkan notifikasi</span>
                </button>
                <p class="notification-status" id="notification-status" role="status" aria-live="polite">Izinkan push sekali agar notifikasi bisa muncul saat aplikasi ditutup.</p>
            </section>

            <footer class="page-footer">
                <span class="footer-mark" aria-hidden="true">✳</span>
                <p>Istirahat sejenak. Kembali dengan lebih segar.</p>
            </footer>
        </section>
    </main>

    <section class="break-overlay" id="break-overlay" role="alertdialog" aria-modal="true" aria-labelledby="break-title" aria-describedby="break-description" hidden>
        <div class="break-dialog">
            <div class="break-art" aria-hidden="true">
                <span class="sun"></span>
                <span class="horizon"></span>
                <span class="hill hill-back"></span>
                <span class="hill hill-front"></span>
                <span class="sparkle sparkle-one">✳</span>
                <span class="sparkle sparkle-two">✦</span>
            </div>
            <p class="break-eyebrow">JEDA SEJENAK</p>
            <h2 id="break-title">Saatnya istirahat.</h2>
            <p class="break-description" id="break-description">Anda sudah cukup lama di depan layar. Alihkan pandangan, regangkan badan, dan ambil napas sejenak.</p>
            <p class="snooze-label">Ingatkan saya lagi dalam</p>
            <div class="snooze-options">
                <button type="button" data-snooze="5">5 <small>menit</small></button>
                <button type="button" data-snooze="10">10 <small>menit</small></button>
                <button type="button" data-snooze="30">30 <small>menit</small></button>
            </div>
            <button class="ignore-button" id="ignore-button" type="button">Abaikan kali ini</button>
        </div>
    </section>

    <script>
        window.JEDA_SCHEDULE = <?= json_encode($scheduleConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
        window.JEDA_SCHEDULE_ERROR = <?= json_encode($scheduleErrorMessage, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
        window.JEDA_WINDOWS_POPUP = <?= json_encode($windowsPopupEnabled) ?>;
    </script>
    <script src="./app.js" defer></script>
</body>
</html>
