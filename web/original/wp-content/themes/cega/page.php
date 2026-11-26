<?php
/** Display WordPress pages created by installed plugins. */
defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
	<main style="max-width:48rem;margin:4rem auto;padding:0 1.5rem;font:1rem/1.6 system-ui,sans-serif">
		<?php while ( have_posts() ) : the_post(); ?>
			<article>
				<h1><?php the_title(); ?></h1>
				<?php the_content(); ?>
			</article>
		<?php endwhile; ?>
	</main>
	<?php wp_footer(); ?>
</body>
</html>
