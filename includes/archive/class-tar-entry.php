<?php
/**
 * One entry read from a TAR archive.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

defined( 'ABSPATH' ) || exit;

/**
 * Header data of one archive member, after pax and GNU extensions are applied.
 */
final class Tar_Entry {

	const TYPE_FILE       = '0';
	const TYPE_HARDLINK   = '1';
	const TYPE_SYMLINK    = '2';
	const TYPE_CHAR       = '3';
	const TYPE_BLOCK      = '4';
	const TYPE_DIR        = '5';
	const TYPE_FIFO       = '6';
	const TYPE_CONTIGUOUS = '7';

	/**
	 * Path inside the archive, exactly as stored (not yet validated).
	 *
	 * @var string
	 */
	public $name = '';

	/**
	 * Typeflag character.
	 *
	 * @var string
	 */
	public $type = self::TYPE_FILE;

	/**
	 * Data size in bytes.
	 *
	 * @var int
	 */
	public $size = 0;

	/**
	 * Modification time (Unix seconds).
	 *
	 * @var int
	 */
	public $mtime = 0;

	/**
	 * Permission bits.
	 *
	 * @var int
	 */
	public $mode = 0;

	/**
	 * Link target for hard and symbolic links.
	 *
	 * @var string
	 */
	public $linkname = '';

	/**
	 * True for GNU sparse files, which are not supported.
	 *
	 * @var bool
	 */
	public $sparse = false;

	/**
	 * Regular file whose data can be extracted.
	 */
	public function is_file(): bool {
		return ( self::TYPE_FILE === $this->type || self::TYPE_CONTIGUOUS === $this->type ) && ! $this->sparse;
	}

	/**
	 * Directory.
	 */
	public function is_dir(): bool {
		return self::TYPE_DIR === $this->type;
	}

	/**
	 * Short description of the type, for error messages.
	 */
	public function type_label(): string {
		$labels = array(
			self::TYPE_FILE       => 'file',
			self::TYPE_HARDLINK   => 'hard link',
			self::TYPE_SYMLINK    => 'symbolic link',
			self::TYPE_CHAR       => 'character device',
			self::TYPE_BLOCK      => 'block device',
			self::TYPE_DIR        => 'directory',
			self::TYPE_FIFO       => 'FIFO',
			self::TYPE_CONTIGUOUS => 'file',
		);
		if ( $this->sparse ) {
			return 'sparse file';
		}
		return isset( $labels[ $this->type ] ) ? $labels[ $this->type ] : sprintf( 'unknown type 0x%02x', ord( $this->type ) );
	}

	/**
	 * JSON-safe representation for saved job state.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'name'     => $this->name,
			'type'     => $this->type,
			'size'     => $this->size,
			'mtime'    => $this->mtime,
			'mode'     => $this->mode,
			'linkname' => $this->linkname,
			'sparse'   => $this->sparse,
		);
	}

	/**
	 * Rebuilds an entry from to_array() output.
	 *
	 * @param array $data Saved entry.
	 */
	public static function from_array( array $data ): self {
		$entry           = new self();
		$entry->name     = (string) $data['name'];
		$entry->type     = (string) $data['type'];
		$entry->size     = (int) $data['size'];
		$entry->mtime    = (int) $data['mtime'];
		$entry->mode     = (int) $data['mode'];
		$entry->linkname = (string) $data['linkname'];
		$entry->sparse   = (bool) $data['sparse'];
		return $entry;
	}
}
