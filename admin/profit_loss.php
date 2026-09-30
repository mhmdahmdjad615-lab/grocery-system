<?php
require_once __DIR__ . '/includes/header.php';
require_admin();

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to'] ?? date('Y-m-d');

// [دقيق 100%] المعادلة المحاسبية الصحيحة: صافي المبيعات (بعد المرتجعات) − صافي تكلفة
// البضاعة المباعة فعلياً (COGS الحقيقي وقت البيع) = مجمل الربح، ثم + الإيرادات الأخرى
// − النفقات الأخرى = صافي الربح. المشتريات وقيمة المخزون لا تدخلان في معادلة الربح
// (المخزون أصل وليس إيراداً، والشراء إنفاق لبناء ذلك الأصل).
$report = get_accurate_profit_report($pdo, $from, $to);

$gross_sales = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM sale_invoices WHERE invoice_date BETWEEN ? AND ?");
$gross_sales->execute([$from, $to]);
$gross_sales_total = (float)$gross_sales->fetch()['t'];
$sales_returns_total = $gross_sales_total - $report['net_sales_revenue'];
$return_rate = $gross_sales_total > 0 ? ($sales_returns_total / $gross_sales_total * 100) : 0;

$gross_purchases = $pdo->prepare("SELECT COALESCE(SUM(total),0) t FROM purchase_invoices WHERE invoice_date BETWEEN ? AND ?");
$gross_purchases->execute([$from, $to]);
$gross_purchases_total = (float)$gross_purchases->fetch()['t'];
$purchase_returns_total = $gross_purchases_total - $report['net_purchases'];

$expenseRowsStmt = $pdo->prepare("SELECT category, SUM(amount) total FROM expenses WHERE type='expense' AND expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$expenseRowsStmt->execute([$from, $to]);
$expenseRows = $expenseRowsStmt->fetchAll();

$incomeRowsStmt = $pdo->prepare("SELECT category, SUM(amount) total FROM expenses WHERE type='income' AND expense_date BETWEEN ? AND ? GROUP BY category ORDER BY total DESC");
$incomeRowsStmt->execute([$from, $to]);
$incomeRows = $incomeRowsStmt->fetchAll();

// الموقف المالي الحالي (لحظي، غير مرتبط بالفترة)
$total_receivables = (float)$pdo->query("SELECT COALESCE(SUM(balance),0) t FROM customers")->fetch()['t'];
$total_payables = (float)$pdo->query("SELECT COALESCE(SUM(balance),0) t FROM suppliers")->fetch()['t'];
$cash_balance = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN type='in' THEN amount ELSE -amount END),0) t FROM cash_movements")->fetch()['t'];
$inventory_value_cost = (float)$pdo->query("SELECT COALESCE(SUM(quantity * purchase_price),0) v FROM stock_batches WHERE location='selling'")->fetch()['v'];
$inventory_value_selling = (float)$pdo->query("SELECT COALESCE(SUM(quantity * sale_price),0) v FROM stock_batches WHERE location='selling'")->fetch()['v'];

$position_no_inv = $cash_balance + $total_receivables - $total_payables;
$position_with_cost = $position_no_inv + $inventory_value_cost;
$position_with_selling = $position_no_inv + $inventory_value_selling;

// نسب لتصور الأشرطة المرئية (waterfall) بمقياس موحّد
$waterfallMax = max(1, abs($report['net_sales_revenue']), abs($report['net_cogs']), abs($report['gross_profit']),
    abs($report['other_income']), abs($report['other_expenses']), abs($report['net_profit']));
function pl_bar($val, $max) { return $max > 0 ? min(100, round(abs($val) / $max * 100)) : 0; }

