<?php
/**
 * Checks and rewrites dump statements for loading into temporary tables.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Database;

use SiteFerry\Archive\Archive_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts only the statements a SiteFerry dump contains and refuses everything else.
 *
 * Allowed: session SET statements (skipped; the importer sets its own), DROP TABLE IF EXISTS,
 * CREATE TABLE and INSERT INTO ... VALUES with literal values only. Every table name must carry
 * the source prefix; it is moved to the temporary prefix, so nothing outside the import's own
 * tables can be touched. Foreign key names and references follow the rename, and character sets
 * and collations the destination server lacks are mapped to close equivalents.
 */
final class Statement_Rewriter {

	/** MySQL's identifier length limit. */
	const MAX_IDENTIFIER = 64;

	/** Session variables a dump may set. */
	const SET_VARIABLES = array( 'names', 'foreign_key_checks', 'unique_checks', 'sql_mode', 'time_zone', 'sql_notes', 'character_set_client', 'autocommit' );

	/**
	 * Source table prefix.
	 *
	 * @var string
	 */
	private $source_prefix;

	/**
	 * Temporary table prefix.
	 *
	 * @var string
	 */
	private $temp_prefix;

	/**
	 * Supported collations (lowercase name => true); empty means "do not map".
	 *
	 * @var array<string,bool>
	 */
	private $collations;

	/**
	 * Supported character sets (lowercase name => true).
	 *
	 * @var array<string,bool>
	 */
	private $charsets;

	/**
	 * Substitutions made: "from" => "to".
	 *
	 * @var array<string,string>
	 */
	private $mapped = array();

	/**
	 * Constructor.
	 *
	 * @param string   $source_prefix Table prefix in the dump.
	 * @param string   $temp_prefix   Prefix of the temporary tables.
	 * @param string[] $collations    Collations the destination server supports (empty: no mapping).
	 * @param string[] $charsets      Character sets the destination server supports.
	 */
	public function __construct( string $source_prefix, string $temp_prefix, array $collations = array(), array $charsets = array() ) {
		$this->source_prefix = $source_prefix;
		$this->temp_prefix   = $temp_prefix;
		$this->collations    = array_fill_keys( array_map( 'strtolower', $collations ), true );
		$this->charsets      = array_fill_keys( array_map( 'strtolower', $charsets ), true );
	}

	/**
	 * Classifies and rewrites one statement.
	 *
	 * @param string $statement Statement without its semicolon.
	 * @return array{type:string,table:string|null,sql:string|null} type is skip, drop, create or insert;
	 *                                                              table is the temporary table name.
	 * @throws Archive_Exception For statements a SiteFerry dump never contains.
	 */
	public function rewrite( string $statement ): array {
		$head = ltrim( substr( $statement, 0, 65536 ) );

		if ( $this->is_session_set( $statement ) ) {
			return array(
				'type'  => 'skip',
				'table' => null,
				'sql'   => null,
			);
		}

		if ( preg_match( '/^DROP\s+TABLE\s+IF\s+EXISTS\s+`((?:[^`]|``)+)`\s*$/i', $statement, $match ) ) {
			$table = $this->temp_name( $match[1] );
			return array(
				'type'  => 'drop',
				'table' => $table,
				'sql'   => 'DROP TABLE IF EXISTS ' . Sql::identifier( $table ),
			);
		}

		if ( preg_match( '/^CREATE\s+TABLE\s+`((?:[^`]|``)+)`\s*\(/i', $head, $match ) ) {
			$table = $this->temp_name( $match[1] );
			return array(
				'type'  => 'create',
				'table' => $table,
				'sql'   => $this->rewrite_create( $statement, $table, strlen( $match[0] ) ),
			);
		}

		if ( preg_match( '/^INSERT\s+INTO\s+`((?:[^`]|``)+)`\s*\(\s*`(?:[^`]|``)+`(?:\s*,\s*`(?:[^`]|``)+`)*\s*\)\s*VALUES\s*\(/i', $head, $match ) ) {
			$table = $this->temp_name( $match[1] );
			$this->assert_literal_values( substr( $statement, strpos( $statement, '(', strlen( 'INSERT INTO ' ) + strlen( $match[1] ) ) ) );
			$name_end = strpos( $statement, '`', strpos( $statement, '`' ) + 1 );
			while ( '`' === substr( $statement, $name_end + 1, 1 ) ) {
				$name_end = strpos( $statement, '`', $name_end + 2 );
			}
			return array(
				'type'  => 'insert',
				'table' => $table,
				'sql'   => 'INSERT INTO ' . Sql::identifier( $table ) . substr( $statement, $name_end + 1 ),
			);
		}

		throw new Archive_Exception( sprintf( 'The database dump contains a statement SiteFerry does not accept: "%s".', self::preview( $statement ) ), Archive_Exception::UNSUPPORTED );
	}

