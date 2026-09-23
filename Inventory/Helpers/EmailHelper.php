<?php
declare(strict_types=1);

/**
 * EmailHelper
 * -----------------------------------------------------------
 * পণ্য অ্যাড হওয়ার পর admin-কে ইমেইল নোটিফিকেশন পাঠায়।
 * ProductEmailHelper.php থেকে আনা + item_location যোগ করা।
 *
 * ব্যবহার (inventory.php-তে, পণ্য সেভ হওয়ার পর):
 *   require_once __DIR__ . '/Helpers/EmailHelper.php';
 *
 *   EmailHelper::sendProductAddNotification([
 *       'product_code'  => $newProductCode,
 *       'category_name' => $productName,
 *       'pieces'        => $pieces,
 *       'buy_price'     => $buyPrice,
 *       'cost'          => $finalCost,
 *       'cash_sell'     => $cashSell,
 *       'item_location' => $itemLocation,   // 'shop' | 'godown'
 *       'image_path'    => $savedImagePath,
 *       'added_by_user' => $_SESSION['username'] ?? 'System',
 *   ]);
 * -----------------------------------------------------------
 */
class EmailHelper
{
    private const FROM_EMAIL = 'no-reply@sadakalofashion.com';
    private const FROM_NAME  = 'SADA KALO Inventory';
    private const TO_EMAIL   = 'hisabkhata24@gmail.com';

    private static array $locationLabels = [
        'shop'   => ['label' => 'দোকান',   'color' => '#1d4ed8', 'bg' => '#dbeafe', 'icon' => '🏪'],
        'godown' => ['label' => 'গোডাউন', 'color' => '#c2410c', 'bg' => '#ffedd5', 'icon' => '🏭'],
    ];

    /**
     * নতুন পণ্য যোগ হলে ইমেইল পাঠায়।
     * @param array  $data       পণ্যের ডেটা (উপরের ব্যবহার উদাহরণ দেখো)
     * @param string $toEmail    কাকে পাঠাবে (default: admin)
     * @return bool  সফল হলে true
     */
    public static function sendProductAddNotification(
        array  $data,
        string $toEmail = self::TO_EMAIL
    ): bool {
        $code     = $data['product_code']  ?? 'N/A';
        $cat      = $data['category_name'] ?? 'N/A';
        $pieces   = (int)($data['pieces']  ?? 0);
        $buy      = (float)($data['buy_price'] ?? 0);
        $cost     = (float)($data['cost']      ?? 0);
        $sell     = (float)($data['cash_sell'] ?? 0);
        $locKey   = $data['item_location'] ?? '';
        $imgPath  = $data['image_path']    ?? '';
        $addedBy  = $data['added_by_user'] ?? 'System';
        $addedAt  = date('d F Y, h:i:s A');

        // লোকেশন লেবেল
        $loc      = self::$locationLabels[$locKey]
                  ?? ['label' => '—', 'color' => '#6b7280', 'bg' => '#f3f4f6', 'icon' => '📦'];

        // ছবির পুরো URL
        // __DIR__ = /public_html/Inventory/Helpers
        // DOCUMENT_ROOT = /public_html
        // তাই সঠিক web path: /Inventory/uploads/Shirt/SKF-478.jpg
        $imgUrl = '';
        if ($imgPath !== '') {
            $proto        = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host         = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $docRoot      = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
            $inventoryDir = rtrim(dirname(__DIR__), '/\\');

            if ($docRoot !== '' && str_starts_with($inventoryDir, $docRoot)) {
                $webBase = str_replace('\\', '/', substr($inventoryDir, strlen($docRoot)));
                $webBase = '/' . ltrim($webBase, '/');
            } else {
                $webBase = '/Inventory'; // fallback
            }

            $imgUrl = $proto . $host . $webBase . '/' . ltrim($imgPath, '/');
        }

        $subject = "🛒 নতুন পণ্য যুক্ত: [{$code}] {$cat}";
        $html    = self::buildHtml(
            $code, $cat, $pieces, $buy, $cost, $sell,
            $loc, $imgUrl, $addedBy, $addedAt
        );

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . self::FROM_NAME . " <" . self::FROM_EMAIL . ">\r\n";

        return @mail($toEmail, $subject, $html, $headers);
    }

