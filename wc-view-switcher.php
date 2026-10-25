<?php
/**
 * Plugin Name: WC View Switcher (Grid / List)
 * Description: สลับมุมมองสินค้า Grid/List, Quick View popup และตัวกรองหมวดหมู่/ช่วงราคา (เปิด-ปิดเมนูได้) สำหรับ WooCommerce
 * Version: 2.0.0
 * Author: You
 * Text Domain: wc-view-switcher
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCVS_VERSION', '2.0.0' );
define( 'WCVS_URL', plugin_dir_url( __FILE__ ) );
define( 'WCVS_DIR', plugin_dir_path( __FILE__ ) );

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
	}

	/* ---------- Options ---------- */

	public function get_options() {
		return wp_parse_args( get_option( self::OPT, array() ), array(
			'default_view' => 'grid',
			'columns'      => 5,
			'details_text' => 'View Details',
			'qv_enabled'   => 1,
			'qv_text'      => 'View',
			'filter_enabled' => 1,
			'filter_default' => 'open',
			'filter_cats'    => 1,
			'filter_price'   => 1,
			'filter_count'   => 1,
		) );
	}

	public function current_view() {
		$opt  = $this->get_options();
		$view = $opt['default_view'];
		if ( isset( $_GET['view'] ) && in_array( $_GET['view'], array( 'grid', 'list' ), true ) ) {
			return sanitize_key( $_GET['view'] );
		}
		if ( isset( $_COOKIE[ self::COOKIE ] ) && in_array( $_COOKIE[ self::COOKIE ], array( 'grid', 'list' ), true ) ) {
			$view = $_COOKIE[ self::COOKIE ];
		}
		return $view;
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
		$parts = array();
		foreach ( $atts as $k => $v ) {
			$k = preg_replace( '/[^a-z0-9_\\-]/i', '', (string) $k );
			if ( '' === $k ) {
				continue;
			}
			$parts[] = $k . '="' . str_replace( '"', '', (string) $v ) . '"';
		}

		$this->filter->begin_shortcode();
		$html = do_shortcode( '[products ' . implode( ' ', $parts ) . ']' );
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
		wp_localize_script( 'wcvs', 'WCVS', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'error'    => __( 'ไม่สามารถโหลดข้อมูลสินค้าได้ กรุณาลองใหม่', 'wc-view-switcher' ),
		) );
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
		<div class="wcvs-switch" role="group" aria-label="<?php esc_attr_e( 'View as', 'wc-view-switcher' ); ?>">
			<span class="wcvs-label"><?php esc_html_e( 'View as:', 'wc-view-switcher' ); ?></span>
			<button type="button" class="wcvs-btn<?php echo 'grid' === $view ? ' is-active' : ''; ?>" data-view="grid" aria-label="Grid" aria-pressed="<?php echo 'grid' === $view ? 'true' : 'false'; ?>">
				<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18M3 12h18"/></svg>
			</button>
			<button type="button" class="wcvs-btn<?php echo 'list' === $view ? ' is-active' : ''; ?>" data-view="list" aria-label="List" aria-pressed="<?php echo 'list' === $view ? 'true' : 'false'; ?>">
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
		printf(
			'<div class="wcvs-qv-wrap"><button type="button" class="wcvs-qv-btn" data-product-id="%1$d" aria-label="%2$s"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg><span>%3$s</span></button></div>',
			(int) $product->get_id(),
			esc_attr( sprintf( __( 'Quick view: %s', 'wc-view-switcher' ), $product->get_name() ) ),
			esc_html( $opt['qv_text'] )
		);
	}

	public function ajax_quick_view() {
		$id      = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$product = $id ? wc_get_product( $id ) : false;

		if ( ! $product || 'publish' !== get_post_status( $id ) || post_password_required( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'wc-view-switcher' ) ) );
		}

		if ( ! function_exists( 'woocommerce_template_single_add_to_cart' ) && defined( 'WC_ABSPATH' ) ) {
			include_once WC_ABSPATH . 'includes/wc-template-functions.php';
		}

		global $post;
		$post = get_post( $id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		$GLOBALS['product'] = $product;

		$opt = $this->get_options();
		ob_start();
		include WCVS_DIR . 'templates/quick-view.php';
		$html = ob_get_clean();
		wp_reset_postdata();

		wp_send_json_success( array( 'html' => $html ) );
	}

	public function details_button() {
		$opt = $this->get_options();
		printf(
			'<a href="%s" class="wcvs-details-btn">%s</a>',
			esc_url( get_permalink() ),
			esc_html( $opt['details_text'] )
		);
	}

	/* ---------- Settings page ---------- */

	public function add_menu() {
		add_submenu_page( 'woocommerce', 'View Switcher', 'View Switcher', 'manage_options', 'wcvs', array( $this, 'settings_page' ) );
	}

	public function register_settings() {
		register_setting( 'wcvs_group', self::OPT, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $in ) {
		return array(
			'default_view' => ( isset( $in['default_view'] ) && 'list' === $in['default_view'] ) ? 'list' : 'grid',
			'columns'      => max( 1, min( 6, (int) ( $in['columns'] ?? 5 ) ) ),
			'details_text' => sanitize_text_field( $in['details_text'] ?? 'View Details' ),
			'qv_enabled'   => empty( $in['qv_enabled'] ) ? 0 : 1,
			'qv_text'      => sanitize_text_field( $in['qv_text'] ?? 'View' ),
			'filter_enabled' => empty( $in['filter_enabled'] ) ? 0 : 1,
			'filter_default' => ( isset( $in['filter_default'] ) && 'closed' === $in['filter_default'] ) ? 'closed' : 'open',
			'filter_cats'    => empty( $in['filter_cats'] ) ? 0 : 1,
			'filter_price'   => empty( $in['filter_price'] ) ? 0 : 1,
			'filter_count'   => empty( $in['filter_count'] ) ? 0 : 1,
		);
	}

	public function settings_page() {
		$o = $this->get_options();
		?>
		<div class="wrap">
			<h1>WC View Switcher</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'wcvs_group' ); ?>
				<table class="form-table">
					<tr><th>มุมมองเริ่มต้น</th><td>
						<select name="<?php echo self::OPT; ?>[default_view]">
							<option value="grid" <?php selected( $o['default_view'], 'grid' ); ?>>Grid</option>
							<option value="list" <?php selected( $o['default_view'], 'list' ); ?>>List</option>
						</select></td></tr>
					<tr><th>จำนวนคอลัมน์ (Grid, จอใหญ่)</th><td>
						<input type="number" min="1" max="6" name="<?php echo self::OPT; ?>[columns]" value="<?php echo esc_attr( $o['columns'] ); ?>"></td></tr>
					<tr><th>ข้อความปุ่มรายละเอียด (List)</th><td>
						<input type="text" name="<?php echo self::OPT; ?>[details_text]" value="<?php echo esc_attr( $o['details_text'] ); ?>"></td></tr>
					<tr><th>เปิดใช้ Quick View (popup)</th><td>
						<label><input type="checkbox" name="<?php echo self::OPT; ?>[qv_enabled]" value="1" <?php checked( $o['qv_enabled'], 1 ); ?>> แสดงปุ่มดูสินค้าแบบ popup บนการ์ดสินค้า</label></td></tr>
					<tr><th>ข้อความปุ่ม Quick View</th><td>
						<input type="text" name="<?php echo self::OPT; ?>[qv_text]" value="<?php echo esc_attr( $o['qv_text'] ); ?>"></td></tr>
					<tr><th>เปิดใช้ตัวกรอง (Filters)</th><td>
						<label><input type="checkbox" name="<?php echo self::OPT; ?>[filter_enabled]" value="1" <?php checked( $o['filter_enabled'], 1 ); ?>> แสดงแผงกรองสินค้าพร้อมปุ่มเปิด/ปิดเมนู</label></td></tr>
					<tr><th>สถานะเริ่มต้นของเมนูกรอง</th><td>
						<select name="<?php echo self::OPT; ?>[filter_default]">
							<option value="open" <?php selected( $o['filter_default'], 'open' ); ?>>เปิดไว้ (จอคอมพิวเตอร์)</option>
							<option value="closed" <?php selected( $o['filter_default'], 'closed' ); ?>>ปิดไว้</option>
						</select>
						<p class="description">บนมือถือเมนูจะเป็นแถบเลื่อนออกมา และปิดอยู่เสมอจนกว่าผู้ใช้จะกดปุ่ม Filters</p></td></tr>
					<tr><th>ตัวกรองที่แสดง</th><td>
						<label><input type="checkbox" name="<?php echo self::OPT; ?>[filter_cats]" value="1" <?php checked( $o['filter_cats'], 1 ); ?>> หมวดหมู่</label><br>
						<label><input type="checkbox" name="<?php echo self::OPT; ?>[filter_count]" value="1" <?php checked( $o['filter_count'], 1 ); ?>> แสดงจำนวนสินค้าในแต่ละหมวด</label><br>
						<label><input type="checkbox" name="<?php echo self::OPT; ?>[filter_price]" value="1" <?php checked( $o['filter_price'], 1 ); ?>> ช่วงราคา</label></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

new WCVS_Plugin();
