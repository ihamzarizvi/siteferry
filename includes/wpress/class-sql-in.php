<?php
/**
 * Database dumps from .wpress backups, rewritten as SiteFerry dumps.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Wpress;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Database\Dumper;
use SiteFerry\Database\Statement_Reader;
use SiteFerry\Jobs\Slice_Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the database.sql of a .wpress backup into the form SiteFerry's importer accepts:
 *
 * - The placeholder prefix "SERVMASK_PREFIX_" goes back to the source site's prefix, in table names
 *   and in values that start with it (option and user-meta names such as ..._user_roles).
 * - INSERTs get the column list taken from the table's CREATE TABLE.
 * - Transaction statements are dropped; SiteFerry's header and end marker are added.
 * - The theme and active plugins, which these dumps leave empty (package.json holds them), are
 *   filled in from the values passed to the constructor.
 *
 * Anything other than DROP TABLE IF EXISTS, CREATE TABLE and INSERT is refused, as for SiteFerry's
 * own dumps. Resumable: state() records where to continue.
 */
final class Sql_In {

	const PLACEHOLDER = 'SERVMASK_PREFIX_';

	/**
	 * The .wpress dump.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * SiteFerry dump being written.
	 *
	 * @var string
	 */
	private $target;

	/**
	 * Prefix the placeholder stands for.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Option values to fill in where the dump has them empty (option name => value).
	 *
	 * @var array<string,string>
	 */
	private $fill;

	/**
	 * Progress and facts collected so far.
	 *
	 * @var array
	 */
	private $state;

	/**
	 * Constructor.
	 *
	 * @param string     $source Dump from the .wpress backup.
	 * @param string     $target Where to write the SiteFerry dump.
	 * @param string     $prefix Table prefix of the source site.
	 * @param array|null $state  Data from state(), to continue.
	 * @param array      $fill   Option values to put in where the dump has them empty, such as
	 *                           template, stylesheet and active_plugins (serialized).
	 */
	public function __construct( string $source, string $target, string $prefix, ?array $state = null, array $fill = array() ) {
		$this->source = $source;
		$this->target = $target;
		$this->prefix = $prefix;
		$this->fill   = $fill;
		$this->state  = null !== $state ? $state : array(
			'offset'     => 0,
			'written'    => 0,
			'tables'     => array(),
			'db_version' => '',
			'charset'    => '',
			'collate'    => '',
			'stray'      => 0,
			'done'       => false,
		);
	}

