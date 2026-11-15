<?php
/*
 * AlertLoop PHP hosting check: is this hosting fit for the library (and for Web Push)?
 *
 * 1. Fill in $config below. access_key is required for web access; empty optional values are skipped.
 * 2. Upload this file to the site, then run it:
 *      php hosting-check.php                                  (full report; exit code 1 on FAIL)
 *      https://<site>/hosting-check.php?key=<access_key>      (same report in the browser)
 * 3. To check cron, schedule every minute either of:
 *      php /path/to/hosting-check.php --cron
 *      wget -q -O /dev/null "https://<site>/hosting-check.php?key=<access_key>&cron=1"
 *    and run the report again a few minutes later.
 * 4. Delete this file and hosting-check.cron after the check: the file contains credentials.
 *
 * Syntax is kept PHP 7.0 compatible so that an old PHP reports its version instead of a parse error.
 */

$config = array(
    'access_key' => '',      // long random string, e.g. output of: php -r "echo bin2hex(random_bytes(16));"
    'db_host' => '',         // MySQL; leave db_host empty to skip
    'db_port' => 3306,
    'db_name' => '',
    'db_user' => '',
    'db_password' => '',
    'telegram_token' => '',  // bot token; only getMe is called, nothing is sent
    'max_token' => '',       // MAX bot token; only GET /me is called, nothing is sent
    'max_ca_file' => '',     // extra CA file (PEM) for MAX, e.g. __DIR__ . '/russian_trusted_root_ca.cer'
    'smtp_host' => '',       // leave empty to skip
    'smtp_port' => 465,      // 465 connects with TLS (ssl://), other ports with plain TCP
);

// ---------------------------------------------------------------------------------------------

// Command line, including php-cgi run by cron or over SSH. php-cgi treats itself as run from the command line
// when none of these four variables is set (sapi/cgi/cgi_main.c); a web server is expected to pass at least one.
// Any other SAPI, or php-cgi with any of them set, is web access and needs the key.
$isCli = PHP_SAPI === 'cli';
if (PHP_SAPI === 'cgi-fcgi') {
    $isCli = true;
    foreach (array('GATEWAY_INTERFACE', 'SERVER_SOFTWARE', 'SERVER_NAME', 'REQUEST_METHOD') as $name) {
        if (isset($_SERVER[$name])) {
            $isCli = false;
        }
    }
}
$markerFile = __DIR__ . '/hosting-check.cron';

if (!$isCli) {
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    if ($config['access_key'] === '' || !is_string($key) || !hash_equals($config['access_key'], $key)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
}

if ($isCli) {
    // php-cgi with register_argc_argv=Off has no argv, but turns the command-line arguments into $_GET keys.
    $args = isset($_SERVER['argv']) ? $_SERVER['argv'] : array_keys($_GET);
    $cronMode = in_array('--cron', $args, true);
} else {
    $cronMode = isset($_GET['cron']);
}
if ($cronMode) {
    $line = gmdate('Y-m-d\TH:i:s\Z') . ' ' . PHP_VERSION . ' ' . PHP_SAPI . "\n";
    if (@file_put_contents($markerFile, $line, LOCK_EX) === false) {
        $error = error_get_last();
        $message = 'hosting-check: cannot write ' . $markerFile . ': ' . ($error ? $error['message'] : 'unknown error') . "\n";
        if ($isCli) {
            file_put_contents('php://stderr', $message);   // STDERR is defined only in the cli SAPI
        } else {
            http_response_code(500);
            echo $message;
        }
        exit(1);
    }
    exit(0);
}

$counts = array('OK' => 0, 'WARN' => 0, 'FAIL' => 0, 'SKIP' => 0);
$secrets = array();
foreach (array('access_key', 'db_password', 'telegram_token', 'max_token') as $name) {
    if ((string) $config[$name] !== '') {
        $secrets[] = (string) $config[$name];
    }
}

function hc_report($status, $section, $detail)
{
    global $counts, $secrets;
    $counts[$status]++;
    $text = str_replace($secrets, '***', $section . ': ' . $detail);
    echo str_pad($status, 4) . '  ' . preg_replace('/\s+/', ' ', $text) . "\n";
}

function hc_http_get($url, $headers, $caFile)
{
    $ch = curl_init($url);
    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    );
    if ($caFile !== '') {
        $options[CURLOPT_CAINFO] = $caFile;
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);

    return array(
        'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'errno' => curl_errno($ch),
        'error' => curl_error($ch),
        'body' => is_string($body) ? $body : '',
    );
}

function hc_failure($r)
{
    return 'curl errno ' . $r['errno'] . ': ' . $r['error'];
}

function hc_is_tls_error($r)
{
    // 35 SSL connect error, 51/60 peer certificate not verified, 77 CA file problem.
    return in_array($r['errno'], array(35, 51, 60, 77), true);
}

