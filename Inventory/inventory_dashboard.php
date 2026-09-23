<?php
declare(strict_types=1);

session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// ─── System Error Logger ────────────────────────────────────────────────────
function logSystemError(Throwable $e): void {
    $logDir = __DIR__ . '/../Logs';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    $timestamp = date('Y-m-d H:i:s');
    $msg = "[{$timestamp}] Error: " . $e->getMessage()
         . " | File: " . $e->getFile()
         . " | Line: " . $e->getLine() . PHP_EOL;
    @file_put_contents($logDir . '/error_log.txt', $msg, FILE_APPEND);
}

$isAjax = isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'load_dashboard_data';

// ─── Session Timeout (20 min) ───────────────────────────────────────────────
$lastActivity = $_SESSION['last_activity'] ?? null;
if ($lastActivity !== null && is_int($lastActivity) && (time() - $lastActivity > 1200)) {
    session_unset(); session_destroy();
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'session_expired']); exit;
    }
    header("Location: ../index.php"); exit;
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'session_expired']); exit;
    }
    header("Location: ../index.php"); exit;
}

// ─── CSRF Token ─────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrfToken = is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : '';

// ─── DB Connection ───────────────────────────────────────────────────────────
$db_path = '../db_connect.php';
if (file_exists($db_path)) include $db_path;
/** @var PDO $conn */

$role = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : 'user';
$uid  = isset($_SESSION['user_id']) && is_scalar($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
date_default_timezone_set('Asia/Dhaka');

// ════════════════════════════════════════════════════════════════════════════
// AJAX HANDLER
// ════════════════════════════════════════════════════════════════════════════
if ($isAjax) {
    ob_clean();
    header('Content-Type: application/json');

    // CSRF validation
    $postCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if ($postCsrf === '' || !hash_equals($csrfToken, $postCsrf)) {
        echo json_encode(['error' => 'Security token mismatch!']); exit;
    }

    $data = [];

    // ── Pending Returns (admin only) ────────────────────────────────────────
    $data['pendingReturns'] = 0;
    if ($role === 'admin') {
        try {
            $stmt = $conn->query("SELECT COUNT(*) FROM inventory_returns WHERE status = 'pending'");
            $val  = $stmt !== false ? $stmt->fetchColumn() : 0;
            $data['pendingReturns'] = is_numeric($val) ? (int)$val : 0;
        } catch (Throwable $e) { logSystemError($e); }
    }

    // ── Admin Stats (admin only) ─────────────────────────────────────────────
    if ($role === 'admin') {

        // Current live stock
        $curStock = ['pcs' => 0, 'val' => 0];
        try {
            $stmt = $conn->query(
                "SELECT SUM(pieces) as pcs, SUM(pieces * (buy_price + cost)) as val
                   FROM inventory"
            );
            if ($stmt !== false) {
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($res)) $curStock = $res;
            }
        } catch (Throwable $e) { logSystemError($e); }

        $curPcs = isset($curStock['pcs']) && is_numeric($curStock['pcs']) ? (int)$curStock['pcs']   : 0;
        $curVal = isset($curStock['val']) && is_numeric($curStock['val']) ? (float)$curStock['val'] : 0.0;

        // Ever sold (for all-time total)
        $everSold = ['pcs' => 0, 'val' => 0];
        try {
            $stmt = $conn->query(
                "SELECT SUM(pieces) as pcs, SUM(pieces * (buy_price + cost)) as val
                   FROM inventory_sale_items"
            );
            if ($stmt !== false) {
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($res)) $everSold = $res;
            }
        } catch (Throwable $e) { logSystemError($e); }

        $everAddedPcs = $curPcs + (isset($everSold['pcs']) && is_numeric($everSold['pcs']) ? (int)$everSold['pcs']     : 0);
        $everAddedVal = $curVal + (isset($everSold['val']) && is_numeric($everSold['val']) ? (float)$everSold['val']   : 0.0);

        // Today added (new items + stock adjustments)
        $todayAdded = ['pcs' => 0, 'val' => 0];
        $adjInc     = ['pcs' => 0, 'val' => 0];
        try {
            $stmt1 = $conn->query(
                "SELECT SUM(pieces) as pcs, SUM(pieces * (buy_price + cost)) as val
                   FROM inventory
                  WHERE DATE(created_at) = CURRENT_DATE"
            );
            if ($stmt1 !== false) {
                $res1 = $stmt1->fetch(PDO::FETCH_ASSOC);
                if (is_array($res1)) $todayAdded = $res1;
            }
            $stmt2 = $conn->query(
                "SELECT SUM(a.pieces) as pcs, SUM(a.pieces * (i.buy_price + i.cost)) as val
                   FROM inventory_adjustments a
                   JOIN inventory i ON a.product_code = i.product_code
                  WHERE DATE(a.created_at) = CURRENT_DATE
                    AND a.adjustment_type = 'increase'"
            );
            if ($stmt2 !== false) {
                $res2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                if (is_array($res2)) $adjInc = $res2;
            }
        } catch (Throwable $e) { logSystemError($e); }

        $todayPcs = (isset($todayAdded['pcs']) && is_numeric($todayAdded['pcs']) ? (int)$todayAdded['pcs']     : 0)
                  + (isset($adjInc['pcs'])     && is_numeric($adjInc['pcs'])     ? (int)$adjInc['pcs']         : 0);
        $todayVal = (isset($todayAdded['val']) && is_numeric($todayAdded['val']) ? (float)$todayAdded['val']   : 0.0)
                  + (isset($adjInc['val'])     && is_numeric($adjInc['val'])     ? (float)$adjInc['val']       : 0.0);

        $data['adminStats'] = [
            'everAddedPcs'  => number_format($everAddedPcs),
            'everAddedVal'  => '৳ ' . number_format($everAddedVal, 2),
            'curPcs'        => number_format($curPcs),
            'curVal'        => '৳ ' . number_format($curVal, 2),
            'todayAddedPcs' => number_format($todayPcs),
            'todayAddedVal' => '৳ ' . number_format($todayVal, 2),
        ];
    }

    echo json_encode($data); exit;
}
// ════════════════════════════════════════════════════════════════════════════
// END AJAX — HTML page render below
// ════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

    <title>ইনভেন্টরি হাব — SADA KALO</title>
    <meta name="theme-color" content="#ffffff">
