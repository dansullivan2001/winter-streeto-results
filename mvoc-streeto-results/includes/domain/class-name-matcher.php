<?php
/**
 * Matches a MapRun result row to a known competitor.
 *
 * The problem is real and recurring: the same person turns up as "Dave Smith"
 * in the club's spreadsheet and "David Smith" in MapRun, and enters their club
 * as any of "MV", "MVOC", "Mole Valley" or "Mole Valley Orienteering Club". Getting this wrong in either direction is costly — a
 * missed match splits someone's league points across two identities, and a
 * wrong match hands one runner another's score.
 *
 * So this class only ever *suggests*. Ranked candidates go to the co-ordinator,
 * who confirms; the confirmation is then stored as an alias, and that spelling
 * never needs deciding again.
 *
 * Deliberately free of WordPress dependencies so it can be unit-tested with
 * plain PHPUnit.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Normalises names and ranks candidate competitors.
 */
class Name_Matcher {

	/**
	 * Minimum score for a competitor to be worth showing as a suggestion.
	 *
	 * Set so that a clear surname match with a plausible first name surfaces,
	 * while unrelated names do not. Suggestions below this are noise, and noise
	 * trains the co-ordinator to click through without reading.
	 */
	public const SUGGESTION_THRESHOLD = 55;

	/**
	 * Score at or above which a suggestion is strong enough to pre-select.
	 *
	 * Still never applied automatically — it only controls emphasis in the UI.
	 */
	public const STRONG_THRESHOLD = 90;

	/**
	 * Common British diminutives, mapped to their formal form.
	 *
	 * Only pairs where the short form is unambiguous in practice. "Sam" is
	 * deliberately absent: it maps to both Samuel and Samantha, and guessing
	 * across genders is exactly the error this list must not introduce.
	 *
	 * @var array<string,string>
	 */
	private const DIMINUTIVES = array(
		'bob'    => 'robert',
		'rob'    => 'robert',
		'bobby'  => 'robert',
		'dan'    => 'daniel',
		'danny'  => 'daniel',
		'dave'   => 'david',
		'mike'   => 'michael',
		'mick'   => 'michael',
		'tony'   => 'anthony',
		'jenny'  => 'jennifer',
		'jen'    => 'jennifer',
		'jim'    => 'james',
		'jimmy'  => 'james',
		'tom'    => 'thomas',
		'tommy'  => 'thomas',
		'nick'   => 'nicholas',
		'chris'  => 'christopher',
		'steve'  => 'stephen',
		'phil'   => 'philip',
		'pete'   => 'peter',
		'andy'   => 'andrew',
		'ed'     => 'edward',
		'eddie'  => 'edward',
		'ben'    => 'benjamin',
		'matt'   => 'matthew',
		'greg'   => 'gregory',
		'ian'    => 'iain',
		'kate'   => 'katherine',
		'katie'  => 'katherine',
		'cathy'  => 'catherine',
		'sue'    => 'susan',
		'liz'    => 'elizabeth',
		'beth'   => 'elizabeth',
		'vicki'  => 'victoria',
		'vicky'  => 'victoria',
		'debbie' => 'deborah',
		'deb'    => 'deborah',
		'chrissy' => 'christine',
		'abi'    => 'abigail',
		'gemma'  => 'gemma',
	);

	/**
	 * Characters that need folding to ASCII before comparison.
	 *
	 * A small explicit map rather than iconv, whose //TRANSLIT behaviour varies
	 * by platform and would make matching depend on the host's locale.
	 *
	 * @var array<string,string>
	 */
	private const ACCENT_MAP = array(
		'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
		'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
		'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
		'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
		'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
		'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
		'æ' => 'ae', 'œ' => 'oe',
	);

	/**
	 * Club spellings that mean the same club.
	 *
	 * Kept small and explicit. Over-normalising clubs would be worse than not
	 * normalising at all: club is used to tell two same-named runners apart, so
	 * collapsing genuinely different clubs would defeat the point.
	 *
	 * @var array<string,string>
	 */
	private array $club_aliases = array(
		'mv'                            => 'mvoc',
		'mvoc'                          => 'mvoc',
		'mole valley'                   => 'mvoc',
		'mole valley oc'                => 'mvoc',
		'mole valley orienteering club' => 'mvoc',
	);

	/**
	 * @param array<string,string> $club_aliases Optional extra club spellings.
	 */
	public function __construct( array $club_aliases = array() ) {
		foreach ( $club_aliases as $spelling => $canonical ) {
			$this->club_aliases[ self::normalise( (string) $spelling ) ] = self::normalise( (string) $canonical );
		}
	}

	/**
	 * Reduce a name to a comparable form.
	 *
	 * Casefolds, folds accents, turns hyphens into spaces so a hyphenated
	 * surname matches its spaced form, drops apostrophes so "O'Brien" and
	 * "OBrien" agree, and collapses whitespace.
	 *
	 * @param string $name Raw name.
	 */
	public static function normalise( string $name ): string {
		$name = strtr( mb_strtolower( trim( $name ), 'UTF-8' ), self::ACCENT_MAP );
		$name = str_replace( array( "'", '’', '.', ',' ), '', $name );
		$name = (string) preg_replace( '/[^a-z0-9]+/', ' ', $name );

		return trim( (string) preg_replace( '/\s+/', ' ', $name ) );
	}

