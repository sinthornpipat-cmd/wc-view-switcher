<?php
/**
 * Quick View popup content.
 * Variables: $product (WC_Product), $details_text (string)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ids = array();
if ( $product->get_image_id() ) {
	$ids[] = $product->get_image_id();
}
$ids  = array_slice( array_unique( array_merge( $ids, $product->get_gallery_image_ids() ) ), 0, 6 );
$main = $ids ? wp_get_attachment_image_url( $ids[0], 'large' ) : wc_placeholder_img_src( 'large' );

$percent = 0;
if ( $product->is_on_sale() && ! $product->is_type( 'variable' ) ) {
	$reg  = (float) $product->get_regular_price();
	$sale = (float) $product->get_sale_price();
	if ( $reg > 0 && $sale > 0 && $sale < $reg ) {
		$percent = round( ( $reg - $sale ) / $reg * 100 );
	}
}
?>
<div class="wcvs-qv">
	<div class="wcvs-qv-media">
		<div class="wcvs-qv-main">
			<?php if ( $percent ) : ?>
				<span class="wcvs-qv-sale">-<?php echo (int) $percent; ?>%</span>
			<?php endif; ?>
			<img class="wcvs-qv-main-img" src="<?php echo esc_url( $main ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>">
		</div>
		<?php if ( count( $ids ) > 1 ) : ?>
			<div class="wcvs-qv-thumbs">
				<?php foreach ( $ids as $i => $img_id ) : ?>
					<button type="button" class="wcvs-qv-thumb<?php echo 0 === $i ? ' is-active' : ''; ?>" data-full="<?php echo esc_url( wp_get_attachment_image_url( $img_id, 'large' ) ); ?>">
						<?php echo wp_kses_post( wp_get_attachment_image( $img_id, 'woocommerce_gallery_thumbnail' ) ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="wcvs-qv-info">
		<h2 class="wcvs-qv-title"><?php echo esc_html( $product->get_name() ); ?></h2>

		<?php if ( function_exists( 'wc_review_ratings_enabled' ) && wc_review_ratings_enabled() && $product->get_rating_count() > 0 ) : ?>
			<div class="wcvs-qv-rating"><?php echo wp_kses_post( wc_get_rating_html( $product->get_average_rating() ) ); ?></div>
		<?php endif; ?>

		<div class="wcvs-qv-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>

		<?php if ( $product->get_short_description() ) : ?>
			<div class="wcvs-qv-desc"><?php echo wp_kses_post( apply_filters( 'woocommerce_short_description', $product->get_short_description() ) ); ?></div>
		<?php endif; ?>

		<ul class="wcvs-qv-meta">
			<?php if ( $product->get_sku() ) : ?>
				<li><span><?php esc_html_e( 'SKU', 'grid-list-view-switcher-for-woocommerce' ); ?></span> <?php echo esc_html( $product->get_sku() ); ?></li>
			<?php endif; ?>
			<?php if ( function_exists( 'wc_get_stock_html' ) ) : ?>
				<li class="wcvs-qv-stock"><?php echo wp_kses_post( wc_get_stock_html( $product ) ); ?></li>
			<?php endif; ?>
		</ul>

		<div class="wcvs-qv-cart">
			<?php
			if ( $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() ) :
				$min = max( 1, (int) $product->get_min_purchase_quantity() );
				$max = (int) $product->get_max_purchase_quantity();
				?>
				<div class="wcvs-qty">
					<button type="button" class="wcvs-qty-minus" aria-label="<?php esc_attr_e( 'Decrease quantity', 'grid-list-view-switcher-for-woocommerce' ); ?>">&minus;</button>
					<input type="number" class="wcvs-qty-input" value="<?php echo (int) $min; ?>" min="<?php echo (int) $min; ?>" <?php if ( $max > 0 ) : ?>max="<?php echo (int) $max; ?>"<?php endif; ?> step="1" inputmode="numeric" aria-label="<?php esc_attr_e( 'Quantity', 'grid-list-view-switcher-for-woocommerce' ); ?>">
					<button type="button" class="wcvs-qty-plus" aria-label="<?php esc_attr_e( 'Increase quantity', 'grid-list-view-switcher-for-woocommerce' ); ?>">+</button>
				</div>
				<a href="<?php echo esc_url( $product->add_to_cart_url() ); ?>"
					data-quantity="<?php echo (int) $min; ?>"
					data-product_id="<?php echo (int) $product->get_id(); ?>"
					data-product_sku="<?php echo esc_attr( $product->get_sku() ); ?>"
					class="button wcvs-qv-add add_to_cart_button ajax_add_to_cart"
					rel="nofollow"><?php echo esc_html( $product->add_to_cart_text() ); ?></a>
			<?php else : ?>
				<?php woocommerce_template_single_add_to_cart(); ?>
			<?php endif; ?>
		</div>

		<a class="wcvs-qv-full" href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>">
			<?php echo esc_html( $details_text ); ?> &rarr;
		</a>
	</div>
</div>
