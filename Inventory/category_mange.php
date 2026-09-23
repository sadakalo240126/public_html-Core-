<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1200)) {
    session_unset(); session_destroy();
    echo "<script>alert('Session Expired!'); window.location.href='../index.php';</script>"; exit;
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    echo "<script>window.location.href='../index.php';</script>"; exit;
}

include '../db_connect.php';
/** @var PDO $conn */
$role    = $_SESSION['role'] ?? 'user';
$isAdmin = ($role === 'admin');
$uid     = (int)($_SESSION['user_id'] ?? 1);

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrfToken = $_SESSION['csrf_token'];

// ── Ensure status column exists ────────────────────────────
try { $conn->query("SELECT status FROM categories LIMIT 1"); }
catch (Exception $e) {
    try { $conn->query("ALTER TABLE categories ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'"); }
    catch (Exception $e2) {}
}

// ── Detect date column ─────────────────────────────────────
$stmtDateCol  = $conn->query("SHOW COLUMNS FROM inventory LIKE 'date'");
$invDateCol   = $stmtDateCol->rowCount() > 0 ? 'date' : 'created_at';

// ════════════════════════════════════════════════════════════
// AJAX HANDLERS
// ════════════════════════════════════════════════════════════
if (isset($_POST['ajax_action'])) {
    ob_clean(); header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['status'=>'error','message'=>'সিকিউরিটি টোকেন মিসম্যাচ!']); exit;
    }

    // ── Category edit / toggle (admin only) ────────────────
    if (!$isAdmin && in_array($_POST['ajax_action'], ['toggle_status','edit_category'])) {
        echo json_encode(['status'=>'error','message'=>'অনুমতি নেই!']); exit;
    }

    try {
        // toggle_status
        if ($_POST['ajax_action'] === 'toggle_status') {
            $s = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
            $stmt = $conn->prepare("UPDATE categories SET status = ? WHERE id = ?");
            $stmt->execute([$s, (int)$_POST['id']]);
            echo json_encode(['status'=>'success','message'=>'স্ট্যাটাস আপডেট!']); exit;
        }

        // edit_category
        if ($_POST['ajax_action'] === 'edit_category') {
            $catName = trim($_POST['name'] ?? '');
            if ($catName === '') { echo json_encode(['status'=>'error','message'=>'নাম খালি রাখা যাবে না!']); exit; }
            $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
            $stmt->execute([$catName, (int)$_POST['id']]);
            echo json_encode(['status'=>'success','message'=>'নাম আপডেট!']); exit;
        }

        // ── load_category_products — per-category product list ──
        if ($_POST['ajax_action'] === 'load_category_products') {
            $catId = (int)($_POST['cat_id'] ?? -1);
            $page  = max(1, (int)($_POST['page'] ?? 1));
            $limit = 20;
            $off   = ($page - 1) * $limit;

            if ($catId === 0) {
                $where = "WHERE (i.category_id = 0 OR i.category_id IS NULL)";
            } else {
                $where = "WHERE i.category_id = :cid";
            }

            // total count
            $cntSql  = "SELECT COUNT(*) FROM inventory i $where";
            $cntStmt = $conn->prepare($cntSql);
            if ($catId !== 0) $cntStmt->bindValue(':cid', $catId, PDO::PARAM_INT);
            $cntStmt->execute();
            $total = (int)$cntStmt->fetchColumn();

            // product rows + sold qty
            $sql = "
                SELECT
                    i.product_code,
                    i.name          AS product_name,
                    i.pieces        AS stock_remaining,
                    i.buy_price,
                    i.cash_sell,
                    i.item_location,
                    i.$invDateCol   AS added_date,
                    i.added_by,
                    COALESCE(s.sold_pcs, 0) AS sold_pcs,
                    (i.pieces + COALESCE(s.sold_pcs,0)) AS total_added_pcs,
                    u.username      AS added_by_name
                FROM inventory i
                LEFT JOIN (
                    SELECT product_code, SUM(pieces) AS sold_pcs
                    FROM inventory_sale_items
                    GROUP BY product_code
                ) s ON s.product_code = i.product_code
                LEFT JOIN users u ON u.id = i.added_by
                $where
                ORDER BY i.$invDateCol DESC
                LIMIT :lim OFFSET :off
            ";
            $stmt = $conn->prepare($sql);
            if ($catId !== 0) $stmt->bindValue(':cid', $catId, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':off', $off,   PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'rows'   => $rows,
                'total'  => $total,
                'page'   => $page,
                'pages'  => (int)ceil($total / $limit),
            ]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['status'=>'error','message'=>'সার্ভার সমস্যা: '.$e->getMessage()]); exit;
    }
}

// ════════════════════════════════════════════════════════════
// PAGE DATA
// ════════════════════════════════════════════════════════════
$totalRemaining = 0; $totalSold = 0; $totalAdded = 0; $todayAdded = 0; $todaySold = 0;
try {
    $totalRemaining = (int)($conn->query("SELECT SUM(pieces) FROM inventory")->fetchColumn() ?? 0);
    $totalSold      = (int)($conn->query("SELECT SUM(pieces) FROM inventory_sale_items")->fetchColumn() ?? 0);
    $totalAdded     = $totalRemaining + $totalSold;
    $todayAdded     = (int)($conn->query("SELECT SUM(pieces) FROM inventory WHERE DATE($invDateCol) = CURDATE()")->fetchColumn() ?? 0);

    $stmtSoldDateCol = $conn->query("SHOW COLUMNS FROM inventory_sale_items LIKE 'sale_date'");
    $soldDateCol     = $stmtSoldDateCol->rowCount() > 0 ? 'sale_date' : 'created_at';
    $todaySold       = (int)($conn->query("SELECT SUM(pieces) FROM inventory_sale_items WHERE DATE($soldDateCol) = CURDATE()")->fetchColumn() ?? 0);
} catch (Exception $e) {}

