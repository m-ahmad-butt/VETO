<?php
/**
 * Build Elementor nested-container trees (every node independently editable).
 *
 * @package Peak_Core
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TPB_Elementor_Nodes {

	/**
	 * Random 7-char Elementor id.
	 */
	public static function id(): string {
		return substr( bin2hex( random_bytes( 8 ) ), 0, 7 );
	}

	/**
	 * Outer or nested flex container.
	 *
	 * @param array<string,mixed>       $settings Settings.
	 * @param array<int,array<string,mixed>> $children Child elements.
	 * @param bool                      $is_inner Nested flag.
	 * @return array<string,mixed>
	 */
	public static function container( array $settings = array(), array $children = array(), bool $is_inner = false ): array {
		if ( isset( $settings['css_classes'] ) && ! isset( $settings['_css_classes'] ) ) {
			$settings['_css_classes'] = $settings['css_classes'];
			unset( $settings['css_classes'] );
		}

		$defaults = array(
			'flex_direction'      => 'column',
			'flex_gap'            => array(
				'unit'  => 'px',
				'size'  => 16,
				'sizes' => array(),
			),
			'boxed_width'         => array(
				'unit' => 'px',
				'size' => 1140,
			),
			'content_width'       => 'boxed',
		);

		return array(
			'id'       => self::id(),
			'elType'   => 'container',
			'isInner'  => $is_inner,
			'settings' => array_merge( $defaults, $settings ),
			'elements' => $children,
		);
	}

	/**
	 * Heading widget.
	 *
	 * @param string               $title    Title.
	 * @param array<string,mixed>  $settings Extra settings.
	 * @return array<string,mixed>
	 */
	public static function heading( string $title, array $settings = array() ): array {
		if ( isset( $settings['css_classes'] ) && ! isset( $settings['_css_classes'] ) ) {
			$settings['_css_classes'] = $settings['css_classes'];
			unset( $settings['css_classes'] );
		}

		return array(
			'id'         => self::id(),
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => array_merge(
				array(
					'title'       => $title,
					'header_size' => 'h2',
					'title_color' => '#2D2B1A',
				),
				$settings
			),
			'elements'   => array(),
		);
	}

	/**
	 * Text editor widget (short HTML ok — keep small; never whole-page).
	 *
	 * @param string              $html     Content.
	 * @param array<string,mixed> $settings Extra.
	 * @return array<string,mixed>
	 */
	public static function text( string $html, array $settings = array() ): array {
		if ( isset( $settings['css_classes'] ) && ! isset( $settings['_css_classes'] ) ) {
			$settings['_css_classes'] = $settings['css_classes'];
			unset( $settings['css_classes'] );
		}

		return array(
			'id'         => self::id(),
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => array_merge(
				array(
					'editor' => $html,
				),
				$settings
			),
			'elements'   => array(),
		);
	}

	/**
	 * Button widget.
	 *
	 * @param string              $text     Label.
	 * @param string              $url      Link.
	 * @param array<string,mixed> $settings Extra.
	 * @return array<string,mixed>
	 */
	public static function button( string $text, string $url, array $settings = array() ): array {
		if ( isset( $settings['css_classes'] ) && ! isset( $settings['_css_classes'] ) ) {
			$settings['_css_classes'] = $settings['css_classes'];
			unset( $settings['css_classes'] );
		}

		return array(
			'id'         => self::id(),
			'elType'     => 'widget',
			'widgetType' => 'button',
			'settings'   => array_merge(
				array(
					'text'            => $text,
					'link'            => array(
						'url'         => $url,
						'is_external' => '',
						'nofollow'    => '',
					),
					'background_color' => '#5F5C37',
					'button_text_color' => '#FFFFFF',
					'border_radius'   => array(
						'unit'     => 'px',
						'top'      => '12',
						'right'    => '12',
						'bottom'   => '12',
						'left'     => '12',
						'isLinked' => true,
					),
				),
				$settings
			),
			'elements'   => array(),
		);
	}

	/**
	 * Shortcode widget (Woo product grids, etc.).
	 *
	 * @param string $shortcode Shortcode string.
	 * @return array<string,mixed>
	 */
	public static function shortcode( string $shortcode ): array {
		return array(
			'id'         => self::id(),
			'elType'     => 'widget',
			'widgetType' => 'shortcode',
			'settings'   => array(
				'shortcode' => $shortcode,
			),
			'elements'   => array(),
		);
	}

	/**
	 * Image widget from external/theme URL.
	 *
	 * @param string              $url      Image URL.
	 * @param string              $alt      Alt text.
	 * @param array<string,mixed> $settings Extra.
	 * @return array<string,mixed>
	 */
	public static function image( string $url, string $alt = '', array $settings = array() ): array {
		return array(
			'id'         => self::id(),
			'elType'     => 'widget',
			'widgetType' => 'image',
			'settings'   => array_merge(
				array(
					'image' => array(
						'url' => $url,
						'id'  => '',
						'alt' => $alt,
					),
					'image_size' => 'full',
				),
				$settings
			),
			'elements'   => array(),
		);
	}

	/**
	 * Spacer widget.
	 *
	 * @param int $size Pixels.
	 * @return array<string,mixed>
	 */
	public static function spacer( int $size = 24 ): array {
		return array(
			'id'         => self::id(),
			'elType'     => 'widget',
			'widgetType' => 'spacer',
			'settings'   => array(
				'space' => array(
					'unit' => 'px',
					'size' => $size,
				),
			),
			'elements'   => array(),
		);
	}

	/**
	 * Card-like nested container with heading + text (fully editable children).
	 *
	 * @param string $title Title.
	 * @param string $body  Body HTML.
	 * @return array<string,mixed>
	 */
	public static function card( string $title, string $body ): array {
		return self::container(
			array(
				'content_width'          => 'full',
				'background_background'  => 'classic',
				'background_color'       => '#FFFFFF',
				'border_border'          => 'solid',
				'border_width'           => array(
					'unit'     => 'px',
					'top'      => '1',
					'right'    => '1',
					'bottom'   => '1',
					'left'     => '1',
					'isLinked' => true,
				),
				'border_color'           => '#E5E7EB',
				'border_radius'          => array(
					'unit'     => 'px',
					'top'      => '12',
					'right'    => '12',
					'bottom'   => '12',
					'left'     => '12',
					'isLinked' => true,
				),
				'padding'                => array(
					'unit'     => 'px',
					'top'      => '24',
					'right'    => '24',
					'bottom'   => '24',
					'left'     => '24',
					'isLinked' => true,
				),
			),
			array(
				self::heading( $title, array( 'header_size' => 'h3', 'title_color' => '#2D2B1A' ) ),
				self::text( '<p style="color:#64748B;font-size:14px;line-height:1.6;margin:0;">' . esc_html( $body ) . '</p>' ),
			),
			true
		);
	}
}
