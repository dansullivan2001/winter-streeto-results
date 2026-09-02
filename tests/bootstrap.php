<?php
/**
 * Test bootstrap.
 *
 * The parser and domain classes are deliberately free of WordPress
 * dependencies, so they can be tested without a WordPress install. They still
 * carry the standard `defined( 'ABSPATH' ) || exit;` guard that stops direct
 * web access, so the bootstrap defines that constant to let them load.
 *
 * Classes load through the plugin's own autoloader rather than by explicit
 * require, so the tests exercise the same resolution path WordPress uses: a
 * class whose filename does not match its name fails here rather than fatalling
 * on the live site.
 *
 * @package MVOC_StreetO
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MVOC_STREETO_DIR', dirname( __DIR__ ) . '/mvoc-streeto-results/' );

require_once MVOC_STREETO_DIR . 'includes/class-autoloader.php';
\MVOC\StreetO\Autoloader::register();