	/**
	 * Character set and collation substitutions made so far ("from" => "to").
	 *
	 * @return array<string,string>
	 */
	public function mapped(): array {
		return $this->mapped;
	}

	/**
	 * Temporary name for a table from the dump.
	 *
	 * @param string $escaped Name as written between backticks.
	 * @throws Archive_Exception If the table does not carry the source prefix or the name is too long.
	 */
	public function temp_name( string $escaped ): string {
		$name = str_replace( '``', '`', $escaped );
		if ( '' === $this->source_prefix || 0 !== strpos( $name, $this->source_prefix ) || strlen( $name ) === strlen( $this->source_prefix ) ) {
			throw new Archive_Exception( sprintf( 'The database dump refers to the table "%s", which does not use the backup\'s prefix "%s".', self::preview( $name ), $this->source_prefix ), Archive_Exception::UNSUPPORTED );
		}
		$temp = $this->temp_prefix . substr( $name, strlen( $this->source_prefix ) );
		if ( strlen( $temp ) > self::MAX_IDENTIFIER ) {
			throw new Archive_Exception( sprintf( 'The table name "%s" is too long to import with a temporary prefix.', self::preview( $name ) ), Archive_Exception::UNSUPPORTED );
		}
		return $temp;
	}

	/**
	 * Rewrites a CREATE TABLE statement.
	 *
	 * @param string $statement Original statement.
	 * @param string $table     Temporary table name.
	 * @param int    $head      Length of "CREATE TABLE `name` (".
	 * @throws Archive_Exception For table options that reach outside the database.
	 */
	private function rewrite_create( string $statement, string $table, int $head ): string {
		$outside = self::outside_quotes( $statement );
		if ( preg_match( '/\b(DATA|INDEX)\s+DIRECTORY\b|\bCONNECTION\s*=|\bUNION\s*=|\bENGINE\s*=\s*(FEDERATED|CONNECT|MRG_MYISAM|MERGE)\b|\)\s*(AS\s+)?SELECT\b/i', $outside ) ) {
			throw new Archive_Exception( sprintf( 'The table definition of "%s" uses options SiteFerry does not accept.', $table ), Archive_Exception::UNSUPPORTED );
		}

		$sql = 'CREATE TABLE ' . Sql::identifier( $table ) . ' (' . substr( $statement, $head );

		// Foreign keys: referenced tables move to the temporary prefix; constraint names must be
		// unique in the database, so they get the prefix too.
		$sql = (string) preg_replace_callback(
			'/\bREFERENCES\s+`((?:[^`]|``)+)`/i',
			function ( array $match ): string {
				return 'REFERENCES ' . Sql::identifier( $this->temp_name( $match[1] ) );
			},
			$sql
		);
		$sql = (string) preg_replace_callback(
			'/\bCONSTRAINT\s+`((?:[^`]|``)+)`/i',
			function ( array $match ): string {
				$name = substr( $this->temp_prefix . str_replace( '``', '`', $match[1] ), 0, self::MAX_IDENTIFIER );
				return 'CONSTRAINT ' . Sql::identifier( $name );
			},
			$sql
		);

		if ( array() === $this->collations ) {
			return $sql;
		}
		$sql = (string) preg_replace_callback(
			'/\b(CHARSET|CHARACTER\s+SET)(\s*=?\s*)([A-Za-z0-9_]+)/i',
			function ( array $match ): string {
				return $match[1] . $match[2] . $this->map_charset( $match[3] );
			},
			$sql
		);
		return (string) preg_replace_callback(
			'/\bCOLLATE(\s*=?\s*)([A-Za-z0-9_]+)/i',
			function ( array $match ): string {
				return 'COLLATE' . $match[1] . $this->map_collation( $match[2] );
			},
			$sql
		);
	}

	/**
	 * A collation the destination supports: the same one, or the closest of the same character set.
	 *
	 * @param string $collation Collation from the dump.
	 * @throws Archive_Exception If nothing suitable exists.
	 */
	private function map_collation( string $collation ): string {
		$lower = strtolower( $collation );
		if ( isset( $this->collations[ $lower ] ) ) {
			return $collation;
		}
		$underscore = strpos( $lower, '_' );
		$charset    = $this->map_charset( false === $underscore ? $lower : substr( $lower, 0, $underscore ) );
		foreach ( array( '_unicode_520_ci', '_unicode_ci', '_general_ci', '_bin' ) as $suffix ) {
			if ( isset( $this->collations[ $charset . $suffix ] ) ) {
				$this->mapped[ $lower ] = $charset . $suffix;
				return $charset . $suffix;
			}
		}
		throw new Archive_Exception( sprintf( 'This database server does not support the collation %s used by the backup.', $collation ), Archive_Exception::UNSUPPORTED );
	}

