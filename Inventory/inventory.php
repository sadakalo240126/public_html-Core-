<?php
declare(strict_types=1);

session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// ── Helpers ───────────────────────────────────────────────
require_once __DIR__ . '/Helpers/ImageHelper.php';
require_once __DIR__ . '/Helpers/StorageLocationHelper.php';
require_once __DIR__ . '/Helpers/LocationAuditHelper.php';
require_once __DIR__ . '/Helpers/CategoryFolderHelper.php';
require_once __DIR__ . '/Helpers/EmailHelper.php';
require_once __DIR__ . '/Helpers/Validator.php';

// ── Error Logger ──────────────────────────────────────────
function logSystemError(Throwable $e): void {
    $logDir = __DIR__ . '/../Logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    file_put_contents(
        $logDir . '/error_log.txt',
        '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() .
        ' in ' . $e->getFile() . ' on line ' . $e->getLine() . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// ── Session / Auth ────────────────────────────────────────
$lastActivity = $_SESSION['last_activity'] ?? null;
if ($lastActivity !== null && (time() - (int)$lastActivity > 1200)) {
    session_unset(); session_destroy();
    echo "<script>window.location.href='../index.php';</script>"; exit;
}
$_SESSION['last_activity'] = time();

if (empty($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo "<script>window.location.href='../index.php';</script>"; exit;
}

if (empty($_SESSION['csrf_token'])) {
    try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    catch (Exception $e) { $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32)); }
}
$csrfToken = (string)$_SESSION['csrf_token'];

// ── DB + Audit ────────────────────────────────────────────
$db_path = __DIR__ . '/../db_connect.php';
if (file_exists($db_path)) { require_once $db_path; }
/** @var PDO $conn */

$uid      = (int)($_SESSION['user_id'] ?? 1);
$userName = (string)($_SESSION['username'] ?? 'User');
$role     = strtolower(trim((string)($_SESSION['role'] ?? 'user')));
$isAdmin  = ($role === 'admin');
date_default_timezone_set('Asia/Dhaka');

require_once __DIR__ . '/Helpers/AuditInit.php';
AuditInit::boot($conn);

// ── Upload Lock ───────────────────────────────────────────
function inv_lock_path(): string { return __DIR__ . '/lock.json'; }

function inv_is_upload_locked(): bool {
    $f = inv_lock_path();
    if (!is_file($f)) return false;
    $data = json_decode((string)@file_get_contents($f), true);
    return is_array($data) && !empty($data['upload_locked']);
}

