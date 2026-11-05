<?php
/**
 * Resumable database dump through $wpdb.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Database;

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Slice_Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Writes base tables with the site's prefix to an SQL file, in batches that can stop and resume.
 *
 * Rows are read with keyset paging on the primary key (WHERE (pk) > (last) ORDER BY pk LIMIT n),
 * which stays fast on large tables and copes with rows changing between slices. Tables without a
 * primary key fall back to LIMIT/OFFSET. Binary columns are written as hex literals, NULL as NULL,
 * everything else as escaped strings in the connection's character set (utf8mb4 keeps 4-byte
 * characters such as emoji). Generated columns are left out of INSERTs; the server recomputes them.
 * Views and triggers are not dumped.
 *
 * The file ends with END_MARKER so an incomplete dump is detectable.
 */
final class Dumper {

	/** Approximate bytes fetched per batch. */
	const TARGET_BATCH_BYTES = 2097152;

	const MIN_BATCH_ROWS = 10;
	const MAX_BATCH_ROWS = 2000;

	/** INSERT statements are split at about this size (MySQL's smallest max_allowed_packet is 4 MB). */
	const MAX_STATEMENT_BYTES = 1048576;

	/** Last line of a complete dump. */
	const END_MARKER = '-- SiteFerry dump end';

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db Connection.
	 */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * Base tables whose names start with $prefix, minus $exclude, with size estimates.
	 *
	 * @param string   $prefix  Table prefix.
	 * @param string[] $exclude Table names to leave out.
	 * @return array<int,array{name:string,rows:int,avg_row:int,data:int}>
	 */
	public function tables( string $prefix, array $exclude = array() ): array {
		$like   = $this->db->esc_like( $prefix ) . '%';
		$base   = array();
		$listed = $this->query( $this->db->prepare( 'SHOW FULL TABLES LIKE %s', $like ), ARRAY_N );
		foreach ( $listed as $row ) {
			if ( 'BASE TABLE' === strtoupper( (string) $row[1] ) && 0 === strpos( (string) $row[0], $prefix ) ) {
				$base[ (string) $row[0] ] = true;
			}
		}

		$tables = array();
		foreach ( $this->query( $this->db->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A ) as $status ) {
			$name = (string) $status['Name'];
			if ( ! isset( $base[ $name ] ) || in_array( $name, $exclude, true ) ) {
				continue;
			}
			$tables[] = array(
				'name'    => $name,
				'rows'    => (int) $status['Rows'],
				'avg_row' => (int) $status['Avg_row_length'],
				'data'    => (int) $status['Data_length'],
			);
		}
		usort(
			$tables,
			static function ( array $a, array $b ): int {
				return strcmp( $a['name'], $b['name'] );
			}
		);
		return $tables;
	}

	/**
	 * Initial state for run().
	 *
	 * @param array                $tables     From tables().
	 * @param array<string,string> $filters    Table name => SQL condition rows must meet (built with prepare()).
	 * @param int|null             $batch_rows Fixed rows per batch instead of the size-based estimate (tests).
	 * @return array
	 */
	public static function initial_state( array $tables, array $filters = array(), ?int $batch_rows = null ): array {
		$rows = 0;
		foreach ( $tables as $index => $table ) {
			$tables[ $index ]['filter'] = isset( $filters[ $table['name'] ] ) ? $filters[ $table['name'] ] : null;
			$tables[ $index ]['dumped'] = 0; // Rows written so far ("rows" is the server's estimate).
			$rows                      += $table['rows'];
		}
		return array(
			'tables'     => array_values( $tables ),
			'index'      => 0,
			'table'      => null,
			'size'       => 0,
			'started'    => false,
			'done'       => false,
			'rows_done'  => 0,
			'rows_total' => $rows,
			'batch_rows' => $batch_rows,
		);
	}

