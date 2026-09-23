<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1200)) {
    session_unset(); session_destroy(); header("Location: ../index.php"); exit;
}
$_SESSION['last_activity'] = time();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) { header("Location: ../index.php"); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrfToken = $_SESSION['csrf_token'];
include '../db_connect.php';
$role = $_SESSION['role'] ?? 'user';
$uid  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;
date_default_timezone_set('Asia/Dhaka');

// Audit + StorageLocation Helper
require_once __DIR__ . '/Helpers/AuditInit.php';
require_once __DIR__ . '/Helpers/StorageLocationHelper.php';
AuditInit::boot($conn);

// ── Location Colors (DB → JS) ──────────────────────────────
// location_colors টেবিল থেকে আনা — সব ডিভাইসে একই কালার দেখাবে
$locationColors = [
    'shop'   => ['label'=>'দোকান',   'color'=>'#1d4ed8','bg'=>'#dbeafe','icon'=>'fa-store'],
    'godown' => ['label'=>'গোডাউন','color'=>'#c2410c','bg'=>'#ffedd5','icon'=>'fa-warehouse'],
];
try {
    $lcRows = $conn->query('SELECT location_key,label_bn,color_hex,bg_hex FROM location_colors')
                   ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lcRows as $lcRow) {
        $k = $lcRow['location_key'];
        if (isset($locationColors[$k])) {
            if ($lcRow['label_bn'])  $locationColors[$k]['label'] = $lcRow['label_bn'];
            if ($lcRow['color_hex']) $locationColors[$k]['color'] = $lcRow['color_hex'];
            if ($lcRow['bg_hex'])    $locationColors[$k]['bg']    = $lcRow['bg_hex'];
        }
    }
} catch (PDOException $e) { /* fallback colors */ }

// ── Invoice Number ──────────────────────────────────────────
$invStmt = $conn->query("SELECT invoice_no FROM inventory_sales WHERE invoice_no LIKE 'SKF-INV-%' ORDER BY CAST(SUBSTRING_INDEX(invoice_no, '-', -1) AS UNSIGNED) DESC LIMIT 1");
$lastInv = $invStmt->fetchColumn();
$nextInvoiceNo = 'SKF-INV-6003';
if ($lastInv) {
    $lastNum = (int) str_replace('SKF-INV-', '', $lastInv);
    if ($lastNum >= 6003) $nextInvoiceNo = 'SKF-INV-' . ($lastNum + 1);
}

