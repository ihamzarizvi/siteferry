<?php
/**
 * Source of archive bytes.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Where Tar_Reader gets the uncompressed TAR stream from.
 */
interface Input_Stream {

	/**
	 * Reads up to $length bytes. Returns fewer only at the end of the stream.
	 *
	 * @param int $length Bytes wanted (> 0).
	 */
	public function read( int $length ): string;

	/**
	 * Skips up to $length bytes and returns how many were skipped.
	 *
	 * @param int $length Bytes to skip.
	 */
	public function skip( int $length ): int;

	/**
	 * Uncompressed position of the next byte.
	 */
	public function position(): int;

	/**
	 * State that open() can resume from.
	 *
	 * @return array
	 */
	public function state(): array;

	/**
	 * Closes the file.
	 */
	public function close(): void;
}