	/**
	 * Dumps until finished or the budget runs out.
	 *
	 * @param array        $state  In/out: from initial_state().
	 * @param string       $file   Dump file.
	 * @param Slice_Budget $budget Work limit; at least one batch per call.
	 * @param callable     $warn   function( string $message ): void.
	 * @return bool True when the dump is complete.
	 * @throws Archive_Exception If the file cannot be written.
	 * @throws Job_Exception On database errors.
	 */
	public function run( array &$state, string $file, Slice_Budget $budget, callable $warn ): bool {
		if ( $state['done'] ) {
			return true;
		}

		$handle = Io::open_or_fail( $file, 'c+b' );
		$errors = $this->db->suppress_errors( true );
		$zone   = $this->db->get_var( 'SELECT @@session.time_zone' );
		$this->db->query( "SET time_zone = '+00:00'" );

		try {
			if ( ! ftruncate( $handle, $state['size'] ) || 0 !== fseek( $handle, $state['size'] ) ) {
				throw new Archive_Exception( __( 'Cannot rewind the database dump.', 'siteferry' ), Archive_Exception::IO );
			}
			if ( ! $state['started'] ) {
				$this->write( $handle, $budget, $this->header( count( $state['tables'] ) ) );
				$state['started'] = true;
			}

			while ( $state['index'] < count( $state['tables'] ) ) {
				if ( null === $state['table'] ) {
					$state['table'] = $this->start_table( $state['tables'][ $state['index'] ], $handle, $budget, $warn );
					if ( null !== $state['table'] && ! empty( $state['batch_rows'] ) ) {
						$state['table']['batch'] = (int) $state['batch_rows'];
					}
				}
				if ( null === $state['table'] || ! $this->dump_batch( $state, $handle, $budget ) ) {
					$state['table'] = null;
					++$state['index'];
				}
				if ( $budget->exhausted() ) {
					break;
				}
			}

			if ( $state['index'] >= count( $state['tables'] ) ) {
				$this->write( $handle, $budget, "SET FOREIGN_KEY_CHECKS = 1;\n" . self::END_MARKER . "\n" );
				$state['done'] = true;
			}

			fflush( $handle );
			$state['size'] = (int) ftell( $handle );
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( is_string( $zone ) && '' !== $zone ) {
				$this->db->query( $this->db->prepare( 'SET time_zone = %s', $zone ) );
			}
			$this->db->suppress_errors( $errors );
		}

		return $state['done'];
	}

