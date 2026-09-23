<?php
declare(strict_types=1);

/**
 * StorageLocationHelper
 * -----------------------------------------------------------
 * যেসব জায়গায় কাজ করে:
 *   ১) পণ্য অ্যাড ফর্ম  → renderField()    সিলেক্টর বাটন
 *   ২) পণ্য লিস্ট       → renderBadge()    ছোট কালার ব্যাজ
 *   ৩) POS/সেলস পেজ    → renderBadge()    কোথায় আছে দেখাবে
 *   ৪) সেলস হিস্ট্রি    → renderMovement() কোথা থেকে কোথায় গেল
 *   ৫) সেভের সময়       → validate()       সার্ভার-সাইড চেক
 *
 * কলাম: inventory.item_location ENUM('shop','godown') DEFAULT 'shop'
 *        (আগে থেকেই আছে — নতুন কিছু লাগবে না)
 *
 * কালার: location_colors টেবিল থেকে আসে (DB-তে সেন্ট্রাল,
 *        সব ডিভাইসে একই দেখা যায়)
 * -----------------------------------------------------------
 */
class StorageLocationHelper
{
    public const SHOP    = 'shop';
    public const GODOWN  = 'godown';
    public const MISSING = 'missing';
    public const DAMAGED = 'damaged';

    /** পণ্য এড ফর্মে শুধু এই ২টা দেখাবে (এড করার সময় পাইনি/ড্যামেজ হয় না) */
    public const ADD_OPTIONS = [self::SHOP, self::GODOWN];

    /** সব অবস্থা — লিস্ট/আপডেটের সময় এই ৪টা */
    public const ALL_OPTIONS = [self::SHOP, self::GODOWN, self::MISSING, self::DAMAGED];

    /** DB থেকে কালার না আসলে এই ডিফল্ট ব্যবহার হবে (৪টা নির্দিষ্ট রঙ) */
    private static array $fallback = [
        self::SHOP    => ['label' => 'দোকান',      'color' => '#1d4ed8', 'bg' => '#dbeafe', 'icon' => 'fa-store'],
        self::GODOWN  => ['label' => 'গোডাউন',     'color' => '#ea580c', 'bg' => '#ffedd5', 'icon' => 'fa-warehouse'],
        self::MISSING => ['label' => 'পণ্য পাইনি', 'color' => '#dc2626', 'bg' => '#fee2e2', 'icon' => 'fa-circle-question'],
        self::DAMAGED => ['label' => 'ড্যামেজ',    'color' => '#eab308', 'bg' => '#fef9c3', 'icon' => 'fa-heart-crack'],
    ];

    // ═══════════════════════════════════════════════════════
    // ১) পণ্য অ্যাড ফর্ম — সিলেক্টর বাটন
    // ═══════════════════════════════════════════════════════

