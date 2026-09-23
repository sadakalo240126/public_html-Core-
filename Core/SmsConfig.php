<?php
declare(strict_types=1);

/**
 * Core/SmsConfig.php — কেন্দ্রীয় SMS + OTP কনফিগ ও হেল্পার
 * ────────────────────────────────────────────────────────────────
 *  auto-load: .env থেকে SMS credential
 *  OTP      : 5 মিনিট মেয়াদ, হ্যাশ করে সেশনে রাখে
 *  SMS লিমিট: একই নম্বরে 30 মিনিটে সর্বোচ্চ 3 বার
 *  Log      : success + error দুটোই লেখে
 *
 *  ব্যবহার (যেকোনো পেজে একটা লাইন):
 *    require_once __DIR__ . '/Core/SmsConfig.php';
 */

/* ═══════════════════════════════════════════════════════════════
 *  ১. VAULT_PATH নিশ্চিত
 * ═══════════════════════════════════════════════════════════════ */
if (!defined('VAULT_PATH')) {
    define('VAULT_PATH', '/home/sadakalo/App/.env');
}

/* ═══════════════════════════════════════════════════════════════
 *  ২. OTP ও SMS লিমিট কনস্ট্যান্ট
 * ═══════════════════════════════════════════════════════════════ */
if (!defined('OTP_EXPIRE_MIN'))    define('OTP_EXPIRE_MIN', 5);      // OTP 5 মিনিট
if (!defined('SMS_LIMIT_COUNT'))   define('SMS_LIMIT_COUNT', 3);     // 3 বার
if (!defined('SMS_LIMIT_MINUTES')) define('SMS_LIMIT_MINUTES', 30);  // 30 মিনিটে

/* ═══════════════════════════════════════════════════════════════
 *  ৩. .env থেকে SMS credential auto-load → constant
 * ═══════════════════════════════════════════════════════════════ */
if (!defined('SMS_LOADED')) {
    $__smsEnv = is_file(VAULT_PATH) ? parse_ini_file(VAULT_PATH) : [];
    if (!is_array($__smsEnv)) $__smsEnv = [];

    define('SMS_API_URL',  trim((string)($__smsEnv['SMS_API_URL']  ?? '')));
    define('SMS_USERNAME', trim((string)($__smsEnv['SMS_USERNAME'] ?? '')));
    define('SMS_API_KEY',  trim((string)($__smsEnv['SMS_API_KEY']  ?? '')));
    define('SMS_SENDER',   trim((string)($__smsEnv['SMS_SENDER']   ?? '')));
    define('SMS_TYPE',     trim((string)($__smsEnv['SMS_TYPE']     ?? 'T')));

    unset($__smsEnv);
    define('SMS_LOADED', true);
}