function hc_bot_username($r)
{
    $data = json_decode($r['body'], true);
    if (isset($data['result']['username'])) {
        return $data['result']['username'];      // Telegram getMe
    }
    if (isset($data['username'])) {
        return $data['username'];                // MAX GET /me
    }
    return null;
}

$maxExecutionTime = ini_get('max_execution_time');
if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

echo 'AlertLoop PHP hosting check, ' . gmdate('Y-m-d H:i:s') . " UTC\n\n";

// PHP --------------------------------------------------------------------------------------------

if (version_compare(PHP_VERSION, '8.2.0', '>=')) {
    hc_report('OK', 'php', PHP_VERSION . ' (' . PHP_SAPI . ')');
} else {
    hc_report('FAIL', 'php', PHP_VERSION . ' (' . PHP_SAPI . '); 8.2 or later is required');
}
hc_report('OK', 'php', 'memory_limit=' . ini_get('memory_limit') . ', max_execution_time=' . $maxExecutionTime);
$disabled = trim((string) ini_get('disable_functions'));
hc_report('OK', 'php', 'disable_functions: ' . ($disabled === '' ? 'none' : $disabled));

$missing = array();
foreach (array('curl', 'pdo_mysql', 'mbstring', 'openssl', 'json') as $extension) {
    if (!extension_loaded($extension)) {
        $missing[] = $extension;
    }
}
if ($missing) {
    hc_report('FAIL', 'php', 'missing extensions: ' . implode(', ', $missing));
} else {
    hc_report('OK', 'php', 'extensions curl, pdo_mysql, mbstring, openssl, json');
}

$finishRequest = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request'
    : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : '');
if ($isCli) {
    hc_report('SKIP', 'php', 'fastcgi_finish_request / litespeed_finish_request: only the web report tells');
} elseif ($finishRequest !== '') {
    hc_report('OK', 'php', $finishRequest . ' available');
} else {
    hc_report('WARN', 'php', 'neither fastcgi_finish_request nor litespeed_finish_request is available;'
        . ' first outbox attempt will run inside the request');
}

$bigMath = extension_loaded('gmp') ? 'gmp' : (extension_loaded('bcmath') ? 'bcmath' : '');
if (extension_loaded('openssl') && $bigMath !== '') {
    hc_report('OK', 'webpush', 'openssl, ' . $bigMath);
} else {
    hc_report('WARN', 'webpush', 'Web Push needs openssl and gmp or bcmath; loaded: openssl='
        . (extension_loaded('openssl') ? 'yes' : 'no') . ', gmp/bcmath=' . ($bigMath === '' ? 'no' : $bigMath));
}

if (extension_loaded('curl')) {
    $curl = curl_version();
    $locations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : array();
    $defaultCa = isset($locations['default_cert_file']) ? $locations['default_cert_file'] : '';
    hc_report('OK', 'tls', 'curl ' . $curl['version'] . ', ' . $curl['ssl_version']
        . '; curl.cainfo=' . (ini_get('curl.cainfo') ?: '(not set)')
        . '; openssl default_cert_file=' . ($defaultCa === '' ? '(unknown)' : $defaultCa . (is_file($defaultCa) ? '' : ' (missing)')));
}

// Cron -------------------------------------------------------------------------------------------

$cronHint = 'php ' . __FILE__ . ' --cron';
if (!is_file($markerFile)) {
    hc_report('WARN', 'cron', 'no run recorded yet; schedule: ' . $cronHint);
} else {
    $parts = explode(' ', trim((string) file_get_contents($markerFile)));
    $time = strtotime($parts[0]);
    $cronPhp = isset($parts[1]) ? $parts[1] : '?';
    $cronSapi = isset($parts[2]) ? $parts[2] : '?';
    $age = $time === false ? -1 : time() - $time;
    $detail = 'last run ' . $age . ' s ago, PHP ' . $cronPhp . ' (' . $cronSapi . ')';
    if (version_compare($cronPhp, '8.2.0', '<')) {
        hc_report('FAIL', 'cron', $detail . '; PHP 8.2 or later is required');
    } elseif ($age < 0 || $age > 180) {
        hc_report('WARN', 'cron', $detail . '; expected every minute');
    } else {
        hc_report('OK', 'cron', $detail);
    }
}

// MySQL ------------------------------------------------------------------------------------------

