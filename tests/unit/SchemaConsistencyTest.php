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

use MVOC\StreetO\Domain\Import_Reconciler;
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

	public function test_every_raw_column_an_import_writes_exists_in_the_schema(): void {
		// Import_Reconciler::raw_columns() is the whole of what an import
		// persists, and it is a method rather than a declared list, so the
		// provider above cannot reach it. A key added there without the
		// matching DDL fails the insert on a live install and nowhere else.
		$columns = array_keys( Import_Reconciler::raw_columns( array() ) );
		$schema  = $this->schema_columns();

		$this->assertNotEmpty( $columns );

		foreach ( $columns as $column ) {
			$this->assertContains(
				$column,
				$schema['results'],
				sprintf( 'Column "%s" is written by an import but missing from the results table.', $column )
			);
		}
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

	/**
	 * Every table pointing at a competitor must be re-pointed by a merge.
	 *
	 * Competitors_Repo::merge() absorbs one competitor into another and then
	 * deletes the absorbed record, so a table it forgets is left pointing at an
	 * id that no longer resolves. Nothing fails: the rows are simply never
	 * matched again, and whatever they carried goes quiet.
	 *
	 * event_organisers was forgotten exactly that way. The organiser
	 * disappeared from the published event table and lost their league bonus,
	 * with no error anywhere — and the merge's own comment said "all three
	 * tables", which was true of the list and not of the schema.
	 *
	 * So the schema is the authority here, not the list: any future table with
	 * a competitor_id fails this until the merge handles it.
	 */
	public function test_every_table_referencing_a_competitor_is_handled_by_a_merge(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/mvoc-streeto-results/includes/repo/class-competitors-repo.php'
		);

		// The merge body alone. Matching the whole file would let a table named
		// only in an unrelated method count as handled.
		$start = strpos( $source, 'public function merge(' );
		$this->assertNotFalse( $start, 'Competitors_Repo::merge() has been renamed or removed.' );
		$merge = substr( $source, $start );

		$referencing = array();

		foreach ( $this->schema_columns() as $table => $columns ) {
			// competitors itself is the record being deleted, not a reference.
			if ( 'competitors' !== $table && in_array( 'competitor_id', $columns, true ) ) {
				$referencing[] = $table;
			}
		}

		$this->assertNotEmpty( $referencing );

		foreach ( $referencing as $table ) {
			$this->assertStringContainsString(
				"'" . $table . "'",
				$merge,
				sprintf(
					'%s carries a competitor_id but is not re-pointed by Competitors_Repo::merge(), '
					. 'so a merge would orphan its rows.',
					$table
				)
			);
		}
	}

	/**
	 * A merge must not collide with a unique key it did not plan for.
	 *
	 * Where a join table is unique on (something, competitor_id) and both
	 * records already hold a row for the same season, event or result,
	 * re-pointing the absorbed one violates the key and the update fails
	 * silently. Those tables need their colliding rows deleted first, which is
	 * what merge() groups separately — so every uniquely-keyed table must be in
	 * that group rather than the wholesale one.
	 */
	public function test_uniquely_keyed_join_tables_are_deduplicated_before_a_merge(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/mvoc-streeto-results/includes/repo/class-competitors-repo.php'
		);

		$start = strpos( $source, 'public function merge(' );
		$this->assertNotFalse( $start );
		$merge = substr( $source, $start );

		// The $scoped map is the group that deletes collisions first.
		$this->assertMatchesRegularExpression( '/\$scoped\s*=\s*array\(/', $merge );
		$scoped = substr( $merge, (int) strpos( $merge, '$scoped' ) );
		$scoped = substr( $scoped, 0, (int) strpos( $scoped, ');' ) );

		foreach ( array( 'series_competitors', 'event_organisers', 'result_competitors' ) as $table ) {
			$this->assertStringContainsString(
				"'" . $table . "'",
				$scoped,
				sprintf( '%s is unique on (scope, competitor_id) and must be deduplicated before a merge.', $table )
			);
		}
	}
}
