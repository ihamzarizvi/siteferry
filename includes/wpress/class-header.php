<?php
/**
 * Entry headers of .wpress archives.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Wpress;

use SiteFerry\Archive\Archive_Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and parses the fixed 4377-byte headers of the .wpress format, the archive format of the
 * All-in-One WP Migration plugin (written here from the format's layout, for interoperability).
 *
 * Layout, every field NUL-padded text: file name (255 bytes), size in decimal (14), modification
 * time as Unix seconds (12), folder relative to wp-content, "." for the top (4088), CRC-32 of the
 * data as eight hex digits (8). The data follows the header unpadded. The archive ends with a
 * header whose name is empty, whose size field holds the length of everything before it and whose
 * CRC field holds the CRC-32 of everything before it. Older archives had a 4096-byte folder field
 * and no CRCs; they are read without the check.
 */
final class Header {

	const SIZE       = 4377;
	const NAME_BYTES = 255;
	const SIZE_BYTES = 14;
	const TIME_BYTES = 12;
	const PATH_BYTES = 4088;
	const CRC_BYTES  = 8;

	const NAME_AT = 0;
	const SIZE_AT = 255;
	const TIME_AT = 269;
	const PATH_AT = 281;
	const CRC_AT  = 4369;

	/**
	 * Header of one file.
	 *
	 * @param string   $name  File name.
	 * @param int      $size  Data size.
	 * @param int      $mtime Modification time.
	 * @param string   $path  Folder relative to wp-content ("." for the top).
	 * @param int|null $crc   CRC-32 of the data, or null to leave the field empty for now.
	 * @throws Archive_Exception If a field does not fit.
	 */
	public static function build( string $name, int $size, int $mtime, string $path, ?int $crc ): string {
		if ( '' === $name || strlen( $name ) > self::NAME_BYTES || false !== strpos( $name, "\0" ) || false !== strpos( $name, '/' ) ) {
			throw new Archive_Exception( sprintf( /* translators: %s: a file or backup name */ __( 'The file name "%s" cannot be stored in a .wpress archive.', 'siteferry' ), $name ), Archive_Exception::UNSUPPORTED );
		}
		if ( '' === $path || strlen( $path ) > self::PATH_BYTES || false !== strpos( $path, "\0" ) ) {
			throw new Archive_Exception( sprintf( /* translators: %s: a file or backup name */ __( 'The folder "%s" cannot be stored in a .wpress archive.', 'siteferry' ), $path ), Archive_Exception::UNSUPPORTED );
		}
		$size_text = (string) $size;
		$time_text = (string) max( 0, $mtime );
		if ( $size < 0 || strlen( $size_text ) > self::SIZE_BYTES || strlen( $time_text ) > self::TIME_BYTES ) {
			throw new Archive_Exception( sprintf( /* translators: %s: a file or backup name */ __( 'The file "%s" is too large for a .wpress archive.', 'siteferry' ), $name ), Archive_Exception::UNSUPPORTED );
		}
		return str_pad( $name, self::NAME_BYTES, "\0" )
			. str_pad( $size_text, self::SIZE_BYTES, "\0" )
			. str_pad( $time_text, self::TIME_BYTES, "\0" )
			. str_pad( $path, self::PATH_BYTES, "\0" )
			. ( null === $crc ? str_repeat( "\0", self::CRC_BYTES ) : Crc32::hex( $crc ) );
	}

	/**
	 * The closing header.
	 *
	 * @param int $length Bytes before it.
	 * @param int $crc    CRC-32 of those bytes.
	 */
	public static function end( int $length, int $crc ): string {
		return str_repeat( "\0", self::NAME_BYTES )
			. str_pad( (string) $length, self::SIZE_BYTES, "\0" )
			. str_repeat( "\0", self::TIME_BYTES + self::PATH_BYTES )
			. Crc32::hex( $crc );
	}

	/**
	 * Parses a header.
	 *
	 * @param string $block Exactly SIZE bytes.
	 * @return array{end:bool,name:string,size:int,mtime:int,path:string,crc:int|null}
	 * @throws Archive_Exception If the header is malformed.
	 */
	public static function parse( string $block ): array {
		if ( strlen( $block ) !== self::SIZE ) {
			throw new Archive_Exception( __( 'The .wpress archive ends in the middle of a header.', 'siteferry' ), Archive_Exception::TRUNCATED );
		}
		$name = self::field( $block, self::NAME_AT, self::NAME_BYTES );
		$size = self::field( $block, self::SIZE_AT, self::SIZE_BYTES );
		$time = self::field( $block, self::TIME_AT, self::TIME_BYTES );
		$crc  = substr( $block, self::CRC_AT, self::CRC_BYTES );

		if ( '' === $name ) {
			return array(
				'end'   => true,
				'name'  => '',
				'size'  => ctype_digit( $size ) ? (int) $size : 0,
				'mtime' => 0,
				'path'  => '',
				'crc'   => preg_match( '/^[0-9a-f]{8}$/', $crc ) ? (int) hexdec( $crc ) : null,
			);
		}
		if ( ! ctype_digit( $size ) || ( '' !== $time && ! ctype_digit( $time ) ) ) {
			throw new Archive_Exception( __( 'The .wpress archive has a damaged header.', 'siteferry' ), Archive_Exception::CORRUPT );
		}

		if ( preg_match( '/^[0-9a-f]{8}$/', $crc ) ) {
			$path  = self::field( $block, self::PATH_AT, self::PATH_BYTES );
			$value = (int) hexdec( $crc );
		} else {
			// Older layout: a 4096-byte folder field and no CRC.
			$path  = self::field( $block, self::PATH_AT, self::PATH_BYTES + self::CRC_BYTES );
			$value = null;
		}
		if ( false !== strpos( $name . $path, "\0" ) || '' === $path ) {
			throw new Archive_Exception( __( 'The .wpress archive has a damaged header.', 'siteferry' ), Archive_Exception::CORRUPT );
		}
		return array(
			'end'   => false,
			'name'  => $name,
			'size'  => (int) $size,
			'mtime' => (int) $time,
			'path'  => $path,
			'crc'   => $value,
		);
	}

	/**
	 * A NUL-padded field without its padding.
	 *
	 * @param string $block  Header.
	 * @param int    $at     Offset.
	 * @param int    $length Length.
	 */
	private static function field( string $block, int $at, int $length ): string {
		return rtrim( substr( $block, $at, $length ), "\0" );
	}
}
