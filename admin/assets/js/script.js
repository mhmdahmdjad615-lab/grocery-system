document.addEventListener('DOMContentLoaded', function () {
    var toggleBtn = document.getElementById('toggleSidebar');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            document.querySelector('.sidebar').classList.toggle('show');
        });
    }

    // إخفاء التنبيهات تلقائياً
    setTimeout(function () {
        document.querySelectorAll('.alert').forEach(function (a) {
            var alert = bootstrap.Alert.getOrCreateInstance(a);
            if (alert) alert.close();
        });
    }, 4000);
});

/* ============ منطق سطور الفاتورة الديناميكية (المشتريات/المبيعات) ============ */
function addInvoiceRow(tableBodyId, productsOptionsHtml) {
    const tbody = document.getElementById(tableBodyId);
    const hasTiers = typeof PRODUCT_PRICE_TIERS !== 'undefined';
    const hasExpiry = typeof PURCHASE_MODE !== 'undefined' && PURCHASE_MODE;
    const hasUnits = typeof PRODUCT_UNITS !== 'undefined';
    const hasMarginCheck = typeof SALE_MODE !== 'undefined' && SALE_MODE;
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>
            <select name="product_id[]" class="form-select product-select" required onchange="fillProductPrice(this)">
                <option value="">-- اختر منتج --</option>
                ${productsOptionsHtml}
            </select>
        </td>
        <td><input type="number" step="0.01" min="0.01" name="quantity[]" class="form-control qty-input" required oninput="calcRowTotal(this)"></td>
        ${hasUnits ? '<td><select name="unit_id[]" class="form-select unit-select" onchange="handleUnitChange(this)"></select></td>' : ''}
        ${hasTiers ? '<td><select class="form-select tier-select" onchange="applyTierPrice(this)"><option value="">افتراضي</option></select></td>' : ''}
        <td><input type="number" step="0.01" min="0" name="price[]" class="form-control price-input" required oninput="calcRowTotal(this)"></td>
        <td><input type="text" class="form-control row-total" value="0.00" readonly></td>
        ${hasExpiry ? '<td><input type="date" name="expiry_date[]" class="form-control" title="تاريخ الصلاحية (اختياري)"></td>' : ''}
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="fa-solid fa-trash"></i></button></td>
    `;
    tbody.appendChild(row);
}

function removeRow(btn) {
    const tbody = btn.closest('tbody');
    if (tbody.querySelectorAll('tr').length > 1) {
        btn.closest('tr').remove();
    }
    calcInvoiceTotal();
}

function fillProductPrice(select) {
    const opt = select.selectedOptions[0];
    const row = select.closest('tr');
    const priceInput = row.querySelector('.price-input');
    if (opt && opt.dataset.price) {
        priceInput.value = opt.dataset.price;
    }

    // تعبئة قائمة الوحدات المتاحة لهذا المنتج (كرتونة/طن/قطعة...)
    const unitSelect = row.querySelector('.unit-select');
    if (unitSelect && typeof PRODUCT_UNITS !== 'undefined') {
        unitSelect.innerHTML = '';
        const units = PRODUCT_UNITS[opt.value] || [];
        units.forEach(function (u) {
            const o = document.createElement('option');
            o.value = u.id;
            o.dataset.factor = u.factor;
            o.textContent = u.name + (u.is_base ? ' (أساسية)' : ' = ' + u.factor + ' ' + u.base_name);
            if (u.is_base) o.selected = true;
            unitSelect.appendChild(o);
        });
    }

    // تعبئة قائمة مستويات الأسعار المتعددة إن وجدت لهذا المنتج
    const tierSelect = row.querySelector('.tier-select');
    if (tierSelect && typeof PRODUCT_PRICE_TIERS !== 'undefined') {
        tierSelect.innerHTML = '';
        const tiers = PRODUCT_PRICE_TIERS[opt.value] || [];
        tiers.forEach(function (t, idx) {
            const o = document.createElement('option');
            o.value = t.price;
            o.textContent = t.name + ' (' + Number(t.price).toFixed(2) + ')';
            if (idx === 0) o.selected = true;
            tierSelect.appendChild(o);
        });
    }
    calcRowTotal(select);
    checkMargin(row);
}

// عند تغيير الوحدة المختارة (كرتونة بدل قطعة مثلاً): يُعاد اقتراح السعر بضربه في معامل التحويل
function handleUnitChange(unitSelect) {
    const row = unitSelect.closest('tr');
    const productOpt = row.querySelector('.product-select').selectedOptions[0];
    const factor = parseFloat(unitSelect.selectedOptions[0]?.dataset.factor) || 1;
    const priceInput = row.querySelector('.price-input');
    if (productOpt && productOpt.dataset.price) {
        priceInput.value = (parseFloat(productOpt.dataset.price) * factor).toFixed(2);
    }
    calcRowTotal(unitSelect);
    checkMargin(row);
}

function applyTierPrice(select) {
    const row = select.closest('tr');
    const priceInput = row.querySelector('.price-input');
    const unitSelect = row.querySelector('.unit-select');
    const factor = unitSelect ? (parseFloat(unitSelect.selectedOptions[0]?.dataset.factor) || 1) : 1;
    if (select.value) {
        priceInput.value = (parseFloat(select.value) * factor).toFixed(2);
    }
    calcRowTotal(select);
}

function calcRowTotal(el) {
    const row = el.closest('tr');
    const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
    const price = parseFloat(row.querySelector('.price-input').value) || 0;
    row.querySelector('.row-total').value = (qty * price).toFixed(2);
    calcInvoiceTotal();
    checkMargin(row);
}

function calcInvoiceTotal() {
    let total = 0;
    document.querySelectorAll('.row-total').forEach(function (inp) {
        total += parseFloat(inp.value) || 0;
    });
    const discountInput = document.getElementById('discountInput');
    const discount = discountInput ? (parseFloat(discountInput.value) || 0) : 0;
    const finalTotal = Math.max(0, total - discount);

    const totalEl = document.getElementById('invoiceTotal');
    if (totalEl) totalEl.value = finalTotal.toFixed(2);
    const totalDisplay = document.getElementById('invoiceTotalDisplay');
    if (totalDisplay) totalDisplay.textContent = finalTotal.toFixed(2);
    const subtotalDisplay = document.getElementById('subtotalDisplay');
    if (subtotalDisplay) subtotalDisplay.textContent = total.toFixed(2);

    // تحديث المتبقي إن وجد
    updateRemain();
}

function updateRemain() {
    const totalEl = document.getElementById('invoiceTotal');
    const paidInput = document.getElementById('paidInput');
    const remainEl = document.getElementById('remainDisplayBox');
    if (totalEl && paidInput && remainEl) {
        const total = parseFloat(totalEl.value) || 0;
        const paid = parseFloat(paidInput.value) || 0;
        remainEl.value = (total - paid).toFixed(2);
    }
}

/* ============ تنبيه هامش الربح المنخفض/السالب وقت البيع ============ */
function checkMargin(row) {
    if (typeof SALE_MODE === 'undefined' || !SALE_MODE) return;
    const productOpt = row.querySelector('.product-select').selectedOptions[0];
    if (!productOpt || !productOpt.value) { clearMarginWarning(row); return; }

    const cost = parseFloat(productOpt.dataset.cost) || 0;
    const unitSelect = row.querySelector('.unit-select');
    const factor = unitSelect ? (parseFloat(unitSelect.selectedOptions[0]?.dataset.factor) || 1) : 1;
    const costForUnit = cost * factor;
    const price = parseFloat(row.querySelector('.price-input').value) || 0;

    let warnCell = row.querySelector('.margin-warning');
    if (!warnCell) {
        warnCell = document.createElement('div');
        warnCell.className = 'margin-warning small mt-1';
        row.querySelector('.price-input').closest('td').appendChild(warnCell);
    }

    if (price > 0 && costForUnit > 0 && price < costForUnit) {
        warnCell.innerHTML = '<span class="text-danger"><i class="fa-solid fa-triangle-exclamation"></i> السعر أقل من التكلفة (' + costForUnit.toFixed(2) + ')!</span>';
    } else {
        warnCell.innerHTML = '';
    }
}

function clearMarginWarning(row) {
    const warnCell = row.querySelector('.margin-warning');
    if (warnCell) warnCell.innerHTML = '';
}
