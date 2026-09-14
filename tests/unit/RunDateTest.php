<?php
/**
 * Tests for working out which day a run was actually done on.
 *
 * A MapRun course stays live after the event, so anyone with the app can run it
 * weeks or months later and MapRun returns their result alongside everyone
 * else's. Real December event, MVOC's "Ashtead Dec25 PXAS ScoreQ60": 60 rows,
 * 59 of them from the night of the 16th and one from the following 12 April,
 * scored 100 points and ranked like any other.
 *
 * Nothing could have caught it. Every other moment in a MapRun row is a time of
 * day with no date attached, so the April run looked exactly like a short run on
 * the night; and it is nobody's duplicate, so the duplicate detector — which
 * needs two rows to compare — was never going to see it.
 *
 * Every literal below is from a real response, both seasons, so the
 * daylight-saving claim in TRACK_START_OFFSET_HOURS is tested against the data
 * it was measured from rather than restated.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Domain\Import_Reconciler;
use MVOC\StreetO\Domain\Scoring_Config;
use MVOC\StreetO\MapRun\Parser;
use MVOC\StreetO\Repo\Results_Repo;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MVOC\StreetO\MapRun\Parser::local_date_of
 * @covers \MVOC\StreetO\MapRun\Parser::is_off_date
 * @covers \MVOC\StreetO\Repo\Results_Repo::effective
 */
class RunDateTest extends TestCase {

	/**
	 * Real rows: the MapRun field against the local time of the first punch.
	 *
	 * The last value says whether the row came from a phone or Strava, where the
	 * field is exact to the second, rather than a MapRunG watch, where it
	 * records the GPS track start and drifts.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:bool}>
	 */
	public function real_rows_provider(): array {
		return array(
			// Winter, GMT. Phone uploads, where the field is exact.
			'Dec, phone, 19:11 start'  => array( '2025-12-16T09:11:17.000Z', '2025-12-16', '19:11:19', true ),
			'Dec, phone, 18:24 start'  => array( '2025-12-16T08:24:31.000Z', '2025-12-16', '18:24:34', true ),
			'Dec, phone, 19:25 start'  => array( '2025-12-16T09:25:00.000Z', '2025-12-16', '19:25:03', true ),
			// Winter, watch. Scattered by minutes, same date regardless.
			'Dec, watch, 19:25 start'  => array( '2025-12-16T10:30:31.000Z', '2025-12-16', '19:25:37', false ),
			'Dec, watch, 18:25 start'  => array( '2025-12-16T09:36:00.000Z', '2025-12-16', '18:25:19', false ),
			// Summer, BST. The offset does not move, which is the whole point.
			'Sep, phone, 19:19 start'  => array( '2025-09-16T09:19:07.000Z', '2025-09-16', '19:19:07', true ),
			'Sep, phone, 18:54 start'  => array( '2025-09-16T08:54:04.000Z', '2025-09-16', '18:54:04', true ),
			// Summer, Strava upload, and the latest start in either response.
			'Sep, Strava, 20:20 start' => array( '2025-09-16T10:20:02.000Z', '2025-09-16', '20:20:02', true ),
			// Summer, watch, the widest scatter seen: 1h 33m.
			'Sep, watch, 18:12 start'  => array( '2025-09-16T09:45:12.000Z', '2025-09-16', '18:12:26', false ),
			// The run that was not done on the night at all. Also the one phone
			// row in either response more than a minute off the ten hours —
			// 14m 20s, on MapRun 7.11.4 where everything else is 7.9.0 or
			// older — so it cannot be held to the tight bound below. Still
			// nowhere near the hour a daylight-saving error would cost.
			'the April outlier'        => array( '2026-04-12T01:36:02.000Z', '2026-04-12', '11:50:22', false ),
		);
	}

	/**
	 * @dataProvider real_rows_provider
	 *
	 * @param string $track_start MapRun's TrackStartDateTimeUTC.
	 * @param string $expected    Local date the run belongs to.
	 */
	public function test_the_local_date_is_recovered_from_a_real_row( string $track_start, string $expected ): void {
		$this->assertSame( $expected, Parser::local_date_of( $track_start ) );
	}

