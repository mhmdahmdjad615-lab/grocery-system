<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$p1_from = $_GET['p1_from'] ?? date('Y-m-01', strtotime('-1 month'));
$p1_to   = $_GET['p1_to'] ?? date('Y-m-t', strtotime('-1 month'));
$p2_from = $_GET['p2_from'] ?? date('Y-m-01');
$p2_to   = $_GET['p2_to'] ?? date('Y-m-d');

// [مُصحَّح] المبيعات والربح الآن صافيان بعد خصم أي مرتجعات بنفس الفترة (لم تكن تُخصم سابقاً)
function period_metrics($pdo, $from, $to) {
    $m = [];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) t, COUNT(*) c FROM sale_invoices WHERE invoice_date BETWEEN ? AND ? AND invoice_type = 'sale'");
    $stmt->execute([$from, $to]);
    $r = $stmt->fetch();
    $grossSales = (float)$r['t'];
    $m['invoices'] = (int)$r['c'];

    $retStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM sale_returns WHERE return_date BETWEEN ? AND ?");
    $retStmt->execute([$from, $to]);
    $salesReturns = (float)$retStmt->fetch()['t'];
    $m['sales'] = $grossSales - $salesReturns;

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(si.total),0) revenue, COALESCE(SUM(si.quantity*si.cost_price),0) cost, COALESCE(SUM(si.quantity),0) qty
        FROM sale_items si JOIN sale_invoices s ON s.id = si.invoice_id
        WHERE s.invoice_date BETWEEN ? AND ? AND s.invoice_type = 'sale'
    ");
    $stmt->execute([$from, $to]);
    $r = $stmt->fetch();

    $retCostStmt = $pdo->prepare("
        SELECT COALESCE(SUM(sri.total),0) revenue, COALESCE(SUM(CASE WHEN sri.restocked=1 THEN sri.quantity*sri.cost_price ELSE 0 END),0) cost
        FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.return_id
        WHERE sr.return_date BETWEEN ? AND ?
    ");
    $retCostStmt->execute([$from, $to]);
    $rr = $retCostStmt->fetch();

    $m['profit'] = ((float)$r['revenue'] - (float)$rr['revenue']) - ((float)$r['cost'] - (float)$rr['cost']);
    $m['qty_sold'] = (float)$r['qty'];

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT customer_id) c FROM sale_invoices WHERE invoice_date BETWEEN ? AND ? AND invoice_type = 'sale'");
    $stmt->execute([$from, $to]);
    $m['active_customers'] = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM purchase_invoices WHERE invoice_date BETWEEN ? AND ?");
    $stmt->execute([$from, $to]);
    $grossPurchases = (float)$stmt->fetch()['t'];
    $purchRetStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM purchase_returns WHERE return_date BETWEEN ? AND ?");
    $purchRetStmt->execute([$from, $to]);
    $m['purchases'] = $grossPurchases - (float)$purchRetStmt->fetch()['t'];

    $m['avg_invoice'] = $m['invoices'] > 0 ? $m['sales'] / $m['invoices'] : 0;

    return $m;
}

$p1 = period_metrics($pdo, $p1_from, $p1_to);
$p2 = period_metrics($pdo, $p2_from, $p2_to);

function pct_change($old, $new) {
    if ($old == 0) return $new > 0 ? null : 0;
    return (($new - $old) / abs($old)) * 100;
}

$metrics = [
    'sales' => 'صافي المبيعات (بعد المرتجعات)',
    'invoices' => 'عدد فواتير البيع',
    'avg_invoice' => 'متوسط قيمة الفاتورة',
    'profit' => 'إجمالي الربح',
    'qty_sold' => 'إجمالي الكمية المباعة',
    'active_customers' => 'عدد العملاء النشطين',
    'purchases' => 'صافي المشتريات (بعد المرتجعات)',
];
?>
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0">مقارنة الأداء بين فترتين</h4>
    <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
</div>

<div class="alert alert-success no-print">
    <i class="fa-solid fa-circle-check"></i>
    المبيعات والمشتريات والربح المعروضة هنا الآن صافية بعد خصم أي مرتجعات بنفس كل فترة، لضمان
    مقارنة دقيقة وعادلة بين الفترتين.
</div>

<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-6">
                <label class="form-label fw-bold text-secondary">الفترة الأولى (المرجع)</label>
                <div class="row g-2">
                    <div class="col-6"><input type="date" name="p1_from" class="form-control" value="<?= e($p1_from) ?>"></div>
                    <div class="col-6"><input type="date" name="p1_to" class="form-control" value="<?= e($p1_to) ?>"></div>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-bold text-success">الفترة الثانية (الحالية)</label>
                <div class="row g-2">
                    <div class="col-6"><input type="date" name="p2_from" class="form-control" value="<?= e($p2_from) ?>"></div>
                    <div class="col-6"><input type="date" name="p2_to" class="form-control" value="<?= e($p2_to) ?>"></div>
                </div>
            </div>
            <div class="col-12"><button class="btn btn-success">مقارنة</button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        الفترة 1: <?= e($p1_from) ?> إلى <?= e($p1_to) ?> &nbsp;|&nbsp;
        الفترة 2: <?= e($p2_from) ?> إلى <?= e($p2_to) ?>
    </div>
    <div class="card-body p-0">
        <table class="table mb-0 align-middle">
            <thead><tr><th>المؤشر</th><th>الفترة 1</th><th>الفترة 2</th><th>التغيّر</th></tr></thead>
            <tbody>
            <?php foreach ($metrics as $key => $label):
                $v1 = $p1[$key]; $v2 = $p2[$key];
                $change = pct_change($v1, $v2);
                $isMoney = in_array($key, ['sales','profit','purchases','avg_invoice']);
            ?>
                <tr>
                    <td><?= $label ?></td>
                    <td><?= $isMoney ? money($v1) : rtrim(rtrim(number_format($v1,2),'0'),'.') ?></td>
                    <td><?= $isMoney ? money($v2) : rtrim(rtrim(number_format($v2,2),'0'),'.') ?></td>
                    <td>
                        <?php if ($change === null): ?>
                            <span class="badge bg-success">جديد</span>
                        <?php else: ?>
                            <span class="badge <?= $change>=0?'bg-success':'bg-danger' ?>">
                                <i class="fa-solid fa-arrow-<?= $change>=0?'up':'down' ?>"></i> <?= number_format(abs($change),1) ?>%
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
