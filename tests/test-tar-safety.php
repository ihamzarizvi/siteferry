<?php
/**
 * Path validation and refusal of malicious archives.
 *
 * Run: php tests/test-tar-safety.php [--keep]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Archive\Archive_Exception;
use SiteFerry\Archive\Io;
use SiteFerry\Archive\Path_Guard;
use SiteFerry\Archive\Tar_Header;

echo "TAR safety tests\n";

$dir = Sf_Test::temp_dir( 'safety' );

Sf_Test::run(
	'safe paths are accepted and normalized',
	static function () {
		$cases = array(
			'wp-content/a.txt'               => 'wp-content/a.txt',
			'./wp-content/a.txt'             => 'wp-content/a.txt',
			'wp-content//uploads/./x/'       => 'wp-content/uploads/x',
			'wp-content/日本語/😀.txt'        => 'wp-content/日本語/😀.txt',
			'wp-content/..hidden/a..b'       => 'wp-content/..hidden/a..b',
			'database.sql'                   => 'database.sql',
		);
		foreach ( $cases as $input => $normalized ) {
			Sf_Test::same( $normalized, Path_Guard::check( $input, false ), "generic $input:" );
			Sf_Test::same( $normalized, Path_Guard::check( $input, true ), "windows $input:" );
		}
	}
);

Sf_Test::run(
	'unsafe paths are rejected on every platform',
	static function () {
		$bad = array(
			'',
			'.',
			'./',
			'..',
			'../x',
			'wp-content/../../x',
			'wp-content/..',
			'/etc/passwd',
			'//server/share/x',
			'\\\\server\\share\\x',
			'..\\x',
			'wp-content\\..\\..\\x',
			'C:/Windows/x',
			'c:x',
			'D:',
			"a\0b",
			"a\nb",
			"a\tb",
			"bad\xff\xfe",
			"\xc0\xaf", // Overlong "/" encoding.
		);
		foreach ( $bad as $path ) {
			foreach ( array( false, true ) as $windows ) {
				Sf_Test::throws(
					Archive_Exception::UNSAFE_PATH,
					static function () use ( $path, $windows ) {
						Path_Guard::check( $path, $windows );
					},
					bin2hex( $path ) . ( $windows ? ' (windows)' : '' )
				);
			}
		}
	}
);

Sf_Test::run(
	'Windows-only rules: streams, reserved names, trailing dots and spaces',
	static function () {
		foreach ( array( 'a/b:c', 'a/file.txt:$DATA', 'a/CON', 'a/con.txt', 'NUL', 'a/LPT1.log', 'a/com9', 'a/b.', 'a/b ', 'a/x?y', 'a/x*y', 'a/x|y', 'a/x"y', 'a/<x>' ) as $path ) {
			Sf_Test::throws(
				Archive_Exception::UNSAFE_PATH,
				static function () use ( $path ) {
					Path_Guard::check( $path, true );
				},
				$path
			);
			Sf_Test::same( $path, Path_Guard::check( $path, false ), "allowed off Windows: $path:" );
		}
	}
);

/**
 * Builds a crafted archive, tries to extract it, and checks nothing escaped the staging folder.
 *
 * @param string   $dir     Work folder.
 * @param string   $label   Case label.
 * @param string[] $entries Raw entries.
 * @param int      $code    Expected Archive_Exception code.
 * @param array    $options Tar_Extractor options.
 */
function sf_expect_refusal( string $dir, string $label, array $entries, int $code, array $options = array() ): void {
	$case    = $dir . '/' . $label;
	$staging = $case . '/staging/inner';
	mkdir( $staging, 0755, true );
	$archive = $case . '/crafted.tar';
	sf_write_raw_archive( $archive, $entries );

	$error = Sf_Test::throws(
		$code,
		static function () use ( $archive, $staging, $options ) {
			sf_unpack( $archive, $staging, $options );
		},
		$label . ':'
	);
	Sf_Test::ok( '' !== $error->getMessage(), 'message expected' );

	// Nothing may appear next to or above the staging folder.
	Sf_Test::same( array( 'crafted.tar', 'staging' ), array_values( array_diff( scandir( $case ), array( '.', '..' ) ) ), $label . ' case folder:' );
	Sf_Test::same( array( 'inner' ), array_values( array_diff( scandir( $case . '/staging' ), array( '.', '..' ) ) ), $label . ' staging parent:' );
	Sf_Test::ok( ! file_exists( $dir . '/evil.txt' ), $label . ': evil.txt escaped' );
}

