<?php
/**
 * Streaming, resumable TAR writer.
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace SiteFerry\Archive;

use SiteFerry\Jobs\Slice_Budget;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a POSIX TAR archive (optionally block-gzipped) across several requests.
 *
 * Typical slice:
 *
 *     $writer = $state ? Tar_Writer::resume( $path, $state ) : Tar_Writer::create( $path, $options );
 *     if ( $writer->has_pending() ) { $result = $writer->continue_file( $budget ); }
 *     ... add_file() until the budget runs out or add_file() returns null ...
 *     $state = $writer->checkpoint();   // save with the rest of the job state
 *     $writer->close();
 *
 * A file's header records the size seen when packing started. If the file shrinks while it is
 * read, the rest is filled with zero bytes; if it grows, only the original size is stored. Either
 * way the entry is flagged "changed" and its checksum matches the bytes actually stored.
 */
final class Tar_Writer {

	/** Bytes read from a source file at a time. */
	const CHUNK = 1048576;

	/** Archives are padded to a whole number of 10 KiB records, like other TAR tools. */
	const RECORD = 10240;

	const FILE_MODE = 0644;
	const DIR_MODE  = 0755;

	/**
	 * Output stream.
	 *
	 * @var Output_Stream
	 */
	private $out;

	/**
	 * Normalized options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * File currently being copied, or null.
	 *
	 * @var array|null
	 */
	private $pending = null;

	/**
	 * Checksum of the pending file.
	 *
	 * @var Resumable_Hash|null
	 */
	private $hash = null;

	/**
	 * True once the end-of-archive marker is written.
	 *
	 * @var bool
	 */
	private $finished = false;

	/**
	 * Constructor.
	 *
	 * @param Output_Stream $out     Output.
	 * @param array         $options Normalized options.
	 */
	private function __construct( Output_Stream $out, array $options ) {
		$this->out     = $out;
		$this->options = $options;
	}

	/**
	 * Starts a new archive. The file must not exist yet.
	 *
	 * @param string $path    Archive path.
	 * @param array  $options {
	 *     Optional settings.
	 *
	 *     @type bool $compress       Write .tar.gz in independent gzip members. Default false.
	 *     @type int  $level          gzip level 1-9. Default 6.
	 *     @type int  $block_size     Uncompressed bytes per gzip member. Default 8 MiB.
	 *     @type bool $hash           Compute SHA-256 of each file. Default true.
	 *     @type bool $serialize_hash Save hash state directly when PHP allows it. Default true.
	 * }
	 */
	public static function create( string $path, array $options = array() ): self {
		$options = self::normalize_options( $options );
		$out     = $options['compress']
			? Gzip_Output::open( $path, null, $options['level'], $options['block_size'] )
			: File_Output::open( $path );
		return new self( $out, $options );
	}

	/**
	 * Reopens an archive at a saved checkpoint.
	 *
	 * @param string $path  Archive path.
	 * @param array  $state Data from checkpoint().
	 * @throws Archive_Exception If the state is unusable.
	 */
	public static function resume( string $path, array $state ): self {
		if ( ! isset( $state['version'], $state['output'], $state['options'] ) || 1 !== $state['version'] || ! empty( $state['finished'] ) ) {
			throw new Archive_Exception( __( 'Invalid saved archive state.', 'siteferry' ), Archive_Exception::BAD_STATE );
		}

		$options = self::normalize_options( (array) $state['options'] );
		$out     = $options['compress']
			? Gzip_Output::open( $path, (array) $state['output'], $options['level'], $options['block_size'] )
			: File_Output::open( $path, (array) $state['output'] );

		$writer = new self( $out, $options );
		if ( ! empty( $state['pending'] ) ) {
			$writer->pending = (array) $state['pending'];
			if ( $options['hash'] ) {
				$writer->hash = Resumable_Hash::import( isset( $state['hash'] ) ? (array) $state['hash'] : array(), $writer->replay_pending() );
			}
		}
		return $writer;
	}

	/**
	 * Adds a directory entry.
	 *
	 * @param string $name  Archive path.
	 * @param int    $mtime Modification time.
	 */
	public function add_directory( string $name, int $mtime = 0 ): void {
		$this->assert_idle();
		$name = Path_Guard::check( $name, false ) . '/';
		$this->out->write( Tar_Header::build( $name, Tar_Entry::TYPE_DIR, 0, $mtime, self::DIR_MODE ) );
	}

