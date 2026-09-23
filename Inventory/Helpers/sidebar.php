<?php
// inventory/Helpers/sidebar.php
// ইউনিফর্ম সাইডবার – সব পেজে ইনক্লুড করবেন

$role = $_SESSION['role'] ?? 'user';
$currentFile = basename($_SERVER['PHP_SELF']);

function isActive($filename) {
    global $currentFile;
    return $currentFile === $filename ? 'active' : '';
}
?>
<style>
/* ----- টগল সুইচ (কাস্টম) ----- */
.switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
    flex-shrink: 0;
}
.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background: var(--sk-muted);
    transition: .3s;
    border-radius: 24px;
}
.slider::before {
    content: "";
    position: absolute;
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background: #fff;
    transition: .3s;
    border-radius: 50%;
}
input:checked + .slider {
    background: var(--sk-brand);
}
input:checked + .slider::before {
    transform: translateX(20px);
}
/* অ্যাডমিন লিংক কন্টেইনার — টগল দিয়ে দেখাবে/লুকাবে */
.admin-links {
    display: none;
}
.admin-links.open {
    display: grid;
}
/* ডিভাইডার লাইন (মাছ বরাবর দাগ) */
.admin-divider {
    margin: 12px 0 8px;
    border-top: 2px dashed var(--sk-line-2);
    opacity: 0.6;
}
</style>

<!-- সাইডবার ওভারলে -->
<div class="sk-overlay" id="myOverlay" onclick="toggleSidebar()"></div>