Sf_Test::run(
	'link, device, FIFO and sparse entries are refused',
	static function () use ( $dir ) {
		$types = array(
			'symlink'  => '2',
			'hardlink' => '1',
			'chardev'  => '3',
			'blockdev' => '4',
			'fifo'     => '6',
			'gnu-sparse' => 'S',
		);
		foreach ( $types as $label => $type ) {
			sf_expect_refusal(
				$dir,
				$label,
				array(
					sf_raw_entry( 'wp-content/ok.txt', '0', 'fine' ),
					sf_raw_entry( 'wp-content/' . $label, $type ),
				),
				Archive_Exception::UNSUPPORTED
			);
		}
		sf_expect_refusal(
			$dir,
			'pax-sparse',
			array(
				sf_raw_entry( 'PaxHeader/x', 'x', Tar_Header::pax_record( 'GNU.sparse.major', '1' ) . Tar_Header::pax_record( 'GNU.sparse.minor', '0' ) ),
				sf_raw_entry( 'wp-content/sparse.bin', '0', 'data' ),
			),
			Archive_Exception::UNSUPPORTED
		);
	}
);

Sf_Test::run(
	'traversal through the ustar name, the pax path and a GNU long name is refused',
	static function () use ( $dir ) {
		sf_expect_refusal( $dir, 'dotdot', array( sf_raw_entry( '../../../evil.txt', '0', 'x' ) ), Archive_Exception::UNSAFE_PATH );
		sf_expect_refusal( $dir, 'dotdot-mid', array( sf_raw_entry( 'wp-content/../../../../evil.txt', '0', 'x' ) ), Archive_Exception::UNSAFE_PATH );
		sf_expect_refusal(
			$dir,
			'pax-path',
			array(
				sf_raw_entry( 'PaxHeader/benign.txt', 'x', Tar_Header::pax_record( 'path', '../../../evil.txt' ) ),
				sf_raw_entry( 'wp-content/benign.txt', '0', 'x' ),
			),
			Archive_Exception::UNSAFE_PATH
		);
		sf_expect_refusal(
			$dir,
			'gnu-longname',
			array(
				sf_raw_entry( '././@LongLink', 'L', '../../../evil.txt' . "\0" ),
				sf_raw_entry( 'wp-content/benign.txt', '0', 'x' ),
			),
			Archive_Exception::UNSAFE_PATH
		);
		sf_expect_refusal( $dir, 'absolute', array( sf_raw_entry( '/tmp/evil.txt', '0', 'x' ) ), Archive_Exception::UNSAFE_PATH );
		sf_expect_refusal( $dir, 'drive', array( sf_raw_entry( 'C:/evil.txt', '0', 'x' ) ), Archive_Exception::UNSAFE_PATH );
		sf_expect_refusal( $dir, 'drive-relative', array( sf_raw_entry( 'C:evil.txt', '0', 'x' ) ), Archive_Exception::UNSAFE_PATH );
		sf_expect_refusal( $dir, 'backslash', array( sf_raw_entry( '..\\..\\..\\evil.txt', '0', 'x' ) ), Archive_Exception::UNSAFE_PATH );
		sf_expect_refusal( $dir, 'dir-dotdot', array( sf_raw_entry( '../../evil-dir/', '5' ) ), Archive_Exception::UNSAFE_PATH );
	}
);

