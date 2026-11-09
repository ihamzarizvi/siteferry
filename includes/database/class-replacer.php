<?php
/**
 * Search and replace that keeps PHP-serialized data valid.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Database;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text: they are returned as JSON (the admin page shows them with textContent), printed by WP-CLI or sent in plain-text emails, never echoed into HTML.

use SiteFerry\Archive\Io;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces strings in a value; inside serialized data, string lengths are recomputed.
 *
 * Replacement uses strtr(): one pass, longest search string first, and text that was just
 * inserted is never searched again. Serialized values are rewritten by walking the serialized
 * format, never by unserializing into objects: unserialize() with allowed_classes => false is
 * only used to check that a value is valid before and after the rewrite, so no class is ever
 * instantiated and no magic method runs. Array keys and object property names are kept as they
 * are; nested serialized strings are handled recursively. Values that look serialized but are
 * already broken get a plain replacement. Custom-serialized objects (C:) are left untouched.
 */
final class Replacer {

	/**
	 * Search => replace.
	 *
	 * @var array<string,string>
	 */
	private $pairs;

	/**
	 * Counters: serialized, broken, opaque.
	 *
	 * @var array<string,int>
	 */
	private $stats = array(
		'serialized' => 0,
		'broken'     => 0,
		'opaque'     => 0,
	);

	/**
	 * Constructor.
	 *
	 * @param array<string,string> $pairs Search => replace. Empty searches and no-op pairs are dropped.
	 */
	public function __construct( array $pairs ) {
		$this->pairs = array();
		foreach ( $pairs as $search => $replace ) {
			$search = (string) $search;
			if ( '' !== $search && $search !== (string) $replace ) {
				$this->pairs[ $search ] = (string) $replace;
			}
		}
	}

	/**
	 * Search strings.
	 *
	 * @return string[]
	 */
	public function searches(): array {
		return array_map( 'strval', array_keys( $this->pairs ) );
	}

	/**
	 * Search => replace pairs in use.
	 *
	 * @return array<string,string>
	 */
	public function pairs(): array {
		return $this->pairs;
	}

	/**
	 * Counters of special cases seen.
	 *
	 * @return array<string,int>
	 */
	public function stats(): array {
		return $this->stats;
	}

	/**
	 * Applies the replacements to one value.
	 *
	 * @param string $value Database value.
	 */
	public function replace( string $value ): string {
		if ( array() === $this->pairs || ! $this->contains_search( $value ) ) {
			return $value;
		}

		$core = trim( $value );
		if ( self::looks_serialized( $core ) ) {
			if ( self::is_valid_serialized( $core ) ) {
				$rewritten = $this->rewrite_serialized( $core );
				if ( null !== $rewritten ) {
					++$this->stats['serialized'];
					$start = strpos( $value, $core );
					return substr( $value, 0, $start ) . $rewritten . substr( $value, $start + strlen( $core ) );
				}
				// Valid, but our walker could not handle it (custom formats): leave it alone.
				++$this->stats['opaque'];
				return $value;
			}
			++$this->stats['broken'];
		}
		return strtr( $value, $this->pairs );
	}

	/**
	 * Whether a value has the shape of PHP-serialized data.
	 *
	 * @param string $value Trimmed value.
	 */
	public static function looks_serialized( string $value ): bool {
		if ( 'N;' === $value ) {
			return true;
		}
		$length = strlen( $value );
		if ( $length < 4 || ':' !== $value[1] ) {
			return false;
		}
		$last = $value[ $length - 1 ];
		return ( ';' === $last || '}' === $last ) && false !== strpos( 'aObsidCE', $value[0] );
	}

	/**
	 * Whether a value unserializes, without instantiating any class.
	 *
	 * @param string $value Value.
	 */
	public static function is_valid_serialized( string $value ): bool {
		if ( 'b:0;' === $value ) {
			return true;
		}
		$result = Io::quiet(
			static function () use ( $value ) {
				return unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			}
		);
		return false !== $result;
	}

	/**
	 * Rewrites a valid serialized value, or returns null if it cannot be done safely.
	 *
	 * @param string $value Trimmed, valid serialized value.
	 */
	private function rewrite_serialized( string $value ): ?string {
		try {
			$position = 0;
			$result   = $this->walk( $value, $position, true );
		} catch ( \UnexpectedValueException $e ) {
			return null;
		}
		if ( strlen( $value ) !== $position || ! self::is_valid_serialized( $result ) ) {
			return null;
		}
		return $result;
	}