<?php require $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_assets.php'; ?>

    <script>(function(){try{var t=localStorage.getItem('sk-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);else if(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Hind+Siliguri:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="theme.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script defer src="theme-toggle.js"></script>

    <style>
        /* ═══ Brand Color Override ══════════════════════════════════════════ */
        :root {
            --sk-brand:          #065f46;
            --sk-brand-2:        #047857;
            --sk-brand-ink:      #059669;
            --sk-grad-brand:     linear-gradient(135deg, #000000, #065f46);
            --sk-shadow-brand:   0 8px 24px rgba(6,95,70,.35);
            --sk-appbar-height:  56px;
        }
        [data-theme="dark"] {
            --sk-brand:          #22c55e;
            --sk-brand-2:        #4ade80;
            --sk-brand-ink:      #86efac;
            --sk-grad-brand:     linear-gradient(135deg, #16a34a, #22c55e);
            --sk-shadow-brand:   0 8px 24px rgba(34,197,94,.4);
        }

        /* ═══ App bar — sticky at top ════════════════════════════════════════ */
        header.sk-appbar {
            position: -webkit-sticky !important;
            position: sticky !important;
            top: 0 !important;
            left: 0; right: 0;
            z-index: 900 !important;
            background: var(--sk-surface) !important;
            box-shadow: var(--sk-shadow-sm);
        }

        /* ═══ Sticky block — Banner + Quick Menu ═════════════════════════════ */
        .sk-sticky-header-block {
            position: -webkit-sticky;
            position: sticky;
            top: var(--sk-appbar-height, 56px);
            z-index: 800;
            margin-left: -16px;
            margin-right: -16px;
            padding: 0 16px 6px;
            background: var(--sk-bg, #f4f4f5);
            box-shadow: 0 4px 16px rgba(0,0,0,.10);
        }
        [data-theme="dark"] .sk-sticky-header-block {
            background: var(--sk-bg, #09090b);
            box-shadow: 0 4px 16px rgba(0,0,0,.40);
        }

        /* ═══ Quick menu chips ══════════════════════════════════════════════ */
        .qchip-row { display:flex; gap:6px; overflow-x:auto; padding:4px 0 8px; }
        .qchip-row::-webkit-scrollbar { display:none; }
        .qchip {
            flex-shrink:0; display:flex; flex-direction:row; align-items:center;
            gap:6px; padding:7px 12px; background:var(--sk-surface);
            border:1px solid var(--sk-line); border-radius:10px;
            text-decoration:none; color:var(--sk-ink);
            transition:.18s; box-shadow:var(--sk-shadow-sm);
        }
        .qchip:hover { border-color:var(--sk-brand); box-shadow:var(--sk-shadow); }
        .qchip__ic {
            width:22px; height:22px; border-radius:6px;
            display:flex; align-items:center; justify-content:center;
            background:var(--sk-surface-3); color:var(--sk-brand);
            font-size:11px; flex-shrink:0;
        }
        .qchip__label { font-size:11px; font-weight:800; letter-spacing:.2px; white-space:nowrap; }
        .qchip--pos   { background:var(--sk-grad-ink); color:#fff; border-color:transparent; }
        .qchip--pos   .qchip__ic { background:var(--sk-brand); color:#fff; }
        .qchip--brand { background:var(--sk-grad-brand); color:#fff; border-color:transparent; }
        .qchip--brand .qchip__ic { background:rgba(255,255,255,.18); color:#fff; }

        /* ═══ Speed-dial FAB ════════════════════════════════════════════════ */
        .skd-fab-container { position:fixed; right:18px; bottom:96px; z-index:950; }
        .skd-fab-menu { position:absolute; right:8px; bottom:8px; width:0; height:0; pointer-events:none; }
        .skd-fab-item {
            position:absolute; width:48px; height:48px; border-radius:16px;
            color:#fff; display:flex; align-items:center; justify-content:center;
            font-size:17px; text-decoration:none; box-shadow:var(--sk-shadow);
            opacity:0; right:0; bottom:0; transform:scale(.4); pointer-events:none;
            transition:all .35s cubic-bezier(.4,0,.2,1);
            background:var(--sk-grad-ink); border:1px solid rgba(255,255,255,.12);
        }
        .skd-fab-item:nth-child(3) { background:var(--sk-grad-brand); }
        .skd-fab-menu.active .skd-fab-item { opacity:1; pointer-events:auto; }
        .skd-fab-menu.active .skd-fab-item:nth-child(1) { transform:translate(0,-72px) scale(1); transition-delay:.00s; }
        .skd-fab-menu.active .skd-fab-item:nth-child(2) { transform:translate(-52px,-52px) scale(1); transition-delay:.04s; }
        .skd-fab-menu.active .skd-fab-item:nth-child(3) { transform:translate(-72px,0) scale(1); transition-delay:.08s; }
        .skd-fab-menu.active .skd-fab-item:nth-child(4) { transform:translate(-52px,52px) scale(1); transition-delay:.12s; }
        .skd-fab-menu.active .skd-fab-item:nth-child(5) { transform:translate(0,72px) scale(1); transition-delay:.16s; }
        .skd-main-fab {
            width:62px; height:62px; border-radius:22px;
            background:var(--sk-grad-brand); color:#fff;
            display:flex; align-items:center; justify-content:center;
            font-size:22px; border:4px solid var(--sk-surface);
            cursor:pointer; box-shadow:var(--sk-shadow-brand);
            transition:.35s; position:relative; z-index:10;
        }
        .skd-main-fab.active { transform:rotate(135deg); background:var(--sk-grad-ink); box-shadow:var(--sk-shadow-ink); }

        /* ═══ Image Lightbox ════════════════════════════════════════════════ */
        #imageLightbox {
            display:none; position:fixed; z-index:100000; inset:0;
            background:rgba(9,9,11,.95);
            align-items:center; justify-content:center; flex-direction:column;
            backdrop-filter:blur(8px);
        }
        #lightboxImg {
            max-width:90%; max-height:78vh; border-radius:18px;
            border:3px solid #fff; object-fit:contain; box-shadow:var(--sk-shadow-lg);
        }
        .close-lightbox {
            position:absolute; top:18px; right:24px;
            color:#fff; font-size:36px; font-weight:900; cursor:pointer;
        }

        @keyframes pulse-brand {
            0%   { box-shadow:0 0 0 0    rgba(225,29,72,.4); }
            70%  { box-shadow:0 0 0 14px rgba(225,29,72,0);  }
            100% { box-shadow:0 0 0 0    rgba(225,29,72,0);  }
        }

        /* ═══ Notification bell ═════════════════════════════════════════════ */
        .nf-bell { position:relative; }
        .nf-bell.ringing i { transform-origin:50% 0; animation:nf-ring .9s cubic-bezier(.36,.07,.19,.97) both; }
        @keyframes nf-ring {
            0%,100% { transform:rotate(0);      } 10% { transform:rotate(22deg);  }
            20%     { transform:rotate(-18deg); } 30% { transform:rotate(16deg);  }
            40%     { transform:rotate(-12deg); } 50% { transform:rotate(8deg);   }
            60%     { transform:rotate(-5deg);  } 70% { transform:rotate(3deg);   }
        }
        .nf-bell__badge {
            position:absolute; top:-4px; right:-4px;
            min-width:18px; height:18px; padding:0 5px;
            background:var(--sk-brand); color:#fff;
            font-size:10px; font-weight:900; line-height:18px; text-align:center;
            border-radius:999px; border:2px solid var(--sk-surface);
            display:none; animation:nf-badge-pulse 1.8s infinite;
        }
        .nf-bell__badge.show { display:block; }
        @keyframes nf-badge-pulse {
            0%   { box-shadow:0 0 0 0   rgba(220,53,69,.5); }
            70%  { box-shadow:0 0 0 9px rgba(220,53,69,0);  }
            100% { box-shadow:0 0 0 0   rgba(220,53,69,0);  }
        }

        /* ═══ Global layout ════════════════════════════════════════════════ */
        html, body { overflow-x:hidden; }
        body { overflow-y:visible; padding-bottom:76px; }
    </style>
</head>
<body>

<!-- ── App Bar ──────────────────────────────────────────────────────────── -->
<header class="sk-appbar">
    <div class="sk-appbar__left">
        <button class="sk-iconbtn" onclick="toggleSidebar()" aria-label="Menu">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    <div class="sk-appbar__title"><span class="dot"></span> ইনভেন্টরি হাব</div>
    <div class="sk-appbar__right" style="display:flex; gap:8px; align-items:center;">
        <a href="notification_dashboard.php" class="sk-iconbtn nf-bell" id="notifBell" aria-label="Notifications">
            <i class="fas fa-bell"></i>
            <span class="nf-bell__badge" id="notifBellBadge">0</span>
        </a>
        <a href="../logout.php"
           onclick="localStorage.removeItem('sk-cache');"
           class="sk-iconbtn sk-iconbtn--danger"
           aria-label="Logout">
            <i class="fas fa-power-off"></i>
        </a>
    </div>
</header>

<!-- ── Drawer ───────────────────────────────────────────────────────────── -->
<div class="sk-overlay" id="myOverlay" onclick="toggleSidebar()"></div>
<aside class="sk-drawer" id="mySidebar">
    <div class="sk-drawer__head">
        <button class="sk-drawer__close" onclick="toggleSidebar()"><i class="fas fa-times"></i></button>
        <img src="logo.png" alt="Logo" onerror="this.style.display='none'" class="sk-drawer__logo">
        <div class="sk-drawer__brand">SADA KALO</div>
        <div class="sk-drawer__sub">FASHION</div>
    </div>
    <div class="sk-drawer__section">Quick Menu</div>
    <div class="sk-drawer__grid">
        <a href="../dashboard.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-home"></i></div>
            <span class="sk-drawer__label">হোমপেজ</span>
        </a>
        <a href="inventory_dashboard.php" class="sk-drawer__item active">
            <div class="sk-drawer__icon"><i class="fas fa-th-large"></i></div>
            <span class="sk-drawer__label">ড্যাশবোর্ড</span>
        </a>
        <a href="inventory.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-plus"></i></div>
            <span class="sk-drawer__label">Add Item</span>
        </a>
        <a href="Invantory_Items.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-box-open"></i></div>
            <span class="sk-drawer__label">Item List</span>
        </a>
        <a href="inventory_pos.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-shopping-cart"></i></div>
            <span class="sk-drawer__label">POS Sell</span>
        </a>
        <a href="inventory_sales_history.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-receipt"></i></div>
            <span class="sk-drawer__label">History</span>
        </a>
        <a href="return_product.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-undo-alt"></i></div>
            <span class="sk-drawer__label">Return</span>
        </a>
        <a href="out_of_stock.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-exclamation-triangle"></i></div>
            <span class="sk-drawer__label">Out Stock</span>
        </a>
        <a href="category_mange.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-folder-tree"></i></div>
            <span class="sk-drawer__label">ক্যাটাগরি</span>
        </a>
        <?php if ($role === 'admin'): ?>
        <a href="admin_inventory_control.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-cogs"></i></div>
            <span class="sk-drawer__label">Admin</span>
        </a>
        <a href="admin_category_control.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-tags"></i></div>
            <span class="sk-drawer__label">Category Ctrl</span>
        </a>
        <a href="daily_activity.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-clipboard-check"></i></div>
            <span class="sk-drawer__label">Daily Act.</span>
        </a>
        <a href="product_edit_history.php" class="sk-drawer__item">
            <div class="sk-drawer__icon"><i class="fas fa-pen-to-square"></i></div>
            <span class="sk-drawer__label">Edit Log</span>
        </a>
        <?php endif; ?>
    </div>
</aside>

<!-- ═══════════════════════════════════════════════════════════════════════ -->
<main class="sk-container">

    <!-- Admin alert (non-sticky, scrolls away) -->
    <?php if ($role === 'admin'): ?>
    <div id="adminAlertBox" class="sk-card sk-card--accent"
         style="display:none; margin-bottom:14px; animation:pulse-brand 2s infinite;">
        <div class="sk-row sk-row--between" style="gap:12px;">
            <div class="sk-row" style="gap:12px;">
                <span class="sk-stat__icon" style="background:var(--sk-grad-brand);">
                    <i class="fas fa-bell"></i>
                </span>
                <div>
                    <div style="font-size:10px; font-weight:900; letter-spacing:1.5px;
                                text-transform:uppercase; color:var(--sk-brand-2);">
                        অ্যাডমিন অ্যাকশন প্রয়োজন!
                    </div>
                    <div style="font-size:11px; font-weight:700; margin-top:3px; color:var(--sk-ink-2);">
                        স্টাফদের থেকে
                        <strong class="sk-pill sk-pill--brand" id="alertPendingCount">0</strong>
                        টি রিটার্ন রিকোয়েস্ট পেন্ডিং।
                    </div>
                </div>
            </div>
            <a href="admin_return_history.php" class="sk-btn sk-btn--ink sk-btn--sm">
                যাচাই <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ STICKY BLOCK — Banner + Quick Menu ════════════════════════════ -->
    <div class="sk-sticky-header-block">

        <!-- Banner -->
        <div class="sk-banner" style="margin-bottom:10px;">
            <img src="banner.jpg" alt="SADA KALO FASHION Banner"
                 onerror="this.style.display='none'">
        </div>

        <!-- Quick Menu -->
        <div class="sk-card" style="margin-bottom:0;">
            <div class="sk-section-title" style="margin:0 0 8px;">
                <h2><i class="fas fa-bolt"></i> কুইক মেনু</h2>
                <span class="sk-sub"><i class="fas fa-arrows-alt-h"></i> Swipe</span>
            </div>
            <div class="qchip-row">
                <a href="inventory.php" class="qchip">
                    <span class="qchip__ic"><i class="fas fa-plus"></i></span>
                    <span class="qchip__label">Add Item</span>
                </a>
                <a href="Invantory_Items.php" class="qchip">
                    <span class="qchip__ic"><i class="fas fa-box-open"></i></span>
                    <span class="qchip__label">Item List</span>
                </a>
                <a href="inventory_pos.php" class="qchip qchip--brand">
                    <span class="qchip__ic"><i class="fas fa-shopping-cart"></i></span>
                    <span class="qchip__label">POS Sell</span>
                </a>
                <a href="inventory_sales_history.php" class="qchip">
                    <span class="qchip__ic"><i class="fas fa-receipt"></i></span>
                    <span class="qchip__label">History</span>
                </a>
                <a href="return_product.php" class="qchip">
                    <span class="qchip__ic"><i class="fas fa-undo-alt"></i></span>
                    <span class="qchip__label">Return</span>
                </a>
                <a href="out_of_stock.php" class="qchip">
                    <span class="qchip__ic"><i class="fas fa-exclamation-triangle"></i></span>
                    <span class="qchip__label">Out Stock</span>
                </a>
                <a href="category_mange.php" class="qchip">
                    <span class="qchip__ic"><i class="fas fa-folder-tree"></i></span>
                    <span class="qchip__label">ক্যাটাগরি</span>
                </a>
                <?php if ($role === 'admin'): ?>
                <a href="admin_inventory_control.php" class="qchip qchip--pos">
                    <span class="qchip__ic"><i class="fas fa-cogs"></i></span>
                    <span class="qchip__label">Admin</span>
                </a>
                <a href="daily_activity.php" class="qchip">
                    <span class="qchip__ic"><i class="fas fa-clipboard-check"></i></span>
                    <span class="qchip__label">Daily Act</span>
                </a>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.sk-sticky-header-block -->

    <!-- ══ SCROLLABLE CONTENT ════════════════════════════════════════════ -->

    <?php if ($role === 'admin'): ?>

    <!-- Inventory Summary heading -->
    <div class="sk-section-title" style="margin-top:14px;">
        <h2><i class="fas fa-chart-line"></i> ইনভেন্টরি সামারি</h2>
        <span class="sk-sub">Admin View</span>
    </div>

    <!-- All-time + Live stats -->
    <div class="sk-stats sk-stats--2" style="margin-bottom:12px;">
        <div class="sk-stat sk-stat--accent">
            <div class="sk-row sk-row--between">
                <span class="sk-stat__icon" style="background:var(--sk-grad-brand);">
                    <i class="fas fa-warehouse"></i>
                </span>
                <span class="sk-pill sk-pill--brand">All-time</span>
            </div>
            <div class="sk-stat__lbl">শুরু থেকে অ্যাড করা</div>
            <div class="sk-stat__val" id="val_everAddedPcs">
                <i class="fas fa-spinner fa-spin"></i>
            </div>
            <div class="sk-pill sk-pill--brand" style="margin-top:6px;" id="val_everAddedVal"></div>
        </div>
        <div class="sk-stat sk-stat--success">
            <div class="sk-row sk-row--between">
                <span class="sk-stat__icon"><i class="fas fa-boxes-stacked"></i></span>
                <span class="sk-pill sk-pill--success">Live</span>
            </div>
            <div class="sk-stat__lbl">বর্তমান স্টক</div>
            <div class="sk-stat__val" id="val_curPcs">
                <i class="fas fa-spinner fa-spin"></i>
            </div>
            <div class="sk-pill sk-pill--success" style="margin-top:6px;" id="val_curVal"></div>
        </div>
    </div>

    <!-- ★ আজকে অ্যাড করা — এটাই শেষ section ★ -->
    <div class="sk-card sk-card--ink" style="margin-bottom:24px;">
        <div style="font-size:10px; font-weight:800; letter-spacing:1.8px;
                    text-transform:uppercase; color:var(--sk-brand-ink); margin-bottom:10px;">
            <i class="fas fa-calendar-day"></i> আজকে নতুন করে অ্যাড করা
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
            <div>
                <div style="font-size:10px; font-weight:700; text-transform:uppercase;
                            color:rgba(255,255,255,.65);">পরিমাণ</div>
                <div style="font-size:22px; font-weight:900; color:#fff; margin-top:4px;"
                     id="val_todayAddedPcs">
                    <i class="fas fa-spinner fa-spin"></i>
                </div>
            </div>
            <div style="border-left:1px solid rgba(255,255,255,.12); padding-left:14px;">
                <div style="font-size:10px; font-weight:700; text-transform:uppercase;
                            color:rgba(255,255,255,.65);">মোট ভ্যালু</div>
                <div style="font-size:18px; font-weight:900; color:var(--sk-brand-ink); margin-top:4px;"
                     id="val_todayAddedVal">
                    <i class="fas fa-spinner fa-spin"></i>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

</main><!-- /.sk-container -->

<!-- ── Image Lightbox ────────────────────────────────────────────────────── -->
<div id="imageLightbox" onclick="closeImageModal()">
    <span class="close-lightbox" onclick="closeImageModal()">&times;</span>
    <img id="lightboxImg" src="" alt="">
    <div id="lightboxText" style="margin-top:14px; font-weight:900; letter-spacing:2.5px;
                                   font-size:14px; color:#fff; background:#09090b;
                                   border:1px solid var(--sk-brand); padding:8px 18px;
                                   border-radius:14px;"></div>
</div>

<!-- ── Bottom Navigation Bar ────────────────────────────────────────────── -->
<?php include 'inventory_bottom_nav.php'; ?>

<script>
const userCsrfToken = '<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>';

/* ── UI helpers ─────────────────────────────────────────────────────────── */
function toggleSidebar() {
    document.getElementById('mySidebar').classList.toggle('open');
    document.getElementById('myOverlay').classList.toggle('active');
}
function openImageModal(imgSrc, textLabel) {
    if (!imgSrc) return;
    document.getElementById('lightboxImg').src        = imgSrc;
    document.getElementById('lightboxText').innerText = textLabel || '';
    document.getElementById('imageLightbox').style.display = 'flex';
}
function closeImageModal() {
    document.getElementById('imageLightbox').style.display = 'none';
}
function toggleFabMenu() {
    document.getElementById('fabMenu').classList.toggle('active');
    document.getElementById('mainFabBtn').classList.toggle('active');
}
document.addEventListener('click', function (e) {
    const c = document.querySelector('.skd-fab-container');
    if (c && !c.contains(e.target)) {
        document.getElementById('fabMenu').classList.remove('active');
        document.getElementById('mainFabBtn').classList.remove('active');
    }
});

/* ── Dashboard AJAX fetch ───────────────────────────────────────────────── */
$(document).ready(function () {
    $.ajax({
        url:      'inventory_dashboard.php',
        type:     'POST',
        data:     { ajax_action: 'load_dashboard_data', csrf_token: userCsrfToken },
        dataType: 'json',
        success: function (response) {
            if (!response || response.error === 'session_expired') {
                window.location.href = '../index.php'; return;
            }
            if (response.error) return;

            /* Pending returns alert */
            if (response.pendingReturns > 0) {
                $('#alertPendingCount').text(response.pendingReturns);
                $('#adminAlertBox').show();
            }

            /* Admin stats */
            if (response.adminStats) {
                $('#val_everAddedPcs').text(response.adminStats.everAddedPcs  + ' পিস');
                $('#val_everAddedVal').text(response.adminStats.everAddedVal);
                $('#val_curPcs').text(response.adminStats.curPcs              + ' পিস');
                $('#val_curVal').text(response.adminStats.curVal);
                $('#val_todayAddedPcs').text(response.adminStats.todayAddedPcs + ' পিস');
                $('#val_todayAddedVal').text(response.adminStats.todayAddedVal);
            }
        }
    });
});

/* ── Notification bell ──────────────────────────────────────────────────── */
(function () {
    function getSeen() {
        var v = parseInt(localStorage.getItem('sk-notif-seen') || '0', 10);
        return isNaN(v) ? 0 : v;
    }
    function pollBell(ring) {
        $.ajax({
            url: 'notification_dashboard.php', type: 'POST', dataType: 'json',
            data: { ajax_action: 'load_notifications', csrf_token: userCsrfToken },
            success: function (res) {
                if (!res || res.error || !Array.isArray(res.items)) return;
                var seen   = getSeen();
                var unseen = res.items.filter(function (it) { return it.epoch > seen; }).length;
                var badge  = document.getElementById('notifBellBadge');
                if (!badge) return;
                if (unseen > 0) {
                    badge.textContent = unseen > 99 ? '99+' : String(unseen);
                    badge.classList.add('show');
                    if (ring) {
                        var b = document.getElementById('notifBell');
                        b.classList.remove('ringing');
                        void b.offsetWidth;
                        b.classList.add('ringing');
                    }
                } else {
                    badge.classList.remove('show');
                }
            }
        });
    }
    pollBell(false);
    setInterval(function () { pollBell(true); }, 30000);
})();
</script>
</body>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_shell.php'; ?>
</html>