Sf_Test::run(
	'entries outside the allowed top-level folders are refused',
	static function () use ( $dir ) {
		$allow = static function ( string $path ): bool {
			return in_array( $path, array( 'siteferry.json', 'database.sql', 'siteferry.sha256' ), true )
				|| 'wp-content' === $path
				|| 0 === strpos( $path, 'wp-content/' );
		};
		sf_expect_refusal(
			$dir,
			'allow-list',
			array(
				sf_raw_entry( 'wp-content/ok.txt', '0', 'fine' ),
				sf_raw_entry( 'wp-admin/evil.php', '0', '<?php' ),
			),
			Archive_Exception::UNSAFE_PATH,
			array( 'allow' => $allow )
		);
		sf_expect_refusal(
			$dir,
			'allow-prefix',
			array( sf_raw_entry( 'wp-content-evil/x.php', '0', 'x' ) ),
			Archive_Exception::UNSAFE_PATH,
			array( 'allow' => $allow )
		);
	}
);

Sf_Test::run(
	'file and folder name clashes inside staging are refused',
	static function () use ( $dir ) {
		sf_expect_refusal(
			$dir,
			'file-then-dir',
			array(
				sf_raw_entry( 'wp-content/a', '0', 'x' ),
				sf_raw_entry( 'wp-content/a/b.txt', '0', 'x' ),
			),
			Archive_Exception::UNSAFE_PATH
		);
		sf_expect_refusal(
			$dir,
			'dir-then-file',
			array(
				sf_raw_entry( 'wp-content/a/', '5' ),
				sf_raw_entry( 'wp-content/a', '0', 'x' ),
			),
			Archive_Exception::UNSAFE_PATH
		);
	}
);

/**
 * Extracting through a link that points outside the staging folder must fail without writing there.
 *
 * @param string $case Folder with staging/wp-content linked to outside/.
 */
function sf_expect_link_refusal( string $case ): void {
	$outside = $case . '/outside';
	$staging = $case . '/staging';
	$archive = $case . '/crafted.tar';
	sf_write_raw_archive( $archive, array( sf_raw_entry( 'wp-content/uploads/evil.txt', '0', 'x' ) ) );
	Sf_Test::throws(
		Archive_Exception::UNSAFE_PATH,
		static function () use ( $archive, $staging ) {
			sf_unpack( $archive, $staging );
		}
	);
	Sf_Test::same( array(), array_values( array_diff( scandir( $outside ), array( '.', '..' ) ) ), 'outside folder:' );
}

Sf_Test::run(
	'a symbolic link planted inside the staging folder cannot redirect extraction',
	static function () use ( $dir ) {
		$case = $dir . '/planted-symlink';
		mkdir( $case . '/outside', 0755, true );
		mkdir( $case . '/staging', 0755, true );
		$linked = Io::quiet(
			static function () use ( $case ) {
				return symlink( $case . '/outside', $case . '/staging/wp-content' );
			}
		);
		if ( ! $linked ) {
			Sf_Test::skip( 'this account cannot create symbolic links' );
		}
		sf_expect_link_refusal( $case );
	}
);

Sf_Test::run(
	'a Windows junction planted inside the staging folder cannot redirect extraction',
	static function () {
		// PHP cannot create junctions; tests/run-tests.ps1 prepares one and passes its folder here.
		$case = (string) getenv( 'SITEFERRY_TEST_JUNCTION_CASE' );
		if ( '' === $case || ! is_dir( $case . '/staging/wp-content' ) ) {
			Sf_Test::skip( 'no junction prepared (run through tests/run-tests.ps1 on Windows)' );
		}
		Sf_Test::same( false, is_link( $case . '/staging/wp-content' ), 'PHP does not report junctions as links:' );
		sf_expect_link_refusal( $case );
	}
);

