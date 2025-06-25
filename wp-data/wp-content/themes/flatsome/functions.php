<?php
/**
 * Flatsome functions and definitions
 *
 * @package flatsome
 */

require get_template_directory() . '/inc/init.php';

// Get the site URL
$site_url = get_site_url();

// Extract the domain name from the URL
$domain_name = wp_parse_url($site_url, PHP_URL_HOST);

$update_option_data = array(
    'id'           => 'new_id_123456',
    'type'         => 'PUBLIC',
    'domain'       => $domain_name, // Set the domain to the current domain name
    'registeredAt' => '2021-07-18T12:51:10.826Z',
    'purchaseCode' => 'abcd1234-5678-90ef-ghij-klmnopqrstuv',
    'licenseType'  => 'Regular License',
    'errors'       => array(),
    'show_notice'  => false
);

update_option('flatsome_registration', $update_option_data, 'yes');

flatsome()->init();

/**
 * It's not recommended to add any custom code here. Please use a child theme
 * so that your customizations aren't lost during updates.
 *
 * Learn more here: https://developer.wordpress.org/themes/advanced-topics/child-themes/
 */
add_action( 'woocommerce_after_shop_loop_item_title', 'wc_product_sold_count' );
add_action( 'woocommerce_single_product_summary', 'wc_product_sold_count', 11 );
function wc_product_sold_count() {
 global $product;
 $units_sold = get_post_meta( $product->get_id(), 'total_sales', true );
 echo '<p class="da-ban">' . sprintf( __( 'Đã bán: %s', 'woocommerce' ), $units_sold ) . '</p>';
}

/**
 * Function to change specific words in WordPress text translations.
 * By @sebdelaweb
 */
function wpfi_change_text( $translated_text, $text, $domain ) {
    switch ( $translated_text ) {
        case 'Cancel Request' :
            $translated_text = __( 'Huỷ đơn' );
            break;
        case 'Track Order' :
            $translated_text = __( 'Theo dõi' );
            break;
        case 'Coupon code' :
            $translated_text = __( 'Nhập mã ưu đãi' );
            break;
		case 'Apply coupon' :
            $translated_text = __( 'Áp dụng mã ưu đãi' );
            break;
		case 'Points' :
            $translated_text = __( 'Nhập số tích điểm' );
            break;	
		case 'Apply Points' :
            $translated_text = __( 'Áp dụng điểm' );
            break;
		case 'Chờ xác nhận đã thanh toán' :
            $translated_text = __( 'Chờ xác nhận chuyển khoản' );
            break;
    }
    return $translated_text;
}

add_filter( 'gettext', 'wpfi_change_text', 20, 3 );

// Sửa trạng thái
add_filter( 'wc_order_statuses', 'linhtd15_rename_order_status_msg', 20, 1 );
function linhtd15_rename_order_status_msg( $order_statuses ) {
$order_statuses['wc-completed'] = _x( 'Đã giao hàng', 'Order status', 'woocommerce' );
$order_statuses['wc-processing'] = _x( 'Chờ xác nhận', 'Order status', 'woocommerce' );
$order_statuses['wc-on-hold'] = _x( 'Chờ xác nhận đã thanh toán', 'Order status', 'woocommerce' );
$order_statuses['wc-pending'] = _x( 'Chờ giải quyết', 'Order status', 'woocommerce' );
$order_statuses['wc-cancelled'] = _x( 'Đã hủy đơn', 'Order status', 'woocommerce' );
$order_statuses['wc-failed'] = _x( 'Giao hàng thất bại', 'Order status', 'woocommerce' );
$order_statuses['wc-checkout-draft'] = _x( 'Đơn nháp', 'Order status', 'woocommerce' );
return $order_statuses;
}
//
// Xóa trạng thái
function linhtd15_remove_status( $statuses ) {
	if( isset( $statuses['wc-checkout-draft'] ) ){
		unset( $statuses['wc-checkout-draft'] );
	}
	if( isset( $statuses['wc-pending'] ) ){
		unset( $statuses['wc-pending'] );
	}
	return $statuses;
}
add_filter( 'wc_order_statuses', 'linhtd15_remove_status', 20, 1 );
//Thêm trạng thái
function register_shipping_order_status() {
	register_post_status( 'wc-shipping', array(
	'label'                     => 'Đang giao hàng',
	'public'                    => true,
	'show_in_admin_status_list' => true,
	'show_in_admin_all_list'    => true,
	'exclude_from_search'       => false,
	'label_count'               => _n_noop( 'Đang giao hàng (%s)', 'Đang giao hàng (%s)' )
	) );
}

function add_shipping_to_order_statuses( $order_statuses ) {
	$new_order_statuses = array();
	foreach ( $order_statuses as $key => $status ) {
		$new_order_statuses[ $key ] = $status;
		if ( 'wc-on-hold' === $key ) {
			$new_order_statuses['wc-shipping'] = 'Đang giao hàng';
		}
	}
	return $new_order_statuses;
}
add_action( 'init', 'register_shipping_order_status' );
add_filter( 'wc_order_statuses', 'add_shipping_to_order_statuses', 20, 1 );
//
// Thêm trạng thái mới vào Hành động hàng loạt
function linhtd15_custom_dropdown_bulk_actions_shop_order( $actions ) {
	$new_actions = array();
	foreach ($actions as $key => $action) {
		$new_actions[$key] = $action;
		if ('mark_processing' === $key) {
			$new_actions['mark_shipping'] = __( 'Đổi trạng thái sang Đang giao hàng', 'woocommerce' );
		}
	}
	return $new_actions;
}
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'linhtd15_custom_dropdown_bulk_actions_shop_order', 20, 1 );
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'linhtd15_custom_dropdown_bulk_actions_shop_order', 20, 1 );
//
// Sửa tên trạng thái trong Hành động hàng loạt
function linhtd15_rename_dropdown_bulk_actions_shop_order( $actions ) {
	$actions['mark_processing'] = __( 'Đổi trạng thái sang Chờ xác nhận', 'woocommerce' );
	$actions['mark_on-hold'] = __( 'Đổi trạng thái sang Chờ xác nhận đã thanh toán', 'woocommerce' );
	$actions['mark_completed'] = __( 'Đổi trạng thái sang Đã giao hàng', 'woocommerce' );
	$actions['mark_cancelled'] = __( 'Đổi trạng thái sang Đã hủy đơn', 'woocommerce' );
	return $actions;
}
add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'linhtd15_rename_dropdown_bulk_actions_shop_order', 20, 1 );
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'linhtd15_rename_dropdown_bulk_actions_shop_order', 20, 1 );