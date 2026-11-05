<?php
/**
 * Encoding and decoding of TAR header blocks (POSIX ustar with pax extended headers).
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and parses 512-byte TAR header blocks.
 *
 * Writing uses plain ustar headers when a path and size fit, and adds a pax extended header
 * ("x" entry) for long or non-ASCII paths, sizes of 8 GiB and more, and out-of-range times.
 * Reading also accepts GNU base-256 numbers; GNU long names are handled by Tar_Reader.
 */
final class Tar_Header {

	const BLOCK = 512;

	/** Largest value an 11-digit octal field can hold (8 GiB - 1). */
	const MAX_OCTAL = 8589934591;

	const NAME_LENGTH   = 100;
	const PREFIX_LENGTH = 155;

	/**
	 * Builds the header block(s) for one entry: an optional pax header with its data, then the ustar header.
	 *
	 * @param string $name  Archive path (already validated), with a trailing "/" for directories.
	 * @param string $type  Typeflag.
	 * @param int    $size  Data size.
	 * @param int    $mtime Modification time.
	 * @param int    $mode  Permission bits.
	 * @return string A multiple of 512 bytes.
	 */
	public static function build( string $name, string $type, int $size, int $mtime, int $mode ): string {
		$pax   = array();
		$split = self::split_name( $name );

		// Non-ASCII names go in a pax record too, so every reader decodes them as UTF-8.
		if ( null === $split || preg_match( '/[^\x20-\x7e]/', $name ) ) {
			$pax['path'] = $name;
			if ( null === $split ) {
				$split = array( '', self::fallback_name( $name ) );
			}
		}

		if ( $size > self::MAX_OCTAL ) {
			$pax['size'] = (string) $size;
			$size        = 0;
		}

		if ( $mtime < 0 || $mtime > self::MAX_OCTAL ) {
			$pax['mtime'] = (string) $mtime;
			$mtime        = max( 0, min( $mtime, self::MAX_OCTAL ) );
		}

		$out = '';
		if ( array() !== $pax ) {
			$records = '';
			foreach ( $pax as $key => $value ) {
				$records .= self::pax_record( $key, $value );
			}
			$pax_name = 'PaxHeader/' . rtrim( self::fallback_name( $name ), '/' );
			$out     .= self::block( substr( $pax_name, 0, self::NAME_LENGTH ), '', 'x', strlen( $records ), $mtime, 0644 );
			$out     .= self::pad( $records );
		}

		return $out . self::block( $split[1], $split[0], $type, $size, $mtime, $mode );
	}

	/**
	 * Parses a header block.
	 *
	 * @param string $block 512 bytes.
	 * @return array{name:string,type:string,size:int,mtime:int,mode:int,linkname:string}
	 * @throws Archive_Exception On a bad checksum or malformed numeric field.
	 */
	public static function parse( string $block ): array {
		if ( self::BLOCK !== strlen( $block ) ) {
			throw new Archive_Exception( __( 'Short TAR header block.', 'siteferry' ), Archive_Exception::TRUNCATED );
		}

		$stored = self::parse_number( substr( $block, 148, 8 ) );
		$blank  = substr_replace( $block, '        ', 148, 8 );
		if ( $stored !== self::checksum( $blank ) && $stored !== array_sum( unpack( 'c*', $blank ) ) ) {
			throw new Archive_Exception( __( 'TAR header checksum mismatch. The archive is damaged or not a TAR file.', 'siteferry' ), Archive_Exception::CORRUPT );
		}

		$name = self::cstring( substr( $block, 0, self::NAME_LENGTH ) );

		// Only POSIX ustar uses the prefix field; GNU tar stores other data there.
		if ( "ustar\0" === substr( $block, 257, 6 ) ) {
			$prefix = self::cstring( substr( $block, 345, self::PREFIX_LENGTH ) );
			if ( '' !== $prefix ) {
				$name = $prefix . '/' . $name;
			}
		}

		$type = $block[156];
		if ( "\0" === $type ) {
			$type = Tar_Entry::TYPE_FILE;
		}

		return array(
			'name'     => $name,
			'type'     => $type,
			'size'     => self::parse_number( substr( $block, 124, 12 ) ),
			'mtime'    => self::parse_number( substr( $block, 136, 12 ) ),
			'mode'     => self::parse_number( substr( $block, 100, 8 ) ) & 07777,
			'linkname' => self::cstring( substr( $block, 157, self::NAME_LENGTH ) ),
		);
	}

