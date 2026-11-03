<?php
/**
 * Destination for archive bytes.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Where Tar_Writer sends the TAR stream (plain file or block-gzip file).
 */
interface Output_Stream {

	/**
	 * Appends TAR bytes.
	 *
	 * @param string $data Bytes.
	 */
	public function write( string $data ): void;

	/**
	 * Uncompressed TAR bytes written so far.
	 */
	public function position(): int;

	/**
	 * Flushes everything to disk and returns a state that open() can resume from.
	 *
	 * @return array
	 */
	public function checkpoint(): array;

	/**
	 * Closes the file. Call checkpoint() first to keep the data.
	 */
	public function close(): void;
}