    /**
     * দোকান / গোডাউন সিলেক্ট করার বাটন রেন্ডার করে।
     * সিলেক্ট না করে সেভ করলে ওয়ার্নিং দেখায়, ফর্ম ডাটা মোছে না।
     *
     * ব্যবহার: echo StorageLocationHelper::renderField($conn);
     */
    public static function renderField(PDO $conn, ?string $selected = null): string
    {
        $colors = self::fetchColors($conn);
        $selVal = htmlspecialchars((string) $selected, ENT_QUOTES, 'UTF-8');

        ob_start(); ?>
        <div class="sk-field">
            <label class="sk-label">
                পণ্যটি রাখা হয়েছে
                <span style="color:var(--sk-danger)">*</span>
            </label>

            <input type="hidden" id="item_location" name="item_location" value="<?= $selVal ?>">

            <div class="sl-group" id="slGroup">
                <?php foreach ($colors as $key => $c): ?>
                <?php if (!in_array($key, self::ADD_OPTIONS, true)) continue; // এড ফর্মে শুধু দোকান/গোডাউন ?>
                <button type="button"
                        class="sl-btn <?= $selected === $key ? 'sl-btn--active' : '' ?>"
                        data-value="<?= $key ?>"
                        data-color="<?= htmlspecialchars($c['color'], ENT_QUOTES) ?>"
                        data-bg="<?= htmlspecialchars($c['bg'],    ENT_QUOTES) ?>"
                        style="<?= $selected === $key
                            ? "border-color:{$c['color']};background:{$c['bg']};color:{$c['color']};"
                            : '' ?>"
                        onclick="slSelect('<?= $key ?>')">
                    <i class="fas <?= $c['icon'] ?>"></i>
                    <?= htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') ?>
                </button>
                <?php endforeach; ?>
            </div>

            <div id="slWarning" class="sl-warning" style="display:none;">
                <i class="fas fa-triangle-exclamation"></i>
                দোকান বা গোডাউন সিলেক্ট করুন — না করলে সেভ হবে না!
            </div>
        </div>

        <style>
        .sl-group { display:flex; gap:.6rem; }
        .sl-btn {
            flex:1; padding:.7rem .5rem; border-radius:.6rem;
            border:2px solid var(--sk-line); background:var(--sk-surface-2);
            color:var(--sk-muted); font-weight:800; font-size:.85rem;
            cursor:pointer; display:flex; align-items:center;
            justify-content:center; gap:.4rem; transition:all .15s;
        }
        .sl-warning {
            margin-top:.4rem; color:var(--sk-danger);
            font-size:.78rem; font-weight:700;
            display:flex; align-items:center; gap:.3rem;
        }
        </style>

        <script>
        function slSelect(val) {
            document.getElementById('item_location').value = val;
            document.querySelectorAll('#slGroup .sl-btn').forEach(function(btn) {
                var active = btn.getAttribute('data-value') === val;
                btn.classList.toggle('sl-btn--active', active);
                if (active) {
                    btn.style.borderColor  = btn.getAttribute('data-color');
                    btn.style.background   = btn.getAttribute('data-bg');
                    btn.style.color        = btn.getAttribute('data-color');
                } else {
                    btn.style.borderColor  = '';
                    btn.style.background   = '';
                    btn.style.color        = '';
                }
            });
            document.getElementById('slWarning').style.display = 'none';
        }

        /* সেভ বাটনে ক্লিক হওয়ার আগে এই ফাংশন কল করো।
           false হলে সাবমিট বন্ধ করো — ফর্ম ডাটা অক্ষত থাকবে। */
        function slValidate() {
            if (!document.getElementById('item_location').value) {
                document.getElementById('slWarning').style.display = 'flex';
                document.getElementById('slGroup')
                    .scrollIntoView({ behavior:'smooth', block:'center' });
                return false;
            }
            return true;
        }
        </script>
        <?php
        return (string) ob_get_clean();
    }

    // ═══════════════════════════════════════════════════════
    // ২+৩) পণ্য লিস্ট ও POS — ছোট কালার ব্যাজ
    // ═══════════════════════════════════════════════════════

    /**
     * পণ্য লিস্ট ও POS পেজে প্রতিটা পণ্যের পাশে ছোট ব্যাজ দেখায়।
     * কালার DB থেকে আসে (সব ডিভাইসে একই)।
     *
     * ব্যবহার:
     *   echo StorageLocationHelper::renderBadge('shop',   $conn); // দোকান ব্যাজ
     *   echo StorageLocationHelper::renderBadge('godown', $conn); // গোডাউন ব্যাজ
     *
     * POS-এ $conn না থাকলে ডিফল্ট কালার ব্যবহার হবে (চলতে থাকবে):
     *   echo StorageLocationHelper::renderBadge('shop');
     */
    public static function renderBadge(string $location, ?PDO $conn = null): string
    {
        $colors = $conn !== null ? self::fetchColors($conn) : self::$fallback;
        $c      = $colors[$location] ?? $colors[self::SHOP];

        $label = htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8');
        $color = htmlspecialchars($c['color'], ENT_QUOTES, 'UTF-8');
        $bg    = htmlspecialchars($c['bg'],    ENT_QUOTES, 'UTF-8');
        $icon  = htmlspecialchars($c['icon'],  ENT_QUOTES, 'UTF-8');

        return "<span class=\"sl-badge\" style=\"color:{$color};background:{$bg};border-color:{$color};\">"
             . "<i class=\"fas {$icon}\"></i> {$label}</span>"
             . self::badgeStyle();
    }

