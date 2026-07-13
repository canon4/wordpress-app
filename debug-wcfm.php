<?php
require_once('wp-load.php');
$wcfm_store_url = wcfm_get_option( 'wcfm_store_url', 'store' );
echo "wcfm_store_url is: " . $wcfm_store_url . "\n";

$seller_info = get_user_by( 'slug', 'a' );
if ($seller_info) {
    echo "Seller found! ID: " . $seller_info->ID . ", Role: " . implode(', ', $seller_info->roles) . "\n";
} else {
    echo "Seller 'a' not found!\n";
}

$page = get_page_by_path('amazonia');
if ($page) {
    echo "Warning: There is a page with slug 'amazonia'. ID: " . $page->ID . "\n";
}