Sf_Test::run(
	'skip: left-out entries are not written, also across slices; they are still checked first',
	static function () use ( $dir ) {
		$archive = $dir . '/skip.tar';
		$writer  = SiteFerry\Archive\Tar_Writer::create( $archive );
		$writer->add_string( 'siteferry.json', '{}', 1700000000 );
		$writer->add_directory( 'wp-content/', 1700000000 );
		$writer->add_string( 'wp-content/plugins/a/a.php', str_repeat( 'a', 5000 ), 1700000000 );
		$writer->add_string( 'wp-content/plugins/b/b.php', str_repeat( 'b', 7000 ), 1700000000 );
		$writer->add_string( 'wp-content/uploads/big.bin', random_bytes( 20000 ), 1700000000 );
		$writer->add_string( 'wp-content/plugins/a/readme.txt', 'readme', 1700000000 );
		$writer->finish();
		$writer->close();

		$skip = static function ( string $path ): bool {
			return 0 === strpos( $path, 'wp-content/uploads' ) || 0 === strpos( $path, 'wp-content/plugins/b' );
		};
		foreach ( array( null, 512 ) as $slice ) {
			$dest = $dir . '/skip-' . ( null === $slice ? 'whole' : 'slices' );
			mkdir( $dest );
			$result = sf_unpack( $archive, $dest, array( 'skip' => $skip ), $slice );
			Sf_Test::same( array( 'siteferry.json', 'wp-content', 'wp-content/plugins/a/a.php', 'wp-content/plugins/a/readme.txt' ), array_keys( $result['entries'] ), ( null === $slice ? 'one pass' : 'slices' ) . ':' );
			Sf_Test::same( str_repeat( 'a', 5000 ), file_get_contents( $dest . '/wp-content/plugins/a/a.php' ) );
			Sf_Test::same( false, file_exists( $dest . '/wp-content/plugins/b' ), 'nothing of a skipped folder:' );
			Sf_Test::same( false, file_exists( $dest . '/wp-content/uploads' ) );
			if ( null !== $slice ) {
				Sf_Test::ok( $result['slices'] > 1, 'several slices (' . $result['slices'] . ')' );
			}
		}

		// A slice that ends right after an entry was skipped continues past its data.
		$dest = $dir . '/skip-each';
		mkdir( $dest );
		$state  = null;
		$slices = 0;
		do {
			$reader    = SiteFerry\Archive\Tar_Reader::open( $archive, null === $state ? null : $state['reader'] );
			$extractor = new SiteFerry\Archive\Tar_Extractor( $reader, $dest, array( 'skip' => $skip ), $state );
			$done      = $extractor->run( new SiteFerry\Jobs\Slice_Budget( 0.0 ) );
			$state     = json_decode( (string) json_encode( $extractor->state() ), true );
			$reader->close();
			++$slices;
		} while ( ! $done && $slices < 50 );
		Sf_Test::ok( $done && $slices >= 6, 'one entry per slice (' . $slices . ' slices)' );
		Sf_Test::same( 'readme', file_get_contents( $dest . '/wp-content/plugins/a/readme.txt' ), 'the entry after two skipped ones:' );
		Sf_Test::same( false, file_exists( $dest . '/wp-content/uploads' ) );

		// Skipping never lets an entry through that would be refused.
		$bad = $dir . '/skip-bad.tar';
		sf_write_raw_archive( $bad, array( sf_raw_entry( 'wp-content/ok.txt', '0', 'ok' ), sf_raw_entry( '../outside.txt', '0', 'x' ) ) );
		mkdir( $dir . '/skip-bad' );
		Sf_Test::throws(
			Archive_Exception::UNSAFE_PATH,
			static function () use ( $bad, $dir ) {
				sf_unpack(
					$bad,
					$dir . '/skip-bad',
					array(
						'skip' => static function (): bool {
							return true;
						},
					)
				);
			},
			'an unsafe path is refused even when it would be skipped'
		);
		$refused = $dir . '/skip-refused.tar';
		sf_write_raw_archive( $refused, array( sf_raw_entry( 'wp-config.php', '0', 'x' ) ) );
		mkdir( $dir . '/skip-refused' );
		Sf_Test::throws(
			Archive_Exception::UNSAFE_PATH,
			static function () use ( $refused, $dir ) {
				sf_unpack(
					$refused,
					$dir . '/skip-refused',
					array(
						'allow' => static function ( string $path ): bool {
							return 0 === strpos( $path, 'wp-content/' );
						},
						'skip'  => static function (): bool {
							return true;
						},
					)
				);
			},
			'an entry outside the allowed folders is refused even when it would be skipped'
		);
	}
);

exit( Sf_Test::finish( 'test-tar-safety' ) );
