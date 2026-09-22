<?php defined( 'ABSPATH' ) || exit; ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Reel Together — Your life in movies</title>
    <?php remove_action( 'wp_head', 'wp_site_icon', 99 ); wp_head(); ?>
    <link rel="icon" type="image/svg+xml" sizes="any" href="<?php echo esc_url( RT_URL . 'assets/favicon.svg?ver=' . RT_VERSION ); ?>">
</head>
<body class="reel-together-body">
    <a class="skip-link" href="#main">Skip to content</a>
    <?php wp_body_open(); ?>
    <?php echo RT_App::render(); // All dynamic values escaped in render(). ?>
    <?php wp_footer(); ?>
</body>
</html>
