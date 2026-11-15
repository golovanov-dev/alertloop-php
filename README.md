# AlertLoop PHP

Incident alerts and reliable delivery for PHP sites on shared hosting.

> **Status: in development.** Nothing is released yet. The API described below is the
> planned 0.1 scope and may change before the first release.

## What it is for

A site on shared hosting calls an external API (a CRM webhook, a payment provider, a
messenger) and the call fails. Without extra code the failure is silent: the visitor sees
"thank you", the data is lost, and nobody finds out. AlertLoop PHP is a Composer library
that the site embeds to:

- store the payload in MySQL before the first delivery attempt and retry it from cron;
- open an incident on the first failure, remind while it stays open, and report recovery
  with its duration;
- send those alerts to Telegram, MAX, email (SMTP) or a signed webhook, with a fallback
  channel.

It needs only what shared hosting usually has: PHP, MySQL and a cron job once a minute.
No separate server, no daemon, no Composer on the hosting.

## Planned for 0.1

- Incidents: `fire()` / `resolve()` by key, deduplication while open, reminders, recovery
  message.
- Outbox: `push()` persists first, tries once after the response is sent to the visitor,
  then retries from cron with backoff within a time window. Items that run out of time or are
  rejected stay in the database as `dead` and are reported once.
- Incidents close by themselves: no manual acknowledgement or cleanup.
- Channels: Telegram (with a configurable API base for proxies), MAX, SMTP email, webhook
  with HMAC signature; fallback channel.
- `vendor/bin/alertloop run` for cron, plus a secret URL for hosts whose cron can only
  fetch a URL; overlapping runs are prevented.
- `vendor/bin/alertloop check` to verify PHP, MySQL, outgoing connections and each channel
  on the target hosting.

## Requirements

- PHP 8.2 or later
- MySQL 5.7 or 8.4

## Checking a hosting before installation

[`tools/hosting-check.php`](tools/hosting-check.php) is a single dependency-free file that
reports whether a hosting meets these requirements. Fill in the settings at its top, upload
it next to the site and run it:

```sh
php hosting-check.php                                          # over SSH on the hosting
curl "https://example.com/hosting-check.php?key=<access_key>"  # or open the URL in a browser
```

To check cron, schedule `php /path/to/hosting-check.php --cron` every minute and run the
report again a few minutes later. Delete the file after the check: it contains credentials.

## License

MIT, see [LICENSE](LICENSE).
