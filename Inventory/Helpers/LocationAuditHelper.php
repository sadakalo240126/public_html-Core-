<?php
declare(strict_types=1);

/**
 * LocationAuditHelper
 * -----------------------------------------------------------------------
 * পণ্য দোকান↔গোডাউন-এর মধ্যে কখন/কে/কী পরিবর্তন করলো — তার লগ রাখে এবং
 * লাইভ (auto-refresh) ভিউ রেন্ডার করে।
 *
 * ⚠️ কোনো নতুন টেবিল লাগে না — বিদ্যমান `audit_logs` টেবিল ব্যবহার হয়,
 * `AuditLogger::update()` এর মাধ্যমে (module = 'product_location')।
 * এই ফাইল Audit/ মডিউলের কোনো ফাইল টাচ করে না, শুধু ব্যবহার করে।
 *
 * প্রি-রিকুইজিট: যে পেজে এই হেল্পার ব্যবহার হবে, সেখানে আগে থেকেই
 * AuditInit::boot($conn) কল করা থাকতে হবে (inventory.php-তে ইতিমধ্যে আছে)।
 *
 * ব্যবহার:
 *   require_once __DIR__ . '/Helpers/LocationAuditHelper.php';
 *
 *   // ১) পণ্য অ্যাড/মুভ করার সময়, DB transaction-এর ভিতরেই কল করুন:
 *   LocationAuditHelper::log(
 *       $productId, $productCode, $productName,
 *       $fromLocation,   // নতুন পণ্য হলে null
 *       $toLocation      // 'shop' | 'godown'
 *   );
 *
 *   // ২) কোনো পেজে লাইভ লগ টেবিল বসাতে:
 *   echo LocationAuditHelper::renderLiveView();
 *
 *   // ৩) AJAX action হ্যান্ডলারে (যেখানে বসাবেন) যোগ করুন:
 *   case 'get_location_audit_log':
 *       header('Content-Type: application/json; charset=utf-8');
 *       echo json_encode(LocationAuditHelper::fetchRecent($conn, 20));
 *       exit;
 */
final class LocationAuditHelper
{
    /** audit_logs টেবিলে module কলামের ভ্যালু — filter/report-এর জন্য এই নামেই খুঁজতে হবে */
    public const MODULE = 'product_location';

    private static array $labels = [
        'shop'   => 'দোকান',
        'godown' => 'গোডাউন',
    ];

    /**
     * একটা মুভমেন্ট লগ এন্ট্রি সেভ করে — বিদ্যমান AuditLogger::update() দিয়ে।
     * AuditInit::boot($conn) আগেই কল করা থাকতে হবে (না হলে চুপচাপ স্কিপ হবে,
     * মূল save operation বন্ধ হবে না — AuditLogger নিজেই এভাবে ডিজাইন করা)।
     */
    public static function log(
        int $productId,
        string $productCode,
        string $productName,
        ?string $fromLocation,
        string $toLocation,
        ?string $note = null
    ): void {
        if (!class_exists('AuditLogger')) {
            return; // Audit মডিউল বুট না হলে নীরবে স্কিপ (মূল কাজ আটকাবে না)
        }

        $oldData = $fromLocation !== null
            ? ['location' => $fromLocation, 'product_code' => $productCode, 'product_name' => $productName]
            : null;

        $newData = ['location' => $toLocation, 'product_code' => $productCode, 'product_name' => $productName];

        $fromLabel = $fromLocation !== null ? (self::$labels[$fromLocation] ?? $fromLocation) : null;
        $toLabel   = self::$labels[$toLocation] ?? $toLocation;

        $description = $fromLabel !== null
            ? "পণ্য \"{$productName}\" ({$productCode}) {$fromLabel} থেকে {$toLabel}-এ সরানো হয়েছে"
            : "পণ্য \"{$productName}\" ({$productCode}) {$toLabel}-এ যোগ করা হয়েছে";

        if ($note !== null && $note !== '') {
            $description .= " — {$note}";
        }

        AuditLogger::update(self::MODULE, $productId, $oldData, $newData, $description);
    }

