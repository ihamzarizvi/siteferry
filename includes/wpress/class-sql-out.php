<?php
/**
 * SiteFerry dumps, rewritten for .wpress backups.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Wpress;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Database\Statement_Reader;
use SiteFerry\Jobs\Slice_Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a SiteFerry dump in the style .wpress importers expect:
 *
 * - The site's table prefix becomes "SERVMASK_PREFIX_" in table names, in the <prefix>user_roles
 *   option and at the start of user-meta keys (such as wp_capabilities), so the importer can put in
 *   its own prefix. Other option names are left alone: WordPress has options whose names start
 *   with "wp_" whatever the prefix (wp_page_for_privacy_policy and others), and these must not
 *   change. This is the same rule SiteFerry's own import applies when the prefix changes.
 * - One row per INSERT, without a column list when the row covers every column of the table.
 * - Each table's rows inside START TRANSACTION / COMMIT. Session SET statements are left out.
 *
 * Resumable: state() records where to continue.
 */
final class Sql_Out {

	/**
	 * SiteFerry dump.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Dump being written.
	 *
	 * @var string
	 */
	private $target;

	/**
	 * Table prefix of the site.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Progress.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Constructor.
	 *
	 * @param string     $source SiteFerry dump.
	 * @param string     $target Where to write.
	 * @param string     $prefix The site's table prefix.
	 * @param array|null $state  Data from state(), to continue.
	 */
	public function __construct( string $source, string $target, string $prefix, ?array $state = null ) {
		$this->source = $source;
		$this->target = $target;
		$this->prefix = $prefix;
		$this->state  = null !== $state ? $state : array(
			'offset'  => 0,
			'written' => 0,
			'columns' => array(),
			'open'    => '',
			'done'    => false,
		);
	}