$categoryList = []; $totalCategoriesCount = 0;
try {
    $sqlCat = "
        SELECT c.*,
               COALESCE(stock.remaining, 0) AS total_remaining,
               COALESCE(sales.sold, 0)      AS total_sold,
               COALESCE(stock.remaining,0)+COALESCE(sales.sold,0) AS total_added
        FROM categories c
        LEFT JOIN (
            SELECT category_id, SUM(pieces) AS remaining
            FROM inventory GROUP BY category_id
        ) stock ON stock.category_id = c.id
        LEFT JOIN (
            SELECT i.category_id, SUM(si.pieces) AS sold
            FROM inventory_sale_items si
            JOIN inventory i ON i.product_code = si.product_code
            GROUP BY i.category_id
        ) sales ON sales.category_id = c.id
    ";
    $categoryList         = $conn->query($sqlCat)->fetchAll(PDO::FETCH_ASSOC);
    $totalCategoriesCount = count($categoryList);

    $uncatRem   = (int)($conn->query("SELECT SUM(pieces) FROM inventory WHERE category_id=0 OR category_id IS NULL")->fetchColumn() ?? 0);
    $uncatSold  = (int)($conn->query("SELECT SUM(si.pieces) FROM inventory_sale_items si JOIN inventory i ON i.product_code=si.product_code WHERE i.category_id=0 OR i.category_id IS NULL")->fetchColumn() ?? 0);
    $uncatAdded = $uncatRem + $uncatSold;
    if ($uncatAdded > 0) {
        $categoryList[] = ['id'=>0,'name'=>'ক্যাটাগরি ছাড়া','status'=>'active',
            'total_added'=>$uncatAdded,'total_sold'=>$uncatSold,'total_remaining'=>$uncatRem,'is_uncategorized'=>true];
    }

    $rem = array_column($categoryList, 'total_remaining');
    $add = array_column($categoryList, 'total_added');
    array_multisort($rem, SORT_DESC, $add, SORT_DESC, $categoryList);
} catch (Exception $e) {}

