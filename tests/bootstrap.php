<?php
/**
 * Test bootstrap.
 *
 * The domain and parser classes are deliberately free of WordPress
 * dependencies, so they can be tested without a WordPress install. They still
 * carry the standard `defined( 'ABSPATH' ) || exit;` guard that stops direct
 * web access, so the bootstrap defines that constant to let them load.
 *
 * @package MVOC_StreetO
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/mvoc-streeto-results/includes/maprun/class-parser.php';
