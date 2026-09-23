<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Dhaka');

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../Controllers/AuthController.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$msg = '';
$step = (int)($_SESSION['otp_step'] ?? 1);
$is_setup = isset($_GET['setup']) && isset($_SESSION['setup_id']);
$setup_id = $is_setup ? (int)$_SESSION['setup_id'] : null;
$delivery = strtoupper((string)($_SESSION['otp_delivery_method'] ?? ''));
$authController = new AuthController($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
        $msg = "<div class='alert error'>❌ নিরাপত্তা টোকেনটি অকার্যকর! অনুগ্রহ করে পেজটি রিফ্রেশ করে পুনরায় চেষ্টা করুন।</div>";
    } elseif (isset($_POST['send_otp'])) {
        $identifier = trim((string)($_POST['identifier'] ?? ''));
        $delivery = strtoupper((string)($_POST['delivery_method'] ?? 'SMS'));
        if (!in_array($delivery, ['EMAIL','SMS'], true)) $delivery = 'SMS';
        $response = $authController->processOtpRequest($identifier, $is_setup, $setup_id, $delivery);
        $msg = $response['message'] ?? '';
        $step = !empty($response['success']) ? 2 : 1;
        if (!empty($response['success'])) $_SESSION['otp_step'] = 2;
    } elseif (isset($_POST['verify_otp'])) {
        $identifier = (string)($_SESSION['auth_email'] ?? '');
        $otp = trim((string)($_POST['otp'] ?? ''));
        $response = $authController->verifyAndReset($identifier, $otp);
        if (!empty($response['success'])) { 
            $step = 3; 
            $msg = "<div class='alert success'>✅ যাচাইকরণ সফল হয়েছে! অনুগ্রহ করে আপনার নতুন পাসওয়ার্ড প্রদান করুন।</div>"; 
        } else { 
            $step = 2; 
            $msg = $response['message'] ?? "<div class='alert error'>❌ ওটিপি (OTP) যাচাই করা সম্ভব হয়নি।</div>"; 
        }
    } elseif (isset($_POST['reset_password'])) {
        $identifier = (string)($_SESSION['auth_email'] ?? '');
        $new_pass = (string)($_POST['new_password'] ?? '');
        $confirm_pass = (string)($_POST['confirm_password'] ?? '');
        
        if ($new_pass !== $confirm_pass || strlen($new_pass) < 4 || strlen($new_pass) > 8) {
            $step = 3; 
            $msg = "<div class='alert error'>❌ পাসওয়ার্ড দুটি মিলছে না অথবা এটি ৪ থেকে ৮ অক্ষরের মধ্যে নেই!</div>";
        } else {
            $response = $authController->verifyAndReset($identifier, 'forced_skip', $new_pass);
            if (!empty($response['success'])) {
                $step = 1; 
                // এখানে এইচটিএমএল সিনট্যাক্স এররটি সংশোধন করা হয়েছে
                $msg = "<div class='alert success'>✅ আপনার পাসওয়ার্ড সফলভাবে আপডেট করা হয়েছে!<br><br><a href='/index.php' class='login-link'>লগইন করতে এখানে ক্লিক করুন</a></div>";
            } else { 
                $step = 3; 
                $msg = $response['message'] ?? "<div class='alert error'>❌ পাসওয়ার্ড আপডেট করা সম্ভব হয়নি।</div>"; 
            }
        }
    }
}

