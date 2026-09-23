<?php
declare(strict_types=1);

// ══════════════════════════════════════════════════════════════════
//  SADA KALO FASHION — Inventory Items Page
//  Security: CSRF, PDO, XSS-escape, Session timeout, Sec-Headers
// ══════════════════════════════════════════════════════════════════

ob_start(); // ensure output buffering is active for AJAX ob_clean()

// ── 1. SECURITY HEADERS ──────────────────────────────────────────
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── 2. SESSION TIMEOUT (20 min) ──────────────────────────────────
const SESSION_TIMEOUT_SECONDS = 1200;
$lastActivity = $_SESSION['LAST_ACTIVITY'] ?? null;
if (is_int($lastActivity) && (time() - $lastActivity) > SESSION_TIMEOUT_SECONDS) {
    session_unset();
    session_destroy();
    header('Location: ../index.php');
    exit;
}
$_SESSION['LAST_ACTIVITY'] = time();

// ── 3. AUTH GUARD ────────────────────────────────────────────────
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: ../index.php');
    exit;
}

// ── 4. CSRF TOKEN ────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

// ── 5. DATABASE ──────────────────────────────────────────────────
$dbPath = __DIR__ . '/../db_connect.php';
if (!file_exists($dbPath)) {
    http_response_code(503);
    exit('Database configuration not found.');
}
require_once $dbPath;
/** @var PDO $conn */

// ── 6. USER CONTEXT ──────────────────────────────────────────────
$rawRole  = isset($_SESSION['role']) && is_string($_SESSION['role']) ? $_SESSION['role'] : 'user';
$userRole = strtolower(trim($rawRole));
$isAdmin  = ($userRole === 'admin');
$userId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$username = isset($_SESSION['username']) && is_string($_SESSION['username'])
            ? htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') : 'User';

// ── 7. HELPERS / AUDIT ───────────────────────────────────────────
require_once __DIR__ . '/Helpers/AuditInit.php';
require_once __DIR__ . '/Helpers/StorageLocationHelper.php';
AuditInit::boot($conn);

// ── 8. CONSTANTS ─────────────────────────────────────────────────
const ALLOWED_LOCATIONS = ['shop', 'godown', 'missing', 'damaged'];

// ── 9. HELPER FUNCTIONS ──────────────────────────────────────────
/**
 * Send JSON response and terminate. Cleans any buffered output first.
 */