    // ═══════════════════════════════════════════════════════
    // ৪) সেলস হিস্ট্রি — কোথা থেকে কোথায় গেল
    // ═══════════════════════════════════════════════════════

    /**
     * সেলস হিস্ট্রিতে মুভমেন্ট দেখায়।
     * $from = null মানে নতুন পণ্য সরাসরি এই লোকেশনে যোগ হয়েছে।
     *
     * ব্যবহার:
     *   echo StorageLocationHelper::renderMovement(null,     'shop',   $conn); // নতুন → দোকান
     *   echo StorageLocationHelper::renderMovement('shop',   'godown', $conn); // দোকান → গোডাউন
     *   echo StorageLocationHelper::renderMovement('godown', 'shop',   $conn); // গোডাউন → দোকান
     */
    public static function renderMovement(?string $from, string $to, ?PDO $conn = null): string
    {
        $colors = $conn !== null ? self::fetchColors($conn) : self::$fallback;
        $toC    = $colors[$to] ?? $colors[self::SHOP];

        $toLabel  = htmlspecialchars($toC['label'],  ENT_QUOTES, 'UTF-8');
        $toColor  = htmlspecialchars($toC['color'],  ENT_QUOTES, 'UTF-8');
        $toBg     = htmlspecialchars($toC['bg'],     ENT_QUOTES, 'UTF-8');
        $toIcon   = htmlspecialchars($toC['icon'],   ENT_QUOTES, 'UTF-8');

        // নতুন পণ্য — শুধু গন্তব্য দেখাবে
        if ($from === null || $from === '') {
            return "<span class=\"sl-move\">"
                 . "<span class=\"sl-badge\" style=\"color:{$toColor};background:{$toBg};border-color:{$toColor};\">"
                 . "<i class=\"fas {$toIcon}\"></i> {$toLabel}</span>"
                 . "</span>"
                 . self::badgeStyle();
        }

        $fromC     = $colors[$from] ?? $colors[self::GODOWN];
        $fromLabel = htmlspecialchars($fromC['label'], ENT_QUOTES, 'UTF-8');
        $fromColor = htmlspecialchars($fromC['color'], ENT_QUOTES, 'UTF-8');
        $fromBg    = htmlspecialchars($fromC['bg'],    ENT_QUOTES, 'UTF-8');
        $fromIcon  = htmlspecialchars($fromC['icon'],  ENT_QUOTES, 'UTF-8');

        return "<span class=\"sl-move\">"
             . "<span class=\"sl-badge\" style=\"color:{$fromColor};background:{$fromBg};border-color:{$fromColor};\">"
             . "<i class=\"fas {$fromIcon}\"></i> {$fromLabel}</span>"
             . " <i class=\"fas fa-arrow-right sl-arrow\"></i> "
             . "<span class=\"sl-badge\" style=\"color:{$toColor};background:{$toBg};border-color:{$toColor};\">"
             . "<i class=\"fas {$toIcon}\"></i> {$toLabel}</span>"
             . "</span>"
             . self::badgeStyle();
    }

    // ═══════════════════════════════════════════════════════
    // ৫) সার্ভার-সাইড ভ্যালিডেশন — সেভের সময়
    // ═══════════════════════════════════════════════════════

    /**
     * সেভ বাটন ক্লিকের পর সার্ভারে চেক করে।
     * ভুল/খালি হলে Exception ছোড়ে — caller JSON error রিটার্ন করবে,
     * DB-তে কিছু যাবে না, ফর্ম ডাটা মোছে না।
     *
     * ব্যবহার (add_product handler-এ):
     *   $location = StorageLocationHelper::validate($_POST['item_location'] ?? null);
     */
    public static function validate(?string $value): string
    {
        $value = trim((string) $value);
        if (!in_array($value, [self::SHOP, self::GODOWN], true)) {
            throw new Exception('দোকান নাকি গোডাউন — সিলেক্ট করুন!');
        }
        return $value;
    }