$activeCategories = []; $inactiveCategories = [];
foreach ($categoryList as $cat) {
    if (($cat['status'] ?? 'active') === 'active') $activeCategories[] = $cat;
    else $inactiveCategories[] = $cat;
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>ক্যাটাগরি — SADA KALO</title>
<meta name="theme-color" content="#ffffff">
<link rel="icon" href="/logo.png" type="image/png">
<link rel="manifest" href="/manifest.json">
<?php require $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_assets.php'; ?>
<script>(function(){try{var t=localStorage.getItem('sk-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);else if(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="theme.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script defer src="theme-toggle.js"></script>
<style>
/* ═══ Category Page ═══════════════════════════════════════════ */

/* ── Category Cards Grid ────────────────────────────────────── */
.catgrid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: .625rem;
}
@media(min-width: 640px) { .catgrid { grid-template-columns: repeat(3,1fr); } }

.catcard {
    background: var(--sk-surface);
    border: 1px solid var(--sk-line);
    border-radius: .875rem;
    padding: .75rem;
    box-shadow: var(--sk-shadow-sm);
    display: flex;
    flex-direction: column;
    gap: .45rem;
    cursor: pointer;
    transition: transform .15s, box-shadow .15s;
    position: relative;
}
.catcard:active { transform: scale(.97); }
.catcard.active-selection {
    border-color: var(--sk-primary);
    box-shadow: 0 0 0 2px rgba(var(--sk-primary-rgb,.14), .18), var(--sk-shadow);
}
.catcard--inactive { opacity: .65; }

.catcard__top {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.catcard__title {
    font-weight: 800;
    color: var(--sk-ink);
    font-size: .82rem;
    line-height: 1.3;
    margin: 0;
}
.catcard__stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: .2rem;
    text-align: center;
    margin-top: .1rem;
}
.catcard__stat {
    background: var(--sk-surface-2);
    border-radius: .375rem;
    padding: .3rem .15rem;
}
.catcard__statlbl {
    display: block;
    font-size: .55rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: .1rem;
    text-transform: uppercase;
    letter-spacing: .03em;
}
.catcard__statval {
    font-weight: 900;
    font-size: .82rem;
    line-height: 1;
}
.catcard__actions {
    display: flex;
    gap: .3rem;
    margin-top: .15rem;
}
.readonly-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .25rem;
    background: var(--sk-surface-2);
    color: var(--sk-muted);
    font-size: .6rem;
    font-weight: 700;
    padding: .3rem .5rem;
    border-radius: .375rem;
    border: 1px solid var(--sk-line);
    width: 100%;
}

/* ── Product Detail Panel ──────────────────────────────────── */
#productPanel {
    position: fixed;
    inset: 0;
    z-index: 9000;
    display: flex;
    flex-direction: column;
    background: var(--sk-bg);
    transform: translateX(100%);
    transition: transform .28s cubic-bezier(.4,0,.2,1);
    will-change: transform;
}
#productPanel.panel-open {
    transform: translateX(0);
}

.panel-header {
    background: var(--sk-surface);
    border-bottom: 1px solid var(--sk-line);
    padding: .75rem 1rem;
    display: flex;
    align-items: center;
    gap: .625rem;
    flex-shrink: 0;
    min-height: 56px;
}
.panel-header__back {
    width: 36px; height: 36px;
    border: none; border-radius: .5rem;
    background: var(--sk-surface-2);
    color: var(--sk-ink);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; flex-shrink: 0;
    font-size: .95rem;
}
.panel-header__title {
    flex: 1;
    min-width: 0;
}
.panel-header__title h2 {
    font-size: .9rem;
    font-weight: 800;
    color: var(--sk-ink);
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.panel-header__title p {
    font-size: .7rem;
    color: var(--sk-muted);
    margin: .1rem 0 0;
    font-weight: 600;
}

/* Panel summary bar */
.panel-sumbar {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: .5rem;
    padding: .625rem .875rem;
    background: var(--sk-grad-primary);
    flex-shrink: 0;
}
.panel-sum__item {
    text-align: center;
}
.panel-sum__lbl {
    display: block;
    font-size: .58rem;
    font-weight: 700;
    color: rgba(255,255,255,.75);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.panel-sum__val {
    display: block;
    font-size: 1.05rem;
    font-weight: 900;
    color: #fff;
    line-height: 1.1;
}

/* Search bar in panel */
.panel-search {
    padding: .5rem .875rem;
    background: var(--sk-surface);
    border-bottom: 1px solid var(--sk-line);
    flex-shrink: 0;
}
.panel-search__inner {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: var(--sk-surface-2);
    border: 1px solid var(--sk-line);
    border-radius: .625rem;
    padding: .45rem .75rem;
}
.panel-search__inner i {
    color: var(--sk-muted);
    font-size: .8rem;
    flex-shrink: 0;
}
.panel-search__inner input {
    flex: 1;
    border: none;
    background: transparent;
    outline: none;
    font-size: .82rem;
    font-weight: 600;
    color: var(--sk-ink);
    font-family: inherit;
}

/* Product table */
.panel-body {
    flex: 1;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    overscroll-behavior: contain;
}

/* Table wrapper — horizontal scroll on mobile */
.tbl-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.prod-table {
    width: 100%;
    min-width: 600px;
    border-collapse: collapse;
    font-size: .75rem;
}
.prod-table thead th {
    background: var(--sk-surface-2);
    padding: .5rem .625rem;
    font-weight: 700;
    font-size: .65rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--sk-muted);
    white-space: nowrap;
    border-bottom: 2px solid var(--sk-line);
    position: sticky;
    top: 0;
    z-index: 1;
}
.prod-table tbody tr {
    border-bottom: 1px solid var(--sk-line-2);
    transition: background .12s;
}
.prod-table tbody tr:hover { background: var(--sk-surface-2); }
.prod-table td {
    padding: .5rem .625rem;
    vertical-align: middle;
    white-space: nowrap;
}

/* Code badge */
.code-badge {
    display: inline-block;
    background: var(--sk-surface-2);
    border: 1px solid var(--sk-line);
    border-radius: .3rem;
    padding: .15rem .4rem;
    font-size: .7rem;
    font-weight: 800;
    color: var(--sk-ink);
    font-family: 'JetBrains Mono', monospace, inherit;
    letter-spacing: .02em;
}

/* Stock badge */
.stock-badge {
    display: inline-flex;
    align-items: center;
    gap: .2rem;
    padding: .2rem .45rem;
    border-radius: .3rem;
    font-size: .68rem;
    font-weight: 800;
}
.stock-badge--ok  { background: var(--sk-success-soft, #d1fae5); color: var(--sk-success, #059669); }
.stock-badge--low { background: #fef3c7; color: #b45309; }
.stock-badge--out { background: var(--sk-danger-soft, #fee2e2); color: var(--sk-danger, #dc2626); }

/* location pill */
.loc-pill {
    display: inline-flex;
    align-items: center;
    gap: .18rem;
    padding: .15rem .38rem;
    border-radius: .25rem;
    font-size: .62rem;
    font-weight: 700;
    white-space: nowrap;
}
.loc-shop   { background: #dbeafe; color: #1d4ed8; }
.loc-godown { background: #ffedd5; color: #c2410c; }
.loc-other  { background: var(--sk-surface-2); color: var(--sk-muted); }

/* Panel pagination */
.panel-pager {
    padding: .5rem .875rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
    background: var(--sk-surface);
    border-top: 1px solid var(--sk-line);
    flex-shrink: 0;
}
.panel-pager__info {
    font-size: .7rem;
    font-weight: 600;
    color: var(--sk-muted);
}
.panel-pager__btns {
    display: flex;
    gap: .25rem;
}
.ppbtn {
    width: 30px; height: 30px;
    border: 1px solid var(--sk-line);
    border-radius: .375rem;
    background: var(--sk-surface);
    color: var(--sk-ink);
    font-size: .72rem;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: .12s;
}
.ppbtn:hover, .ppbtn.on { background: var(--sk-grad-primary); color: #fff; border-color: transparent; }
.ppbtn:disabled { opacity: .4; cursor: not-allowed; }

/* Loading / empty inside panel */
.panel-loader {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem 1rem;
    gap: .75rem;
    color: var(--sk-muted);
    font-size: .85rem;
    font-weight: 600;
}
.panel-loader i { font-size: 1.8rem; }

/* Inactive toggle section */
.inactive-toggle-btn {
    width: 100%;
    background: var(--sk-surface);
    border: 1px solid var(--sk-line);
    border-radius: .75rem;
    padding: .875rem 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    font-weight: 700;
    font-size: .85rem;
    color: var(--sk-ink-2);
    margin-bottom: 0;
    font-family: inherit;
}
.inactive-toggle-btn i.chevron { transition: transform .25s; }
.inactive-toggle-btn.open i.chevron { transform: rotate(180deg); }
</style>
</head>
<body>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_shell.php'; ?>

<!-- ════ APP BAR ════════════════════════════════════════════ -->
<header class="sk-appbar">
    <div class="sk-appbar__left">
        <button class="sk-iconbtn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
        <a href="inventory_dashboard.php" class="sk-iconbtn"><i class="fas fa-arrow-left"></i></a>
    </div>
    <div class="sk-appbar__title"><span class="dot"></span> ক্যাটাগরি প্যানেল</div>
    <div class="sk-appbar__right">
        <?php if (!$isAdmin): ?>
        <span class="sk-pill sk-pill--ghost"><i class="fas fa-eye"></i> VIEW</span>
        <?php endif; ?>
        <a href="../logout.php" class="sk-iconbtn sk-iconbtn--danger"><i class="fas fa-power-off"></i></a>
    </div>
</header>

<!-- ════ SIDEBAR ════════════════════════════════════════════ -->
<div class="sk-overlay" id="myOverlay" onclick="toggleSidebar()"></div>
<aside class="sk-drawer" id="mySidebar">
    <div class="sk-drawer__head">
        <button class="sk-drawer__close" onclick="toggleSidebar()"><i class="fas fa-times"></i></button>
        <img src="logo.png" class="sk-drawer__logo" onerror="this.style.display='none'">
        <div class="sk-drawer__brand">SADA KALO</div>
        <div class="sk-drawer__sub">CATEGORY PANEL</div>
    </div>
    <div class="sk-drawer__section">Quick Links</div>
    <div class="sk-drawer__grid">
        <a href="../dashboard.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-home"></i></div><span class="sk-drawer__label">হোম</span></a>
        <a href="inventory_dashboard.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-th-large"></i></div><span class="sk-drawer__label">Dashboard</span></a>
        <a href="inventory.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-plus"></i></div><span class="sk-drawer__label">Add Item</span></a>
        <a href="Invantory_Items.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-box-open"></i></div><span class="sk-drawer__label">Item List</span></a>
        <a href="inventory_pos.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-shopping-cart"></i></div><span class="sk-drawer__label">POS</span></a>
        <a href="category_mange.php" class="sk-drawer__item active"><div class="sk-drawer__icon"><i class="fas fa-folder-tree"></i></div><span class="sk-drawer__label">Category</span></a>
        <?php if ($isAdmin): ?>
        <a href="admin_inventory_control.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-cogs"></i></div><span class="sk-drawer__label">Admin</span></a>
        <a href="admin_category_control.php" class="sk-drawer__item"><div class="sk-drawer__icon"><i class="fas fa-tags"></i></div><span class="sk-drawer__label">Cat Ctrl</span></a>
        <?php endif; ?>
    </div>
</aside>

<!-- ════ MAIN ════════════════════════════════════════════════ -->
<main class="sk-container">

    <!-- Summary stats -->
    <div class="sk-stats sk-stats--4" style="margin-bottom:.875rem;">
        <div class="sk-stat sk-stat--info">
            <div class="sk-stat__icon"><i class="fas fa-box"></i></div>
            <div class="sk-stat__lbl">সর্বমোট এড</div>
            <div class="sk-stat__val"><?= number_format($totalAdded) ?> <small>পিস</small></div>
            <span class="sk-pill sk-pill--info" style="margin-top:.375rem;"><i class="far fa-calendar-alt"></i> আজ: <?= number_format($todayAdded) ?></span>
        </div>
        <div class="sk-stat sk-stat--success">
            <div class="sk-stat__icon"><i class="fas fa-shopping-cart"></i></div>
            <div class="sk-stat__lbl">সর্বমোট বিক্রি</div>
            <div class="sk-stat__val"><?= number_format($totalSold) ?> <small>পিস</small></div>
            <span class="sk-pill sk-pill--success" style="margin-top:.375rem;"><i class="far fa-calendar-check"></i> আজ: <?= number_format($todaySold) ?></span>
        </div>
        <div class="sk-stat sk-stat--warn">
            <div class="sk-stat__icon"><i class="fas fa-cubes"></i></div>
            <div class="sk-stat__lbl">স্টকে আছে</div>
            <div class="sk-stat__val"><?= number_format($totalRemaining) ?> <small>পিস</small></div>
        </div>
        <div class="sk-stat sk-stat--accent">
            <div class="sk-stat__icon"><i class="fas fa-tags"></i></div>
            <div class="sk-stat__lbl">মোট ক্যাটাগরি</div>
            <div class="sk-stat__val"><?= number_format($totalCategoriesCount) ?> <small>টি</small></div>
        </div>
    </div>

    <!-- Section heading + search -->
    <div class="sk-row sk-row--between sk-row--wrap" style="margin-bottom:.625rem; gap:.5rem;">
        <h2 style="font-size:.9rem;font-weight:700;color:var(--sk-ink);margin:0;display:flex;align-items:center;gap:.45rem;">
            <i class="fas fa-list-ul" style="color:var(--sk-primary);"></i> ক্যাটাগরি তালিকা
            <span style="font-size:.7rem;font-weight:600;color:var(--sk-muted);">· ক্লিক করলে পণ্যের লিস্ট দেখবে</span>
        </h2>
        <div class="sk-input-wrap" style="flex:1;max-width:220px;">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="সার্চ করুন..." class="sk-input sk-input--icon">
        </div>
    </div>

    <!-- Active category cards -->
    <div class="catgrid" id="activeCardsContainer">
        <?php foreach ($activeCategories as $cat):
            $isUncat = isset($cat['is_uncategorized']) && $cat['is_uncategorized'];
        ?>
        <div class="catcard card-item-wrapper"
             data-cat-id="<?= $cat['id'] ?>"
             data-cat-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>"
             data-total-added="<?= (int)($cat['total_added']??0) ?>"
             data-total-sold="<?= (int)($cat['total_sold']??0) ?>"
             data-total-rem="<?= (int)($cat['total_remaining']??0) ?>"
             onclick="openPanel(this)">
            <div class="catcard__top">
                <span class="sk-pill sk-pill--success" style="font-size:.58rem;"><i class="fas fa-check-circle"></i> ACTIVE</span>
                <span style="font-size:.65rem;color:var(--sk-muted-2);font-weight:700;">#<?= $cat['id'] ?></span>
            </div>
            <h3 class="catcard__title row-name"><?= htmlspecialchars($cat['name']) ?></h3>
            <div class="catcard__stats">
                <div class="catcard__stat">
                    <span class="catcard__statlbl" style="color:var(--sk-info);">এড</span>
                    <span class="catcard__statval" style="color:var(--sk-info);"><?= (int)($cat['total_added']??0) ?></span>
                </div>
                <div class="catcard__stat">
                    <span class="catcard__statlbl" style="color:var(--sk-success);">বিক্রি</span>
                    <span class="catcard__statval" style="color:var(--sk-success);"><?= (int)($cat['total_sold']??0) ?></span>
                </div>
                <div class="catcard__stat">
                    <span class="catcard__statlbl" style="color:var(--sk-warn);">স্টক</span>
                    <span class="catcard__statval" style="color:var(--sk-warn);"><?= (int)($cat['total_remaining']??0) ?></span>
                </div>
            </div>
            <?php if (!$isUncat && $isAdmin): ?>
            <div class="catcard__actions" onclick="event.stopPropagation()">
                <button onclick="toggleCategoryStatus(<?= $cat['id'] ?>, 'inactive')" class="sk-btn sk-btn--ghost sk-btn--sm" style="flex:1;font-size:.65rem;">
                    <i class="fas fa-power-off"></i> DISABLE
                </button>
                <button onclick="openCategoryEditModal(<?= $cat['id'] ?>, '<?= addslashes(htmlspecialchars($cat['name'])) ?>')" class="sk-btn sk-btn--accent sk-btn--sm" style="flex:1;font-size:.65rem;">
                    <i class="far fa-edit"></i> এডিট
                </button>
            </div>
            <?php elseif (!$isUncat): ?>
            <span class="readonly-badge"><i class="fas fa-eye"></i> শুধু দেখার অনুমতি</span>
            <?php else: ?>
            <span class="readonly-badge"><i class="fas fa-info-circle"></i> সিস্টেম ক্যাটাগরি</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if (empty($activeCategories)): ?>
        <div style="grid-column:1/-1;" class="sk-card sk-empty">
            <i class="fas fa-box-open"></i><p>কোনো অ্যাক্টিভ ক্যাটাগরি নেই!</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Pagination for cards -->
    <div class="sk-row sk-row--between sk-row--wrap" style="margin-top:.75rem; gap:.5rem;">
        <div class="sk-pager__info" id="pageInfo"></div>
        <div class="sk-pager" id="paginationControls"></div>
    </div>

    <!-- Inactive section -->
    <div style="margin-top:1.25rem;">
        <button class="inactive-toggle-btn" id="inactiveToggleBtn" onclick="toggleInactiveSection()">
            <span style="display:flex;align-items:center;gap:.5rem;">
                <i class="fas fa-ban" style="color:var(--sk-danger);"></i>
                ইনঅ্যাক্টিভ ক্যাটাগরি
                <span class="sk-pill sk-pill--danger"><?= count($inactiveCategories) ?></span>
            </span>
            <i class="fas fa-chevron-down chevron"></i>
        </button>

        <div id="inactiveCardsContainer" class="hidden catgrid" style="margin-top:.625rem;">
            <?php foreach ($inactiveCategories as $cat): ?>
            <div class="catcard catcard--inactive inactive-card-wrapper"
                 data-cat-id="<?= $cat['id'] ?>"
                 data-cat-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>"
                 data-total-added="<?= (int)($cat['total_added']??0) ?>"
                 data-total-sold="<?= (int)($cat['total_sold']??0) ?>"
                 data-total-rem="<?= (int)($cat['total_remaining']??0) ?>"
                 onclick="openPanel(this)">
                <div class="catcard__top">
                    <span class="sk-pill sk-pill--danger" style="font-size:.58rem;"><i class="fas fa-ban"></i> INACTIVE</span>
                    <span style="font-size:.65rem;color:var(--sk-muted-2);font-weight:700;">#<?= $cat['id'] ?></span>
                </div>
                <h3 class="catcard__title row-name"><?= htmlspecialchars($cat['name']) ?></h3>
                <div class="catcard__stats">
                    <div class="catcard__stat">
                        <span class="catcard__statlbl" style="color:var(--sk-muted);">এড</span>
                        <span class="catcard__statval" style="color:var(--sk-muted);"><?= (int)($cat['total_added']??0) ?></span>
                    </div>
                    <div class="catcard__stat">
                        <span class="catcard__statlbl" style="color:var(--sk-muted);">বিক্রি</span>
                        <span class="catcard__statval" style="color:var(--sk-muted);"><?= (int)($cat['total_sold']??0) ?></span>
                    </div>
                    <div class="catcard__stat">
                        <span class="catcard__statlbl" style="color:var(--sk-muted);">স্টক</span>
                        <span class="catcard__statval" style="color:var(--sk-muted);"><?= (int)($cat['total_remaining']??0) ?></span>
                    </div>
                </div>
                <?php if ($isAdmin): ?>
                <div class="catcard__actions" onclick="event.stopPropagation()">
                    <button onclick="toggleCategoryStatus(<?= $cat['id'] ?>, 'active')" class="sk-btn sk-btn--success sk-btn--sm" style="flex:1;font-size:.65rem;">
                        <i class="fas fa-check"></i> ENABLE
                    </button>
                    <button onclick="openCategoryEditModal(<?= $cat['id'] ?>, '<?= addslashes(htmlspecialchars($cat['name'])) ?>')" class="sk-btn sk-btn--ghost sk-btn--sm" style="flex:1;font-size:.65rem;">
                        <i class="far fa-edit"></i> এডিট
                    </button>
                </div>
                <?php else: ?>
                <span class="readonly-badge"><i class="fas fa-eye"></i> শুধু দেখার অনুমতি</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if (empty($inactiveCategories)): ?>
            <div style="grid-column:1/-1;text-align:center;padding:1rem;color:var(--sk-muted);font-weight:600;">
                কোনো ইনঅ্যাক্টিভ ক্যাটাগরি নেই।
            </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<!-- ════ PRODUCT DETAIL PANEL (slide-in) ═══════════════════════════ -->
<div id="productPanel">

    <!-- Panel header -->
    <div class="panel-header">
        <button class="panel-header__back" onclick="closePanel()">
            <i class="fas fa-arrow-left"></i>
        </button>
        <div class="panel-header__title">
            <h2 id="panelCatName">ক্যাটাগরি</h2>
            <p id="panelSubtitle">পণ্যের তালিকা লোড হচ্ছে...</p>
        </div>
        <div style="display:flex;gap:.35rem;">
            <button class="sk-iconbtn" id="panelExportBtn" onclick="exportTable()" title="CSV ডাউনলোড" style="font-size:.8rem;">
                <i class="fas fa-download"></i>
            </button>
        </div>
    </div>

    <!-- Summary bar -->
    <div class="panel-sumbar">
        <div class="panel-sum__item">
            <span class="panel-sum__lbl">মোট এড</span>
            <span class="panel-sum__val" id="pSumAdded">—</span>
        </div>
        <div class="panel-sum__item">
            <span class="panel-sum__lbl">বিক্রি</span>
            <span class="panel-sum__val" id="pSumSold">—</span>
        </div>
        <div class="panel-sum__item">
            <span class="panel-sum__lbl">স্টক</span>
            <span class="panel-sum__val" id="pSumStock">—</span>
        </div>
    </div>

    <!-- Search inside panel -->
    <div class="panel-search">
        <div class="panel-search__inner">
            <i class="fas fa-search"></i>
            <input type="text" id="panelSearch" placeholder="পণ্য কোড / নাম দিয়ে ফিল্টার করুন..." autocomplete="off">
        </div>
    </div>

    <!-- Table body -->
    <div class="panel-body" id="panelBody">
        <div class="panel-loader">
            <i class="fas fa-circle-notch fa-spin"></i>
            <span>লোড হচ্ছে...</span>
        </div>
    </div>

    <!-- Pagination -->
    <div class="panel-pager" id="panelPager" style="display:none;">
        <div class="panel-pager__info" id="panelPagerInfo"></div>
        <div class="panel-pager__btns" id="panelPagerBtns"></div>
    </div>

</div>

<!-- ════ EDIT MODAL ════════════════════════════════════════════ -->
<?php if ($isAdmin): ?>
<div id="editCategoryModal" class="sk-modal">
    <div class="sk-modal__sheet">
        <div class="sk-modal__head">
            <div class="sk-modal__title"><i class="fas fa-edit"></i> ক্যাটাগরি আপডেট</div>
            <button onclick="closeCategoryEditModal()" class="sk-modal__close">&times;</button>
        </div>
        <form id="editCategoryForm">
            <input type="hidden" id="edit_category_id">
            <div class="sk-field">
                <label class="sk-label">ক্যাটাগরির নাম</label>
                <input type="text" id="edit_category_name" class="sk-input" required>
            </div>
            <button type="submit" id="saveCategoryBtn" class="sk-btn sk-btn--accent sk-btn--block sk-btn--lg">
                <i class="fas fa-save"></i> সেভ করুন
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ════ SCRIPTS ════════════════════════════════════════════════ -->
<script>
/* ── Sidebar ── */
function toggleSidebar() {
    document.getElementById('mySidebar').classList.toggle('open');
    document.getElementById('myOverlay').classList.toggle('active');
}

/* ── Inactive Section Toggle ── */
function toggleInactiveSection() {
    var c   = document.getElementById('inactiveCardsContainer');
    var btn = document.getElementById('inactiveToggleBtn');
    c.classList.toggle('hidden');
    btn.classList.toggle('open');
}

/* ════════════════════════════════
   CATEGORY CARD GRID (pagination)
═════════════════════════════════ */
var itemsPerPage = 12;
var currentPage  = parseInt(sessionStorage.getItem('categoryCurrentPage') || '1');
var allCards     = [];
var filteredCards = [];

$(document).ready(function() {
    $('.card-item-wrapper').each(function() { allCards.push($(this)); });
    filteredCards = allCards.slice();
    displayCards();
});

$('#searchInput').on('input', function() {
    var q = $(this).val().toLowerCase().trim();
    filteredCards = allCards.filter(function(c) {
        return c.find('.row-name').text().toLowerCase().indexOf(q) > -1;
    });
    currentPage = 1;
    sessionStorage.setItem('categoryCurrentPage', 1);
    displayCards();

    // Also filter inactive
    var hasInactive = false;
    $('.inactive-card-wrapper').each(function() {
        if ($(this).find('.row-name').text().toLowerCase().indexOf(q) > -1) {
            $(this).show(); hasInactive = true;
        } else {
            $(this).hide();
        }
    });
    if (q && hasInactive && $('#inactiveCardsContainer').hasClass('hidden')) {
        toggleInactiveSection();
    }
});

function displayCards() {
    var total      = filteredCards.length;
    var totalPages = Math.max(1, Math.ceil(total / itemsPerPage));
    if (currentPage > totalPages) currentPage = totalPages;
    var start = (currentPage - 1) * itemsPerPage;
    var items = filteredCards.slice(start, start + itemsPerPage);

    $('#activeCardsContainer').empty();
    if (!items.length) {
        $('#activeCardsContainer').html('<div style="grid-column:1/-1;" class="sk-empty"><i class="fas fa-search"></i><p>কোনো ডেটা পাওয়া যায়নি!</p></div>');
        $('#paginationControls').html(''); $('#pageInfo').text('');
        return;
    }
    items.forEach(function(c) { $('#activeCardsContainer').append(c); });
    buildCardPager(total, totalPages);
}

function buildCardPager(total, totalPages) {
    var s = (currentPage - 1) * itemsPerPage + 1;
    var e = Math.min(currentPage * itemsPerPage, total);
    $('#pageInfo').text('মোট ' + total + ' টির মধ্যে ' + s + '–' + e);
    var h = '';
    if (currentPage > 1) h += '<button onclick="cardGoTo(' + (currentPage-1) + ')" class="sk-pager__btn"><i class="fas fa-chevron-left"></i></button>';
    for (var i = 1; i <= totalPages; i++) {
        h += '<button onclick="cardGoTo(' + i + ')" class="sk-pager__btn' + (i===currentPage?' active':'') + '">' + i + '</button>';
    }
    if (currentPage < totalPages) h += '<button onclick="cardGoTo(' + (currentPage+1) + ')" class="sk-pager__btn"><i class="fas fa-chevron-right"></i></button>';
    $('#paginationControls').html(h);
}
function cardGoTo(p) {
    currentPage = p;
    sessionStorage.setItem('categoryCurrentPage', p);
    displayCards();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ════════════════════════════════
   PRODUCT PANEL
═════════════════════════════════ */
var _panelCatId   = null;
var _panelCatName = '';
var _panelPage    = 1;
var _panelSearch  = '';
var _panelAllRows = []; // client-side filtered cache

function openPanel(card) {
    var $c   = $(card);
    _panelCatId   = $c.data('cat-id');
    _panelCatName = $c.data('cat-name');
    _panelPage    = 1;
    _panelSearch  = '';
    _panelAllRows = [];

    $('#panelCatName').text(_panelCatName);
    $('#panelSubtitle').text('লোড হচ্ছে...');
    $('#pSumAdded').text($c.data('total-added'));
    $('#pSumSold').text($c.data('total-sold'));
    $('#pSumStock').text($c.data('total-rem'));
    $('#panelSearch').val('');
    $('#panelPager').hide();

    // highlight
    $('.catcard').removeClass('active-selection');
    $c.addClass('active-selection');

    // open panel
    document.getElementById('productPanel').classList.add('panel-open');
    document.body.style.overflow = 'hidden';

    loadPanelPage(1);
}

function closePanel() {
    document.getElementById('productPanel').classList.remove('panel-open');
    document.body.style.overflow = '';
    $('.catcard').removeClass('active-selection');
    _panelCatId = null;
}

// Back button / swipe close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePanel();
});

// Touch swipe right to close
(function() {
    var sx = 0, sy = 0;
    var panel = document.getElementById('productPanel');
    panel.addEventListener('touchstart', function(e) {
        sx = e.touches[0].clientX;
        sy = e.touches[0].clientY;
    }, { passive: true });
    panel.addEventListener('touchend', function(e) {
        var dx = e.changedTouches[0].clientX - sx;
        var dy = Math.abs(e.changedTouches[0].clientY - sy);
        if (dx > 60 && dy < 40) closePanel();
    }, { passive: true });
})();

function loadPanelPage(page) {
    _panelPage = page;
    $('#panelBody').html('<div class="panel-loader"><i class="fas fa-circle-notch fa-spin"></i><span>লোড হচ্ছে...</span></div>');
    $('#panelPager').hide();

    $.ajax({
        url: 'category_mange.php',
        type: 'POST',
        dataType: 'json',
        data: {
            ajax_action: 'load_category_products',
            cat_id: _panelCatId,
            page: page,
            csrf_token: '<?= $csrfToken ?>'
        },
        success: function(r) {
            if (r.status !== 'success') {
                $('#panelBody').html('<div class="panel-loader"><i class="fas fa-exclamation-triangle" style="color:var(--sk-danger);"></i><span>লোড ব্যর্থ!</span></div>');
                return;
            }
            _panelAllRows = r.rows;
            renderTable(r.rows);
            $('#panelSubtitle').text('মোট ' + r.total + ' টি পণ্য');
            buildPanelPager(r.total, r.page, r.pages);
            applyPanelSearch(); // re-apply any search
        },
        error: function() {
            $('#panelBody').html('<div class="panel-loader"><i class="fas fa-wifi" style="color:var(--sk-danger);"></i><span>নেটওয়ার্ক সমস্যা!</span></div>');
        }
    });
}

function renderTable(rows) {
    if (!rows || !rows.length) {
        $('#panelBody').html('<div class="panel-loader"><i class="fas fa-box-open"></i><span>এই ক্যাটাগরিতে কোনো পণ্য নেই</span></div>');
        return;
    }

    var html = '<div class="tbl-wrap"><table class="prod-table">'
             + '<thead><tr>'
             + '<th>#</th>'
             + '<th>পণ্য কোড</th>'
             + '<th>নাম</th>'
             + '<th>মোট এড (পিস)</th>'
             + '<th>বিক্রি (পিস)</th>'
             + '<th>স্টক (পিস)</th>'
             + '<th>ক্রয় মূল্য</th>'
             + '<th>বিক্রয় মূল্য</th>'
             + '<th>লোকেশন</th>'
             + '<th>তারিখ</th>'
             + '<th>এড করেছে</th>'
             + '</tr></thead><tbody>';

    rows.forEach(function(r, idx) {
        var stock   = parseInt(r.stock_remaining) || 0;
        var sold    = parseInt(r.sold_pcs) || 0;
        var addedPc = parseInt(r.total_added_pcs) || 0;

        // Stock badge
        var stockCls = 'stock-badge--ok';
        if (stock === 0) stockCls = 'stock-badge--out';
        else if (stock <= 2) stockCls = 'stock-badge--low';

        // Location pill
        var loc = (r.item_location || '').toLowerCase();
        var locCls = 'loc-other';
        var locLabel = r.item_location || '—';
        if (loc === 'shop')   { locCls = 'loc-shop';   locLabel = '<i class="fas fa-store"></i> দোকান'; }
        if (loc === 'godown') { locCls = 'loc-godown'; locLabel = '<i class="fas fa-warehouse"></i> গোডাউন'; }

        // Date format
        var dateStr = '—';
        if (r.added_date) {
            var d = new Date(r.added_date);
            if (!isNaN(d)) {
                dateStr = d.toLocaleDateString('bn-BD', { day:'2-digit', month:'short', year:'numeric' });
            }
        }

        var buyPrice  = parseFloat(r.buy_price)  || 0;
        var cashSell  = parseFloat(r.cash_sell)  || 0;

        html += '<tr class="prod-row" data-code="' + escHtml(r.product_code) + '" data-name="' + escHtml(r.product_name) + '">'
              + '<td style="color:var(--sk-muted);font-size:.65rem;">' + ((_panelPage-1)*20 + idx+1) + '</td>'
              + '<td><span class="code-badge">' + escHtml(r.product_code) + '</span></td>'
              + '<td style="max-width:120px;white-space:normal;line-height:1.3;">' + escHtml(r.product_name) + '</td>'
              + '<td style="text-align:center;font-weight:800;color:var(--sk-info);">' + addedPc + '</td>'
              + '<td style="text-align:center;font-weight:800;color:var(--sk-success);">' + sold + '</td>'
              + '<td style="text-align:center;"><span class="stock-badge ' + stockCls + '">' + stock + '</span></td>'
              + '<td style="text-align:right;font-weight:700;">৳' + buyPrice.toLocaleString('en-IN') + '</td>'
              + '<td style="text-align:right;font-weight:700;">৳' + cashSell.toLocaleString('en-IN') + '</td>'
              + '<td><span class="loc-pill ' + locCls + '">' + locLabel + '</span></td>'
              + '<td style="font-size:.68rem;">' + dateStr + '</td>'
              + '<td style="font-size:.68rem;color:var(--sk-muted);font-weight:600;">' + escHtml(r.added_by_name || '#'+r.added_by) + '</td>'
              + '</tr>';
    });

    html += '</tbody></table></div>';
    $('#panelBody').html(html);
}

function buildPanelPager(total, page, pages) {
    if (pages <= 1) { $('#panelPager').hide(); return; }
    $('#panelPager').show();
    var s = (page - 1) * 20 + 1;
    var e = Math.min(page * 20, total);
    $('#panelPagerInfo').text(s + '–' + e + ' / ' + total);

    var h = '';
    if (page > 1)     h += '<button class="ppbtn" onclick="loadPanelPage(' + (page-1) + ')"><i class="fas fa-chevron-left"></i></button>';
    var from = Math.max(1, page - 2);
    var to   = Math.min(pages, page + 2);
    for (var i = from; i <= to; i++) {
        h += '<button class="ppbtn' + (i===page?' on':'') + '" onclick="loadPanelPage(' + i + ')">' + i + '</button>';
    }
    if (page < pages) h += '<button class="ppbtn" onclick="loadPanelPage(' + (page+1) + ')"><i class="fas fa-chevron-right"></i></button>';
    $('#panelPagerBtns').html(h);
}

/* Panel search filter (client-side on loaded rows) */
$('#panelSearch').on('input', function() {
    _panelSearch = $(this).val().toLowerCase().trim();
    applyPanelSearch();
});

function applyPanelSearch() {
    if (!_panelSearch) {
        renderTable(_panelAllRows);
        return;
    }
    var filtered = _panelAllRows.filter(function(r) {
        return (r.product_code || '').toLowerCase().indexOf(_panelSearch) > -1
            || (r.product_name || '').toLowerCase().indexOf(_panelSearch) > -1;
    });
    renderTable(filtered);
}

/* ── CSV Export ── */
function exportTable() {
    if (_panelCatId === null) return;
    var rows = _panelAllRows;
    if (!rows.length) { alert('কোনো ডেটা নেই।'); return; }
    var header = ['পণ্য কোড','নাম','মোট এড (পিস)','বিক্রি (পিস)','স্টক (পিস)','ক্রয় মূল্য','বিক্রয় মূল্য','লোকেশন','তারিখ','এড করেছে'];
    var csv = [header.join(',')];
    rows.forEach(function(r) {
        csv.push([
            '"'+r.product_code+'"',
            '"'+r.product_name+'"',
            r.total_added_pcs,
            r.sold_pcs,
            r.stock_remaining,
            r.buy_price,
            r.cash_sell,
            '"'+(r.item_location||'')+'"',
            '"'+(r.added_date||'')+'"',
            '"'+(r.added_by_name||r.added_by)+'"'
        ].join(','));
    });
    var blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    var a    = document.createElement('a');
    a.href   = URL.createObjectURL(blob);
    a.download = _panelCatName.replace(/[^\w\u0980-\u09FF]/g, '_') + '_products.csv';
    a.click();
}

/* ── Utility ── */
function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

<?php if ($isAdmin): ?>
/* ── Admin: toggle category status ── */
function toggleCategoryStatus(id, newStatus) {
    if (!confirm((newStatus==='inactive'?'ডিজেবল':'এনাবল') + ' করতে নিশ্চিত?')) return;
    $.ajax({
        url: 'category_mange.php', type: 'POST', dataType: 'json',
        data: { ajax_action:'toggle_status', id:id, status:newStatus, csrf_token:'<?= $csrfToken ?>' },
        success: function(r) { r.status==='success' ? location.reload() : alert(r.message); }
    });
}
/* ── Admin: edit modal ── */
function openCategoryEditModal(id, name) {
    $('#edit_category_id').val(id);
    $('#edit_category_name').val(name);
    $('#editCategoryModal').addClass('open');
}
function closeCategoryEditModal() { $('#editCategoryModal').removeClass('open'); }
$('#editCategoryForm').submit(function(e) {
    e.preventDefault();
    var btn = $('#saveCategoryBtn'); var orig = btn.html();
    btn.html('<i class="fas fa-spinner fa-spin"></i> সেভ হচ্ছে...').prop('disabled', true);
    $.ajax({
        url: 'category_mange.php', type: 'POST', dataType: 'json',
        data: { ajax_action:'edit_category', id:$('#edit_category_id').val(), name:$('#edit_category_name').val(), csrf_token:'<?= $csrfToken ?>' },
        success: function(r) { r.status==='success' ? location.reload() : alert(r.message); },
        complete: function() { btn.html(orig).prop('disabled', false); }
    });
});
<?php endif; ?>
</script>

</body>
</html>
