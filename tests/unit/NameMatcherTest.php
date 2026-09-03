<?php
/**
 * Tests for the name matcher.
 *
 * The cases are drawn from real data wherever possible: the club's 2019/20
 * workbook and the 2026 MapRun response contain many of the same people under
 * different spellings, which is the problem this class exists to solve.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Name_Matcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\Domain\Name_Matcher
 */
class NameMatcherTest extends TestCase {

	/**
	 * Build a row as the parser would emit it.
	 *
	 * @param string   $first   First name.
	 * @param string   $surname Surname.
	 * @param string   $club    Club.
	 * @param int|null $year    Year of birth.
	 * @return array<string,mixed>
	 */
	private function person( string $first, string $surname, string $club = '', ?int $year = null ): array {
		return array(
			'first_name'    => $first,
			'surname'       => $surname,
			'club'          => $club,
			'year_of_birth' => $year,
		);
	}

	/**
	 * @dataProvider normalise_provider
	 */
	public function test_normalise( string $input, string $expected ): void {
		$this->assertSame( $expected, Name_Matcher::normalise( $input ) );
	}

	public function normalise_provider(): array {
		return array(
			'plain'          => array( 'Smith', 'smith' ),
			'case'           => array( 'YARDLEY', 'yardley' ),
			'apostrophe'     => array( "O'Halloran", 'ohalloran' ),
			'curly quote'    => array( 'O’Halloran', 'ohalloran' ),
			'hyphen'         => array( 'Ashby-Prentice', 'ashby prentice' ),
			'space variant'  => array( 'Ashby Prentice', 'ashby prentice' ),
			'accents'        => array( 'Muñoz-Fernández', 'munoz fernandez' ),
			'extra spaces'   => array( '  Jerome   Standish  ', 'jerome standish' ),
			'trailing dot'   => array( 'St. John', 'st john' ),
		);
	}

	public function test_hyphen_and_space_surnames_normalise_alike(): void {
		// Hyphenated surnames turn up in the real data; a runner typing one
		// either way must not become two people.
		$this->assertSame(
			Name_Matcher::normalise( 'Ashby-Prentice' ),
			Name_Matcher::normalise( 'Ashby Prentice' )
		);
	}

	/**
	 * @dataProvider diminutive_provider
	 */
	public function test_diminutives_reduce_to_the_formal_name( string $short, string $formal ): void {
		$this->assertSame(
			Name_Matcher::canonical_first_name( $formal ),
			Name_Matcher::canonical_first_name( $short )
		);
	}

	public function diminutive_provider(): array {
		return array(
			// The workbook has a short form; MapRun has the formal one.
			'rob/robert'    => array( 'Rob', 'Robert' ),
			'dan/daniel'    => array( 'Dan', 'Daniel' ),
			'mike/michael'  => array( 'Mike', 'Michael' ),
			'tony/anthony'  => array( 'Tony', 'Anthony' ),
			'vicki/victoria' => array( 'Vicki', 'Victoria' ),
			'sue/susan'     => array( 'Sue', 'Susan' ),
			'jenny/jennifer' => array( 'Jenny', 'Jennifer' ),
		);
	}

	public function test_ambiguous_short_forms_are_left_alone(): void {
		// "Sam" is both Samuel and Samantha. Guessing across genders is exactly
		// the mistake this list must not make, so it is absent from it.
		$this->assertSame( 'sam', Name_Matcher::canonical_first_name( 'Sam' ) );
	}

	public function test_rob_mccaffrey_matches_robert_mccaffrey(): void {
		// The headline real case: same person, two spellings, two data sources.
		$matcher = new Name_Matcher();

		$ranked = $matcher->rank(
			$this->person( 'Robert', 'Yardley', 'MVOC', 1967 ),
			array( array( 'id' => 1 ) + $this->person( 'Rob', 'Yardley', 'MVOC' ) )
		);

		$this->assertCount( 1, $ranked );
		$this->assertSame( 1, $ranked[0]['competitor']['id'] );
		$this->assertContains( 'first name matches as a short form', $ranked[0]['reasons'] );
	}

	public function test_an_exact_match_scores_higher_than_a_short_form(): void {
		$matcher = new Name_Matcher();

		$ranked = $matcher->rank(
			$this->person( 'Robert', 'Yardley' ),
			array(
				array( 'id' => 1 ) + $this->person( 'Rob', 'Yardley' ),
				array( 'id' => 2 ) + $this->person( 'Robert', 'Yardley' ),
			)
		);

		$this->assertSame( 2, $ranked[0]['competitor']['id'] );
	}

	public function test_two_people_with_one_name_both_surface_as_candidates(): void {
		// Year of birth used to separate these decisively. It is no longer
		// stored - holding every member's date of birth to occasionally split a
		// pair of namesakes was not a fair trade - so both are now offered and
		// the co-ordinator picks. Nothing is ever merged automatically, which
		// is what makes that acceptable rather than dangerous.
		$matcher = new Name_Matcher();

		$ranked = $matcher->rank(
			$this->person( 'Ian', 'Fenwick', 'MV' ),
			array(
				array( 'id' => 1 ) + $this->person( 'Ian', 'Fenwick', 'MV' ),
				array( 'id' => 2 ) + $this->person( 'Ian', 'Fenwick', 'SLOW' ),
			)
		);

		$this->assertCount( 2, $ranked, 'both namesakes should be offered' );
	}

