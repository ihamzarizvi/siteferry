<?php
/**
 * Reads SQL statements from a dump file, resumably.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Database;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;

defined( 'ABSPATH' ) || exit;

/**
 * Splits a dump into statements at semicolons outside quotes.
 *
 * Handles '...' and "..." strings with backslash and doubled-quote escapes, `identifiers`, and
 * comment lines (-- and #) and block comments between statements. offset() is the byte position
 * just after the last statement returned, so a later request can continue from there.
 */
final class Statement_Reader {

	/** Largest single statement accepted, to bound memory use. */
	const MAX_STATEMENT_BYTES = 268435456;

	/**
	 * Dump file.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Unparsed text from the current line.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * File offset of the start of $buffer.
	 *
	 * @var int
	 */
	private $buffer_start;

	/**
	 * Offset just after the last statement returned.
	 *
	 * @var int
	 */
	private $offset;

	/**
	 * Opens a dump at a byte offset.
	 *
	 * @param string $file   Dump file.
	 * @param int    $offset Byte offset to start at (0, or an earlier offset()).
	 * @throws Archive_Exception If the file cannot be opened.
	 */
	public function __construct( string $file, int $offset = 0 ) {
		$this->handle = Io::open_or_fail( $file, 'rb' );
		if ( $offset > 0 && 0 !== fseek( $this->handle, $offset ) ) {
			$this->close();
			throw new Archive_Exception( __( 'Cannot resume the database dump at its saved position.', 'siteferry' ), Archive_Exception::BAD_STATE );
		}
		$this->buffer_start = $offset;
		$this->offset       = $offset;
	}

	/**
	 * The next statement without its terminating semicolon, or null at the end of the file.
	 *
	 * @throws Archive_Exception If the dump ends inside a statement or a statement is too large.
	 */
	public function next(): ?string {
		$statement = '';
		$quote     = '';

		while ( true ) {
			if ( '' === $this->buffer ) {
				$line = fgets( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
				if ( false === $line ) {
					if ( '' !== trim( $statement ) ) {
						throw new Archive_Exception( __( 'The database dump ends in the middle of a statement.', 'siteferry' ), Archive_Exception::TRUNCATED );
					}
					return null;
				}
				$this->buffer       = $line;
				$this->buffer_start = (int) ftell( $this->handle ) - strlen( $line );
			}

			if ( '' === $statement && '' === $quote && $this->skip_between_statements() ) {
				continue;
			}

			$length = strlen( $this->buffer );
			$i      = 0;
			while ( $i < $length ) {
				if ( '' === $quote ) {
					$i += strcspn( $this->buffer, "'\"`;", $i );
					if ( $i >= $length ) {
						break;
					}
					if ( ';' === $this->buffer[ $i ] ) {
						$statement         .= substr( $this->buffer, 0, $i );
						$this->buffer       = (string) substr( $this->buffer, $i + 1 );
						$this->buffer_start = $this->buffer_start + $i + 1;
						$this->offset       = $this->buffer_start;
						return trim( $statement );
					}
					$quote = $this->buffer[ $i ];
					++$i;
					continue;
				}

				// Inside a quoted string or identifier: backslash escapes apply to strings only.
				$i += strcspn( $this->buffer, '`' === $quote ? '`' : $quote . '\\', $i );
				if ( $i >= $length ) {
					break;
				}
				if ( '\\' === $this->buffer[ $i ] ) {
					$i += 2;
					continue;
				}
				if ( $i + 1 < $length && $quote === $this->buffer[ $i + 1 ] ) {
					$i += 2; // Doubled quote: an escaped quote character.
					continue;
				}
				$quote = '';
				++$i;
			}

			$statement   .= $this->buffer;
			$this->buffer = '';
			if ( strlen( $statement ) > self::MAX_STATEMENT_BYTES ) {
				throw new Archive_Exception( __( 'A statement in the database dump is too large.', 'siteferry' ), Archive_Exception::CORRUPT );
			}
		}
	}

	/**
	 * Byte offset just after the last statement returned.
	 */
	public function offset(): int {
		return $this->offset;
	}

	/**
	 * Closes the file.
	 */
	public function close(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->handle = null;
		}
	}

	/**
	 * Consumes whitespace and comments before a statement.
	 *
	 * @return bool True if the buffer was consumed and the caller should read more.
	 */
	private function skip_between_statements(): bool {
		$trimmed = ltrim( $this->buffer );
		$skipped = strlen( $this->buffer ) - strlen( $trimmed );

		if ( '' === $trimmed ) {
			$this->buffer = '';
			return true;
		}
		if ( 0 === strpos( $trimmed, '#' ) || preg_match( '/^--(\s|$)/', $trimmed ) ) {
			$this->buffer = '';
			return true;
		}
		if ( 0 === strpos( $trimmed, '/*' ) && 0 !== strpos( $trimmed, '/*!' ) ) {
			$end = strpos( $trimmed, '*/' );
			while ( false === $end ) {
				$line = fgets( $this->handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
				if ( false === $line ) {
					throw new Archive_Exception( __( 'The database dump ends inside a comment.', 'siteferry' ), Archive_Exception::TRUNCATED );
				}
				$trimmed            = $line;
				$skipped            = 0;
				$this->buffer_start = (int) ftell( $this->handle ) - strlen( $line );
				$end                = strpos( $trimmed, '*/' );
			}
			$this->buffer_start += $skipped + $end + 2;
			$this->buffer        = (string) substr( $trimmed, $end + 2 );
			return true;
		}

		$this->buffer        = $trimmed;
		$this->buffer_start += $skipped;
		return false;
	}
}
