# Changelog

All notable changes to this project are documented in this file.

## 0.1.0 - 2026-11-15

### Added
- Incidents: `fire()` and `resolve()` by key; repeats of an open incident are counted, not re-sent; reminders every `alerts.remind_every` minutes (default 60); recovery message with downtime; `resolveAfter` resolves an incident after that many minutes without a repeat.
- Alert channels: Telegram (`api_base` for a mirror of the Bot API), MAX (`ca_file` for a root certificate missing on the hosting), email through PHPMailer (`mail` or `smtp`). Every address in `alerts.to` gets the alert; if any refuses, `alerts.fallback` gets it too; an alert nobody accepted is resent by the next run.
- Outbox: `$al->outbox($name)->push($payload)` saves the payload in MySQL or throws, hands it to the configured handler after the response, and retries failed attempts from `run` within `retry_window` (default 24 h); `on_failure` gets the payload once per item; the incident `outbox.<name>` opens on a failure with the number of items in the queue and resolves on the next delivery with the downtime, the items still queued and the items that became `dead`; an attempt counts when it starts, and no retry starts after `retry_window`; an item that runs out of time or throws `PermanentFailure` becomes `dead` and is reported once, without the payload.
- `vendor/bin/alertloop check [--send] [--config=path]`: PHP extensions, MySQL login, version and `GET_LOCK`, the last `run`, every channel (Telegram `getMe`, MAX `GET /me`, SMTP login); `--send` sends a test message to every alert address. Exit code 1 when a check fails.
- `vendor/bin/alertloop run [--config=path]` for cron once a minute: overlapping runs are skipped, resolved incidents are deleted after 30 days.
- Configuration as a PHP file returning an array (`AlertLoop::fromFile()`); an unknown key is an error that names it; built-in alert texts in English and Russian, times in the configured timezone.
- MySQL 5.7 and 8.4 with a table prefix (default `al_`); `run` and `check` create and upgrade the tables.
- Alerts and the first outbox attempt of a web request run after the response; the site's session is closed before them.
