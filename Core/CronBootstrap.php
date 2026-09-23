<?php
declare(strict_types=1);
/**
 * Core/CronBootstrap.php — ক্রোন জবের জন্য কেন্দ্রীয় এন্ট্রি পয়েন্ট
 * ────────────────────────────────────────────────────────────────────────
 *  যা করে:
 *    ✓ শুধু CLI তে রান হবে
 *    ✓ টাইমজোন সেট
 *    ✓ .env (Vault) লোড — double quote safe
 *    ✓ special character password safe (DB_PASS="abc*=xyz#123")
 *    ✓ কনফিগ ভ্যালিডেশন
 *    ✓ PDO কানেকশন
 *    ✓ DB health check
 *    ✓ cron_log_success() / cron_log_fail() — জেনেরিক ফাইল লগ
 *    ✓ sensitive ভ্যারিয়েবল cleanup
 *
 *  যা করে না:
 *    ✗ Session
 *    ✗ AuthKernel
 *    ✗ CSRF
 *    ✗ Business logic
 *
 *  ব্যবহার (সব ক্রোন ফাইলে):
 *    $root = dirname(__DIR__);
 *    require_once $root . '/Core/CronBootstrap.php';
 *    // এরপর $conn ব্যবহার করো
 */

/* ═══════════════════════════════════════════════════════════════
 * 1. শুধু CLI তে রান হবে
 * ═══════════════════════════════════════════════════════════════ */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Access Denied.\n");
}

/* ═══════════════════════════════════════════════════════════════
 * 2. কনস্ট্যান্ট
 * ═══════════════════════════════════════════════════════════════ */
if (!defined('VAULT_PATH')) {
    define('VAULT_PATH', '/home/sadakalo/App/.env');
}
if (!defined('TIMEZONE')) {
    define('TIMEZONE', 'Asia/Dhaka');
}

/* ═══════════════════════════════════════════════════════════════
 * 3. টাইমজোন
 * ═══════════════════════════════════════════════════════════════ */
if (!@date_default_timezone_set(TIMEZONE)) {
    error_log('CronBootstrap: Invalid timezone: ' . TIMEZONE);
    exit(1);
}

/* ═══════════════════════════════════════════════════════════════
 * 4. এরর হ্যান্ডলার
 *    — Bootstrap.php এর show_db_error_page() এর সাথে clash নেই
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('cron_error')) {
    function cron_error(string $msg): never
    {
        error_log('CronBootstrap Error: ' . $msg);
        fwrite(STDERR, '[ERROR] ডাটাবেজ/কনফিগারেশন সমস্যা হয়েছে।' . PHP_EOL);
        exit(1);
    }
}

/* ═══════════════════════════════════════════════════════════════
 * 4b. জেনেরিক ক্রোন লগ হেল্পার (ফাইল-ভিত্তিক, কোনো DB টেবিল লাগে না)
 *     লগ যায়: /Logs/cron_log.txt
 * ═══════════════════════════════════════════════════════════════ */
if (!defined('CRON_LOG_FILE')) {
    define('CRON_LOG_FILE', dirname(__DIR__) . '/Logs/cron_log.txt');
}
if (!function_exists('cron_log_write')) {
    function cron_log_write(string $line): void
    {
        $dir = dirname(CRON_LOG_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(CRON_LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
if (!function_exists('cron_log_success')) {
    function cron_log_success(string $job, string $note = ''): void
    {
        cron_log_write(sprintf('[%s] [OK]   %s %s', date('Y-m-d H:i:s'), $job, $note));
    }
}
if (!function_exists('cron_log_fail')) {
    function cron_log_fail(string $job, string $error = ''): void
    {
        cron_log_write(sprintf('[%s] [FAIL] %s — %s', date('Y-m-d H:i:s'), $job, $error));
        error_log("Cron [{$job}] failed: {$error}");
    }
}

/* ═══════════════════════════════════════════════════════════════
 * 5. Vault ফাইল চেক
 * ═══════════════════════════════════════════════════════════════ */
if (!is_file(VAULT_PATH)) {
    cron_error('Vault file not found: ' . VAULT_PATH);
}
if (!is_readable(VAULT_PATH)) {
    cron_error('Vault file is not readable.');
}

/* ═══════════════════════════════════════════════════════════════
 * 6. .env পার্সার
 *
 *    parse_ini_file() ব্যবহার করা হয়নি কারণ:
 *      DB_PASS="abc*=xyz#123"  → parse_ini_file এ নষ্ট হয়
 *
 *    সাপোর্ট করে:
 *      KEY="value"   ← double quote (তোমার format)
 *      KEY='value'   ← single quote
 *      KEY=value     ← plain
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('cron_load_vault')) {
    /**
     * @return array<string,string>
     */
    function cron_load_vault(string $path): array
    {
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            cron_error('Vault could not be read.');
        }

        $config = [];

        foreach ($lines as $lineNumber => $line) {

            $line = trim($line);

            /* খালি লাইন */
            if ($line === '') {
                continue;
            }

            /* পুরো লাইন comment */
            if (str_starts_with($line, '#')) {
                continue;
            }

            /* UTF-8 BOM — প্রথম লাইনে থাকতে পারে */
            if ($lineNumber === 0) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
            }

            /* প্রথম = তে কাটো */
            $sep = strpos($line, '=');
            if ($sep === false) {
                cron_error('Invalid Vault syntax on line ' . ($lineNumber + 1) . '.');
            }

            $key   = trim(substr($line, 0, $sep));
            $value = trim(substr($line, $sep + 1));

            /* key ভ্যালিডেশন */
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                cron_error('Invalid Vault key on line ' . ($lineNumber + 1) . '.');
            }

            /* quotes সরাও */
            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last  = $value[$len - 1];

                if (
                    ($first === '"' && $last === '"') ||
                    ($first === "'" && $last === "'")
                ) {
                    $value = substr($value, 1, -1);

                    /* double quote escape handling */
                    if ($first === '"') {
                        $value = str_replace(
                            ['\n',  '\r',  '\t',  '\"', '\\\\'],
                            ["\n",  "\r",  "\t",  '"',  '\\'],
                            $value
                        );
                    }
                }
            }

            $config[$key] = $value;
        }

        return $config;
    }
}