if ($config['db_host'] === '') {
    hc_report('SKIP', 'mysql', 'db_host is not set');
} elseif (!extension_loaded('pdo_mysql')) {
    hc_report('FAIL', 'mysql', 'pdo_mysql is not loaded');
} else {
    $pdo = null;
    try {
        $dsn = 'mysql:host=' . $config['db_host'] . ';port=' . (int) $config['db_port']
            . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ));
    } catch (Exception $e) {
        hc_report('FAIL', 'mysql', 'connect: ' . $e->getMessage());
    }

    if ($pdo !== null) {
        $step = 'server variables';
        try {
            $vars = $pdo->query("SHOW VARIABLES WHERE Variable_name IN ('version', 'sql_mode', 'character_set_server',"
                . " 'collation_server', 'innodb_large_prefix', 'innodb_default_row_format')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $version = $vars['version'];
            if (strpos($version, '5.7.') === 0 || strpos($version, '8.4.') === 0) {
                hc_report('OK', 'mysql', 'version ' . $version);
            } elseif (version_compare($version, '5.7', '<')) {
                hc_report('FAIL', 'mysql', 'version ' . $version . '; MySQL 5.7 or 8.4 is required');
            } else {
                hc_report('WARN', 'mysql', 'version ' . $version . ' is not tested; supported are MySQL 5.7 and 8.4');
            }
            $info = array();
            foreach (array('sql_mode', 'character_set_server', 'collation_server', 'innodb_large_prefix', 'innodb_default_row_format') as $name) {
                if (isset($vars[$name])) {
                    $info[] = $name . '=' . $vars[$name];
                }
            }
            hc_report('OK', 'mysql', implode('; ', $info));

            $step = 'session sql_mode';
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ENGINE_SUBSTITUTION'");

            $step = 'GET_LOCK';
            $locked = (int) $pdo->query("SELECT GET_LOCK('al_check_lock', 0)")->fetchColumn();
            $released = (int) $pdo->query("SELECT RELEASE_LOCK('al_check_lock')")->fetchColumn();
            if ($locked === 1 && $released === 1) {
                hc_report('OK', 'mysql', 'GET_LOCK / RELEASE_LOCK');
            } else {
                hc_report('FAIL', 'mysql', 'GET_LOCK returned ' . $locked . ', RELEASE_LOCK returned ' . $released);
            }

            $step = 'CREATE TABLE';
            $pdo->exec('CREATE TABLE IF NOT EXISTS al_check_probe (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                probe_key VARCHAR(191) NOT NULL,
                created_at DATETIME(3) NOT NULL,
                UNIQUE KEY al_check_probe_key (probe_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            try {
                $step = 'INSERT';
                $probeKey = 'probe-' . uniqid() . " \u{1F600}";
                $pdo->prepare('INSERT INTO al_check_probe (probe_key, created_at) VALUES (?, UTC_TIMESTAMP(3))')->execute(array($probeKey));
                $step = 'SELECT';
                $statement = $pdo->prepare('SELECT probe_key, created_at, UTC_TIMESTAMP(3) AS now_utc FROM al_check_probe WHERE probe_key = ?');
                $statement->execute(array($probeKey));
                $row = $statement->fetch(PDO::FETCH_ASSOC);
                $phpNow = microtime(true);
                if (!$row || $row['probe_key'] !== $probeKey) {
                    hc_report('FAIL', 'mysql', 'utf8mb4 value did not round-trip through the table');
                } else {
                    hc_report('OK', 'mysql', 'table InnoDB utf8mb4 with DATETIME(3) and unique VARCHAR(191): create, insert, read');
                }
                $dbNow = $row ? DateTime::createFromFormat('Y-m-d H:i:s.u', $row['now_utc'], new DateTimeZone('UTC')) : false;
                if ($dbNow === false) {
                    hc_report('FAIL', 'mysql', 'cannot parse UTC_TIMESTAMP(3)');
                } else {
                    $skew = $phpNow - (float) $dbNow->format('U.u');
                    hc_report(abs($skew) <= 2 ? 'OK' : 'WARN', 'mysql', sprintf('clock skew PHP - MySQL UTC_TIMESTAMP: %+.3f s', $skew));
                }
            } finally {
                $step = 'DROP TABLE';
                $pdo->exec('DROP TABLE IF EXISTS al_check_probe');
            }
        } catch (Exception $e) {
            hc_report('FAIL', 'mysql', $step . ': ' . $e->getMessage());
        }
    }
}

// Outgoing HTTPS ---------------------------------------------------------------------------------

if (!function_exists('curl_exec')) {
    hc_report('FAIL', 'https', 'curl_exec is not available');
} else {
    $r = hc_http_get('https://api.telegram.org/', array(), '');
    $telegramReachable = $r['errno'] === 0;
    if ($telegramReachable) {
        hc_report('OK', 'telegram', 'api.telegram.org reachable (HTTP ' . $r['code'] . ')');
    } else {
        hc_report('FAIL', 'telegram', 'api.telegram.org: ' . hc_failure($r));
    }

    if ($config['telegram_token'] === '') {
        hc_report('SKIP', 'telegram', 'telegram_token is not set');
    } elseif (!$telegramReachable) {
        hc_report('SKIP', 'telegram', 'getMe: api.telegram.org is not reachable');
    } else {
        $r = hc_http_get('https://api.telegram.org/bot' . $config['telegram_token'] . '/getMe', array(), '');
        $username = hc_bot_username($r);
        if ($r['errno'] !== 0) {
            hc_report('FAIL', 'telegram', 'getMe: ' . hc_failure($r));
        } elseif ($r['code'] === 200 && $username !== null) {
            hc_report('OK', 'telegram', 'getMe: @' . $username);
        } else {
            hc_report('FAIL', 'telegram', 'getMe: HTTP ' . $r['code'] . ' (token rejected?)');
        }
    }

    $maxUrl = 'https://platform-api2.max.ru/me';
    $maxCa = '';
    $maxReachable = false;
    $r = hc_http_get($maxUrl, array(), '');
    if ($r['errno'] === 0) {
        $maxReachable = true;
        hc_report('OK', 'max', 'platform-api2.max.ru reachable (HTTP ' . $r['code'] . ')');
    } elseif (!hc_is_tls_error($r)) {
        hc_report('FAIL', 'max', 'platform-api2.max.ru: ' . hc_failure($r));
    } elseif ($config['max_ca_file'] === '') {
        hc_report('FAIL', 'max', 'platform-api2.max.ru: ' . hc_failure($r) . '; set max_ca_file to the Russian Trusted Root CA (PEM)');
    } elseif (!is_readable($config['max_ca_file'])) {
        hc_report('FAIL', 'max', 'max_ca_file is not readable: ' . $config['max_ca_file']);
    } else {
        $retry = hc_http_get($maxUrl, array(), $config['max_ca_file']);
        if ($retry['errno'] === 0) {
            $maxCa = $config['max_ca_file'];
            $maxReachable = true;
            hc_report('OK', 'max', 'platform-api2.max.ru reachable only with max_ca_file (HTTP ' . $retry['code'] . '); system CA store: ' . hc_failure($r));
        } else {
            hc_report('FAIL', 'max', 'platform-api2.max.ru: ' . hc_failure($r) . '; with max_ca_file: ' . hc_failure($retry));
        }
    }

    if ($config['max_token'] === '') {
        hc_report('SKIP', 'max', 'max_token is not set');
    } elseif (!$maxReachable) {
        hc_report('SKIP', 'max', 'GET /me: platform-api2.max.ru is not reachable');
    } else {
        $r = hc_http_get($maxUrl, array('Authorization: ' . $config['max_token']), $maxCa);
        $username = hc_bot_username($r);
        if ($r['errno'] !== 0) {
            hc_report('FAIL', 'max', 'GET /me: ' . hc_failure($r));
        } elseif ($r['code'] === 200 && $username !== null) {
            hc_report('OK', 'max', 'GET /me: @' . $username);
        } else {
            hc_report('FAIL', 'max', 'GET /me: HTTP ' . $r['code'] . ' (token rejected?)');
        }
    }
}

// SMTP -------------------------------------------------------------------------------------------

if ($config['smtp_host'] === '') {
    hc_report('SKIP', 'smtp', 'smtp_host is not set');
} elseif (!function_exists('stream_socket_client')) {
    hc_report('FAIL', 'smtp', 'stream_socket_client is not available');
} else {
    $port = (int) $config['smtp_port'];
    $target = ($port === 465 ? 'ssl://' : 'tcp://') . $config['smtp_host'] . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($target, $errno, $errstr, 5);
    if ($socket === false) {
        $error = error_get_last();
        hc_report('FAIL', 'smtp', $target . ': ' . ($errstr !== '' ? $errstr : ($error ? $error['message'] : 'connection failed')) . ' (' . $errno . ')');
    } else {
        stream_set_timeout($socket, 5);
        $banner = trim((string) fgets($socket, 512));
        @fwrite($socket, "QUIT\r\n");
        fclose($socket);
        if (strpos($banner, '220') === 0) {
            hc_report('OK', 'smtp', $target . ': ' . substr($banner, 0, 100));
        } else {
            hc_report('FAIL', 'smtp', $target . ': unexpected banner "' . substr($banner, 0, 100) . '"');
        }
    }
}

// Result -----------------------------------------------------------------------------------------

echo "\nResult: " . ($counts['FAIL'] > 0 ? 'NOT READY' : 'READY') . ' (' . $counts['FAIL'] . ' FAIL, ' . $counts['WARN'] . ' WARN, '
    . $counts['SKIP'] . " SKIP). Delete this file after the check.\n";

if ($isCli && $counts['FAIL'] > 0) {
    exit(1);
}