	/**
	 * Writes the DROP/CREATE statements of a table and returns its batch state.
	 *
	 * @param array        $table  Entry from tables().
	 * @param resource     $handle Dump file.
	 * @param Slice_Budget $budget Work limit.
	 * @param callable     $warn   Warning callback.
	 * @return array|null Null if the table disappeared.
	 */
	private function start_table( array $table, $handle, Slice_Budget $budget, callable $warn ): ?array {
		$name   = $table['name'];
		$create = $this->db->get_row( 'SHOW CREATE TABLE ' . Sql::identifier( $name ), ARRAY_N );
		if ( ! is_array( $create ) || ! isset( $create[1] ) ) {
			$warn( sprintf( 'The table %s disappeared during the export and was left out.', $name ) );
			return null;
		}

		$columns = array();
		$insert  = array();
		foreach ( $this->query( 'SHOW FULL COLUMNS FROM ' . Sql::identifier( $name ), ARRAY_A ) as $column ) {
			$generated = (bool) preg_match( '/\bGENERATED\b/i', (string) $column['Extra'] );
			$columns[ (string) $column['Field'] ] = array(
				'kind'      => Sql::kind( (string) $column['Type'] ),
				'generated' => $generated,
			);
			if ( ! $generated ) {
				$insert[] = (string) $column['Field'];
			}
		}

		$keys = $this->query( 'SHOW KEYS FROM ' . Sql::identifier( $name ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A );
		usort(
			$keys,
			static function ( array $a, array $b ): int {
				return (int) $a['Seq_in_index'] - (int) $b['Seq_in_index'];
			}
		);
		$primary = array();
		foreach ( $keys as $key ) {
			$primary[] = (string) $key['Column_name'];
		}

		// Inserted columns first, then any generated key column needed only for paging.
		$select = array_merge( $insert, array_values( array_diff( $primary, $insert ) ) );
		$kinds  = array();
		foreach ( $select as $column ) {
			$kinds[] = $columns[ $column ]['kind'];
		}

		$this->write(
			$handle,
			$budget,
			sprintf( "\n-- Table %s\nDROP TABLE IF EXISTS %s;\n%s;\n", Sql::identifier( $name ), Sql::identifier( $name ), $create[1] )
		);

		return array(
			'select'  => $select,
			'kinds'   => $kinds,
			'inserts' => count( $insert ),
			'primary' => $primary,
			'batch'   => max( self::MIN_BATCH_ROWS, min( self::MAX_BATCH_ROWS, intdiv( self::TARGET_BATCH_BYTES, max( 64, $table['avg_row'] ) ) ) ),
			'last'    => null,
			'offset'  => 0,
		);
	}

	/**
	 * Dumps one batch of rows of the current table.
	 *
	 * @param array        $state  Dump state.
	 * @param resource     $handle Dump file.
	 * @param Slice_Budget $budget Work limit.
	 * @return bool False when the table has no more rows.
	 */
	private function dump_batch( array &$state, $handle, Slice_Budget $budget ): bool {
		$table = $state['tables'][ $state['index'] ];
		$batch = &$state['table'];

		$where = array();
		if ( null !== $table['filter'] ) {
			$where[] = '(' . $table['filter'] . ')';
		}
		if ( array() !== $batch['primary'] && null !== $batch['last'] ) {
			$where[] = $this->after_key( $batch );
		}

		$sql = 'SELECT ' . implode( ', ', array_map( array( Sql::class, 'select_expression' ), $batch['select'], $batch['kinds'] ) )
			. ' FROM ' . Sql::identifier( $table['name'] )
			. ( array() === $where ? '' : ' WHERE ' . implode( ' AND ', $where ) );
		if ( array() !== $batch['primary'] ) {
			$sql .= ' ORDER BY ' . implode( ', ', array_map( array( Sql::class, 'identifier' ), $batch['primary'] ) ) . ' LIMIT ' . (int) $batch['batch'];
		} else {
			$sql .= ' LIMIT ' . (int) $batch['offset'] . ', ' . (int) $batch['batch'];
		}

		$rows = $this->query( $sql, ARRAY_N );
		if ( array() === $rows ) {
			return false;
		}

		$this->write( $handle, $budget, $this->inserts( $table['name'], $batch, $rows ) );

		$count = count( $rows );
		if ( array() !== $batch['primary'] ) {
			// Key values are saved as hex: binary keys are not valid UTF-8 and cannot go into JSON.
			$last          = end( $rows );
			$batch['last'] = array();
			foreach ( $batch['primary'] as $column ) {
				$batch['last'][] = bin2hex( (string) $last[ (int) array_search( $column, $batch['select'], true ) ] );
			}
		} else {
			$batch['offset'] += $count;
		}
		$state['rows_done'] += $count;
		if ( isset( $state['tables'][ $state['index'] ]['dumped'] ) ) {
			$state['tables'][ $state['index'] ]['dumped'] += $count;
		}

		return $count >= $batch['batch'];
	}

	/**
	 * INSERT statements for a batch, split at MAX_STATEMENT_BYTES.
	 *
	 * @param string $name  Table name.
	 * @param array  $batch Batch state.
	 * @param array  $rows  Rows (numeric arrays in select order).
	 */
	private function inserts( string $name, array $batch, array $rows ): string {
		$columns = array_slice( $batch['select'], 0, $batch['inserts'] );
		$head    = 'INSERT INTO ' . Sql::identifier( $name ) . ' (' . implode( ', ', array_map( array( Sql::class, 'identifier' ), $columns ) ) . ') VALUES ';
		$out     = '';
		$current = '';

		foreach ( $rows as $row ) {
			$values = array();
			for ( $i = 0; $i < $batch['inserts']; $i++ ) {
				$values[] = Sql::literal( $row[ $i ], $batch['kinds'][ $i ] );
			}
			$tuple = '(' . implode( ',', $values ) . ')';

			if ( '' === $current ) {
				$current = $head . $tuple;
			} elseif ( strlen( $current ) + strlen( $tuple ) + 1 > self::MAX_STATEMENT_BYTES ) {
				$out    .= $current . ";\n";
				$current = $head . $tuple;
			} else {
				$current .= ',' . $tuple;
			}
		}
		return $out . $current . ";\n";
	}

	/**
	 * WHERE condition selecting rows after the last key of the previous batch.
	 *
	 * @param array $batch Batch state.
	 */
	private function after_key( array $batch ): string {
		$columns = array();
		$values  = array();
		foreach ( $batch['primary'] as $index => $column ) {
			$kind      = $batch['kinds'][ (int) array_search( $column, $batch['select'], true ) ];
			$value     = (string) hex2bin( $batch['last'][ $index ] );
			$columns[] = Sql::identifier( $column );
			if ( 'binary' === $kind ) {
				$values[] = Sql::hex_literal( $value );
			} elseif ( ( 'number' === $kind || 'bit' === $kind ) && preg_match( '/^-?[0-9]+(\.[0-9]+)?$/', $value ) ) {
				$values[] = $value;
			} else {
				$values[] = $this->db->prepare( '%s', $value );
			}
		}
		if ( 1 === count( $columns ) ) {
			return $columns[0] . ' > ' . $values[0];
		}
		return '(' . implode( ', ', $columns ) . ') > (' . implode( ', ', $values ) . ')';
	}

	/**
	 * Opening lines of the dump.
	 *
	 * @param int $tables Number of tables.
	 */
	private function header( int $tables ): string {
		$charset = preg_match( '/^[A-Za-z0-9_]+$/', (string) $this->db->charset ) ? $this->db->charset : 'utf8mb4';
		return "-- SiteFerry database dump\n"
			. '-- Server version: ' . preg_replace( '/[^\x20-\x7e]/', '', (string) $this->db->db_server_info() ) . "\n"
			. '-- Character set: ' . $charset . "\n"
			. '-- Tables: ' . $tables . "\n\n"
			. '/*!40101 SET NAMES ' . $charset . " */;\n"
			. "SET FOREIGN_KEY_CHECKS = 0;\n"
			. "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n"
			. "SET TIME_ZONE = '+00:00';\n";
	}

	/**
	 * Runs a read query and fails loudly on errors.
	 *
	 * @param string $sql    Query.
	 * @param string $output ARRAY_A or ARRAY_N.
	 * @return array
	 * @throws Job_Exception On database errors.
	 */
	private function query( string $sql, string $output ): array {
		$rows  = $this->db->get_results( $sql, $output );
		$error = $this->db->last_error;
		$this->db->flush();
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			$this->db->queries = array();
		}
		if ( '' !== $error ) {
			throw new Job_Exception( sprintf( /* translators: %s: the reason */ __( 'Database error during export: %s', 'siteferry' ), $error ), Job_Exception::STEP );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Writes to the dump and counts the bytes against the budget.
	 *
	 * @param resource     $handle Dump file.
	 * @param Slice_Budget $budget Work limit.
	 * @param string       $text   SQL text.
	 */
	private function write( $handle, Slice_Budget $budget, string $text ): void {
		Io::write_all( $handle, $text );
		$budget->consume( strlen( $text ) );
	}
}
