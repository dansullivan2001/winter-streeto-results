<?php
/**
 * Asserts that every column a repository writes actually exists in the schema.
 *
 * This exists because it did not, and a real bug got through: Competitors_Repo
 * read and wrote `competitors.year_of_birth` for a whole milestone while the
 * table had no such column. Every test passed, because none of them touched a
 * database — the domain layer is covered thoroughly and persistence not at all.
 * On a live install the insert would simply have failed.
 *
 * Rather than stand up MySQL for this, the test reads the CREATE TABLE
 * statements out of the schema source and compares them against each repo's
 * declared column list. It needs no database and runs in milliseconds.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Repo\Competitors_Repo;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class SchemaConsistencyTest extends TestCase {

	/**
	 * Logical table name => column names, parsed from the schema source.
	 *
	 * @return array<string,string[]>
	 */
	private function schema_columns(): array {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/mvoc-streeto-results/includes/class-schema.php'
		);

		// Each definition is written as:
		//
		//     $table = self::table( 'name' );
		//     $sql[] = "CREATE TABLE {$table} ( ... ) {$charset};";
		//
		// Both halves are required. Matching on self::table() alone paired a
		// call made elsewhere - the migration that collapses duplicate sources
		// - with the next CREATE block, silently swallowing a table.
		$pattern = '/\$table\s*=\s*self::table\(\s*\'([a-z_]+)\'\s*\);\s*'
			. '\$sql\[\]\s*=\s*"CREATE TABLE \{\$table\} \((.*?)\)\s*\{\$charset\}/s';

		preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER );

		$tables = array();

		foreach ( $matches as $match ) {
			$columns = array();

			foreach ( explode( "\n", $match[2] ) as $line ) {
				$line = trim( $line );

				// Skip key definitions and blank lines; a column line starts
				// with its name followed by a type.
				if ( '' === $line || preg_match( '/^(PRIMARY|UNIQUE|KEY|FULLTEXT)\b/i', $line ) ) {
					continue;
				}

				if ( preg_match( '/^([a-z_][a-z0-9_]*)\s+[a-z]/i', $line, $column ) ) {
					$columns[] = $column[1];
				}
			}

			$tables[ $match[1] ] = $columns;
		}

		return $tables;
	}

	public function test_the_schema_parses(): void {
		$tables = $this->schema_columns();

		$this->assertArrayHasKey( 'competitors', $tables );
		$this->assertArrayHasKey( 'results', $tables );
		$this->assertContains( 'id', $tables['competitors'] );
	}

	/**
	 * Guard the parser itself: if the regex silently stops matching, every
	 * other assertion here would pass vacuously.
	 */
	public function test_every_declared_table_is_found(): void {
		// Compared as sets, not sequences: Schema::table() is called outside
		// the CREATE TABLE definitions too - by the migration that collapses
		// duplicates, for one - so the order the parser meets them in is not
		// the order they are declared, and pinning it would fail for reasons
		// that say nothing about the schema.
		$found    = array_keys( $this->schema_columns() );
		$declared = \MVOC\StreetO\Schema::table_names();

		sort( $found );
		sort( $declared );

		$this->assertSame(
			$declared,
			$found,
			'The schema parser and Schema::table_names() disagree.'
		);
	}

	/**
	 * @dataProvider repo_provider
	 *
	 * @param string   $table   Logical table name.
	 * @param string[] $columns Columns the repo writes.
	 */
	public function test_repo_columns_exist_in_the_schema( string $table, array $columns ): void {
		$schema = $this->schema_columns();

		$this->assertArrayHasKey( $table, $schema );

		foreach ( $columns as $column ) {
			$this->assertContains(
				$column,
				$schema[ $table ],
				sprintf( 'Column "%s" is written by a repo but missing from the %s table.', $column, $table )
			);
		}
	}

	public function repo_provider(): array {
		return array(
			'competitors' => array( 'competitors', Competitors_Repo::COLUMNS ),
		);
	}

	public function test_no_table_stores_a_date_of_birth(): void {
		// Over-55 is derived from MapRun at import and kept as a flag per
		// season, so no birth year is held anywhere. This asserts that stays
		// true rather than creeping back in as a convenience.
		foreach ( $this->schema_columns() as $table => $columns ) {
			foreach ( $columns as $column ) {
				$this->assertStringNotContainsString(
					'birth',
					$column,
					sprintf( '%s.%s looks like a date of birth.', $table, $column )
				);
			}
		}
	}

	public function test_the_per_season_category_table_exists(): void {
		$this->assertArrayHasKey( 'series_competitors', $this->schema_columns() );
		$this->assertContains( 'is_over55', $this->schema_columns()['series_competitors'] );
	}
}
