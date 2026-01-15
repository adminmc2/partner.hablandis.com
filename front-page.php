<?php
/**
 * Front Page Template - Partner Portal (Clean, No WordPress Header)
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="icon" type="image/svg+xml" href="<?php echo get_stylesheet_directory_uri(); ?>/images/favicon.svg">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?> style="margin:0;padding:0;background:#d4e157;">
<?php wp_body_open(); ?>

<?php echo do_shortcode('[hablandis_partner_portal]'); ?>

<?php wp_footer(); ?>
</body>
</html>
