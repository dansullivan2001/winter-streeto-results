<?php
/**
 * MapRun API client.
 *
 * Two ingest paths produce identical output:
 *
 *   fetch()  - HTTP GET against the MapRun API.
 *   ingest() - JSON pasted in by hand.
 *
 * The paste path is a first-class equal, not a fallback for emergencies. The
 * MapRun API listens on port 8886, and shared hosting frequently blocks
 * outbound connections to anything but 80/443. If mvoc.org's host is one of
 * those, the HTTP path simply cannot work, and the plugin still has to.
 *
 * @package MVOC_StreetO
 */

namespace MVOC\StreetO\MapRun;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and validates MapRun result payloads.
 */
class Client {

	public const API_HOST = 'p.fne.com.au';
	public const API_PORT = 8886;
	public const API_URL  = 'https://p.fne.com.au:8886/resultsGetPublicForEventv2';

	/**
	 * Request timeout in seconds.
	 */
	private const TIMEOUT = 20;

	/**
	 * Fetch results for a MapRun event over HTTP.
	 *
	 * @param string $event_name Full MapRun event name, as published.
	 * @return array{payload:string,rows:array<int,array<string,mixed>>}
	 * @throws \RuntimeException On transport failure, HTTP error or bad payload.
	 */
	public function fetch( string $event_name ): array {
		$event_name = trim( $event_name );
		if ( '' === $event_name ) {
			throw new \RuntimeException( 'No MapRun event name given.' );
		}

		// rawurlencode() here is deliberate, not a double-encode: add_query_arg()
		// runs urlencode_deep() over the URL's *existing* query string only, and
		// merges array arguments in afterwards untouched. MapRun event names
		// contain spaces, so without this they would go out raw.
		$url = add_query_arg( array( 'eventName' => rawurlencode( $event_name ) ), self::API_URL );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Could not reach MapRun: %s. If this host blocks outbound port 8886, use Paste JSON instead.', 'mvoc-streeto' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'MapRun returned HTTP %d.', 'mvoc-streeto' ),
					$code
				)
			);
		}

		return $this->ingest( (string) wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Validate and parse a raw JSON payload, however it was obtained.
	 *
	 * @param string $payload Raw JSON text.
	 * @return array{payload:string,rows:array<int,array<string,mixed>>}
	 * @throws \RuntimeException If the payload is not usable.
	 */
	public function ingest( string $payload ): array {
		$payload = trim( $payload );
		if ( '' === $payload ) {
			throw new \RuntimeException( __( 'The MapRun response was empty.', 'mvoc-streeto' ) );
		}

		$decoded = json_decode( $payload, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: JSON parser error message. */
					__( 'Could not read the MapRun response as JSON: %s', 'mvoc-streeto' ),
					json_last_error_msg()
				)
			);
		}

		return array(
			'payload' => $payload,
			'rows'    => Parser::unwrap( $decoded ),
		);
	}

	/**
	 * Report whether this host can reach the MapRun API at all.
	 *
	 * Run once at setup. A failure here is not a bug to debug in the plugin —
	 * it means the club's hosting blocks the port, and the co-ordinator should
	 * use the Paste JSON path.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function check_connectivity(): array {
		$response = wp_remote_get(
			add_query_arg( array( 'eventName' => 'mvoc-streeto-connectivity-check' ), self::API_URL ),
			array( 'timeout' => self::TIMEOUT )
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: host and port, 2: error message. */
					__( 'This site cannot reach %1$s (%2$s). Automatic fetching will not work here — use Paste JSON.', 'mvoc-streeto' ),
					self::API_HOST . ':' . self::API_PORT,
					$response->get_error_message()
				),
			);
		}

		// Any HTTP response at all proves the port is open. A MapRun-level
		// error for a nonsense event name is the expected, healthy outcome.
		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: 1: host and port, 2: HTTP status code. */
				__( 'Reached %1$s (HTTP %2$d). Automatic fetching should work.', 'mvoc-streeto' ),
				self::API_HOST . ':' . self::API_PORT,
				(int) wp_remote_retrieve_response_code( $response )
			),
		);
	}
}
