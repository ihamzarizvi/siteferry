<?php
/**
 * CRC-32 that can be continued across chunks and requests.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Wpress;

defined( 'ABSPATH' ) || exit;

/**
 * CRC-32 (the zip/gzip polynomial, PHP's "crc32b") as integers, with combine(): the CRC of A.B from
 * the CRCs of A and B and the length of B. That lets a large file be checked chunk by chunk, with
 * only an integer kept between requests.
 *
 * combine() applies "append n zero bytes" to the first CRC as a linear map over GF(2) (a 32x32 bit
 * matrix), using precomputed matrices for 2^k bytes, then adds the second CRC.
 */
final class Crc32 {

	/** Reversed CRC-32 polynomial. */
	const POLYNOMIAL = 0xedb88320;

	/**
	 * Matrices for appending 2^k zero bytes, k = 0..47.
	 *
	 * @var array<int,int[]>|null
	 */
	private static $powers = null;

	/**
	 * CRC-32 of a string.
	 *
	 * @param string $data Data.
	 */
	public static function of( string $data ): int {
		return (int) hexdec( hash( 'crc32b', $data ) );
	}

	/**
	 * CRC-32 of A followed by B.
	 *
	 * @param int $crc_a    CRC of A.
	 * @param int $crc_b    CRC of B.
	 * @param int $length_b Length of B in bytes.
	 */
	public static function combine( int $crc_a, int $crc_b, int $length_b ): int {
		if ( $length_b <= 0 ) {
			return $crc_a;
		}
		$powers = self::powers();
		$k      = 0;
		while ( $length_b > 0 ) {
			if ( $length_b & 1 ) {
				$crc_a = self::times( $powers[ $k ], $crc_a );
			}
			$length_b >>= 1;
			++$k;
		}
		return ( $crc_a ^ $crc_b ) & 0xffffffff;
	}

	/**
	 * CRC-32 after appending data to something whose CRC is known.
	 *
	 * @param int    $crc  CRC so far.
	 * @param string $data More data.
	 */
	public static function update( int $crc, string $data ): int {
		return self::combine( $crc, self::of( $data ), strlen( $data ) );
	}

	/**
	 * Eight hex digits, as .wpress headers store them.
	 *
	 * @param int $crc CRC.
	 */
	public static function hex( int $crc ): string {
		return sprintf( '%08x', $crc & 0xffffffff );
	}

	/**
	 * Matrix times vector over GF(2).
	 *
	 * @param int[] $matrix 32 columns.
	 * @param int   $vector Vector.
	 */
	private static function times( array $matrix, int $vector ): int {
		$sum = 0;
		$i   = 0;
		while ( 0 !== $vector ) {
			if ( $vector & 1 ) {
				$sum ^= $matrix[ $i ];
			}
			$vector = ( $vector >> 1 ) & 0x7fffffff;
			++$i;
		}
		return $sum;
	}

	/**
	 * The matrix applied twice.
	 *
	 * @param int[] $matrix Matrix.
	 * @return int[]
	 */
	private static function square( array $matrix ): array {
		$square = array();
		for ( $n = 0; $n < 32; $n++ ) {
			$square[ $n ] = self::times( $matrix, $matrix[ $n ] );
		}
		return $square;
	}

	/**
	 * Matrices for 1, 2, 4, ... zero bytes.
	 *
	 * @return array<int,int[]>
	 */
	private static function powers(): array {
		if ( null === self::$powers ) {
			// One zero bit: shift right, folding in the polynomial when the low bit falls off.
			$bit = array( self::POLYNOMIAL );
			$row = 1;
			for ( $n = 1; $n < 32; $n++ ) {
				$bit[ $n ] = $row;
				$row     <<= 1;
			}
			$byte         = self::square( self::square( self::square( $bit ) ) );
			self::$powers = array( $byte );
			for ( $k = 1; $k < 48; $k++ ) {
				self::$powers[ $k ] = self::square( self::$powers[ $k - 1 ] );
			}
		}
		return self::$powers;
	}
}
