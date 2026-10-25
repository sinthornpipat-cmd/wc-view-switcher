<?php
/**
 * WCVS_Filter – แผงกรองสินค้า (หมวดหมู่ + ช่วงราคา) พร้อมปุ่มเปิด/ปิดเมนู
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCVS_Filter {

	const COOKIE = 'wcvs_filter';

	/** @var WCVS_Plugin */
	private $plugin;

	/** แสดงแผงกรองหรือไม่ (ใช้กับ shortcode: filter="false") */
	public $show = true;

	private $in_shortcode = false;
	private $opened       = 0;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		add_action( 'woocommerce_before_shop_loop', array( $this, 'layout_open' ), 5 );
		add_action( 'woocommerce_before_shop_loop', array( $this, 'toggle_button' ), 16 );
		add_action( 'woocommerce_after_shop_loop', array( $this, 'layout_close' ), 20 );

		// ไม่พบสินค้า: ยังต้องมีแผงกรองให้ผู้ใช้ปรับ/รีเซ็ตได้
		add_action( 'woocommerce_no_products_found', array( $this, 'no_products_open' ), 5 );
		add_action( 'woocommerce_no_products_found', array( $this, 'layout_close' ), 20 );
		add_action( 'woocommerce_shortcode_products_loop_no_results', array( $this, 'shortcode_no_results' ) );

		// ใช้ตัวกรองกับ query
		add_action( 'woocommerce_product_query', array( $this, 'filter_main_query' ), 10, 1 );
		add_filter( 'woocommerce_shortcode_products_query', array( $this, 'filter_shortcode_query' ), 10, 3 );

		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/* ---------- Helpers ---------- */

	private function opts() {
		return $this->plugin->get_options();
	}

	public function state() {
		$o = $this->opts();
		$s = ( isset( $o['filter_default'] ) && 'closed' === $o['filter_default'] ) ? 'closed' : 'open';
		if ( isset( $_COOKIE[ self::COOKIE ] ) ) {
			$cookie = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
			if ( in_array( $cookie, array( 'open', 'closed' ), true ) ) {
				$s = $cookie;
			}
		}
		return $s;
	}

	private function active() {
		$o = $this->opts();
		if ( empty( $o['filter_enabled'] ) ) {
			return false;
		}
		if ( $this->plugin->is_forced() ) {
			return $this->show;
		}
		return $this->plugin->is_shop_context();
	}

	public function begin_shortcode() {
		$this->in_shortcode = true;
	}

	public function end_shortcode() {
		$this->in_shortcode = false;
	}

	public function body_class( $classes ) {
		$o = $this->opts();
		if ( ! empty( $o['filter_enabled'] ) && $this->plugin->is_shop_context() ) {
			$classes[] = 'wcvs-filter-' . $this->state();
		}
		return $classes;
	}

	/* ---------- ค่าที่ผู้ใช้เลือก ---------- */

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters are public, read-only GET requests.

	public function selected_cats() {
		if ( ! isset( $_GET['wcvs_cat'] ) ) {
			return array();
		}
		$raw = wp_unslash( $_GET['wcvs_cat'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each item is sanitized below.
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $r ) {
			if ( is_scalar( $r ) ) {
				$r = sanitize_title( (string) $r );
				if ( '' !== $r ) {
					$out[] = $r;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	private function num( $key ) {
		if ( ! isset( $_GET[ $key ] ) ) {
			return null;
		}
		$v = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
		if ( ! is_scalar( $v ) || '' === $v || ! is_numeric( $v ) ) {
			return null;
		}
		return (float) $v;
	}

	public function price_params() {
		return array( $this->num( 'min_price' ), $this->num( 'max_price' ) );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	/** ขอบเขตราคาต่ำสุด/สูงสุดของร้าน (cache 10 นาที) */
	public function bounds() {
		$b = get_transient( 'wcvs_price_bounds' );
		if ( false === $b ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- static aggregate query with no user input; cached in a transient below.
			$mn = 0;
			$mx = 0;
			if ( ! empty( $wpdb->wc_product_meta_lookup ) ) {
				$row = $wpdb->get_row(
					"SELECT MIN(l.min_price) AS mn, MAX(l.max_price) AS mx
					 FROM {$wpdb->wc_product_meta_lookup} l
					 INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id
					 WHERE p.post_status = 'publish' AND p.post_type = 'product' AND l.max_price > 0"
				);
				if ( $row ) {
					$mn = (float) $row->mn;
					$mx = (float) $row->mx;
				}
			}
			if ( $mx <= 0 ) {
				$row = $wpdb->get_row(
					"SELECT MIN(CAST(m.meta_value AS DECIMAL(12,2))) AS mn, MAX(CAST(m.meta_value AS DECIMAL(12,2))) AS mx
					 FROM {$wpdb->postmeta} m
					 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
					 WHERE m.meta_key = '_price' AND m.meta_value <> '' AND p.post_status = 'publish' AND p.post_type = 'product'"
				);
				if ( $row ) {
					$mn = (float) $row->mn;
					$mx = (float) $row->mx;
				}
			}
			$b = array(
				'min' => (int) floor( $mn ),
				'max' => (int) ceil( $mx ),
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery
			set_transient( 'wcvs_price_bounds', $b, 10 * MINUTE_IN_SECONDS );
		}
		return $b;
	}

	public function active_count() {
		$n = count( $this->selected_cats() );
		list( $min, $max ) = $this->price_params();
		$b = $this->bounds();
		if ( ( null !== $min && $min > $b['min'] ) || ( null !== $max && $max < $b['max'] ) ) {
			$n++;
		}
		return $n;
	}

	/* ---------- Query ---------- */

	/** หน้า Shop / หมวดหมู่ (ราคาใช้ min_price / max_price ของ WooCommerce อยู่แล้ว) */
	public function filter_main_query( $q ) {
		$cats = $this->selected_cats();
		if ( $cats ) {
			$tax = (array) $q->get( 'tax_query' );
			$new = array( 'relation' => 'AND' );
			if ( $tax ) {
				$new[] = $tax;
			}
			$new[] = array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $cats,
				'operator' => 'IN',
			);
			$q->set( 'tax_query', $new );
		}
	}

	/** [wcvs_products] */
	public function filter_shortcode_query( $args, $attrs = array(), $type = '' ) {
		if ( ! $this->in_shortcode ) {
			return $args;
		}

		$cats = $this->selected_cats();
		if ( $cats ) {
			$tax = isset( $args['tax_query'] ) ? (array) $args['tax_query'] : array();
			$new = array( 'relation' => 'AND' );
			if ( $tax ) {
				$new[] = $tax;
			}
			$new[]             = array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $cats,
				'operator' => 'IN',
			);
			$args['tax_query'] = $new;
		}

		list( $min, $max ) = $this->price_params();
		if ( null !== $min || null !== $max ) {
			$meta = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();
			$new  = array( 'relation' => 'AND' );
			if ( $meta ) {
				$new[] = $meta;
			}
			if ( null !== $min ) {
				$new[] = array(
					'key'     => '_price',
					'value'   => $min,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				);
			}
			if ( null !== $max ) {
				$new[] = array(
					'key'     => '_price',
					'value'   => $max,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				);
			}
			$args['meta_query'] = $new;
		}

		return $args;
	}

	/* ---------- Layout ---------- */

	public function layout_open() {
		if ( ! $this->active() ) {
			return;
		}
		$this->opened++;
		echo '<div class="wcvs-layout">';
		$this->render_panel();
		echo '<div class="wcvs-main">';
	}

	public function layout_close() {
		if ( $this->opened > 0 ) {
			$this->opened--;
			echo '</div></div>';
		}
	}

	public function no_products_open() {
		if ( ! $this->active() ) {
			return;
		}
		$this->layout_open();
		echo '<div class="wcvs-toolbar">';
		$this->print_toggle();
		echo '</div>';
	}

	public function shortcode_no_results() {
		if ( ! $this->active() ) {
			return;
		}
		$this->layout_open();
		echo '<div class="wcvs-toolbar">';
		$this->print_toggle();
		echo '</div>';
		echo '<p class="woocommerce-info">' . esc_html__( 'No products were found matching your selection.', 'grid-list-view-switcher-for-woocommerce' ) . '</p>';
		$this->layout_close();
	}

	public function toggle_button() {
		if ( $this->active() ) {
			$this->print_toggle();
		}
	}

	private function print_toggle() {
		$n    = $this->active_count();
		$open = ( 'open' === $this->state() );
		?>
		<button type="button" class="wcvs-filter-toggle" aria-controls="wcvs-filter" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h18l-7 8.5V19l-4 2v-8.5z"/></svg><span><?php esc_html_e( 'Filters', 'grid-list-view-switcher-for-woocommerce' ); ?></span><?php if ( $n ) : ?><b class="wcvs-badge"><?php echo (int) $n; ?></b><?php endif; ?></button>
		<?php
	}

	/* ---------- Panel ---------- */

	/** URL ของหน้าปัจจุบัน (ไม่มี query/หน้าแบ่ง) โดยใช้โดเมนจาก home_url() ไม่ใช่ Host header */
	private function base_url() {
		$req  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = (string) wp_parse_url( $req, PHP_URL_PATH );
		$path = preg_replace( '#/page/\d+/?$#', '/', '' === $path ? '/' : $path );
		$home = wp_parse_url( home_url() );
		$host = isset( $home['host'] ) ? $home['host'] : '';
		if ( isset( $home['port'] ) ) {
			$host .= ':' . (int) $home['port'];
		}
		return set_url_scheme( '//' . $host . $path );
	}

	/** พารามิเตอร์อื่น ๆ ที่ต้องเก็บไว้ (เช่น orderby, s, post_type) */
	private function kept_params() {
		$skip = array( 'wcvs_cat', 'min_price', 'max_price', 'paged', 'product-page' );
		$out  = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- carries public query args (orderby, s, ...) into the filter form; every key and value is sanitized.
		foreach ( wp_unslash( $_GET ) as $k => $v ) {
			if ( in_array( $k, $skip, true ) || ! is_scalar( $v ) ) {
				continue;
			}
			$out[ sanitize_key( $k ) ] = sanitize_text_field( (string) $v );
		}
		return $out;
	}

	private function cat_list( $parent, $selected, $count, $depth = 0 ) {
		$args = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'pad_counts' => true,
			'parent'     => (int) $parent,
			'orderby'    => 'name',
		);
		if ( 0 === (int) $parent ) {
			$default = (int) get_option( 'default_product_cat' );
			if ( $default ) {
				$args['exclude'] = array( $default );
			}
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}
		echo '<ul class="wcvs-cats' . ( $depth ? ' wcvs-cats-sub' : '' ) . '">';
		foreach ( $terms as $t ) {
			echo '<li><label><input type="checkbox" name="wcvs_cat[]" value="' . esc_attr( $t->slug ) . '"' . checked( in_array( $t->slug, $selected, true ), true, false ) . '> <span class="wcvs-cat-name">' . esc_html( $t->name ) . '</span>';
			if ( $count ) {
				echo ' <em class="wcvs-count">' . (int) $t->count . '</em>';
			}
			echo '</label>';
			if ( $depth < 2 ) {
				$this->cat_list( $t->term_id, $selected, $count, $depth + 1 );
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	private function render_panel() {
		$o        = $this->opts();
		$selected = $this->selected_cats();
		$base     = $this->base_url();
		$kept     = $this->kept_params();
		$reset    = $kept ? add_query_arg( $kept, $base ) : $base;
		$n        = $this->active_count();

		// หมวดหมู่
		$show_cats = ! empty( $o['filter_cats'] );
		$parent    = 0;
		if ( $show_cats && function_exists( 'is_product_category' ) && is_product_category() ) {
			$term   = get_queried_object();
			$parent = ( $term && ! empty( $term->term_id ) ) ? (int) $term->term_id : 0;
			if ( $parent ) {
				$children = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true, 'parent' => $parent, 'fields' => 'ids' ) );
				if ( is_wp_error( $children ) || empty( $children ) ) {
					$show_cats = false;
				}
			}
		}

		// ราคา
		$show_price = ! empty( $o['filter_price'] );
		$b          = $this->bounds();
		if ( $b['max'] <= $b['min'] ) {
			$show_price = false;
		}
		list( $pmin, $pmax ) = $this->price_params();
		$cur_min             = ( null === $pmin ) ? $b['min'] : max( $b['min'], min( $b['max'], (int) round( $pmin ) ) );
		$cur_max             = ( null === $pmax ) ? $b['max'] : max( $b['min'], min( $b['max'], (int) round( $pmax ) ) );
		$step                = ( $b['max'] - $b['min'] ) > 5000 ? 10 : 1;
		$symbol              = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '';
		?>
		<aside class="wcvs-filter" id="wcvs-filter" aria-label="<?php esc_attr_e( 'Filters', 'grid-list-view-switcher-for-woocommerce' ); ?>">
			<div class="wcvs-filter-head">
				<strong><?php esc_html_e( 'Filters', 'grid-list-view-switcher-for-woocommerce' ); ?></strong>
				<?php if ( $n ) : ?>
					<a class="wcvs-clear" href="<?php echo esc_url( $reset ); ?>"><?php esc_html_e( 'Clear all', 'grid-list-view-switcher-for-woocommerce' ); ?></a>
				<?php endif; ?>
				<button type="button" class="wcvs-filter-close" aria-label="<?php esc_attr_e( 'Close', 'grid-list-view-switcher-for-woocommerce' ); ?>">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
				</button>
			</div>

			<form class="wcvs-filter-form" method="get" action="<?php echo esc_url( $base ); ?>">
				<?php foreach ( $kept as $k => $v ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
				<?php endforeach; ?>

				<?php if ( $show_cats ) : ?>
					<details class="wcvs-fs" open>
						<summary><?php esc_html_e( 'Categories', 'grid-list-view-switcher-for-woocommerce' ); ?></summary>
						<div class="wcvs-fs-body">
							<?php $this->cat_list( $parent, $selected, ! empty( $o['filter_count'] ) ); ?>
						</div>
					</details>
				<?php endif; ?>

				<?php if ( $show_price ) : ?>
					<details class="wcvs-fs" open>
						<summary><?php esc_html_e( 'Price range', 'grid-list-view-switcher-for-woocommerce' ); ?></summary>
						<div class="wcvs-fs-body">
							<div class="wcvs-price" data-min="<?php echo (int) $b['min']; ?>" data-max="<?php echo (int) $b['max']; ?>">
								<div class="wcvs-range">
									<div class="wcvs-range-track"><div class="wcvs-range-fill"></div></div>
									<input type="range" class="wcvs-range-min" min="<?php echo (int) $b['min']; ?>" max="<?php echo (int) $b['max']; ?>" step="<?php echo (int) $step; ?>" value="<?php echo (int) $cur_min; ?>" aria-label="<?php esc_attr_e( 'Min price', 'grid-list-view-switcher-for-woocommerce' ); ?>">
									<input type="range" class="wcvs-range-max" min="<?php echo (int) $b['min']; ?>" max="<?php echo (int) $b['max']; ?>" step="<?php echo (int) $step; ?>" value="<?php echo (int) $cur_max; ?>" aria-label="<?php esc_attr_e( 'Max price', 'grid-list-view-switcher-for-woocommerce' ); ?>">
								</div>
								<div class="wcvs-price-inputs">
									<label><span><?php echo esc_html( $symbol ); ?></span>
										<input type="number" name="min_price" class="wcvs-in-min" min="<?php echo (int) $b['min']; ?>" max="<?php echo (int) $b['max']; ?>" value="<?php echo (int) $cur_min; ?>" inputmode="numeric"></label>
									<i>&ndash;</i>
									<label><span><?php echo esc_html( $symbol ); ?></span>
										<input type="number" name="max_price" class="wcvs-in-max" min="<?php echo (int) $b['min']; ?>" max="<?php echo (int) $b['max']; ?>" value="<?php echo (int) $cur_max; ?>" inputmode="numeric"></label>
								</div>
							</div>
						</div>
					</details>
				<?php endif; ?>

				<div class="wcvs-filter-actions">
					<button type="submit" class="wcvs-apply"><?php esc_html_e( 'Apply', 'grid-list-view-switcher-for-woocommerce' ); ?></button>
					<a class="wcvs-reset" href="<?php echo esc_url( $reset ); ?>"><?php esc_html_e( 'Reset', 'grid-list-view-switcher-for-woocommerce' ); ?></a>
				</div>
			</form>
		</aside>
		<div class="wcvs-filter-backdrop"></div>
		<?php
	}
}
