# AlertLoop PHP

Incident alerts and reliable delivery for PHP sites on shared hosting.

A site calls an external API (a CRM webhook, a payment provider) and the call fails. AlertLoop PHP keeps the
data in MySQL and retries it from cron, and tells you about the failure and the recovery in Telegram, MAX or
email. It needs only PHP, MySQL and cron: no daemon, no own server, no Composer on the hosting.

## Requirements

- PHP 8.2 or later with `curl`, `json`, `mbstring`, `pdo_mysql`
- MySQL 5.7 or 8.4
- cron once a minute
- the site in UTF-8: messages, fields and payloads must be valid UTF-8

## Installation

On your machine, in the site project, add the library; then upload `vendor/` to the hosting with the site:

```sh
composer require golovanov/alertloop-php
```

Create `alertloop.php` next to `vendor/`. The handler and `on_failure` also run in cron, where only
`vendor/autoload.php` and this file are loaded, so the file requires the site code they use:

```php
<?php

require_once __DIR__ . '/lib/crm.php';  // CrmClient, mailLeadToManager()

return [
    'db' => ['host' => 'localhost', 'port' => 3306, 'name' => 'site', 'user' => 'site', 'password' => '...'],
    'table_prefix' => 'al_',
    'source' => 'example.com',          // first line of every alert
    'language' => 'en',                 // alert texts: en | ru
    'timezone' => 'Europe/Berlin',      // times in alerts; default: date_default_timezone_get()

    'channels' => [
        'tg'   => ['type' => 'telegram', 'token' => '123456:ABC...'],
        'max'  => ['type' => 'max', 'token' => '...'],
        'mail' => ['type' => 'email', 'transport' => 'mail', 'from' => 'alerts@example.com'],
    ],

    'alerts' => [
        'to'       => [['tg', '-1001234567890'], ['max', '-71234567']],  // [channel, chat id or e-mail]
        'fallback' => [['mail', 'ops@example.com']],                     // when an address in 'to' refuses
        'remind_every' => 60,                                            // minutes; 0 turns reminders off
    ],

    'outbox' => [
        'crm.lead' => [
            'handler'    => fn (array $lead) => (new CrmClient())->createLead($lead),
            'on_failure' => fn (array $lead, string $error) => mailLeadToManager($lead, $error),
            'retry_window' => 24 * 60,                                   // minutes
        ],
    ],
];
```

A key the library does not know, such as a misspelt one, is an error that names the key. The site loads this
file on every page that uses the library, so run `alertloop check` after every change to it.

Every command below needs PHP of the same version as the site, by its full path: plain `php` over SSH may be
another version (`php -v` shows it). The path `/opt/php/8.2/bin/php` is an example; the hosting's help or
control panel names the real one.

On the hosting, over SSH as the site user, from the site directory, create the tables once:

```sh
/opt/php/8.2/bin/php vendor/bin/alertloop run
```

Until then `fire()` and `resolve()` only write an error to the PHP error log, and `push()` throws.

In the hosting control panel, add a cron job that runs every minute, with the same PHP and the site directory:

```
* * * * * /opt/php/8.2/bin/php /path/to/site/vendor/bin/alertloop run
```

`run` prints nothing on success; when it fails, it prints the error and cron mails it. Channel refusals,
including an alert that no address accepted, go to the PHP error log: to stderr and so to cron mail when
`error_log` is empty, otherwise to that file. See which, with the PHP from the cron line:

```sh
/opt/php/8.2/bin/php -r 'var_dump(ini_get("error_log"));'
```

A config in another place: `alertloop run --config=/path/to/alertloop.php`.

Check the hosting, the database and every channel; the exit code is 1 when a line says FAIL:

```sh
/opt/php/8.2/bin/php vendor/bin/alertloop check
```

Also send a test message to every address in `alerts.to` and `alerts.fallback`:

```sh
/opt/php/8.2/bin/php vendor/bin/alertloop check --send
```

