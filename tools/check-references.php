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

// And every call made on a locally constructed object must pass enough
// arguments for the method's signature.
//
// Added because it happened: delete_manual() gained a required $event_id, and
// tools/integration-test.php went on calling it with one argument. php -l sees
// only syntax, the unit tests never touch that script, and the script itself
// had not been run — so a guaranteed ArgumentCountError sat in the repository
// through two releases. The scripts under tools/ are the ones at risk, because
// nothing else exercises them.
//
// Deliberately narrow: it resolves `$var = new Class_Name(...)` and then checks
// `$var->method(...)` against that class. Anything it cannot resolve is
// skipped, so this never guesses.
foreach ( array( __DIR__, dirname( __DIR__ ) . '/tests' ) as $directory ) {
	foreach ( glob( $directory . '/{,*/}*.php', GLOB_BRACE ) as $file ) {
		$source = file_get_contents( $file );
		$short  = basename( dirname( $file ) ) . '/' . basename( $file );

		// Map short class names to the fully qualified ones this file imports.
		preg_match_all( '/^use\s+([^;]+);/m', $source, $imports );
		$aliases = array();
		foreach ( $imports[1] as $imported ) {
			$imported            = trim( $imported );
			$parts               = explode( '\\', $imported );
			$aliases[ end( $parts ) ] = $imported;
		}

		// $var = new Thing( ... )
		preg_match_all( '/\$(\w+)\s*=\s*new\s+\\\\?([\w\\\\]+)\s*\(/', $source, $built, PREG_SET_ORDER );

		$types = array();
		foreach ( $built as $match ) {
			$name  = $match[2];
			$class = $aliases[ $name ] ?? ( false !== strpos( $name, '\\' ) ? $name : null );

			if ( $class && class_exists( $class ) ) {
				$types[ $match[1] ] = $class;
			}
		}

		foreach ( $types as $variable => $class ) {
			// Count arguments at the top level of the call only, so a nested
			// call or an array literal cannot inflate the tally.
			preg_match_all( '/\$' . preg_quote( $variable, '/' ) . '->(\w+)\(/', $source, $calls, PREG_OFFSET_CAPTURE );

			foreach ( $calls[1] as $index => $call ) {
				$method = $call[0];

				if ( ! method_exists( $class, $method ) ) {
					printf( "  %s calls undefined %s::%s()\n", $short, $class, $method );
					$fail++;
					continue;
				}

				$open  = $calls[0][ $index ][1] + strlen( $calls[0][ $index ][0] ) - 1;
				$depth = 0;
				$args  = '';

				for ( $i = $open, $length = strlen( $source ); $i < $length; $i++ ) {
					$character = $source[ $i ];

					if ( '(' === $character || '[' === $character ) {
						$depth++;
					} elseif ( ')' === $character || ']' === $character ) {
						$depth--;
						if ( 0 === $depth ) {
							break;
						}
					}

					$args .= $character;
				}

				$args  = trim( substr( $args, 1 ) );
				$given = '' === $args ? 0 : 1;

				$depth = 0;
				foreach ( str_split( $args ) as $character ) {
					if ( '(' === $character || '[' === $character ) {
						$depth++;
					} elseif ( ')' === $character || ']' === $character ) {
						$depth--;
					} elseif ( ',' === $character && 0 === $depth ) {
						$given++;
					}
				}

				$required = ( new ReflectionMethod( $class, $method ) )->getNumberOfRequiredParameters();

				if ( $given < $required ) {
					printf(
						"  %s calls %s::%s() with %d argument(s); %d required\n",
						$short,
						$class,
						$method,
						$given,
						$required
					);
					$fail++;
				}
			}
		}
	}
}

echo $fail ? "\n$fail problem(s) found\n" : "All self:: constants, \$this-> calls and tool/test call signatures resolve.\n";
exit( $fail ? 1 : 0 );