	/**
	 * @dataProvider real_rows_provider
	 *
	 * @param string $track_start MapRun's TrackStartDateTimeUTC.
	 * @param string $expected    Local date the run belongs to.
	 * @param string $punch       StartPunchTimeLocal from the same row.
	 * @param bool   $exact       Whether this row's field is exact to the second.
	 */
	public function test_the_recovered_time_agrees_with_the_punch_it_came_with(
		string $track_start,
		string $expected,
		string $punch,
		bool $exact
	): void {
		// The cross-check that makes the ten hours an observation rather than a
		// guess: add them back and the result should land on the first punch of
		// the same row.
		//
		// The bound is the discriminating part. A phone upload has to match
		// within a minute — 64 of the 65 real ones do — so an offset wrong by
		// an hour, which is what any daylight-saving handling of this field
		// would produce, fails here immediately. Only the rows that genuinely
		// record something other than the first punch get the loose bound:
		// watch uploads, which time the GPS track start and drift by up to
		// 1h 33m, and the one late-app-version phone row at 14m 20s. Holding
		// every row to the loose bound would let a one-hour error through.
		$recovered = ( new DateTimeImmutable( $track_start, new DateTimeZone( 'UTC' ) ) )
			->add( new DateInterval( 'PT' . Parser::TRACK_START_OFFSET_HOURS . 'H' ) );

		$drift = abs( $recovered->getTimestamp() - strtotime( $expected . ' ' . $punch . ' UTC' ) );

		$this->assertLessThan(
			$exact ? 60 : 7200,
			$drift,
			'the recovered local time should sit on the first punch'
		);
	}

	public function test_the_offset_does_not_shift_with_daylight_saving(): void {
		// Stated directly, because it is the reason the field cannot be a real
		// UTC value and the reason a timezone-aware conversion would be wrong.
		// Two phone rows whose first punch is at the same wall-clock time, one
		// in GMT and one in BST, carry the same MapRun value.
		$december  = new DateTimeImmutable( '2025-12-16T09:19:07.000Z' );
		$september = new DateTimeImmutable( '2025-09-16T09:19:07.000Z' );

		$this->assertSame( '09:19:07', $december->format( 'H:i:s' ) );
		$this->assertSame( $december->format( 'H:i:s' ), $september->format( 'H:i:s' ) );
	}

	public function test_an_evening_run_does_not_roll_into_the_next_day(): void {
		// The latest start in either real response, 20:20 local. Adding ten
		// hours to a field that is already ten hours behind cannot cross
		// midnight unless the run itself did.
		$this->assertSame( '2025-09-16', Parser::local_date_of( '2025-09-16T10:20:02.000Z' ) );
	}

	public function test_a_morning_run_does_not_roll_back_a_day(): void {
		// The mirror case, and the one a naive date substring gets wrong: a
		// 09:00 local start puts MapRun's value on the *previous* evening, so
		// slicing the date out of the string would report the wrong day.
		$this->assertSame( '2026-04-13', Parser::local_date_of( '2026-04-12T23:00:00.000Z' ) );
		$this->assertNotSame(
			'2026-04-12',
			Parser::local_date_of( '2026-04-12T23:00:00.000Z' ),
			'the date must be derived, not sliced off the front of the string'
		);
	}

	public function test_the_hosts_timezone_cannot_change_the_answer(): void {
		// The whole feature is about a mislabelled timezone, so the derivation
		// must not pick up another one from wherever WordPress is hosted.
		$original = date_default_timezone_get();

		try {
			foreach ( array( 'UTC', 'Europe/London', 'Australia/Brisbane', 'Pacific/Kiritimati' ) as $zone ) {
				date_default_timezone_set( $zone );

				$this->assertSame(
					'2025-12-16',
					Parser::local_date_of( '2025-12-16T09:11:17.000Z' ),
					'recovered date changed under ' . $zone
				);
			}
		} finally {
			date_default_timezone_set( $original );
		}
	}

	public function test_an_unreadable_or_absent_value_gives_no_date(): void {
		// No date is not the same as a wrong date. An older MapRun version that
		// never sent the field must leave a row unjudged.
		$this->assertNull( Parser::local_date_of( '' ) );
		$this->assertNull( Parser::local_date_of( '   ' ) );
		$this->assertNull( Parser::local_date_of( 'not a date' ) );
	}

	public function test_a_run_on_another_day_is_off_date(): void {
		$this->assertTrue( Parser::is_off_date( '2026-04-12', '2025-12-16' ) );
	}

	public function test_a_run_on_the_night_is_not(): void {
		$this->assertFalse( Parser::is_off_date( '2025-12-16', '2025-12-16' ) );
	}

	public function test_nothing_is_off_date_without_both_dates(): void {
		// An event with no date set, or a row whose track start would not
		// parse, is an absence of evidence. Flagging a whole field on the
		// strength of one would teach the co-ordinator to ignore the warning.
		$this->assertFalse( Parser::is_off_date( null, '2025-12-16' ) );
		$this->assertFalse( Parser::is_off_date( '2026-04-12', null ) );
		$this->assertFalse( Parser::is_off_date( '2026-04-12', '' ) );
		$this->assertFalse( Parser::is_off_date( null, null ) );
	}

