<?php
/**
 * Import step: new addresses and paths in the temporary tables.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Import;

use SiteFerry\Database\Raw_Query;
use SiteFerry\Database\Replacer;
use SiteFerry\Database\Sql;
use SiteFerry\Database\Table_Replacer;
use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the replacement pairs over every temporary table (posts.guid is left alone, as GUIDs
 * must never change), renames prefixed option and user meta keys when the table prefix changes,
 * and sets home and siteurl to exactly the new addresses.
 */
final class Replace_Step implements Step {

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    The job.
	 * @param Slice_Budget $budget Work limit.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		global $wpdb;

		$inspect = $job->state( 'inspect' );
		$temp    = (string) $job->option( 'temp_prefix' );
		$state   = $job->state( 'replace' );
		if ( array() === $state ) {
			$state = array(
				'tables'   => $job->state( 'database' )['tables'],
				'index'    => 0,
				'plan'     => null,
				'rows'     => 0,
				'changed'  => array(),
				'stats'    => array(
					'serialized' => 0,
					'broken'     => 0,
					'opaque'     => 0,
				),
				'prefixed' => false,
			);
			sort( $state['tables'] );
		}

		if ( ! $state['prefixed'] ) {
			$this->rename_prefixed_keys( $wpdb, $temp, $inspect['prefix'], $job->option( 'site' )['prefix'] );
			$state['prefixed'] = true;
		}

		$replacer = new Replacer( $inspect['pairs'] );
		$tables   = new Table_Replacer( $wpdb, $replacer );
		while ( $state['index'] < count( $state['tables'] ) ) {
			$table = $state['tables'][ $state['index'] ];
			if ( null === $state['plan'] ) {
				$skip          = $temp . 'posts' === $table ? array( 'guid' ) : array();
				$state['plan'] = $tables->plan( $table, $skip );
				if ( null === $state['plan'] ) {
					++$state['index'];
					continue;
				}
			}
			if ( $tables->run( $state['plan'] ) ) {
				if ( $state['plan']['rows'] > 0 ) {
					$state['changed'][ substr( $table, strlen( $temp ) ) ] = $state['plan']['rows'];
					$state['rows']                                       += $state['plan']['rows'];
				}
				$state['plan'] = null;
				++$state['index'];
			}
			if ( $budget->exhausted() ) {
				break;
			}
		}
		foreach ( $replacer->stats() as $key => $count ) {
			$state['stats'][ $key ] += $count;
		}

		$done = $state['index'] >= count( $state['tables'] );
		if ( $done ) {
			if ( in_array( $temp . 'options', $state['tables'], true ) ) {
				$this->set_addresses( $wpdb, $temp, $inspect['target'] ); // Unless a partial restore leaves the options as they are.
			}
			if ( $state['stats']['broken'] > 0 ) {
				$job->warn( sprintf( '%d values looked serialized but were already damaged in the backup; they were updated as plain text.', $state['stats']['broken'] ) );
			}
			if ( $state['stats']['opaque'] > 0 ) {
				$job->warn( sprintf( '%d serialized values use a custom format SiteFerry cannot rewrite; they keep the old addresses.', $state['stats']['opaque'] ) );
			}
			$job->log( 'info', sprintf( 'Search and replace: %d rows changed (%s).', $state['rows'], self::describe( $state['changed'] ) ) );
		}
		$job->set_state( 'replace', $state );
		$job->set_progress( sprintf( 'Updating addresses: table %d of %d', min( $state['index'] + 1, count( $state['tables'] ) ), count( $state['tables'] ) ), $state['index'], count( $state['tables'] ) );
		return $done;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job The job.
	 */
	public function cleanup( Job $job ): void {
	}

	/**
	 * Moves "<old prefix>user_roles" and "<old prefix>..." user meta keys to the site's prefix.
	 *
	 * @param \wpdb  $db   Connection.
	 * @param string $temp Temporary prefix.
	 * @param string $from Prefix in the backup.
	 * @param string $to   This site's prefix.
	 */
	private function rename_prefixed_keys( $db, string $temp, string $from, string $to ): void {
		if ( $from === $to ) {
			return;
		}
		$tables = Raw_Query::column( $db, 'SHOW TABLES LIKE ' . Raw_Query::quote( $db, addcslashes( $temp, '_%\\' ) . '%' ) );
		if ( in_array( $temp . 'options', $tables, true ) ) {
			Raw_Query::run(
				$db,
				'UPDATE ' . Sql::identifier( $temp . 'options' ) . ' SET option_name = ' . Raw_Query::quote( $db, $to . 'user_roles' )
				. ' WHERE option_name = ' . Raw_Query::quote( $db, $from . 'user_roles' )
			);
		}
		if ( in_array( $temp . 'usermeta', $tables, true ) ) {
			// When the new prefix starts with the old one ("wp_" -> "wp_shop_"), keys already renamed
			// by an interrupted earlier slice must not be renamed twice.
			$again = 0 === strpos( $to, $from ) ? ' AND meta_key NOT LIKE ' . Raw_Query::quote( $db, addcslashes( $to, '_%\\' ) . '%' ) : '';
			Raw_Query::run(
				$db,
				'UPDATE ' . Sql::identifier( $temp . 'usermeta' ) . ' SET meta_key = CONCAT(' . Raw_Query::quote( $db, $to ) . ', SUBSTRING(meta_key, ' . ( strlen( $from ) + 1 ) . '))'
				. ' WHERE meta_key LIKE ' . Raw_Query::quote( $db, addcslashes( $from, '_%\\' ) . '%' ) . $again
			);
		}
	}

	/**
	 * Sets home and siteurl to exactly the target addresses.
	 *
	 * @param \wpdb  $db     Connection.
	 * @param string $temp   Temporary prefix.
	 * @param array  $target Target facts.
	 */
	private function set_addresses( $db, string $temp, array $target ): void {
		foreach ( array( 'home', 'siteurl' ) as $name ) {
			Raw_Query::run(
				$db,
				'UPDATE ' . Sql::identifier( $temp . 'options' ) . ' SET option_value = ' . Raw_Query::quote( $db, (string) $target[ $name ] )
				. ' WHERE option_name = ' . Raw_Query::quote( $db, $name )
			);
		}
	}

	/**
	 * "posts: 12, options: 3" for the log.
	 *
	 * @param array<string,int> $changed Table suffix => rows.
	 */
	private static function describe( array $changed ): string {
		$parts = array();
		foreach ( $changed as $table => $rows ) {
			$parts[] = $table . ': ' . $rows;
		}
		return array() === $parts ? 'none' : implode( ', ', $parts );
	}
}