function inv_set_upload_locked(bool $locked): array {
    $path    = inv_lock_path();
    $payload = json_encode([
        'upload_locked' => $locked,
        'updated_at'    => date('c'),
        'updated_by'    => (string)($_SESSION['user_id'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($payload === false) return ['ok' => false, 'error' => 'JSON encode ব্যর্থ'];
    $written = @file_put_contents($path, $payload, LOCK_EX);
    clearstatcache(true, $path);
    if ($written === false) return ['ok' => false, 'error' => 'lock.json লিখা যায়নি।'];
    return inv_is_upload_locked() === $locked
        ? ['ok' => true, 'error' => '']
        : ['ok' => false, 'error' => 'read-back মিলেনি'];
}

// ═════════════════════════════════════════════════════════
// AJAX Handlers
// ═════════════════════════════════════════════════════════
$ajaxAction = isset($_POST['ajax_action']) && is_string($_POST['ajax_action'])
    ? $_POST['ajax_action'] : '';

if ($ajaxAction !== '') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    // CSRF — check_duplicate ও get_upload_lock ছাড়া সবখানে
    if (!in_array($ajaxAction, ['check_duplicate','get_upload_lock'], true)) {
        if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['status'=>'error','message'=>'সিকিউরিটি টোকেন মিসম্যাচ!']); exit;
        }
    }

    // ── get_upload_lock ──────────────────────────────────
    if ($ajaxAction === 'get_upload_lock') {
        echo json_encode(['status'=>'success','upload_locked'=>inv_is_upload_locked()]);
        exit;
    }

    // ── toggle_upload_lock ───────────────────────────────
    if ($ajaxAction === 'toggle_upload_lock') {
        if (!$isAdmin) {
            echo json_encode(['status'=>'error','message'=>'শুধু এডমিন লক পরিবর্তন করতে পারেন!']);
            exit;
        }
        $newState = !inv_is_upload_locked();
        $result   = inv_set_upload_locked($newState);
        if (empty($result['ok'])) {
            echo json_encode(['status'=>'error','message'=>$result['error'] ?? 'lock.json লিখা যায়নি!']);
            exit;
        }
        echo json_encode([
            'status'        => 'success',
            'upload_locked' => $newState,
            'message'       => $newState ? 'আপলোড লক ON — শুধু লাইভ ক্যামেরা' : 'আপলোড লক OFF — গ্যালারি + ক্যামেরা',
        ]);
        exit;
    }

    // ── check_duplicate ──────────────────────────────────
    if ($ajaxAction === 'check_duplicate') {
        try {
            $code = trim((string)($_POST['product_code'] ?? ''));
            $st   = $conn->prepare("SELECT id FROM inventory WHERE product_code = ? LIMIT 1");
            $st->execute([$code]);
            echo json_encode(['status' => $st->rowCount() > 0 ? 'exists' : 'clear']);
        } catch (PDOException $e) {
            logSystemError($e);
            echo json_encode(['status'=>'error','message'=>'ডাটাবেস এরর!']);
        }
        exit;
    }

    // ── get_location_audit_log ───────────────────────────
    if ($ajaxAction === 'get_location_audit_log') {
        echo json_encode(LocationAuditHelper::fetchRecent($conn, 20));
        exit;
    }

    // ── add_category ─────────────────────────────────────
    if ($ajaxAction === 'add_category') {
        try {
            if (!$isAdmin) throw new Exception('অনুমতি নেই!');
            $catName = trim((string)($_POST['category_name'] ?? ''));
            if ($catName === '') throw new Exception('নাম প্রয়োজন!');

            $conn->beginTransaction();
            $st = $conn->prepare("INSERT INTO categories (name, status) VALUES (?, 'active')");
            $st->execute([$catName]);
            $newCatId = (int)$conn->lastInsertId();
            $conn->commit();

            // ── ক্যাটাগরির সাথে সাথে ফোল্ডার তৈরি ────
            CategoryFolderHelper::create($catName);

            // Audit log
            if (class_exists('AuditLogger') && $newCatId > 0) {
                AuditLogger::create('categories', $newCatId, null,
                    ['name' => $catName, 'status' => 'active'],
                    "ক্যাটাগরি অ্যাড — {$catName}"
                );
            }

            echo json_encode([
                'status' => 'success',
                'id'     => $newCatId,
                'name'   => htmlspecialchars($catName, ENT_QUOTES, 'UTF-8'),
            ]);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            logSystemError($e);
            echo json_encode(['status'=>'error','message'=>'ডাটাবেস এরর!']);
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }

    // ── add_product ──────────────────────────────────────
    if ($ajaxAction === 'add_product') {
        try {
            // ১) Validate সব ইনপুট একসাথে
            Validator::validateProductForm($_POST);

            // ২) ক্যাটাগরি ও নাম
            $categoryId  = (int)$_POST['category_id'];
            $catNameStmt = $conn->prepare("SELECT name FROM categories WHERE id = ? LIMIT 1");
            $catNameStmt->execute([$categoryId]);
            $productName = (string)($catNameStmt->fetchColumn() ?: '');
            if ($productName === '') throw new Exception('ক্যাটাগরি পাওয়া যায়নি!');

            // ৩) মূল্য
            $pieces   = (int)$_POST['pieces'];
            $buyPrice = (float)$_POST['buy_price'];
            $cashSell = (float)$_POST['cash_sell'];
            $finalCost = $isAdmin ? (float)$_POST['cost'] : 15.0;

            if (!$isAdmin && $cashSell < ($buyPrice + $finalCost)) {
                throw new Exception(
                    "বিক্রি মূল্য অবশ্যই ক্রয় মূল্য (৳{$buyPrice}) ও খরচের (৳{$finalCost}) যোগফলের সমান বা বেশি!"
                );
            }

            // ৪) লোকেশন validate ── StorageLocationHelper
            $itemLocation = StorageLocationHelper::validate($_POST['item_location'] ?? null);

            // ৫) Product code
            $imageSource    = trim((string)($_POST['image_source'] ?? 'camera'));
            $newProductCode = trim((string)($_POST['product_code'] ?? ''));
            if ($newProductCode === '') {
                $lastCode = $conn->query(
                    "SELECT product_code FROM inventory WHERE product_code LIKE 'SKF-%'
                     ORDER BY CAST(SUBSTRING_INDEX(product_code,'-',-1) AS UNSIGNED) DESC LIMIT 1"
                )->fetchColumn();
                $num = $lastCode ? (int)str_replace('SKF-', '', $lastCode) + 1 : 1;
                $newProductCode = 'SKF-' . str_pad((string)$num, 2, '0', STR_PAD_LEFT);
            }

            // ৬) Duplicate check
            $dupSt = $conn->prepare("SELECT id FROM inventory WHERE product_code = ? LIMIT 1");
            $dupSt->execute([$newProductCode]);
            if ($dupSt->rowCount() > 0) throw new Exception('এই বারকোডটি ইতিমধ্যে রয়েছে!');

            // ৭) ছবি — ImageHelper দিয়ে resize + ক্যাটাগরি ফোল্ডারে সেভ
            $uploadedImagePath = '';
            $hasImage = !empty($_POST['base64_image']) && is_string($_POST['base64_image']);

            if ($hasImage && inv_is_upload_locked() && $imageSource === 'upload') {
                throw new Exception('আপলোড লক করা আছে! শুধু লাইভ ক্যামেরা দিয়ে ছবি তুলুন।');
            }

            if ($hasImage) {
                $rawB64  = preg_replace('#^data:image/\w+;base64,#i', '', $_POST['base64_image']);
                $imgData = base64_decode($rawB64, true);
                if ($imgData === false) throw new Exception('ছবির ডাটা পড়া যায়নি!');
                if (strlen($imgData) > 6 * 1024 * 1024) throw new Exception('ছবির সাইজ ৬ MB-এর বেশি!');
                $mime    = (new finfo(FILEINFO_MIME_TYPE))->buffer($imgData);
                if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/gif'], true)) {
                    throw new Exception('ছবির ফরম্যাট অনুমোদিত নয় (JPG/PNG/WebP)!');
                }
                // ── ImageHelper: resize + ক্যাটাগরি ফোল্ডারে সেভ ──
                $uploadedImagePath = ImageHelper::save($imgData, $newProductCode, $productName);
            }

            // ৮) DB Transaction — INSERT
            $conn->beginTransaction();

            $insert = $conn->prepare(
                "INSERT INTO inventory
                    (product_code, category_id, name, image_path, pieces,
                     buy_price, cost, cash_sell, item_location, added_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $newProductCode, $categoryId, $productName, $uploadedImagePath,
                $pieces, $buyPrice, $finalCost, $cashSell, $itemLocation, $uid,
            ]);
            $newId = (int)$conn->lastInsertId();
            $conn->commit();

            // ৯) Location Audit log ── LocationAuditHelper
            LocationAuditHelper::log(
                $newId, $newProductCode, $productName,
                null,          // নতুন পণ্য, কোনো from_location নেই
                $itemLocation
            );

            // ১০) Inventory Audit log ── AuditLogger
            if (class_exists('AuditLogger') && $newId > 0) {
                AuditLogger::create('inventory', $newId, null, [
                    'product_code'  => $newProductCode,
                    'category_id'   => $categoryId,
                    'name'          => $productName,
                    'image_path'    => $uploadedImagePath,
                    'pieces'        => $pieces,
                    'buy_price'     => $buyPrice,
                    'cost'          => $finalCost,
                    'cash_sell'     => $cashSell,
                    'item_location' => $itemLocation,
                    'added_by'      => $uid,
                ], "পণ্য অ্যাড — {$newProductCode} ({$productName})");
            }

            // ১০.৫) Location Timeline log ── location_logs এ 'added'
            //       (পণ্যের জীবন এখান থেকেই শুরু — প্রথম ঘটনা)
            StorageLocationHelper::logEvent($conn, [
                'product_code' => $newProductCode,
                'event_type'   => 'added',
                'to_location'  => $itemLocation,
                'pieces'       => $pieces,
                'unit_price'   => $buyPrice,
                'note'         => 'নতুন পণ্য এড',
                'done_by'      => $uid,
                'done_by_name' => (string)($_SESSION['username'] ?? 'User'),
            ]);

            // ১১) Email নোটিফিকেশন ── EmailHelper
            EmailHelper::sendProductAddNotification([
                'product_code'  => $newProductCode,
                'category_name' => $productName,
                'pieces'        => $pieces,
                'buy_price'     => $buyPrice,
                'cost'          => $finalCost,
                'cash_sell'     => $cashSell,
                'item_location' => $itemLocation,
                'image_path'    => $uploadedImagePath,
                'added_by_user' => $userName,
            ]);

            echo json_encode([
                'status'       => 'success',
                'message'      => 'পণ্যটি সফলভাবে যুক্ত হয়েছে!',
                'product_code' => $newProductCode,
            ]);

        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            logSystemError($e);
            echo json_encode(['status'=>'error','message'=>'ডাটাবেস এরর!']);
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }
}

// ═════════════════════════════════════════════════════════
// Page Data
// ═════════════════════════════════════════════════════════
$categoryList = [];
try {
    $categoryList = $conn->query(
        "SELECT * FROM categories WHERE status='active' ORDER BY name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { logSystemError($e); }

$displayCode = 'SKF-01';
try {
    $lastCode = $conn->query(
        "SELECT product_code FROM inventory WHERE product_code LIKE 'SKF-%'
         ORDER BY CAST(SUBSTRING_INDEX(product_code,'-',-1) AS UNSIGNED) DESC LIMIT 1"
    )->fetchColumn();
    if ($lastCode) {
        $displayCode = 'SKF-' . str_pad(
            (string)((int)str_replace('SKF-', '', $lastCode) + 1), 2, '0', STR_PAD_LEFT
        );
    }
} catch (PDOException $e) { logSystemError($e); }
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>পণ্য এড — SADA KALO</title>
    <meta name="theme-color" content="#ffffff">
<link rel="icon" href="/logo.png" type="image/png">
<link rel="manifest" href="/manifest.json">
<?php require $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_assets.php'; ?>
    <script>(function(){try{var t=localStorage.getItem('sk-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);else if(window.matchMedia&&window.matchMedia('(prefers-color-scheme:dark)').matches)document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="theme.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <style>
        #cameraModal { display:none; position:fixed; inset:0; z-index:10001; background:rgba(0,0,0,.6); align-items:center; justify-content:center; padding:1rem; }
        #cameraModal.cam-open { display:flex; }
        #webcam { width:100%; max-height:320px; object-fit:cover; border-radius:.5rem; background:#000; }
        .calc-panel { background:var(--sk-surface-2); border:1px solid var(--sk-line); border-radius:.5rem; padding:.625rem .875rem; margin-bottom:.875rem; display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; justify-content:space-between; }
        .calc-item { font-size:.75rem; font-weight:600; color:var(--sk-muted); }
        .calc-item span { color:var(--sk-ink); margin-left:.25rem; font-weight:700; }
        #profit_display { font-size:.85rem; font-weight:800; }
        .profit-pos { color:var(--sk-success) !important; }
        .profit-neg { color:var(--sk-danger) !important; }
        .profit-zero { color:var(--sk-muted) !important; }
        .grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:.5rem; }
        .grid-2 { display:grid; grid-template-columns:repeat(2,1fr); gap:.5rem; }
        .sk-field { margin-bottom:.5rem; }
        .sk-input::placeholder { font-size:.78rem; font-weight:600; color:var(--sk-muted); }
        body.inventory-add-page .sk-appbar__right .sk-iconbtn:not(.sk-iconbtn--danger) { display:none !important; }
        .il-page-title { text-align:center; margin:.15rem 0 .9rem; }
        .il-page-title h1 { margin:0; font-size:1.5rem; font-weight:900; letter-spacing:-.02em; color:var(--sk-ink); }
        .il-page-title p  { margin:.3rem 0 0; font-size:.78rem; font-weight:600; color:var(--sk-muted); }
        .il-lock-row  { display:flex; align-items:center; gap:8px; margin-top:2px; }
        .il-lock-btn  { display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:999px; border:1px solid var(--sk-line); background:var(--sk-surface-2); color:var(--sk-ink-2); font-size:11px; font-weight:800; cursor:pointer; }
        .il-lock-btn.is-locked { border-color:var(--sk-danger); background:var(--sk-danger-soft); color:var(--sk-danger); }
        .il-lock-hint { font-size:.68rem; font-weight:600; color:var(--sk-muted); }
    </style>
</head>
<body class="inventory-add-page">
<?php include $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_shell.php'; ?>

<header class="sk-appbar">
    <div class="sk-appbar__left">
        <button type="button" class="sk-iconbtn" onclick="skToggleDrawer()"><i class="fas fa-bars"></i></button>
        <a href="inventory_dashboard.php" class="sk-iconbtn"><i class="fas fa-arrow-left"></i></a>
    </div>
    <div class="sk-appbar__title">পণ্য অ্যাড</div>
    <div class="sk-appbar__right">
        <a href="../logout.php" class="sk-iconbtn sk-iconbtn--danger"><i class="fas fa-power-off"></i></a>
    </div>
</header>

<div class="sk-overlay" id="skOverlay" onclick="skToggleDrawer()"></div>
<aside class="sk-drawer" id="skDrawer">
    <div class="sk-drawer__head">
        <button type="button" class="sk-drawer__close" onclick="skToggleDrawer()"><i class="fas fa-times"></i></button>
        <img src="logo.png" onerror="this.style.display='none'" class="sk-drawer__logo" alt="logo">
        <div class="sk-drawer__brand">SADA KALO</div>
        <div class="sk-drawer__sub">FASHION</div>
    </div>
    <div class="sk-drawer__section">Main</div>
    <div class="sk-drawer__grid">
        <a href="../dashboard.php"          class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-home"></i></div><div class="sk-drawer__label">হোম</div></a>
        <a href="inventory_dashboard.php"   class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-th-large"></i></div><div class="sk-drawer__label">ড্যাশবোর্ড</div></a>
        <a href="inventory.php"             class="sk-drawer__item active"><div class="sk-drawer__icon"><i class="fas fa-plus"></i></div><div class="sk-drawer__label">Add Item</div></a>
        <a href="Invantory_Items.php"        class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-box-open"></i></div><div class="sk-drawer__label">Item List</div></a>
        <a href="inventory_pos.php"         class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-shopping-cart"></i></div><div class="sk-drawer__label">POS</div></a>
        <a href="inventory_sales_history.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-receipt"></i></div><div class="sk-drawer__label">History</div></a>
        <a href="return_product.php"        class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-undo-alt"></i></div><div class="sk-drawer__label">Return</div></a>
        <a href="out_of_stock.php"          class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-exclamation-triangle"></i></div><div class="sk-drawer__label">Out Stock</div></a>
    </div>
    <?php if ($isAdmin): ?>
    <div class="sk-drawer__section">Admin</div>
    <div class="sk-drawer__grid">
        <a href="admin_inventory_control.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-cogs"></i></div><div class="sk-drawer__label">Inv Ctrl</div></a>
        <a href="admin_category_control.php"  class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-tags"></i></div><div class="sk-drawer__label">Category</div></a>
        <a href="admin_return_history.php"    class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-receipt"></i></div><div class="sk-drawer__label">Returns</div></a>
        <a href="daily_activity.php"          class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-clipboard-check"></i></div><div class="sk-drawer__label">Daily Act.</div></a>
        <a href="product_edit_history.php"    class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-pen-to-square"></i></div><div class="sk-drawer__label">Edits</div></a>
    </div>
    <?php endif; ?>
</aside>

<main class="sk-container" style="max-width:520px;">
    <div class="il-page-title">
        <h1>পণ্য অ্যাড পেজ</h1>
        <p>নতুন পণ্য যোগ করুন</p>
    </div>

    <div class="sk-card sk-card--pad-lg">
        <form id="productForm" autocomplete="off">
            <input type="hidden" name="ajax_action"  value="add_product">
            <input type="hidden" name="csrf_token"   id="csrf_token_field" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="product_code" id="product_code_input" value="<?= htmlspecialchars($displayCode, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="base64_image" id="base64_img">

            <!-- Serial Badge -->
            <div style="text-align:center; margin-bottom:1rem;">
                <span id="display_serial" class="sk-pill sk-pill--ink" style="font-size:.85rem; padding:.5rem 1rem; letter-spacing:.15em;">
                    <i class="fas fa-barcode"></i> &nbsp; সিরিয়াল: <?= htmlspecialchars($displayCode, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>

            <!-- Category + Scanner -->
            <div class="grid-2" style="margin-bottom:.625rem;">
                <div class="sk-field" style="margin-bottom:0;">
                    <div style="display:flex; gap:.375rem;">
                        <select name="category_id" id="categoryDropdown" class="sk-select" required>
                            <option value="" disabled selected>📦 ক্যাটাগরি *</option>
                            <?php foreach ($categoryList as $cat): ?>
                                <option value="<?= htmlspecialchars((string)$cat['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($isAdmin): ?>
                        <button type="button" onclick="addCat()" class="sk-btn sk-btn--ink sk-btn--sm" style="flex-shrink:0;" title="নতুন ক্যাটাগরি">
                            <i class="fas fa-plus"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="sk-field" style="margin-bottom:0;">
                    <button type="button" onclick="toggleScanner()" id="scanBtn" class="sk-btn sk-btn--accent sk-btn--block">
                        <i class="fas fa-qrcode"></i> স্ক্যান করুন
                    </button>
                </div>
            </div>

            <!-- Scanner Area -->
            <div id="inlineScannerArea" class="hidden" style="margin-bottom:.875rem; position:relative;">
                <div id="reader" class="sk-scanner" style="min-height:200px;"></div>
                <button type="button" onclick="stopScanner()" class="sk-iconbtn sk-iconbtn--danger" style="position:absolute; top:-10px; right:-10px; z-index:10;"><i class="fas fa-times"></i></button>
                <p style="text-align:center; font-size:.75rem; font-weight:600; color:var(--sk-muted); margin-top:.5rem;">ক্যামেরার সামনে QR/Barcode ধরুন</p>
            </div>

            <!-- Pieces + Buy Price + Cost -->
            <div class="grid-3">
                <div class="sk-field">
                    <input type="number" name="pieces" id="pieces_p" class="sk-input" required min="1" placeholder="পিস">
                </div>
                <div class="sk-field">
                    <input type="number" step="0.01" name="buy_price" id="buy_p" class="sk-input" required min="0" placeholder="ক্রয় ৳" oninput="calcPrices()">
                </div>
                <div class="sk-field">
                    <input type="number" step="0.01" name="cost" id="cost_p" value="15" class="sk-input"
                           placeholder="<?= !$isAdmin ? 'Cost 🔒' : 'Cost ৳' ?>"
                           style="<?= !$isAdmin ? 'color:var(--sk-danger);background:var(--sk-danger-soft);' : '' ?>"
                           <?= !$isAdmin ? 'readonly' : '' ?> oninput="calcPrices()">
                </div>
            </div>

            <!-- Cash Sell + Image -->
            <div class="grid-2">
                <div class="sk-field">
                    <input type="number" step="0.01" name="cash_sell" id="cash_sell_p" class="sk-input" required min="0" placeholder="সেল ৳" style="border-color:var(--sk-primary);" oninput="calcPrices()">
                    <small id="min_price_hint" style="font-size:.65rem; font-weight:600; color:var(--sk-muted); display:block; margin-top:.25rem;"></small>
                </div>
                <div class="sk-field">
                    <div style="display:flex; flex-direction:column; gap:.375rem;">
                        <button type="button" id="camBtn" onclick="openCam()" class="sk-btn sk-btn--info sk-btn--block">
                            <i class="fas fa-camera"></i> লাইভ ছবি
                        </button>
                        <button type="button" id="uploadBtn" onclick="document.getElementById('fileUpload').click()" class="sk-btn sk-btn--ghost sk-btn--block">
                            <i class="fas fa-image"></i> গ্যালারি আপলোড
                        </button>
                        <input type="file" id="fileUpload" accept="image/*" style="display:none;" onchange="onFilePicked(this)">
                        <input type="hidden" name="image_source" id="image_source" value="">
                        <?php if ($isAdmin): ?>
                        <div class="il-lock-row">
                            <button type="button" id="lockToggleBtn" class="il-lock-btn" onclick="toggleUploadLock(); return false;">
                                <i class="fas fa-lock-open" id="lockToggleIcon"></i>
                                <span id="lockBtnText">আনলক</span>
                            </button>
                            <span id="lockHint" class="il-lock-hint"></span>
                        </div>
                        <?php else: ?>
                        <div id="lockHint" class="il-lock-hint"></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── দোকান / গোডাউন সিলেক্টর ── StorageLocationHelper -->
            <?= StorageLocationHelper::renderField($conn) ?>

            <!-- Profit Calculator -->
            <div class="calc-panel" id="calcPanel" style="display:none;">
                <div class="calc-item">ক্রয়: <span id="cp_buy">৳০</span></div>
                <div class="calc-item">Cost: <span id="cp_cost">৳০</span></div>
                <div class="calc-item">বিক্রি: <span id="cp_sell">৳০</span></div>
                <div class="calc-item">লাভ/ক্ষতি: <span id="profit_display" class="profit-zero">—</span></div>
            </div>

            <!-- Image Preview -->
            <div style="display:flex; justify-content:center; margin:.875rem 0;">
                <div style="position:relative; display:none;" id="previewBox">
                    <img id="preview" src="" style="width:120px; height:120px; object-fit:cover; border-radius:.75rem; border:3px solid var(--sk-primary); box-shadow:var(--sk-shadow);">
                    <button type="button" onclick="removeImage()" style="position:absolute; top:-8px; right:-8px; width:26px; height:26px; border-radius:50%; background:var(--sk-danger); color:#fff; border:0; cursor:pointer; font-size:.75rem; display:flex; align-items:center; justify-content:center;"><i class="fas fa-times"></i></button>
                </div>
            </div>

            <!-- Save Button -->
            <button type="submit" id="saveBtn" class="sk-btn sk-btn--success sk-btn--block sk-btn--lg" style="margin-top:.5rem;">
                <i class="fas fa-save"></i> সেভ করুন
            </button>
        </form>
    </div>

    <p style="text-align:center; margin-top:.875rem; font-size:.7rem; font-weight:600; color:var(--sk-muted); letter-spacing:.12em;">&copy; SADA KALO FASHION</p>
</main>

<!-- Camera Modal -->
<div id="cameraModal">
    <div class="sk-modal__sheet" style="max-width:400px;">
        <div class="sk-modal__head">
            <div class="sk-modal__title"><i class="fas fa-camera"></i> ছবি ক্যাপচার</div>
            <button type="button" onclick="closeCam()" class="sk-modal__close"><i class="fas fa-times"></i></button>
        </div>
        <div id="camLoadingMsg" style="display:block; text-align:center; padding:1.25rem;">
            <i class="fas fa-spinner fa-spin" style="font-size:1.5rem; color:var(--sk-primary);"></i>
            <p style="margin-top:.5rem;">ক্যামেরা চালু হচ্ছে...</p>
        </div>
        <video id="webcam" autoplay playsinline muted style="display:none; width:100%; max-height:320px; object-fit:cover; border-radius:.5rem; margin-bottom:.625rem;"></video>
        <canvas id="canvas" style="display:none;"></canvas>
        <div style="display:flex; gap:.5rem; margin-top:.5rem;">
            <button type="button" id="snapBtn" onclick="takeSnapshot()" class="sk-btn sk-btn--success sk-grow" disabled style="opacity:.5;"><i class="fas fa-camera"></i> ক্লিক</button>
            <button type="button" onclick="closeCam()" class="sk-btn sk-btn--ghost sk-grow">বাতিল</button>
        </div>
    </div>
</div>

<script>
var isAdminUser = <?= $isAdmin ? 'true' : 'false' ?>;
var uploadLocked = false;

// ── Drawer ─────────────────────────────────────────────
function skToggleDrawer() {
    document.getElementById('skDrawer').classList.toggle('open');
    document.getElementById('skOverlay').classList.toggle('active');
}

// ── Upload Lock ─────────────────────────────────────────
function applyLockUI() {
    var upBtn   = document.getElementById('uploadBtn');
    var hint    = document.getElementById('lockHint');
    var icon    = document.getElementById('lockToggleIcon');
    var btn     = document.getElementById('lockToggleBtn');
    var btnText = document.getElementById('lockBtnText');
    if (upBtn)   { upBtn.style.display = uploadLocked ? 'none' : ''; }
    if (hint)    { hint.innerHTML = uploadLocked ? '<span style="color:var(--sk-danger)">শুধু লাইভ ক্যামেরা</span>' : '<span style="color:var(--sk-success)">গ্যালারি + ক্যামেরা</span>'; }
    if (icon)    { icon.className = uploadLocked ? 'fas fa-lock' : 'fas fa-lock-open'; }
    if (btnText) { btnText.textContent = uploadLocked ? 'লক' : 'আনলক'; }
    if (btn)     { uploadLocked ? btn.classList.add('is-locked') : btn.classList.remove('is-locked'); }
}
function refreshUploadLock() {
    $.post('inventory.php', { ajax_action:'get_upload_lock' }, function(res) {
        if (res && res.status === 'success') { uploadLocked = !!res.upload_locked; applyLockUI(); }
    }, 'json');
}
var _lockBusy = false;
function toggleUploadLock() {
    if (!isAdminUser || _lockBusy) return;
    _lockBusy = true;
    $.ajax({
        url:'inventory.php', type:'POST', dataType:'json',
        data:{ ajax_action:'toggle_upload_lock', csrf_token:$('#csrf_token_field').val() },
        success: function(res) {
            if (res && res.status === 'success') { uploadLocked = !!res.upload_locked; applyLockUI(); if (res.message) alert(res.message); }
            else { alert((res && res.message) ? res.message : 'লক পরিবর্তন ব্যর্থ'); refreshUploadLock(); }
        },
        error: function() { alert('সার্ভার এরর'); refreshUploadLock(); },
        complete: function() { _lockBusy = false; }
    });
}

// ── Image ───────────────────────────────────────────────
function setPreviewFromDataUrl(dataUrl, source) {
    document.getElementById('base64_img').value = dataUrl;
    var src = document.getElementById('image_source');
    if (src) src.value = source || 'camera';
    document.getElementById('preview').src = dataUrl;
    document.getElementById('previewBox').style.display = 'block';
}
function onFilePicked(input) {
    if (uploadLocked) { alert('আপলোড লক করা আছে! শুধু লাইভ ক্যামেরা ব্যবহার করুন।'); input.value=''; return; }
    var file = input.files && input.files[0];
    if (!file) return;
    if (!file.type || file.type.indexOf('image/') !== 0) { alert('শুধু ছবি ফাইল নির্বাচন করুন!'); input.value=''; return; }
    if (file.size > 6*1024*1024) { alert('ছবি ৬ MB-এর কম হতে হবে!'); input.value=''; return; }
    var reader = new FileReader();
    reader.onload = function(e) { setPreviewFromDataUrl(String(e.target.result||''), 'upload'); };
    reader.readAsDataURL(file);
}
function removeImage() {
    document.getElementById('base64_img').value = '';
    document.getElementById('preview').src = '';
    document.getElementById('previewBox').style.display = 'none';
    var src = document.getElementById('image_source'); if (src) src.value='';
    var fu  = document.getElementById('fileUpload');   if (fu)  fu.value='';
}

// ── Camera ──────────────────────────────────────────────
var camStream=null, camReady=false;
function openCam() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { alert('ক্যামেরা সাপোর্ট নেই।'); return; }
    var modal=document.getElementById('cameraModal'), video=document.getElementById('webcam'),
        loading=document.getElementById('camLoadingMsg'), snap=document.getElementById('snapBtn');
    modal.classList.add('cam-open'); video.style.display='none'; loading.style.display='block';
    snap.disabled=true; snap.style.opacity='0.5'; camReady=false; _stopCamStream();
    navigator.mediaDevices.getUserMedia({ video:{ facingMode:{ideal:'environment'}, width:{ideal:1280}, height:{ideal:720} }, audio:false })
    .then(function(stream) {
        camStream=stream; video.srcObject=stream;
        video.onloadedmetadata=function() {
            video.play().then(function() { loading.style.display='none'; video.style.display='block'; camReady=true; snap.disabled=false; snap.style.opacity='1'; });
        };
    }).catch(function(err) {
        var msg='ক্যামেরা অ্যাকসেস করা যায়নি।';
        if (err.name==='NotAllowedError') msg='ক্যামেরার অনুমতি দেওয়া হয়নি!';
        if (err.name==='NotFoundError')   msg='কোনো ক্যামেরা পাওয়া যায়নি।';
        if (err.name==='NotReadableError')msg='ক্যামেরা অন্য অ্যাপ ব্যবহার করছে।';
        _camError(msg);
    });
}
function _camError(msg){ document.getElementById('cameraModal').classList.remove('cam-open'); _stopCamStream(); alert(msg); }
function _stopCamStream(){ if(camStream){camStream.getTracks().forEach(function(t){t.stop();}); camStream=null;} camReady=false; var v=document.getElementById('webcam'); if(v){v.pause(); v.srcObject=null;} }
function closeCam(){ document.getElementById('cameraModal').classList.remove('cam-open'); document.getElementById('webcam').style.display='none'; document.getElementById('camLoadingMsg').style.display='none'; _stopCamStream(); }
function takeSnapshot() {
    if (!camReady) { alert('ক্যামেরা এখনো প্রস্তুত নয়।'); return; }
    var video=document.getElementById('webcam'), canvas=document.getElementById('canvas');
    if (!video.videoWidth) { alert('ক্যামেরা এখনো তৈরি হয়নি।'); return; }
    canvas.width=video.videoWidth; canvas.height=video.videoHeight;
    canvas.getContext('2d').drawImage(video,0,0);
    setPreviewFromDataUrl(canvas.toDataURL('image/jpeg',0.85),'camera');
    closeCam();
}

// ── QR Scanner ─────────────────────────────────────────
var qrScanner=null, qrScannerRunning=false;
function toggleScanner(){ qrScannerRunning ? stopScanner() : startScanner(); }
function startScanner() {
    var area=document.getElementById('inlineScannerArea'); area.classList.remove('hidden');
    if (qrScanner){ try{qrScanner.clear();}catch(e){} qrScanner=null; }
    document.getElementById('reader').innerHTML='';
    qrScanner=new Html5Qrcode('reader');
    qrScanner.start({facingMode:'environment'},{fps:10,qrbox:{width:200,height:200},aspectRatio:1.0},
        function(decodedText){
            $.post('inventory.php',{ajax_action:'check_duplicate',product_code:decodedText},function(res){
                if(res.status==='exists'){alert('⚠️ এই বারকোডটি ইতিমধ্যে ব্যবহৃত!');}
                else{
                    document.getElementById('display_serial').innerHTML='<i class="fas fa-check-circle" style="color:var(--sk-success)"></i> স্ক্যানড: '+decodedText;
                    document.getElementById('product_code_input').value=decodedText;
                    stopScanner();
                }
            },'json').fail(function(){ document.getElementById('product_code_input').value=decodedText; stopScanner(); });
        }, function(){}
    ).then(function(){
        qrScannerRunning=true;
        document.getElementById('scanBtn').innerHTML='<i class="fas fa-stop-circle"></i> বন্ধ করুন';
        document.getElementById('scanBtn').classList.replace('sk-btn--accent','sk-btn--danger');
    }).catch(function(err){
        area.classList.add('hidden'); qrScannerRunning=false; qrScanner=null;
        alert('স্ক্যানার চালু করা যায়নি।'+(err&&err.name==='NotAllowedError'?' (অনুমতি দেওয়া হয়নি)':''));
    });
}
function stopScanner(){
    if(qrScanner&&qrScannerRunning){ qrScanner.stop().then(_cleanupScanner).catch(_cleanupScanner); }
    else{ _cleanupScanner(); }
}
function _cleanupScanner(){
    qrScannerRunning=false;
    if(qrScanner){try{qrScanner.clear();}catch(e){} qrScanner=null;}
    document.getElementById('inlineScannerArea').classList.add('hidden');
    var btn=document.getElementById('scanBtn');
    btn.innerHTML='<i class="fas fa-qrcode"></i> স্ক্যান করুন';
    btn.classList.replace('sk-btn--danger','sk-btn--accent');
}

// ── Add Category ────────────────────────────────────────
function addCat() {
    var cat = prompt('নতুন ক্যাটাগরির নাম:');
    if (!cat || cat.trim()==='') return;
    $.post('inventory.php',{ajax_action:'add_category',csrf_token:$('#csrf_token_field').val(),category_name:cat.trim()},function(res){
        if (res.status==='success'){
            $('#categoryDropdown').append(new Option(res.name,res.id,true,true));
        } else { alert(res.message); }
    },'json').fail(function(){ alert('সার্ভার সমস্যা।'); });
}

// ── Profit Calc ─────────────────────────────────────────
function calcPrices() {
    var buy=parseFloat(document.getElementById('buy_p').value)||0;
    var cost=parseFloat(document.getElementById('cost_p').value)||0;
    var sell=parseFloat(document.getElementById('cash_sell_p').value)||0;
    var panel=document.getElementById('calcPanel');
    if (buy>0||sell>0) {
        panel.style.display='flex';
        document.getElementById('cp_buy').textContent='৳'+buy.toFixed(2);
        document.getElementById('cp_cost').textContent='৳'+cost.toFixed(2);
        document.getElementById('cp_sell').textContent='৳'+sell.toFixed(2);
        var profit=sell-(buy+cost);
        var el=document.getElementById('profit_display');
        if (sell===0){ el.textContent='—'; el.className='profit-zero'; }
        else if (profit>0){ el.textContent='+৳'+profit.toFixed(2)+' লাভ'; el.className='profit-pos'; }
        else if (profit===0){ el.textContent='৳০ (সমান)'; el.className='profit-zero'; }
        else { el.textContent='৳'+profit.toFixed(2)+' ক্ষতি'; el.className='profit-neg'; }
    } else { panel.style.display='none'; }
    document.getElementById('min_price_hint').textContent = buy>0 ? 'সর্বনিম্ন বিক্রি মূল্য: ৳'+(buy+cost).toFixed(2) : '';
    <?php if (!$isAdmin): ?>
    var ci=document.getElementById('cash_sell_p');
    if (sell>0&&sell<buy+cost){ ci.style.borderColor='var(--sk-danger)'; ci.style.background='var(--sk-danger-soft)'; }
    else { ci.style.borderColor=''; ci.style.background=''; }
    <?php endif; ?>
}

// ── Form Submit ─────────────────────────────────────────
$('#productForm').on('submit', function(e) {
    e.preventDefault();

    // Category check
    if (!$('#categoryDropdown').val()) { alert('ক্যাটাগরি সিলেক্ট করুন!'); return; }

    // ── StorageLocationHelper: দোকান/গোডাউন চেক ──
    if (!slValidate()) return;

    var btn=$('#saveBtn'), orig=btn.html();
    btn.prop('disabled',true).html('<i class="fas fa-spinner fa-spin"></i> প্রসেসিং...');
    if (qrScannerRunning) stopScanner();
    closeCam();

    $.ajax({
        url:'inventory.php', type:'POST',
        data:new FormData(this), contentType:false, processData:false, dataType:'json',
        success: function(res) { alert(res.message); if (res.status==='success') location.reload(); },
        error:   function() { alert('সার্ভার সমস্যা।'); },
        complete:function() { btn.prop('disabled',false).html(orig); }
    });
});

$(function(){ refreshUploadLock(); calcPrices(); });
</script>

<style>body{padding-bottom:76px;}</style>
<?php include 'inventory_bottom_nav.php'; ?>
</body>
</html>
