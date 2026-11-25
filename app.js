(() => {
    "use strict";

    const STORAGE_KEY = "jeda-fixed-schedule";
    const timerDisplay = document.getElementById("timer-display");
    const timerProgress = document.getElementById("timer-progress");
    const timerCaption = document.getElementById("timer-caption");
    const startButton = document.getElementById("start-button");
    const dailySchedule = document.getElementById("daily-schedule");
    const scheduleError = document.getElementById("schedule-error");
    const scheduleNote = document.getElementById("schedule-note");
    const overlay = document.getElementById("break-overlay");
    const notificationButton = document.getElementById("notification-button");
    const notificationLabel = notificationButton.querySelector("span");
    const notificationStatus = document.getElementById("notification-status");
    const installButton = document.getElementById("install-button");
    const schedule = parseSchedule(window.JEDA_SCHEDULE);
    const windowsPopupEnabled = window.JEDA_WINDOWS_POPUP === true;
    const scheduleTimes = schedule ? schedule.times : [];
    const scheduleTimezone = schedule ? schedule.timezone : "Asia/Jakarta";
    const zonedTimeFormatter = schedule ? new Intl.DateTimeFormat("en-US", {
        timeZone: scheduleTimezone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hourCycle: "h23"
    }) : null;
    let settings = loadSettings();
    let reminderOpen = false;
    let installPrompt = null;

    function parseSchedule(config) {
        if (!config || !Array.isArray(config.times) || config.times.length === 0 || typeof config.timezone !== "string") {
            return null;
        }

        const validTimes = config.times.every((time) => (
            typeof time === "string" && /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(time)
        ));
        if (!validTimes || new Set(config.times).size !== config.times.length) {
            return null;
        }

        try {
            new Intl.DateTimeFormat("en-US", { timeZone: config.timezone });
        } catch (error) {
            console.error("Zona waktu di schedule.json tidak valid.", error);
            return null;
        }

        return { times: [...config.times].sort(), timezone: config.timezone };
    }

    function loadSettings() {
        try {
            const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || "{}");
            return {
                enabled: stored.enabled !== false,
                lastHandledAt: Number.isFinite(stored.lastHandledAt) ? stored.lastHandledAt : null,
                snoozeUntil: Number.isFinite(stored.snoozeUntil) ? stored.snoozeUntil : null,
                snoozeStartedAt: Number.isFinite(stored.snoozeStartedAt) ? stored.snoozeStartedAt : null
            };
        } catch (error) {
            console.warn("Pengaturan pengingat tidak dapat dibaca.", error);
            return { enabled: true, lastHandledAt: null, snoozeUntil: null, snoozeStartedAt: null };
        }
    }

    function saveSettings() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
        } catch (error) {
            console.warn("Pengaturan pengingat tidak dapat disimpan.", error);
        }
    }

    function zonedParts(timestamp) {
        const parts = zonedTimeFormatter.formatToParts(new Date(timestamp));
        const values = {};
        for (const part of parts) {
            if (part.type !== "literal") values[part.type] = Number(part.value);
        }
        return values;
    }

    function dateKey(parts) {
        return `${parts.year}-${String(parts.month).padStart(2, "0")}-${String(parts.day).padStart(2, "0")}`;
    }

    function calendarDay(parts, offset) {
        const date = new Date(Date.UTC(parts.year, parts.month - 1, parts.day + offset));
        return {
            year: date.getUTCFullYear(),
            month: date.getUTCMonth() + 1,
            day: date.getUTCDate()
        };
    }

    function occurrenceOnDay(day, time) {
        const [hours, minutes] = time.split(":").map(Number);
        const wantedWallTime = Date.UTC(day.year, day.month - 1, day.day, hours, minutes);
        let timestamp = wantedWallTime;

        for (let attempt = 0; attempt < 3; attempt += 1) {
            const observed = zonedParts(timestamp);
            const observedWallTime = Date.UTC(
                observed.year,
                observed.month - 1,
                observed.day,
                observed.hour,
                observed.minute,
                observed.second
            );
            timestamp += wantedWallTime - observedWallTime;
        }
        return timestamp;
    }

    function latestOccurrence(timestamp) {
        const today = zonedParts(timestamp);
        let latest = null;
        for (let dayOffset = -1; dayOffset <= 0; dayOffset += 1) {
            const day = calendarDay(today, dayOffset);
            for (const time of scheduleTimes) {
                const occurrence = occurrenceOnDay(day, time);
                if (occurrence <= timestamp && (latest === null || occurrence > latest.timestamp)) {
                    latest = { timestamp: occurrence, time };
                }
            }
        }
        return latest;
    }

    function nextOccurrence(timestamp) {
        const today = zonedParts(timestamp);
        let next = null;
        for (let dayOffset = 0; dayOffset <= 1; dayOffset += 1) {
            const day = calendarDay(today, dayOffset);
            for (const time of scheduleTimes) {
                const occurrence = occurrenceOnDay(day, time);
                if (occurrence > timestamp && (next === null || occurrence < next.timestamp)) {
                    next = { timestamp: occurrence, time };
                }
            }
        }
        return next;
    }

    function previousOccurrence(timestamp) {
        const target = zonedParts(timestamp);
        let previous = null;
        for (let dayOffset = -1; dayOffset <= 0; dayOffset += 1) {
            const day = calendarDay(target, dayOffset);
            for (const time of scheduleTimes) {
                const occurrence = occurrenceOnDay(day, time);
                if (occurrence < timestamp && (previous === null || occurrence > previous)) {
                    previous = occurrence;
                }
            }
        }
        return previous;
    }

    function formatTime(time) {
        return time.replace(":", ".");
    }

    function formatCountdown(milliseconds) {
        const totalSeconds = Math.ceil(Math.max(0, milliseconds) / 1000);
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;
    }

    function renderSchedule() {
        dailySchedule.replaceChildren();
        for (const time of scheduleTimes) {
            const item = document.createElement("span");
            item.className = "schedule-time";
            item.innerHTML = '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M10 6v4l2.5 1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
            item.append(document.createTextNode(formatTime(time)));
            dailySchedule.append(item);
        }
        scheduleNote.textContent = `Zona waktu: ${scheduleTimezone}.`;
    }

    function render() {
        startButton.innerHTML = settings.enabled
            ? '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6 4.5h3v11H6zM11 4.5h3v11h-3z" fill="currentColor"/></svg> Jeda pengingat'
            : '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 4.8v10.4L15 10 7 4.8Z" fill="currentColor"/></svg> Aktifkan pengingat';

        if (!settings.enabled || scheduleTimes.length === 0) {
            timerDisplay.textContent = "--:--:--";
            timerProgress.style.width = "0%";
            timerCaption.textContent = settings.enabled ? "Periksa jadwal pada schedule.json." : "Pengingat sedang dijeda.";
            return;
        }

        const now = Date.now();
        const scheduled = nextOccurrence(now);
        const snoozeIsNext = settings.snoozeUntil !== null
            && settings.snoozeUntil < scheduled.timestamp;
        const target = snoozeIsNext
            ? { timestamp: settings.snoozeUntil, time: null }
            : scheduled;
        const elapsedStart = snoozeIsNext
            ? settings.snoozeStartedAt
            : previousOccurrence(target.timestamp);
        const duration = elapsedStart === null ? 0 : target.timestamp - elapsedStart;
        const progress = duration > 0 ? 100 * (1 - (target.timestamp - now) / duration) : 0;

        timerDisplay.textContent = formatCountdown(target.timestamp - now);
        timerProgress.style.width = `${Math.max(0, Math.min(100, progress))}%`;

        if (snoozeIsNext) {
            timerCaption.textContent = "Pengingat ditunda. Akan muncul lagi sebentar lagi.";
        } else {
            const isToday = dateKey(zonedParts(scheduled.timestamp)) === dateKey(zonedParts(now));
            timerCaption.textContent = `Berikutnya ${isToday ? "hari ini" : "besok"} pukul ${formatTime(scheduled.time)}.`;
        }
    }

    function openReminder(source, scheduledAt) {
        if (source === "schedule") {
            settings.lastHandledAt = scheduledAt;
        }
        settings.snoozeUntil = null;
        settings.snoozeStartedAt = null;
        reminderOpen = true;
        overlay.hidden = false;
        document.body.classList.add("dialog-open");
        saveSettings();
        render();
        overlay.querySelector("[data-snooze]").focus();
        syncReminderState().catch((error) => {
            console.error("Status pengingat tidak dapat diperbarui.", error);
            if (windowsPopupEnabled) notificationStatus.textContent = error.message;
        });

        if (document.hidden && "Notification" in window && Notification.permission === "granted") {
            new Notification("Saatnya istirahat sejenak", {
                body: "Anda sudah cukup lama di depan layar. Ambil jeda sebentar.",
                icon: "./app-icon.svg",
                tag: "jeda-break"
            });
        }
    }

    function checkSchedule() {
        if (!settings.enabled || scheduleTimes.length === 0 || reminderOpen || windowsPopupEnabled) {
            render();
            return;
        }

        const now = Date.now();
        const scheduled = latestOccurrence(now);
        const hasNewSchedule = scheduled !== null
            && (settings.lastHandledAt === null || scheduled.timestamp > settings.lastHandledAt);
        const snoozeDue = settings.snoozeUntil !== null && settings.snoozeUntil <= now;

        if (hasNewSchedule && (!settings.snoozeUntil || scheduled.timestamp <= settings.snoozeUntil)) {
            openReminder("schedule", scheduled.timestamp);
        } else if (snoozeDue) {
            openReminder("snooze");
        } else if (hasNewSchedule) {
            openReminder("schedule", scheduled.timestamp);
        } else {
            render();
        }
    }

    function decodeApplicationServerKey(key) {
        const padded = key + "=".repeat((4 - key.length % 4) % 4);
        const binary = atob(padded.replace(/-/g, "+").replace(/_/g, "/"));
        return Uint8Array.from(binary, (character) => character.charCodeAt(0));
    }

    async function savePushSubscription(subscription) {
        const response = await fetch("./push-subscribe.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(subscription)
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Subscription push gagal disimpan.");
    }

    async function updateServerSubscription(enabled) {
        const registration = await serviceWorkerReady;
        if (!registration || !("PushManager" in window)) return;
        const subscription = await registration.pushManager.getSubscription();
        if (!subscription) return;

        const response = await fetch(enabled ? "./push-subscribe.php" : "./push-unsubscribe.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(enabled ? subscription : { endpoint: subscription.endpoint })
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Status notifikasi push gagal diperbarui.");
    }

    async function syncReminderState() {
        const now = Date.now();
        const snoozeUntil = !settings.enabled || (
            settings.snoozeUntil !== null && settings.snoozeUntil < now - 10 * 60_000
        ) ? null : settings.snoozeUntil;
        const response = await fetch("./push-state.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                enabled: settings.enabled,
                snoozeUntil
            })
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Status pengingat gagal diperbarui.");
    }

    async function enablePushNotifications() {
        if (!("Notification" in window) || !("PushManager" in window) || !("serviceWorker" in navigator)) {
            throw new Error("Browser ini belum mendukung Web Push.");
        }

        let permission = Notification.permission;
        if (permission === "default") permission = await Notification.requestPermission();
        if (permission !== "granted") {
            throw new Error(permission === "denied"
                ? "Notifikasi diblokir. Izinkan notifikasi Jeda dari pengaturan browser."
                : "Izin notifikasi belum diberikan.");
        }

        notificationStatus.textContent = "Menghubungkan perangkat ke layanan push...";
        const keyResponse = await fetch("./push-public-key.php", { cache: "no-store" });
        const keyResult = await keyResponse.json();
        if (!keyResponse.ok) throw new Error(keyResult.error || "Kunci push belum tersedia di server.");

        const registration = await serviceWorkerReady;
        if (!registration) throw new Error("Service worker belum siap. Muat ulang aplikasi lalu coba lagi.");
        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: decodeApplicationServerKey(keyResult.publicKey)
            });
        }

        await savePushSubscription(subscription);
        notificationLabel.textContent = "Notifikasi push aktif";
        notificationStatus.textContent = "Perangkat ini terdaftar untuk menerima notifikasi meski PWA ditutup.";
    }

    async function syncPushStatus() {
        if (!("Notification" in window)) {
            notificationLabel.textContent = "Notifikasi tidak didukung";
            notificationStatus.textContent = "Gunakan browser yang mendukung Web Push.";
            return;
        }
        const registration = await serviceWorkerReady;
        if (Notification.permission === "denied") {
            notificationLabel.textContent = "Notifikasi diblokir";
            notificationStatus.textContent = "Izinkan notifikasi untuk localhost di pengaturan situs Chrome, lalu muat ulang aplikasi.";
            return;
        }
        if (Notification.permission !== "granted") {
            notificationLabel.textContent = "Aktifkan notifikasi";
            return;
        }
        const subscription = registration ? await registration.pushManager.getSubscription() : null;
        if (subscription) {
            await updateServerSubscription(settings.enabled);
            notificationLabel.textContent = settings.enabled ? "Notifikasi push aktif" : "Notifikasi dijeda";
            notificationStatus.textContent = settings.enabled
                ? "Perangkat ini terdaftar untuk menerima notifikasi meski PWA ditutup."
                : "Pengingat dijeda; aktifkan kembali untuk menerima push.";
        } else {
            notificationLabel.textContent = "Hubungkan notifikasi";
            notificationStatus.textContent = "Izin browser sudah ada. Tekan tombol untuk mendaftarkan perangkat.";
        }
    }

    function toggleReminders() {
        if (settings.enabled) {
            const latest = latestOccurrence(Date.now());
            if (latest) settings.lastHandledAt = latest.timestamp;
            settings.snoozeUntil = null;
            settings.snoozeStartedAt = null;
            settings.enabled = false;
        } else {
            settings.enabled = true;
        }
        saveSettings();
        checkSchedule();
        syncReminderState().catch((error) => {
            console.error("Status pengingat tidak dapat diperbarui.", error);
            if (windowsPopupEnabled) notificationStatus.textContent = error.message;
        });
        updateServerSubscription(settings.enabled).catch((error) => {
            console.error("Status push tidak dapat diperbarui.", error);
            if (!windowsPopupEnabled) notificationStatus.textContent = error.message;
        });
    }

    function dismissReminder(minutes) {
        overlay.hidden = true;
        document.body.classList.remove("dialog-open");
        reminderOpen = false;
        if (minutes === null) {
            settings.snoozeUntil = null;
            settings.snoozeStartedAt = null;
        } else {
            settings.snoozeStartedAt = Date.now();
            settings.snoozeUntil = settings.snoozeStartedAt + minutes * 60_000;
        }
        saveSettings();
        syncReminderState().catch((error) => {
            console.error("Status pengingat tidak dapat diperbarui.", error);
            if (windowsPopupEnabled) notificationStatus.textContent = error.message;
        });
        checkSchedule();
    }

    function initialize() {
        if (window.JEDA_SCHEDULE_ERROR || scheduleTimes.length === 0) {
            scheduleError.hidden = false;
            scheduleError.textContent = window.JEDA_SCHEDULE_ERROR || "Periksa jadwal di schedule.json.";
            startButton.disabled = true;
            timerCaption.textContent = "Pengingat belum dapat dijalankan.";
            console.error("Jadwal tidak valid. Periksa schedule.json.");
            return;
        }

        renderSchedule();
        if (settings.lastHandledAt === null) {
            const previous = latestOccurrence(Date.now() - 1);
            settings.lastHandledAt = previous ? previous.timestamp : null;
            saveSettings();
        }

        startButton.addEventListener("click", toggleReminders);
        if (windowsPopupEnabled) {
            notificationButton.hidden = true;
            notificationStatus.textContent = "Windows akan membuka jendela Jeda pada waktu jadwal. PC harus menyala dan akun Windows tetap login.";
        }
        overlay.querySelectorAll("[data-snooze]").forEach((button) => {
            button.addEventListener("click", () => dismissReminder(Number(button.dataset.snooze)));
        });
        document.getElementById("ignore-button").addEventListener("click", () => dismissReminder(null));
        notificationButton.addEventListener("click", async () => {
            notificationButton.disabled = true;
            try {
                await enablePushNotifications();
            } catch (error) {
                console.error("Push tidak dapat diaktifkan.", error);
                if (Notification.permission === "denied") {
                    notificationLabel.textContent = "Notifikasi diblokir";
                }
                notificationStatus.textContent = error.message;
            } finally {
                notificationButton.disabled = false;
            }
        });

        window.addEventListener("beforeinstallprompt", (event) => {
            event.preventDefault();
            installPrompt = event;
            installButton.hidden = false;
        });
        installButton.addEventListener("click", async () => {
            if (!installPrompt) return;
            installPrompt.prompt();
            await installPrompt.userChoice;
            installPrompt = null;
            installButton.hidden = true;
        });

        navigator.serviceWorker.addEventListener("message", (event) => {
            if (event.data && event.data.type === "JEDA_SHOW_BREAK" && settings.enabled && !reminderOpen) {
                openReminder("push");
            }
        });
        document.addEventListener("visibilitychange", checkSchedule);

        checkSchedule();
        if (settings.enabled && !reminderOpen && new URLSearchParams(location.search).get("break") === "1") {
            const scheduled = latestOccurrence(Date.now());
            openReminder("schedule", scheduled ? scheduled.timestamp : null);
        }
        syncReminderState().catch((error) => {
            console.error("Status pengingat tidak dapat dibaca.", error);
            if (windowsPopupEnabled) notificationStatus.textContent = error.message;
        });
        if (!windowsPopupEnabled) {
            syncPushStatus().catch((error) => {
                console.error("Status push tidak dapat dibaca.", error);
            });
        }
        window.setInterval(checkSchedule, 1000);
    }

    const serviceWorkerReady = "serviceWorker" in navigator && location.protocol !== "file:"
        ? navigator.serviceWorker.register("./sw.js")
            .then(() => navigator.serviceWorker.ready)
            .catch((error) => {
                console.error("Service worker gagal didaftarkan.", error);
                return null;
            })
        : Promise.resolve(null);

    initialize();
})();