	/**
	 * Adds a file whose content is in memory (manifest, small generated files).
	 *
	 * @param string $name  Archive path.
	 * @param string $data  Content.
	 * @param int    $mtime Modification time.
	 * @return array Same shape as add_file() results.
	 */
	public function add_string( string $name, string $data, int $mtime = 0 ): array {
		$this->assert_idle();
		$name = Path_Guard::check( $name, false );
		$this->out->write( Tar_Header::build( $name, Tar_Entry::TYPE_FILE, strlen( $data ), $mtime, self::FILE_MODE ) );
		$this->out->write( $data );
		$this->out->write( self::padding( strlen( $data ) ) );

		return array(
			'name'    => $name,
			'size'    => strlen( $data ),
			'mtime'   => $mtime,
			'sha256'  => $this->options['hash'] ? hash( 'sha256', $data ) : null,
			'changed' => false,
		);
	}

	/**
	 * Adds a file from disk, stopping early when the budget runs out.
	 *
	 * @param string            $source File to read.
	 * @param string            $name   Archive path.
	 * @param Slice_Budget|null $budget Work limit for this request; null means no limit.
	 * @return array|null Null if the file is only partly written (call continue_file() in a later
	 *                    slice). Otherwise ['name', 'size', 'mtime', 'sha256', 'changed'], or
	 *                    ['name', 'skipped' => 'missing'|'unreadable'] when nothing was written.
	 */
	public function add_file( string $source, string $name, ?Slice_Budget $budget = null ): ?array {
		$this->assert_idle();
		$name = Path_Guard::check( $name, false );

		$stat = Io::stat( $source );
		if ( false === $stat || ! is_file( $source ) ) {
			return array(
				'name'    => $name,
				'skipped' => 'missing',
			);
		}
		$handle = Io::open( $source, 'rb' );
		if ( false === $handle ) {
			return array(
				'name'    => $name,
				'skipped' => 'unreadable',
			);
		}

		$this->pending = array(
			'source'  => $source,
			'name'    => $name,
			'size'    => (int) $stat['size'],
			'mtime'   => (int) $stat['mtime'],
			'written' => 0,
			'changed' => false,
			'short'   => false,
		);
		$this->hash    = $this->options['hash'] ? new Resumable_Hash() : null;

		$this->out->write( Tar_Header::build( $name, Tar_Entry::TYPE_FILE, $this->pending['size'], $this->pending['mtime'], self::FILE_MODE ) );
		return $this->copy( $handle, $budget );
	}

	/**
	 * Continues the file that an earlier slice left unfinished.
	 *
	 * @param Slice_Budget|null $budget Work limit.
	 * @return array|null Same as add_file().
	 * @throws Archive_Exception If no file is pending.
	 */
	public function continue_file( ?Slice_Budget $budget = null ): ?array {
		if ( null === $this->pending ) {
			throw new Archive_Exception( __( 'No file is being written.', 'siteferry' ), Archive_Exception::BAD_STATE );
		}

		$handle = null;
		if ( ! $this->pending['short'] ) {
			if ( ! $this->source_unchanged() ) {
				$this->pending['changed'] = true;
			}
			$handle = Io::open( $this->pending['source'], 'rb' );
			if ( false === $handle || 0 !== fseek( $handle, $this->pending['written'] ) ) {
				if ( false !== $handle ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				}
				$handle                   = null;
				$this->pending['short']   = true;
				$this->pending['changed'] = true;
			}
		}
		return $this->copy( $handle, $budget );
	}

	/**
	 * Whether a file is partly written.
	 */
	public function has_pending(): bool {
		return null !== $this->pending;
	}

	/**
	 * Progress of the partly written file: ['name', 'size', 'written'], or null.
	 */
	public function pending(): ?array {
		if ( null === $this->pending ) {
			return null;
		}
		return array(
			'name'    => $this->pending['name'],
			'size'    => $this->pending['size'],
			'written' => $this->pending['written'],
		);
	}

	/**
	 * Uncompressed TAR bytes written so far.
	 */
	public function position(): int {
		return $this->out->position();
	}

	/**
	 * Flushes to disk and returns the state to save for resume().
	 *
	 * @return array JSON-safe state.
	 */
	public function checkpoint(): array {
		return array(
			'version'  => 1,
			'options'  => $this->options,
			'output'   => $this->out->checkpoint(),
			'pending'  => $this->pending,
			'hash'     => null !== $this->hash ? $this->hash->export( $this->options['serialize_hash'] ) : null,
			'finished' => $this->finished,
		);
	}

	/**
	 * Writes the end-of-archive marker and closes the file.
	 *
	 * @return array Final state; ['output']['size'] is the archive size on disk.
	 */
	public function finish(): array {
		$this->assert_idle();
		$this->out->write( str_repeat( "\0", 2 * Tar_Header::BLOCK ) );
		$extra = $this->out->position() % self::RECORD;
		if ( $extra > 0 ) {
			$this->out->write( str_repeat( "\0", self::RECORD - $extra ) );
		}
		$this->finished = true;
		$state          = $this->checkpoint();
		$this->close();
		return $state;
	}

	/**
	 * Closes the file without finishing the archive (call checkpoint() first).
	 */
	public function close(): void {
		$this->out->close();
	}