    // ─── Private ────────────────────────────────────────

    private static function buildHtml(
        string $code, string $cat, int $pieces,
        float $buy, float $cost, float $sell,
        array $loc, string $imgUrl,
        string $addedBy, string $addedAt
    ): string {
        $locBadge = "<span style='display:inline-block;padding:3px 10px;border-radius:5px;"
                  . "background:{$loc['bg']};color:{$loc['color']};border:1.5px solid {$loc['color']};"
                  . "font-weight:800;font-size:13px;'>"
                  . "{$loc['icon']} {$loc['label']}</span>";

        $imgBlock = $imgUrl !== ''
            ? "<div style='text-align:center;margin-bottom:20px;'>"
            . "<img src='{$imgUrl}' style='width:120px;height:120px;object-fit:cover;"
            . "border-radius:10px;border:3px solid #e5e7eb;box-shadow:0 2px 8px rgba(0,0,0,.1);'>"
            . "</div>"
            : '';

        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="bn">
<head><meta charset="UTF-8">
<style>
body{font-family:Arial,sans-serif;background:#f7f9fa;margin:0;padding:20px;color:#1f2937}
.wrap{max-width:620px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;
      box-shadow:0 4px 15px rgba(0,0,0,.05);border:1px solid #e5e7eb}
.hd{background:#111827;color:#fff;padding:25px 20px;text-align:center}
.hd h1{margin:0;font-size:20px;font-weight:700}
.hd p{margin:5px 0 0;font-size:13px;color:#9ca3af}
.bd{padding:25px}
.badge-top{background:#e0f2fe;color:#0369a1;padding:10px 15px;border-radius:6px;
           font-size:13px;font-weight:600;margin-bottom:20px;text-align:center}
table{width:100%;border-collapse:collapse;margin-bottom:20px}
th,td{padding:10px 12px;font-size:14px;text-align:left;border-bottom:1px solid #f3f4f6}
th{color:#4b5563;font-weight:600;width:45%;background:#f9fafb}
td{color:#111827;font-weight:700}
.ft{background:#f9fafb;padding:15px;text-align:center;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb}
</style>
</head>
<body>
<div class="wrap">
  <div class="hd">
    <h1>SADA KALO FASHION</h1>
    <p>ইনভেন্টরি ম্যানেজমেন্ট — নতুন পণ্য নোটিফিকেশন</p>
  </div>
  <div class="bd">
    <div class="badge-top">✨ ইনভেন্টরিতে নতুন পণ্য সফলভাবে যোগ হয়েছে!</div>
    {$imgBlock}
    <table>
      <tr><th>পণ্য কোড:</th>
          <td><span style='background:#e5e7eb;padding:3px 8px;border-radius:4px;font-family:monospace'>{$e($code)}</span></td></tr>
      <tr><th>ক্যাটাগরি:</th><td>{$e($cat)}</td></tr>
      <tr><th>স্টক (পিস):</th><td>{$pieces} টি</td></tr>
      <tr><th>ক্রয় মূল্য:</th><td>৳{$e(number_format($buy,2))}</td></tr>
      <tr><th>কস্ট:</th><td>৳{$e(number_format($cost,2))}</td></tr>
      <tr><th>বিক্রি রেট:</th>
          <td><span style='color:#2563eb'>৳{$e(number_format($sell,2))}</span></td></tr>
      <tr><th>রাখা হয়েছে:</th><td>{$locBadge}</td></tr>
      <tr><th>এন্ট্রি করেছেন:</th><td>{$e($addedBy)}</td></tr>
      <tr><th>সময়:</th><td>{$e($addedAt)}</td></tr>
    </table>
  </div>
  <div class="ft">&copy; {$e(date('Y'))} SADA KALO FASHION. স্বয়ংক্রিয় ইনভেন্টরি নোটিফিকেশন।</div>
</div>
</body></html>
HTML;
    }
}
