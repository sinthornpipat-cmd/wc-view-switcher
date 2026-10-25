<?php
/**
 * WCVS_Plugin – ตัวหลักของปลั๊กอิน (ตั้งค่า, มุมมอง Grid/List, Quick View, shortcode)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCVS_Plugin {

	const OPT    = 'wcvs_options';
	const COOKIE = 'wcvs_view';

	private $forced = false;

	/** @var WCVS_Filter */
	public $filter;

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		require_once WCVS_DIR . 'includes/class-wcvs-filter.php';
		$this->filter = new WCVS_Filter( $this );

		// Admin settings
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_wcvs_group', array( $this, 'settings_capability' ) );

		// Front-end
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		// Toolbar: [result count] [sorting] [view as]  (อยู่ในแถวเดียวกัน)
		add_action( 'woocommerce_before_shop_loop', array( $this, 'toolbar_open' ), 15 );
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render_switcher' ), 35 );
		add_action( 'woocommerce_before_shop_loop', array( $this, 'toolbar_close' ), 40 );

		// Quick View
		add_action( 'woocommerce_before_shop_loop_item', array( $this, 'quick_view_button' ), 5 );
		add_action( 'wp_ajax_wcvs_quick_view', array( $this, 'ajax_quick_view' ) );
		add_action( 'wp_ajax_nopriv_wcvs_quick_view', array( $this, 'ajax_quick_view' ) );

		// Shortcode สำหรับหน้าที่สร้างเอง
		add_shortcode( 'wcvs_products', array( $this, 'shortcode' ) );

		// ปุ่ม View Details (แสดงเฉพาะมุมมอง List)
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'details_button' ), 15 );

		// ล้าง cache ช่วงราคาเมื่อสินค้าถูกแก้ไข
		add_action( 'woocommerce_update_product', array( $this, 'flush_price_bounds' ) );
		add_action( 'woocommerce_new_product', array( $this, 'flush_price_bounds' ) );
		add_action( 'woocommerce_delete_product', array( $this, 'flush_price_bounds' ) );
		add_action( 'woocommerce_trash_product', array( $this, 'flush_price_bounds' ) );
	}

	/* ---------- Options ---------- */

	public function get_options() {
		return wp_parse_args(
			get_option( self::OPT, array() ),
			array(
				'default_view'   => 'grid',
				'columns'        => 5,
				'details_text'   => '',
				'qv_enabled'     => 1,
				'qv_text'        => '',
				'filter_enabled' => 1,
				'filter_default' => 'open',
				'filter_cats'    => 1,
				'filter_price'   => 1,
				'filter_count'   => 1,
			)
		);
	}

	/** ข้อความปุ่มรายละเอียด (ค่าว่าง = ใช้ข้อความเริ่มต้นที่แปลได้) */
	public function details_text() {
		$opt = $this->get_options();
		return '' !== $opt['details_text'] ? $opt['details_text'] : __( 'View Details', 'grid-list-view-switcher-for-woocommerce' );
	}

	/** ข้อความปุ่ม Quick View (ค่าว่าง = ใช้ข้อความเริ่มต้นที่แปลได้) */
	public function qv_text() {
		$opt = $this->get_options();
		return '' !== $opt['qv_text'] ? $opt['qv_text'] : __( 'View', 'grid-list-view-switcher-for-woocommerce' );
	}

	public function current_view() {
		$opt  = $this->get_options();
		$view = $opt['default_view'];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only display preference.
		if ( isset( $_GET['wcvs_view'] ) ) {
			$req = sanitize_key( wp_unslash( $_GET['wcvs_view'] ) );
			if ( in_array( $req, array( 'grid', 'list' ), true ) ) {
				return $req;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$cookie = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
			if ( in_array( $cookie, array( 'grid', 'list' ), true ) ) {
				$view = $cookie;
			}
		}
		return $view;
	}

	public function flush_price_bounds() {
		delete_transient( 'wcvs_price_bounds' );
	}

	/* ---------- Front-end ---------- */

	public function is_forced() {
		return $this->forced;
	}

	public function is_shop_context() {
		if ( $this->forced ) {
			return true;
		}
		if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() || ( is_search() && 'product' === get_query_var( 'post_type' ) ) ) ) {
			return true;
		}
		// หน้าที่ใช้ shortcode [wcvs_products]
		if ( is_singular() ) {
			$post = get_post();
			if ( $post && has_shortcode( $post->post_content, 'wcvs_products' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * [wcvs_products] – ใช้ attribute เดียวกับ [products] ของ WooCommerce
	 * ตัวอย่าง: [wcvs_products limit="12" category="boxes"]
	 */
	public function shortcode( $atts ) {
		// บังคับโหลดสไตล์/สคริปต์ แม้ธีมหรือ page builder จะตรวจหน้าไม่เจอ
		$this->forced = true;
		$this->enqueue_assets();

		$atts        = is_array( $atts ) ? $atts : array();
		$show_filter = ! isset( $atts['filter'] ) || ! in_array( strtolower( (string) $atts['filter'] ), array( 'false', '0', 'no' ), true );
		unset( $atts['filter'] );
		$this->filter->show = $show_filter;

		wp_add_inline_script(
			'wcvs',
			'(function(){var b=document.body;if(!b)return;if(!/wcvs-view-/.test(b.className)){b.classList.add("wcvs-view-' . esc_js( $this->current_view() ) . '");}if(!/wcvs-filter-/.test(b.className)){b.classList.add("wcvs-filter-' . esc_js( $this->filter->state() ) . '");}})();'
		);

		$atts = array_merge(
			array(
				'limit'    => '12',
				'paginate' => 'true',   // ต้องเป็น true เพื่อให้แสดงแถบจำนวน/เรียงลำดับ/View as
				'orderby'  => '',       // ว่าง = ให้ใช้ค่าจากตัวเลือก Sort by
				'order'    => '',
			),
			$atts
		);
		$clean = array();
		foreach ( $atts as $k => $v ) {
			$k = sanitize_key( (string) $k );
			if ( '' === $k || ! is_scalar( $v ) ) {
				continue;
			}
			$clean[ $k ] = (string) $v;
		}

		// เรียก WooCommerce โดยตรง ไม่ต้องประกอบ shortcode เป็นข้อความ
		$this->filter->begin_shortcode();
		$products = new WC_Shortcode_Products( $clean, 'products' );
		$html     = $products->get_content();
		$this->filter->end_shortcode();
		return $html;
	}

	public function assets() {
		if ( $this->is_shop_context() ) {
			$this->enqueue_assets();
		}
	}

	public function enqueue_assets() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$opt  = $this->get_options();
		wp_enqueue_style( 'wcvs', WCVS_URL . 'assets/style.css', array(), WCVS_VERSION );
		wp_add_inline_style( 'wcvs', ':root{--wcvs-cols:' . (int) $opt['columns'] . ';--wcvs-cols-open:' . max( 1, (int) $opt['columns'] - 1 ) . ';}' );
		wp_enqueue_script( 'wcvs', WCVS_URL . 'assets/script.js', array( 'jquery' ), WCVS_VERSION, true );
		wp_localize_script(
			'wcvs',
			'WCVS',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'error'    => __( 'Could not load product details. Please try again.', 'grid-list-view-switcher-for-woocommerce' ),
				'close'    => __( 'Close', 'grid-list-view-switcher-for-woocommerce' ),
			)
		);
		// สำหรับสินค้าแบบมีตัวเลือก (variable) ใน popup
		wp_enqueue_script( 'wc-add-to-cart-variation' );
	}

	public function body_class( $classes ) {
		if ( $this->is_shop_context() ) {
			$classes[] = 'wcvs-view-' . $this->current_view();
		}
		return $classes;
	}

	public function toolbar_open() {
		echo '<div class="wcvs-toolbar">';
	}

	public function toolbar_close() {
		echo '</div>';
	}

	public function render_switcher() {
		$view = $this->current_view();
		?>
		<div class="wcvs-switch" role="group" aria-label="<?php esc_attr_e( 'View as', 'grid-list-view-switcher-for-woocommerce' ); ?>">
			<span class="wcvs-label"><?php esc_html_e( 'View as:', 'grid-list-view-switcher-for-woocommerce' ); ?></span>
			<button type="button" class="wcvs-btn<?php echo 'grid' === $view ? ' is-active' : ''; ?>" data-view="grid" aria-label="<?php esc_attr_e( 'Grid', 'grid-list-view-switcher-for-woocommerce' ); ?>" aria-pressed="<?php echo 'grid' === $view ? 'true' : 'false'; ?>">
				<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18M3 12h18"/></svg>
			</button>
			<button type="button" class="wcvs-btn<?php echo 'list' === $view ? ' is-active' : ''; ?>" data-view="list" aria-label="<?php esc_attr_e( 'List', 'grid-list-view-switcher-for-woocommerce' ); ?>" aria-pressed="<?php echo 'list' === $view ? 'true' : 'false'; ?>">
				<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M9 6h12M9 12h12M9 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/></svg>
			</button>
		</div>
		<?php
	}

	public function quick_view_button() {
		$opt = $this->get_options();
		if ( empty( $opt['qv_enabled'] ) || ! $this->is_shop_context() ) {
			return;
		}
		global $product;
		if ( ! $product ) {
			return;
		}
		/* translators: %s: product name */
		$label = sprintf( __( 'Quick view: %s', 'grid-list-view-switcher-for-woocommerce' ), $product->get_name() );
		?>
		<div class="wcvs-qv-wrap"><button type="button" class="wcvs-qv-btn" data-product-id="<?php echo (int) $product->get_id(); ?>" aria-label="<?php echo esc_attr( $label ); ?>"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><span><?php echo esc_html( $this->qv_text() ); ?></span></button></div>
		<?php
	}

	public function ajax_quick_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only view of a published product; a nonce would break on cached pages.
		$id      = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$product = $id ? wc_get_product( $id ) : false;

		if ( ! $product || 'publish' !== get_post_status( $id ) || post_password_required( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'grid-list-view-switcher-for-woocommerce' ) ) );
		}

		// WooCommerce โหลด template functions ให้ในคำขอ AJAX อยู่แล้ว; สำรองไว้กรณีถูกปิด
		if ( ! function_exists( 'woocommerce_template_single_add_to_cart' ) && is_callable( array( WC(), 'frontend_includes' ) ) ) {
			WC()->frontend_includes();
		}

		global $post;
		$post = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		$GLOBALS['product'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce templates read the global product.

		$details_text = $this->details_text();
		ob_start();
		include WCVS_DIR . 'templates/quick-view.php';
		$html = ob_get_clean();
		wp_reset_postdata();

		wp_send_json_success( array( 'html' => $html ) );
	}

	public function details_button() {
		printf(
			'<a href="%s" class="wcvs-details-btn">%s</a>',
			esc_url( get_permalink() ),
			esc_html( $this->details_text() )
		);
	}

	/* ---------- Settings page ---------- */

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'View Switcher', 'grid-list-view-switcher-for-woocommerce' ),
			__( 'View Switcher', 'grid-list-view-switcher-for-woocommerce' ),
			'manage_woocommerce',
			'wcvs',
			array( $this, 'settings_page' )
		);
	}

	public function settings_capability() {
		return 'manage_woocommerce';
	}

	public function register_settings() {
		register_setting( 'wcvs_group', self::OPT, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $in ) {
		$in = is_array( $in ) ? $in : array();
		return array(
			'default_view'   => ( isset( $in['default_view'] ) && 'list' === $in['default_view'] ) ? 'list' : 'grid',
			'columns'        => max( 1, min( 6, (int) ( $in['columns'] ?? 5 ) ) ),
			'details_text'   => sanitize_text_field( $in['details_text'] ?? '' ),
			'qv_enabled'     => empty( $in['qv_enabled'] ) ? 0 : 1,
			'qv_text'        => sanitize_text_field( $in['qv_text'] ?? '' ),
			'filter_enabled' => empty( $in['filter_enabled'] ) ? 0 : 1,
			'filter_default' => ( isset( $in['filter_default'] ) && 'closed' === $in['filter_default'] ) ? 'closed' : 'open',
			'filter_cats'    => empty( $in['filter_cats'] ) ? 0 : 1,
			'filter_price'   => empty( $in['filter_price'] ) ? 0 : 1,
			'filter_count'   => empty( $in['filter_count'] ) ? 0 : 1,
		);
	}

	/** ชื่อ field ของฟอร์มตั้งค่า (escape แล้ว) */
	private function field( $key ) {
		return esc_attr( self::OPT . '[' . $key . ']' );
	}

	public function settings_page() {
		$o = $this->get_options();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Grid/List View Switcher', 'grid-list-view-switcher-for-woocommerce' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'wcvs_group' ); ?>
				<table class="form-table">
					<tr><th><?php esc_html_e( 'Default view', 'grid-list-view-switcher-for-woocommerce' ); ?></th><td>
						<select name="<?php echo $this->field( 'default_view' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in field(). ?>">
							<option value="grid" <?php selected( $o['default_view'], 'grid' ); ?>><?php esc_html_e( 'Grid', 'grid-list-view-switcher-for-woocommerce' ); ?></option>
							<option value="list" <?php selected( $o['default_view'], 'list' ); ?>><?php esc_html_e( 'List', 'grid-list-view-switcher-for-woocommerce' ); ?></option>
						</select></td></tr>
					<tr><th><?php esc_html_e( 'Columns (grid view, large screens)', 'grid-list-view-switcher-for-woocommerce' ); ?></th><td>
						<input type="number" min="1" max="6" name="<?php echo esc_attr( self::OPT . '[columns]' ); ?>" value="<?php echo esc_attr( $o['columns'] ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Details button text (list view)', 'grid-list-view-switcher-for-woocommerce' ); ?></th><td>
						<input type="text" name="<?php echo esc_attr( self::OPT . '[details_text]' ); ?>" value="<?php echo esc_attr( $o['details_text'] ); ?>" placeholder="<?php esc_attr_e( 'View Details', 'grid-list-view-switcher-for-woocommerce' ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Enable Quick View (popup)', 'grid-list-view-switcher-for-woocommerce' ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( self::OPT . '[qv_enabled]' ); ?>" value="1" <?php checked( $o['qv_enabled'], 1 ); ?>> <?php esc_html_e( 'Show a Quick View button on product cards', 'grid-list-view-switcher-for-woocommerce' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Quick View button text', 'grid-list-view-switcher-for-woocommerce' ); ?></th><td>
						<input type="text" name="<?php echo esc_attr( self::OPT . '[qv_text]' ); ?>" value="<?php echo esc_attr( $o['qv_text'] ); ?>" placeholder="<?php esc_attr_e( 'View', 'grid-list-view-switcher-for-woocommerce' ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Enable filters', 'grid-list-view-switcher-for-woocommerce' ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( self::OPT . '[filter_enabled]' ); ?>" value="1" <?php checked( $o['filter_enabled'], 1 ); ?>> <?php esc_html_e( 'Show the product filter panel with an open/close toggle', 'grid-list-view-switcher-for-woocommerce' ); ?></label></td></tr>
					<tr><th><?php esc_html_e( 'Filter panel default state', 'grid-list-view-switcher-for-woocommerce' ); ?></th><td>
						<select name="<?php echo esc_attr( self::OPT . '[filter_default]' ); ?>">
							<option value="open" <?php selected( $o['filter_default'], 'open' ); ?>><?php esc_html_e( 'Open (desktop)', 'grid-list-view-switcher-for-woocommerce' ); ?></option>
							<option value="closed" <?php selected( $o['filter_default'], 'closed' ); ?>><?php esc_html_e( 'Closed', 'grid-list-view-switcher-for-woocommerce' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'On mobile the filters open as a slide-out drawer and stay closed until the shopper taps the Filters button.', 'grid-list-view-switcher-for-woocommerce' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Filters to show', 'grid-list-view-switcher-for-woocommerce' ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( self::OPT . '[filter_cats]' ); ?>" value="1" <?php checked( $o['filter_cats'], 1 ); ?>> <?php esc_html_e( 'Categories', 'grid-list-view-switcher-for-woocommerce' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( self::OPT . '[filter_count]' ); ?>" value="1" <?php checked( $o['filter_count'], 1 ); ?>> <?php esc_html_e( 'Show product count for each category', 'grid-list-view-switcher-for-woocommerce' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( self::OPT . '[filter_price]' ); ?>" value="1" <?php checked( $o['filter_price'], 1 ); ?>> <?php esc_html_e( 'Price range', 'grid-list-view-switcher-for-woocommerce' ); ?></label></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