    /**
     * সাম্প্রতিক location-change লগ এন্ট্রি রিটার্ন করে (নতুন থেকে পুরনো)।
     * বিদ্যমান AuditLogModel::getAll() ব্যবহার করে, module ফিল্টার দিয়ে —
     * Audit মডিউলের কোনো ফাইল এডিট না করেই।
     *
     * @return array<int,array<string,mixed>>
     */
    public static function fetchRecent(PDO $conn, int $limit = 20): array
    {
        if (!class_exists('AuditLogModel')) {
            return [];
        }
        $model  = new AuditLogModel($conn);
        $result = $model->getAll([
            'module'   => self::MODULE,
            'page'     => 1,
            'per_page' => max(1, min(100, $limit)),
        ]);

        return $result['data'] ?? [];
    }

    /**
     * লাইভ (৫ সেকেন্ড পরপর auto-refresh) মুভমেন্ট লগ — HTML+JS।
     * যেকোনো admin পেজে echo করে বসানো যাবে।
     *
     * নোট: AJAX polling (৫ সেকেন্ড) — এই প্রজেক্টে WebSocket ইনফ্রাস্ট্রাকচার
     * নেই বলে সবচেয়ে সহজ ও স্টেবল "live" পদ্ধতি এটাই। প্রতিবার সার্ভার থেকে
     * সাম্প্রতিক N টা এন্ট্রি টেনে পুরো লিস্ট রিফ্রেশ করে (delta-tracking না,
     * তাই বিদ্যমান AuditLogModel-এ কোনো পরিবর্তন লাগে না)।
     */
    public static function renderLiveView(): string
    {
        ob_start();
        ?>
        <div class="loc-audit-wrap">
            <div class="loc-audit-head">
                <span class="loc-audit-title"><i class="fas fa-clock-rotate-left"></i> লোকেশন মুভমেন্ট লগ (লাইভ)</span>
                <span class="loc-audit-live-dot"></span>
            </div>
            <div id="locAuditTableBody" class="loc-audit-body">
                <div class="loc-audit-empty">লোড হচ্ছে...</div>
            </div>
        </div>

        <style>
            .loc-audit-wrap { border:1px solid var(--sk-line); border-radius:.7rem; overflow:hidden; }
            .loc-audit-head {
                display:flex; align-items:center; justify-content:space-between;
                padding:.6rem .9rem; background:var(--sk-surface-2); font-weight:800; font-size:.85rem;
            }
            .loc-audit-live-dot {
                width:9px; height:9px; border-radius:50%; background:#22c55e;
                animation:locAuditPulse 1.5s infinite;
            }
            @keyframes locAuditPulse {
                0%   { box-shadow:0 0 0 0 rgba(34,197,94,.6); }
                70%  { box-shadow:0 0 0 8px rgba(34,197,94,0); }
                100% { box-shadow:0 0 0 0 rgba(34,197,94,0); }
            }
            .loc-audit-body { max-height:320px; overflow-y:auto; }
            .loc-audit-row {
                display:flex; flex-direction:column; gap:.15rem; padding:.55rem .9rem;
                border-top:1px solid var(--sk-line); font-size:.8rem;
            }
            .loc-audit-meta { color:var(--sk-muted); font-size:.72rem; }
            .loc-audit-empty { padding:1rem; text-align:center; color:var(--sk-muted); font-size:.82rem; }
        </style>

        <script>
        (function () {
            var container = document.getElementById('locAuditTableBody');

            function esc(s) {
                var d = document.createElement('div');
                d.innerText = s || '';
                return d.innerHTML;
            }

            function renderRows(rows) {
                if (!rows.length) {
                    container.innerHTML = '<div class="loc-audit-empty">এখনো কোনো মুভমেন্ট লগ নেই</div>';
                    return;
                }
                container.innerHTML = rows.map(function (r) {
                    return '<div class="loc-audit-row">' +
                        '<div>' + esc(r.description || '') + '</div>' +
                        '<div class="loc-audit-meta"><i class="fas fa-user"></i> ' + esc(r.username || 'Unknown') +
                        ' &nbsp;·&nbsp; <i class="fas fa-clock"></i> ' + esc(r.created_at || '') + '</div>' +
                        '</div>';
                }).join('');
            }

            function poll() {
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'ajax_action=get_location_audit_log'
                })
                .then(function (res) { return res.json(); })
                .then(function (rows) { renderRows(Array.isArray(rows) ? rows : []); })
                .catch(function () { /* নেটওয়ার্ক এরর হলে পরের সাইকেলে রিট্রাই */ });
            }

            poll();
            setInterval(poll, 5000);
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }
}
