<?php
/**
 * Plugin Name: Peak Core
 * Description: Seeds Elementor pages with nested containers (every child editable), Woo helpers, and site options for Peak.
 * Version: 1.1.33
 * Author: Peak
 * Requires Plugins: elementor
 * Text Domain: peak-core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPB_CORE_VERSION', '1.1.33' );
define( 'TPB_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'TPB_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once TPB_CORE_DIR . 'includes/class-elementor-nodes.php';
require_once TPB_CORE_DIR . 'includes/class-page-seeder.php';
require_once TPB_CORE_DIR . 'includes/class-product-seeder.php';

/**
 * WP-CLI: wp peak seed-pages | seed-products
 */
final class TPB_CLI_Command {

	/**
	 * Seed Elementor nested-container pages.
	 *
	 * ## EXAMPLES
	 *
	 *     wp peak seed-pages
	 *
	 * @subcommand seed-pages
	 * @when after_wp_load
	 */
	public function seed_pages(): void {
		$ids = TPB_Page_Seeder::seed();
		\WP_CLI::success( 'Seeded ' . count( $ids ) . ' Elementor pages.' );
	}

	/**
	 * Seed WooCommerce products and stacks from catalog.json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp peak seed-products
	 *
	 * @subcommand seed-products
	 * @when after_wp_load
	 */
	public function seed_products(): void {
		$result = TPB_Product_Seeder::seed( false );
		\WP_CLI::success(
			sprintf(
				'Seeded %d products and %d stacks.',
				(int) $result['products'],
				(int) $result['stacks']
			)
		);
	}

	/**
	 * Re-attach WebP catalog images to existing products/stacks (no SKU rewrite).
	 *
	 * ## EXAMPLES
	 *
	 *     wp peak refresh-product-images
	 *
	 * @subcommand refresh-product-images
	 * @when after_wp_load
	 */
	public function refresh_product_images(): void {
		$media = TPB_Product_Seeder::import_all_media();
		$result = TPB_Product_Seeder::refresh_images();
		\WP_CLI::success(
			sprintf(
				'Media: imported %d (skipped %d). Refreshed images on %d products and %d stacks.',
				(int) $media['imported'],
				(int) $media['skipped'],
				(int) $result['products'],
				(int) $result['stacks']
			)
		);
	}

	/**
	 * Permanently delete all products, variations, and their product images.
	 *
	 * ## EXAMPLES
	 *
	 *     wp peak wipe-products
	 *
	 * @subcommand wipe-products
	 * @when after_wp_load
	 */
	public function wipe_products(): void {
		$result = TPB_Product_Seeder::wipe_all();
		\WP_CLI::success(
			sprintf(
				'Deleted %d products/variations and %d attachments.',
				(int) $result['products'],
				(int) $result['attachments']
			)
		);
	}

	/**
	 * Convert simple products that already have dosage variations (NAD+, SS-31, etc).
	 *
	 * ## EXAMPLES
	 *
	 *     wp peak fix-variable-products
	 *
	 * @subcommand fix-variable-products
	 * @when after_wp_load
	 */
	public function fix_variable_products(): void {
		$count = TPB_Product_Seeder::promote_simple_parents_with_children();
		\WP_CLI::success( sprintf( 'Converted %d products to variable.', $count ) );
	}
}

/**
 * Register CLI.
 */
function tpb_core_register_cli(): void {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		return;
	}
	\WP_CLI::add_command( 'peak', 'TPB_CLI_Command' );
}
add_action( 'cli_init', 'tpb_core_register_cli' );

/**
 * One-time seed after activation (safe no-op if pages exist).
 */
function tpb_core_activate(): void {
	update_option( 'tpb_core_needs_seed', '1' );
}
register_activation_hook( __FILE__, 'tpb_core_activate' );

/**
 * Run seeder once Elementor is available.
 * Also refreshes pages whose layout revision was bumped (e.g. About).
 */
function tpb_core_maybe_seed(): void {
	if ( function_exists( 'peak_is_elementor_canvas' ) && peak_is_elementor_canvas() ) {
		return;
	}

	$needs = get_option( 'tpb_core_needs_seed' ) === '1';
	$rev   = (string) get_option( 'tpb_core_layout_bundle', '' );
	$want  = 'blog-1';

	if ( ! $needs && $rev === $want ) {
		return;
	}
	if ( ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
		return;
	}
	TPB_Page_Seeder::seed();
	delete_option( 'tpb_core_needs_seed' );
	update_option( 'tpb_core_layout_bundle', $want, false );
}
add_action( 'init', 'tpb_core_maybe_seed', 30 );

/**
 * One-time: attach compressed olive stack photos as featured images.
 */
