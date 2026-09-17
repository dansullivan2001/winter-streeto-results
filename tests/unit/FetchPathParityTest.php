<?php
/**
 * Tests that fetching over HTTP and pasting the same JSON are one path.
 *
 * The claim is made in three places — the Client's own docblock, the setup
 * screen's "use Paste JSON, which produces exactly the same result", and the
 * Help screen — and until now it rested on reading `fetch()` and seeing that
 * its last line hands the body to `ingest()`. That is true, and it is exactly
 * the kind of true that a later edit quietly ends: a fix applied to one path
 * only, a header or a flag read on the way past, and the two paths diverge
 * with nothing failing.
 *
 * It matters most for the thing the paste route exists for. A host that blocks
 * MapRun's port 8886 has only the paste; a host that does not will mostly use
 * the fetch. If the punch recovery — the repair for an event MapRun would not
 * score, where every finisher comes back MP on nil points — worked on one and
 * not the other, whichever the co-ordinator was not using that night would
 * publish a field of nobodies.
 *
 * So `request()` is overridden here to answer from a stored response, and the
 * whole of `fetch()` above it runs for real.
 *
 * @package MVOC_StreetO
 */

use MVOC\StreetO\Importer;
use MVOC\StreetO\MapRun\Client;
use MVOC\StreetO\MapRun\Parser;
use PHPUnit\Framework\TestCase;

/**
 * A client whose one HTTP call is answered from a fixture.
 */
class Recorded_Client extends Client {

	/**
	 * The URL fetch() asked for, so the request itself can be asserted.
	 */
	public string $requested = '';

	private string $body;

	private int $code;

	/**
	 * @param string $body Response body to return.
	 * @param int    $code HTTP status to report.
	 */
	public function __construct( string $body, int $code = 200 ) {
		$this->body = $body;
		$this->code = $code;
	}

	/**
	 * @param string $url Fully built request URL.
	 * @return array{code:int,body:string}
	 */
	protected function request( string $url ): array {
		$this->requested = $url;

		return array(
			'code' => $this->code,
			'body' => $this->body,
		);
	}
}

/**
 * @covers \MVOC\StreetO\MapRun\Client::fetch
 */
class FetchPathParityTest extends TestCase {

	/**
	 * The Burpham response: the event MapRun would not score.
	 */
	private function burpham(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-burpham-unscored.json' );
	}

	/**
	 * The whole of it, in one assertion: same payload, same rows, same warning.
	 */
	public function test_a_fetch_returns_exactly_what_a_paste_of_the_same_response_returns(): void {
		$payload = $this->burpham();

		$fetched = ( new Recorded_Client( $payload ) )->fetch( 'Burpham Sep26 PXAS Score Q60' );
		$pasted  = ( new Client() )->ingest( $payload );

		$this->assertSame( $pasted, $fetched );
	}

	/**
	 * The response a browser is sent to, which is what makes a paste possible.
	 *
	 * Asserted here rather than left to url_for()'s own test because the
	 * equality above is only worth anything if both paths are asking MapRun the
	 * same question.
	 */
	public function test_the_fetch_asks_for_the_url_the_co_ordinator_would_paste_from(): void {
		$client = new Recorded_Client( $this->burpham() );
		$client->fetch( '  Burpham Sep26 PXAS Score Q60  ' );

		$this->assertSame(
			Client::url_for( 'Burpham Sep26 PXAS Score Q60' ),
			$client->requested
		);
	}

	/**
	 * The repair the paste route was proved on, reaching the fetched rows.
	 *
	 * Parsing is the importer's step rather than the client's, so this asserts
	 * what actually matters: the rows a fetch produces go through the same
	 * parser and come out with the same scores rebuilt from the punches.
	 */
	public function test_the_punch_recovery_reaches_a_fetched_response_too(): void {
		$fetched = ( new Recorded_Client( $this->burpham() ) )->fetch( 'Burpham Sep26 PXAS Score Q60' );
		$rows    = ( new Parser() )->parse( $fetched['rows'] );

		$this->assertSame( 4, Importer::count_recovered( $rows ) );

		$fenwick = array_values(
			array_filter( $rows, static fn( array $row ): bool => 'Fenwick' === $row['surname'] )
		);

		$this->assertSame( 1150, $fenwick[0]['score'] );
		$this->assertSame( Parser::SCORE_FIELD_PUNCHES, $fenwick[0]['score_field'] );
	}

	/**
	 * A warning is not an error, on either path.
	 *
	 * "Multiple events found" is the one the real responses carry, and it must
	 * travel with a fetch exactly as it does with a paste — it is how the
	 * review screen comes to show it.
	 */
	public function test_a_warning_travels_the_fetch_path(): void {
		$payload = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-worcester-park.json' );
		$fetched = ( new Recorded_Client( $payload ) )->fetch( 'Worcester Park Nov26 PXAS ScoreQ60' );

		$this->assertSame( 'Multiple events found ... There should be only one.', $fetched['warning'] );
		$this->assertCount( 16, $fetched['rows'] );
	}

	/**
	 * And an error MapRun reports in a 200 stops the fetch, as it stops a paste.
	 */
	public function test_a_maprun_error_stops_the_fetch_as_it_stops_a_paste(): void {
		$payload = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/maprun-error.json' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'MapRun API error: Event not found' );

		( new Recorded_Client( $payload ) )->fetch( 'Nowhere Jan26 ScoreQ60' );
	}

	/**
	 * An event name is required before anything is requested.
	 */
	public function test_an_empty_event_name_never_reaches_the_network(): void {
		$client = new Recorded_Client( $this->burpham() );

		try {
			$client->fetch( '   ' );
			$this->fail( 'an empty event name should throw' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'No MapRun event name given.', $e->getMessage() );
		}

		$this->assertSame( '', $client->requested );
	}
}