From the command line `check` cannot see whether the hosting sends the response before the handler runs, and
says SKIP. Open a page with this line once, then delete it; `false` means the visitor waits until the handler
and the alerts finish:

```php
<?php var_dump(function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'));
```

## Usage

```php
use Golovanov\AlertLoop\AlertLoop;
use Golovanov\AlertLoop\PermanentFailure;
use Golovanov\AlertLoop\Severity;

$al = AlertLoop::fromFile(__DIR__ . '/alertloop.php');

$al->fire('crm.down', Severity::Error, 'CRM does not respond', ['queue' => 3]);
$al->fire('disk.low', Severity::Warning, 'Disk 92% full', resolveAfter: 30);
$al->resolve('crm.down');

$id = $al->outbox('crm.lead')->push(['name' => 'Ann', 'phone' => '+10000000000']);
```

- `fire()` opens an incident and alerts once; repeats of an open incident are counted and shown in reminders.
- `resolveAfter: 30` resolves the incident after 30 minutes without a repeat.
- `resolve()` closes the incident and reports the downtime.
- `fire()` and `resolve()` never throw on database or channel errors: they go to the PHP error log.
- `push()` returns once the payload is saved and throws if it is not, so show "thank you" only after it returns.
- The handler gets the payload after the response is sent to the visitor (in CLI at once); an exception means
  the attempt failed and `run` retries it.
- Throw `PermanentFailure` from the handler when a retry is pointless (the receiver rejected the data).
- `on_failure` gets the payload and the error once per item, on its first failure.
- The first failure opens the incident `outbox.<name>` with the number of items in the queue; the first
  delivery after it resolves the incident with the downtime, the items still in the queue and the items that
  became `dead` meanwhile.
- An item that runs out of `retry_window` or hits `PermanentFailure` becomes `dead` and is reported once.
  An attempt that ended the process (`exit`, memory limit) is retried too, but reported only when the item
  becomes `dead`.
- Alerts never contain the payload, but they quote the handler's exception message: keep personal data out
  of it.
- A `dead` item stays in the table `<table_prefix>outbox` for 30 days; this version cannot send it again:

  ```sql
  SELECT id, name, created_at, last_error, payload FROM al_outbox WHERE status = 'dead';
  ```

- Give the handler a timeout well under two minutes: after two minutes `run` may start the item again.
- Alerts and the first outbox attempt run after the response; the site's session is closed before them.

## Channels

- **Telegram:** the token comes from [@BotFather](https://t.me/BotFather); add the bot to the chat, send
  `/start` there and take `chat.id` from `https://api.telegram.org/bot<token>/getUpdates`.
  `'api_base' => 'https://tg-mirror.example.net'` sends through a mirror of the Bot API.
- **MAX:** create the bot and copy its token at [business.max.ru/self](https://business.max.ru/self), section
  "Chat bots" (API docs: [dev.max.ru](https://dev.max.ru)); the chat ids of the
  chats the bot is in are in the answer of `curl -H 'Authorization: <token>' https://platform-api2.max.ru/chats`.
  `'ca_file' => __DIR__ . '/russian_trusted_root_ca.pem'` when the hosting does not trust the certificate of
  the MAX API (`check` says curl error 60): the root certificate Russian Trusted Root CA in PEM from
  [gosuslugi.ru/crt](https://www.gosuslugi.ru/crt), the root alone.
- **Email:** `'transport' => 'mail'` uses PHP `mail()`; `'transport' => 'smtp'` takes `host`, `port`
  (465 by default, implicit TLS), `user` and `password`.

An alert that no address accepted is resent by a later `run`.

## Defaults

| What | Value |
|---|---|
| Outbox retry window | 24 h |
| Pause between attempts | 1, 2, 4, 8, 16, 30 min, then 30 |
| Reminder | every 60 min |
| Delivered items kept | 7 days |
| Dead items kept | 30 days |
| Resolved incidents kept | 30 days |
| One `run` takes new work for | 50 s |

## License

MIT, see [LICENSE](LICENSE).
