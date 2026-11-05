<?php
/**
 * Site-to-site move, the sending site: reads a key, talks to the receiving site, starts the move.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Move;

use SiteFerry\Export\Export;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Security\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Moving this site: a fresh backup, encrypted with the key's secret, made and then sent straight
 * to the site that made the key (Send_Step). Only that site's address from the key is ever
 * contacted.
 */
final class Sender {

	/** The step that sends the backup. */
	const STEP = 'move.send';

	/** Seconds one request to the other site may take. */
	const TIMEOUT = 120;

	/**
	 * Reads a key: the receiving site's address, the key id and the secret.
	 *
	 * @param string $key Key as copied from the receiving site.
	 * @return array{url:string,id:string,secret:string}
	 * @throws Job_Exception If it is not a key.
	 */
	public static function parse(
		#[\SensitiveParameter]
		string $key
	): array {
		$key  = preg_replace( '/\s+/', '', $key );
		$json = 0 === strpos( (string) $key, Receiver::PREFIX ) ? base64_decode( strtr( substr( (string) $key, strlen( Receiver::PREFIX ) ), '-_', '+/' ), true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || ! isset( $data['u'], $data['i'], $data['s'] )
			|| ! preg_match( '#^https?://[^/\s]+#i', (string) $data['u'] )
			|| ! preg_match( '/^[a-f0-9]{16}$/', (string) $data['i'] )
			|| ! preg_match( '/^[A-Za-z0-9_-]{43}$/', (string) $data['s'] ) ) {
			throw new Job_Exception( 'That is not a move key. Copy the whole key from "Receive a site" on the new site.', Job_Exception::STEP );
		}
		$url = untrailingslashit( (string) $data['u'] );
		if ( untrailingslashit( home_url() ) === $url && ! apply_filters( 'siteferry_move_to_self', false ) ) {
			throw new Job_Exception( 'This key was made on this site. Make the key on the site you are moving to.', Job_Exception::STEP );
		}
		return array(
			'url'    => $url,
			'id'     => (string) $data['i'],
			'secret' => (string) $data['s'],
		);
	}

	/**
	 * Asks the receiving site about itself (and whether the key is still good).
	 *
	 * @param array $key Parsed key.
	 * @throws Job_Exception If it cannot be reached or refuses the key.
	 */
	public static function hello( array $key ): array {
		return self::call( $key, 'GET', 'hello' );
	}

	/**
	 * Starts moving this site: a backup encrypted with the key's secret, then sent.
	 *
	 * @param string $key        Key.
	 * @param bool   $keep_local Keep the backup on this server afterwards.
	 * @return array{0:Job,1:string} The job and its token.
	 */
	public static function start(
		#[\SensitiveParameter]
		string $key,
		bool $keep_local = false
	): array {
		$parsed = self::parse( $key );
		$hello  = self::hello( $parsed );
		$host   = (string) wp_parse_url( (string) $hello['site'], PHP_URL_HOST );
		$options = (array) apply_filters(
			'siteferry_move_export_options',
			array(
				'title'    => 'Moved to ' . $host,
				'tags'     => array( 'moved' ),
				'compress' => \SiteFerry\Requirements::can_compress(),
			)
		);
		list( $job, $token ) = Export::start(
			array( 'password' => $parsed['secret'] ) + $options,
			null,
			'admin',
			'',
			array( self::STEP )
		);
		$job->set_state(
			Send_Step::STATE,
			array(
				'key'        => Secrets::site()->encrypt( wp_json_encode( $parsed ) ),
				'to'         => (string) $hello['site'],
				'keep_local' => $keep_local,
			)
		);
		$job->save();
		return array( $job, $token );
	}

	/**
	 * One request to the receiving site's move routes.
	 *
	 * @param array       $key     Parsed key.
	 * @param string      $method  GET or POST.
	 * @param string      $action  hello, start, chunk or finish.
	 * @param array       $headers Extra headers.
	 * @param string|null $body    Body (JSON or raw bytes).
	 * @throws Job_Exception On a network error or a refusal.
	 */
	public static function call( array $key, string $method, string $action, array $headers = array(), ?string $body = null ): array {
		// "?rest_route=" works whether or not the other site uses pretty permalinks.
		$response = wp_remote_request(
			$key['url'] . '/?rest_route=/siteferry/v1/move/' . $action,
			array(
				'method'      => $method,
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array( 'X-SiteFerry-Move' => $key['id'] . '.' . $key['secret'] ) + $headers,
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Job_Exception( sprintf( 'The other site (%s) cannot be reached: %s', $key['url'], $response->get_error_message() ), Job_Exception::STEP );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 401 === $code || 403 === $code ) {
			throw new Job_Exception( 'The other site refused the key: it has expired, was used already, or was replaced. Make a new key there.', Job_Exception::STEP );
		}
		if ( 404 === $code && ( ! is_array( $data ) || ! isset( $data['code'] ) || 'rest_no_route' === $data['code'] ) ) {
			throw new Job_Exception( sprintf( 'SiteFerry %s or later is not active on %s.', SITEFERRY_VERSION, $key['url'] ), Job_Exception::STEP );
		}
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$error = new Job_Exception( sprintf( 'The other site answered: %s', is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'HTTP ' . $code ), 409 === $code ? Job_Exception::BUSY : Job_Exception::STEP );
			throw $error;
		}
		return $data;
	}
}
