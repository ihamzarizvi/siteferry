<?php
/**
 * What Tar_Extractor needs from an archive reader.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

use SiteFerry\Jobs\Slice_Budget;

defined( 'ABSPATH' ) || exit;

/**
 * A resumable, sequential reader of archive entries (TAR, or .wpress through SiteFerry\Wpress\Reader).
 */
interface Entry_Reader {

	/**
	 * Moves to the next entry, skipping any unread data of the current one.
	 *
	 * @return Tar_Entry|null Null at the end of the archive.
	 * @throws Archive_Exception On damaged or truncated archives.
	 */
	public function next(): ?Tar_Entry;

	/**
	 * Data bytes of the current entry not yet read.
	 */
	public function remaining(): int;

	/**
	 * Copies the rest of the current entry into a file, within the budget.
	 *
	 * @param resource            $handle Target file.
	 * @param Slice_Budget|null   $budget Work limit; at least one chunk is copied.
	 * @param Resumable_Hash|null $hash   Hash to update with the data.
	 * @return bool True when the entry is complete.
	 * @throws Archive_Exception On damaged or truncated archives.
	 */
	public function copy_to( $handle, ?Slice_Budget $budget = null, ?Resumable_Hash $hash = null ): bool;

	/**
	 * JSON-safe state for continuing in a later request.
	 */
	public function state(): array;

	/**
	 * Closes the file.
	 */
	public function close(): void;
}