    // ═══════════════════════════════════════════════════════
    // ৬) অবস্থা বদলানো — সব এক জায়গায়
    //    (item_location আপডেট + location_logs লগ + অডিট)
    // ═══════════════════════════════════════════════════════

    /**
     * পণ্যের অবস্থা বদলায় (দোকান/গোডাউন/পাইনি/ড্যামেজ) এবং সব লগ রাখে।
     *
     * যা করে:
     *   ১) inventory.item_location আপডেট (সর্বশেষ অবস্থা)
     *   ২) location_logs এ একটা row যোগ (টাইমলাইন — মুছবে না)
     *   ৩) AuditLogger::update() → audit_logs এ (কে/কবে/কী)
     *
     * @return string সফল বার্তা (Bangla)
     * @throws Exception ভুল ইনপুট হলে
     */
    public static function updateStatus(
        PDO    $conn,
        string $productCode,
        string $newStatus,
        int    $userId,
        string $userName,
        ?string $userNote = null
    ): string {
        $productCode = trim($productCode);
        $newStatus   = trim($newStatus);

        if ($productCode === '') {
            throw new Exception('পণ্য কোড নেই!');
        }
        if (!in_array($newStatus, self::ALL_OPTIONS, true)) {
            throw new Exception('সঠিক অবস্থা সিলেক্ট করুন!');
        }

        // আগের অবস্থা
        $stmtOld = $conn->prepare("SELECT item_location FROM inventory WHERE product_code = ? LIMIT 1");
        $stmtOld->execute([$productCode]);
        $oldStatus = (string)($stmtOld->fetchColumn() ?: '');
        if ($oldStatus === '') {
            throw new Exception('পণ্য পাওয়া যায়নি!');
        }

        // event_type ও অটো নোট নির্ধারণ
        [$eventType, $autoNote] = self::resolveEvent($oldStatus, $newStatus);

        // ব্যবহারকারীর নোট থাকলে যোগ
        $fullNote = $autoNote;
        if ($userNote !== null && trim($userNote) !== '') {
            $fullNote .= ' — ' . trim($userNote);
        }

        // ── ট্রানজেকশন: আপডেট + লগ একসাথে ──
        $conn->beginTransaction();
        try {
            // ১) সর্বশেষ অবস্থা আপডেট
            $conn->prepare("UPDATE inventory SET item_location = ? WHERE product_code = ?")
                 ->execute([$newStatus, $productCode]);

            // ২) টাইমলাইন লগ (location_logs)
            self::logEvent($conn, [
                'product_code'  => $productCode,
                'event_type'    => $eventType,
                'from_location' => $oldStatus,
                'to_location'   => $newStatus,
                'note'          => $fullNote,
                'done_by'       => $userId,
                'done_by_name'  => $userName,
            ]);

            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw new Exception('ডাটাবেস ত্রুটি!');
        }

        // ৩) অডিট লগ (আলাদা — ফেল করলেও মূল কাজ আটকাবে না)
        if (class_exists('AuditLogger')) {
            AuditLogger::update(
                'inventory',
                $productCode,
                ['item_location' => $oldStatus],
                ['item_location' => $newStatus],
                $fullNote . " ({$productCode})"
            );
        }

        return $autoNote;
    }

