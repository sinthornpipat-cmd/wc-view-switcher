<?php
/**
 * Plugin Name:          Grid/List View Switcher for WooCommerce
 * Plugin URI:           https://n2npay.freedev.app/product/grid-list-view-switcher-for-woocommerce/
 * Description:          Switch WooCommerce product listings between grid and list views, with a Quick View popup and a collapsible category / price filter panel.
 * Version:              1.0.0
 * Author:               n2npay
 * Author URI:           https://n2npay.freedev.app/
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          grid-list-view-switcher-for-woocommerce
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 7.0
 * WC tested up to:      9.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * มีปลั๊กอินรุ่นเก่า (WC View Switcher) หรือสำเนาอื่นของปลั๊กอินนี้เปิดใช้อยู่หรือไม่
 * ทั้งสองใช้ชื่อคลาสและค่าคงที่เดียวกัน ถ้าโหลดพร้อมกันจะเกิด fatal error
 */
$wcvs_other_copy_active = ( static function () {
	if ( defined( 'WCVS_VERSION' ) || class_exists( 'WCVS_Plugin', false ) ) {
		return true;
	}
	// ปลั๊กอินถูกโหลดตามลำดับตัวอักษร สำเนาอื่นอาจยังไม่ถูกโหลด จึงตรวจจากรายการที่เปิดใช้ด้วย
	$self   = plugin_basename( __FILE__ );
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	foreach ( $active as $plugin ) {
		if ( $plugin !== $self && 'wc-view-switcher.php' === basename( (string) $plugin ) ) {
			return true;
		}
	}
	return false;
} )();

if ( $wcvs_other_copy_active ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Grid/List View Switcher for WooCommerce is not running because another copy of it (for example the older "WC View Switcher") is still active. Deactivate and delete the other copy, then reload this page.', 'grid-list-view-switcher-for-woocommerce' ) . '</p></div>';
		}
	);
	return;
}

define( 'WCVS_VERSION', '1.0.0' );
define( 'WCVS_URL', plugin_dir_url( __FILE__ ) );
define( 'WCVS_DIR', plugin_dir_path( __FILE__ ) );

require_once WCVS_DIR . 'includes/class-wcvs-plugin.php';

// ประกาศรองรับ HPOS (ปลั๊กอินไม่ได้ใช้ข้อมูลคำสั่งซื้อ)
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

new WCVS_Plugin();