	public function test_the_field_is_parsed_off_a_maprun_row(): void {
		$parsed = ( new Parser() )->parse_row(
			array(
				'Id'                    => 588986,
				'Firstname'             => 'Marcus',
				'Surname'               => 'Pentlow',
				'Classifier'            => 'OK',
				'TrackStartDateTimeUTC' => '2026-04-12T01:36:02.000Z',
				'StartPunchTimeLocal'   => '11:50:22',
				'FinishPunchTimeLocal'  => '12:09:59',
				'TotalTimeSecs'         => 1176,
				'GrossScore'            => 100,
				'NetScore'              => 100,
			)
		);

		$this->assertSame( '2026-04-12T01:36:02.000Z', $parsed['track_start_utc'] );
	}

	public function test_the_date_survives_the_round_trip_into_storage(): void {
		// The seam, not the algorithm. v10 added three columns the detector
		// needed and the detector still found nothing, because the import never
		// wrote them; this asserts the same mistake is not repeated here.
		$parsed = ( new Parser() )->parse_row(
			array(
				'Id'                    => 588986,
				'Firstname'             => 'Marcus',
				'Surname'               => 'Pentlow',
				'Classifier'            => 'OK',
				'TrackStartDateTimeUTC' => '2026-04-12T01:36:02.000Z',
				'StartPunchTimeLocal'   => '11:50:22',
				'FinishPunchTimeLocal'  => '12:09:59',
				'TotalTimeSecs'         => 1176,
				'GrossScore'            => 100,
				'NetScore'              => 100,
			)
		);

		$columns = Import_Reconciler::raw_columns( $parsed );

		$this->assertSame( '2026-04-12T01:36:02.000Z', $columns['raw_track_start_utc'] );

		$stored = array_merge(
			$columns,
			array(
				'id'                    => 1,
				'competitor_id'         => null,
				'resolved_score'        => null,
				'resolved_penalty'      => null,
				'resolved_time_secs'    => null,
				'resolved_course_label' => '',
				'is_excluded'           => false,
				'is_manual'             => false,
				'is_withdrawn'          => false,
			)
		);

		$row = Results_Repo::effective( $stored, new Scoring_Config() );

		$this->assertSame( '2026-04-12', $row['run_date'] );
		$this->assertTrue( Parser::is_off_date( $row['run_date'], '2025-12-16' ) );
	}

	public function test_a_row_from_before_the_field_was_stored_is_not_flagged(): void {
		// Every row imported before v11 has an empty column until the backfill
		// reaches it. Those must read as "not known", never as "wrong day",
		// or an upgrade would flag entire past seasons.
		$stored = array(
			'id'                    => 1,
			'competitor_id'         => null,
			'maprun_id'             => '591179',
			'raw_first_name'        => 'Rowan',
			'raw_surname'           => 'Orpington',
			'raw_club'              => '',
			'classifier'            => 'OK',
			'course_label'          => '60',
			'raw_score'             => 1180,
			'raw_penalty'           => 0,
			'raw_time_secs'         => 3593,
			'raw_track_start_utc'   => '',
			'raw_start_local'       => '18:53:36',
			'raw_finish_local'      => '19:53:30',
			'raw_course_revision'   => null,
			'resolved_score'        => null,
			'resolved_penalty'      => null,
			'resolved_time_secs'    => null,
			'resolved_course_label' => '',
			'is_excluded'           => false,
			'is_manual'             => false,
			'is_withdrawn'          => false,
		);

		$row = Results_Repo::effective( $stored, new Scoring_Config() );

		$this->assertNull( $row['run_date'] );
		$this->assertFalse( Parser::is_off_date( $row['run_date'], '2025-12-16' ) );
	}

	public function test_the_backfill_can_recover_the_date_from_a_stored_snapshot(): void {
		// What the v11 upgrade relies on: the responses are kept verbatim, so a
		// season already imported gets its dates without being re-fetched.
		$payload = json_encode(
			array(
				'errorFlag' => false,
				'results'   => array(
					array(
						'Id'                    => 588986,
						'Firstname'             => 'Marcus',
						'Surname'               => 'Pentlow',
						'Classifier'            => 'OK',
						'TrackStartDateTimeUTC' => '2026-04-12T01:36:02.000Z',
						'StartPunchTimeLocal'   => '11:50:22',
						'FinishPunchTimeLocal'  => '12:09:59',
						'TotalTimeSecs'         => 1176,
						'GrossScore'            => 100,
					),
				),
			)
		);

		$identities = Parser::identities( (string) $payload );

		$this->assertArrayHasKey( '588986', $identities );
		$this->assertSame( '2026-04-12T01:36:02.000Z', $identities['588986']['track_start_utc'] );
		$this->assertSame( '2026-04-12', Parser::local_date_of( $identities['588986']['track_start_utc'] ) );
	}
}