/* ═══════════════════════════════════════════════════════════════
 * 7. Vault লোড
 * ═══════════════════════════════════════════════════════════════ */
$_cronConfig = cron_load_vault(VAULT_PATH);

if (empty($_cronConfig)) {
    cron_error('Vault is empty.');
}

/* ═══════════════════════════════════════════════════════════════
 * 8. Required keys চেক
 * ═══════════════════════════════════════════════════════════════ */
foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'CARD_ENC_KEY'] as $_key) {
    if (
        !array_key_exists($_key, $_cronConfig) ||
        trim((string)$_cronConfig[$_key]) === ''
    ) {
        cron_error("Required config missing: {$_key}");
    }
}
unset($_key);

/* ═══════════════════════════════════════════════════════════════
 * 9. DB কনফিগ ভ্যারিয়েবল
 * ═══════════════════════════════════════════════════════════════ */
$_dbHost = trim((string)$_cronConfig['DB_HOST']);
$_dbUser = trim((string)$_cronConfig['DB_USER']);
$_dbPass =       (string)$_cronConfig['DB_PASS'];  // trim নয় — space intentional হতে পারে
$_dbName = trim((string)$_cronConfig['DB_NAME']);

/* ═══════════════════════════════════════════════════════════════
 * 10. CARD_ENC_KEY
 * ═══════════════════════════════════════════════════════════════ */
if (!defined('CARD_ENC_KEY')) {
    define('CARD_ENC_KEY', (string)$_cronConfig['CARD_ENC_KEY']);
}

/* Config array আর দরকার নেই — মুছো */
unset($_cronConfig);

/* Basic validation */
if ($_dbHost === '') { cron_error('DB_HOST is empty.'); }
if ($_dbUser === '') { cron_error('DB_USER is empty.'); }
if ($_dbName === '') { cron_error('DB_NAME is empty.'); }

/* ═══════════════════════════════════════════════════════════════
 * 11. PDO কানেকশন
 * ═══════════════════════════════════════════════════════════════ */
try {
    $conn = new PDO(
        "mysql:host={$_dbHost};dbname={$_dbName};charset=utf8mb4",
        $_dbUser,
        $_dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]
    );
} catch (PDOException $e) {
    error_log('CronBootstrap PDO Error: ' . $e->getMessage());
    cron_error('Database connection failed.');
}

/* DB credentials আর দরকার নেই — মুছো */
unset($_dbHost, $_dbUser, $_dbPass, $_dbName);

/* ═══════════════════════════════════════════════════════════════
 * 12. Connection ভ্যালিডেশন
 * ═══════════════════════════════════════════════════════════════ */
if (!$conn instanceof PDO) {
    cron_error('PDO connection was not initialized.');
}

/* ═══════════════════════════════════════════════════════════════
 * 13. DB Health Check
 * ═══════════════════════════════════════════════════════════════ */
try {
    $conn->query('SELECT 1');
} catch (PDOException $e) {
    error_log('CronBootstrap Health Check Error: ' . $e->getMessage());
    cron_error('Database health check failed.');
}

/* ═══════════════════════════════════════════════════════════════
 * ✅ প্রস্তুত
 *    ক্রোন ফাইলে শুধু পাবে:
 *      $conn          → PDO object
 *      CARD_ENC_KEY   → constant
 *      cron_log_success() / cron_log_fail()  → লগ হেল্পার
 *    DB credentials কোথাও নেই
 * ═══════════════════════════════════════════════════════════════ */
