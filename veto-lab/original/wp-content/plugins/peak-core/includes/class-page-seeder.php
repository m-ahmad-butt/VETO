<?php
/**
 * Seed all Peak pages as Elementor documents with nested containers.
 *
 * @package Peak_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TPB_Page_Seeder {

	/**
	 * Create / update pages and return map slug => ID.
	 *
	 * @return array<string,int>
	 */
	public static function seed(): array {
		$pages = array(
			'home'               => array( 'title' => 'Home', 'builder' => 'home' ),
			'products'           => array( 'title' => 'Products', 'builder' => 'shop' ),
			'stacks'             => array( 'title' => 'Stacks & Bundles', 'builder' => 'stacks' ),
			'coa'                => array( 'title' => 'COA Library', 'builder' => 'coa' ),
			'about'              => array( 'title' => 'About', 'builder' => 'about' ),
			'contact'            => array( 'title' => 'Contact', 'builder' => 'contact' ),
			'storage-handling'   => array( 'title' => 'Storage & Handling', 'builder' => 'storage' ),
			'faq'                => array( 'title' => 'FAQ', 'builder' => 'faq' ),
			'partner'            => array( 'title' => 'Partner With Us', 'builder' => 'partner' ),
		);

		$ids = array();
		foreach ( $pages as $slug => $meta ) {
			if ( $meta['builder'] === 'shop' ) {
				$ids[ $slug ] = self::ensure_shop_page( $slug, $meta['title'] );
				continue;
			}
			$tree = match ( $meta['builder'] ) {
				'home'    => self::home_tree(),
				'about'   => self::about_tree(),
				'contact' => self::contact_tree(),
				'storage' => self::storage_tree(),
				'stacks'  => self::stacks_tree(),
				'coa'     => self::coa_tree(),
				'faq'     => self::faq_tree(),
				'partner' => self::partner_tree(),
				default   => self::simple_tree( $meta['title'] ),
			};
			$ids[ $slug ] = self::upsert_elementor_page( $slug, $meta['title'], $tree, $meta['builder'] );
		}

		// Legal / policy pages are theme-rendered (not Elementor placeholders).
		if ( function_exists( 'peak_ensure_legal_pages' ) ) {
			$ids = array_merge( $ids, peak_ensure_legal_pages() );
		} else {
			$legal = array(
				'terms'           => 'Terms & Conditions',
				'privacy-policy'  => 'Privacy Policy',
				'refund-policy'   => 'Refund & Return Policy',
				'shipping'        => 'Shipping Policy',
				'research-policy' => 'Research Use Disclaimer',
			);
			foreach ( $legal as $slug => $title ) {
				$ids[ $slug ] = self::ensure_plain_page( $slug, $title );
			}
		}

		if ( ! empty( $ids['home'] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', (string) $ids['home'] );
		}

		// Blog posts page (not Elementor — uses home.php).
		$blog = get_page_by_path( 'blog' );
		if ( ! ( $blog instanceof WP_Post ) ) {
			$blog_id = (int) wp_insert_post(
				array(
					'post_title'  => 'Blog',
					'post_name'   => 'blog',
					'post_status' => 'publish',
					'post_type'   => 'page',
					'post_content'=> '',
				),
				true
			);
		} else {
			$blog_id = (int) $blog->ID;
		}
		if ( $blog_id > 0 ) {
			$ids['blog'] = $blog_id;
			update_option( 'page_for_posts', $blog_id );
		}

		self::ensure_menus( $ids );

		return $ids;
	}

	/**
	 * @param array<string,int> $ids Page ids.
	 */
	private static function ensure_menus( array $ids ): void {
		$menu_name = 'Peak Primary';
		$menu      = wp_get_nav_menu_object( $menu_name );
		if ( ! $menu ) {
			$menu_id = wp_create_nav_menu( $menu_name );
		} else {
			$menu_id = (int) $menu->term_id;
		}

		$order = array( 'home', 'products', 'stacks', 'coa', 'about', 'contact', 'faq', 'blog' );
		$existing = wp_get_nav_menu_items( $menu_id );
		if ( empty( $existing ) ) {
			$position = 1;
			foreach ( $order as $slug ) {
				if ( empty( $ids[ $slug ] ) ) {
					continue;
				}
				wp_update_nav_menu_item(
					$menu_id,
					0,
					array(
						'menu-item-title'     => get_the_title( $ids[ $slug ] ),
						'menu-item-object'    => 'page',
						'menu-item-object-id' => $ids[ $slug ],
						'menu-item-type'      => 'post_type',
						'menu-item-status'    => 'publish',
						'menu-item-position'  => $position++,
					)
				);
			}
		}

		$locations = get_theme_mod( 'nav_menu_locations', array() );
		if ( ! is_array( $locations ) ) {
			$locations = array();
		}
		$locations['primary'] = $menu_id;
		$locations['footer']  = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );
	}

	/**
	 * Layout revision per builder — bump to refresh that page without wiping others.
	 *
	 * @return array<string,string>
	 */
	private static function layout_revisions(): array {
		return array(
			'home'    => '8',
			'about'   => '3',
			'contact' => '4',
			'storage' => '2',
			'stacks'  => '3',
			'coa'     => '2',
			'faq'     => '1',
			'partner' => '1',
		);
	}

	/**
	 * Plain WP page (theme-rendered legal / policy content).
	 */
	private static function ensure_plain_page( string $slug, string $title ): int {
		$existing = get_page_by_path( $slug );
		if ( $existing instanceof WP_Post ) {
			$page_id = (int) $existing->ID;
			wp_update_post(
				array(
					'ID'           => $page_id,
					'post_title'   => $title,
					'post_status'  => 'publish',
					'post_name'    => $slug,
					'post_content' => '',
				)
			);
		} else {
			$page_id = (int) wp_insert_post(
				array(
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => '',
				),
				true
			);
			if ( $page_id <= 0 ) {
				return 0;
			}
		}

		delete_post_meta( $page_id, '_elementor_edit_mode' );
		delete_post_meta( $page_id, '_elementor_data' );
		delete_post_meta( $page_id, '_elementor_template_type' );
		delete_post_meta( $page_id, '_elementor_version' );
		delete_post_meta( $page_id, '_elementor_page_settings' );
		update_post_meta( $page_id, '_wp_page_template', 'page-legal.php' );
		update_post_meta( $page_id, '_tpb_legal', '1' );
		update_post_meta( $page_id, '_tpb_seeded', '1' );

		return $page_id;
	}

	/**
	 * WooCommerce shop page — plain WP page so archive-product.php can render.
	 */
	private static function ensure_shop_page( string $slug, string $title ): int {
		$existing = get_page_by_path( $slug );
		if ( $existing instanceof WP_Post ) {
			$page_id = (int) $existing->ID;
		} else {
			$page_id = (int) wp_insert_post(
				array(
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => '',
				),
				true
			);
			if ( $page_id <= 0 ) {
				return 0;
			}
		}

		update_option( 'woocommerce_shop_page_id', (string) $page_id );

		return $page_id;
	}

	/**
	 * @param array<int,array<string,mixed>> $tree Elementor elements.
	 */
	private static function upsert_elementor_page( string $slug, string $title, array $tree, string $builder = 'simple' ): int {
		$existing = get_page_by_path( $slug );
		if ( $existing instanceof WP_Post ) {
			$page_id = (int) $existing->ID;
			$revs    = self::layout_revisions();
			$wanted  = $revs[ $builder ] ?? '';
			$current = (string) get_post_meta( $page_id, '_tpb_layout_rev', true );
			$stale   = ( $wanted !== '' && $current !== $wanted );

			// Do not overwrite if already built in Elementor (preserves editor work).
			if ( get_post_meta( $page_id, '_elementor_edit_mode', true ) === 'builder'
				&& get_post_meta( $page_id, '_tpb_seeded', true ) === '1'
				&& get_option( 'tpb_force_reseed' ) !== '1'
				&& ! $stale ) {
				return $page_id;
			}
		} else {
			$page_id = (int) wp_insert_post(
				array(
					'post_title'  => $title,
					'post_name'   => $slug,
					'post_status' => 'publish',
					'post_type'   => 'page',
					'post_content'=> '',
				),
				true
			);
			if ( $page_id <= 0 ) {
				return 0;
			}
		}

		$json = wp_json_encode( $tree );
		if ( ! is_string( $json ) ) {
			return $page_id;
		}

		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $page_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $page_id, '_elementor_page_settings', array() );
		update_post_meta( $page_id, '_wp_page_template', 'default' );
		update_post_meta( $page_id, '_tpb_seeded', '1' );

		$revs = self::layout_revisions();
		if ( isset( $revs[ $builder ] ) ) {
			update_post_meta( $page_id, '_tpb_layout_rev', $revs[ $builder ] );
		}

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		return $page_id;
	}

	/**
	 * Home page tree — each section is its own root container with nested children.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function home_tree(): array {
		$n         = TPB_Elementor_Nodes::class;
		$mountain  = self::brand_url( 'hero-mountain-bg_ff05f99c' );
		$stack_img = self::brand_url( 'hero-stack-olive_6cb0d770' );

				$hero_copy = $n::container(
			array(
				'content_width'    => 'full',
				'flex_direction'   => 'column',
				'flex_align_items' => 'flex-start',
				'flex_gap'         => array( 'unit' => 'px', 'size' => 0, 'sizes' => array() ),
				'width'            => array( 'unit' => '%', 'size' => 58 ),
				'css_classes'      => 'tpb-hero__copy',
			),
			array(
				$n::text(
					'<div class="tpb-hero__badge"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10 2v7.527a2 2 0 0 1-.211.896L4.72 20.55a1 1 0 0 0 .9 1.45h12.76a1 1 0 0 0 .9-1.45l-5.069-10.127A2 2 0 0 1 14 9.527V2"/><path d="M8.5 2h7"/><path d="M7 16h10"/></svg><span>Premium Research Peptides</span></div>',
					array( 'align' => 'left' )
				),
				$n::text(
					'<h1 class="tpb-hero__title">'
					. '<span class="tpb-hero__title-line tpb-hero__title-line--desk">Research Peptides</span>'
					. '<span class="tpb-hero__title-line tpb-hero__title-line--desk">USA <span class="tpb-hero__accent">>98% Verified</span></span>'
					. '<span class="tpb-hero__title-line tpb-hero__title-line--mob">Research</span>'
					. '<span class="tpb-hero__title-line tpb-hero__title-line--mob">Peptides USA</span>'
					. '<span class="tpb-hero__title-line tpb-hero__title-line--mob"><span class="tpb-hero__accent">>98% Verified</span></span>'
					. '<span class="tpb-hero__title-line tpb-hero__accent">Purity.</span>'
					. '<span class="tpb-hero__title-line">Every Batch.</span>'
					. '</h1>',
					array( 'align' => 'left' )
				),
				$n::text(
					'<p class="tpb-hero__lead">Peak supplies third-party HPLC-verified research peptides to qualified laboratories, academic researchers, and institutional buyers. Every lot ships with a Certificate of Analysis.</p>',
					array( 'align' => 'left' )
				),
				$n::container(
					array(
						'content_width'  => 'full',
						'flex_direction' => 'row',
						'flex_wrap'      => 'wrap',
						'flex_gap'       => array( 'unit' => 'px', 'size' => 16, 'sizes' => array() ),
						'css_classes'    => 'tpb-hero__ctas',
					),
					array(
						$n::button(
							'Shop Peptides →',
							home_url( '/products/' ),
							array(
								'typography_typography'  => 'custom',
								'typography_font_weight' => '600',
								'typography_font_size'   => array( 'unit' => 'px', 'size' => 16 ),
								'button_padding'         => array(
									'unit' => 'px', 'top' => '16', 'right' => '32', 'bottom' => '16', 'left' => '32', 'isLinked' => false,
								),
							)
						),
						$n::button(
							'View COA Library',
							home_url( '/coa/' ),
							array(
								'background_color'       => 'rgba(255,255,255,0.55)',
								'button_text_color'      => '#334155',
								'border_border'          => 'solid',
								'border_width'           => array(
									'unit' => 'px', 'top' => '2', 'right' => '2', 'bottom' => '2', 'left' => '2', 'isLinked' => true,
								),
								'border_color'           => '#E2E8F0',
								'typography_typography'  => 'custom',
								'typography_font_weight' => '600',
								'typography_font_size'   => array( 'unit' => 'px', 'size' => 16 ),
								'button_padding'         => array(
									'unit' => 'px', 'top' => '16', 'right' => '32', 'bottom' => '16', 'left' => '32', 'isLinked' => false,
								),
							)
						),
					),
					true
				),
				$n::text(
					'<div class="tpb-hero__google"><span class="tpb-hero__google-label">Google</span><span class="tpb-hero__stars">★★★★★</span><span class="tpb-hero__google-score">5.0</span><a href="#">Rate us ↗</a></div>'
				),
			),
			true
		);

		$hero_offer = $n::container(
			array(
				'content_width'    => 'full',
				'width'            => array( 'unit' => '%', 'size' => 42 ),
				'flex_direction'   => 'column',
				'flex_align_items' => 'center',
				'flex_gap'         => array( 'unit' => 'px', 'size' => 24, 'sizes' => array() ),
				'css_classes'      => 'tpb-hero__media',
			),
			array(
				$n::image(
					$stack_img,
					'PEAK Research Peptides',
					array(
						'border_radius' => array(
							'unit' => 'px', 'top' => '16', 'right' => '16', 'bottom' => '16', 'left' => '16', 'isLinked' => true,
						),
						'css_classes'   => 'tpb-hero__stack-img',
					)
				),
				$n::container(
					array(
						'content_width'         => 'full',
						'background_background' => 'classic',
						'background_color'      => 'rgba(255,255,255,0.92)',
						'border_radius'         => array(
							'unit' => 'px', 'top' => '16', 'right' => '16', 'bottom' => '16', 'left' => '16', 'isLinked' => true,
						),
						'padding'               => array(
							'unit' => 'px', 'top' => '24', 'right' => '24', 'bottom' => '24', 'left' => '24', 'isLinked' => true,
						),
						'border_border'         => 'solid',
						'border_width'          => array(
							'unit' => 'px', 'top' => '1', 'right' => '1', 'bottom' => '1', 'left' => '1', 'isLinked' => true,
						),
						'border_color'          => 'rgba(95,92,55,0.12)',
						'css_classes'           => 'tpb-hero__offer',
					),
					array(
						$n::text(
							'<div class="tpb-hero__offer-top"><span class="tpb-hero__offer-pill">⚡ LIMITED-TIME OFFER</span><span class="tpb-hero__offer-pct">15% OFF</span></div><button type="button" class="tpb-hero__offer-code" data-tpb-offer-code="DEMO15" aria-label="Copy discount code DEMO15"><span class="tpb-hero__offer-code-main"><span class="tpb-hero__offer-label">Discount code</span><span class="tpb-hero__offer-value">DEMO15</span></span><span class="tpb-hero__offer-copy"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg><span class="tpb-hero__offer-copy-text">Copy</span></span></button>'
						),
					),
					true
				),
			),
			true
		);

		$hero = $n::container(
			array(
				'content_width'          => 'full',
				'min_height'             => array( 'unit' => 'vh', 'size' => 85 ),
				'flex_direction'         => 'row',
				'flex_align_items'       => 'center',
				'flex_justify_content'   => 'center',
				'flex_wrap'              => 'wrap',
				'background_background'  => 'classic',
				'background_image'       => array(
					'url' => $mountain,
					'id'  => '',
				),
				'background_position'    => 'center center',
				'background_repeat'      => 'no-repeat',
				'background_size'        => 'cover',
				'padding'                => array(
					'unit' => 'px', 'top' => '48', 'right' => '0', 'bottom' => '48', 'left' => '0', 'isLinked' => false,
				),
				'css_classes'            => 'tpb-hero',
			),
			array(
				$n::container(
					array(
						'content_width'        => 'full',
						'flex_direction'       => 'row',
						'flex_align_items'     => 'center',
						'flex_justify_content' => 'space-between',
						'flex_wrap'            => 'wrap',
						'flex_gap'             => array( 'unit' => 'px', 'size' => 48, 'sizes' => array() ),
						'css_classes'          => 'tpb-hero__inner',
					),
					array( $hero_copy, $hero_offer ),
					true
				),
			)
		);

		$url_products = esc_url( home_url( '/products/' ) );
		$url_coa      = esc_url( home_url( '/coa/' ) );
		$url_faq      = esc_url( home_url( '/faq/' ) );

		$guarantee = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#5F5C37',
				'css_classes'           => 'tpb-guarantee',
			),
			array(
				$n::text(
					'<div class="tpb-guarantee__inner"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg><p>Buy with confidence — Money-back guarantee</p></div>'
				),
			)
		);

		$trust = $n::container(
			array(
				'content_width' => 'full',
				'css_classes'   => 'tpb-trust',
			),
			array(
				$n::text(
					'<div class="tpb-wrap"><div class="tpb-trust__grid">'
					. '<div class="tpb-trust__item"><span class="tpb-trust__icon" aria-hidden="true"></span><div class="tpb-trust__text"><p class="tpb-trust__label">>98% Purity</p><p class="tpb-trust__sub">HPLC Verified</p></div></div>'
					. '<div class="tpb-trust__item"><span class="tpb-trust__icon" aria-hidden="true"></span><div class="tpb-trust__text"><p class="tpb-trust__label">Third-Party Tested</p><p class="tpb-trust__sub">Independent Labs</p></div></div>'
					. '<div class="tpb-trust__item"><span class="tpb-trust__icon" aria-hidden="true"></span><div class="tpb-trust__text"><p class="tpb-trust__label">Same-Day Shipping</p><p class="tpb-trust__sub">Orders before 2PM EST</p></div></div>'
					. '<div class="tpb-trust__item"><span class="tpb-trust__icon" aria-hidden="true"></span><div class="tpb-trust__text"><p class="tpb-trust__label">COA Included</p><p class="tpb-trust__sub">Every Order</p></div></div>'
					. '</div></div>'
				),
			)
		);

		$index = $n::container(
			array(
				'content_width' => 'full',
				'css_classes'   => 'tpb-index',
			),
			array(
				$n::text(
					'<div class="tpb-wrap"><div class="tpb-index__grid">'
					. '<div class="tpb-index__copy">'
					. '<span class="tpb-eyebrow">New Resource</span>'
					. '<h2 class="tpb-h2">Peptide Research Index</h2>'
					. '<p class="tpb-lead">Explore our comprehensive database of research peptides. Each entry includes molecular data, research applications, mechanism of action, and cited studies from PubMed.</p>'
					. '<div class="tpb-index__tags">'
					. '<div class="tpb-index__tag">37+ Peptide Entries</div>'
					. '<div class="tpb-index__tag">PubMed Citations</div>'
					. '<div class="tpb-index__tag">Molecular Data</div>'
					. '<div class="tpb-index__tag">Research Categories</div>'
					. '</div>'
					. '<a class="tpb-btn" href="' . $url_products . '">Explore Peptide Index →</a>'
					. '</div>'
					. '<div class="tpb-index__cards">'
					. '<a class="tpb-research-card" href="' . $url_products . '"><span class="tpb-research-card__cat">Cytoprotection Research</span><span class="tpb-research-card__name">BPC-157</span><span class="tpb-research-card__link">View research →</span></a>'
					. '<a class="tpb-research-card" href="' . $url_products . '"><span class="tpb-research-card__cat">Metabolic Research</span><span class="tpb-research-card__name">GLP-1 Agonists</span><span class="tpb-research-card__link">View research →</span></a>'
					. '<a class="tpb-research-card" href="' . $url_products . '"><span class="tpb-research-card__cat">Actin-Pathway Research</span><span class="tpb-research-card__name">TB-500</span><span class="tpb-research-card__link">View research →</span></a>'
					. '<a class="tpb-research-card" href="' . $url_products . '"><span class="tpb-research-card__cat">Copper-Peptide Research</span><span class="tpb-research-card__name">GHK-Cu</span><span class="tpb-research-card__link">View research →</span></a>'
					. '</div></div></div>'
				),
			)
		);

		$how = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#F8FAFC',
				'css_classes'           => 'tpb-how',
			),
			array(
				$n::text(
					'<div class="tpb-wrap tpb-how__inner">'
					. '<h2 class="tpb-h2 tpb-h2--center">How it works</h2>'
					. '<div class="tpb-how__grid">'
					. '<div class="tpb-how__step"><div class="tpb-how__num">01</div><h3 class="tpb-h3">Precision Synthesis</h3><p class="tpb-body">Made in controlled facilities under strict manufacturing standards.</p></div>'
					. '<div class="tpb-how__step"><div class="tpb-how__num">02</div><h3 class="tpb-h3">Verified Purity</h3><p class="tpb-body">Every batch is third-party tested with HPLC and MS to confirm chemical integrity.</p></div>'
					. '<div class="tpb-how__step"><div class="tpb-how__num">03</div><h3 class="tpb-h3">Same-Day Fulfillment</h3><p class="tpb-body">Products are dispatched same-day from our U.S. facility.</p></div>'
					. '</div></div>'
				),
			)
		);

		$what = $n::container(
			array(
				'content_width' => 'full',
				'css_classes'   => 'tpb-what',
			),
			array(
				$n::text(
					'<div class="tpb-wrap">'
					. '<div class="tpb-sec-head"><h2 class="tpb-h2 tpb-h2--center">What We Are</h2>'
					. '<p class="tpb-lead tpb-lead--center">Peak is a research peptide supplier based in the United States.</p></div>'
					. '<div class="tpb-card-grid tpb-card-grid--3">'
					. '<div class="tpb-info-card"><h3 class="tpb-h3">USA-Based Company</h3><p class="tpb-body">We are a United States company. All orders ship domestically within the USA. We do not ship internationally.</p></div>'
					. '<div class="tpb-info-card"><h3 class="tpb-h3">Research Chemical Supplier</h3><p class="tpb-body">We are a peptide supplier selling research chemicals. We are not a pharmacy, medical provider, or supplement company. Our products are chemicals for laboratory use.</p></div>'
					. '<div class="tpb-info-card"><h3 class="tpb-h3">Quality-Focused</h3><p class="tpb-body">All peptides are >98% purity verified. We use third-party HPLC and Mass Spectrometry testing. Every order includes a Certificate of Analysis (COA).</p></div>'
					. '</div></div>'
				),
			)
		);

		$featured = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#F5F4EE',
				'css_classes'           => 'tpb-featured',
			),
			array(
				$n::text(
					'<div class="tpb-wrap"><div class="tpb-featured__head">'
					. '<div><p class="tpb-eyebrow">Catalog / Featured</p><h2 class="tpb-h2">Featured Compounds</h2></div>'
					. '<a class="tpb-text-link tpb-featured__all" href="' . $url_products . '">View all products →</a>'
					. '</div></div>'
				),
				$n::shortcode( '[products limit="8" columns="4" orderby="id" order="ASC"]' ),
			)
		);

		$categories = $n::container(
			array(
				'content_width' => 'full',
				'css_classes'   => 'tpb-cats',
			),
			array(
				$n::text(
					'<div class="tpb-wrap">'
					. '<div class="tpb-sec-head"><h2 class="tpb-h2 tpb-h2--center">Product Categories</h2>'
					. '<p class="tpb-lead tpb-lead--center">Peak sells research peptides organized into these categories. All products are research chemicals.</p></div>'
					. '<div class="tpb-card-grid tpb-card-grid--3">'
					. '<div class="tpb-info-card"><h3 class="tpb-h3">Research Peptides</h3><p class="tpb-body">Synthetic peptides for in-vitro research and laboratory studies.</p><p class="tpb-meta">Includes: BPC-157, TB-500, GHK-Cu, PT-141, Epithalon, MOTS-c, SS-31, Thymosin Alpha-1</p></div>'
					. '<div class="tpb-info-card"><h3 class="tpb-h3">GHRH &amp; Ghrelin-Receptor Compounds</h3><p class="tpb-body">GHRH analogs and GHS-R1a agonist peptides for research applications.</p><p class="tpb-meta">Includes: IGF-1 LR3, CJC-1295, Ipamorelin, Sermorelin, Tesamorelin</p></div>'
					. '<div class="tpb-info-card"><h3 class="tpb-h3">Specialty Research Compounds</h3><p class="tpb-body">Small molecules and specialty compounds for advanced research.</p><p class="tpb-meta">Includes: NAD+, 5-Amino-1MQ, tp-r3ta, tp-t1rz, Selank, Semax</p></div>'
					. '</div></div>'
				),
			)
		);

		$quality = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#F8FAFC',
				'css_classes'           => 'tpb-quality',
			),
			array(
				$n::text(
					'<div class="tpb-wrap">'
					. '<div class="tpb-sec-head"><h2 class="tpb-h2 tpb-h2--center">Quality &amp; Verification</h2>'
					. '<p class="tpb-lead tpb-lead--center">How Peak verifies product quality and purity.</p></div>'
					. '<div class="tpb-card-grid tpb-card-grid--4 tpb-quality__grid">'
					. '<div class="tpb-stat-card"><p class="tpb-stat-card__value">>98%</p><p class="tpb-stat-card__label">Purity</p><p class="tpb-body">All peptides meet or exceed >98% purity standards.</p></div>'
					. '<div class="tpb-stat-card"><p class="tpb-stat-card__value">HPLC</p><p class="tpb-stat-card__label">Testing</p><p class="tpb-body">High-Performance Liquid Chromatography for purity verification.</p></div>'
					. '<div class="tpb-stat-card"><p class="tpb-stat-card__value">MS</p><p class="tpb-stat-card__label">Mass Spec</p><p class="tpb-body">MS testing confirms molecular identity of each peptide.</p></div>'
					. '<div class="tpb-stat-card"><p class="tpb-stat-card__value">COA</p><p class="tpb-stat-card__label">Included</p><p class="tpb-body">Every order includes a Certificate of Analysis from third-party labs.</p></div>'
					. '</div>'
					. '<div class="tpb-center"><a class="tpb-btn" href="' . $url_coa . '">View Our COA Library →</a></div>'
					. '</div>'
				),
			)
		);

		$coa = $n::container(
			array(
				'content_width' => 'full',
				'css_classes'   => 'tpb-coa',
			),
			array(
				$n::text(
					'<div class="tpb-wrap">'
					. '<div class="tpb-sec-head"><p class="tpb-eyebrow tpb-eyebrow--center">Quality Assurance</p>'
					. '<h2 class="tpb-h2 tpb-h2--center">Certificate of Analysis</h2>'
					. '<p class="tpb-lead tpb-lead--center">Every batch is independently tested by Krause Analytical. View real-time COA data for any product.</p></div>'
					. '<div class="tpb-coa__grid">'
					. '<div class="tpb-coa-card"><span class="tpb-coa-card__check"></span><div><p class="tpb-coa-card__name">tp-r3ta</p><p class="tpb-meta">15mg · Purity: 99.9%</p></div></div>'
					. '<div class="tpb-coa-card"><span class="tpb-coa-card__check"></span><div><p class="tpb-coa-card__name">tp-t1rz</p><p class="tpb-meta">10mg · Purity: 99.9%</p></div></div>'
					. '<div class="tpb-coa-card"><span class="tpb-coa-card__check"></span><div><p class="tpb-coa-card__name">MOTS-C</p><p class="tpb-meta">20mg · Purity: 99.24%</p></div></div>'
					. '<div class="tpb-coa-card"><span class="tpb-coa-card__check"></span><div><p class="tpb-coa-card__name">Tesamorelin</p><p class="tpb-meta">10mg · Purity: 99.21%</p></div></div>'
					. '<div class="tpb-coa-card"><span class="tpb-coa-card__check"></span><div><p class="tpb-coa-card__name">Ipamorelin</p><p class="tpb-meta">10mg · Purity: 99.31%</p></div></div>'
					. '<div class="tpb-coa-card"><span class="tpb-coa-card__check"></span><div><p class="tpb-coa-card__name">5-Amino-1MQ</p><p class="tpb-meta">50mg · Purity: 99.05%</p></div></div>'
					. '</div>'
					. '<div class="tpb-center"><a class="tpb-text-link" href="' . $url_coa . '">View Full COA Library →</a></div>'
					. '</div>'
				),
			)
		);

		$shipping = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#F8FAFC',
				'css_classes'           => 'tpb-ship',
			),
			array(
				$n::text(
					'<div class="tpb-wrap">'
					. '<div class="tpb-sec-head"><h2 class="tpb-h2 tpb-h2--center">Shipping &amp; Operations</h2></div>'
					. '<div class="tpb-card-grid tpb-card-grid--3">'
					. '<div class="tpb-info-card tpb-info-card--center"><h3 class="tpb-h3">USA Only</h3><p class="tpb-body">We ship exclusively within the United States. International shipping is not available.</p></div>'
					. '<div class="tpb-info-card tpb-info-card--center"><h3 class="tpb-h3">Ships from USA</h3><p class="tpb-body">All orders ship from our facility in the United States.</p></div>'
					. '<div class="tpb-info-card tpb-info-card--center"><h3 class="tpb-h3">Same-Day Shipping</h3><p class="tpb-body">Orders placed before 2 PM EST ship the same business day.</p></div>'
					. '</div></div>'
				),
			)
		);

		$newsletter = $n::container(
			array(
				'content_width' => 'full',
				'css_classes'   => 'tpb-news',
			),
			array(
				$n::text(
					'<div class="tpb-wrap tpb-news__inner">'
					. '<h2 class="tpb-h2 tpb-h2--center">Quarterly Literature Digest</h2>'
					. '<p class="tpb-lead tpb-lead--center">Stay current with new research peptide literature and lot releases. A short quarterly summary for qualified researchers — new literature, recent lot COAs, and product availability.</p>'
					. '<ul class="tpb-news__list">'
					. '<li><span class="tpb-news__tick"></span><span>New literature and emerging research</span></li>'
					. '<li><span class="tpb-news__tick"></span><span>Third-party COAs the moment each new lot clears testing</span></li>'
					. '<li><span class="tpb-news__tick"></span><span>Restock notifications and lot-availability updates</span></li>'
					. '</ul></div>'
				),
			)
		);

		$faq = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#F8FAFC',
				'css_classes'           => 'tpb-faq',
			),
			array(
				$n::text(
					'<div class="tpb-wrap">'
					. '<div class="tpb-sec-head"><h2 class="tpb-h2 tpb-h2--center">Frequently Asked Questions</h2></div>'
					. '<div class="tpb-faq__list">'
					. '<details class="tpb-faq__item"><summary>What is the purity of your peptides?</summary><p class="tpb-body">All our peptides are manufactured to a minimum purity of >98%, verified through third-party HPLC and MS testing. Each product comes with a Certificate of Analysis (COA) documenting the exact purity levels.</p></details>'
					. '<details class="tpb-faq__item"><summary>Are your products for human consumption?</summary><p class="tpb-body">No. All products sold by Peak are intended for laboratory and research purposes only. Not for human consumption, cosmetic, or therapeutic use.</p></details>'
					. '<details class="tpb-faq__item"><summary>How do you ship orders?</summary><p class="tpb-body">All orders ship from our U.S. facility. Orders placed before 2 PM EST ship the same business day. We ship exclusively within the United States.</p></details>'
					. '<details class="tpb-faq__item"><summary>Do you provide Certificates of Analysis (COA)?</summary><p class="tpb-body">Yes. Every order includes a Certificate of Analysis from independent third-party laboratories confirming purity and identity.</p></details>'
					. '</div>'
					. '<div class="tpb-center"><a class="tpb-text-link" href="' . $url_faq . '">View All FAQs →</a></div>'
					. '</div>'
				),
			)
		);

		$edu = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#F9F9F7',
				'css_classes'           => 'tpb-edu',
			),
			array(
				$n::text(
					'<div class="tpb-wrap">'
					. '<div class="tpb-sec-head"><h2 class="tpb-h2 tpb-h2--center">Educational Resources</h2>'
					. '<p class="tpb-lead tpb-lead--center">Comprehensive research summaries, protocols, and educational guides for laboratory researchers.</p></div>'
					. '<div class="tpb-edu__primary">'
					. '<a class="tpb-edu-card" href="' . $url_products . '"><h3 class="tpb-h3">BPC-157 Research</h3><p class="tpb-body">Pentadecapeptide &amp; angiogenesis-pathway mechanisms</p><span class="tpb-edu-card__link">Read more →</span></a>'
					. '<a class="tpb-edu-card" href="' . $url_products . '"><h3 class="tpb-h3">TB-500 Research</h3><p class="tpb-body">Thymosin Beta-4 fragment studies</p><span class="tpb-edu-card__link">Read more →</span></a>'
					. '<a class="tpb-edu-card" href="' . $url_products . '"><h3 class="tpb-h3">GHK-Cu Research</h3><p class="tpb-body">Copper-peptide signaling studies</p><span class="tpb-edu-card__link">Read more →</span></a>'
					. '<a class="tpb-edu-card" href="' . $url_products . '"><h3 class="tpb-h3">IGF-1 LR3 Research</h3><p class="tpb-body">Long-acting insulin-like growth factor studies</p><span class="tpb-edu-card__link">Read more →</span></a>'
					. '</div>'
					. '<div class="tpb-edu__secondary">'
					. '<div class="tpb-edu-card tpb-edu-card--muted"><h3 class="tpb-h3">All Peptide Research</h3><p class="tpb-meta">Browse 37+ comprehensive peptide research summaries</p></div>'
					. '<div class="tpb-edu-card tpb-edu-card--muted"><h3 class="tpb-h3">Research Guides</h3><p class="tpb-meta">15+ guides on reconstitution, storage, and protocols</p></div>'
					. '<div class="tpb-edu-card tpb-edu-card--muted"><h3 class="tpb-h3">Learning Center</h3><p class="tpb-meta">10+ educational articles for researchers</p></div>'
					. '</div></div>'
				),
			)
		);

		$cta = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#3D3B24',
				'css_classes'           => 'tpb-cta',
			),
			array(
				$n::text(
					'<div class="tpb-wrap tpb-cta__inner">'
					. '<h2 class="tpb-h2 tpb-h2--center tpb-h2--light">Take Your Research Further</h2>'
					. '<p class="tpb-lead tpb-lead--center tpb-lead--light">Peak provides verified purity research peptides with COAs and easy ordering. Trusted by researchers who value transparency and quality.</p>'
					. '<div class="tpb-center"><a class="tpb-btn tpb-btn--cta" href="' . $url_products . '">Shop Now <span aria-hidden="true">›</span></a></div>'
					. '</div>'
				),
			)
		);

		return array( $hero, $guarantee, $trust, $featured, $index, $how, $what, $categories, $quality, $coa, $shipping, $newsletter, $faq, $edu, $cta );
	}

	/**
	 * About page — matches reference layout (hero, values, process, stats, disclaimer).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function about_tree(): array {
		$n = TPB_Elementor_Nodes::class;

		$about = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#FFFFFF',
				'css_classes'           => 'tpb-about',
			),
			array(
				$n::text(
					'<div class="tpb-wrap tpb-about__inner">'
					. '<div class="tpb-about__hero">'
					. '<p class="tpb-eyebrow">About Us</p>'
					. '<h1 class="tpb-about__title">Advancing Research Through Precision</h1>'
					. '<p class="tpb-about__lead">Peak was founded with a singular mission: to provide the research community with the highest quality peptides available, backed by rigorous third-party testing and complete transparency in every batch we produce.</p>'
					. '</div>'
					. '<div class="tpb-about__values">'
					. '<div class="tpb-about-card">'
					. '<div class="tpb-about-card__icon" aria-hidden="true"></div>'
					. '<h3 class="tpb-h3">Our Mission</h3>'
					. '<p class="tpb-body">To accelerate scientific discovery by providing researchers with peptides of uncompromising purity and documented quality.</p>'
					. '</div>'
					. '<div class="tpb-about-card">'
					. '<div class="tpb-about-card__icon" aria-hidden="true"></div>'
					. '<h3 class="tpb-h3">Our Standard</h3>'
					. '<p class="tpb-body">Every compound undergoes HPLC and Mass Spectrometry verification by independent laboratories, achieving >98% purity on every batch.</p>'
					. '</div>'
					. '<div class="tpb-about-card">'
					. '<div class="tpb-about-card__icon" aria-hidden="true"></div>'
					. '<h3 class="tpb-h3">Our Community</h3>'
					. '<p class="tpb-body">We serve academic institutions, private laboratories, and qualified researchers across the United States and internationally.</p>'
					. '</div>'
					. '</div>'
					. '<div class="tpb-about__process">'
					. '<h2 class="tpb-h2">Our Quality Process</h2>'
					. '<div class="tpb-about__steps">'
					. '<div class="tpb-about-step"><span class="tpb-about-step__num lab-readout">01</span><h4 class="tpb-about-step__title">Synthesis</h4><p class="tpb-body">Solid-phase peptide synthesis under controlled conditions.</p></div>'
					. '<div class="tpb-about-step"><span class="tpb-about-step__num lab-readout">02</span><h4 class="tpb-about-step__title">Purification</h4><p class="tpb-body">HPLC purification to achieve >98% purity levels.</p></div>'
					. '<div class="tpb-about-step"><span class="tpb-about-step__num lab-readout">03</span><h4 class="tpb-about-step__title">Testing</h4><p class="tpb-body">Independent third-party lab verification via HPLC &amp; MS.</p></div>'
					. '<div class="tpb-about-step"><span class="tpb-about-step__num lab-readout">04</span><h4 class="tpb-about-step__title">Documentation</h4><p class="tpb-body">Full COA generated and included with every shipment.</p></div>'
					. '</div>'
					. '</div>'
					. '<div class="tpb-about__stats">'
					. '<div class="tpb-about-stat"><p class="tpb-about-stat__value">>98%</p><p class="tpb-about-stat__label">Purity Standard</p></div>'
					. '<div class="tpb-about-stat"><p class="tpb-about-stat__value">20+</p><p class="tpb-about-stat__label">Compounds</p></div>'
					. '<div class="tpb-about-stat"><p class="tpb-about-stat__value">100%</p><p class="tpb-about-stat__label">COA Coverage</p></div>'
					. '<div class="tpb-about-stat"><p class="tpb-about-stat__value">Same-Day</p><p class="tpb-about-stat__label">Shipping</p></div>'
					. '</div>'
					. '<div class="tpb-about__disclaimer">'
					. '<h3 class="tpb-about__disclaimer-title">Research Use Disclaimer</h3>'
					. '<p class="tpb-about__disclaimer-text">All products sold by Peak are intended for laboratory research purposes only. They are not intended for human consumption, veterinary use, or any clinical application. By purchasing our products, you confirm that you are a qualified researcher and that all products will be used in accordance with applicable laws and regulations.</p>'
					. '</div>'
					. '</div>'
				),
			)
		);

		return array( $about );
	}

	/**
	 * Contact info card HTML (one Elementor text widget per item — add/remove in editor).
	 */
	private static function contact_info_item( string $icon, string $label, string $value_html ): string {
		return '<div class="tpb-contact-info__item"><div class="tpb-contact-info__icon">' . $icon . '</div><div>'
			. '<p class="tpb-contact-info__label">' . esc_html( $label ) . '</p>'
			. '<p class="tpb-contact-info__value">' . $value_html . '</p>'
			. '</div></div>';
	}

	/**
	 * Contact page — editable hero, info cards, and AJAX form shortcode.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function contact_tree(): array {
		$n = TPB_Elementor_Nodes::class;

		$icon_mail  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>';
		$icon_clock = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
		$icon_pin   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg>';

		$contact = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#FFFFFF',
				'css_classes'           => 'tpb-contact',
			),
			array(
				$n::container(
					array(
						'content_width' => 'full',
						'css_classes'   => 'tpb-wrap tpb-contact__inner',
					),
					array(
						$n::text(
							'<div class="tpb-contact__hero">'
							. '<p class="tpb-eyebrow">Get in Touch</p>'
							. '<h1 class="tpb-contact__title">Contact Us</h1>'
							. '<p class="tpb-contact__lead">Have questions about our products or need assistance? Our team is here to help.</p>'
							. '</div>'
						),
						$n::container(
							array(
								'content_width'  => 'full',
								'css_classes'    => 'tpb-contact__grid',
								'flex_direction' => 'row',
								'flex_wrap'      => 'wrap',
							),
							array(
								$n::container(
									array(
										'content_width'  => 'full',
										'css_classes'    => 'tpb-contact-info',
										'flex_direction' => 'column',
									),
									array(
										$n::text( self::contact_info_item( $icon_mail, 'Email', '<a href="mailto:support@example.com">support@example.com</a>' ) ),
										$n::text( self::contact_info_item( $icon_clock, 'Hours', 'Mon-Fri: 9AM - 5PM EST' ) ),
										$n::text( self::contact_info_item( $icon_pin, 'Location', 'United States' ) ),
									),
									true
								),
								$n::shortcode( '[tpb_contact_form]' ),
							),
							true
						),
					),
					true
				),
			)
		);

		return array( $contact );
	}

	/**
	 * COA Library — interactive certificate browser.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function coa_tree(): array {
		$n = TPB_Elementor_Nodes::class;

		return array(
			$n::container(
				array(
					'content_width'         => 'full',
					'background_background' => 'classic',
					'background_color'      => '#FFFFFF',
					'css_classes'           => 'tpb-coa-page',
				),
				array(
					$n::shortcode( '[tpb_coa_library]' ),
				)
			),
		);
	}

	/**
	 * Stacks & Bundles page — header + Woo stack grid (Manus layout).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function stacks_tree(): array {
		$n = TPB_Elementor_Nodes::class;

		return array(
			$n::container(
				array(
					'content_width'         => 'full',
					'background_background' => 'classic',
					'background_color'      => '#FFFFFF',
					'css_classes'           => 'tpb-stacks',
				),
				array(
					$n::text(
						'<div class="tpb-wrap tpb-stacks__inner">'
						. '<div class="tpb-stacks__hero">'
						. '<h1 class="tpb-stacks__title">Research Stacks &amp; Bundles</h1>'
						. '<p class="tpb-stacks__lead">Curated research bundles at discounted prices. Save up to 14% on multi-compound protocols.</p>'
						. '</div>'
						. '</div>'
					),
					$n::shortcode( '[products limit="12" columns="3" category="stacks-bundles" orderby="id" order="ASC"]' ),
				)
			),
		);
	}

	/**
	 * FAQ page — Manus FAQ.tsx accordion layout.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function faq_tree(): array {
		$n = TPB_Elementor_Nodes::class;

		$items = array(
			array(
				'q'    => 'What are research peptides?',
				'a'    => 'Research peptides are synthetic amino acid chains used exclusively in laboratory research settings. They are not intended for human consumption, veterinary use, or any clinical application.',
				'open' => true,
			),
			array(
				'q' => 'How do I store my peptides?',
				'a' => 'Store lyophilized peptides at −20°C for long-term storage. Once reconstituted, store at 2–8°C and use within 14 days. Avoid repeated freeze-thaw cycles.',
			),
			array(
				'q' => 'What is a Certificate of Analysis (COA)?',
				'a' => 'A COA is a document from an independent laboratory confirming the identity and purity of a compound. Our COAs include HPLC purity data, mass spectrometry confirmation, and endotoxin testing results.',
			),
			array(
				'q' => 'Do you ship internationally?',
				'a' => 'Currently we ship within the United States only. International shipping options are being evaluated for qualified research institutions.',
			),
			array(
				'q' => 'What payment methods do you accept?',
				'a' => 'We accept major credit cards, ACH bank transfers, and cryptocurrency. All transactions are encrypted and PCI-compliant.',
			),
			array(
				'q' => 'What is your return policy?',
				'a' => 'Due to the nature of research compounds, we cannot accept returns on opened products. Unopened products may be returned within 30 days for a full refund.',
			),
			array(
				'q' => 'How is purity verified?',
				'a' => 'All products undergo HPLC (High-Performance Liquid Chromatography) and Mass Spectrometry testing by independent third-party laboratories. Results are documented in each product\'s COA.',
			),
			array(
				'q' => 'Do I need an account to order?',
				'a' => 'Yes, you must create an account and verify your research credentials before placing an order. This ensures compliance with our research-use-only policy.',
			),
			array(
				'q' => 'What are stacks/bundles?',
				'a' => 'Stacks are pre-configured combinations of complementary research peptides offered at a discounted price. They are designed for researchers studying related pathways.',
			),
			array(
				'q' => 'How quickly do you ship?',
				'a' => 'Orders placed before 2PM EST Monday–Friday ship same day. Standard shipping is 2–5 business days. Expedited options are available at checkout.',
			),
		);

		$list = '';
		foreach ( $items as $item ) {
			$open  = ! empty( $item['open'] ) ? ' open' : '';
			$list .= '<details class="tpb-faq__item"' . $open . ' name="tpb-faq">'
				. '<summary>' . esc_html( $item['q'] ) . '</summary>'
				. '<p class="tpb-body">' . esc_html( $item['a'] ) . '</p>'
				. '</details>';
		}

		$faq = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#FFFFFF',
				'css_classes'           => 'tpb-faq-page',
			),
			array(
				$n::text(
					'<div class="tpb-wrap tpb-faq-page__inner">'
					. '<div class="tpb-faq-page__hero">'
					. '<p class="tpb-eyebrow">Support</p>'
					. '<h1 class="tpb-faq-page__title">Frequently Asked Questions</h1>'
					. '<p class="tpb-faq-page__lead">Find answers to common questions about our products, shipping, and policies.</p>'
					. '</div>'
					. '<div class="tpb-faq__list">'
					. $list
					. '</div>'
					. '</div>'
				),
			)
		);

		return array( $faq );
	}

	/**
	 * Storage & Handling — Elementor-editable sections (solvent table, steps, reference).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function storage_tree(): array {
		$n = TPB_Elementor_Nodes::class;

		$icon_temp   = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z"/></svg>';
		$icon_drop   = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 16.3c2.2 0 4-1.83 4-4.05 0-1.16-.57-2.26-1.71-3.19S7.29 6.75 7 5.3c-.29 1.45-1.14 2.84-2.29 3.76S3 11.1 3 12.25c0 2.22 1.8 4.05 4 4.05z"/><path d="M12.56 6.6A10.97 10.97 0 0 0 14 3.02c.5 2.5 2 4.9 4 6.5s3 3.5 3 5.5a6.98 6.98 0 0 1-11.91 4.97"/></svg>';
		$icon_shield = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>';
		$icon_alert  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>';

		$solvent_table = '<div class="tpb-storage-ref"><h3 class="tpb-storage-ref__title">Solvent Selection</h3>'
			. '<table class="tpb-storage-table"><thead><tr><th>Solvent</th><th>Use Case</th><th>Reconstitution</th></tr></thead><tbody>'
			. '<tr><td>Bacteriostatic Water</td><td>Multi-use over days/weeks</td><td class="lab-readout">Up to 28 days at 2–8°C</td></tr>'
			. '<tr><td>Sterile Water</td><td>Single-use applications</td><td class="lab-readout">Use within 24 hours</td></tr>'
			. '<tr><td>Acetic Acid (0.1%)</td><td>Hydrophobic peptides</td><td class="lab-readout">Up to 14 days at 2–8°C</td></tr>'
			. '<tr><td>DMSO</td><td>Very hydrophobic peptides</td><td class="lab-readout">Up to 30 days at -20°C</td></tr>'
			. '</tbody></table></div>';

		$recommendation = '<p class="tpb-body"><strong>Recommendation:</strong> For most PEAK peptides, bacteriostatic water is the preferred reconstitution solvent.</p>';

		$step1 = '<div class="tpb-storage-step"><h3 class="tpb-h3">Step 1: Preparation</h3><ul class="tpb-body">'
			. '<li>Allow the peptide vial to reach room temperature (5–10 minutes)</li>'
			. '<li>Clean your workspace and gather all materials</li>'
			. '<li>Swab the vial stopper with an alcohol wipe</li>'
			. '</ul></div>';

		$step2 = '<div class="tpb-storage-step"><h3 class="tpb-h3">Step 2: Calculate Volume</h3><ul class="tpb-body">'
			. '<li>Determine the desired concentration for your research protocol</li>'
			. '<li>Calculate solvent volume: Volume (mL) = peptide mass (mg) ÷ desired concentration (mg/mL)</li>'
			. '</ul></div>';

		$step3 = '<div class="tpb-storage-step"><h3 class="tpb-h3">Step 3: Reconstitution</h3><ul class="tpb-body">'
			. '<li>Inject solvent slowly along the inside wall of the vial</li>'
			. '<li>Do not squirt directly onto the lyophilized peptide</li>'
			. '<li>Gently swirl until fully dissolved — do not vortex</li>'
			. '</ul></div>';

		$quick_ref = '<div class="tpb-storage-ref"><h3 class="tpb-storage-ref__title">Storage Quick Reference</h3>'
			. '<table class="tpb-storage-table"><thead><tr><th>Condition</th><th>Temperature</th><th>Duration</th></tr></thead><tbody>'
			. '<tr><td>Long-term (lyophilized)</td><td class="lab-readout">-20°C</td><td>Up to 24 months</td></tr>'
			. '<tr><td>Short-term (lyophilized)</td><td class="lab-readout">2–8°C</td><td>Up to 3 months</td></tr>'
			. '<tr><td>Reconstituted solution</td><td class="lab-readout">2–8°C</td><td>Up to 14 days</td></tr>'
			. '<tr><td>Shipping</td><td class="lab-readout">Ambient</td><td>Stable for 5–7 days</td></tr>'
			. '</tbody></table></div>';

		$storage = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#FFFFFF',
				'css_classes'           => 'tpb-storage',
			),
			array(
				$n::container(
					array(
						'content_width' => 'full',
						'css_classes'   => 'tpb-wrap tpb-storage__inner',
					),
					array(
						$n::text(
							'<div class="tpb-storage__hero">'
							. '<p class="tpb-eyebrow">Guidelines</p>'
							. '<h1 class="tpb-storage__title">Storage &amp; Handling</h1>'
							. '<p class="tpb-storage__lead">Proper storage and handling ensures maximum stability and research integrity.</p>'
							. '</div>'
						),
						$n::text(
							'<div class="tpb-storage__grid">'
							. '<div class="tpb-storage-card"><div class="tpb-storage-card__icon">' . $icon_temp . '</div><h3 class="tpb-h3">Temperature</h3><p class="tpb-body">Store lyophilized peptides at -20°C. Reconstituted solutions at 2–8°C. Never expose to temperatures above 25°C.</p></div>'
							. '<div class="tpb-storage-card"><div class="tpb-storage-card__icon">' . $icon_drop . '</div><h3 class="tpb-h3">Reconstitution</h3><p class="tpb-body">Use bacteriostatic water or sterile water. Add solvent slowly along the vial wall. Do not vortex — gently swirl until dissolved.</p></div>'
							. '<div class="tpb-storage-card"><div class="tpb-storage-card__icon">' . $icon_shield . '</div><h3 class="tpb-h3">Protection</h3><p class="tpb-body">Protect from light, moisture, and repeated freeze-thaw cycles. Use amber vials for light-sensitive compounds.</p></div>'
							. '<div class="tpb-storage-card"><div class="tpb-storage-card__icon">' . $icon_alert . '</div><h3 class="tpb-h3">Handling</h3><p class="tpb-body">Work in a clean environment. Use sterile technique. Avoid contamination by using fresh syringes for each withdrawal.</p></div>'
							. '</div>'
						),
						$n::text( $solvent_table ),
						$n::text( $recommendation ),
						$n::heading( 'Step-by-Step Reconstitution', array( 'header_size' => 'h2', 'title_color' => '#2D2B1A' ) ),
						$n::text( $step1 ),
						$n::text( $step2 ),
						$n::text( $step3 ),
						$n::text( $quick_ref ),
					),
					true
				),
			)
		);

		return array( $storage );
	}

	/**
	 * Partner With Us — investment / partnership inquiry form.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function partner_tree(): array {
		$n = TPB_Elementor_Nodes::class;

		$partner = $n::container(
			array(
				'content_width'         => 'full',
				'background_background' => 'classic',
				'background_color'      => '#FFFFFF',
				'css_classes'           => 'tpb-partner',
			),
			array(
				$n::container(
					array(
						'content_width' => 'full',
						'css_classes'   => 'tpb-wrap tpb-partner__inner',
					),
					array(
						$n::text(
							'<div class="tpb-partner__hero">'
							. '<p class="tpb-eyebrow">Partnerships</p>'
							. '<h1 class="tpb-partner__title">Partner With Us</h1>'
							. '<p class="tpb-partner__lead">Interested in investing or becoming a distribution partner? Share a few details and our team will follow up.</p>'
							. '</div>'
						),
						$n::text(
							'<ul class="tpb-partner__points tpb-body">'
							. '<li>Investment and strategic partnership opportunities</li>'
							. '<li>Research supply and distribution collaborations</li>'
							. '<li>Qualified partners in the United States</li>'
							. '</ul>'
						),
						$n::shortcode( '[tpb_partner_form]' ),
					),
					true
				),
			)
		);

		return array( $partner );
	}

	/**
	 * Inner page scaffold — editable nested containers ready for Elementor polish.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function simple_tree( string $title ): array {
		$n = TPB_Elementor_Nodes::class;

		$body_children = array();
		$lower         = strtolower( $title );
		if ( str_contains( $lower, 'product' ) ) {
			$body_children[] = $n::shortcode( '[products limit="24" columns="4" orderby="id" order="ASC"]' );
		}

		$body_children[] = $n::container(
			array(
				'content_width'        => 'full',
				'flex_direction'       => 'row',
				'flex_wrap'            => 'wrap',
				'flex_justify_content' => 'space-between',
			),
			array(
				$n::card( 'Section block A', 'Replace this nested container with your layout — headings, images, buttons, and columns stay independently editable.' ),
				$n::card( 'Section block B', 'Do not paste the whole page into one HTML widget; keep widgets as siblings inside containers.' ),
			),
			true
		);

		return array(
			$n::container(
				array(
					'content_width'         => 'boxed',
					'background_background' => 'classic',
					'background_color'      => '#F5F4EE',
					'padding'               => array(
						'unit' => 'px', 'top' => '72', 'right' => '0', 'bottom' => '72', 'left' => '0', 'isLinked' => false,
					),
				),
				array(
					$n::heading( $title, array( 'header_size' => 'h1', 'align' => 'center', 'title_color' => '#2D2B1A' ) ),
					$n::text( '<p style="text-align:center;color:#64748B;max-width:40rem;margin:0 auto;">Edit this page in Elementor. Every container below is a nested child — select any of them to change Style, Advanced, or content.</p>' ),
				)
			),
			$n::container(
				array(
					'content_width' => 'boxed',
					'padding'       => array(
						'unit' => 'px', 'top' => '48', 'right' => '0', 'bottom' => '64', 'left' => '0', 'isLinked' => false,
					),
				),
				$body_children
			),
		);
	}

	/**
	 * Prefer compressed WebP/JPEG brand assets when present.
	 */
	private static function brand_url( string $stem ): string {
		$dir = TPB_CORE_DIR . 'assets/brand/';
		$base = TPB_CORE_URL . 'assets/brand/';
		foreach ( array( '.webp', '.jpg', '.jpeg', '.png' ) as $ext ) {
			if ( is_readable( $dir . $stem . $ext ) ) {
				return set_url_scheme( $base . $stem . $ext, 'https' );
			}
		}
		return set_url_scheme( $base . $stem . '.png', 'https' );
	}
}