/* ═══════════════════════════════════════════════════════════════
 *  ৪. নম্বর নরমালাইজ (mobile ও phone দুটোই → 88 প্রিফিক্স)
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('sms_normalize_number')) {
    function sms_normalize_number(string $phone): string
    {
        $mobile = preg_replace('/\D/', '', $phone);
        if (strlen($mobile) === 11 && str_starts_with($mobile, '0')) {
            $mobile = '88' . $mobile;                 // 01712... → 8801712...
        } elseif (strlen($mobile) === 10) {
            $mobile = '880' . $mobile;                // 1712...   → 8801712...
        }
        return $mobile;
    }
}

/* ═══════════════════════════════════════════════════════════════
 *  ৫. Log হেল্পার (success + error)
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('sms_log')) {
    function sms_log(string $level, string $msg): void
    {
        $dir = __DIR__ . '/../Logs';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $file = ($level === 'ERROR') ? '/Sms_error_log.txt' : '/sms_log.txt';
        file_put_contents(
            $dir . $file,
            '[' . date('Y-m-d H:i:s') . "] SMS {$level}: {$msg}\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

/* ═══════════════════════════════════════════════════════════════
 *  ৬. লিমিট ট্র্যাকিং (একই নম্বরে 30 মিনিটে 3 বার)
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('sms_limit_file')) {
    function sms_limit_file(): string
    {
        $dir = __DIR__ . '/../Logs/sms_limits';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        return $dir . '/sms_count.json';
    }
}

if (!function_exists('sms_can_send')) {
    function sms_can_send(string $phone): bool
    {
        $file   = sms_limit_file();
        $data   = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        if (!is_array($data)) $data = [];

        $mobile = sms_normalize_number($phone);
        $cutoff = time() - (SMS_LIMIT_MINUTES * 60);

        $recent = [];
        if (isset($data[$mobile]) && is_array($data[$mobile])) {
            $recent = array_filter($data[$mobile], fn(int $ts) => $ts > $cutoff);
        }
        return count($recent) < SMS_LIMIT_COUNT;
    }
}

if (!function_exists('sms_record_sent')) {
    function sms_record_sent(string $phone): void
    {
        $file   = sms_limit_file();
        $data   = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        if (!is_array($data)) $data = [];

        $mobile = sms_normalize_number($phone);
        $cutoff = time() - (SMS_LIMIT_MINUTES * 60);

        if (isset($data[$mobile]) && is_array($data[$mobile])) {
            $data[$mobile] = array_values(array_filter($data[$mobile], fn(int $ts) => $ts > $cutoff));
        } else {
            $data[$mobile] = [];
        }

        $data[$mobile][] = time();
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }
}

if (!function_exists('sms_remaining')) {
    function sms_remaining(string $phone): int
    {
        $file   = sms_limit_file();
        $data   = is_file($file) ? json_decode(file_get_contents($file), true) : [];
        if (!is_array($data)) return SMS_LIMIT_COUNT;

        $mobile = sms_normalize_number($phone);
        $cutoff = time() - (SMS_LIMIT_MINUTES * 60);

        if (!isset($data[$mobile]) || !is_array($data[$mobile])) return SMS_LIMIT_COUNT;

        $recent = array_filter($data[$mobile], fn(int $ts) => $ts > $cutoff);
        return max(0, SMS_LIMIT_COUNT - count($recent));
    }
}

/* ═══════════════════════════════════════════════════════════════
 *  ৭. OTP জেনারেট + হ্যাশ (সেশনে হ্যাশ রাখে, প্লেইন রিটার্ন করে)
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('otp_generate')) {
    /**
     * 6-ডিজিট OTP বানায়, হ্যাশ করে সেশনে রাখে, প্লেইন কোড রিটার্ন করে।
     * প্লেইন কোডটা SMS/Email এ পাঠাবেন — সেশনে শুধু হ্যাশ থাকে।
     * $purpose দিয়ে আলাদা কাজ আলাদা করুন: 'login', 'reset', 'admin'...
     */
    function otp_generate(string $purpose = 'default'): string
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $_SESSION['otp'][$purpose] = [
            'hash'    => password_hash($code, PASSWORD_DEFAULT),
            'expires' => time() + (OTP_EXPIRE_MIN * 60),
            'tries'   => 0,
        ];
        return $code;
    }
}

if (!function_exists('otp_verify')) {
    /**
     * ইউজারের দেওয়া কোড সেশনের হ্যাশের সাথে মেলায়।
     * সফল হলে OTP মুছে ফেলে (এক বারই ব্যবহারযোগ্য)।
     * সর্বোচ্চ 5 বার ভুল চেষ্টা → ব্লক।
     */
    function otp_verify(string $input, string $purpose = 'default'): bool
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $rec = $_SESSION['otp'][$purpose] ?? null;
        if (!is_array($rec)) return false;

        if (time() > ($rec['expires'] ?? 0)) {          // মেয়াদ শেষ
            unset($_SESSION['otp'][$purpose]);
            return false;
        }

        if (($rec['tries'] ?? 0) >= 5) {                // বেশি ভুল চেষ্টা
            unset($_SESSION['otp'][$purpose]);
            return false;
        }

        $input = preg_replace('/\D/', '', $input);
        if (password_verify($input, $rec['hash'])) {
            unset($_SESSION['otp'][$purpose]);          // এক বার ব্যবহার
            return true;
        }

        $_SESSION['otp'][$purpose]['tries']++;
        return false;
    }
}