$margin_pct = $report['net_sales_revenue'] > 0 ? ($report['gross_profit'] / $report['net_sales_revenue'] * 100) : null;
$net_margin_pct = $report['net_sales_revenue'] > 0 ? ($report['net_profit'] / $report['net_sales_revenue'] * 100) : null;
?>
<style>
.pl-wrap { --pl-green:#198754; --pl-green-dark:#14532d; --pl-red:#dc3545; --pl-blue:#0d6efd; --pl-gold:#B8860B; --pl-gray:#6c757d; }
.pl-hero { background: linear-gradient(135deg, var(--primary-dark), var(--primary)); border-radius: 18px; color:#fff; padding: 32px; position:relative; overflow:hidden; }
.pl-hero::after { content:""; position:absolute; inset:0; background: radial-gradient(600px 300px at 90% -20%, rgba(255,255,255,.12), transparent 60%); }
.pl-hero .pl-label { font-size:14px; opacity:.85; margin-bottom:6px; }
.pl-hero .pl-value { font-size:42px; font-weight:800; line-height:1.1; }
.pl-hero .pl-sub { font-size:13px; opacity:.8; margin-top:8px; }
.pl-hero.negative { background: linear-gradient(135deg, #5a1418, #8a1c22); }
.pl-mini-stats { display:flex; gap:24px; margin-top:22px; flex-wrap:wrap; position:relative; z-index:1; }
.pl-mini-stat { background:rgba(255,255,255,.12); border-radius:12px; padding:12px 18px; min-width:140px; }
.pl-mini-stat .n { font-size:20px; font-weight:700; }
.pl-mini-stat .l { font-size:12px; opacity:.85; }

.pl-card { background:#fff; border-radius:14px; box-shadow:0 2px 10px rgba(0,0,0,.05); padding:22px; }
.pl-card.pl-fill { height:100%; }
.pl-card h6 { font-weight:700; margin-bottom:16px; display:flex; align-items:center; gap:8px; }

.pl-step { display:flex; align-items:center; gap:14px; padding:10px 0; border-bottom:1px dashed #e9ecef; }
.pl-step:last-child { border-bottom:none; }
.pl-step .op { width:26px; height:26px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; color:#fff; flex-shrink:0; }
.pl-step .op.plus { background:var(--pl-green); }
.pl-step .op.minus { background:var(--pl-red); }
.pl-step .op.eq { background:var(--pl-gray); }
.pl-step .body { flex:1; min-width:0; }
.pl-step .lbl { font-size:13.5px; font-weight:600; color:#333; display:flex; justify-content:space-between; }
.pl-step .lbl b { font-family: inherit; }
.pl-step .track { height:8px; border-radius:6px; background:#f1f3f5; margin-top:6px; overflow:hidden; }
.pl-step .fill { height:100%; border-radius:6px; }
.pl-step.result .lbl { font-size:15px; }
.pl-step.result .op { width:30px; height:30px; }

.pl-compare-table td, .pl-compare-table th { vertical-align:middle; }
.pl-scenario { border-radius:12px; padding:16px; text-align:center; height:100%; border:2px solid transparent; }
.pl-scenario .tag { font-size:11px; font-weight:700; padding:3px 10px; border-radius:99px; display:inline-block; margin-bottom:8px; }
.pl-scenario .val { font-size:22px; font-weight:800; margin:4px 0; }
.pl-scenario.conservative { background:#eef7f0; border-color:#c3e6cb; }
.pl-scenario.moderate { background:#eef2fb; border-color:#c7d2fe; }
.pl-scenario.optimistic { background:#fff8e6; border-color:#ffe08a; }

@media print { .pl-hero { background:#14532d !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style>

<div class="pl-wrap">
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <h4 class="mb-0"><i class="fa-solid fa-scale-balanced"></i> قائمة الأرباح والخسائر</h4>
    <div>
        <button onclick="window.print()" class="btn btn-outline-secondary"><i class="fa-solid fa-print"></i> طباعة</button>
        <a href="reports.php" class="btn btn-outline-dark"><i class="fa-solid fa-arrow-right"></i> رجوع للتقارير</a>
    </div>
</div>

<div class="card mb-4 no-print">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-4"><label class="form-label">من تاريخ</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
            <div class="col-md-4"><label class="form-label">إلى تاريخ</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
            <div class="col-md-4 d-flex align-items-end"><button class="btn btn-success w-100">عرض</button></div>
        </form>
    </div>
</div>

<!-- ===== Hero: صافي الربح ===== -->
<div class="pl-hero <?= $report['net_profit']<0?'negative':'' ?> mb-4">
    <div class="pl-label"><i class="fa-solid fa-calendar-days"></i> الفترة من <?= e($from) ?> إلى <?= e($to) ?></div>
    <div class="pl-value"><?= money($report['net_profit']) ?></div>
    <div class="pl-sub">صافي الربح <?= $net_margin_pct !== null ? '— بهامش صافي ' . number_format($net_margin_pct,1) . '% من صافي المبيعات' : '' ?></div>
    <div class="pl-mini-stats">
        <div class="pl-mini-stat"><div class="n"><?= money($report['net_sales_revenue']) ?></div><div class="l">صافي المبيعات</div></div>
        <div class="pl-mini-stat"><div class="n"><?= money($report['net_cogs']) ?></div><div class="l">تكلفة البضاعة المباعة</div></div>
        <div class="pl-mini-stat"><div class="n"><?= money($report['gross_profit']) ?></div><div class="l">مجمل الربح<?= $margin_pct!==null ? ' ('.number_format($margin_pct,1).'%)' : '' ?></div></div>
        <div class="pl-mini-stat"><div class="n"><?= money($report['other_income'] - $report['other_expenses']) ?></div><div class="l">صافي الأنشطة الأخرى</div></div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- ===== خطوات الوصول للربح (Waterfall) ===== -->
    <div class="col-lg-7">
        <div class="pl-card pl-fill">
            <h6><i class="fa-solid fa-route text-success"></i> كيف وصلنا لهذا الرقم؟</h6>

            <div class="pl-step">
                <span class="op eq">$</span>
                <div class="body">
                    <div class="lbl"><span>صافي المبيعات <small class="text-muted">(بعد خصم <?= money($sales_returns_total) ?> مرتجعات)</small></span><b class="text-dark"><?= money($report['net_sales_revenue']) ?></b></div>
                    <div class="track"><div class="fill" style="width:<?= pl_bar($report['net_sales_revenue'],$waterfallMax) ?>%;background:var(--pl-blue)"></div></div>
                </div>
            </div>
            <div class="pl-step">
                <span class="op minus">−</span>
                <div class="body">
                    <div class="lbl"><span>تكلفة البضاعة المباعة (COGS الفعلي وقت البيع)</span><b class="text-danger"><?= money($report['net_cogs']) ?></b></div>
                    <div class="track"><div class="fill" style="width:<?= pl_bar($report['net_cogs'],$waterfallMax) ?>%;background:var(--pl-red)"></div></div>
                </div>
            </div>
            <div class="pl-step result">
                <span class="op eq">=</span>
                <div class="body">
                    <div class="lbl"><span class="fw-bold">مجمل الربح (Gross Profit)</span><b class="<?= $report['gross_profit']>=0?'text-success':'text-danger' ?>"><?= money($report['gross_profit']) ?></b></div>
                    <div class="track"><div class="fill" style="width:<?= pl_bar($report['gross_profit'],$waterfallMax) ?>%;background:<?= $report['gross_profit']>=0?'var(--pl-green)':'var(--pl-red)' ?>"></div></div>
                </div>
            </div>
            <div class="pl-step">
                <span class="op plus">+</span>
                <div class="body">
                    <div class="lbl"><span>الإيرادات الأخرى</span><b class="text-success"><?= money($report['other_income']) ?></b></div>
                    <div class="track"><div class="fill" style="width:<?= pl_bar($report['other_income'],$waterfallMax) ?>%;background:var(--pl-green)"></div></div>
                </div>
            </div>
            <div class="pl-step">
                <span class="op minus">−</span>
                <div class="body">
                    <div class="lbl"><span>النفقات الأخرى (تشغيلية)</span><b class="text-danger"><?= money($report['other_expenses']) ?></b></div>
                    <div class="track"><div class="fill" style="width:<?= pl_bar($report['other_expenses'],$waterfallMax) ?>%;background:var(--pl-red)"></div></div>
                </div>
            </div>
            <div class="pl-step result">
                <span class="op eq" style="background:<?= $report['net_profit']>=0?'var(--pl-green-dark)':'var(--pl-red)' ?>">=</span>
                <div class="body">
                    <div class="lbl"><span class="fw-bold fs-6">صافي الربح</span><b class="fs-5 <?= $report['net_profit']>=0?'text-success':'text-danger' ?>"><?= money($report['net_profit']) ?></b></div>
                    <div class="track"><div class="fill" style="width:<?= pl_bar($report['net_profit'],$waterfallMax) ?>%;background:<?= $report['net_profit']>=0?'var(--pl-green-dark)':'var(--pl-red)' ?>"></div></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== تفاصيل جانبية: مبيعات/مرتجعات + مشتريات إعلامي ===== -->
    <div class="col-lg-5">
        <div class="pl-card mb-4">
            <h6><i class="fa-solid fa-cash-register text-primary"></i> تفاصيل المبيعات</h6>
            <div class="d-flex justify-content-between text-sm mb-1"><span>إجمالي فواتير المبيعات</span><b><?= money($gross_sales_total) ?></b></div>
            <div class="track mb-3"><div class="fill" style="width:100%;background:var(--pl-blue)"></div></div>
            <div class="d-flex justify-content-between text-sm mb-1"><span>مرتجعات المبيعات <small class="text-muted">(<?= number_format($return_rate,1) ?>%)</small></span><b class="text-danger"><?= money($sales_returns_total) ?></b></div>
            <div class="track mb-3"><div class="fill" style="width:<?= min(100,$return_rate) ?>%;background:var(--pl-red)"></div></div>
            <div class="d-flex justify-content-between border-top pt-2"><span class="fw-bold">صافي المبيعات</span><b class="text-success"><?= money($report['net_sales_revenue']) ?></b></div>
        </div>

        <div class="pl-card" style="background:#f8f9fa">
            <h6><i class="fa-solid fa-circle-info text-secondary"></i> معلومات إضافية (لا تدخل بحساب الربح)</h6>
            <p class="text-xs text-muted mb-3">المشتريات إنفاق على بناء المخزون (أصل)، وتتحوّل لتكلفة فقط لحظة البيع الفعلي — لذا لا تُحتسب هنا كخسارة مباشرة.</p>
            <div class="d-flex justify-content-between text-sm mb-1"><span>إجمالي فواتير الشراء</span><b><?= money($gross_purchases_total) ?></b></div>
            <div class="d-flex justify-content-between text-sm mb-1"><span>مرتجعات المشتريات</span><b class="text-danger"><?= money($purchase_returns_total) ?></b></div>
            <div class="d-flex justify-content-between border-top pt-2 mt-1"><span class="fw-bold">صافي المشتريات</span><b><?= money($report['net_purchases']) ?></b></div>
        </div>
    </div>
</div>

<!-- ===== الإيرادات والنفقات الأخرى ===== -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="pl-card pl-fill">
            <h6><i class="fa-solid fa-arrow-trend-up text-success"></i> الإيرادات الأخرى حسب التصنيف</h6>
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($incomeRows as $r): $pct = $report['other_income']>0 ? ($r['total']/$report['other_income']*100) : 0; ?>
                    <tr>
                        <td style="width:35%"><?= e($r['category']) ?></td>
                        <td>
                            <div class="track"><div class="fill" style="width:<?= $pct ?>%;background:var(--pl-green)"></div></div>
                        </td>
                        <td class="text-end fw-bold" style="width:110px"><?= money($r['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($incomeRows)): ?><tr><td colspan="3" class="text-center text-muted py-3">لا توجد إيرادات أخرى</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-md-6">
        <div class="pl-card pl-fill">
            <h6><i class="fa-solid fa-arrow-trend-down text-danger"></i> النفقات الأخرى حسب التصنيف</h6>
            <table class="table table-sm mb-0">
                <tbody>
                <?php foreach ($expenseRows as $r): $pct = $report['other_expenses']>0 ? ($r['total']/$report['other_expenses']*100) : 0; ?>
                    <tr>
                        <td style="width:35%"><?= e($r['category']) ?></td>
                        <td>
                            <div class="track"><div class="fill" style="width:<?= $pct ?>%;background:var(--pl-red)"></div></div>
                        </td>
                        <td class="text-end fw-bold" style="width:110px"><?= money($r['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($expenseRows)): ?><tr><td colspan="3" class="text-center text-muted py-3">لا توجد نفقات أخرى</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ===== الموقف المالي الحالي (Balance Sheet) ===== -->
<div class="pl-card">
    <h6><i class="fa-solid fa-landmark text-primary"></i> الموقف المالي الحالي (لحظي، غير مرتبط بالفترة)</h6>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card"><div class="icon-box" style="background:#198754"><i class="fa-solid fa-hand-holding-dollar"></i></div>
            <div><div class="value"><?= money($total_receivables) ?></div><div class="label">المستحق لنا (العملاء)</div></div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card"><div class="icon-box" style="background:#dc3545"><i class="fa-solid fa-scale-unbalanced"></i></div>
            <div><div class="value"><?= money($total_payables) ?></div><div class="label">المستحق علينا (الموردين)</div></div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card"><div class="icon-box" style="background:#0d6efd"><i class="fa-solid fa-sack-dollar"></i></div>
            <div><div class="value"><?= money($cash_balance) ?></div><div class="label">رصيد الخزينة</div></div></div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card"><div class="icon-box" style="background:#6f42c1"><i class="fa-solid fa-warehouse"></i></div>
            <div><div class="value"><?= money($inventory_value_cost) ?></div><div class="label">المخزون (سعر التكلفة)</div></div></div>
        </div>
    </div>

    <p class="text-sm text-muted mb-2"><i class="fa-solid fa-circle-info"></i> ثلاثة سيناريوهات لصافي موقفك المالي لو تم تسييل كل شيء الآن — من الأكثر تحفظاً للأكثر تفاؤلاً:</p>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="pl-scenario conservative">
                <span class="tag" style="background:#c3e6cb;color:#155724">الأكثر تحفظاً</span>
                <div class="text-sm text-muted">خزينة + مستحقات − التزامات</div>
                <div class="val <?= $position_no_inv>=0?'text-success':'text-danger' ?>"><?= money($position_no_inv) ?></div>
                <div class="text-xs text-muted">بدون احتساب المخزون</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="pl-scenario moderate">
                <span class="tag" style="background:#c7d2fe;color:#3730a3">معتدل (موصى به)</span>
                <div class="text-sm text-muted">+ المخزون بسعر التكلفة</div>
                <div class="val <?= $position_with_cost>=0?'text-success':'text-danger' ?>"><?= money($position_with_cost) ?></div>
                <div class="text-xs text-muted">قيمة مضمونة فعلياً</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="pl-scenario optimistic">
                <span class="tag" style="background:#ffe08a;color:#7a5c00">الأكثر تفاؤلاً</span>
                <div class="text-sm text-muted">+ المخزون بسعر البيع الحالي</div>
                <div class="val <?= $position_with_selling>=0?'text-success':'text-danger' ?>"><?= money($position_with_selling) ?></div>
                <div class="text-xs text-muted">يفترض بيع الكل فوراً بسعره</div>
            </div>
        </div>
    </div>
</div>

</div><!-- /.pl-wrap -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
