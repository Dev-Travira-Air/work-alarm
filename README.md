# Jeda — pengingat istirahat

Jeda opens its break dialog in a maximized Chrome app window on the local Windows/XAMPP setup. Online delivery uses Web Push notifications.

## Local XAMPP setup

1. Start Apache in XAMPP and install Jeda as a Chrome PWA from its normal `http://localhost/...` address. Keep the desktop shortcut named **Jeda**.

2. Install or refresh the Windows scheduler:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\bin\install-scheduler.ps1
   ```

   The one-minute task runs through Windows Script Host in the background. At an alarm time it opens a Chrome app window directly to the centered break dialog and uses a hidden PowerShell helper to maximize the window. The PC must be awake and your Windows account must be logged in. Windows may not bring the window to the foreground while another app or lock screen is active.

   The local popup does not require browser notification permission. The snooze and pause controls are synchronized with the Windows task.

## Schedule

Edit `schedule.json`. Use 24-hour `HH:MM` values and an IANA timezone. The Windows task checks once per minute:

```json
{
  "timezone": "Asia/Jakarta",
  "times": ["10:00", "15:00"]
}
```

The standalone local popup launcher retries a missed scheduled alarm for up to two minutes.

## Web Push / online server

Web Push is retained for hosting on HTTPS. It can show an operating-system notification while the PWA is closed, but browsers do not let a server force-open a full-screen app window.

- Install PHP dependencies with `composer install`, then deploy over HTTPS.
- Configure `JEDA_VAPID_PUBLIC_KEY`, `JEDA_VAPID_PRIVATE_KEY`, and `JEDA_VAPID_SUBJECT` as server environment variables, or deploy `storage/push-config.local.php` securely.
- Set `JEDA_STORAGE_PATH` to a writable directory outside the public web root. The default `storage` directory is denied by the included Apache rule; configure an equivalent deny rule for Nginx or another web server.
- Configure cron to run `php /path/to/work-alarm/bin/send-reminders.php` every minute.
- Keep the same VAPID keys when deploying; a domain change requires users to open the new PWA origin and subscribe again.

On the online server, users must allow browser notifications and click **Aktifkan notifikasi**. The Windows popup scheduler is local-only and is not deployed.