/* ═══════════════════════════════════════════════════════════════
 *  ৮. মূল হেল্পার — OTP SMS পাঠানো
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('send_otp_sms')) {
    function send_otp_sms(string $phone, string $otpCode): bool
    {
        if (SMS_API_URL === '' || SMS_API_KEY === '') {
            sms_log('ERROR', "credentials missing in .env | {$phone}");
            return false;
        }

        if (!sms_can_send($phone)) {
            sms_log('ERROR', 'limit (' . SMS_LIMIT_COUNT . ' per ' . SMS_LIMIT_MINUTES . " min) reached | {$phone}");
            return false;
        }

        $mobile = sms_normalize_number($phone);
        if (strlen($mobile) < 13) {
            sms_log('ERROR', "invalid phone: {$phone}");
            return false;
        }

        $payload = json_encode([
            'UserName'        => SMS_USERNAME,
            'Apikey'          => SMS_API_KEY,
            'MobileNumber'    => $mobile,
            'SenderName'      => SMS_SENDER,
            'TransactionType' => SMS_TYPE,
            'CampaignId'      => 'null',
            'Message'         => "OTP-{$otpCode}",
        ]);

        $ch = curl_init(SMS_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: bearer',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError)         { sms_log('ERROR', "cURL: {$curlError} | {$mobile}"); return false; }
        if ($httpCode !== 200)  { sms_log('ERROR', "HTTP {$httpCode} | {$response} | {$mobile}"); return false; }

        sms_record_sent($phone);
        sms_log('SUCCESS', "sent to {$mobile} | remaining " . sms_remaining($phone));   // ✅ success log
        return true;
    }
}

/* ═══════════════════════════════════════════════════════════════
 *  ৯. এক লাইনে OTP বানাও + পাঠাও (সুবিধার জন্য)
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('otp_send_sms')) {
    /**
     * OTP জেনারেট + হ্যাশ + SMS পাঠানো — এক ধাপে।
     * সফল হলে true; ভেরিফাই করতে otp_verify($input, $purpose) ডাকুন।
     */
    function otp_send_sms(string $phone, string $purpose = 'login'): bool
    {
        $code = otp_generate($purpose);   // সেশনে হ্যাশ রাখে
        return send_otp_sms($phone, $code);
    }
}

/* ═══════════════════════════════════════════════════════════════
 *  ১০. জেনারেল SMS পাঠানো (যেকোনো মেসেজ) — OTP লিমিটের বাইরে
 *      যেমন: অ্যাডমিনকে উত্তোলন নোটিফিকেশন, অ্যালার্ট ইত্যাদি
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('send_sms')) {
    /**
     * যেকোনো টেক্সট মেসেজ পাঠায়। OTP নয় — তাই 30 মিনিটের rate-limit
     * প্রযোজ্য নয় (নোটিফিকেশন সবসময় যাওয়া উচিত)।
     * সফল হলে true।
     */
    function send_sms(string $phone, string $message): bool
    {
        if (SMS_API_URL === '' || SMS_API_KEY === '') {
            sms_log('ERROR', "credentials missing in .env | {$phone}");
            return false;
        }

        $mobile = sms_normalize_number($phone);
        if (strlen($mobile) < 13) {
            sms_log('ERROR', "invalid phone: {$phone}");
            return false;
        }

        $payload = json_encode([
            'UserName'        => SMS_USERNAME,
            'Apikey'          => SMS_API_KEY,
            'MobileNumber'    => $mobile,
            'SenderName'      => SMS_SENDER,
            'TransactionType' => SMS_TYPE,
            'CampaignId'      => 'null',
            'Message'         => $message,
        ]);

        $ch = curl_init(SMS_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: bearer',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError)        { sms_log('ERROR', "cURL: {$curlError} | {$mobile}"); return false; }
        if ($httpCode !== 200) { sms_log('ERROR', "HTTP {$httpCode} | {$response} | {$mobile}"); return false; }

        sms_log('SUCCESS', "sent to {$mobile} | " . mb_substr($message, 0, 40));
        return true;
    }
}