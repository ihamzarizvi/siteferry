<?php
/**
 * The interface every storage driver implements.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * A place to send backups to and fetch them from.
 *
 * Uploads are split into parts so a job can send one or a few parts per slice: begin() once,
 * put_part() for each part in order (repeating a part after an interruption is safe), then
 * finish(). Every method retries temporary failures itself.
 */
interface Driver {

	/**
	 * Validates settings from the Storage tab.
	 *
	 * @param array $input Raw input.
	 * @return array{settings:array,secrets:array<string,string|null>} Secrets that are null were left
	 *                                                                 empty (keep the saved value).
	 * @throws Storage_Exception On invalid input (CONFIG).
	 */
	public static function normalize( array $input ): array;

	/**
	 * Secret setting names and their labels.
	 *
	 * @return array<string,string>
	 */
	public static function secrets(): array;

	/**
	 * Static information for the admin screen (presets and the like).
	 */
	public static function info(): array;

	/**
	 * Creates the driver from saved settings.
	 *
	 * @param array                $settings  Output of normalize()['settings'].
	 * @param array<string,string> $secrets   Decrypted secrets.
	 * @param Transport            $transport HTTP.
	 */
	public static function create( array $settings, array $secrets, Transport $transport ): Driver;

	/**
	 * Where backups go, for messages ("Amazon S3 bucket "x", folder "y"").
	 */
	public function describe(): string;

	/**
	 * Checks the settings with a small test file: write, read, list, delete.
	 *
	 * @return string[] One line per check.
	 * @throws Storage_Exception Naming the check that failed.
	 */
	public function test(): array;

	/**
	 * Bytes per part for a file of this size (every part but the last has this size).
	 *
	 * @param int $size File size.
	 */
	public function part_size( int $size ): int;

	/**
	 * Starts sending a backup.
	 *
	 * @param string $name File name.
	 * @param int    $size File size.
	 * @return array JSON-safe upload state.
	 */
	public function begin( string $name, int $size ): array;

	/**
	 * Sends one part.
	 *
	 * @param array  $state Upload state.
	 * @param int    $index Part number, from 0.
	 * @param string $bytes Part data.
	 * @return array New upload state.
	 */
	public function put_part( array $state, int $index, string $bytes ): array;

	/**
	 * Completes the upload and checks the stored size.
	 *
	 * @param array $state Upload state.
	 */
	public function finish( array $state ): void;

	/**
	 * Abandons an unfinished upload and removes what was sent.
	 *
	 * @param array $state Upload state.
	 */
	public function abort( array $state ): void;

	/**
	 * SiteFerry backups stored there, newest first.
	 *
	 * @return array<int,array{name:string,size:int,modified:int}>
	 */
	public function list(): array;

	/**
	 * Reads part of a stored backup.
	 *
	 * @param string $name   File name.
	 * @param int    $offset First byte.
	 * @param int    $length Number of bytes.
	 */
	public function download_range( string $name, int $offset, int $length ): string;

	/**
	 * Deletes a stored backup.
	 *
	 * @param string $name File name.
	 */
	public function delete( string $name ): void;
}