	/**
	 * Whether a block is all zero bytes (end-of-archive marker).
	 *
	 * @param string $block 512 bytes.
	 */
	public static function is_zero_block( string $block ): bool {
		return str_repeat( "\0", self::BLOCK ) === $block;
	}

	/**
	 * Encodes one pax record: "<length> <key>=<value>\n", where length counts the whole record.
	 *
	 * @param string $key   Keyword.
	 * @param string $value Value (UTF-8 for paths).
	 */
	public static function pax_record( string $key, string $value ): string {
		$body   = ' ' . $key . '=' . $value . "\n";
		$length = strlen( $body ) + 1;
		while ( strlen( (string) $length ) + strlen( $body ) !== $length ) {
			$length = strlen( (string) $length ) + strlen( $body );
		}
		return $length . $body;
	}

	/**
	 * Parses pax extended header data into keyword => value.
	 *
	 * @param string $data Header data.
	 * @return array<string,string>
	 * @throws Archive_Exception On malformed records.
	 */
	public static function parse_pax( string $data ): array {
		$records = array();
		$offset  = 0;
		$total   = strlen( $data );

		while ( $offset < $total ) {
			if ( "\0" === $data[ $offset ] ) {
				break;
			}
			$space = strpos( $data, ' ', $offset );
			if ( false === $space || $space - $offset > 18 ) {
				throw new Archive_Exception( __( 'Malformed pax header.', 'siteferry' ), Archive_Exception::CORRUPT );
			}
			$digits = substr( $data, $offset, $space - $offset );
			$length = preg_match( '/^[0-9]+$/', $digits ) ? (int) $digits : 0;
			if ( $length < strlen( $digits ) + 4 || $offset + $length > $total || "\n" !== $data[ $offset + $length - 1 ] ) {
				throw new Archive_Exception( __( 'Malformed pax header.', 'siteferry' ), Archive_Exception::CORRUPT );
			}

			$record = substr( $data, $space + 1, $offset + $length - $space - 2 );
			$equals = strpos( $record, '=' );
			if ( false === $equals || 0 === $equals ) {
				throw new Archive_Exception( __( 'Malformed pax header.', 'siteferry' ), Archive_Exception::CORRUPT );
			}
			$records[ substr( $record, 0, $equals ) ] = (string) substr( $record, $equals + 1 );
			$offset                                  += $length;
		}

		return $records;
	}

	/**
	 * Splits a path into ustar prefix and name fields.
	 *
	 * @param string $path Archive path.
	 * @return array{0:string,1:string}|null [prefix, name], or null if the path does not fit.
	 */
	public static function split_name( string $path ): ?array {
		$length = strlen( $path );
		if ( $length <= self::NAME_LENGTH ) {
			return array( '', $path );
		}
		if ( $length > self::PREFIX_LENGTH + 1 + self::NAME_LENGTH ) {
			return null;
		}

		// The name part must be at most 100 bytes, so the separating slash is at index >= length - 101.
		$slash = strpos( $path, '/', $length - self::NAME_LENGTH - 1 );
		while ( false !== $slash && $slash <= self::PREFIX_LENGTH ) {
			$name = (string) substr( $path, $slash + 1 );
			if ( $slash > 0 && '' !== $name ) {
				return array( substr( $path, 0, $slash ), $name );
			}
			$slash = strpos( $path, '/', $slash + 1 );
		}
		return null;
	}

