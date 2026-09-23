<?php
declare(strict_types=1);

/**
 * =====================================================================
 * SADA KALO FASHION — Auto-Block Cron Runner
 * File : admin/cron_auto_block.php
 * =====================================================================
 *
 * CLI-only script. Web থেকে সরাসরি অ্যাক্সেস করা যাবে না।
 *
 * Cron job setup (প্রতি ১ মিনিটে):
 *   * * * * * php /var/www/html/admin/cron_auto_block.php >> /var/log/sada_kalo_cron.log 2>&1
 *
 * অথবা ফুল পাথ দিয়ে (সার্ভার অনুযায়ী পরিবর্তন করুন):
 *   * * * * * /usr/bin/php8.2 /home/user/public_html/admin/cron_auto_block.php >> /tmp/sk_cron.log 2>&1
 */

// ── CLI Guard: Web থেকে কেউ এই ফাইলে ঢুকতে পারবে না ──────────────────────────
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied.');
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
define('CRON_START', microtime(true));

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors',     '1');

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/auto_block_engine.php';

// ── Run Engine ────────────────────────────────────────────────────────────────
try {
    runAutoBlockEngine($conn);
    $elapsed = round((microtime(true) - CRON_START) * 1000, 2);
    echo '[' . date('Y-m-d H:i:s') . '] AutoBlock OK — ' . $elapsed . 'ms' . PHP_EOL;
} catch (Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] AutoBlock ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