	public function test_the_same_club_breaks_the_tie_between_namesakes(): void {
		// Club is the only corroborating signal left, so it decides the order -
		// while still never being strong enough to rule anyone out.
		$matcher = new Name_Matcher();

		$ranked = $matcher->rank(
			$this->person( 'Ian', 'Fenwick', 'MVOC' ),
			array(
				array( 'id' => 1 ) + $this->person( 'Ian', 'Fenwick', 'SLOW' ),
				array( 'id' => 2 ) + $this->person( 'Ian', 'Fenwick', 'MV' ),
			)
		);

		$this->assertSame( 2, $ranked[0]['competitor']['id'] );
		$this->assertContains( 'same club', $ranked[0]['reasons'] );
	}

	public function test_a_year_of_birth_on_the_row_is_ignored(): void {
		// MapRun still supplies one, but nothing is stored to compare it with,
		// so it must not affect scoring either way.
		$matcher = new Name_Matcher();

		$with    = $matcher->rank(
			$this->person( 'Jane', 'Pargeter', 'MVOC', 1970 ),
			array( array( 'id' => 1 ) + $this->person( 'Jane', 'Pargeter', 'MVOC' ) )
		);
		$without = $matcher->rank(
			$this->person( 'Jane', 'Pargeter', 'MVOC' ),
			array( array( 'id' => 1 ) + $this->person( 'Jane', 'Pargeter', 'MVOC' ) )
		);

		$this->assertSame( $without[0]['score'], $with[0]['score'] );
	}

	public function test_unrelated_names_are_not_suggested(): void {
		$matcher = new Name_Matcher();

		$ranked = $matcher->rank(
			$this->person( 'Tim', 'Orpington' ),
			array(
				array( 'id' => 1 ) + $this->person( 'Kate', 'Dalrymple' ),
				array( 'id' => 2 ) + $this->person( 'Petra', 'Loveridge' ),
			)
		);

		$this->assertSame( array(), $ranked );
	}

	public function test_a_typo_in_a_surname_still_surfaces(): void {
		// A surname misspelt by one letter, which happens across the
		// club's own records.
		$matcher = new Name_Matcher();

		$ranked = $matcher->rank(
			$this->person( 'Tim', 'Orpington', 'MV' ),
			array( array( 'id' => 1 ) + $this->person( 'Tim', 'Merriweather', 'MV' ) )
		);

		$this->assertCount( 1, $ranked );
	}

	/**
	 * @dataProvider club_provider
	 */
	public function test_club_aliases_resolve( string $spelling ): void {
		$matcher = new Name_Matcher();

		$this->assertSame( 'mvoc', $matcher->normalise_club( $spelling ) );
	}

	public function club_provider(): array {
		// Every one of these appears in the real Worcester Park response.
		return array(
			array( 'MV' ),
			array( 'MVOC' ),
			array( 'Mole Valley' ),
			array( 'Mole Valley Orienteering Club' ),
		);
	}

	public function test_different_clubs_stay_different(): void {
		// Club is used to tell two same-named runners apart, so collapsing
		// genuinely different clubs would defeat the purpose.
		$matcher = new Name_Matcher();

		$this->assertNotSame(
			$matcher->normalise_club( 'SLOW' ),
			$matcher->normalise_club( 'HAVOC' )
		);
	}

	public function test_a_club_mismatch_is_not_a_refutation(): void {
		// Runners change clubs and often leave the field blank.
		$matcher = new Name_Matcher();

		$ranked = $matcher->rank(
			$this->person( 'David', 'Wolstenholme', 'HAVOC' ),
			array( array( 'id' => 1 ) + $this->person( 'David', 'Wolstenholme', 'MVOC' ) )
		);

		$this->assertCount( 1, $ranked );
	}

	public function test_extra_club_aliases_can_be_supplied(): void {
		$matcher = new Name_Matcher( array( 'South London Orienteers' => 'SLOW' ) );

		$this->assertSame(
			$matcher->normalise_club( 'SLOW' ),
			$matcher->normalise_club( 'South London Orienteers' )
		);
	}

	public function test_alias_key_is_stable_across_spellings(): void {
		$this->assertSame(
			Name_Matcher::alias_key( 'Sybil', "O'Halloran" ),
			Name_Matcher::alias_key( ' sybil ', 'OHalloran' )
		);
	}

	public function test_candidates_are_ranked_best_first(): void {
		$matcher = new Name_Matcher();

		$ranked = $matcher->rank(
			$this->person( 'David', 'Wolstenholme', 'MVOC', 1969 ),
			array(
				array( 'id' => 1 ) + $this->person( 'Dave', 'Wolstenholme', '', null ),
				array( 'id' => 2 ) + $this->person( 'David', 'Wolstenholme', 'MVOC', 1969 ),
			)
		);

		$this->assertSame( 2, $ranked[0]['competitor']['id'] );
		$this->assertGreaterThanOrEqual( Name_Matcher::STRONG_THRESHOLD, $ranked[0]['score'] );
	}
}
