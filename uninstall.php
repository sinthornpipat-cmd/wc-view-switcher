<?php
/**
 * ลบข้อมูลของปลั๊กอินเมื่อถูกลบออกจากเว็บไซต์
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wcvs_options' );
delete_transient( 'wcvs_price_bounds' );
