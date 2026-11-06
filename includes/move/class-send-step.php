<?php
/**
 * Site-to-site move: the step that sends the backup to the receiving site.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Move;

use SiteFerry\Archive\Io;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;
use SiteFerry\Security\Secrets;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the export's (encrypted) backup in chunks with their SHA-256; the receiving site keeps
 * track of how much it has, so a slice that ends, a dropped connection or a chunk that arrived
 * twice just carries on from there. Then the receiving site takes the backup into its list.
 */
final class Send_Step implements Step {

	const STATE = 'move';

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 * @throws Job_Exception If the other site refuses or cannot be reached.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$state = $job->state( self::STATE );
		$key   = json_decode( Secrets::site()->decrypt( (string) $state['key'] ), true );
		$file  = (string) $job->result()['file'];
		$name  = basename( $file );
		$size  = (int) filesize( $file );

		if ( ! isset( $state['offset'] ) ) {
			$started         = Sender::call( $key, 'POST', 'start', array( 'Content-Type' => 'application/json' ), (string) wp_json_encode( array( 'name' => $name, 'size' => $size, 'from' => home_url() ) ) );
			$state['offset'] = (int) $started['upload']['offset'];
			$state['chunk']  = max( 262144, (int) $started['chunk'] );
			$job->log( 'info', sprintf( 'Sending %s (%s) to %s.', $name, size_format( $size, 1 ), $state['to'] ) );
		}

		$handle = Io::open_or_fail( $file, 'rb' );
		try {
			while ( $state['offset'] < $size ) {
				fseek( $handle, $state['offset'] );
				$bytes = (string) fread( $handle, (int) min( $state['chunk'], $size - $state['offset'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				try {
					$answer = Sender::call(
						$key,
						'POST',
						'chunk',
						array(
							'Content-Type'       => 'application/octet-stream',
							'X-SiteFerry-Offset' => (string) $state['offset'],
							'X-SiteFerry-Sha256' => hash( 'sha256', $bytes ),
						),
						$bytes
					);
					$state['offset'] = (int) $answer['offset'];
				} catch ( Job_Exception $e ) {
					if ( Job_Exception::BUSY !== $e->getCode() ) {
						throw $e;
					}
					// The other site has a different amount (a chunk arrived twice, or not at all): ask it.
					$again           = Sender::call( $key, 'POST', 'start', array( 'Content-Type' => 'application/json' ), (string) wp_json_encode( array( 'name' => $name, 'size' => $size, 'from' => home_url() ) ) );
					$state['offset'] = (int) $again['upload']['offset'];
				}
				$job->set_state( self::STATE, $state );
				$job->set_progress( sprintf( /* translators: 1: where it goes, such as Dropbox or another site's host name, 2: a size, such as 12 MB, 3: a size, such as 12 MB */ __( 'Sending to %1$s: %2$s of %3$s', 'siteferry' ), (string) wp_parse_url( $state['to'], PHP_URL_HOST ), size_format( $state['offset'], 1 ), size_format( $size, 1 ) ), $state['offset'], $size );
				$job->save();
				if ( $state['offset'] < $size && $budget->exhausted() ) {
					return false;
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$finished = Sender::call( $key, 'POST', 'finish' );
		$result   = $job->result();
		$admin           = isset( $finished['admin'] ) && is_string( $finished['admin'] ) ? $finished['admin'] : '';
		$result['moved'] = array(
			'to'    => (string) $state['to'],
			'name'  => (string) $finished['backup'],
			'size'  => $size,
			// SiteFerry's page on the other site, to link to; only an address on that same site.
			'admin' => self::same_site( $admin, (string) $state['to'] ) ? $admin : '',
		);
		if ( empty( $state['keep_local'] ) ) {
			$deleted = Io::quiet(
				static function () use ( $file ) {
					return unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			);
			$result['deleted_local'] = (bool) $deleted;
			if ( $deleted ) {
				\SiteFerry\Labels::set( $name, '', '', array() ); // Its name and tags went with it.
			}
		}
		$job->set_result( $result );
		$job->set_state( self::STATE, array( 'to' => $state['to'] ) ); // The key is no longer needed.
		$job->set_progress( sprintf( /* translators: %s: where it went, such as Dropbox or another site's host name */ __( 'Sent to %s', 'siteferry' ), $state['to'] ), 1, 1 );
		$job->log( 'info', sprintf( 'Sent to %s as %s. Finish the move there.', $state['to'], $finished['backup'] ) );
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
		$job->set_state( self::STATE, array() );
	}

	/**
	 * Whether an address is a web page of the given site: http(s), same host and port.
	 *
	 * @param string $address Address the other site sent.
	 * @param string $site    The other site's address (from the key).
	 */
	public static function same_site( string $address, string $site ): bool {
		// Browsers read a backslash as a slash, so "https://site\@other" would open "other".
		if ( preg_match( '/[\\\\\s\x00-\x1f\x7f]/', $address ) ) {
			return false;
		}
		$a = wp_parse_url( $address );
		if ( is_array( $a ) && ( isset( $a['user'] ) || isset( $a['pass'] ) ) ) {
			return false;
		}
		$b = wp_parse_url( $site );
		if ( ! is_array( $a ) || ! is_array( $b ) || ! isset( $a['scheme'], $a['host'], $b['host'] ) ) {
			return false;
		}
		return in_array( strtolower( $a['scheme'] ), array( 'http', 'https' ), true )
			&& strtolower( $a['host'] ) === strtolower( $b['host'] )
			&& ( isset( $a['port'] ) ? (int) $a['port'] : 0 ) === ( isset( $b['port'] ) ? (int) $b['port'] : 0 );
	}
}