$remaining = 0;
if ($step === 2 && !empty($_SESSION['otp_sent_at'])) {
    $validity = 300;
    try { 
        $st = $conn->query("SELECT otp_validity_seconds FROM Global_Sms_Settings WHERE id=1 LIMIT 1"); 
        $v = $st ? $st->fetchColumn() : null; 
        if ($v !== false && $v !== null) $validity = max(60, (int)$v); 
    } catch(Throwable $e) {}
    $remaining = max(0, $validity - (time() - (int)$_SESSION['otp_sent_at']));
}
?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<meta name="theme-color" content="#0a0a0a">
<link rel="manifest" href="/manifest.json">
<?php require $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_assets.php'; ?>
<title>Forgot Password — SADA KALO FASHION</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
/* কর্পোরেট এবং প্রফেশনাল কালার গ্রেডিং ডিজাইন */
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    min-height: 100vh;
    background: radial-gradient(circle at top right, #1a1a24 0%, #050505 60%, #000000 100%);
    color: #f8fafc;
    font-family: 'Inter', system-ui, sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 14px;
}
.card {
    width: 100%;
    max-width: 440px;
    background: rgba(18, 18, 24, 0.85);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(212, 175, 55, 0.15);
    border-radius: 24px;
    padding: 32px 24px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
    text-align: center;
}
.logo {
    width: 85px;
    height: 85px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #d4af37;
    box-shadow: 0 0 30px rgba(212, 175, 55, 0.25);
    margin-bottom: 12px;
}
h1 {
    font-size: 22px;
    margin: 8px 0 4px;
    font-weight: 800;
    letter-spacing: 0.5px;
    color: #ffffff;
}
.sub {
    font-size: 13px;
    color: #d4af37;
    font-weight: 600;
    margin: 0 0 24px;
    letter-spacing: 0.5px;
}
.home {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    text-decoration: none;
    color: #a1a1aa;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 10px 16px;
    margin-bottom: 24px;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.3s ease;
}
.home:hover {
    color: #ffffff;
    background: rgba(255, 255, 255, 0.08);
}
.methods {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 18px;
}
.method {
    position: relative;
}
.method input {
    position: absolute;
    opacity: 0;
}
.method label {
    display: block;
    padding: 16px 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 14px;
    background: rgba(0, 0, 0, 0.4);
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    color: #a1a1aa;
    transition: all 0.3s ease;
}
.method label i {
    font-size: 18px;
    margin-bottom: 8px;
    color: #64748b;
    transition: color 0.3s ease;
}
.method input:checked + label {
    border-color: #d4af37;
    background: rgba(212, 175, 55, 0.08);
    color: #ffffff;
    box-shadow: 0 0 15px rgba(212, 175, 55, 0.1);
}
.method input:checked + label i {
    color: #d4af37;
}
.hint {
    font-size: 12px;
    color: #94a3b8;
    margin: 0 0 18px;
    line-height: 1.5;
}
.field {
    position: relative;
    margin-bottom: 16px;
}
.field i {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 14px;
}
.field input {
    width: 100%;
    padding: 14px 14px 14px 44px;
    background: rgba(0, 0, 0, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: #ffffff;
    border-radius: 12px;
    outline: none;
    font-size: 14px;
    transition: all 0.3s ease;
}
.field input:focus {
    border-color: #d4af37;
    background: rgba(0, 0, 0, 0.8);
    box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.1);
}
.btn {
    width: 100%;
    border: 0;
    border-radius: 12px;
    padding: 15px;
    background: linear-gradient(135deg, #d4af37, #aa8529);
    color: #000000;
    font-weight: 800;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.btn:hover {
    background: linear-gradient(135deg, #e3c457, #bd962e);
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(212, 175, 55, 0.3);
}
.alert {
    padding: 14px;
    border-radius: 12px;
    margin-bottom: 18px;
    font-size: 13px;
    font-weight: 600;
    text-align: left;
    line-height: 1.5;
}
.success {
    background: rgba(6, 78, 59, 0.3);
    color: #34d399;
    border: 1px solid rgba(52, 211, 153, 0.3);
}
.error {
    background: rgba(127, 29, 29, 0.3);
    color: #fca5a5;
    border: 1px solid rgba(248, 113, 113, 0.3);
}
.timer {
    margin: 4px 0 20px;
    padding: 12px;
    border-radius: 12px;
    background: rgba(0, 0, 0, 0.4);
    border: 1px solid rgba(255, 255, 255, 0.1);
    font-size: 14px;
    font-weight: 600;
}
.timer b {
    color: #d4af37;
    font-size: 18px;
    margin-left: 5px;
}
.small {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 14px;
}
.login-link {
    color: #d4af37;
    font-weight: 700;
    text-decoration: none;
    border-bottom: 1px solid #d4af37;
    padding-bottom: 2px;
}
.login-link:hover {
    color: #e3c457;
}
</style>
</head>
<body>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_shell.php'; ?>
<div class="card">
    <a class="home" href="/index.php"><i class="fas fa-arrow-left"></i> লগইন পেজে ফিরে যান</a>
    <img src="/logo.png" class="logo" alt="SADA KALO FASHION">
    <h1>SADA KALO FASHION</h1>
    <p class="sub"><?= $is_setup ? 'Account Security Setup' : 'Forgot Password & Verification' ?></p>
    
    <?= $msg ?>

    <?php if ($step === 1 && strpos($msg, 'সফলভাবে') === false): ?>
    <form method="post" id="sendForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="methods">
            <div class="method">
                <input id="email" type="radio" name="delivery_method" value="EMAIL" <?= $delivery === 'EMAIL' ? 'checked' : '' ?>>
                <label for="email"><i class="fas fa-envelope"></i><br>Email</label>
            </div>
            <div class="method">
                <input id="sms" type="radio" name="delivery_method" value="SMS" <?= $delivery !== 'EMAIL' ? 'checked' : '' ?>>
                <label for="sms"><i class="fas fa-comment-sms"></i><br>SMS + Email</label>
            </div>
        </div>
        <div class="hint" id="methodHint">এসএমএস (SMS) নির্বাচন করলে আপনার মোবাইল নম্বরে এবং বৈধ ইমেইল থাকলে সেখানেও একই ওটিপি (OTP) পাঠানো হবে।</div>
        <div class="field">
            <i class="fas fa-user"></i>
            <input type="text" name="identifier" placeholder="ইমেইল অথবা মোবাইল নম্বর দিন" required autocomplete="On">
        </div>
        <button class="btn" name="send_otp" type="submit"><i class="fas fa-paper-plane"></i> ওটিপি (OTP) পাঠান</button>
    </form>
    
    <?php elseif ($step === 2): ?>
    <div class="timer">ওটিপি (OTP) এর মেয়াদ শেষ হবে: <b id="timer">--:--</b></div>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="field">
            <i class="fas fa-key"></i>
            <input type="text" name="otp" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="Otp Verify " required autocomplete="one-time-code" style="text-align:center; font-size:22px; letter-spacing:8px; font-weight:700;">
        </div>
        <button class="btn" name="verify_otp" type="submit"><i class="fas fa-check-circle"></i> যাচাই করুন</button>
    </form>
    <div class="small">ভুল কোড প্রদান করলে আপনি সর্বোচ্চ ৫ বার চেষ্টা করার সুযোগ পাবেন।</div>
    
    <?php elseif ($step === 3): ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="field">
            <i class="fas fa-lock"></i>
            <input type="password" name="new_password" minlength="4" maxlength="8" placeholder="নতুন পাসওয়ার্ড (৪-৮ অক্ষর)" required>
        </div>
        <div class="field">
            <i class="fas fa-check-double"></i>
            <input type="password" name="confirm_password" minlength="4" maxlength="8" placeholder="পুনরায় পাসওয়ার্ডটি দিন" required>
        </div>
        <button class="btn" name="reset_password" type="submit"><i class="fas fa-save"></i> পাসওয়ার্ড সেভ করুন</button>
    </form>
    <?php endif; ?>
</div>

<script>
(function(){
    const hint = document.getElementById('methodHint');
    document.querySelectorAll('input[name="delivery_method"]').forEach(r => {
        r.addEventListener('change', () => {
            hint.textContent = r.value === 'EMAIL' 
                ? 'ইমেইল (Email) নির্বাচন করলে শুধুমাত্র ইমেইলে ওটিপি (OTP) পাঠানো হবে।' 
                : 'এসএমএস (SMS) নির্বাচন করলে আপনার মোবাইল নম্বরে এবং বৈধ ইমেইল থাকলে সেখানেও একই ওটিপি (OTP) পাঠানো হবে।';
        });
    });

    let left = <?= (int)$remaining ?>;
    let el = document.getElementById('timer');
    if (el) { 
        const tick = () => {
            left = Math.max(0, left); 
            const m = Math.floor(left / 60);
            const s = left % 60; 
            el.textContent = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'); 
            
            if (left === 0) {
                el.textContent = '00:00';
                el.style.color = '#ef4444'; // Red color when time is up
            } else {
                left--;
                setTimeout(tick, 1000);
            }
        }; 
        tick(); 
    }
})();
</script>
</body>
</html>