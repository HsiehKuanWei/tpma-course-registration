<?php
defined('ABSPATH') || exit;
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10mm 15mm; }
        body { margin: 0; color: #000; font-family: tpma_dikai; font-size: 10.5pt; }
        .receipt { position: relative; width: 100%; }
        .serial { position: absolute; top: 0; left: 0; font-size: 9pt; white-space: nowrap; }
        .association { margin: -5mm 0 0; text-align: center; font-size: 18pt; font-weight: bold; line-height: 9mm; }
        .title { margin: 0 0 2mm; padding-left: 8mm; text-align: center; font-size: 18pt; font-weight: bold; letter-spacing: 8mm; line-height: 9mm; }
        .receipt-form { width: 172mm; margin: 0 auto; border-collapse: collapse; border-spacing: 0; table-layout: fixed; }
        .receipt-form td { border: .5pt solid #000; padding: 0 1.5mm; vertical-align: middle; }
        .receipt-date-row { height: 8mm; font-size: 10.5pt; letter-spacing: .5mm; text-align: left; }
        .receipt-label { width: 34mm; font-size: 14pt; text-align: justify; text-align-last: justify; }
        .receipt-payer { width: 77mm; font-size: 14pt; font-weight: bold; }
        .receipt-tax-label { width: 26mm; font-size: 14pt; line-height: 5mm; text-align: justify; text-align-last: justify; }
        .receipt-tax-value { width: 35mm; font-size: 14pt; font-weight: bold; }
        .receipt-person-row > td { padding-top: 3mm; padding-bottom: 3mm; }
        .receipt-item-row > td { padding-top: 5mm; padding-bottom: 5mm; }
        .receipt-item { font-size: 14pt; font-weight: bold; }
        .receipt-amount-row > td { padding-top: 1mm; padding-bottom: 1mm; }
        .receipt-amount-area { height: 16mm; padding: 0 !important; }
        .receipt-amount-grid { width: 100%; height: 16mm; border-collapse: collapse; border-spacing: 0; table-layout: fixed; }
        .receipt-amount-grid td { border-top: 0; border-bottom: 0; border-right: .5pt solid #000; border-left: 0; padding: 0; text-align: center; vertical-align: middle; }
        .receipt-amount-grid td:last-child { border-right: 0; }
        .receipt-currency { width: 23%; font-size: 14pt; }
        .receipt-digit { width: 7.5%; font-size: 18pt; font-weight: bold; }
        .receipt-unit { width: 4.5%; font-size: 14pt; }
        .receipt-end { width: 10%; font-size: 14pt; white-space: nowrap; }
        .receipt-receiver { width: 34mm; padding: 0 !important; font-size: 11pt; text-align: center; }
        .receipt-receiver span { display: inline-block; line-height: 5.5mm; }
        .receipt-unit-area { height: 7mm; padding: 0 !important; }
        .receipt-unit-grid { width: 100%; height: 7mm; border-collapse: collapse; border-spacing: 0; table-layout: fixed; }
        .receipt-unit-grid td { height: 7mm; border: 0; padding: 0 1.5mm; vertical-align: middle; }
        .receipt-unit-label { width: 26mm; border-right: .5pt solid #000 !important; font-size: 11pt; text-align: justify; text-align-last: justify; }
        .receipt-unit-info { font-size: 11pt; }
        .receipt-signature-table { width: 172mm; height: 9mm; margin: 0 auto; border-collapse: collapse; border-spacing: 0; table-layout: fixed; font-size: 10.5pt; }
        .receipt-signature-table td { width: 50%; padding: 2mm 2mm 0; vertical-align: top; }
        .receipt-chairman-grid { width: 30mm; border-collapse: collapse; border-spacing: 0; table-layout: fixed; }
        .receipt-chairman-grid td { width: auto; padding: 0; vertical-align: top; }
        .receipt-chairman-label { width: 17mm !important; white-space: nowrap; }
        .receipt-chairman-seal { width: 10mm !important; }
        .seal-wrap { display: block; width: 10mm; height: 10mm; }
        .seal-wrap svg { width: 10mm; height: 10mm; }
    </style>
</head>
<body>
<div class="receipt">
    <div class="serial">流水編號：<?php echo esc_html($receipt['serial'] ?? ''); ?></div>
    <p class="association">社團法人台灣專案管理學會</p>
    <p class="title">收 據</p>

    <?php $amount_digits = (array) ($receipt['amount_digits'] ?? array('×', '×', '×', '×', '×', '×')); ?>
    <table class="receipt-form">
        <tr><td class="receipt-date-row" colspan="4">中華民國 <?php echo esc_html($receipt['issue_date_roc'] ?? ''); ?></td></tr>
        <tr class="receipt-person-row">
            <td class="receipt-label">戶名</td>
            <td class="receipt-payer"><?php echo esc_html($receipt['payer_name'] ?? ''); ?></td>
            <td class="receipt-tax-label">身分證號<br>統一編號</td>
            <td class="receipt-tax-value"><?php echo esc_html($receipt['tax_id'] ?? ''); ?></td>
        </tr>
        <tr class="receipt-item-row"><td class="receipt-label">品名</td><td class="receipt-item" colspan="3"><?php echo esc_html($receipt['item_name'] ?? '課程費'); ?></td></tr>
        <tr class="receipt-amount-row">
            <td class="receipt-label">金額</td>
            <td class="receipt-amount-area" colspan="3">
                <table class="receipt-amount-grid"><tr>
                    <td class="receipt-currency">新台幣</td>
                    <td class="receipt-digit"><?php echo esc_html($amount_digits[0] ?? '×'); ?></td><td class="receipt-unit">拾</td>
                    <td class="receipt-digit"><?php echo esc_html($amount_digits[1] ?? '×'); ?></td><td class="receipt-unit">萬</td>
                    <td class="receipt-digit"><?php echo esc_html($amount_digits[2] ?? '×'); ?></td><td class="receipt-unit">仟</td>
                    <td class="receipt-digit"><?php echo esc_html($amount_digits[3] ?? '×'); ?></td><td class="receipt-unit">佰</td>
                    <td class="receipt-digit"><?php echo esc_html($amount_digits[4] ?? '×'); ?></td><td class="receipt-unit">拾</td>
                    <td class="receipt-digit"><?php echo esc_html($amount_digits[5] ?? '×'); ?></td><td class="receipt-end">元整</td>
                </tr></table>
            </td>
        </tr>
        <tr class="receipt-unit-row">
            <td class="receipt-receiver" rowspan="5"><span>收<br>款<br>單<br>位</span></td>
            <td class="receipt-unit-area" colspan="3"><table class="receipt-unit-grid"><tr><td class="receipt-unit-label">單位名稱</td><td class="receipt-unit-info">社團法人台灣專案管理學會</td></tr></table></td>
        </tr>
        <tr class="receipt-unit-row"><td class="receipt-unit-area" colspan="3"><table class="receipt-unit-grid"><tr><td class="receipt-unit-label">地址</td><td class="receipt-unit-info">高雄市鳳山區博愛路529號12F</td></tr></table></td></tr>
        <tr class="receipt-unit-row"><td class="receipt-unit-area" colspan="3"><table class="receipt-unit-grid"><tr><td class="receipt-unit-label">電話</td><td class="receipt-unit-info">07-7476543</td></tr></table></td></tr>
        <tr class="receipt-unit-row"><td class="receipt-unit-area" colspan="3"><table class="receipt-unit-grid"><tr><td class="receipt-unit-label">統一編號</td><td class="receipt-unit-info">15561599</td></tr></table></td></tr>
        <tr class="receipt-unit-row"><td class="receipt-unit-area" colspan="3"><table class="receipt-unit-grid"><tr><td class="receipt-unit-label">立案證號</td><td class="receipt-unit-info">台內社字第0910023763號</td></tr></table></td></tr>
    </table>

    <table class="receipt-signature-table"><tr>
        <td><table class="receipt-chairman-grid"><tr><td class="receipt-chairman-label">理事長：</td><td class="receipt-chairman-seal"><?php if (!empty($seal_svg)) : ?><span class="seal-wrap" aria-label="理事長印"><?php echo $seal_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- version-controlled local SVG. ?></span><?php endif; ?></td></tr></table></td>
        <td>經辦：</td>
    </tr></table>
</div>
</body>
</html>