<!-- সাইডবার (ড্রয়ার) -->
<aside class="sk-drawer" id="mySidebar">
    <div class="sk-drawer__head">
        <button class="sk-drawer__close" onclick="toggleSidebar()"><i class="fas fa-times"></i></button>
        <img src="logo.png" alt="Logo" onerror="this.style.display='none'" class="sk-drawer__logo">
        <div class="sk-drawer__brand">SADA KALO</div>
        <div class="sk-drawer__sub">FASHION</div>
    </div>

    <!-- ১. প্রধান -->
    <div class="sk-drawer__section">প্রধান</div>
    <div class="sk-drawer__grid">
        <a href="../dashboard.php" class="sk-drawer__item <?php echo isActive('dashboard.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-home"></i></div>
            <span class="sk-drawer__label">হোমপেজ</span>
        </a>
        <a href="inventory_dashboard.php" class="sk-drawer__item <?php echo isActive('inventory_dashboard.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-th-large"></i></div>
            <span class="sk-drawer__label">ড্যাশবোর্ড</span>
        </a>
    </div>

    <!-- ২. ইনভেন্টরি -->
    <div class="sk-drawer__section">ইনভেন্টরি</div>
    <div class="sk-drawer__grid">
        <a href="inventory.php" class="sk-drawer__item <?php echo isActive('inventory.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-plus"></i></div>
            <span class="sk-drawer__label">Add Item</span>
        </a>
        <a href="Inventory_Items.php" class="sk-drawer__item <?php echo isActive('Inventory_Items.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-box-open"></i></div>
            <span class="sk-drawer__label">Item List</span>
        </a>
        <a href="out_of_stock.php" class="sk-drawer__item <?php echo isActive('out_of_stock.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-exclamation-triangle"></i></div>
            <span class="sk-drawer__label">Out Stock</span>
        </a>
        <a href="category_mange.php" class="sk-drawer__item <?php echo isActive('category_mange.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-folder-tree"></i></div>
            <span class="sk-drawer__label">ক্যাটাগরি</span>
        </a>
    </div>

    <!-- ৩. বিক্রয় ও রিটার্ন -->
    <div class="sk-drawer__section">বিক্রয় ও রিটার্ন</div>
    <div class="sk-drawer__grid">
        <a href="inventory_pos.php" class="sk-drawer__item <?php echo isActive('inventory_pos.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-shopping-cart"></i></div>
            <span class="sk-drawer__label">POS Sell</span>
        </a>
        <a href="inventory_sales_history.php" class="sk-drawer__item <?php echo isActive('inventory_sales_history.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-receipt"></i></div>
            <span class="sk-drawer__label">History</span>
        </a>
        <a href="return_product.php" class="sk-drawer__item <?php echo isActive('return_product.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-undo-alt"></i></div>
            <span class="sk-drawer__label">Return</span>
        </a>
    </div>

    <!-- ৪. সাপ্লায়ার -->
    <div class="sk-drawer__section">সাপ্লায়ার</div>
    <div class="sk-drawer__grid">
        <a href="supplier_exchange.php" class="sk-drawer__item <?php echo isActive('supplier_exchange.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-exchange-alt"></i></div>
            <span class="sk-drawer__label">Exchange</span>
        </a>
        <a href="supplier_exchange_report.php" class="sk-drawer__item <?php echo isActive('supplier_exchange_report.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-file-alt"></i></div>
            <span class="sk-drawer__label">Exchange Report</span>
        </a>
        <a href="supplier_return_approval.php" class="sk-drawer__item <?php echo isActive('supplier_return_approval.php'); ?>">
            <div class="sk-drawer__icon"><i class="fas fa-check-double"></i></div>
            <span class="sk-drawer__label">Return Approval</span>
        </a>
    </div>

    <!-- ৫. অ্যাডমিন (শুধুমাত্র অ্যাডমিনের জন্য, টগল সহ) -->
    <?php if ($role === 'admin'): ?>
        <!-- ডিভাইডার লাইন (মাছ বরাবর দাগ) -->
        <div class="admin-divider"></div>

        <div class="sk-drawer__section" style="display:flex; justify-content:space-between; align-items:center;">
            <span><i class="fas fa-user-shield" style="margin-right:6px;"></i> অ্যাডমিন</span>
            <label class="switch" id="adminToggleSwitch">
                <input type="checkbox" id="adminToggleCheckbox">
                <span class="slider round"></span>
            </label>
        </div>

        <!-- অ্যাডমিন লিংক গ্রিড (টগল দিয়ে কন্ট্রোল) -->
        <div class="sk-drawer__grid admin-links" id="adminLinksContainer">
            <a href="admin_inventory_control.php" class="sk-drawer__item <?php echo isActive('admin_inventory_control.php'); ?>">
                <div class="sk-drawer__icon"><i class="fas fa-cogs"></i></div>
                <span class="sk-drawer__label">Inventory Ctrl</span>
            </a>
            <a href="admin_category_control.php" class="sk-drawer__item <?php echo isActive('admin_category_control.php'); ?>">
                <div class="sk-drawer__icon"><i class="fas fa-tags"></i></div>
                <span class="sk-drawer__label">Category Ctrl</span>
            </a>
            <a href="admin_return_history.php" class="sk-drawer__item <?php echo isActive('admin_return_history.php'); ?>">
                <div class="sk-drawer__icon"><i class="fas fa-clock-rotate-left"></i></div>
                <span class="sk-drawer__label">Return History</span>
            </a>
            <a href="product_edit_history.php" class="sk-drawer__item <?php echo isActive('product_edit_history.php'); ?>">
                <div class="sk-drawer__icon"><i class="fas fa-pen-to-square"></i></div>
                <span class="sk-drawer__label">Edit Log</span>
            </a>
            <a href="unsold_inventory.php" class="sk-drawer__item <?php echo isActive('unsold_inventory.php'); ?>">
                <div class="sk-drawer__icon"><i class="fas fa-hourglass-end"></i></div>
                <span class="sk-drawer__label">Unsold</span>
            </a>
            <!-- যদি আরও অ্যাডমিন পেজ থাকে, তবে এখানে যোগ করুন -->
        </div>
    <?php endif; ?>
</aside>

<script>
// সাইডবার টগল ফাংশন (পূর্বের মতো)
function toggleSidebar() {
    const sidebar = document.getElementById("mySidebar");
    const overlay = document.getElementById("myOverlay");
    if (sidebar) sidebar.classList.toggle("open");
    if (overlay) overlay.classList.toggle("active");
}

// অ্যাডমিন টগল সেটিং – লোকাল স্টোরেজে রাখা
(function() {
    const checkbox = document.getElementById('adminToggleCheckbox');
    const linksContainer = document.getElementById('adminLinksContainer');
    if (!checkbox || !linksContainer) return; // অ্যাডমিন না হলে কিছু করো না

    // লোকাল স্টোরেজ থেকে অবস্থা নাও
    const stored = localStorage.getItem('sk-admin-toggle');
    let isOn = (stored === 'on'); // ডিফল্ট 'off'

    // চেকবক্স ও লিংক কন্টেইনার আপডেট করো
    function applyState(state) {
        checkbox.checked = state;
        if (state) {
            linksContainer.classList.add('open');
        } else {
            linksContainer.classList.remove('open');
        }
        localStorage.setItem('sk-admin-toggle', state ? 'on' : 'off');
    }

    // প্রাথমিক অবস্থা সেট করো
    applyState(isOn);

    // চেকবক্স পরিবর্তনে ইভেন্ট
    checkbox.addEventListener('change', function() {
        applyState(this.checked);
    });
})();
</script>