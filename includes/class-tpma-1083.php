<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-tpma-special-product.php';

if (!class_exists('TPMA_Woo_Special_1083') && class_exists('TPMA_Woo_Special_Product')) {
    class_alias('TPMA_Woo_Special_Product', 'TPMA_Woo_Special_1083');
}