// ══════════════════════════════════════════════════════════
// AJAX Handlers
// ══════════════════════════════════════════════════════════
if (isset($_POST['ajax_action'])) {
    ob_clean(); header('Content-Type: application/json; charset=utf-8');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        echo json_encode(['status'=>'error','message'=>'সিকিউরিটি টোকেন মিসম্যাচ!']); exit;
    }

    // ── scan_barcode ──────────────────────────────────────
    if ($_POST['ajax_action'] == 'scan_barcode') {
        $code = trim($_POST['product_code'] ?? '');
        $stmt = $conn->prepare(
            "SELECT i.*, c.name as cat_name
             FROM inventory i
             LEFT JOIN categories c ON i.category_id = c.id
             WHERE i.product_code = ? LIMIT 1"
        );
        $stmt->execute([$code]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($product) echo json_encode(['status'=>'success','product'=>$product]);
        else          echo json_encode(['status'=>'error']);
        exit;
    }

    // ── search_product ─────────────────────────────────────
    if ($_POST['ajax_action'] == 'search_product') {
        $searchQuery = trim($_POST['query'] ?? '');
        if (empty($searchQuery)) { echo json_encode([]); exit; }
        $searchTerm  = "%{$searchQuery}%";
        $endMatch    = "%{$searchQuery}";
        $paddedMatch = "%" . str_pad($searchQuery, 2, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare(
            "SELECT i.*, c.name as cat_name
             FROM inventory i
             LEFT JOIN categories c ON i.category_id = c.id
             WHERE (i.product_code LIKE ? OR i.name LIKE ?) AND i.pieces > 0
             ORDER BY
               CASE WHEN i.product_code LIKE ? THEN 1
                    WHEN i.product_code LIKE ? THEN 2
                    ELSE 3 END,
               i.id DESC
             LIMIT 15"
        );
        $stmt->execute([$searchTerm, $searchTerm, $paddedMatch, $endMatch]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }

    // ── submit_sale ────────────────────────────────────────
    if ($_POST['ajax_action'] == 'submit_sale') {
        $cartData     = json_decode($_POST['cart_data'] ?? '[]', true);
        $newInvoiceNo = trim($_POST['invoice_no'] ?? '');
        $customerName = trim($_POST['customer_name'] ?? '');
        if (mb_strlen($customerName) > 120) $customerName = mb_substr($customerName, 0, 120);
        if (empty($cartData))     { echo json_encode(['status'=>'error','message'=>'কার্ট খালি!']); exit; }
        if (empty($newInvoiceNo)) { echo json_encode(['status'=>'error','message'=>'ইনভয়েস নম্বর ফাঁকা!']); exit; }

        try {
            $conn->beginTransaction();
            $dupCheck = $conn->prepare("SELECT id FROM inventory_sales WHERE invoice_no = ? LIMIT 1");
            $dupCheck->execute([$newInvoiceNo]);
            if ($dupCheck->rowCount() > 0) throw new Exception("ইনভয়েস নম্বর ({$newInvoiceNo}) ইতিমধ্যে ব্যবহৃত!");

            $grandTotalPieces = 0; $grandTotalAmount = 0; $grandTotalProfit = 0;
            $insertSaleStmt = $conn->prepare(
                "INSERT INTO inventory_sales (invoice_no, customer_name, total_pieces, total_sell_amount, total_profit, sold_by)
                 VALUES (?, ?, 0, 0, 0, ?)"
            );
            $insertSaleStmt->execute([$newInvoiceNo, ($customerName !== '' ? $customerName : null), $uid]);
            $saleId = $conn->lastInsertId();

            $insertItemStmt  = $conn->prepare(
                "INSERT INTO inventory_sale_items
                    (sale_id, product_code, category_name, buy_price, cost, sell_price, profit, pieces, item_location)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $updateStockStmt = $conn->prepare("UPDATE inventory SET pieces = pieces - ? WHERE product_code = ?");
            $checkItemStmt   = $conn->prepare(
                "SELECT i.pieces, i.buy_price, i.cost, i.item_location,
                        c.name as cat_name
                 FROM inventory i LEFT JOIN categories c ON i.category_id = c.id
                 WHERE i.product_code = ? LIMIT 1"
            );

            $auditItems = [];
            foreach ($cartData as $item) {
                $productCode = trim((string)($item['product_code'] ?? ''));
                $sellQty     = (int)($item['qty'] ?? 0);
                $sellPrice   = (float)($item['price'] ?? 0);
                $checkItemStmt->execute([$productCode]);
                $dbItem = $checkItemStmt->fetch(PDO::FETCH_ASSOC);
                if (!$dbItem || $dbItem['pieces'] < $sellQty)
                    throw new Exception("স্টকে পর্যাপ্ত পণ্য নেই: {$productCode}");

                $buyPrice     = (float)$dbItem['buy_price'];
                $cost         = (float)$dbItem['cost'];
                $minPrice     = $buyPrice + $cost;
                $catName      = (string)($dbItem['cat_name'] ?? 'N/A');
                $itemLocation = (string)($dbItem['item_location'] ?? ''); // বিক্রির সময়ের স্ন্যাপশট

                if ($role !== 'admin' && $sellPrice < $minPrice) {
                    throw new Exception("নিরাপত্তা এলার্ট: '{$productCode}' বিক্রয় মূল্য (৳{$sellPrice}) কেনা+খরচ (৳{$minPrice}) থেকে কম!");
                }

                $unitProfit       = $sellPrice - $minPrice;
                $totalItemAmount  = $sellPrice * $sellQty;
                $grandTotalPieces += $sellQty;
                $grandTotalAmount += $totalItemAmount;
                $grandTotalProfit += $unitProfit * $sellQty;

                $insertItemStmt->execute([$saleId, $productCode, $catName, $buyPrice, $cost, $sellPrice, $unitProfit, $sellQty, $itemLocation]);
                $updateStockStmt->execute([$sellQty, $productCode]);

                // ── Location Timeline log: 'sold' ──
                StorageLocationHelper::logEvent($conn, [
                    'product_code'  => $productCode,
                    'event_type'    => 'sold',
                    'from_location' => $itemLocation,
                    'pieces'        => $sellQty,
                    'unit_price'    => $sellPrice,
                    'invoice_no'    => $newInvoiceNo,
                    'note'          => 'বিক্রি',
                    'done_by'       => $uid,
                    'done_by_name'  => (string)($_SESSION['username'] ?? 'User'),
                ]);

                $auditItems[] = compact('productCode','catName','sellQty','sellPrice','buyPrice','cost','unitProfit','totalItemAmount');
            }

            $conn->prepare("UPDATE inventory_sales SET total_pieces=?, total_sell_amount=?, total_profit=? WHERE id=?")
                 ->execute([$grandTotalPieces, $grandTotalAmount, $grandTotalProfit, $saleId]);
            $conn->commit();

            if (class_exists('AuditLogger')) {
                AuditLogger::create('inventory_sales', (int)$saleId, null, [
                    'invoice_no'        => $newInvoiceNo,
                    'customer_name'     => $customerName ?: null,
                    'total_pieces'      => $grandTotalPieces,
                    'total_sell_amount' => $grandTotalAmount,
                    'total_profit'      => $grandTotalProfit,
                    'sold_by'           => $uid,
                    'items'             => $auditItems,
                ], "POS বিক্রি — ইনভয়েস {$newInvoiceNo} ({$grandTotalPieces} পিস, ৳{$grandTotalAmount})");
            }

            echo json_encode(['status'=>'success','message'=>'বিক্রি সম্পন্ন!','invoice_no'=>$newInvoiceNo]);
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>POS — SADA KALO</title>
    <meta name="theme-color" content="#ffffff">
<link rel="icon" href="/logo.png" type="image/png">
<link rel="manifest" href="/manifest.json">
<?php require $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_assets.php'; ?>
    <script>(function(){try{var t=localStorage.getItem('sk-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);else if(window.matchMedia&&window.matchMedia('(prefers-color-scheme:dark)').matches)document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="theme.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script defer src="theme-toggle.js"></script>
    <style>
        /* ── Search Dropdown ─────────────────── */
        .sk-search-results {
            position:absolute; top:100%; left:0; width:100%;
            background:var(--sk-surface); border:1px solid var(--sk-line);
            border-radius:.5rem; box-shadow:var(--sk-shadow-lg);
            max-height:360px; overflow-y:auto; z-index:500;
            display:none; margin-top:.375rem;
        }
        .search-item {
            display:flex; align-items:center; justify-content:space-between;
            padding:.625rem .75rem; border-bottom:1px solid var(--sk-line-2);
            cursor:pointer; transition:.12s; gap:.75rem;
        }
        .search-item:hover { background:var(--sk-surface-2); border-left:4px solid var(--sk-primary); }
        .search-item:last-child { border-bottom:0; }

        /* ── Sticky Search Bar ───────────────── */
        .pos-sticky {
            position: sticky;
            top: 52px;          /* sk-appbar height */
            z-index: 200;
            background: var(--sk-bg, #f7f9fa);
            padding: .5rem 0 .25rem;
            margin: 0 -1rem;    /* sk-container padding cancel */
            padding-left: 1rem;
            padding-right: 1rem;
            border-bottom: 1px solid var(--sk-line);
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .pos-sticky .sk-field { margin-bottom:.375rem; }

        /* ── Location Badge ──────────────────── */
        .loc-badge {
            display:inline-flex; align-items:center; gap:.25rem;
            padding:.15rem .45rem; border-radius:.35rem;
            border:1.5px solid; font-size:.68rem; font-weight:800;
            white-space:nowrap; line-height:1.4;
        }
    </style>
</head>
<body>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_shell.php'; ?>

<header class="sk-appbar">
    <div class="sk-appbar__left">
        <button class="sk-iconbtn" onclick="document.getElementById('skDrawer').classList.add('open');document.getElementById('skOverlay').classList.add('active');"><i class="fas fa-bars"></i></button>
        <a href="inventory_dashboard.php" class="sk-iconbtn"><i class="fas fa-arrow-left"></i></a>
    </div>
    <div class="sk-appbar__title"><span class="dot"></span> POS · বিক্রি</div>
    <div class="sk-appbar__right">
        <a href="../logout.php" class="sk-iconbtn sk-iconbtn--danger"><i class="fas fa-power-off"></i></a>
    </div>
</header>

<div class="sk-overlay" id="skOverlay" onclick="document.getElementById('skDrawer').classList.remove('open');this.classList.remove('active');"></div>
<aside class="sk-drawer" id="skDrawer">
    <div class="sk-drawer__head">
        <button class="sk-drawer__close" onclick="document.getElementById('skDrawer').classList.remove('open');document.getElementById('skOverlay').classList.remove('active');"><i class="fas fa-times"></i></button>
        <img src="logo.png" onerror="this.style.display='none'" class="sk-drawer__logo" alt="logo">
        <div class="sk-drawer__brand">SADA KALO</div>
        <div class="sk-drawer__sub">POS · POINT OF SALE</div>
    </div>
    <div class="sk-drawer__section">Main</div>
    <div class="sk-drawer__grid">
        <a href="inventory_dashboard.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-th-large"></i></span><span class="sk-drawer__label">Dashboard</span></a>
        <a href="inventory.php"           class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-plus"></i></span><span class="sk-drawer__label">Add Item</span></a>
        <a href="Invantory_Items.php"      class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-box-open"></i></span><span class="sk-drawer__label">Item List</span></a>
        <a href="inventory_pos.php"       class="sk-drawer__item active"><span class="sk-drawer__icon"><i class="fas fa-shopping-cart"></i></span><span class="sk-drawer__label">POS</span></a>
        <a href="inventory_sales_history.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-receipt"></i></span><span class="sk-drawer__label">History</span></a>
        <a href="return_product.php"      class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-undo-alt"></i></span><span class="sk-drawer__label">Return</span></a>
        <a href="out_of_stock.php"        class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-exclamation-triangle"></i></span><span class="sk-drawer__label">Out Stock</span></a>
    </div>
    <?php if ($role === 'admin'): ?>
    <div class="sk-drawer__section">Admin</div>
    <div class="sk-drawer__grid">
        <a href="admin_inventory_control.php" class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-cogs"></i></span><span class="sk-drawer__label">Inv Ctrl</span></a>
        <a href="admin_category_control.php"  class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-tags"></i></span><span class="sk-drawer__label">Category</span></a>
        <a href="admin_return_history.php"    class="sk-drawer__item"><span class="sk-drawer__icon"><i class="fas fa-history"></i></span><span class="sk-drawer__label">Return Log</span></a>
    </div>
    <?php endif; ?>
</aside>

<main class="sk-container">

    <!-- Invoice Card -->
    <div class="sk-card sk-card--accent" style="margin-bottom:.875rem;">
        <div class="sk-row sk-row--between">
            <div>
                <div style="font-size:.7rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:var(--sk-primary-ink);"><i class="fas fa-file-invoice"></i> ইনভয়েস নং</div>
                <?php if($role === 'admin'): ?>
                    <div style="font-size:.7rem; font-weight:600; margin-top:.25rem; color:var(--sk-danger);"><i class="fas fa-pen-alt"></i> অ্যাডমিন কন্ট্রোল</div>
                <?php else: ?>
                    <div style="font-size:.7rem; font-weight:600; margin-top:.25rem; color:var(--sk-muted);">অটো সিরিয়াল</div>
                <?php endif; ?>
            </div>
            <input type="text" id="pos_invoice_no" value="<?= $nextInvoiceNo ?>"
                   class="sk-input sk-mono"
                   style="font-weight:800; color:var(--sk-primary); text-align:right; width:210px; <?= ($role!=='admin')?'background:var(--sk-surface-3);cursor:not-allowed;':'' ?>"
                   <?= ($role === 'admin') ? '' : 'readonly' ?>>
        </div>
    </div>

    <!-- ══ STICKY: Customer Name + Search ══ -->
    <div class="pos-sticky" id="posSticky">

        <!-- Customer Name -->
        <div class="sk-field">
            <div class="sk-input-wrap">
                <i class="fas fa-user-tag"></i>
                <input type="text" id="pos_customer_name" maxlength="120"
                       placeholder="👤 কাস্টমার নাম (ঐচ্ছিক)"
                       class="sk-input sk-input--icon">
            </div>
        </div>

        <!-- Search Box -->
        <div class="sk-field" style="position:relative; margin-bottom:0;">
            <div class="sk-search">
                <div class="sk-input-wrap sk-grow">
                    <i class="fas fa-barcode"></i>
                    <input type="text" id="productSearchInput" autocomplete="off"
                           placeholder="🔍 কোড বা নাম লিখুন..."
                           class="sk-input sk-input--icon">
                </div>
                <button type="button" onclick="openAutoScanner()" class="sk-btn sk-btn--ink"><i class="fas fa-qrcode"></i></button>
            </div>
            <div id="searchResultsDropdown" class="sk-search-results"></div>
        </div>
    </div>
    <!-- ══ STICKY END ══ -->

    <div class="sk-card sk-card--pad-lg" style="margin-top:.75rem;">

        <!-- Scanner Modal -->
        <div id="scannerModal" class="sk-modal">
            <div class="sk-modal__sheet">
                <div class="sk-modal__head">
                    <div class="sk-modal__title"><i class="fas fa-qrcode"></i> লাইভ স্ক্যানার</div>
                    <button class="sk-modal__close" onclick="closeAutoScanner()"><i class="fas fa-times"></i></button>
                </div>
                <div id="reader" class="sk-scanner"></div>
                <div style="text-align:center; margin-top:.75rem;">
                    <span class="sk-scanner__hint"><i class="fas fa-info-circle"></i> বারকোড ক্যামেরার সামনে ধরুন</span>
                </div>
            </div>
        </div>

        <!-- Cart -->
        <div id="cartArea" style="display:none; margin-top:.5rem;">
            <div class="sk-section-title">
                <h2><i class="fas fa-shopping-cart"></i> আপনার কার্ট</h2>
                <span class="sk-sub">Items</span>
            </div>
            <div class="sk-table-wrap" style="margin-bottom:.875rem; overflow-x:auto;">
                <table class="sk-table" style="min-width:560px;">
                    <thead>
                        <tr>
                            <th>পণ্য ও ছবি</th>
                            <th style="text-align:center;">রেট (৳)</th>
                            <th style="text-align:center;">পিস</th>
                            <th style="text-align:right;">মোট (৳)</th>
                            <th style="text-align:center;">বাদ</th>
                        </tr>
                    </thead>
                    <tbody id="cartTableBody"></tbody>
                </table>
            </div>

            <div class="sk-card sk-card--primary" style="padding:1rem; display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <div style="font-size:.7rem; font-weight:700; letter-spacing:.12em; text-transform:uppercase; color:rgba(255,255,255,.85);">সর্বমোট বিল</div>
                    <div id="grandTotalDisplay" style="font-size:1.6rem; font-weight:800; margin-top:.125rem; color:#fff;">৳ 0.00</div>
                </div>
                <button onclick="submitSale()" id="submitSaleBtn" class="sk-btn sk-btn--lg" style="background:#fff; color:var(--sk-primary); font-weight:700;">
                    <i class="fas fa-check-circle"></i> সাবমিট
                </button>
            </div>
        </div>

        <div id="emptyCartMsg" class="sk-empty">
            <i class="fas fa-cart-arrow-down"></i>
            <p style="color:var(--sk-ink); font-size:.95rem;">কোনো পণ্য কার্টে নেই</p>
            <p style="font-size:.75rem; margin-top:.25rem;">স্ক্যান বা সার্চ করে পণ্য যুক্ত করুন</p>
        </div>
    </div>
</main>

<script>
const userCsrfToken = '<?= $csrfToken ?>';
const userRole      = '<?= $role ?>';

// ── Location Colors (DB থেকে PHP → JS) ──────────────────────
// সব ডিভাইসে একই কালার দেখাবে
const locationColors = <?= json_encode($locationColors, JSON_UNESCAPED_UNICODE) ?>;

/**
 * যেকোনো জায়গায় location badge রেন্ডার করে।
 * StorageLocationHelper::renderBadge() এর JS সমতুল্য।
 */
function renderLocationBadge(location) {
    const c = locationColors[location] || { label: location || '?', color:'#6b7280', bg:'#f3f4f6', icon:'fa-box' };
    return `<span class="loc-badge" style="border-color:${c.color};background:${c.bg};color:${c.color};">
                <i class="fas ${c.icon}" style="font-size:.6rem;"></i> ${c.label}
            </span>`;
}

// ── Scanner ──────────────────────────────────────────────────
let html5QrCodePOS, isScannerRunning = false;

function openAutoScanner() {
    document.getElementById('scannerModal').classList.add('open');
    if (!html5QrCodePOS) html5QrCodePOS = new Html5Qrcode("reader");
    html5QrCodePOS.start({ facingMode:"environment" }, { fps:15, qrbox:{width:250,height:120} },
        (decodedText) => {
            if (isScannerRunning) {
                isScannerRunning = false;
                $.ajax({
                    url:'inventory_pos.php', type:'POST',
                    data:{ ajax_action:'scan_barcode', csrf_token:userCsrfToken, product_code:decodedText },
                    dataType:'json',
                    success: function(res) {
                        if (res.status === 'success') addToCart(res.product);
                        else { alert("বারকোড " + decodedText + " এর কোনো পণ্য স্টকে নেই!"); }
                        html5QrCodePOS.stop().then(() => document.getElementById('scannerModal').classList.remove('open')).catch(()=>{});
                    }
                });
            }
        }, ()=>{}
    ).then(() => { isScannerRunning = true; })
     .catch(() => { alert("ক্যামেরা এক্সেস পাওয়া যায়নি!"); closeAutoScanner(); });
}

function closeAutoScanner() {
    if (html5QrCodePOS && isScannerRunning) {
        html5QrCodePOS.stop()
            .then(() => { isScannerRunning = false; document.getElementById('scannerModal').classList.remove('open'); })
            .catch(() => document.getElementById('scannerModal').classList.remove('open'));
    } else { document.getElementById('scannerModal').classList.remove('open'); }
}

// ── Search ───────────────────────────────────────────────────
let saleCartList = [];
const searchInput    = document.getElementById('productSearchInput');
const searchDropdown = document.getElementById('searchResultsDropdown');
let typingTimer;
const doneTypingInterval = 200;

$('#productSearchInput').on('keyup', function(e) {
    clearTimeout(typingTimer);
    const query = $(this).val().trim();
    if (e.key === 'Enter') {
        e.preventDefault();
        const first = document.querySelector('.search-item');
        if (first) first.click();
        return;
    }
    if (query.length > 0) {
        typingTimer = setTimeout(function() {
            $.ajax({
                url:'inventory_pos.php', type:'POST',
                data:{ ajax_action:'search_product', csrf_token:userCsrfToken, query:query },
                dataType:'json',
                success: function(data) {
                    searchDropdown.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(item => {
                            const imgSrc  = item.image_path || '';
                            const imgHtml = imgSrc
                                ? `<img src="${imgSrc}" style="width:46px;height:46px;object-fit:cover;border-radius:.5rem;border:1px solid var(--sk-line);flex-shrink:0;">`
                                : `<div style="width:46px;height:46px;border-radius:.5rem;background:var(--sk-surface-3);display:inline-flex;align-items:center;justify-content:center;color:var(--sk-muted);flex-shrink:0;"><i class="fas fa-image"></i></div>`;
                            const minPrice = parseFloat(item.buy_price) + parseFloat(item.cost);

                            // ── Location Badge (StorageLocationHelper সমতুল্য) ──
                            const locBadge = renderLocationBadge(item.item_location);

                            searchDropdown.innerHTML += `
                            <div class="search-item" onclick='addToCart(${JSON.stringify(item)})'>
                                <div style="display:flex;align-items:center;gap:.625rem;min-width:0;">
                                    ${imgHtml}
                                    <div style="min-width:0;">
                                        <div style="font-weight:700;color:var(--sk-ink);line-height:1.2;">${item.name}</div>
                                        <div style="font-size:.75rem;font-weight:600;color:var(--sk-primary);">${item.product_code}</div>
                                        <div style="margin-top:.2rem;display:flex;gap:.3rem;align-items:center;flex-wrap:wrap;">
                                            ${locBadge}
                                            <span class="sk-pill sk-pill--danger" style="font-size:.65rem;">কেনা+খরচ: ৳${minPrice.toFixed(2)}</span>
                                        </div>
                                    </div>
                                </div>
                                <div style="text-align:right;display:flex;flex-direction:column;gap:.25rem;align-items:flex-end;flex-shrink:0;">
                                    <span class="sk-pill sk-pill--accent">৳${parseFloat(item.cash_sell).toFixed(2)}</span>
                                    <span class="sk-pill sk-pill--warn">${item.pieces} পিস</span>
                                </div>
                            </div>`;
                        });
                        searchDropdown.style.display = 'block';
                        if (data.length === 1 && data[0].product_code === query) {
                            document.querySelector('.search-item').click();
                        }
                    } else {
                        searchDropdown.innerHTML = '<div style="padding:1rem;text-align:center;font-weight:700;color:var(--sk-danger);">কোনো পণ্য পাওয়া যায়নি!</div>';
                        searchDropdown.style.display = 'block';
                    }
                }
            });
        }, doneTypingInterval);
    } else { searchDropdown.style.display = 'none'; }
});

document.addEventListener('click', function(e) {
    if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target))
        searchDropdown.style.display = 'none';
});

// ── Cart ─────────────────────────────────────────────────────
function addToCart(product) {
    searchInput.value = ''; searchDropdown.style.display = 'none'; searchInput.focus();
    const existing         = saleCartList.find(i => i.product_code === product.product_code);
    const defaultQty       = 8;
    const minAllowablePrice = parseFloat(product.buy_price) + parseFloat(product.cost);

    if (existing) {
        if ((existing.qty + defaultQty) <= parseInt(product.pieces)) existing.qty += defaultQty;
        else { alert('স্টকে পর্যাপ্ত পণ্য নেই!'); return; }
    } else {
        const qtyToAdd = Math.min(defaultQty, parseInt(product.pieces));
        if (qtyToAdd > 0) {
            saleCartList.push({
                product_code:  product.product_code,
                name:          product.name,
                image:         product.image_path,
                price:         parseFloat(product.cash_sell),
                min_price:     minAllowablePrice,
                max_pieces:    parseInt(product.pieces),
                qty:           qtyToAdd,
                item_location: product.item_location || '', // ← location সংরক্ষণ
            });
        } else { alert('স্টক শেষ!'); return; }
    }
    try { new Audio('https://www.soundjay.com/buttons/sounds/button-09.mp3').play().catch(()=>{}); } catch(e){}
    renderCart();
}

function updatePrice(index, newPrice) {
    newPrice = parseFloat(newPrice);
    if (isNaN(newPrice)) newPrice = saleCartList[index].price;
    if (userRole !== 'admin' && newPrice < saleCartList[index].min_price) {
        alert(`কেনা+খরচের (৳${saleCartList[index].min_price.toFixed(2)}) নিচে বিক্রি করা যাবে না!`);
        newPrice = saleCartList[index].min_price;
    }
    saleCartList[index].price = newPrice;
    renderCart();
}
function updateQty(index, newQty) {
    newQty = parseInt(newQty);
    if (isNaN(newQty) || newQty < 1) newQty = 1;
    if (newQty > saleCartList[index].max_pieces) {
        alert(`স্টকে মাত্র ${saleCartList[index].max_pieces} পিস আছে!`);
        newQty = saleCartList[index].max_pieces;
    }
    saleCartList[index].qty = newQty; renderCart();
}
function removeFromCart(index) { saleCartList.splice(index, 1); renderCart(); }

function renderCart() {
    const cartArea         = document.getElementById('cartArea');
    const emptyMsg         = document.getElementById('emptyCartMsg');
    const tbody            = document.getElementById('cartTableBody');
    const grandTotalDisplay = document.getElementById('grandTotalDisplay');

    if (saleCartList.length === 0) {
        cartArea.style.display = 'none'; emptyMsg.style.display = 'block'; return;
    }
    cartArea.style.display = 'block'; emptyMsg.style.display = 'none';
    tbody.innerHTML = '';
    let grandTotal = 0;

    saleCartList.forEach((item, index) => {
        const itemTotal = item.price * item.qty;
        grandTotal += itemTotal;
        const imgHtml = item.image
            ? `<img src="${item.image}" style="width:42px;height:42px;object-fit:cover;border-radius:.375rem;border:1px solid var(--sk-line);">`
            : `<div style="width:42px;height:42px;border-radius:.375rem;background:var(--sk-surface-3);display:inline-flex;align-items:center;justify-content:center;color:var(--sk-muted);"><i class="fas fa-image"></i></div>`;

        // ── Location Badge কার্টেও দেখাবে ──
        const locBadge = renderLocationBadge(item.item_location);

        tbody.innerHTML += `
        <tr>
            <td>
                <div style="display:flex;align-items:center;gap:.5rem;">
                    ${imgHtml}
                    <div>
                        <div style="font-weight:700;color:var(--sk-ink);font-size:.85rem;line-height:1.2;">${item.name}</div>
                        <div style="font-size:.7rem;font-weight:600;color:var(--sk-primary);">${item.product_code}</div>
                        <div style="margin-top:.2rem;display:flex;gap:.3rem;align-items:center;flex-wrap:wrap;">
                            ${locBadge}
                            <span class="sk-pill sk-pill--danger" style="font-size:.6rem;">কেনা: ৳${item.min_price.toFixed(2)}</span>
                        </div>
                    </div>
                </div>
            </td>
            <td style="text-align:center;">
                <input type="number" step="0.01" min="${item.min_price}" value="${item.price}"
                       onchange="updatePrice(${index}, this.value)"
                       class="sk-input" style="width:84px;padding:.375rem .5rem;text-align:center;font-size:.85rem;">
            </td>
            <td style="text-align:center;">
                <input type="number" min="1" max="${item.max_pieces}" value="${item.qty}"
                       onchange="updateQty(${index}, this.value)"
                       class="sk-input" style="width:68px;padding:.375rem .5rem;text-align:center;font-size:.85rem;">
            </td>
            <td style="text-align:right;font-weight:800;color:var(--sk-primary);">৳ ${itemTotal.toFixed(2)}</td>
            <td style="text-align:center;">
                <button onclick="removeFromCart(${index})" class="sk-btn sk-btn--danger sk-btn--sm" style="padding:.375rem .625rem;">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </td>
        </tr>`;
    });
    grandTotalDisplay.innerText = `৳ ${grandTotal.toFixed(2)}`;
}

// ── Submit Sale ───────────────────────────────────────────────
function submitSale() {
    const invoiceInput = document.getElementById('pos_invoice_no').value.trim();
    if (saleCartList.length === 0) return;
    if (invoiceInput === '') { alert("ইনভয়েস নম্বর ফাঁকা রাখা যাবে না!"); return; }
    if (!confirm('বিলটি সাবমিট করতে চান?')) return;

    const btn = document.getElementById('submitSaleBtn');
    const origHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> প্রসেসিং...';
    btn.disabled = true;

    $.ajax({
        url:'inventory_pos.php', type:'POST',
        data:{
            ajax_action:'submit_sale',
            csrf_token:   userCsrfToken,
            invoice_no:   invoiceInput,
            customer_name: document.getElementById('pos_customer_name').value.trim(),
            cart_data:    JSON.stringify(saleCartList)
        },
        dataType:'json',
        success: function(res) {
            if (res.status === 'success') {
                confetti({ particleCount:150, spread:80, origin:{ y:0.6 } });
                alert(res.message + '\nইনভয়েস: ' + res.invoice_no);
                const parts = invoiceInput.split('-');
                const num   = parseInt(parts[parts.length - 1]);
                if (!isNaN(num)) { parts[parts.length - 1] = num + 1; document.getElementById('pos_invoice_no').value = parts.join('-'); }
                saleCartList = [];
                document.getElementById('pos_customer_name').value = '';
                renderCart();
                searchInput.focus();
            } else { alert('Error: ' + res.message); }
        },
        complete: function() { btn.innerHTML = origHtml; btn.disabled = false; }
    });
}
</script>

<style>body{padding-bottom:76px;}</style>
<?php include 'inventory_bottom_nav.php'; ?>
</body>
</html>