    /**
     * location_logs টেবিলে একটা লগ row যোগ করে (append-only)।
     * টেবিল না থাকলে নীরবে স্কিপ — মূল কাজ আটকাবে না।
     */
    public static function logEvent(PDO $conn, array $d): void
    {
        try {
            $stmt = $conn->prepare("
                INSERT INTO location_logs
                    (product_code, event_type, from_location, to_location,
                     pieces, unit_price, invoice_no, note, done_by, done_by_name)
                VALUES
                    (:pc, :et, :fl, :tl, :pcs, :up, :inv, :note, :by, :byname)
            ");
            $stmt->execute([
                ':pc'     => $d['product_code'] ?? '',
                ':et'     => $d['event_type']   ?? 'moved',
                ':fl'     => $d['from_location'] ?? null,
                ':tl'     => $d['to_location']   ?? null,
                ':pcs'    => $d['pieces']        ?? null,
                ':up'     => $d['unit_price']    ?? null,
                ':inv'    => $d['invoice_no']    ?? null,
                ':note'   => $d['note']          ?? null,
                ':by'     => $d['done_by']       ?? 0,
                ':byname' => $d['done_by_name']  ?? '',
            ]);
        } catch (Throwable $e) {
            error_log('location_logs write failed: ' . $e->getMessage());
        }
    }

    /**
     * পুরনো ও নতুন অবস্থা থেকে event_type ও অটো-নোট ঠিক করে।
     * @return array{0:string,1:string} [event_type, auto_note]
     */
    private static function resolveEvent(string $old, string $new): array
    {
        $labels = [
            'shop'    => 'দোকান',
            'godown'  => 'গোডাউন',
            'missing' => 'পণ্য পাইনি',
            'damaged' => 'ড্যামেজ',
        ];
        $oldLbl = $labels[$old] ?? $old;
        $newLbl = $labels[$new] ?? $new;

        // নতুন অবস্থা অনুযায়ী event_type
        if ($new === 'missing') {
            return ['missing', "পণ্য পাইনি (আগে ছিল: {$oldLbl})"];
        }
        if ($new === 'damaged') {
            return ['damaged', "ড্যামেজ চিহ্নিত (আগে ছিল: {$oldLbl})"];
        }
        // দোকান/গোডাউনে ফেরানো
        if (in_array($old, ['missing','damaged'], true)) {
            return ['found', "পণ্য পাওয়া গেছে → {$newLbl}"];
        }
        // সাধারণ মুভ (দোকান↔গোডাউন)
        return ['moved', "{$oldLbl} → {$newLbl} পাঠানো হয়েছে"];
    }

    /**
     * একটা পণ্যের পুরো টাইমলাইন আনে (location_logs থেকে, নতুন উপরে)।
     */
    public static function fetchTimeline(PDO $conn, string $productCode, int $limit = 50): array
    {
        try {
            $stmt = $conn->prepare("
                SELECT event_type, from_location, to_location, pieces,
                       unit_price, invoice_no, note, done_by_name, created_at
                FROM location_logs
                WHERE product_code = :pc
                ORDER BY created_at DESC, id DESC
                LIMIT :lim
            ");
            $stmt->bindValue(':pc', $productCode);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }


    // ═══════════════════════════════════════════════════════
    // Private
    // ═══════════════════════════════════════════════════════

    /**
     * location_colors টেবিল থেকে কালার আনে।
     * টেবিল না থাকলে / DB এরর হলে fallback কালারে চলে।
     */
    private static function fetchColors(PDO $conn): array
    {
        $colors = self::$fallback;
        try {
            $rows = $conn->query(
                'SELECT location_key, label_bn, color_hex, bg_hex FROM location_colors'
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $key = $row['location_key'] ?? '';
                if (isset($colors[$key])) {
                    $colors[$key]['label'] = $row['label_bn']  ?: $colors[$key]['label'];
                    $colors[$key]['color'] = $row['color_hex'] ?: $colors[$key]['color'];
                    $colors[$key]['bg']    = $row['bg_hex']    ?: $colors[$key]['bg'];
                }
            }
        } catch (Throwable $e) {
            // টেবিল না থাকলে ডিফল্ট কালারেই চলবে
        }
        return $colors;
    }

    /** badge CSS — একই পেজে বারবার লোড না হওয়ার জন্য */
    private static function badgeStyle(): string
    {
        static $printed = false;
        if ($printed) return '';
        $printed = true;
        return '
        <style>
        .sl-badge {
            display:inline-flex; align-items:center; gap:.3rem;
            padding:.2rem .55rem; border-radius:.4rem; border:1.5px solid;
            font-size:.72rem; font-weight:800; white-space:nowrap;
        }
        .sl-move { display:inline-flex; align-items:center; gap:.3rem; flex-wrap:wrap; }
        .sl-arrow { color:var(--sk-muted); font-size:.7rem; }
        </style>';
    }
}
