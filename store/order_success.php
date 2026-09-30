<?php
require_once __DIR__ . '/includes/store_functions.php';
store_require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM store_orders WHERE id = ? AND store_customer_id = ?");
$stmt->execute([$id, store_current_id()]);
$order = $stmt->fetch();
if (!$order) redirect('index.php');

require_once __DIR__ . '/includes/store_header.php';
?>
<div class="container" style="padding:64px 16px;text-align:center;max-width:560px">
    <div class="gcard fade-in" style="padding:48px">
        <div style="width:80px;height:80px;border-radius:50%;background:rgba(16,185,129,.12);display:flex;align-items:center;justify-content:center;margin:0 auto 20px">
            <i class="fa-solid fa-check text-success" style="font-size:34px"></i>
        </div>
        <h2 style="margin-bottom:8px">تم إرسال طلبك بنجاح</h2>
        <p class="text-muted-store mb-4">رقم الطلب <strong class="text-navy"><?= e($order['order_number']) ?></strong></p>

        <div class="gcard" style="background:var(--g-50);border:none;padding:16px;margin-bottom:24px">
            <div class="d-flex justify-content-between text-sm"><span class="text-muted-store">إجمالي الطلب</span><span class="tabular-num fw-bold" style="color:var(--p-700)"><?= money($order['total']) ?></span></div>
        </div>

        <p class="text-sm text-muted-store mb-4">سيتم مراجعة طلبك واعتماده من الإدارة قريباً، ويمكنك متابعة حالته في أي وقت من صفحة طلباتي.</p>
        <div class="d-flex justify-content-center gap-2 flex-wrap">
            <a href="order_view.php?id=<?= $order['id'] ?>" class="btn btn-primary">متابعة حالة الطلب</a>
            <a href="index.php" class="btn btn-outline">متابعة التسوق</a>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/store_footer.php'; ?>