function jsonOut(array $data): never {
    ob_clean();
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Constant-time CSRF token comparison.
 */
function validateCsrf(string $submitted): bool {
    global $csrfToken;
    return $submitted !== '' && hash_equals($csrfToken, $submitted);
}

/**
 * Write to the application error log safely.
 */
function appLog(string $message): void {
    $logDir = __DIR__ . '/../Logs';
    @mkdir($logDir, 0755, true);
    @file_put_contents(
        $logDir . '/error_log.txt',
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// ══════════════════════════════════════════════════════════════════
//  AJAX DISPATCH
// ══════════════════════════════════════════════════════════════════
$ajaxAction = isset($_POST['ajax_action']) && is_string($_POST['ajax_action'])
              ? trim($_POST['ajax_action']) : '';

// ─────────────────────────────────────────────────────────────────
//  A. UPDATE LOCATION
// ─────────────────────────────────────────────────────────────────
if ($ajaxAction === 'update_location') {
    $postCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!validateCsrf($postCsrf)) {
        jsonOut(['status' => 'error', 'message' => 'Security token mismatch!']);
    }

    $pCode    = isset($_POST['product_code']) && is_string($_POST['product_code']) ? trim($_POST['product_code']) : '';
    $location = isset($_POST['location'])     && is_string($_POST['location'])     ? trim($_POST['location'])     : '';
    $userNote = isset($_POST['note'])         && is_string($_POST['note'])          ? trim($_POST['note'])         : '';

    if ($pCode === '' || !in_array($location, ALLOWED_LOCATIONS, true)) {
        jsonOut(['status' => 'error', 'message' => 'সঠিক তথ্য দিন!']);
    }

    try {
        $msg = StorageLocationHelper::updateStatus(
            $conn, $pCode, $location,
            $userId,
            (string)($_SESSION['username'] ?? 'User'),
            $userNote !== '' ? $userNote : null
        );
        jsonOut(['status' => 'success', 'message' => $msg, 'new_location' => $location]);
    } catch (Exception $e) {
        jsonOut(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// ─────────────────────────────────────────────────────────────────
//  B. LOAD TIMELINE  (Admin Only)
// ─────────────────────────────────────────────────────────────────
if ($ajaxAction === 'load_timeline') {
    if (!$isAdmin) {
        jsonOut(['status' => 'error', 'message' => 'শুধু অ্যাডমিন দেখতে পারবে!']);
    }
    $postCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!validateCsrf($postCsrf)) {
        jsonOut(['status' => 'error', 'message' => 'Security token mismatch!']);
    }
    $pCode = isset($_POST['product_code']) && is_string($_POST['product_code']) ? trim($_POST['product_code']) : '';
    if ($pCode === '') {
        jsonOut(['status' => 'error', 'message' => 'পণ্য কোড নেই!']);
    }
    $logs = StorageLocationHelper::fetchTimeline($conn, $pCode, 50);
    jsonOut(['status' => 'success', 'logs' => $logs]);
}

// ─────────────────────────────────────────────────────────────────
//  C. FULL PRODUCT UPDATE  (Admin Only)
// ─────────────────────────────────────────────────────────────────
if ($ajaxAction === 'update_full_product') {
    $postCsrf = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? trim($_POST['csrf_token']) : '';
    if (!validateCsrf($postCsrf)) {
        jsonOut(['status' => 'error', 'message' => 'Security token mismatch!']);
    }
    if (!$isAdmin) {
        jsonOut(['status' => 'error', 'message' => 'অ্যাক্সেস ডিনাইড!']);
    }

    $pCode       = isset($_POST['product_code']) && is_string($_POST['product_code']) ? trim($_POST['product_code'])  : '';
    $newName     = isset($_POST['name'])         && is_string($_POST['name'])          ? trim($_POST['name'])          : '';
    $newBuy      = isset($_POST['buy_price'])    && is_numeric($_POST['buy_price'])    ? (float)$_POST['buy_price']   : -1.0;
    $newCost     = isset($_POST['cost'])         && is_numeric($_POST['cost'])         ? (float)$_POST['cost']        : -1.0;
    $newCashSell = isset($_POST['cash_sell'])    && is_numeric($_POST['cash_sell'])    ? (float)$_POST['cash_sell']   : -1.0;

    if ($pCode === '' || $newName === '' || $newBuy < 0.0 || $newCost < 0.0 || $newCashSell < 0.0) {
        jsonOut(['status' => 'error', 'message' => 'সব ঘর সঠিকভাবে পূরণ করুন!']);
    }

    try {
        $conn->beginTransaction();

        $stmtOld = $conn->prepare('SELECT name, buy_price, cost, cash_sell FROM inventory WHERE product_code = ? FOR UPDATE');
        $stmtOld->execute([$pCode]);
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);
        if (!$oldData) {
            throw new Exception('পণ্যটি পাওয়া যায়নি!');
        }

        // Build change log
        $changes = [];
        if ($oldData['name'] !== $newName)
            $changes[] = "নাম: [{$oldData['name']}] → [{$newName}]";
        if ((float)$oldData['buy_price'] !== $newBuy)
            $changes[] = "ক্রয়: ৳" . (float)$oldData['buy_price'] . " → ৳" . $newBuy;
        if ((float)$oldData['cost'] !== $newCost)
            $changes[] = "খরচ: ৳" . (float)$oldData['cost'] . " → ৳" . $newCost;
        if ((float)$oldData['cash_sell'] !== $newCashSell)
            $changes[] = "বিক্রি: ৳" . (float)$oldData['cash_sell'] . " → ৳" . $newCashSell;

        if (!empty($changes)) {
            $stmtLog = $conn->prepare('INSERT INTO product_edit_history (product_code, changes_details, changed_by) VALUES (?, ?, ?)');
            $stmtLog->execute([$pCode, implode(' | ', $changes), $userId]);
        }

        $stmtUpdate = $conn->prepare('UPDATE inventory SET name = ?, buy_price = ?, cost = ?, cash_sell = ? WHERE product_code = ?');
        $stmtUpdate->execute([$newName, $newBuy, $newCost, $newCashSell, $pCode]);
        $conn->commit();

        if (class_exists('AuditLogger')) {
            AuditLogger::update(
                'inventory', $pCode,
                ['product_code' => $pCode, 'name' => $oldData['name'], 'buy_price' => (float)$oldData['buy_price'], 'cost' => (float)$oldData['cost'],  'cash_sell' => (float)$oldData['cash_sell']],
                ['product_code' => $pCode, 'name' => $newName,         'buy_price' => $newBuy,                       'cost' => $newCost,                  'cash_sell' => $newCashSell],
                "পণ্য এডিট — {$pCode}"
            );
        }

        jsonOut(['status' => 'success', 'message' => 'পণ্যটি সফলভাবে আপডেট হয়েছে!']);

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        appLog('Edit Error [' . $pCode . ']: ' . $e->getMessage());
        jsonOut(['status' => 'error', 'message' => 'ডাটাবেস আপডেটে সমস্যা হয়েছে।']);
    }
}

// ─────────────────────────────────────────────────────────────────
//  D. LOAD CATEGORIES
// ─────────────────────────────────────────────────────────────────
if ($ajaxAction === 'load_categories') {
    try {
        $catStmt    = $conn->query("SELECT id, name FROM categories WHERE status = 'active' ORDER BY name ASC");
        $categories = ($catStmt instanceof PDOStatement) ? $catStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Exception $e) {
        $categories = [];
    }
    $catOptions = '<option value="all">সব ক্যাটাগরি</option>';
    foreach ($categories as $cat) {
        $cId   = isset($cat['id'])   ? (int)$cat['id']   : 0;
        $cName = isset($cat['name']) ? htmlspecialchars((string)$cat['name'], ENT_QUOTES, 'UTF-8') : '';
        $catOptions .= '<option value="' . $cId . '">' . $cName . '</option>';
    }
    $catOptions .= '<option value="none">ক্যাটাগরি নেই</option>';
    jsonOut(['html' => $catOptions]);
}

// ─────────────────────────────────────────────────────────────────
//  E. LOAD ITEMS TABLE  (returns card HTML)
// ─────────────────────────────────────────────────────────────────
if ($ajaxAction === 'load_items_table') {
    // -- Filter inputs (all sanitised / validated) --
    $page           = isset($_POST['page'])            ? max(1, (int)$_POST['page'])                                                                    : 1;
    $perPage        = 20;
    $searchValue    = isset($_POST['search'])          && is_string($_POST['search'])          ? trim($_POST['search'])          : '';
    $catFilter      = isset($_POST['category_filter']) && is_string($_POST['category_filter']) ? trim($_POST['category_filter']) : 'all';
    $stockFilter    = isset($_POST['stock_filter'])    && is_string($_POST['stock_filter'])    ? trim($_POST['stock_filter'])    : 'all';
    $locationFilter = isset($_POST['location_filter']) && is_string($_POST['location_filter']) ? trim($_POST['location_filter']) : 'all';

    $whereParts = [];
    $params     = [];

    // Search
    if ($searchValue !== '') {
        $whereParts[] = '(i.product_code LIKE ? OR i.name LIKE ? OR c.name LIKE ?)';
        $w = '%' . $searchValue . '%';
        array_push($params, $w, $w, $w);
    }

    // Category filter
    if ($catFilter !== 'all' && $catFilter !== '') {
        if ($catFilter === 'none') {
            $whereParts[] = '(i.category_id IS NULL OR i.category_id = 0)';
        } else {
            $whereParts[] = 'i.category_id = ?';
            $params[]     = (int)$catFilter;
        }
    }

    // Stock filter
    if ($stockFilter === 'low') {
        $whereParts[] = 'i.pieces > 0 AND i.pieces < 10';
    } elseif ($stockFilter === 'zero') {
        $whereParts[] = 'i.pieces <= 0';
    } elseif ($stockFilter === 'high') {
        $whereParts[] = 'i.pieces >= 10';
    }

    // Location filter
    if ($locationFilter !== 'all' && in_array($locationFilter, ALLOWED_LOCATIONS, true)) {
        $whereParts[] = 'i.item_location = ?';
        $params[]     = $locationFilter;
    }

    $whereSql = empty($whereParts) ? '1=1' : implode(' AND ', $whereParts);

    // Accent colour per location (Nocturne palette)
    $locationAccents = [
        'shop'    => '#9184d9',
        'godown'  => '#ea580c',
        'missing' => '#ef4444',
        'damaged' => '#eab308',
    ];

    try {
        // Count & total pieces
        $stmtCount = $conn->prepare("SELECT COUNT(i.id) FROM inventory i LEFT JOIN categories c ON i.category_id = c.id WHERE {$whereSql}");
        $stmtCount->execute($params);
        $filtered = (int)$stmtCount->fetchColumn();

        $stmtSum = $conn->prepare("SELECT COALESCE(SUM(i.pieces), 0) FROM inventory i LEFT JOIN categories c ON i.category_id = c.id WHERE {$whereSql}");
        $stmtSum->execute($params);
        $totalPieces = (int)$stmtSum->fetchColumn();

        $totalPages = max(1, (int)ceil($filtered / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        // Fetch items
        $stmtItems = $conn->prepare(
            "SELECT i.*, c.name AS cat_name, u.username AS entry_by, i.item_location
             FROM inventory i
             LEFT JOIN categories c ON i.category_id = c.id
             LEFT JOIN users u ON i.added_by = u.id
             WHERE {$whereSql}
             ORDER BY (i.pieces <= 0) ASC, i.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmtItems->execute($params);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // ── Build Card HTML ──────────────────────────────────────
        $html = '';

        if (count($items) === 0) {
            $html  = '<div class="empty-state">';
            $html .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="44"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
            $html .= '<p>কোনো পণ্য পাওয়া যায়নি!</p></div>';
        } else {
            foreach ($items as $item) {
                // Raw values
                $pCode    = (string)($item['product_code'] ?? '');
                $pName    = (string)($item['name']         ?? '');
                $catName  = (string)($item['cat_name']     ?? 'ক্যাটাগরি নেই');
                $entryBy  = (string)($item['entry_by']     ?? 'Unknown');
                $imgPath  = !empty($item['image_path'])    ? (string)$item['image_path'] : '';
                $buyP     = (float)($item['buy_price']     ?? 0);
                $costP    = (float)($item['cost']          ?? 0);
                $cashP    = (float)($item['cash_sell']     ?? 0);
                $totalBuy = $buyP + $costP;                          // total acquisition cost
                $stock    = (int)($item['pieces']          ?? 0);
                $location = in_array($item['item_location'] ?? '', ALLOWED_LOCATIONS, true)
                            ? $item['item_location'] : 'shop';

                // SECURITY: JS-safe encoding via json_encode (prevents XSS in onclick)
                $jsCode = json_encode($pCode,    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
                $jsImg  = json_encode($imgPath,  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
                // FIX: json_encode() wraps the value in real double-quote (") characters.
                // Those were being dropped straight into a double-quoted onclick="" attribute,
                // which cut the attribute short at the first quote and silently broke the
                // click handler (this is why the image/timeline buttons did nothing).
                // htmlspecialchars() on top turns those quotes into &quot; so the HTML
                // attribute stays valid while the browser still unescapes it back to a
                // normal JS string before running the onclick code.
                $aCode  = htmlspecialchars($jsCode, ENT_QUOTES, 'UTF-8');
                $aImg   = htmlspecialchars($jsImg,  ENT_QUOTES, 'UTF-8');

                // SECURITY: HTML-safe encoding for attributes/content
                $hCode  = htmlspecialchars($pCode,   ENT_QUOTES, 'UTF-8');
                $hName  = htmlspecialchars($pName,   ENT_QUOTES, 'UTF-8');
                $hCat   = htmlspecialchars($catName, ENT_QUOTES, 'UTF-8');
                $hBy    = htmlspecialchars($entryBy, ENT_QUOTES, 'UTF-8');
                $hImg   = htmlspecialchars($imgPath, ENT_QUOTES, 'UTF-8');

                $accentColor = $locationAccents[$location] ?? '#9184d9';
                $soldOut     = ($stock <= 0);
                $stockClass  = $soldOut ? 'stock-zero' : ($stock < 10 ? 'stock-low' : 'stock-ok');

                // ── Card wrapper ─────────────────────────────────
                $html .= '<div class="product-card' . ($soldOut ? ' sold-out' : '') . '">';
                $html .= '<div class="card-accent-bar" style="background:' . $accentColor . '"></div>';

                // ── Left panel ───────────────────────────────────
                $html .= '<div class="card-left">';
                $html .= '<span class="card-code">' . $hCode . '</span>';

                // Thumbnail (secure onclick via json_encode)
                if ($imgPath !== '') {
                    $html .= '<button type="button" class="card-thumb has-img" onclick="openImageModal(' . $aImg . ',' . $aCode . ')" title="ছবি বড় করুন">'
                           . '<img src="' . $hImg . '" alt="" loading="lazy">'
                           . '</button>';
                } else {
                    $html .= '<button type="button" class="card-thumb no-img" onclick="openImageModal(null,' . $aCode . ')">'
                           . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="22"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
                           . '</button>';
                }

                // Timeline button (backend enforces admin-only)
                $html .= '<button type="button" class="card-timeline-btn" onclick="showTimeline(' . $aCode . ')">'
                       . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
                       . ' টাইমলাইন</button>';
                $html .= '</div>'; // .card-left

                // ── Body ─────────────────────────────────────────
                $html .= '<div class="card-body">';

                // Header row: name + sold-out badge
                $html .= '<div class="card-header-row">';
                $html .= '<div class="card-name-group">'
                       . '<div class="card-name">' . $hName . '</div>'
                       . '<div class="card-sub">' . $hCat . ' · ' . $hBy . '</div>'
                       . '</div>';
                if ($soldOut) {
                    $html .= '<span class="badge-sold-out">'
                           . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="10"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>'
                           . ' শেষ</span>';
                }
                $html .= '</div>'; // .card-header-row

                // Price + stock pills
                $html .= '<div class="card-pills">'
                       . '<span class="pill pill-cost">কেনা <strong>৳' . number_format($totalBuy, 0) . '</strong></span>'
                       . '<span class="pill pill-sell">বিক্রি <strong>৳' . number_format($cashP,    0) . '</strong></span>'
                       . '<span class="pill ' . $stockClass . '">পিস <strong>' . $stock . '</strong></span>'
                       . '</div>';

                // Footer: location select + edit button
                // SECURITY: data-code uses htmlspecialchars, onchange reads dataset (no inline eval)
                $html .= '<div class="card-footer">';
                $html .= '<select class="loc-select loc-' . $location . '" '
                       . 'data-prev="' . $location . '" '
                       . 'data-code="' . $hCode . '" '
                       . 'onchange="updateLocation(this.dataset.code, this.value, this)">'
                       . '<option value="shop"    ' . ($location === 'shop'    ? 'selected' : '') . '>🏪 দোকান</option>'
                       . '<option value="godown"  ' . ($location === 'godown'  ? 'selected' : '') . '>🏭 গোডাউন</option>'
                       . '<option value="missing" ' . ($location === 'missing' ? 'selected' : '') . '>❓ পাইনি</option>'
                       . '<option value="damaged" ' . ($location === 'damaged' ? 'selected' : '') . '>⚠️ ড্যামেজ</option>'
                       . '</select>';

                // SECURITY: edit button uses data-* attributes — NO inline JSON eval
                if ($isAdmin) {
                    $html .= '<button class="btn-edit" '
                           . 'data-code="'  . $hCode . '" '
                           . 'data-name="'  . $hName . '" '
                           . 'data-buy="'   . $buyP   . '" '
                           . 'data-cost="'  . $costP  . '" '
                           . 'data-cash="'  . $cashP  . '" '
                           . 'onclick="openEditFromBtn(this)" title="এডিট">'
                           . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>'
                           . ' এডিট</button>';
                }
                $html .= '</div>'; // .card-footer
                $html .= '</div>'; // .card-body
                $html .= '</div>'; // .product-card
            }
        }

        jsonOut([
            'status'      => 'success',
            'html'        => $html,
            'filtered'    => $filtered,
            'totalPieces' => $totalPieces,
            'page'        => $page,
            'totalPages'  => $totalPages,
        ]);

    } catch (Exception $e) {
        appLog('Load Items Error: ' . $e->getMessage());
        jsonOut(['status' => 'error', 'message' => 'ডেটা লোড সমস্যা!', 'html' => '']);
    }
}

// ══════════════════════════════════════════════════════════════════
//  HTML PAGE  (rendered when no ajax_action is set)
// ══════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="bn" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>পণ্য তালিকা — SADA KALO</title>
    <meta name="theme-color" content="#161826">
    <link rel="icon" href="/logo.png" type="image/png">
    <link rel="manifest" href="/manifest.json">
    <!-- Google Fonts: Inter (numeric/UI) + Hind Siliguri (Bengali) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Font Awesome — required by inventory_bottom_nav.php's <i class="fas fa-*"> icons.
         Without this, the nav renders as plain text with no icons (root cause of the
         "unstyled bottom nav" issue). -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php
    // PWA assets
    $pwaAssets = $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_assets.php';
    if (file_exists($pwaAssets)) { require $pwaAssets; }
    ?>
    <!-- Inline theme flash prevention — runs before first paint -->
    <script>(function(){try{var t=localStorage.getItem('sk-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);else if(window.matchMedia('(prefers-color-scheme:dark)').matches)document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>

    <style>
    /* ═══════════════════════════════════════════════════════════
       NOCTURNE DESIGN SYSTEM — SADA KALO FASHION
       Source: design template v2 · merged with PHP backend
    ═══════════════════════════════════════════════════════════ */

    /* ── Design Tokens (Dark Mode default) ─────────────────── */
    :root {
        /* Backgrounds */
        --color-bg:      #161826;
        --color-surface: #232532;

        /* Text & Dividers */
        --color-text:    #e9e9ed;
        --color-divider: rgba(233, 233, 237, 0.11);

        /* Accent (Blurple — OKLCH hue 289.2) */
        --color-accent:   #9184d9;
        --color-accent-2: #a7a1db;

        /* Tonal ramps */
        --color-neutral-700: #595d6c;
        --color-neutral-800: #3f424d;
        --color-neutral-900: #292b31;
        --color-accent-100:  #f5f4ff;
        --color-accent-800:  #423a6a;

        /* Semantic colours */
        --c-danger:  #f26b6b;
        --c-success: #4eca8b;
        --c-warning: #f5ba49;
        --c-orange:  #ea580c;

        /* Typography */
        --font-bn: 'Hind Siliguri', 'Inter', system-ui, sans-serif;
        --font-ui: 'Inter', system-ui, sans-serif;

        /* Spacing */
        --space-1: 4px;   --space-2: 8px;   --space-3: 12px;
        --space-4: 16px;  --space-6: 24px;

        /* Radii */
        --radius-sm: 6px;
        --radius-md: 10px;
        --radius-lg: 16px;
        --radius-xl: 22px;
        --radius-full: 999px;

        /* Shadows (dark: edge-line + ambient ink) */
        --shadow-sm: 0 0 0 1px #3f424d;
        --shadow-md: 0 0 0 1px #595d6c, 0 4px 14px rgba(0,0,0,.48);

        /* Brand gradient/shadow — used by inventory_bottom_nav.php's
           center "POS" button. Was missing, so that button rendered flat. */
        --sk-grad-brand:   linear-gradient(135deg, var(--color-accent), var(--color-accent-2));
        --sk-shadow-brand: 0 6px 16px rgba(145,132,217,.45);
    }

    /* ── Light Mode Overrides ───────────────────────────────── */
    [data-theme="light"] {
        --color-bg:       #f0f0f8;
        --color-surface:  #ffffff;
        --color-text:     #161826;
        --color-accent:   #6a5fc1;
        --color-accent-2: #7c72c4;
        --color-divider:  rgba(22, 24, 38, 0.09);
        --color-neutral-700: #9397ab;
        --color-neutral-800: #ededf5;
        --color-neutral-900: #e0e0ec;
        --color-accent-100:  #3d3480;
        --color-accent-800:  #ede9ff;
        --c-danger:  #d63a3a;
        --c-success: #1a9e5c;
        --c-warning: #b87c00;
        --shadow-sm: 0 0 0 1px #d4d4e4;
        --shadow-md: 0 0 0 1px #c0c0d4, 0 4px 12px rgba(0,0,0,.08);
        --sk-grad-brand:   linear-gradient(135deg, var(--color-accent), var(--color-accent-2));
        --sk-shadow-brand: 0 6px 16px rgba(106,95,193,.35);
    }

    /* ── Reset ──────────────────────────────────────────────── */
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
    body {
        font-family: var(--font-bn);
        background: var(--color-bg);
        color: var(--color-text);
        min-height: 100vh;
        overflow-x: hidden;
        transition: background .28s ease, color .28s ease;
        padding-bottom: 80px;
    }
    a { text-decoration: none; color: inherit; }
    button { font-family: var(--font-bn); }

    /* ── Scrollbar ──────────────────────────────────────────── */
    ::-webkit-scrollbar { width: 4px; height: 4px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--color-neutral-700); border-radius: 2px; }

    /* ── Spinner ────────────────────────────────────────────── */
    @keyframes _spin { to { transform: rotate(360deg); } }
    .spinner {
        display: inline-block;
        width: 30px; height: 30px; border-radius: 50%;
        border: 3px solid rgba(145,132,217,.2);
        border-top-color: var(--color-accent);
        animation: _spin .7s linear infinite;
    }
    .spinner-wrap { display: flex; justify-content: center; padding: 3rem 0; }

    /* ══════════════════════════════════════════════════════════
       HEADER
    ══════════════════════════════════════════════════════════ */
    .app-header {
        position: sticky; top: 0; z-index: 100;
        background: var(--color-surface);
        border-bottom: 1px solid var(--color-divider);
        box-shadow: var(--shadow-md);
    }
    .app-header__top {
        display: flex; align-items: center; gap: 8px;
        padding: 10px 14px;
    }
    .app-logo {
        width: 34px; height: 34px; border-radius: var(--radius-md); flex: none;
        display: grid; place-items: center;
        background: conic-gradient(from 210deg, #3a3550, #12131c);
        box-shadow: 0 0 0 1.5px rgba(145,132,217,.4);
    }
    .app-logo svg { width: 17px; height: 17px; }
    .app-brand { flex: 1; min-width: 0; overflow: hidden; }
    .app-brand__name {
        font-family: var(--font-ui); font-weight: 800; font-size: 13.5px;
        letter-spacing: .12em; color: var(--color-text); line-height: 1;
    }
    .app-brand__sub {
        font-size: 10px; margin-top: 2px;
        color: rgba(233,233,237,.45); line-height: 1;
    }
    [data-theme="light"] .app-brand__sub { color: rgba(22,24,38,.4); }

    .icon-btn {
        width: 34px; height: 34px; flex: none;
        border-radius: var(--radius-md); border: 1px solid var(--color-divider);
        background: transparent; color: var(--color-text);
        display: grid; place-items: center; cursor: pointer;
        transition: background .18s;
    }
    .icon-btn:hover { background: var(--color-neutral-800); }
    .icon-btn svg { width: 16px; height: 16px; }
    .icon-btn--round { border-radius: 50%; }

    /* Search */
    .app-header__search {
        display: flex; align-items: center; gap: 9px;
        height: 40px; margin: 0 14px 10px;
        padding: 0 13px;
        border-radius: var(--radius-full);
        background: var(--color-bg);
        border: 1px solid var(--color-divider);
        transition: border-color .18s;
    }
    .app-header__search:focus-within { border-color: var(--color-accent); }
    .app-header__search svg { width: 15px; height: 15px; flex: none; color: rgba(145,132,217,.7); }
    .app-header__search input {
        flex: 1; min-width: 0; border: 0; outline: 0;
        background: transparent; color: var(--color-text);
        font-size: 14px; font-family: var(--font-bn);
    }
    .app-header__search input::placeholder { color: rgba(233,233,237,.38); }
    [data-theme="light"] .app-header__search input::placeholder { color: rgba(22,24,38,.32); }

    /* ══════════════════════════════════════════════════════════
       FILTER BAR
    ══════════════════════════════════════════════════════════ */
    .filter-bar {
        background: var(--color-surface);
        border-bottom: 1px solid var(--color-divider);
        padding: 0 14px 10px;
    }
    /* Location chips */
    .loc-chips {
        display: flex; gap: 6px; overflow-x: auto; padding: 8px 0 4px;
        scrollbar-width: none; -webkit-overflow-scrolling: touch;
    }
    .loc-chips::-webkit-scrollbar { display: none; }
    .chip {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 5px 12px; border-radius: var(--radius-full);
        border: 1.5px solid var(--color-divider);
        background: transparent; color: var(--color-text);
        font-size: 11.5px; font-family: var(--font-bn); font-weight: 600;
        white-space: nowrap; cursor: pointer; transition: all .18s; flex: none;
    }
    .chip svg { width: 11px; height: 11px; }
    .chip.active { border-color: var(--color-accent); background: rgba(145,132,217,.13); color: var(--color-accent); }
    .chip-godown.active  { border-color: var(--c-orange);  background: rgba(234,88,12,.12);    color: var(--c-orange); }
    .chip-missing.active { border-color: var(--c-danger);  background: rgba(242,107,107,.12);  color: var(--c-danger); }
    .chip-damaged.active { border-color: var(--c-warning); background: rgba(245,186,73,.12);   color: var(--c-warning); }

    /* Secondary selects */
    .filter-row2 { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
    .filter-select {
        flex: 1; min-width: 110px;
        padding: 7px 10px; border-radius: var(--radius-md);
        border: 1.5px solid var(--color-divider);
        background: var(--color-bg); color: var(--color-text);
        font-size: 12px; font-family: var(--font-bn); cursor: pointer; outline: none;
    }
    .filter-select:focus { border-color: var(--color-accent); }
    .filter-select option { background: var(--color-surface); }

    /* ══════════════════════════════════════════════════════════
       SUMMARY BAR
    ══════════════════════════════════════════════════════════ */
    .summary-bar {
        display: flex; align-items: center; justify-content: space-between;
        padding: 8px 14px 2px;
    }
    .summary-lbl { font-size: 10.5px; color: rgba(233,233,237,.4); font-family: var(--font-ui); }
    [data-theme="light"] .summary-lbl { color: rgba(22,24,38,.38); }
    .summary-stats { display: flex; gap: 10px; }
    .stat-chip {
        display: flex; align-items: center; gap: 5px;
        font-size: 11px; font-family: var(--font-ui);
        padding: 3px 9px; border-radius: var(--radius-full);
        background: var(--color-neutral-800); color: var(--color-text);
    }
    .stat-chip svg { width: 10px; height: 10px; }
    .stat-chip strong { color: var(--color-accent); }

    /* ══════════════════════════════════════════════════════════
       PRODUCT CARDS
    ══════════════════════════════════════════════════════════ */
    .cards-container {
        padding: 10px 12px; display: flex; flex-direction: column; gap: 9px;
    }

    .product-card {
        display: flex; border-radius: var(--radius-lg);
        background: var(--color-surface);
        border: 1px solid var(--color-divider);
        overflow: hidden; position: relative;
        transition: border-color .2s, box-shadow .2s;
    }
    .product-card:hover { border-color: var(--color-accent); box-shadow: var(--shadow-md); }
    .product-card.sold-out { opacity: .62; }

    .card-accent-bar {
        position: absolute; left: 0; top: 0; bottom: 0; width: 3.5px;
    }

    /* Left panel */
    .card-left {
        flex: none; width: 70px; padding: 11px 6px;
        display: flex; flex-direction: column; align-items: center; gap: 7px;
        border-right: 1px solid var(--color-divider);
    }
    .card-code {
        font-family: var(--font-ui); font-weight: 700; font-size: 9.5px;
        color: var(--color-accent); letter-spacing: .03em;
        text-align: center; word-break: break-all; line-height: 1.25;
    }
    .card-thumb {
        width: 52px; height: 52px; border-radius: var(--radius-md);
        border: 1px solid var(--color-divider); background: var(--color-bg);
        display: grid; place-items: center;
        cursor: pointer; padding: 0; overflow: hidden; transition: border-color .18s;
    }
    .card-thumb:hover { border-color: var(--color-accent); }
    .card-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .card-thumb svg { color: rgba(233,233,237,.28); }
    [data-theme="light"] .card-thumb svg { color: rgba(22,24,38,.25); }
    .card-timeline-btn {
        display: flex; align-items: center; gap: 3px;
        font-size: 9px; font-family: var(--font-bn);
        color: rgba(233,233,237,.42); background: transparent;
        border: 0; cursor: pointer; padding: 0; transition: color .18s;
    }
    [data-theme="light"] .card-timeline-btn { color: rgba(22,24,38,.4); }
    .card-timeline-btn:hover { color: var(--color-accent); }
    .card-timeline-btn svg { width: 10px; height: 10px; }

    /* Body */
    .card-body {
        flex: 1; min-width: 0; padding: 10px 11px 10px 13px;
        display: flex; flex-direction: column; gap: 7px;
    }
    .card-header-row { display: flex; align-items: flex-start; gap: 6px; }
    .card-name-group { flex: 1; min-width: 0; }
    .card-name {
        font-size: 13.5px; font-weight: 600; color: var(--color-text);
        line-height: 1.3; word-break: break-word;
    }
    .card-sub {
        font-size: 10px; color: rgba(233,233,237,.45); margin-top: 2px;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    [data-theme="light"] .card-sub { color: rgba(22,24,38,.42); }

    .badge-sold-out {
        flex: none; display: inline-flex; align-items: center; gap: 3px;
        font-size: 9px; font-weight: 700;
        padding: 2px 7px; border-radius: var(--radius-sm);
        background: rgba(242,107,107,.14); color: var(--c-danger);
        white-space: nowrap;
    }
    .badge-sold-out svg { width: 9px; height: 9px; }

    /* Pills */
    .card-pills { display: flex; flex-wrap: wrap; gap: 5px; }
    .pill {
        font-size: 10.5px; padding: 3px 8px; border-radius: var(--radius-sm);
        line-height: 1.4;
    }
    .pill strong { font-family: var(--font-ui); font-weight: 700; }
    .pill-cost { background: var(--color-neutral-800); color: var(--color-text); }
    .pill-sell { background: var(--color-accent-800); color: var(--color-accent-100); }
    .stock-ok   { background: rgba(78,202,139,.14);  color: var(--c-success); font-weight: 600; }
    .stock-low  { background: rgba(245,186,73,.14);  color: var(--c-warning); font-weight: 600; }
    .stock-zero { background: rgba(242,107,107,.14); color: var(--c-danger);  font-weight: 600; }

    /* Footer */
    .card-footer { display: flex; align-items: center; gap: 7px; }
    .loc-select {
        flex: 1; padding: 5px 8px; border-radius: var(--radius-sm);
        border: 1.5px solid; font-size: 11px; font-family: var(--font-bn);
        font-weight: 600; background: transparent; cursor: pointer; outline: none;
        transition: border-color .18s;
    }
    .loc-select option { background: var(--color-surface); color: var(--color-text); }
    .loc-shop    { border-color: var(--color-accent); color: var(--color-accent); }
    .loc-godown  { border-color: var(--c-orange);  color: var(--c-orange); }
    .loc-missing { border-color: var(--c-danger);  color: var(--c-danger); }
    .loc-damaged { border-color: var(--c-warning); color: var(--c-warning); }

    .btn-edit {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 5px 10px; border-radius: var(--radius-sm);
        background: rgba(145,132,217,.12);
        border: 1px solid rgba(145,132,217,.28);
        color: var(--color-accent); font-size: 11px;
        font-family: var(--font-bn); font-weight: 600;
        cursor: pointer; white-space: nowrap; transition: all .18s;
    }
    .btn-edit:hover { background: rgba(145,132,217,.22); }
    .btn-edit svg { width: 12px; height: 12px; }

    /* ── Empty State ──────────────────────────────────────────── */
    .empty-state {
        text-align: center; padding: 3rem 1rem;
        color: rgba(233,233,237,.38);
        display: flex; flex-direction: column; align-items: center; gap: 10px;
    }
    [data-theme="light"] .empty-state { color: rgba(22,24,38,.32); }
    .empty-state svg { opacity: .55; }
    .empty-state p { font-size: 14px; }

    /* ══════════════════════════════════════════════════════════
       PAGINATION
    ══════════════════════════════════════════════════════════ */
    .pagination {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        padding: 12px 14px; margin: 0 12px 12px;
        background: var(--color-surface);
        border: 1px solid var(--color-divider); border-radius: var(--radius-md);
        box-shadow: var(--shadow-sm);
    }
    .page-btn {
        width: 34px; height: 34px; border-radius: var(--radius-sm);
        border: 1.5px solid var(--color-divider); background: var(--color-bg);
        color: var(--color-text); display: grid; place-items: center; cursor: pointer;
        transition: border-color .18s, color .18s;
    }
    .page-btn:hover:not(:disabled) { border-color: var(--color-accent); color: var(--color-accent); }
    .page-btn:disabled { opacity: .3; cursor: not-allowed; }
    .page-btn svg { width: 14px; height: 14px; }
    .page-info {
        padding: 5px 14px; border-radius: var(--radius-sm);
        background: var(--color-neutral-800);
        font-size: 12px; font-family: var(--font-ui); font-weight: 600;
    }

    /* ══════════════════════════════════════════════════════════
       DRAWER
    ══════════════════════════════════════════════════════════ */
    .sk-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,.58); z-index: 999;
        backdrop-filter: blur(2px);
    }
    .sk-overlay.active { display: block; }
    .sk-drawer {
        position: fixed; left: -292px; top: 0; width: 274px; height: 100%;
        background: var(--color-surface); z-index: 1000; overflow-y: auto;
        transition: left .28s cubic-bezier(.4,0,.2,1);
        border-right: 1px solid var(--color-divider);
    }
    .sk-drawer.open { left: 0; }
    .sk-drawer-head {
        padding: 14px; border-bottom: 1px solid var(--color-divider);
        display: flex; align-items: center; gap: 10px;
    }
    .sk-drawer-close {
        margin-left: auto; background: var(--color-neutral-800); border: none;
        color: var(--color-text); width: 28px; height: 28px;
        border-radius: 50%; cursor: pointer; display: grid; place-items: center;
    }
    .sk-drawer-close svg { width: 13px; height: 13px; }
    .sk-drawer-brand { font-family: var(--font-ui); font-weight: 800; font-size: 13px; }
    .sk-section {
        padding: 10px 14px 4px;
        font-size: 9.5px; font-weight: 700; letter-spacing: .09em;
        color: rgba(233,233,237,.38); text-transform: uppercase;
    }
    [data-theme="light"] .sk-section { color: rgba(22,24,38,.33); }
    .sk-grid {
        display: grid; grid-template-columns: repeat(3,1fr); gap: 4px;
        padding: 4px 10px 12px;
    }
    .sk-item {
        display: flex; flex-direction: column; align-items: center;
        padding: 10px 6px; border-radius: var(--radius-md); gap: 4px;
        font-size: 9.5px; font-weight: 600; text-align: center;
        color: var(--color-text); transition: background .18s;
    }
    .sk-item:hover, .sk-item.active {
        background: rgba(145,132,217,.12); color: var(--color-accent);
    }
    .sk-icon {
        width: 34px; height: 34px; border-radius: var(--radius-sm);
        background: var(--color-neutral-800);
        display: grid; place-items: center; margin-bottom: 2px;
    }
    .sk-icon svg { width: 15px; height: 15px; }
    .sk-item.active .sk-icon { background: rgba(145,132,217,.15); }
    .sk-item.active .sk-icon svg { stroke: var(--color-accent); }

    /* ══════════════════════════════════════════════════════════
       MODAL
    ══════════════════════════════════════════════════════════ */
    .modal-overlay {
        position: fixed; inset: 0; background: rgba(0,0,0,.6);
        display: none; align-items: flex-end; justify-content: center;
        z-index: 1000; backdrop-filter: blur(3px); padding: 0;
    }
    .modal-overlay.open { display: flex; }
    .modal {
        background: var(--color-surface);
        border-radius: var(--radius-xl) var(--radius-xl) 0 0;
        border: 1px solid var(--color-divider); border-bottom: none;
        width: 100%; max-width: 480px; max-height: 88vh;
        display: flex; flex-direction: column; overflow: hidden;
    }
    @media (min-width: 520px) {
        .modal-overlay { align-items: center; padding: 14px; }
        .modal { border-radius: var(--radius-xl); border-bottom: 1px solid var(--color-divider); max-height: 84vh; }
    }
    .modal-hdr {
        padding: 14px 16px; display: flex; align-items: center;
        justify-content: space-between;
        border-bottom: 1px solid var(--color-divider); flex: none;
    }
    .modal-title {
        display: flex; align-items: center; gap: 8px;
        font-weight: 700; font-size: 14px;
    }
    .modal-title svg { width: 15px; height: 15px; stroke: var(--color-accent); }
    .modal-close {
        width: 28px; height: 28px; border-radius: 50%;
        background: var(--color-neutral-800); border: none;
        color: var(--color-text); cursor: pointer;
        display: grid; place-items: center; font-size: 17px; line-height: 1;
    }
    .modal-body { padding: 16px; overflow-y: auto; flex: 1; }

    /* ── Form ──────────────────────────────────────────────── */
    .form-group { margin-bottom: 12px; }
    .form-group label {
        display: block; font-size: 10.5px; font-weight: 700;
        color: rgba(233,233,237,.55); margin-bottom: 5px;
        text-transform: uppercase; letter-spacing: .05em;
    }
    [data-theme="light"] .form-group label { color: rgba(22,24,38,.5); }
    .form-group input {
        width: 100%; padding: 10px 12px;
        border: 1.5px solid var(--color-divider); border-radius: var(--radius-md);
        background: var(--color-bg); color: var(--color-text);
        font-size: 14px; font-family: var(--font-bn); outline: none;
        transition: border-color .18s;
    }
    .form-group input:focus { border-color: var(--color-accent); }
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .form-actions { margin-top: 16px; }
    .btn-primary {
        width: 100%; padding: 12px;
        background: var(--color-accent); color: #fff;
        border: none; border-radius: var(--radius-md);
        font-size: 14px; font-family: var(--font-bn); font-weight: 700;
        cursor: pointer; display: flex; align-items: center;
        justify-content: center; gap: 7px; transition: opacity .18s;
    }
    .btn-primary:hover:not(:disabled) { opacity: .86; }
    .btn-primary:disabled { opacity: .45; cursor: not-allowed; }
    .btn-primary svg { width: 15px; height: 15px; }

    /* ── Timeline ──────────────────────────────────────────── */
    .timeline-item {
        display: flex; gap: 10px; padding: 10px 0; position: relative;
    }
    .timeline-item:not(:last-child)::before {
        content: ''; position: absolute; left: 14px; top: 30px; bottom: 0;
        width: 1.5px; background: var(--color-divider);
    }
    .timeline-dot {
        width: 29px; height: 29px; border-radius: 50%; flex: none;
        display: grid; place-items: center; color: #fff; flex-shrink: 0;
    }
    .timeline-dot svg { width: 12px; height: 12px; }
    .timeline-content { flex: 1; min-width: 0; }
    .timeline-type { font-weight: 700; font-size: 12px; margin-bottom: 4px; }
    .timeline-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 4px; }
    .tl-chip {
        font-size: 10px; padding: 1.5px 7px; border-radius: 4px;
        background: var(--color-neutral-800); font-family: var(--font-ui);
        color: var(--color-text);
    }
    .timeline-note {
        font-size: 11.5px; color: rgba(233,233,237,.62);
        background: var(--color-bg); padding: 6px 8px;
        border-radius: var(--radius-sm); margin-top: 4px; word-break: break-word;
    }
    [data-theme="light"] .timeline-note { color: rgba(22,24,38,.58); }
    .timeline-meta {
        font-size: 9.5px; color: rgba(233,233,237,.38); margin-top: 4px;
        font-family: var(--font-ui);
    }
    [data-theme="light"] .timeline-meta { color: rgba(22,24,38,.35); }

    /* ── Lightbox ──────────────────────────────────────────── */
    .lightbox {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,.96); z-index: 2000;
        flex-direction: column; align-items: center; justify-content: center;
    }
    .lightbox.open { display: flex; }
    .lightbox-img { max-width: 92%; max-height: 76vh; border-radius: var(--radius-md); display: block; }
    .lightbox-close {
        position: absolute; top: 14px; right: 14px;
        background: rgba(255,255,255,.14); border: none; color: #fff;
        width: 36px; height: 36px; border-radius: 50%;
        cursor: pointer; display: grid; place-items: center; font-size: 18px;
    }
    .lightbox-lbl {
        position: absolute; bottom: 20px;
        background: rgba(0,0,0,.7); color: #fff; padding: 5px 14px;
        border-radius: var(--radius-full); font-size: 11.5px; font-family: var(--font-ui);
    }
    </style>
</head>
<body>

<?php
// PWA shell
$pwaShell = $_SERVER['DOCUMENT_ROOT'] . '/Helpers/pwa_shell.php';
if (file_exists($pwaShell)) { include $pwaShell; }
?>

<!-- ════════════════════════════════════════════════════════════
     APP HEADER
════════════════════════════════════════════════════════════ -->
<header class="app-header">
    <div class="app-header__top">
        <button class="icon-btn icon-btn--round" onclick="skOpenDrawer()" aria-label="মেনু খুলুন">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <button class="icon-btn icon-btn--round" onclick="history.back()" aria-label="পেছনে যান">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M19 12H5m7 7-7-7 7-7"/></svg>
        </button>
        <div class="app-logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.8"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        </div>
        <div class="app-brand">
            <div class="app-brand__name">SADA&nbsp;KALO</div>
            <div class="app-brand__sub">পণ্য তালিকা</div>
        </div>
        <button class="icon-btn" id="themeBtn" onclick="toggleTheme()" aria-label="থিম পরিবর্তন" title="Light / Dark">
            <svg id="themeIcon" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
        </button>
    </div>
    <div class="app-header__search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21-4.35-4.35"/></svg>
        <input type="text" id="searchInput" placeholder="কোড, নাম বা ক্যাটাগরি..." autocomplete="off" spellcheck="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="2" width="15"><path d="M4 6h16M8 12h8M11 18h2"/></svg>
    </div>
</header>

<!-- ════════════════════════════════════════════════════════════
     FILTER BAR
════════════════════════════════════════════════════════════ -->
<div class="filter-bar">
    <div class="loc-chips" id="locChips" role="group" aria-label="লোকেশন ফিল্টার">
        <button class="chip active" data-loc="all" onclick="setLocFilter('all',this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            সব
        </button>
        <button class="chip chip-shop" data-loc="shop" onclick="setLocFilter('shop',this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            দোকান
        </button>
        <button class="chip chip-godown" data-loc="godown" onclick="setLocFilter('godown',this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            গোডাউন
        </button>
        <button class="chip chip-missing" data-loc="missing" onclick="setLocFilter('missing',this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            পাইনি
        </button>
        <button class="chip chip-damaged" data-loc="damaged" onclick="setLocFilter('damaged',this)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            ড্যামেজ
        </button>
    </div>
    <div class="filter-row2">
        <select id="categoryFilter" class="filter-select" onchange="onFilterChange()">
            <option value="all">ক্যাটাগরি লোড হচ্ছে…</option>
        </select>
        <select id="stockFilter" class="filter-select" onchange="onFilterChange()">
            <option value="all">সব স্টক</option>
            <option value="low">কম স্টক (&lt;10)</option>
            <option value="zero">শেষ (0 পিস)</option>
            <option value="high">পর্যাপ্ত (≥10)</option>
        </select>
    </div>
</div>

<!-- Summary -->
<div class="summary-bar">
    <span class="summary-lbl">ফলাফল</span>
    <div class="summary-stats">
        <div class="stat-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
            আইটেম: <strong id="statItems">0</strong>
        </div>
        <div class="stat-chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
            পিস: <strong id="statPieces">0</strong>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     CARDS CONTAINER
════════════════════════════════════════════════════════════ -->
<div class="cards-container" id="cardsContainer">
    <div class="spinner-wrap"><div class="spinner"></div></div>
</div>

<!-- ════════════════════════════════════════════════════════════
     PAGINATION
════════════════════════════════════════════════════════════ -->
<div class="pagination">
    <button class="page-btn" id="prevBtn" onclick="changePage(-1)" disabled aria-label="আগের পাতা">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    </button>
    <span class="page-info"><span id="pageNum">1</span> / <span id="pageTotal">1</span></span>
    <button class="page-btn" id="nextBtn" onclick="changePage(1)" disabled aria-label="পরের পাতা">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
    </button>
</div>

<!-- ════════════════════════════════════════════════════════════
     DRAWER  (side navigation)
════════════════════════════════════════════════════════════ -->
<div class="sk-overlay" id="skOverlay" onclick="skCloseDrawer()"></div>
<aside class="sk-drawer" id="skDrawer" role="navigation" aria-label="প্রধান মেনু">
    <div class="sk-drawer-head">
        <div class="app-logo" style="width:32px;height:32px">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.8" width="16"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/></svg>
        </div>
        <div>
            <div class="sk-drawer-brand">SADA KALO</div>
            <div class="app-brand__sub">Item List</div>
        </div>
        <button class="sk-drawer-close" onclick="skCloseDrawer()" aria-label="মেনু বন্ধ">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div class="sk-section">মেনু</div>
    <nav class="sk-grid">
        <a href="../dashboard.php" class="sk-item">
            <div class="sk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></div>হোম
        </a>
        <a href="inventory_dashboard.php" class="sk-item">
            <div class="sk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></div>ড্যাশবোর্ড
        </a>
        <a href="inventory.php" class="sk-item">
            <div class="sk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg></div>পণ্য যোগ
        </a>
        <a href="Invantory_Items.php" class="sk-item active">
            <div class="sk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>Item List
        </a>
        <a href="inventory_pos.php" class="sk-item">
            <div class="sk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></div>POS
        </a>
        <a href="inventory_sales_history.php" class="sk-item">
            <div class="sk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></div>ইতিহাস
        </a>
        <a href="return_product.php" class="sk-item">
            <div class="sk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.25"/></svg></div>রিটার্ন
        </a>
        <a href="out_of_stock.php" class="sk-item">
            <div class="sk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>শেষ স্টক
        </a>
        <a href="supplier_exchange.php" class="sk-item">
            <div class="sk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg></div>সাপ্লায়ার
        </a>
        <?php if ($isAdmin): ?>
        <a href="admin_inventory_control.php" class="sk-item">
            <div class="sk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93l-1.41 1.41M5.34 18.66l-1.41 1.41M22 12h-2M4 12H2M19.07 19.07l-1.41-1.41M5.34 5.34L3.93 3.93"/></svg></div>Inv Ctrl
        </a>
        <a href="Audit/" class="sk-item">
            <div class="sk-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>অডিট লগ
        </a>
        <?php endif; ?>
    </nav>
</aside>

<!-- ════════════════════════════════════════════════════════════
     EDIT MODAL  (Admin Only)
════════════════════════════════════════════════════════════ -->
<?php if ($isAdmin): ?>
<div class="modal-overlay" id="editModal" role="dialog" aria-modal="true" aria-label="প্রোডাক্ট এডিট">
    <div class="modal">
        <div class="modal-hdr">
            <div class="modal-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                প্রোডাক্ট আপডেট
            </div>
            <button class="modal-close" onclick="closeEditModal()" aria-label="বন্ধ করুন">×</button>
        </div>
        <div class="modal-body">
            <form id="editForm" onsubmit="submitProductEdit(event)" novalidate>
                <input type="hidden" id="edit_code">
                <div class="form-group">
                    <label>পণ্যের নাম</label>
                    <input type="text" id="edit_name" required autocomplete="off">
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>ক্রয় দাম (৳)</label>
                        <input type="number" step="0.01" min="0" id="edit_buy" required>
                    </div>
                    <div class="form-group">
                        <label>অন্যান্য খরচ (৳)</label>
                        <input type="number" step="0.01" min="0" id="edit_cost" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>বিক্রয় মূল্য (৳)</label>
                    <input type="number" step="0.01" min="0" id="edit_cash" required
                        style="font-size:1.15rem;font-weight:800;color:var(--c-success);text-align:center">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary" id="editSubmitBtn">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        আপডেট করুন
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════
     TIMELINE MODAL
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="timelineModal" role="dialog" aria-modal="true">
    <div class="modal">
        <div class="modal-hdr">
            <div class="modal-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span id="timelineTitle">টাইমলাইন</span>
            </div>
            <button class="modal-close" onclick="closeTimeline()" aria-label="বন্ধ করুন">×</button>
        </div>
        <div class="modal-body" id="timelineBody">
            <div class="spinner-wrap"><div class="spinner"></div></div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     IMAGE LIGHTBOX
════════════════════════════════════════════════════════════ -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="পণ্যের ছবি">
    <button class="lightbox-close" onclick="closeLightbox()" aria-label="বন্ধ করুন">×</button>
    <img id="lightboxImg" src="" alt="পণ্যের ছবি" class="lightbox-img">
    <div class="lightbox-lbl" id="lightboxLbl"></div>
</div>

<!-- ════════════════════════════════════════════════════════════
     BOTTOM NAV
════════════════════════════════════════════════════════════ -->
<?php
$bottomNav = __DIR__ . '/inventory_bottom_nav.php';
if (file_exists($bottomNav)) { include $bottomNav; }
?>

<!-- ════════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════ -->
<script>
'use strict';

/* ── Secure constants from PHP (JSON-encoded) ───────────────────── */
const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const IS_ADMIN   = <?php echo $isAdmin ? 'true' : 'false'; ?>;

/* ── State ───────────────────────────────────────────────────────── */
let currentPage = 1;
let totalPages  = 1;
let locFilter   = 'all';
let searchTimer = null;

/* ══════════════════════════════════════════════════════════════════
   DRAWER
══════════════════════════════════════════════════════════════════ */
function skOpenDrawer() {
    document.getElementById('skDrawer').classList.add('open');
    document.getElementById('skOverlay').classList.add('active');
}
function skCloseDrawer() {
    document.getElementById('skDrawer').classList.remove('open');
    document.getElementById('skOverlay').classList.remove('active');
}
/* inventory_bottom_nav.php's "মেনু" button calls toggleSidebar() if it exists —
   wire it to the drawer already on this page instead of falling back to a redirect. */
function toggleSidebar() { skOpenDrawer(); }

/* ══════════════════════════════════════════════════════════════════
   THEME TOGGLE
══════════════════════════════════════════════════════════════════ */
function toggleTheme() {
    const html = document.documentElement;
    const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    try { localStorage.setItem('sk-theme', next); } catch(e) {}
    renderThemeIcon(next);
}
function renderThemeIcon(theme) {
    const icon = document.getElementById('themeIcon');
    if (!icon) return;
    if (theme === 'dark') {
        // Sun icon (dark mode active → offer light)
        icon.innerHTML = '<circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>';
    } else {
        // Moon icon (light mode active → offer dark)
        icon.innerHTML = '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>';
    }
}

/* ══════════════════════════════════════════════════════════════════
   FILTERS
══════════════════════════════════════════════════════════════════ */
function setLocFilter(loc, btn) {
    locFilter = loc;
    document.querySelectorAll('#locChips .chip').forEach(c => c.classList.remove('active'));
    if (btn) btn.classList.add('active');
    currentPage = 1;
    loadItems();
}
function onFilterChange() {
    currentPage = 1;
    loadItems();
}

/* ══════════════════════════════════════════════════════════════════
   PAGINATION
══════════════════════════════════════════════════════════════════ */
function changePage(dir) {
    const next = currentPage + dir;
    if (next < 1 || next > totalPages) return;
    currentPage = next;
    loadItems();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ══════════════════════════════════════════════════════════════════
   LOAD ITEMS  (main AJAX call)
══════════════════════════════════════════════════════════════════ */
function loadItems() {
    const container = document.getElementById('cardsContainer');
    container.innerHTML = '<div class="spinner-wrap"><div class="spinner"></div></div>';

    fetch('Invantory_Items.php', {
        method: 'POST',
        body: new URLSearchParams({
            ajax_action:     'load_items_table',
            csrf_token:      CSRF_TOKEN,
            page:            String(currentPage),
            search:          document.getElementById('searchInput').value,
            category_filter: document.getElementById('categoryFilter').value,
            stock_filter:    document.getElementById('stockFilter').value,
            location_filter: locFilter,
        })
    })
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(res => {
        if (!res || res.status === 'error') {
            container.innerHTML = '<div class="empty-state"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="40"><path d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><p>লোড ব্যর্থ হয়েছে।</p></div>';
            return;
        }
        container.innerHTML = res.html || '';
        document.getElementById('statItems').textContent  = Number(res.filtered)    || 0;
        document.getElementById('statPieces').textContent = Number(res.totalPieces) || 0;
        currentPage = Number(res.page)       || 1;
        totalPages  = Number(res.totalPages) || 1;
        document.getElementById('pageNum').textContent   = currentPage;
        document.getElementById('pageTotal').textContent = totalPages;
        document.getElementById('prevBtn').disabled = currentPage <= 1;
        document.getElementById('nextBtn').disabled = currentPage >= totalPages;
    })
    .catch(() => {
        container.innerHTML = '<div class="empty-state"><p>সার্ভার সংযোগে সমস্যা।</p></div>';
    });
}

/* ══════════════════════════════════════════════════════════════════
   UPDATE LOCATION
══════════════════════════════════════════════════════════════════ */
function updateLocation(productCode, newLocation, selectEl) {
    const labels = { shop:'দোকান', godown:'গোডাউন', missing:'পণ্য পাইনি', damaged:'ড্যামেজ' };
    const prev   = selectEl.dataset.prev || newLocation;

    if (!confirm('অবস্থা "' + (labels[newLocation] || newLocation) + '" করবেন?')) {
        selectEl.value = prev;
        return;
    }
    const note = prompt('নোট (ঐচ্ছিক):', '');
    if (note === null) { selectEl.value = prev; return; }  // cancelled

    selectEl.disabled = true;
    selectEl.style.opacity = '.5';

    fetch('Invantory_Items.php', {
        method: 'POST',
        body: new URLSearchParams({
            ajax_action:  'update_location',
            csrf_token:   CSRF_TOKEN,
            product_code: productCode,
            location:     newLocation,
            note:         note,
        })
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            selectEl.dataset.prev = newLocation;
            selectEl.className    = 'loc-select loc-' + newLocation;
        } else {
            alert('❌ ' + (res.message || 'ত্রুটি'));
            selectEl.value = prev;
        }
    })
    .catch(() => { alert('❌ সার্ভার ত্রুটি!'); selectEl.value = prev; })
    .finally(() => { selectEl.disabled = false; selectEl.style.opacity = ''; });
}

/* ══════════════════════════════════════════════════════════════════
   TIMELINE MODAL
══════════════════════════════════════════════════════════════════ */
function showTimeline(productCode) {
    document.getElementById('timelineTitle').textContent = productCode + ' — টাইমলাইন';
    document.getElementById('timelineBody').innerHTML    = '<div class="spinner-wrap"><div class="spinner"></div></div>';
    document.getElementById('timelineModal').classList.add('open');

    fetch('Invantory_Items.php', {
        method: 'POST',
        body: new URLSearchParams({
            ajax_action:  'load_timeline',
            csrf_token:   CSRF_TOKEN,
            product_code: productCode,
        })
    })
    .then(r => r.json())
    .then(res => {
        const body = document.getElementById('timelineBody');
        if (res.status !== 'success' || !Array.isArray(res.logs) || !res.logs.length) {
            body.innerHTML = '<div class="empty-state"><p>' + esc(res.message || 'কোনো ইতিহাস নেই।') + '</p></div>';
            return;
        }
        const palette = {
            added:'#9184d9', moved:'#ea580c', missing:'#ef4444',
            found:'#4eca8b', damaged:'#eab308', sold:'#3b82f6', returned:'#f59e0b'
        };
        const labelMap = {
            added:'এড হয়েছে', moved:'স্থানান্তর', missing:'পাইনি',
            found:'পাওয়া গেছে', damaged:'ড্যামেজ', sold:'বিক্রি', returned:'রিটার্ন'
        };
        let html = '';
        res.logs.forEach(function(l) {
            const d   = new Date(l.created_at || '');
            const dt  = isNaN(d.getTime()) ? esc(String(l.created_at || '—'))
                      : d.toLocaleDateString('bn-BD', {day:'2-digit',month:'short',year:'numeric'})
                        + ' ' + d.toLocaleTimeString('bn-BD', {hour:'2-digit',minute:'2-digit'});
            const col = palette[l.event_type] || '#888';
            const lbl = labelMap[l.event_type] || esc(String(l.event_type || ''));

            // Extra info chips (all properly escaped)
            let chips = '';
            if (l.pieces)     chips += '<span class="tl-chip">পিস: ' + esc(String(l.pieces))     + '</span>';
            if (l.invoice_no) chips += '<span class="tl-chip">মেমো: ' + esc(String(l.invoice_no)) + '</span>';
            if (l.unit_price) chips += '<span class="tl-chip">৳'     + esc(String(l.unit_price)) + '</span>';

            html += '<div class="timeline-item">'
                  + '<div class="timeline-dot" style="background:' + col + '">'
                  +   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/></svg>'
                  + '</div>'
                  + '<div class="timeline-content">'
                  +   '<div class="timeline-type" style="color:' + col + '">' + lbl + '</div>'
                  +   (chips ? '<div class="timeline-chips">' + chips + '</div>' : '')
                  +   (l.note ? '<div class="timeline-note">'  + esc(String(l.note))          + '</div>' : '')
                  +   '<div class="timeline-meta">'            + esc(String(l.done_by_name || '—')) + ' · ' + dt + '</div>'
                  + '</div></div>';
        });
        body.innerHTML = html;
    })
    .catch(() => {
        document.getElementById('timelineBody').innerHTML =
            '<div class="empty-state"><p>টাইমলাইন লোড ব্যর্থ।</p></div>';
    });
}
function closeTimeline() {
    document.getElementById('timelineModal').classList.remove('open');
}

/* ══════════════════════════════════════════════════════════════════
   EDIT MODAL  (Admin)
══════════════════════════════════════════════════════════════════ */
/**
 * Called from card's edit button; reads data-* attributes — no eval, no inline JSON.
 */
function openEditFromBtn(btn) {
    document.getElementById('edit_code').value = btn.dataset.code;
    document.getElementById('edit_name').value = btn.dataset.name;
    document.getElementById('edit_buy').value  = btn.dataset.buy;
    document.getElementById('edit_cost').value = btn.dataset.cost;
    document.getElementById('edit_cash').value = btn.dataset.cash;
    document.getElementById('editModal').classList.add('open');
}
function closeEditModal() {
    document.getElementById('editModal').classList.remove('open');
}
function submitProductEdit(e) {
    e.preventDefault();
    const btn  = document.getElementById('editSubmitBtn');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner" style="width:18px;height:18px;border-width:2px"></div> আপডেট হচ্ছে…';

    fetch('Invantory_Items.php', {
        method: 'POST',
        body: new URLSearchParams({
            ajax_action:  'update_full_product',
            csrf_token:   CSRF_TOKEN,
            product_code: document.getElementById('edit_code').value,
            name:         document.getElementById('edit_name').value,
            buy_price:    document.getElementById('edit_buy').value,
            cost:         document.getElementById('edit_cost').value,
            cash_sell:    document.getElementById('edit_cash').value,
        })
    })
    .then(r => r.json())
    .then(res => {
        alert((res.status === 'success' ? '✅ ' : '❌ ') + (res.message || ''));
        if (res.status === 'success') { closeEditModal(); loadItems(); }
    })
    .catch(() => alert('❌ সার্ভার সংযোগ ব্যর্থ!'))
    .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
}

/* ══════════════════════════════════════════════════════════════════
   IMAGE LIGHTBOX
══════════════════════════════════════════════════════════════════ */
function openImageModal(src, label) {
    if (!src) return;
    document.getElementById('lightboxImg').src          = src;
    document.getElementById('lightboxLbl').textContent  = label || '';
    document.getElementById('lightbox').classList.add('open');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('open');
    document.getElementById('lightboxImg').src = '';
}

/* ══════════════════════════════════════════════════════════════════
   UTILITY: HTML escape (used in JS-generated DOM)
══════════════════════════════════════════════════════════════════ */
function esc(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

/* ══════════════════════════════════════════════════════════════════
   BOOTSTRAP
══════════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
    // Apply correct theme icon on load
    renderThemeIcon(document.documentElement.getAttribute('data-theme') || 'dark');

    // Load category dropdown
    fetch('Invantory_Items.php', {
        method: 'POST',
        body: new URLSearchParams({ ajax_action: 'load_categories', csrf_token: CSRF_TOKEN })
    })
    .then(r => r.json())
    .then(res => { if (res && res.html) document.getElementById('categoryFilter').innerHTML = res.html; })
    .catch(() => {});

    // Initial items load
    loadItems();

    // Search with debounce
    document.getElementById('searchInput').addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { currentPage = 1; loadItems(); }, 320);
    });

    // Close any modal on backdrop click
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.classList.remove('open');
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay, .lightbox')
                .forEach(el => el.classList.remove('open'));
        }
    });

    // Close lightbox on backdrop click
    document.getElementById('lightbox').addEventListener('click', function (e) {
        if (e.target === this) closeLightbox();
    });
});
</script>
</body>
</html>
