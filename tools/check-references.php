<?php
/**
 * Check that every self::CONSTANT and $this->method() in the plugin resolves.
 *
 * php -l catches syntax, and the unit tests cover the domain layer, but neither
 * notices a reference to a constant or method that no longer exists - which is
 * exactly what happened when a constant was removed and one caller was left
 * behind. That would only have surfaced as a fatal error on the live site.
 *
 * Usage: php tools/check-references.php
 *
 * @package MVOC_StreetO
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MVOC_STREETO_DIR', dirname( __DIR__ ) . '/mvoc-streeto-results/' );
require MVOC_STREETO_DIR . 'includes/class-autoloader.php';
MVOC\StreetO\Autoloader::register();

$fail = 0;

// Every self::CONSTANT referenced in a plugin file must actually be defined.
foreach ( glob( MVOC_STREETO_DIR . '{,*/,*/*/}*.php', GLOB_BRACE ) as $file ) {
	$source = file_get_contents( $file );

	if ( ! preg_match( '/^\s*(?:final\s+)?class\s+(\w+)/m', $source, $m ) ) {
		continue;
	}
	if ( ! preg_match( '/^namespace\s+([^;]+);/m', $source, $ns ) ) {
		continue;
	}

	$class = trim( $ns[1] ) . '\\' . $m[1];
	if ( ! class_exists( $class ) ) {
		printf( "  could not load %s\n", $class );
		$fail++;
		continue;
	}

	$defined = array_keys( ( new ReflectionClass( $class ) )->getConstants() );

	preg_match_all( '/self::([A-Z][A-Z0-9_]+)\b/', $source, $used );
	foreach ( array_unique( $used[1] ) as $constant ) {
		if ( ! in_array( $constant, $defined, true ) ) {
			printf( "  %s references undefined self::%s\n", $class, $constant );
			$fail++;
		}
	}

	// And every $this->method() must exist on the class.
	$methods = array_map( fn( $r ) => $r->getName(), ( new ReflectionClass( $class ) )->getMethods() );
	preg_match_all( '/\$this->(\w+)\(/', $source, $calls );
	foreach ( array_unique( $calls[1] ) as $method ) {
		if ( ! in_array( $method, $methods, true ) ) {
			printf( "  %s calls undefined \$this->%s()\n", $class, $method );
			$fail++;
		}
	}
}

echo $fail ? "\n$fail problem(s) found\n" : "All self:: constants and \$this-> calls resolve.\n";
exit( $fail ? 1 : 0 );
