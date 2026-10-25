=== Grid/List View Switcher for WooCommerce ===
Contributors: your-wordpress-org-username
Tags: woocommerce, grid view, list view, quick view, product filter
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let shoppers switch product listings between grid and list views, preview products in a Quick View popup, and filter by category and price.

== Description ==

Grid/List View Switcher for WooCommerce adds a "View as" toggle to your shop, product category and product search pages so shoppers can choose between a compact grid and a detailed list.

Features:

* Grid / list toggle next to the result count and sorting dropdown. The shopper's choice is remembered.
* Configurable number of grid columns (1–6) for large screens.
* "View Details" button in list view.
* Quick View popup with image gallery, price, short description, stock, quantity selector and add to cart (including variable products).
* Collapsible filter panel with product categories (with counts) and a price range slider. On mobile it opens as a slide-out drawer.
* `[wcvs_products]` shortcode to show the same layout on any page.

= How to use =

**Shop and category pages**

After activation the grid / list toggle, Quick View button and filter panel appear automatically on the shop page, product category and tag pages, and product search results. No setup is needed.

**Settings**

Go to WooCommerce > View Switcher to change:

* Default view – grid or list for first-time visitors.
* Columns – number of grid columns on large screens (1–6).
* Details button text – label of the button shown in list view.
* Enable Quick View and the Quick View button text.
* Enable filters, whether the filter panel starts open or closed on desktop, and which filters to show (categories, category counts, price range).

Leave a text field empty to use the default, translatable label.

**Shortcode**

Use `[wcvs_products]` to show products with the same toolbar, Quick View and filters on any page or post. It accepts every attribute of WooCommerce's `[products]` shortcode. Common ones:

* `limit` – products per page (default 12).
* `columns` – number of columns.
* `category` – product category slug(s), comma separated.
* `tag` – product tag slug(s), comma separated.
* `ids` / `skus` – show specific products.
* `orderby` / `order` – sorting, e.g. `orderby="price" order="ASC"`. Leave empty to use the shopper's "Sort by" choice.
* `on_sale="true"`, `best_selling="true"`, `top_rated="true"`.
* `filter="false"` – hide the filter panel on this page (specific to this plugin).

Examples:

* `[wcvs_products]` – all products, 12 per page, paginated.
* `[wcvs_products limit="24" category="boxes"]` – the "boxes" category, 24 per page.
* `[wcvs_products on_sale="true" orderby="price" order="ASC"]` – sale products, cheapest first.
* `[wcvs_products category="gifts" filter="false"]` – gifts without the filter panel.

**Links**

* Add `?wcvs_view=list` or `?wcvs_view=grid` to a shop URL to open it in a specific view.
* Filter links use `wcvs_cat`, `min_price` and `max_price`, e.g. `?wcvs_cat=boxes&min_price=100&max_price=500`.

= Privacy =

This plugin does not collect personal data and does not connect to any external service.

It stores two functional cookies in the shopper's browser to remember display preferences:

* `wcvs_view` – the chosen view (grid or list), kept for 1 year.
* `wcvs_filter` – whether the filter panel is open or closed, kept for 1 year.

The chosen view is also saved in the browser's localStorage under `wcvs_view`. You may want to list these cookies in your site's privacy / cookie policy.

== Installation ==

1. Make sure WooCommerce is installed and active.
2. Upload the plugin folder to `/wp-content/plugins/`, or install it from Plugins > Add New.
3. Activate the plugin.
4. Go to WooCommerce > View Switcher to choose the default view, columns, Quick View and filter options.

== Frequently Asked Questions ==

= Does it work with my theme? =

It uses WooCommerce's standard shop loop hooks, so it works with most themes that use WooCommerce templates.

= Can I use it on a custom page? =

Yes. Add the `[wcvs_products]` shortcode to any page or post.

= Does it slow down my store? =

Styles and scripts load only on shop, product category / tag, product search and pages containing the shortcode. The price range is cached and refreshed when products change.

== Screenshots ==

1. Grid view with the filter panel open.
2. List view with View Details buttons.
3. Quick View popup.
4. Settings page.

== Changelog ==

= 2.0.0 =
* Initial public release.