	/**
	 * Translates statements until the dump ends or the budget runs out.
	 *
	 * @param Slice_Budget|null $budget Work limit.
	 * @return bool True when the whole dump is translated.
	 * @throws Archive_Exception On statements SiteFerry does not accept.
	 */
	public function run( ?Slice_Budget $budget = null ): bool {
		if ( $this->state['done'] ) {
			return true;
		}
		$out = Io::open_or_fail( $this->target, 'c+b' );
		$in  = new Statement_Reader( $this->source, (int) $this->state['offset'] );
		try {
			if ( ! ftruncate( $out, (int) $this->state['written'] ) || 0 !== fseek( $out, (int) $this->state['written'] ) ) {
				throw new Archive_Exception( 'Cannot continue writing the converted database dump.', Archive_Exception::IO );
			}
			if ( 0 === (int) $this->state['written'] ) {
				Io::write_all( $out, "-- SiteFerry database dump\n-- Converted from the database.sql of a .wpress backup\n\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n" );
			}
			while ( true ) {
				$statement = $in->next();
				if ( null === $statement ) {
					Io::write_all( $out, "SET FOREIGN_KEY_CHECKS = 1;\n" . Dumper::END_MARKER . "\n" );
					$this->state['done'] = true;
					break;
				}
				$sql = $this->translate( $statement );
				if ( null !== $sql ) {
					Io::write_all( $out, $sql . ";\n" );
				}
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
	 * Progress and facts: tables (name => columns, rows), db_version, charset, collate, stray
	 * (placeholders left inside values), done.
	 */
	public function state(): array {
		return $this->state;
	}

	/**
	 * One statement in SiteFerry's form, or null to drop it.
	 *
	 * @param string $statement Statement without its semicolon.
	 * @throws Archive_Exception If SiteFerry does not accept it.
	 */
	private function translate( string $statement ): ?string {
		if ( preg_match( '/^(START\s+TRANSACTION|BEGIN|COMMIT|(UN)?LOCK\s+TABLES\b.*|SET\s.*|\/\*!\d+\s+SET\s.*\*\/)$/is', $statement ) ) {
			return null;
		}

		$sql = str_replace( array( '`' . self::PLACEHOLDER, "'" . self::PLACEHOLDER ), array( '`' . $this->prefix, "'" . $this->prefix ), $statement );
		if ( false !== strpos( $sql, self::PLACEHOLDER ) ) {
			++$this->state['stray'];
		}

		if ( preg_match( '/^DROP\s+TABLE\s+IF\s+EXISTS\s+`(?:[^`]|``)+`$/i', $sql ) ) {
			return $sql;
		}
		if ( preg_match( '/^CREATE\s+TABLE\s+`((?:[^`]|``)+)`\s*\(/i', $sql, $match ) ) {
			$table                            = str_replace( '``', '`', $match[1] );
			$this->state['tables'][ $table ] = array(
				'columns' => Sql_Values::columns( $sql ),
				'rows'    => 0,
			);
			if ( '' === $this->state['charset'] && preg_match( '/\bCHARSET\s*=\s*([A-Za-z0-9_]+)/i', $sql, $charset ) ) {
				$this->state['charset'] = strtolower( $charset[1] );
				if ( preg_match( '/\bCOLLATE\s*=\s*([A-Za-z0-9_]+)/i', $sql, $collate ) ) {
					$this->state['collate'] = strtolower( $collate[1] );
				}
			}
			return $sql;
		}
		if ( preg_match( '/^INSERT\s+INTO\s+`((?:[^`]|``)+)`\s*(\([^)]*\)\s*)?VALUES\s*/i', $sql, $match ) ) {
			$table = str_replace( '``', '`', $match[1] );
			if ( ! isset( $this->state['tables'][ $table ] ) ) {
				throw new Archive_Exception( sprintf( 'The database dump inserts into the table %s before creating it.', $table ), Archive_Exception::UNSUPPORTED );
			}
			$values = substr( $sql, strlen( $match[0] ) );
			$rows   = Sql_Values::rows( $values );
			$this->state['tables'][ $table ]['rows'] += count( $rows );
			if ( $this->prefix . 'options' === $table ) {
				if ( '' === $this->state['db_version'] ) {
					$this->find_db_version( $rows, $this->state['tables'][ $table ]['columns'] );
				}
				if ( array() !== $this->fill ) {
					$values = $this->fill_options( $rows, $this->state['tables'][ $table ]['columns'], $values );
				}
			}
			$columns = '' !== trim( isset( $match[2] ) ? $match[2] : '' )
				? trim( $match[2] )
				: '(' . implode( ', ', array_map( array( \SiteFerry\Database\Sql::class, 'identifier' ), $this->state['tables'][ $table ]['columns'] ) ) . ')';
			return 'INSERT INTO ' . \SiteFerry\Database\Sql::identifier( $table ) . ' ' . $columns . ' VALUES ' . $values;
		}

		$preview = (string) preg_replace( '/[^\x20-\x7e]/', '?', (string) preg_replace( '/\s+/', ' ', substr( $statement, 0, 100 ) ) );
		throw new Archive_Exception( sprintf( 'The database dump in the .wpress backup contains a statement SiteFerry does not import: "%s".', $preview ), Archive_Exception::UNSUPPORTED );
	}

	/**
	 * Fills in empty values of the options in $this->fill.
	 *
	 * @param string[] $rows    Rows of an INSERT into the options table.
	 * @param string[] $columns The table's columns.
	 * @param string   $values  The VALUES text the rows came from.
	 * @return string The VALUES text, rebuilt if a row changed.
	 */
	private function fill_options( array $rows, array $columns, string $values ): string {
		$name    = array_search( 'option_name', $columns, true );
		$value   = array_search( 'option_value', $columns, true );
		$changed = false;
		if ( false === $name || false === $value ) {
			return $values;
		}
		foreach ( $rows as $i => $row ) {
			foreach ( $this->fill as $option => $fill ) {
				if ( false === strpos( $row, "'" . $option . "'" ) ) {
					continue;
				}
				$fields = Sql_Values::fields( $row );
				if ( isset( $fields[ $name ], $fields[ $value ] ) && "'" . $option . "'" === $fields[ $name ] && in_array( $fields[ $value ], array( "''", "'a:0:{}'" ), true ) ) {
					$fields[ $value ] = "'" . strtr(
						$fill,
						array(
							'\\'   => '\\\\',
							"'"    => "\\'",
							"\0"   => '\\0',
							"\n"   => '\\n',
							"\r"   => '\\r',
							"\x1a" => '\\Z',
						)
					) . "'";
					$rows[ $i ] = implode( ',', $fields );
					$changed    = true;
				}
			}
		}
		return $changed ? '(' . implode( '),(', $rows ) . ')' : $values;
	}

	/**
	 * Records the db_version option if one of the rows holds it.
	 *
	 * @param string[] $rows    Rows of an INSERT into the options table.
	 * @param string[] $columns The table's columns.
	 */
	private function find_db_version( array $rows, array $columns ): void {
		$name  = array_search( 'option_name', $columns, true );
		$value = array_search( 'option_value', $columns, true );
		if ( false === $name || false === $value ) {
			return;
		}
		foreach ( $rows as $row ) {
			if ( false === strpos( $row, "'db_version'" ) ) {
				continue;
			}
			$fields = Sql_Values::fields( $row );
			if ( isset( $fields[ $name ], $fields[ $value ] ) && "'db_version'" === $fields[ $name ] && preg_match( "/^'(\d+)'$/", $fields[ $value ], $version ) ) {
				$this->state['db_version'] = $version[1];
				return;
			}
		}
	}
}
