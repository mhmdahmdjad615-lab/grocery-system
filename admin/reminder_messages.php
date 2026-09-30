<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$min_days = (int)($_GET['min_days'] ?? 30);

$rows = $pdo->prepare("
    SELECT c.id, c.name, c.phone, c.balance, MIN(s.invoice_date) oldest_unpaid_date
    FROM customers c
    JOIN sale_invoices s ON s.customer_id = c.id AND (s.total - s.paid) > 0.001
    WHERE c.balance > 0
    GROUP BY c.id
    HAVING DATEDIFF(CURDATE(), oldest_unpaid_date) >= ?
    ORDER BY c.balance DESC
");
$rows->execute([$min_days]);
$rows = $rows->fetchAll();

// اسم المحل يمكن تعديله هنا ليظهر بالرسائل
$storeName = 'محل الجملة';
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">تذكير العملاء المتأخرين بالسداد</h4>
</div>

<div class="alert alert-info no-print">
    <i class="fa-solid fa-circle-info"></i>
    رسالة جاهزة الصياغة لكل عميل متأخر، يمكنك نسخها وإرسالها عبر واتساب أو رسالة نصية مباشرة
    دون الحاجة لكتابتها يدوياً في كل مرة.
</div>

<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4">
                <label class="form-label">الحد الأدنى لعدد أيام التأخير</label>
                <input type="number" name="min_days" class="form-control" value="<?= $min_days ?>" min="1">
            </div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-success w-100">تحديث</button></div>
        </form>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($rows as $r):
        $daysLate = (int)((strtotime(date('Y-m-d')) - strtotime($r['oldest_unpaid_date'])) / 86400);
        $message = "مرحباً {$r['name']}،\n"
            . "نود تذكيركم بوجود رصيد مستحق لدى {$storeName} بقيمة " . number_format($r['balance'], 2) . " " . CURRENCY . "\n"
            . "منذ حوالي {$daysLate} يوماً. نرجو التكرم بسداده في أقرب وقت ممكن.\n"
            . "شاكرين تعاونكم الدائم معنا.";
    ?>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><?= e($r['name']) ?> <span class="text-muted">(<?= e($r['phone']) ?>)</span></span>
                <span class="badge bg-danger">متأخر <?= $daysLate ?> يوم</span>
            </div>
            <div class="card-body">
                <textarea class="form-control mb-2" rows="5" readonly id="msg_<?= $r['id'] ?>"><?= e($message) ?></textarea>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-secondary" onclick="copyMessage('msg_<?= $r['id'] ?>')"><i class="fa-solid fa-copy"></i> نسخ الرسالة</button>
                    <?php
                        $phoneDigits = preg_replace('/[^0-9]/', '', $r['phone']);
                        $waLink = $phoneDigits ? 'https://wa.me/' . $phoneDigits . '?text=' . urlencode($message) : null;
                    ?>
                    <?php if ($waLink): ?>
                    <a href="<?= $waLink ?>" target="_blank" class="btn btn-sm btn-success"><i class="fa-brands fa-whatsapp"></i> فتح واتساب</a>
                    <?php endif; ?>
                    <a href="customer_statement.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">كشف الحساب</a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
    <div class="col-12"><div class="alert alert-success">لا يوجد عملاء متأخرون بهذا الحد الأدنى من الأيام 🎉</div></div>
    <?php endif; ?>
</div>

<script>
function copyMessage(id) {
    const el = document.getElementById(id);
    el.select();
    document.execCommand('copy');
    alert('تم نسخ الرسالة');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