	/**
	 * Copies the pending file's data until it is done or the budget runs out.
	 *
	 * @param resource|null     $handle Source positioned at 'written', or null to pad with zeros.
	 * @param Slice_Budget|null $budget Work limit.
	 * @return array|null Result, or null if paused.
	 */
	private function copy( $handle, ?Slice_Budget $budget ): ?array {
		$size = $this->pending['size'];

		while ( $this->pending['written'] < $size ) {
			$want = min( self::CHUNK, $size - $this->pending['written'] );
			$data = '';
			if ( null !== $handle ) {
				$data = fread( $handle, $want ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $data || '' === $data ) {
					// The file shrank after its header was written: pad with zeros to keep the archive valid.
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					$handle                   = null;
					$data                     = '';
					$this->pending['short']   = true;
					$this->pending['changed'] = true;
				}
			}
			if ( '' === $data ) {
				$data = str_repeat( "\0", $want );
			}

			$this->out->write( $data );
			if ( null !== $this->hash ) {
				$this->hash->update( $data );
			}
			$this->pending['written'] += strlen( $data );

			if ( null !== $budget ) {
				$budget->consume( strlen( $data ) );
				if ( $this->pending['written'] < $size && $budget->exhausted() ) {
					if ( null !== $handle ) {
						fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					}
					return null;
				}
			}
		}

		if ( null !== $handle ) {
			$extra = fread( $handle, 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( is_string( $extra ) && '' !== $extra ) {
				$this->pending['changed'] = true; // The file grew; only the original size was stored.
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( ! $this->pending['short'] && ! $this->source_unchanged() ) {
			$this->pending['changed'] = true;
		}

		$this->out->write( self::padding( $size ) );

		$result        = array(
			'name'    => $this->pending['name'],
			'size'    => $size,
			'mtime'   => $this->pending['mtime'],
			'sha256'  => null !== $this->hash ? $this->hash->hex() : null,
			'changed' => $this->pending['changed'],
		);
		$this->pending = null;
		$this->hash    = null;
		return $result;
	}

	/**
	 * Whether the pending source still has the size and time it had when packing started.
	 */
	private function source_unchanged(): bool {
		$stat = Io::stat( $this->pending['source'] );
		return false !== $stat
			&& (int) $stat['size'] === $this->pending['size']
			&& (int) $stat['mtime'] === $this->pending['mtime'];
	}

	/**
	 * Replay callback for Resumable_Hash on PHP versions that cannot save hash state:
	 * re-reads the already stored part of the source, if the source is unchanged.
	 *
	 * @return callable
	 */
	private function replay_pending(): callable {
		$pending   = $this->pending;
		$unchanged = ! $pending['changed'] && ! $pending['short'] && $this->source_unchanged();

		return static function ( int $length, callable $feed ) use ( $pending, $unchanged ): bool {
			if ( ! $unchanged ) {
				return false;
			}
			$handle = Io::open( $pending['source'], 'rb' );
			if ( false === $handle ) {
				return false;
			}
			$left = $length;
			while ( $left > 0 ) {
				$chunk = fread( $handle, min( self::CHUNK, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				$feed( $chunk );
				$left -= strlen( $chunk );
			}
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return 0 === $left;
		};
	}

	/**
	 * Throws unless the writer can start a new entry.
	 *
	 * @throws Archive_Exception If finished or a file is pending.
	 */
	private function assert_idle(): void {
		if ( $this->finished ) {
			throw new Archive_Exception( __( 'The archive is already finished.', 'siteferry' ), Archive_Exception::BAD_STATE );
		}
		if ( null !== $this->pending ) {
			throw new Archive_Exception( __( 'A file is still being written; call continue_file() first.', 'siteferry' ), Archive_Exception::BAD_STATE );
		}
	}

	/**
	 * Zero bytes that pad data to a whole block.
	 *
	 * @param int $size Data size.
	 */
	private static function padding( int $size ): string {
		$extra = $size % Tar_Header::BLOCK;
		return 0 === $extra ? '' : str_repeat( "\0", Tar_Header::BLOCK - $extra );
	}

	/**
	 * Fills in defaults and clamps option values.
	 *
	 * @param array $options Raw options.
	 */
	private static function normalize_options( array $options ): array {
		return array(
			'compress'       => ! empty( $options['compress'] ),
			'level'          => isset( $options['level'] ) ? max( 1, min( 9, (int) $options['level'] ) ) : Gzip_Output::DEFAULT_LEVEL,
			'block_size'     => isset( $options['block_size'] ) ? (int) $options['block_size'] : Gzip_Output::DEFAULT_BLOCK_SIZE,
			'hash'           => ! isset( $options['hash'] ) || (bool) $options['hash'],
			'serialize_hash' => ! isset( $options['serialize_hash'] ) || (bool) $options['serialize_hash'],
		);
	}
}
