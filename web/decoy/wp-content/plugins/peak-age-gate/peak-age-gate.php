<?php
/**
 * Plugin Name: Peak Age Gate
 * Description: 21+ age verification modal for Peak (matches Manus prototype).
 * Version: 1.0.1
 * Author: Peak
 * Text Domain: peak-age-gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPB_AGE_GATE_VERSION', '1.0.1' );
define( 'TPB_AGE_GATE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Front-end assets.
 */
function tpb_age_gate_assets(): void {
	if ( is_admin() ) {
		return;
	}

	wp_enqueue_style(
		'tpb-age-gate',
		TPB_AGE_GATE_URL . 'assets/age-gate.css',
		array(),
		TPB_AGE_GATE_VERSION
	);
	wp_enqueue_script(
		'tpb-age-gate',
		TPB_AGE_GATE_URL . 'assets/age-gate.js',
		array(),
		TPB_AGE_GATE_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'tpb_age_gate_assets' );

/**
 * Markup injected before </body>.
 */
function tpb_age_gate_markup(): void {
	if ( is_admin() ) {
		return;
	}
	?>
	<div id="tpb-age-gate" class="tpb-age-gate" hidden>
		<div class="tpb-age-gate__backdrop" data-tpb-age-deny></div>
		<div class="tpb-age-gate__dialog" role="dialog" aria-modal="true" aria-labelledby="tpb-age-gate-title">
			<div class="tpb-age-gate__logo" aria-hidden="true">
				<img src="<?php echo esc_url( TPB_AGE_GATE_URL . 'assets/logo.png' ); ?>" alt="" width="56" height="56" />
			</div>
			<h2 id="tpb-age-gate-title"><?php esc_html_e( 'Age Verification Required', 'peak-age-gate' ); ?></h2>
			<p><?php esc_html_e( 'You must be 21 years or older to access this website. All products are sold exclusively for research purposes and are not intended for human consumption.', 'peak-age-gate' ); ?></p>
			<div class="tpb-age-gate__actions">
				<button type="button" class="tpb-age-gate__btn tpb-age-gate__btn--ghost" data-tpb-age-deny>
					<?php esc_html_e( 'I am under 21', 'peak-age-gate' ); ?>
				</button>
				<button type="button" class="tpb-age-gate__btn tpb-age-gate__btn--primary" data-tpb-age-confirm>
					<?php esc_html_e( 'I am 21 or older', 'peak-age-gate' ); ?>
				</button>
			</div>
			<p class="tpb-age-gate__fine">
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: 1: terms url 2: privacy url 3: research policy url */
						__( 'By entering, you agree to our <a href="%1$s">Terms & Conditions</a>, <a href="%2$s">Privacy Policy</a>, and <a href="%3$s">Research Use Disclaimer</a>.', 'peak-age-gate' ),
						esc_url( home_url( '/terms/' ) ),
						esc_url( home_url( '/privacy-policy/' ) ),
						esc_url( home_url( '/research-policy/' ) )
					)
				);
				?>
			</p>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'tpb_age_gate_markup', 5 );