	/**
	 * Translates statements until the dump ends or the budget runs out.
	 *
	 * @param Slice_Budget|null $budget Work limit.
	 * @return bool True when done.
	 * @throws Archive_Exception On statements a SiteFerry dump does not contain.
	 */
	public function run( ?Slice_Budget $budget = null ): bool {
		if ( $this->state['done'] ) {
			return true;
		}
		$out = Io::open_or_fail( $this->target, 'c+b' );
		$in  = new Statement_Reader( $this->source, (int) $this->state['offset'] );
		try {
			if ( ! ftruncate( $out, (int) $this->state['written'] ) || 0 !== fseek( $out, (int) $this->state['written'] ) ) {
				throw new Archive_Exception( __( 'Cannot continue writing the converted database dump.', 'siteferry' ), Archive_Exception::IO );
			}
			if ( 0 === (int) $this->state['written'] ) {
				Io::write_all( $out, "-- Database dump for a .wpress backup, written by SiteFerry\n--\n\n" );
			}
			while ( true ) {
				$statement = $in->next();
				if ( null === $statement ) {
					Io::write_all( $out, $this->close_transaction() );
					$this->state['done'] = true;
					break;
				}
				Io::write_all( $out, $this->translate( $statement ) );
				$this->state['offset'] = $in->offset();
				if ( null !== $budget ) {
					$budget->consume( strlen( $statement ) );
					if ( $budget->exhausted() ) {
						break;
					}
				}
			}
			fflush( $out );
			$this->state['written'] = (int) ftell( $out );
		} finally {
			$in->close();
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return $this->state['done'];
	}

	/**
	 * Progress.
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * The text to write for one statement.
	 *
	 * @param string $statement Statement without its semicolon.
	 * @throws Archive_Exception On statements a SiteFerry dump does not contain.
	 */
	private function translate( string $statement ): string {
		if ( preg_match( '/^(SET\s.*|\/\*!\d+\s+SET\s.*\*\/)$/is', $statement ) ) {
			return '';
		}
		if ( preg_match( '/^DROP\s+TABLE\s+IF\s+EXISTS\s+`((?:[^`]|``)+)`$/i', $statement, $match ) ) {
			return $this->close_transaction() . 'DROP TABLE IF EXISTS ' . $this->placeholder( $match[1] ) . ";\n";
		}
		if ( preg_match( '/^CREATE\s+TABLE\s+`((?:[^`]|``)+)`\s*\(/i', $statement, $match ) ) {
			$table                               = str_replace( '``', '`', $match[1] );
			$this->state['columns'][ $table ] = Sql_Values::columns( $statement );
			$sql                                 = 'CREATE TABLE ' . $this->placeholder( $match[1] ) . ' ' . substr( $statement, strlen( $match[0] ) - 1 );
			$sql                                 = (string) preg_replace_callback(
				'/\bREFERENCES\s+`((?:[^`]|``)+)`/i',
				function ( array $reference ): string {
					return 'REFERENCES ' . $this->placeholder( $reference[1] );
				},
				$sql
			);
			return $this->close_transaction() . $sql . ";\n\n";
		}
		if ( preg_match( '/^INSERT\s+INTO\s+`((?:[^`]|``)+)`\s*\(([^)]*)\)\s*VALUES\s*/i', $statement, $match ) ) {
			$table   = str_replace( '``', '`', $match[1] );
			$columns = array_map(
				static function ( string $column ): string {
					return str_replace( '``', '`', trim( trim( $column ), '`' ) );
				},
				explode( ',', $match[2] )
			);
			$all     = isset( $this->state['columns'][ $table ] ) ? $this->state['columns'][ $table ] : array();
			$head    = 'INSERT INTO ' . $this->placeholder( $match[1] ) . ( $columns === $all ? '' : ' (' . $match[2] . ')' ) . ' VALUES (';

			$key  = null;
			$only = null;
			if ( $this->prefix . 'options' === $table ) {
				$key  = array_search( 'option_name', $columns, true );
				$only = "'" . $this->prefix . "user_roles'";
			} elseif ( $this->prefix . 'usermeta' === $table ) {
				$key = array_search( 'meta_key', $columns, true );
			}

			$text = '';
			if ( $this->state['open'] !== $table ) {
				$text                .= $this->close_transaction() . "START TRANSACTION;\n";
				$this->state['open'] = $table;
			}
			foreach ( Sql_Values::rows( substr( $statement, strlen( $match[0] ) ) ) as $row ) {
				if ( null !== $key && false !== $key ) {
					$row = $this->placeholder_value( $row, (int) $key, $only );
				}
				$text .= $head . $row . ");\n";
			}
			return $text;
		}
		throw new Archive_Exception( __( 'The database dump contains a statement that cannot be written to a .wpress backup.', 'siteferry' ), Archive_Exception::UNSUPPORTED );
	}

	/**
	 * A table name with the prefix replaced by the placeholder, quoted.
	 *
	 * @param string $escaped Name as written between backticks.
	 */
	private function placeholder( string $escaped ): string {
		$name = str_replace( '``', '`', $escaped );
		if ( '' !== $this->prefix && 0 === strpos( $name, $this->prefix ) ) {
			$name = Sql_In::PLACEHOLDER . substr( $name, strlen( $this->prefix ) );
		}
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * A row with the prefix at the start of one quoted value replaced by the placeholder.
	 *
	 * @param string      $row   Row text.
	 * @param int         $index Value to change.
	 * @param string|null $only  Change it only when it is exactly this (quoted) value.
	 */
	private function placeholder_value( string $row, int $index, ?string $only ): string {
		if ( false === strpos( $row, null === $only ? "'" . $this->prefix : $only ) ) {
			return $row;
		}
		$fields = Sql_Values::fields( $row );
		if ( ! isset( $fields[ $index ] ) || 0 !== strpos( $fields[ $index ], "'" . $this->prefix ) || ( null !== $only && $only !== $fields[ $index ] ) ) {
			return $row;
		}
		$fields[ $index ] = "'" . Sql_In::PLACEHOLDER . substr( $fields[ $index ], 1 + strlen( $this->prefix ) );
		return implode( ',', $fields );
	}

	/**
	 * COMMIT for the table whose rows are being written, if any.
	 */
	private function close_transaction(): string {
		if ( '' === $this->state['open'] ) {
			return '';
		}
		$this->state['open'] = '';
		return "COMMIT;\n\n";
	}
}