function tpb_core_maybe_refresh_stack_images(): void {
	if ( get_option( 'tpb_stack_olive_v1' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}
	TPB_Product_Seeder::refresh_images();
	update_option( 'tpb_stack_olive_v1', '1', false );
}
add_action( 'init', 'tpb_core_maybe_refresh_stack_images', 40 );

/**
 * One-time: corrected catalog/stack WebPs (normal quality) into Media + product thumbs.
 */
function tpb_core_maybe_refresh_catalog_images_v2(): void {
	if ( get_option( 'tpb_catalog_images_v2' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}
	update_option( 'tpb_catalog_images_v2', '1', false );
	try {
		TPB_Product_Seeder::import_all_media();
		TPB_Product_Seeder::refresh_images();
	} catch ( \Throwable $e ) {
		error_log( 'Peak catalog images v2 failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
add_action( 'init', 'tpb_core_maybe_refresh_catalog_images_v2', 41 );

/**
 * One-time: replace seeded WooCommerce prices/variations from the latest catalog sheet.
 */
function tpb_core_maybe_reseed_sheet_prices(): void {
	if ( get_option( 'tpb_prices_sheet_v5' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}
	// Lock first so a timeout/fatal cannot crash every subsequent /products/ request.
	update_option( 'tpb_prices_sheet_v5', '1', false );
	update_option( 'tpb_force_reseed_products', '1' );
	try {
		TPB_Product_Seeder::seed( false );
	} catch ( \Throwable $e ) {
		error_log( 'Peak price reseed failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	delete_option( 'tpb_force_reseed_products' );
}
add_action( 'init', 'tpb_core_maybe_reseed_sheet_prices', 42 );

/**
 * One-time: Semaglutide $59.99, CJC/Tesa → Growth Peptides, refresh olive stamp images.
 */
function tpb_core_maybe_catalog_olive_v1(): void {
	if ( get_option( 'tpb_catalog_olive_v1' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}
	update_option( 'tpb_catalog_olive_v1', '1', false );
	update_option( 'tpb_force_reseed_products', '1' );
	try {
		TPB_Product_Seeder::seed( false );
		TPB_Product_Seeder::refresh_images();
	} catch ( \Throwable $e ) {
		error_log( 'Peak olive catalog sync failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	delete_option( 'tpb_force_reseed_products' );
}
add_action( 'init', 'tpb_core_maybe_catalog_olive_v1', 44 );

/**
 * One-time: ensure Retatrutide (and pinned peers) use the correct product categories.
 */
function tpb_core_maybe_catalog_categories_v2(): void {
	if ( get_option( 'tpb_catalog_categories_v2' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}
	update_option( 'tpb_catalog_categories_v2', '1', false );
	update_option( 'tpb_force_reseed_products', '1' );
	try {
		TPB_Product_Seeder::seed( false );
	} catch ( \Throwable $e ) {
		error_log( 'Peak category sync failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	delete_option( 'tpb_force_reseed_products' );
}
add_action( 'init', 'tpb_core_maybe_catalog_categories_v2', 45 );

/**
 * One-time: re-push olive dosage/batch stamps onto all WC product images.
 * Prior olive_v1 could mark complete before refresh finished, leaving some red stamps.
 */
function tpb_core_maybe_olive_stamps_v3(): void {
	if ( get_option( 'tpb_olive_stamps_v3' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}

	$attempts = (int) get_option( 'tpb_olive_stamps_v3_attempts', 0 );
	if ( $attempts >= 5 ) {
		update_option( 'tpb_olive_stamps_v3', '1', false );
		return;
	}
	update_option( 'tpb_olive_stamps_v3_attempts', $attempts + 1, false );

	try {
		$result   = TPB_Product_Seeder::refresh_images();
		$products = is_array( $result ) ? (int) ( $result['products'] ?? 0 ) : 0;
		$stacks   = is_array( $result ) ? (int) ( $result['stacks'] ?? 0 ) : 0;
		// Success, or last attempt — stop retrying.
		if ( ( $products + $stacks ) > 0 || $attempts >= 4 ) {
			update_option( 'tpb_olive_stamps_v3', '1', false );
		}
	} catch ( \Throwable $e ) {
		error_log( 'Peak olive stamp refresh failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
add_action( 'init', 'tpb_core_maybe_olive_stamps_v3', 46 );

/**
 * One-time: push mountain-HD product WebPs (olive stamps baked in) to WC media.
 */
function tpb_core_maybe_mountain_hd_images_v4(): void {
	if ( get_option( 'tpb_mountain_hd_images_v4' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}

	$attempts = (int) get_option( 'tpb_mountain_hd_images_v4_attempts', 0 );
	if ( $attempts >= 5 ) {
		update_option( 'tpb_mountain_hd_images_v4', '1', false );
		return;
	}
	update_option( 'tpb_mountain_hd_images_v4_attempts', $attempts + 1, false );

	try {
		$result   = TPB_Product_Seeder::refresh_images();
		$products = is_array( $result ) ? (int) ( $result['products'] ?? 0 ) : 0;
		$stacks   = is_array( $result ) ? (int) ( $result['stacks'] ?? 0 ) : 0;
		if ( ( $products + $stacks ) > 0 || $attempts >= 4 ) {
			update_option( 'tpb_mountain_hd_images_v4', '1', false );
		}
	} catch ( \Throwable $e ) {
		error_log( 'Peak mountain HD image refresh failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
add_action( 'init', 'tpb_core_maybe_mountain_hd_images_v4', 47 );

/**
 * One-time: re-push product WebPs with olive dosage/batch corner stamps restored.
 */
function tpb_core_maybe_product_stamp_overlay_v5(): void {
	if ( get_option( 'tpb_product_stamp_overlay_v5' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}

	$attempts = (int) get_option( 'tpb_product_stamp_overlay_v5_attempts', 0 );
	if ( $attempts >= 5 ) {
		update_option( 'tpb_product_stamp_overlay_v5', '1', false );
		return;
	}
	update_option( 'tpb_product_stamp_overlay_v5_attempts', $attempts + 1, false );

	try {
		$result   = TPB_Product_Seeder::refresh_images();
		$products = is_array( $result ) ? (int) ( $result['products'] ?? 0 ) : 0;
		$stacks   = is_array( $result ) ? (int) ( $result['stacks'] ?? 0 ) : 0;
		if ( ( $products + $stacks ) > 0 || $attempts >= 4 ) {
			update_option( 'tpb_product_stamp_overlay_v5', '1', false );
		}
	} catch ( \Throwable $e ) {
		error_log( 'Peak stamp overlay refresh failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
add_action( 'init', 'tpb_core_maybe_product_stamp_overlay_v5', 48 );

/**
 * One-time: sideload Tirzepatide 10mg mountain-HD WebP.
 */
function tpb_core_maybe_tirz10_mountain_hd_v6(): void {
	if ( get_option( 'tpb_tirz10_mountain_hd_v6' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}

	$attempts = (int) get_option( 'tpb_tirz10_mountain_hd_v6_attempts', 0 );
	if ( $attempts >= 5 ) {
		update_option( 'tpb_tirz10_mountain_hd_v6', '1', false );
		return;
	}
	update_option( 'tpb_tirz10_mountain_hd_v6_attempts', $attempts + 1, false );

	try {
		$result   = TPB_Product_Seeder::refresh_images();
		$products = is_array( $result ) ? (int) ( $result['products'] ?? 0 ) : 0;
		$stacks   = is_array( $result ) ? (int) ( $result['stacks'] ?? 0 ) : 0;
		if ( ( $products + $stacks ) > 0 || $attempts >= 4 ) {
			update_option( 'tpb_tirz10_mountain_hd_v6', '1', false );
		}
	} catch ( \Throwable $e ) {
		error_log( 'Peak Tirz 10mg image refresh failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
add_action( 'init', 'tpb_core_maybe_tirz10_mountain_hd_v6', 49 );

/**
 * One-time: Elementor contact + storage pages (remove phone, drop BAC promo line).
 */
function tpb_core_maybe_contact_storage_elementor_v1(): void {
	if ( get_option( 'tpb_contact_storage_elementor_v1' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'TPB_Page_Seeder' ) ) {
		return;
	}

	try {
		TPB_Page_Seeder::seed();
		$storage = get_page_by_path( 'storage-handling' );
		if ( $storage instanceof WP_Post ) {
			delete_post_meta( (int) $storage->ID, '_tpb_legal' );
			update_post_meta( (int) $storage->ID, '_wp_page_template', 'default' );
		}
		$contact = get_page_by_path( 'contact' );
		if ( $contact instanceof WP_Post ) {
			update_post_meta( (int) $contact->ID, '_wp_page_template', 'default' );
		}
		update_option( 'tpb_contact_storage_elementor_v1', '1', false );
	} catch ( \Throwable $e ) {
		error_log( 'Peak contact/storage Elementor sync failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
add_action( 'init', 'tpb_core_maybe_contact_storage_elementor_v1', 50 );

/**
 * One-time: Partner With Us investment inquiry form page.
 */
function tpb_core_maybe_partner_page_v1(): void {
	if ( get_option( 'tpb_partner_page_v1' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'TPB_Page_Seeder' ) ) {
		return;
	}

	try {
		TPB_Page_Seeder::seed();
		update_option( 'tpb_partner_page_v1', '1', false );
	} catch ( \Throwable $e ) {
		error_log( 'Peak partner page sync failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
add_action( 'init', 'tpb_core_maybe_partner_page_v1', 51 );

/**
 * One-time: Janoshik COA pack — multi-dose PDFs, product status, purity copy, Ipamorelin.
 */
function tpb_core_maybe_coa_lab_pack_v1(): void {
	if ( get_option( 'tpb_coa_lab_pack_v1' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}

	$attempts = (int) get_option( 'tpb_coa_lab_pack_v1_attempts', 0 );
	if ( $attempts >= 5 ) {
		update_option( 'tpb_coa_lab_pack_v1', '1', false );
		return;
	}
	update_option( 'tpb_coa_lab_pack_v1_attempts', $attempts + 1, false );

	try {
		update_option( 'tpb_force_reseed_products', '1' );
		TPB_Product_Seeder::seed();
		delete_option( 'tpb_force_reseed_products' );
		TPB_Product_Seeder::refresh_images();
		if ( class_exists( 'TPB_Page_Seeder' ) ) {
			TPB_Page_Seeder::seed();
		}
		update_option( 'tpb_coa_lab_pack_v1', '1', false );
	} catch ( \Throwable $e ) {
		delete_option( 'tpb_force_reseed_products' );
		error_log( 'Peak COA lab pack sync failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
add_action( 'init', 'tpb_core_maybe_coa_lab_pack_v1', 52 );

/**
 * One-time: Reta / Tesa / Glow / Klow price update.
 */
function tpb_core_maybe_prices_v7(): void {
	if ( get_option( 'tpb_prices_v7' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}
	update_option( 'tpb_prices_v7', '1', false );
	update_option( 'tpb_force_reseed_products', '1' );
	try {
		TPB_Product_Seeder::seed( false );
	} catch ( \Throwable $e ) {
		error_log( 'Peak price reseed v7 failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	delete_option( 'tpb_force_reseed_products' );
}
add_action( 'init', 'tpb_core_maybe_prices_v7', 53 );

/**
 * One-time: draft Bacteriostatic Water and relabel Wolverine as 10mg.
 */
function tpb_core_maybe_bac_hide_wolverine_10mg_v1(): void {
	if ( get_option( 'tpb_bac_hide_wolverine_10mg_v1' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}
	update_option( 'tpb_bac_hide_wolverine_10mg_v1', '1', false );
	update_option( 'tpb_force_reseed_products', '1' );
	try {
		TPB_Product_Seeder::seed( false );
	} catch ( \Throwable $e ) {
		error_log( 'Peak bac/wolverine sync failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	delete_option( 'tpb_force_reseed_products' );

	// Belt-and-suspenders: draft any leftover bac-water product even if seeding skipped it.
	$bac_ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'   => '_tpb_catalog_id',
					'value' => 'bac-water',
				),
				array(
					'key'   => '_sku',
					'value' => 'TPB-BACWATER-3',
				),
			),
		)
	);
	foreach ( $bac_ids as $bac_id ) {
		$product = wc_get_product( (int) $bac_id );
		if ( ! $product instanceof WC_Product ) {
			continue;
		}
		$product->set_status( 'draft' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_stock_status( 'outofstock' );
		$product->save();
	}
}
add_action( 'init', 'tpb_core_maybe_bac_hide_wolverine_10mg_v1', 54 );

/**
 * One-time: GHK-Cu 50mg, Glow COA live, Retatrutide 10mg.
 */
function tpb_core_maybe_coa_variants_v2(): void {
	if ( get_option( 'tpb_coa_variants_v2' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}
	update_option( 'tpb_coa_variants_v2', '1', false );
	update_option( 'tpb_force_reseed_products', '1' );
	try {
		TPB_Product_Seeder::seed( false );
	} catch ( \Throwable $e ) {
		error_log( 'Peak COA variants v2 reseed failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	delete_option( 'tpb_force_reseed_products' );
}
add_action( 'init', 'tpb_core_maybe_coa_variants_v2', 55 );

/**
 * One-time: KLOW + tp-sem1 → IN STOCK (clear pending/coming-soon tags).
 */
function tpb_core_maybe_stock_klow_sema_v1(): void {
	if ( get_option( 'tpb_stock_klow_sema_v1' ) === '1' ) {
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'TPB_Product_Seeder' ) ) {
		return;
	}
	update_option( 'tpb_stock_klow_sema_v1', '1', false );
	update_option( 'tpb_force_reseed_products', '1' );
	try {
		TPB_Product_Seeder::seed( false );
	} catch ( \Throwable $e ) {
		error_log( 'Peak KLOW/tp-sem1 stock reseed failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
	delete_option( 'tpb_force_reseed_products' );
}
add_action( 'init', 'tpb_core_maybe_stock_klow_sema_v1', 56 );