	/**
	 * A character set the destination supports.
	 *
	 * @param string $charset Character set from the dump.
	 * @throws Archive_Exception If nothing suitable exists.
	 */
	private function map_charset( string $charset ): string {
		$lower = strtolower( $charset );
		if ( isset( $this->charsets[ $lower ] ) ) {
			return $lower === $charset ? $charset : $lower;
		}
		// utf8mb3 and utf8 are the same character set under two names; utf8mb4 falls back to utf8 last.
		$alternatives = array(
			'utf8mb3' => array( 'utf8' ),
			'utf8'    => array( 'utf8mb3' ),
			'utf8mb4' => array( 'utf8', 'utf8mb3' ),
		);
		foreach ( isset( $alternatives[ $lower ] ) ? $alternatives[ $lower ] : array() as $alternative ) {
			if ( isset( $this->charsets[ $alternative ] ) ) {
				$this->mapped[ $lower ] = $alternative;
				return $alternative;
			}
		}
		throw new Archive_Exception( sprintf( 'This database server does not support the character set %s used by the backup.', $charset ), Archive_Exception::UNSUPPORTED );
	}

	/**
	 * Whether a statement is a session SET the importer may skip.
	 *
	 * @param string $statement Statement.
	 */
	private function is_session_set( string $statement ): bool {
		$inner = $statement;
		if ( preg_match( '/^\/\*!\d+\s+(.*)\*\/$/s', $statement, $match ) ) {
			$inner = trim( $match[1] );
		}
		if ( ! preg_match( '/^SET\s+(.+)$/is', $inner, $match ) ) {
			return false;
		}
		foreach ( explode( ',', self::outside_quotes( $match[1] ) ) as $assignment ) {
			if ( ! preg_match( '/^\s*(?:SESSION\s+|@@SESSION\.|@@)?([a-z_]+)\s*(?:=|\s)/i', $assignment, $name )
				|| ! in_array( strtolower( $name[1] ), self::SET_VARIABLES, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Refuses VALUES lists with anything but literals (no sub-queries or functions).
	 *
	 * @param string $values Text from the first "(" of the column list to the end.
	 * @throws Archive_Exception If something other than a literal appears.
	 */
	private function assert_literal_values( string $values ): void {
		$outside = self::outside_quotes( $values );
		// Words that start with a letter; numbers and hex literals such as 0x1f are single tokens.
		if ( preg_match_all( '/\b[A-Za-z_][A-Za-z0-9_]*/', $outside, $words ) ) {
			foreach ( array_unique( $words[0] ) as $word ) {
				if ( ! in_array( strtoupper( $word ), array( 'VALUES', 'NULL', 'TRUE', 'FALSE', 'B', 'X', '_BINARY', '_UTF8MB4', '_UTF8', '_LATIN1' ), true ) ) {
					throw new Archive_Exception( sprintf( 'An INSERT in the database dump contains "%s", which is not a plain value.', self::preview( $word ) ), Archive_Exception::UNSUPPORTED );
				}
			}
		}
		if ( preg_match( '/[^\s(),.+\-0-9A-Za-z_]/', $outside ) ) {
			throw new Archive_Exception( 'An INSERT in the database dump contains characters that are not part of plain values.', Archive_Exception::UNSUPPORTED );
		}
	}

	/**
	 * The statement with quoted strings and identifiers removed (each replaced by one space).
	 *
	 * @param string $sql SQL text.
	 */
	public static function outside_quotes( string $sql ): string {
		$out    = '';
		$length = strlen( $sql );
		$i      = 0;
		while ( $i < $length ) {
			$next = $i + strcspn( $sql, "'\"`", $i );
			$out .= substr( $sql, $i, $next - $i );
			if ( $next >= $length ) {
				break;
			}
			$quote = $sql[ $next ];
			$j     = $next + 1;
			while ( $j < $length ) {
				$j += strcspn( $sql, '`' === $quote ? '`' : $quote . '\\', $j );
				if ( $j >= $length ) {
					break;
				}
				if ( '\\' === $sql[ $j ] ) {
					$j += 2;
					continue;
				}
				if ( $j + 1 < $length && $quote === $sql[ $j + 1 ] ) {
					$j += 2;
					continue;
				}
				break;
			}
			$out .= ' ';
			$i    = $j + 1;
		}
		return $out;
	}

	/**
	 * Start of a statement for messages.
	 *
	 * @param string $text Text.
	 */
	private static function preview( string $text ): string {
		$text = (string) preg_replace( '/\s+/', ' ', substr( $text, 0, 120 ) );
		return (string) preg_replace( '/[^\x20-\x7e]/', '?', $text );
	}
}
