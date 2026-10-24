<?php
/**
 * Seed WooCommerce products + stacks from catalog.json + bundled images.
 *
 * @package Peak_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TPB_Product_Seeder {

	/**
	 * @return array{products:int,stacks:int}
	 */
	public static function seed( bool $flush_rewrites = true ): array {
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed,WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'products' => 0, 'stacks' => 0 );
		}

		$catalog = self::load_catalog();
		$products = isset( $catalog['products'] ) && is_array( $catalog['products'] ) ? $catalog['products'] : array();
		$stacks   = isset( $catalog['stacks'] ) && is_array( $catalog['stacks'] ) ? $catalog['stacks'] : array();

		$product_count = 0;
		foreach ( $products as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			try {
				if ( self::upsert_product( $item ) ) {
					++$product_count;
				}
			} catch ( \Throwable $e ) {
				error_log( 'TPB_Product_Seeder product: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		$stack_count = 0;
		foreach ( $stacks as $stack ) {
			if ( ! is_array( $stack ) ) {
				continue;
			}
			try {
				if ( self::upsert_stack( $stack ) ) {
					++$stack_count;
				}
			} catch ( \Throwable $e ) {
				error_log( 'TPB_Product_Seeder stack: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		self::promote_simple_parents_with_children();

		update_option( 'woocommerce_shop_page_id', (string) self::ensure_shop_page() );
		update_option( 'woocommerce_coming_soon', 'no' );
		if ( $flush_rewrites ) {
			flush_rewrite_rules( false );
		}

		return array(
			'products' => $product_count,
			'stacks'   => $stack_count,
		);
	}

	/**
	 * Permanently delete all WooCommerce products/variations and their attached images.
	 *
	 * @return array{products:int,attachments:int}
	 */
	public static function wipe_all(): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'products' => 0, 'attachments' => 0 );
		}

		$ids = get_posts(
			array(
				'post_type'      => array( 'product', 'product_variation' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		$deleted_products    = 0;
		$deleted_attachments = 0;
		$seen_attachments    = array();

		foreach ( $ids as $id ) {
			$id = (int) $id;
			$product = wc_get_product( $id );
			if ( $product ) {
				$thumb = (int) $product->get_image_id();
				if ( $thumb > 0 ) {
					$seen_attachments[ $thumb ] = true;
				}
				foreach ( (array) $product->get_gallery_image_ids() as $gid ) {
					$gid = (int) $gid;
					if ( $gid > 0 ) {
						$seen_attachments[ $gid ] = true;
					}
				}
			} else {
				$thumb = (int) get_post_thumbnail_id( $id );
				if ( $thumb > 0 ) {
					$seen_attachments[ $thumb ] = true;
				}
			}

			$result = wp_delete_post( $id, true );
			if ( $result ) {
				++$deleted_products;
			}
		}

		foreach ( array_keys( $seen_attachments ) as $attach_id ) {
			$attach_id = (int) $attach_id;
			if ( $attach_id <= 0 ) {
				continue;
			}
			// Only remove media still unused by other posts.
			$parents = get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_thumbnail_id',
					'meta_value'     => (string) $attach_id,
				)
			);
			if ( $parents !== array() ) {
				continue;
			}
			if ( wp_delete_attachment( $attach_id, true ) ) {
				++$deleted_attachments;
			}
		}

		return array(
			'products'    => $deleted_products,
			'attachments' => $deleted_attachments,
		);
	}

	/**
	 * Convert simple parents that already have variation children (e.g. SS-31, NAD+).
	 */
	public static function promote_simple_parents_with_children(): int {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$converted = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			$product = wc_get_product( $id );
			if ( ! $product || $product->is_type( 'variable' ) ) {
				continue;
			}
			$kids = get_posts(
				array(
					'post_parent'    => $id,
					'post_type'      => 'product_variation',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);
			if ( $kids === array() ) {
				continue;
			}
			self::ensure_variable_product( $id );
			if ( class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $id );
			}
			++$converted;
		}
		return $converted;
	}

	/**
	 * Import every product/stack/brand asset into the Media Library (visible in WP Admin).
	 *
	 * @return array{imported:int,skipped:int}
	 */
	public static function import_all_media(): array {
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed,WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$imported = 0;
		$skipped  = 0;
		$dirs     = array(
			TPB_CORE_DIR . 'assets/products/',
			TPB_CORE_DIR . 'assets/stacks/',
			TPB_CORE_DIR . 'assets/brand/',
		);
		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$files = array();
			foreach ( array( 'webp', 'png', 'jpg', 'jpeg' ) as $ext ) {
				$found = glob( $dir . '*.' . $ext );
				if ( is_array( $found ) ) {
					$files = array_merge( $files, $found );
				}
			}
			foreach ( $files as $file ) {
				$basename = basename( (string) $file );
				if ( $basename === '' || str_starts_with( $basename, '.' ) ) {
					continue;
				}
				$stem = (string) preg_replace( '/\.(png|jpe?g|webp)$/i', '', $basename );
				$existing = self::find_catalog_attachment( $stem );
				if ( $existing > 0 ) {
					++$skipped;
					continue;
				}
				$id = self::sideload_image( (string) $file, 0, $stem, false );
				if ( $id > 0 ) {
					++$imported;
				}
			}
		}
		return array(
			'imported' => $imported,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Re-attach compressed catalog images without rewriting SKUs/variations.
	 *
	 * Updates parent featured image and each dosage variation image.
	 *
	 * @return array{products:int,stacks:int}
	 */
	public static function refresh_images(): array {
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed,WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array( 'products' => 0, 'stacks' => 0 );
		}

		$catalog  = self::load_catalog();
		$products = isset( $catalog['products'] ) && is_array( $catalog['products'] ) ? $catalog['products'] : array();
		$stacks   = isset( $catalog['stacks'] ) && is_array( $catalog['stacks'] ) ? $catalog['stacks'] : array();

		$product_count = 0;
		foreach ( $products as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$slug = sanitize_title( (string) ( $item['id'] ?? '' ) );
			$id   = self::find_product_id_by_slug( $slug );
			if ( $id <= 0 ) {
				continue;
			}
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}

			$variants = isset( $item['variants'] ) && is_array( $item['variants'] ) ? $item['variants'] : array();
			$touched  = false;

			if ( $product->is_type( 'variable' ) && ! empty( $variants ) ) {
				$by_sku    = array();
				$by_dosage = array();
				foreach ( $product->get_children() as $child_id ) {
					$child = wc_get_product( (int) $child_id );
					if ( ! $child instanceof WC_Product_Variation ) {
						continue;
					}
					$child_sku = (string) $child->get_sku();
					if ( $child_sku !== '' ) {
						$by_sku[ $child_sku ] = $child;
					}
					$attrs        = $child->get_attributes();
					$child_dosage = (string) ( $attrs['dosage'] ?? $attrs['Dosage'] ?? '' );
					if ( $child_dosage !== '' ) {
						$by_dosage[ $child_dosage ] = $child;
					}
				}

				foreach ( $variants as $v ) {
					if ( ! is_array( $v ) ) {
						continue;
					}
					$sku        = (string) ( $v['sku'] ?? '' );
					$dosage     = (string) ( $v['dosage'] ?? '' );
					$image_file = self::variant_image_basename( (string) ( $v['image'] ?? '' ) );
					if ( $image_file === '' ) {
						continue;
					}
					$variation = ( $sku !== '' && isset( $by_sku[ $sku ] ) )
						? $by_sku[ $sku ]
						: ( ( $dosage !== '' && isset( $by_dosage[ $dosage ] ) ) ? $by_dosage[ $dosage ] : null );
					if ( ! $variation instanceof WC_Product_Variation ) {
						continue;
					}
					$attach_id = self::sideload_image(
						self::resolve_catalog_image( 'products', $image_file ),
						$id,
						trim( (string) ( $item['name'] ?? $slug ) . ' ' . $dosage ),
						true
					);
					if ( $attach_id <= 0 ) {
						continue;
					}
					$variation->set_image_id( $attach_id );
					$variation->save();
					$touched = true;
				}
			}

			$image_file = self::variant_image_basename( (string) ( $variants[0]['image'] ?? '' ) );
			if ( $image_file === '' ) {
				if ( $touched ) {
					++$product_count;
				}
				continue;
			}
			$attach_id = self::sideload_image(
				self::resolve_catalog_image( 'products', $image_file ),
				$id,
				(string) ( $item['name'] ?? $slug ),
				true
			);
			if ( $attach_id > 0 ) {
				$product->set_image_id( $attach_id );
				$product->save();
				$touched = true;
			}

			if ( $product->is_type( 'variable' ) && class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $id );
			}

			if ( $touched ) {
				++$product_count;
			}
		}

		$stack_count = 0;
		foreach ( $stacks as $stack ) {
			if ( ! is_array( $stack ) ) {
				continue;
			}
			$slug = sanitize_title( (string) ( $stack['id'] ?? '' ) );
			$id   = self::find_product_id_by_slug( $slug );
			if ( $id <= 0 ) {
				continue;
			}
			$image_file = self::variant_image_basename( (string) ( $stack['image'] ?? '' ) );
			if ( $image_file === '' ) {
				continue;
			}
			$attach_id = self::sideload_image( self::resolve_catalog_image( 'stacks', $image_file ), $id, (string) ( $stack['name'] ?? $slug ), true );
			if ( ! $attach_id ) {
				continue;
			}
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$product->set_image_id( $attach_id );
			$product->save();
			++$stack_count;
		}

		return array(
			'products' => $product_count,
			'stacks'   => $stack_count,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function load_catalog(): array {
		$path = TPB_CORE_DIR . 'data/catalog.json';
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$raw = file_get_contents( $path );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : array();
	}

	private static function ensure_shop_page(): int {
		$page = get_page_by_path( 'products' );
		if ( $page instanceof WP_Post ) {
			return (int) $page->ID;
		}
		$shop = get_page_by_path( 'shop' );
		if ( $shop instanceof WP_Post ) {
			return (int) $shop->ID;
		}
		return (int) wp_insert_post(
			array(
				'post_title'  => 'Shop',
				'post_name'   => 'shop',
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
	}

	private static function ensure_variable_product( int $product_id ): ?\WC_Product_Variable {
		if ( $product_id <= 0 ) {
			return null;
		}

		wp_remove_object_terms( $product_id, 'simple', 'product_type' );
		wp_set_object_terms( $product_id, 'variable', 'product_type' );
		delete_transient( 'wc_product_type_' . $product_id );
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}
		clean_post_cache( $product_id );

		$product = new \WC_Product_Variable( $product_id );
		$product->set_regular_price( '' );
		$product->set_sale_price( '' );
		$product->set_manage_stock( false );
		$product->save();

		$fresh = wc_get_product( $product_id );
		return $fresh instanceof \WC_Product_Variable ? $fresh : $product;
	}

	/**
	 * @param array<string,mixed> $item Catalog product.
	 */
	private static function upsert_product( array $item ): bool {
		$slug = sanitize_title( (string) ( $item['id'] ?? '' ) );
		if ( $slug === '' ) {
			return false;
		}

		$existing_id = self::find_product_id_by_slug( $slug );
		$variants    = isset( $item['variants'] ) && is_array( $item['variants'] ) ? $item['variants'] : array();
		$is_variable = count( $variants ) > 1;

		if ( $existing_id ) {
			$product = wc_get_product( $existing_id );
			if ( ! $product ) {
				return false;
			}
			if ( get_post_meta( $existing_id, '_tpb_seeded', true ) === '1' && get_option( 'tpb_force_reseed_products' ) !== '1' ) {
				return true;
			}
			if ( $is_variable ) {
				self::release_parent_variant_sku( $existing_id, $variants );
				$converted = self::ensure_variable_product( $existing_id );
				$product   = $converted instanceof WC_Product_Variable
					? $converted
					: new WC_Product_Variable( $existing_id );
			}
		} else {
			$product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
			$product->set_slug( $slug );
		}

		$product->set_name( (string) ( $item['name'] ?? $slug ) );
		$hidden      = ! empty( $item['hiddenFromShop'] );
		$is_published = ( ! isset( $item['published'] ) || $item['published'] !== false ) && ! $hidden;
		$product->set_status( $is_published ? 'publish' : 'draft' );
		$product->set_catalog_visibility( $hidden ? 'hidden' : 'visible' );
		$product->set_description( self::build_description( $item ) );
		$product->set_short_description( (string) ( $item['subtitle'] ?? '' ) );
		if ( $is_variable ) {
			$current_sku = (string) $product->get_sku();
			foreach ( $variants as $v ) {
				if ( $current_sku !== '' && $current_sku === (string) ( $v['sku'] ?? '' ) ) {
					$product->set_sku( '' );
					break;
				}
			}
		}
		self::set_sku_safe( $product, self::parent_sku( $item, $variants ) );
			$product->set_manage_stock( false );
		$coming_soon      = ! empty( $item['comingSoon'] );
		$pending_testing = ! empty( $item['pendingTesting'] );
		if ( $coming_soon || $pending_testing ) {
			$product->set_stock_status( 'outofstock' );
			$product->set_catalog_visibility( 'visible' );
		} else {
			$product->set_stock_status( ! empty( $item['inStock'] ) ? 'instock' : 'outofstock' );
		}
		$product->set_sold_individually( false );
		$product->set_category_ids( array( self::ensure_category( (string) ( $item['category'] ?? 'Peptides' ) ) ) );

		if ( ! $is_variable && ! empty( $variants[0] ) && is_array( $variants[0] ) ) {
			self::apply_variant_pricing( $product, $variants[0] );
			self::set_sku_safe( $product, (string) ( $variants[0]['sku'] ?? '' ) );
		} elseif ( $is_variable ) {
			$product->set_regular_price( '' );
			$product->set_sale_price( '' );
		}

		$product_id = $product->save();
		if ( ! $product_id ) {
			return false;
		}

		update_post_meta( $product_id, '_tpb_seeded', '1' );
		update_post_meta( $product_id, '_tpb_catalog_id', $slug );
		if ( ! empty( $item['comingSoon'] ) ) {
			update_post_meta( $product_id, '_tpb_coming_soon', '1' );
		} else {
			delete_post_meta( $product_id, '_tpb_coming_soon' );
		}
		if ( ! empty( $item['pendingTesting'] ) ) {
			update_post_meta( $product_id, '_tpb_pending_testing', '1' );
		} else {
			delete_post_meta( $product_id, '_tpb_pending_testing' );
		}
		self::save_research_meta( $product_id, $item );

		$dosages = array();
		foreach ( $variants as $v ) {
			if ( ! empty( $v['dosage'] ) ) {
				$dosages[] = (string) $v['dosage'];
			}
		}
		if ( $dosages ) {
			update_post_meta( $product_id, '_tpb_dosage', implode( ', ', $dosages ) );
		}

		if ( (int) $product->get_image_id() <= 0 && ! empty( $variants[0] ) && is_array( $variants[0] ) ) {
			$image_file = self::variant_image_basename( (string) ( $variants[0]['image'] ?? '' ) );
			if ( $image_file !== '' ) {
				$attach_id = self::sideload_image( self::resolve_catalog_image( 'products', $image_file ), $product_id, (string) $item['name'] );
				if ( $attach_id ) {
					$product->set_image_id( $attach_id );
					$product->save();
				}
			}
		}

		if ( $is_variable ) {
			self::ensure_variable_product( $product_id );
			self::sync_variations( $product_id, $variants );
			self::ensure_variable_product( $product_id );
		}

		return true;
	}

	/**
	 * Parent must not keep a variation SKU (WooCommerce rejects duplicate SKUs).
	 *
	 * @param array<int,array<string,mixed>> $variants Variants.
	 */
	private static function release_parent_variant_sku( int $product_id, array $variants ): void {
		$parent = wc_get_product( $product_id );
		if ( ! $parent ) {
			return;
		}
		$sku = (string) $parent->get_sku();
		if ( $sku === '' ) {
			return;
		}
		foreach ( $variants as $v ) {
			if ( $sku === (string) ( $v['sku'] ?? '' ) ) {
				$parent->set_sku( '' );
				$parent->save();
				return;
			}
		}
	}

	/**
	 * @param array<string,mixed> $stack Stack row.
	 */
	private static function upsert_stack( array $stack ): bool {
		$slug = sanitize_title( (string) ( $stack['id'] ?? '' ) );
		if ( $slug === '' ) {
			return false;
		}

		$existing_id = self::find_product_id_by_slug( $slug );
		if ( $existing_id && get_post_meta( $existing_id, '_tpb_seeded', true ) === '1' && get_option( 'tpb_force_reseed_products' ) !== '1' ) {
			update_post_meta( $existing_id, '_tpb_stack_products', $stack['products'] ?? array() );
			update_post_meta( $existing_id, '_tpb_savings', (string) ( $stack['savings'] ?? '' ) );
			update_post_meta( $existing_id, '_tpb_stack_category', (string) ( $stack['category'] ?? '' ) );
			$existing = wc_get_product( $existing_id );
			if ( $existing ) {
				$existing->set_short_description( (string) ( $stack['description'] ?? '' ) );
				$existing->set_description( (string) ( $stack['description'] ?? '' ) );
				$existing->save();
			}
			return true;
		}

		$product = $existing_id ? wc_get_product( $existing_id ) : new WC_Product_Simple();
		if ( ! $product ) {
			$product = new WC_Product_Simple();
		}

		$product->set_name( (string) ( $stack['name'] ?? $slug ) );
		$product->set_slug( $slug );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_description( (string) ( $stack['description'] ?? '' ) );
		$product->set_short_description( (string) ( $stack['description'] ?? '' ) );
		$product->set_regular_price( (string) ( $stack['originalPrice'] ?? $stack['price'] ?? '' ) );
		$product->set_sale_price( (string) ( $stack['price'] ?? '' ) );
		self::set_sku_safe( $product, 'TPB-STACK-' . strtoupper( str_replace( '-', '', $slug ) ) );
		$product->set_stock_status( 'instock' );
		$product->set_category_ids(
			array(
				self::ensure_category( 'Stacks & Bundles' ),
				self::ensure_category( (string) ( $stack['category'] ?? 'Stacks' ) ),
			)
		);

		$product_id = $product->save();
		if ( ! $product_id ) {
			return false;
		}

		update_post_meta( $product_id, '_tpb_seeded', '1' );
		update_post_meta( $product_id, '_tpb_catalog_id', $slug );
		update_post_meta( $product_id, '_tpb_stack_products', $stack['products'] ?? array() );
		update_post_meta( $product_id, '_tpb_savings', (string) ( $stack['savings'] ?? '' ) );
		update_post_meta( $product_id, '_tpb_stack_category', (string) ( $stack['category'] ?? '' ) );

		$image_file = self::variant_image_basename( (string) ( $stack['image'] ?? '' ) );
		if ( $image_file !== '' ) {
			$attach_id = self::sideload_image( self::resolve_catalog_image( 'stacks', $image_file ), $product_id, (string) $stack['name'] );
			if ( $attach_id ) {
				$product->set_image_id( $attach_id );
				$product->save();
			}
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $item Product.
	 */
	private static function build_description( array $item ): string {
		$parts   = array();
		$parts[] = '<p>' . esc_html( (string) ( $item['description'] ?? '' ) ) . '</p>';
		$parts[] = '<ul>';
		$parts[] = '<li><strong>Sequence:</strong> ' . esc_html( (string) ( $item['sequence'] ?? '' ) ) . '</li>';
		$parts[] = '<li><strong>Molecular weight:</strong> ' . esc_html( (string) ( $item['molecularWeight'] ?? '' ) ) . '</li>';
		$parts[] = '<li><strong>Purity:</strong> ' . esc_html( (string) ( $item['purity'] ?? '' ) ) . '</li>';
		$parts[] = '<li><strong>Form:</strong> ' . esc_html( (string) ( $item['form'] ?? '' ) ) . '</li>';
		$parts[] = '<li><strong>Storage:</strong> ' . esc_html( (string) ( $item['storage'] ?? '' ) ) . '</li>';
		$parts[] = '<li><strong>COA:</strong> ' . esc_html( (string) ( $item['coaBatch'] ?? '' ) ) . ' — ' . esc_html( (string) ( $item['coaPurity'] ?? '' ) ) . ' (' . esc_html( (string) ( $item['coaLab'] ?? '' ) ) . ')</li>';
		$parts[] = '</ul>';
		if ( ! empty( $item['researchAreas'] ) && is_array( $item['researchAreas'] ) ) {
			$parts[] = '<p><strong>Research areas:</strong> ' . esc_html( implode( ', ', $item['researchAreas'] ) ) . '</p>';
		}
		$parts[] = '<p><em>For research purposes only. Not for human consumption.</em></p>';
		return implode( "\n", $parts );
	}

	/**
	 * Ignore duplicate-SKU exceptions so a reseed cannot white-screen the shop.
	 */
	private static function set_sku_safe( \WC_Product $product, string $sku ): void {
		$sku = trim( $sku );
		if ( $sku === '' ) {
			return;
		}
		try {
			$product->set_sku( $sku );
		} catch ( \Throwable $e ) {
			// Duplicate SKU on another row — keep the existing value.
		}
	}

	/**
	 * @param array<string,mixed>        $item     Product.
	 * @param array<int,array<string,mixed>> $variants Variants.
	 */
	private static function parent_sku( array $item, array $variants ): string {
		if ( count( $variants ) === 1 && ! empty( $variants[0]['sku'] ) ) {
			return (string) $variants[0]['sku'];
		}
		return 'TPB-' . strtoupper( str_replace( '-', '', (string) ( $item['id'] ?? 'ITEM' ) ) );
	}

	/**
	 * @param array<string,mixed> $item Product.
	 */
	private static function save_research_meta( int $product_id, array $item ): void {
		$keys = array(
			'purity',
			'sequence',
			'molecularWeight',
			'form',
			'storage',
			'coaBatch',
			'coaPurity',
			'coaDate',
			'coaLab',
			'coaStatus',
		);
		foreach ( $keys as $key ) {
			if ( isset( $item[ $key ] ) ) {
				update_post_meta( $product_id, '_tpb_' . $key, $item[ $key ] );
			}
		}
		if ( ! empty( $item['researchAreas'] ) ) {
			update_post_meta( $product_id, '_tpb_researchAreas', $item['researchAreas'] );
		}
		if ( ! empty( $item['bundles'] ) && is_array( $item['bundles'] ) ) {
			update_post_meta( $product_id, '_tpb_bundles', $item['bundles'] );
		} else {
			delete_post_meta( $product_id, '_tpb_bundles' );
		}
	}

	/**
	 * Regular price, optional sale price, optional stock from a catalog variant.
	 *
	 * @param array<string,mixed> $variant Catalog variant.
	 */
	private static function apply_variant_pricing( \WC_Product $product, array $variant ): void {
		$regular = isset( $variant['price'] ) ? (string) $variant['price'] : '';
		$product->set_regular_price( $regular );

		$sale = '';
		if ( isset( $variant['salePrice'] ) && $variant['salePrice'] !== '' && $variant['salePrice'] !== null ) {
			$sale = (string) $variant['salePrice'];
		}
		if ( $sale !== '' && (float) $sale > 0 && (float) $regular > 0 && (float) $sale < (float) $regular ) {
			$product->set_sale_price( $sale );
		} else {
			$product->set_sale_price( '' );
		}

		if ( isset( $variant['stock'] ) && is_numeric( $variant['stock'] ) ) {
			$qty = (float) $variant['stock'];
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $qty );
			$product->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
		}

		if ( ! empty( $variant['batch'] ) ) {
			$product->update_meta_data( '_tpb_coaBatch', (string) $variant['batch'] );
		}
		if ( ! empty( $variant['coaPurity'] ) ) {
			$product->update_meta_data( '_tpb_coaPurity', (string) $variant['coaPurity'] );
		}
		if ( ! empty( $variant['coaDate'] ) ) {
			$product->update_meta_data( '_tpb_coaDate', (string) $variant['coaDate'] );
		}
		if ( ! empty( $variant['coaFill'] ) ) {
			$product->update_meta_data( '_tpb_coaFill', (string) $variant['coaFill'] );
		}
		if ( ! empty( $variant['coaQty'] ) ) {
			$product->update_meta_data( '_tpb_coa_qty', (string) $variant['coaQty'] );
		}
		if ( ! empty( $variant['coaPdf'] ) ) {
			$product->update_meta_data( '_tpb_coaPdf', (string) $variant['coaPdf'] );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $variants Variants.
	 */
	private static function sync_variations( int $product_id, array $variants ): void {
		$attr_name = 'Dosage';
		$options   = array();
		foreach ( $variants as $v ) {
			$options[] = (string) ( $v['dosage'] ?? '' );
		}
		$options = array_values( array_filter( array_unique( $options ) ) );

		$attribute = new WC_Product_Attribute();
		$attribute->set_name( $attr_name );
		$attribute->set_options( $options );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = self::ensure_variable_product( $product_id );
		if ( ! $parent ) {
			return;
		}
		$parent->set_attributes( array( $attribute ) );
		$parent->save();

		// Remove old variations when force reseeding, then reload parent (stale children break SKUs).
		if ( get_option( 'tpb_force_reseed_products' ) === '1' ) {
			foreach ( $parent->get_children() as $child_id ) {
				wp_delete_post( (int) $child_id, true );
			}
			wc_delete_product_transients( $product_id );
			$parent = self::ensure_variable_product( $product_id );
			if ( ! $parent ) {
				return;
			}
		}

		$existing_by_sku    = array();
		$existing_by_dosage = array();
		foreach ( $parent->get_children() as $child_id ) {
			$child = wc_get_product( (int) $child_id );
			if ( ! $child ) {
				continue;
			}
			$child_sku = (string) $child->get_sku();
			if ( $child_sku !== '' ) {
				$existing_by_sku[ $child_sku ] = $child;
			}
			$attrs         = $child->get_attributes();
			$child_dosage  = (string) ( $attrs['dosage'] ?? $attrs['Dosage'] ?? '' );
			if ( $child_dosage !== '' ) {
				$existing_by_dosage[ $child_dosage ] = $child;
			}
		}

		foreach ( $variants as $v ) {
			$sku    = (string) ( $v['sku'] ?? '' );
			$dosage = (string) ( $v['dosage'] ?? '' );
			if ( $sku === '' || $dosage === '' ) {
				continue;
			}

			$variation = $existing_by_sku[ $sku ] ?? $existing_by_dosage[ $dosage ] ?? null;
			if ( ! $variation instanceof WC_Product_Variation ) {
				$owner_id = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $sku ) : 0;
				if ( $owner_id > 0 ) {
					$owner = wc_get_product( $owner_id );
					if ( $owner instanceof WC_Product_Variation && (int) $owner->get_parent_id() === $product_id ) {
						$variation = $owner;
					} elseif ( $owner instanceof WC_Product_Variation ) {
						$op = (int) $owner->get_parent_id();
						if ( $op <= 0 || ! get_post( $op ) ) {
							wp_delete_post( $owner_id, true );
						}
					}
				}
			}
			if ( ! $variation instanceof WC_Product_Variation ) {
				$variation = new WC_Product_Variation();
			}

			$variation->set_parent_id( $product_id );
			self::set_sku_safe( $variation, $sku );
			self::apply_variant_pricing( $variation, $v );
			$variation->set_attributes( array( 'dosage' => $dosage ) );

			$image_file = self::variant_image_basename( (string) ( $v['image'] ?? '' ) );
			if ( $image_file !== '' ) {
				$attach_id = self::sideload_image(
					self::resolve_catalog_image( 'products', $image_file ),
					$product_id,
					trim( (string) ( get_the_title( $product_id ) ) . ' ' . $dosage )
				);
				if ( $attach_id > 0 ) {
					$variation->set_image_id( $attach_id );
				}
			}

			$variation->save();
		}

		// Parent featured image = first variant image (dosage-specific).
		$first_image = self::variant_image_basename( (string) ( $variants[0]['image'] ?? '' ) );
		if ( $first_image !== '' ) {
			$attach_id = self::sideload_image(
				self::resolve_catalog_image( 'products', $first_image ),
				$product_id,
				(string) get_the_title( $product_id )
			);
			if ( $attach_id > 0 ) {
				$parent = wc_get_product( $product_id );
				if ( $parent ) {
					$parent->set_image_id( $attach_id );
					$parent->save();
				}
			}
		}

		WC_Product_Variable::sync( $product_id );
	}

	private static function ensure_category( string $name ): int {
		$name = trim( $name );
		if ( $name === '' ) {
			$name = 'Peptides';
		}
		$term = term_exists( $name, 'product_cat' );
		if ( is_array( $term ) && isset( $term['term_id'] ) ) {
			return (int) $term['term_id'];
		}
		$created = wp_insert_term( $name, 'product_cat' );
		if ( is_wp_error( $created ) ) {
			return 0;
		}
		return (int) $created['term_id'];
	}

	private static function find_product_id_by_slug( string $slug ): int {
		$by_meta = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_tpb_catalog_id',
				'meta_value'     => $slug,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		if ( ! empty( $by_meta[0] ) ) {
			return (int) $by_meta[0];
		}

		$posts = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		return ! empty( $posts[0] ) ? (int) $posts[0] : 0;
	}

	private static function variant_image_basename( string $path ): string {
		$path = trim( $path );
		if ( $path === '' ) {
			return '';
		}
		return basename( $path );
	}

	/**
	 * Prefer .webp sibling under assets/{products|stacks}/ when available.
	 */
	private static function resolve_catalog_image( string $folder, string $basename ): string {
		$dir  = TPB_CORE_DIR . 'assets/' . $folder . '/';
		$stem = (string) preg_replace( '/\.(png|jpe?g|webp)$/i', '', $basename );
		foreach ( array( $stem . '.webp', $basename, $stem . '.jpg', $stem . '.png' ) as $candidate ) {
			$full = $dir . $candidate;
			if ( is_readable( $full ) ) {
				return $full;
			}
		}
		return $dir . $basename;
	}

	private static function sideload_image( string $file, int $parent_id, string $title, bool $replace = false ): int {
		if ( ! is_readable( $file ) ) {
			return 0;
		}

		$basename = basename( $file );
		$stem     = (string) preg_replace( '/\.(png|jpe?g|webp)$/i', '', $basename );

		if ( $replace ) {
			self::delete_catalog_attachments( $stem, $parent_id );
		} else {
			$existing = self::find_catalog_attachment( $stem );
			if ( $existing > 0 ) {
				return $existing;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( $basename );
		if ( ! $tmp || ! copy( $file, $tmp ) ) {
			return 0;
		}

		$file_array = array(
			'name'     => $basename,
			'tmp_name' => $tmp,
		);

		$attach_id = media_handle_sideload( $file_array, $parent_id, $title );
		if ( is_wp_error( $attach_id ) ) {
			@unlink( $tmp );
			return 0;
		}

		update_post_meta( (int) $attach_id, '_tpb_source_file', $basename );
		return (int) $attach_id;
	}

	/**
	 * Attachment ID previously sideloaded from this catalog stem.
	 */
	private static function find_catalog_attachment( string $stem ): int {
		$names = array( $stem . '.webp', $stem . '.png', $stem . '.jpg', $stem . '.jpeg', $stem );
		foreach ( $names as $name ) {
			$found = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_tpb_source_file',
					'meta_value'     => $name,
				)
			);
			if ( ! empty( $found[0] ) ) {
				return (int) $found[0];
			}
		}
		return 0;
	}

	/**
	 * Remove old PNG/WebP thumbs (and generated sizes) for a catalog image stem.
	 */
	private static function delete_catalog_attachments( string $stem, int $parent_id ): void {
		$ids = array();

		$thumb = (int) get_post_thumbnail_id( $parent_id );
		if ( $thumb > 0 ) {
			$ids[] = $thumb;
		}

		$found = self::find_catalog_attachment( $stem );
		if ( $found > 0 ) {
			$ids[] = $found;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_wp_attached_file',
						'value'   => $stem,
						'compare' => 'LIKE',
					),
				),
			)
		);
		if ( is_array( $query->posts ) ) {
			foreach ( $query->posts as $id ) {
				$ids[] = (int) $id;
			}
		}

		foreach ( array_unique( array_filter( $ids ) ) as $id ) {
			wp_delete_attachment( (int) $id, true );
		}
	}
}