	/**
	 * Normalise a club name, resolving known alternative spellings.
	 *
	 * @param string $club Raw club name.
	 */
	public function normalise_club( string $club ): string {
		$normalised = self::normalise( $club );

		return $this->club_aliases[ $normalised ] ?? $normalised;
	}

	/**
	 * Reduce a first name to its formal form where the short form is known.
	 *
	 * @param string $first_name Raw first name.
	 */
	public static function canonical_first_name( string $first_name ): string {
		$normalised = self::normalise( $first_name );

		return self::DIMINUTIVES[ $normalised ] ?? $normalised;
	}

	/**
	 * The key used for exact alias lookups: normalised "first surname".
	 *
	 * @param string $first_name First name.
	 * @param string $surname    Surname.
	 */
	public static function alias_key( string $first_name, string $surname ): string {
		return trim( self::normalise( $first_name ) . ' ' . self::normalise( $surname ) );
	}

	/**
	 * Rank known competitors as candidates for a parsed result row.
	 *
	 * @param array<string,mixed>            $row         Parsed MapRun row.
	 * @param array<int,array<string,mixed>> $competitors Known competitors.
	 * @return array<int,array{competitor:array<string,mixed>,score:int,reasons:string[]}>
	 */
	public function rank( array $row, array $competitors ): array {
		$ranked = array();

		foreach ( $competitors as $competitor ) {
			$assessment = $this->score( $row, $competitor );

			if ( $assessment['score'] >= self::SUGGESTION_THRESHOLD ) {
				$ranked[] = array(
					'competitor' => $competitor,
					'score'      => $assessment['score'],
					'reasons'    => $assessment['reasons'],
				);
			}
		}

		usort( $ranked, static fn( array $a, array $b ): int => $b['score'] <=> $a['score'] );

		return $ranked;
	}

	/**
	 * Score one competitor against one row.
	 *
	 * Weighted so that the surname dominates and the first name confirms, with
	 * the club as a mild corroborator.
	 *
	 * Year of birth used to be the decisive signal here — two people sharing a
	 * name rarely share one — but it is no longer stored, because holding every
	 * member's date of birth to occasionally separate a pair of namesakes was
	 * not a fair trade. Matching is a little weaker for genuine namesakes as a
	 * result, which is tolerable precisely because nothing is ever merged
	 * automatically: a suggestion always waits for a human.
	 *
	 * @param array<string,mixed> $row        Parsed MapRun row.
	 * @param array<string,mixed> $competitor Known competitor.
	 * @return array{score:int,reasons:string[]}
	 */
	private function score( array $row, array $competitor ): array {
		$reasons = array();

		$row_surname        = self::normalise( (string) ( $row['surname'] ?? '' ) );
		$competitor_surname = self::normalise( (string) ( $competitor['surname'] ?? '' ) );

		$surname_similarity = self::similarity( $row_surname, $competitor_surname );
		$score              = (int) round( $surname_similarity * 0.55 );

		if ( $row_surname === $competitor_surname && '' !== $row_surname ) {
			$reasons[] = 'surname matches';
		}

		$row_first        = self::canonical_first_name( (string) ( $row['first_name'] ?? '' ) );
		$competitor_first = self::canonical_first_name( (string) ( $competitor['first_name'] ?? '' ) );

		if ( '' !== $row_first && $row_first === $competitor_first ) {
			$literal = self::normalise( (string) ( $row['first_name'] ?? '' ) )
				=== self::normalise( (string) ( $competitor['first_name'] ?? '' ) );

			// A literal match outranks one reached through the diminutives
			// table, so that where both a "Dave" and a "David" are on file,
			// the formal spelling is suggested first rather than whichever
			// happens to come back from the database earlier.
			$score    += $literal ? 30 : 27;
			$reasons[] = $literal ? 'first name matches' : 'first name matches as a short form';
		} else {
			$score += (int) round( self::similarity( $row_first, $competitor_first ) * 0.20 );
		}

		$score += $this->score_club( $row, $competitor, $reasons );

		return array(
			'score'   => max( 0, min( 100, $score ) ),
			'reasons' => $reasons,
		);
	}

	/**
	 * Club contribution: a mild confirmation only.
	 *
	 * Weighted a little higher now that year of birth is gone, but still only a
	 * confirmation. Runners change clubs and often leave the field blank, so a
	 * mismatch remains weak evidence and must never be a refutation.
	 *
	 * @param array<string,mixed> $row        Parsed row.
	 * @param array<string,mixed> $competitor Known competitor.
	 * @param string[]            $reasons    Accumulated explanation, by reference.
	 */
	private function score_club( array $row, array $competitor, array &$reasons ): int {
		$row_club        = $this->normalise_club( (string) ( $row['club'] ?? '' ) );
		$competitor_club = $this->normalise_club( (string) ( $competitor['club'] ?? '' ) );

		if ( '' === $row_club || '' === $competitor_club ) {
			return 0;
		}

		if ( $row_club === $competitor_club ) {
			$reasons[] = 'same club';

			return 10;
		}

		return 0;
	}

	/**
	 * Percentage similarity of two normalised strings, 0-100.
	 *
	 * @param string $a First string.
	 * @param string $b Second string.
	 */
	public static function similarity( string $a, string $b ): float {
		if ( '' === $a && '' === $b ) {
			return 100.0;
		}

		if ( '' === $a || '' === $b ) {
			return 0.0;
		}

		if ( $a === $b ) {
			return 100.0;
		}

		$percent = 0.0;
		similar_text( $a, $b, $percent );

		return $percent;
	}
}
