<?php
// دالة عامة: تصدير مصفوفة بيانات كملف CSV متوافق مع Excel (بدعم كامل للعربية عبر UTF-8 BOM)
// $headers: مصفوفة أسماء الأعمدة، $rows: مصفوفة صفوف (كل صف مصفوفة بنفس ترتيب الأعمدة)
function export_to_excel($filename, $headers, $rows) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM حتى يعرض Excel العربية بشكل صحيح
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