	/**
	 * Copies one serialized element starting at $position, replacing inside strings if $replace.
	 *
	 * @param string $data     Serialized data.
	 * @param int    $position In/out: offset of the element.
	 * @param bool   $replace  False for array keys and property names.
	 * @throws \UnexpectedValueException On malformed data.
	 */
	private function walk( string $data, int &$position, bool $replace ): string {
		$type = isset( $data[ $position ] ) ? $data[ $position ] : '';

		switch ( $type ) {
			case 'N':
				$this->expect( $data, $position, 'N;' );
				$position += 2;
				return 'N;';

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $data, ';', $position );
				if ( false === $end || ':' !== substr( $data, $position + 1, 1 ) ) {
					throw new \UnexpectedValueException( 'scalar' );
				}
				$token    = substr( $data, $position, $end - $position + 1 );
				$position = $end + 1;
				return $token;

			case 's':
				list( $string, $position ) = $this->read_string( $data, $position );
				$this->expect( $data, $position, ';' );
				++$position;
				if ( $replace ) {
					$string = $this->replace_inner( $string );
				}
				return 's:' . strlen( $string ) . ':"' . $string . '";';

			case 'a':
				$count = $this->read_count( $data, $position );
				$out   = 'a:' . $count . ':{';
				for ( $i = 0; $i < $count; $i++ ) {
					$out .= $this->walk( $data, $position, false );
					$out .= $this->walk( $data, $position, $replace );
				}
				$this->expect( $data, $position, '}' );
				++$position;
				return $out . '}';

			case 'O':
				list( $class, $position ) = $this->read_string( $data, $position );
				$this->expect( $data, $position, ':' );
				// read_count() expects the ":" before the count one character after its offset.
				$at       = $position - 1;
				$count    = $this->read_count( $data, $at );
				$position = $at;
				$out      = 'O:' . strlen( $class ) . ':"' . $class . '":' . $count . ':{';
				for ( $i = 0; $i < $count; $i++ ) {
					$out .= $this->walk( $data, $position, false );
					$out .= $this->walk( $data, $position, $replace );
				}
				$this->expect( $data, $position, '}' );
				++$position;
				return $out . '}';

			case 'C':
				// Custom serialization: the payload format belongs to the class; copy it unchanged.
				$start              = $position;
				list( , $position ) = $this->read_string( $data, $position );
				$this->expect( $data, $position, ':' );
				$colon = strpos( $data, ':', $position + 1 );
				if ( false === $colon ) {
					throw new \UnexpectedValueException( 'custom' );
				}
				$length   = (int) substr( $data, $position + 1, $colon - $position - 1 );
				$position = $colon + 1;
				$this->expect( $data, $position, '{' );
				$position += 1 + $length;
				$this->expect( $data, $position, '}' );
				++$position;
				$token = substr( $data, $start, $position - $start );
				if ( $replace && $this->contains_search( $token ) ) {
					++$this->stats['opaque'];
				}
				return $token;

			case 'E':
				list( $value, $position ) = $this->read_string( $data, $position );
				$this->expect( $data, $position, ';' );
				++$position;
				return 'E:' . strlen( $value ) . ':"' . $value . '";';
		}

		throw new \UnexpectedValueException( 'type' );
	}

	/**
	 * Replacement inside a serialized string, which may itself hold serialized data.
	 *
	 * @param string $string String contents.
	 */
	private function replace_inner( string $string ): string {
		if ( ! $this->contains_search( $string ) ) {
			return $string;
		}
		if ( self::looks_serialized( $string ) && self::is_valid_serialized( $string ) ) {
			$rewritten = $this->rewrite_serialized( $string );
			if ( null !== $rewritten ) {
				return $rewritten;
			}
		}
		return strtr( $string, $this->pairs );
	}

	/**
	 * Reads <type>:<length>:"<bytes>" at $position; returns [bytes, offset after the closing quote].
	 *
	 * @param string $data     Serialized data.
	 * @param int    $position Offset of the type letter.
	 * @return array{0:string,1:int}
	 * @throws \UnexpectedValueException On malformed data.
	 */
	private function read_string( string $data, int $position ): array {
		$colon = strpos( $data, ':', $position + 2 );
		if ( ':' !== substr( $data, $position + 1, 1 ) || false === $colon ) {
			throw new \UnexpectedValueException( 'string' );
		}
		$digits = substr( $data, $position + 2, $colon - $position - 2 );
		if ( ! preg_match( '/^[0-9]+$/', $digits ) ) {
			throw new \UnexpectedValueException( 'length' );
		}
		$length = (int) $digits;
		$this->expect( $data, $colon + 1, '"' );
		$this->expect( $data, $colon + 2 + $length, '"' );
		return array( (string) substr( $data, $colon + 2, $length ), $colon + 3 + $length );
	}

	/**
	 * Reads ":<count>:{" after a type letter (or class name) at $position; moves past "{".
	 *
	 * @param string $data     Serialized data.
	 * @param int    $position In/out: offset of the type letter (or of the ":" before the count).
	 * @throws \UnexpectedValueException On malformed data.
	 */
	private function read_count( string $data, int &$position ): int {
		$start = $position + 2;
		$colon = strpos( $data, ':', $start );
		if ( false === $colon || ! preg_match( '/^[0-9]+$/', substr( $data, $start, $colon - $start ) ) ) {
			throw new \UnexpectedValueException( 'count' );
		}
		$this->expect( $data, $colon + 1, '{' );
		$count    = (int) substr( $data, $start, $colon - $start );
		$position = $colon + 2;
		return $count;
	}

	/**
	 * Throws unless $data has $expected at $position.
	 *
	 * @param string $data     Data.
	 * @param int    $position Offset.
	 * @param string $expected Expected text.
	 * @throws \UnexpectedValueException On mismatch.
	 */
	private function expect( string $data, int $position, string $expected ): void {
		if ( substr( $data, $position, strlen( $expected ) ) !== $expected ) {
			throw new \UnexpectedValueException( 'expected ' . $expected );
		}
	}

	/**
	 * Whether any search string occurs in a value.
	 *
	 * @param string $value Value.
	 */
	private function contains_search( string $value ): bool {
		foreach ( $this->pairs as $search => $unused ) {
			if ( false !== strpos( $value, (string) $search ) ) {
				return true;
			}
		}
		return false;
	}
}