	/**
	 * Parses an octal or GNU base-256 numeric field.
	 *
	 * @param string $field Raw field bytes.
	 * @throws Archive_Exception On malformed or negative values.
	 */
	public static function parse_number( string $field ): int {
		if ( '' !== $field && ( ord( $field[0] ) & 0x80 ) ) {
			if ( ord( $field[0] ) & 0x40 ) {
				throw new Archive_Exception( __( 'Negative number in TAR header.', 'siteferry' ), Archive_Exception::CORRUPT );
			}
			$value = ord( $field[0] ) & 0x3f;
			for ( $i = 1, $n = strlen( $field ); $i < $n; $i++ ) {
				if ( $value > ( PHP_INT_MAX >> 8 ) ) {
					throw new Archive_Exception( __( 'Number too large in TAR header.', 'siteferry' ), Archive_Exception::CORRUPT );
				}
				$value = ( $value << 8 ) | ord( $field[ $i ] );
			}
			return $value;
		}

		$digits = trim( self::cstring( $field ), ' ' );
		if ( '' === $digits ) {
			return 0;
		}
		if ( ! preg_match( '/^[0-7]{1,20}$/', $digits ) ) {
			throw new Archive_Exception( __( 'Malformed number in TAR header.', 'siteferry' ), Archive_Exception::CORRUPT );
		}
		return (int) octdec( $digits );
	}

	/**
	 * Builds one ustar header block.
	 *
	 * @param string $name   Name field (max 100 bytes).
	 * @param string $prefix Prefix field (max 155 bytes).
	 * @param string $type   Typeflag.
	 * @param int    $size   Size (must fit 11 octal digits).
	 * @param int    $mtime  Time (must fit 11 octal digits).
	 * @param int    $mode   Permission bits.
	 */
	private static function block( string $name, string $prefix, string $type, int $size, int $mtime, int $mode ): string {
		$header = str_pad( $name, self::NAME_LENGTH, "\0" )
			. self::octal( $mode, 8 )
			. self::octal( 0, 8 )          // uid.
			. self::octal( 0, 8 )          // gid.
			. self::octal( $size, 12 )
			. self::octal( $mtime, 12 )
			. '        '                   // Checksum placeholder.
			. $type
			. str_repeat( "\0", 100 )      // Link name.
			. "ustar\0" . '00'             // Magic and version.
			. str_repeat( "\0", 64 )       // User and group names.
			. self::octal( 0, 8 )          // Device major.
			. self::octal( 0, 8 )          // Device minor.
			. str_pad( $prefix, self::PREFIX_LENGTH, "\0" )
			. str_repeat( "\0", 12 );

		return substr_replace( $header, sprintf( '%06o', self::checksum( $header ) ) . "\0 ", 148, 8 );
	}

	/**
	 * Zero-padded octal field ending in NUL.
	 *
	 * @param int $value Value.
	 * @param int $width Field width including the NUL.
	 */
	private static function octal( int $value, int $width ): string {
		return str_pad( decoct( $value ), $width - 1, '0', STR_PAD_LEFT ) . "\0";
	}

	/**
	 * Unsigned byte sum of a header.
	 *
	 * @param string $block Header with the checksum field set to spaces.
	 */
	private static function checksum( string $block ): int {
		return array_sum( unpack( 'C*', $block ) );
	}

	/**
	 * Pads data to a multiple of 512 bytes.
	 *
	 * @param string $data Data.
	 */
	private static function pad( string $data ): string {
		$extra = strlen( $data ) % self::BLOCK;
		return 0 === $extra ? $data : $data . str_repeat( "\0", self::BLOCK - $extra );
	}

	/**
	 * Text up to the first NUL.
	 *
	 * @param string $field Raw field.
	 */
	private static function cstring( string $field ): string {
		$nul = strpos( $field, "\0" );
		return false === $nul ? $field : substr( $field, 0, $nul );
	}

	/**
	 * ASCII stand-in for the ustar name field when the real path lives in a pax record.
	 *
	 * Readers without pax support see the base name with unusual bytes replaced by "_".
	 *
	 * @param string $path Archive path.
	 */
	private static function fallback_name( string $path ): string {
		$is_dir = '/' === substr( $path, -1 );
		$path   = rtrim( $path, '/' );
		$slash  = strrpos( $path, '/' );
		$base   = false === $slash ? $path : substr( $path, $slash + 1 );
		$base   = (string) preg_replace( '/[^\x21-\x7e]/', '_', $base );
		$base   = substr( $base, 0, self::NAME_LENGTH - 1 );
		return $is_dir ? $base . '/' : $base;
	}
}
