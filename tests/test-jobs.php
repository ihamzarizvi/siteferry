<?php
/**
 * Job files, tokens, locking and the slice runner.
 *
 * Run: php tests/test-jobs.php [--keep]
 *
 * @package SiteFerry
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require __DIR__ . '/bootstrap.php';

use SiteFerry\Jobs\Job;
use SiteFerry\Jobs\Job_Exception;
use SiteFerry\Jobs\Runner;
use SiteFerry\Jobs\Slice_Budget;
use SiteFerry\Jobs\Step;
use SiteFerry\Security\Storage;

echo "Job tests\n";

/**
 * Test step: needs three calls, writes a temporary file, removes it on cleanup.
 */
final class Sf_Counting_Step implements Step {
	/**
	 * Calls made, for assertions.
	 *
	 * @var array<string,int>
	 */
	public static $cleanups = array();

	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    Job.
	 * @param Slice_Budget $budget Budget.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		$state          = $job->state( 'count' );
		$state['calls'] = ( isset( $state['calls'] ) ? $state['calls'] : 0 ) + 1;
		$job->set_state( 'count', $state );
		file_put_contents( $job->path( 'count.tmp' ), (string) $state['calls'] );
		$job->set_progress( 'counting', $state['calls'], 3 );
		return $state['calls'] >= 3;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job Job.
	 */
	public function cleanup( Job $job ): void {
		self::$cleanups['count'] = ( isset( self::$cleanups['count'] ) ? self::$cleanups['count'] : 0 ) + 1;
		$job->delete_file( 'count.tmp' );
	}
}

/**
 * Test step: fails if the job option says so.
 */
final class Sf_Failing_Step implements Step {
	/**
	 * {@inheritDoc}
	 *
	 * @param Job          $job    Job.
	 * @param Slice_Budget $budget Budget.
	 * @throws RuntimeException When asked to.
	 */
	public function run( Job $job, Slice_Budget $budget ): bool {
		if ( $job->option( 'fail' ) ) {
			throw new RuntimeException( 'Simulated failure' );
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Job $job Job.
	 */
	public function cleanup( Job $job ): void {
		Sf_Counting_Step::$cleanups['fail'] = ( isset( Sf_Counting_Step::$cleanups['fail'] ) ? Sf_Counting_Step::$cleanups['fail'] : 0 ) + 1;
	}
}

Runner::register( 'test.count', Sf_Counting_Step::class );
Runner::register( 'test.fail', Sf_Failing_Step::class );

$base = Sf_Test::temp_dir( 'jobs' );

Sf_Test::run(
	'create, save and reload a job; the token is checked in constant time',
	static function () use ( $base ) {
		list( $job, $token ) = Job::create( $base, 'export', array( 'test.count' ), array( 'answer' => 42 ) );
		Sf_Test::ok( (bool) preg_match( '/^[a-f0-9]{16}$/', $job->id() ), 'id format' );
		Sf_Test::same( 64, strlen( $token ) );
		Sf_Test::ok( false === strpos( (string) file_get_contents( $job->path( 'job.json' ) ), $token ), 'the plain token must not be stored' );

		$job->set_state( 'step', array( 'unicode' => 'café 😀', 'n' => 7 ) );
		$job->save();
		$again = Job::load( $base, $job->id() );
		Sf_Test::same( 42, $again->option( 'answer' ) );
		Sf_Test::same( array( 'unicode' => 'café 😀', 'n' => 7 ), $again->state( 'step' ) );
		Sf_Test::ok( $again->check_token( $token ), 'right token' );
		Sf_Test::ok( ! $again->check_token( str_repeat( '0', 64 ) ), 'wrong token' );
		Sf_Test::ok( ! $again->check_token( '' ), 'empty token' );
	}
);

Sf_Test::run(
	'malformed and unknown job ids are refused',
	static function () use ( $base ) {
		foreach ( array( '../../etc', 'ABCDEF0123456789', '0123', str_repeat( 'a', 16 ) ) as $id ) {
			try {
				Job::load( $base, $id );
				throw new RuntimeException( 'loaded ' . $id );
			} catch ( Job_Exception $e ) {
				Sf_Test::same( Job_Exception::NOT_FOUND, $e->getCode(), $id );
			}
		}
	}
);

Sf_Test::run(
	'only one request at a time holds the slice lock',
	static function () use ( $base ) {
		list( $job ) = Job::create( $base, 'export', array( 'test.count' ), array() );
		$other       = Job::load( $base, $job->id() );
		Sf_Test::ok( $job->lock(), 'first lock' );
		Sf_Test::ok( ! $other->lock(), 'second lock must fail' );
		try {
			Runner::run_slice( $other, new Slice_Budget( 5.0 ) );
			throw new RuntimeException( 'runner ran while locked' );
		} catch ( Job_Exception $e ) {
			Sf_Test::same( Job_Exception::BUSY, $e->getCode() );
		}
		$job->unlock();
		Sf_Test::ok( $other->lock(), 'lock after release' );
		$other->unlock();
	}
);

Sf_Test::run(
	'runner advances steps across slices and survives a reload between slices',
	static function () use ( $base ) {
		list( $job ) = Job::create( $base, 'export', array( 'test.count', 'test.fail' ), array() );
		$slices      = 0;
		while ( ! $job->finished() ) {
			Runner::run_slice( $job, new Slice_Budget( null, 0 ) );
			$job = Job::load( $base, $job->id() );
			++$slices;
		}
		Sf_Test::same( Job::STATUS_DONE, $job->status() );
		Sf_Test::same( 3, $job->state( 'count' )['calls'] );
		Sf_Test::same( 4, $slices, 'three counting slices, then one for the second step:' );
		Sf_Test::same( null, $job->current_step() );
	}
);

Sf_Test::run(
	'a failing step fails the job, records the error and runs every cleanup in reverse order',
	static function () use ( $base ) {
		Sf_Counting_Step::$cleanups = array();
		list( $job )                = Job::create( $base, 'export', array( 'test.count', 'test.fail' ), array( 'fail' => true ) );
		while ( ! $job->finished() ) {
			Runner::run_slice( $job, Slice_Budget::unlimited() );
		}
		Sf_Test::same( Job::STATUS_FAILED, $job->status() );
		Sf_Test::same( 'Simulated failure', $job->error() );
		Sf_Test::same( array( 'fail' => 1, 'count' => 1 ), Sf_Counting_Step::$cleanups, 'cleanups run last step first:' );
		Sf_Test::ok( ! file_exists( $job->path( 'count.tmp' ) ), 'temporary file removed' );
		Sf_Test::ok( false !== strpos( (string) file_get_contents( $job->path( 'job.log' ) ), '[error] Simulated failure' ), 'error logged' );
		Sf_Test::same( Job::STATUS_FAILED, Job::load( $base, $job->id() )->status(), 'saved:' );
		Runner::run_slice( $job, Slice_Budget::unlimited() );
		Sf_Test::same( Job::STATUS_FAILED, $job->status(), 'a failed job stays failed:' );
	}
);

Sf_Test::run(
	'warnings are counted, kept up to a limit and logged without control characters',
	static function () use ( $base ) {
		list( $job ) = Job::create( $base, 'export', array(), array() );
		for ( $i = 0; $i < Job::MAX_KEPT_WARNINGS + 5; $i++ ) {
			$job->warn( "warning $i\nsecond line" );
		}
		Sf_Test::same( Job::MAX_KEPT_WARNINGS + 5, $job->warning_count() );
		Sf_Test::same( Job::MAX_KEPT_WARNINGS, count( $job->warnings() ) );
		$log = (string) file_get_contents( $job->path( 'job.log' ) );
		Sf_Test::ok( false !== strpos( $log, '[warning] warning 0 second line' ), 'log line flattened' );
	}
);

Sf_Test::run(
	'storage folders get deny files',
	static function () use ( $base ) {
		$dir = Storage::ensure( $base . '/protected/inner' );
		foreach ( array( 'index.php', '.htaccess', 'web.config' ) as $name ) {
			Sf_Test::ok( is_file( $dir . '/' . $name ), $name . ' missing' );
		}
		Sf_Test::ok( false !== strpos( (string) file_get_contents( $dir . '/.htaccess' ), 'Require all denied' ), '.htaccess rule' );
		Sf_Test::same( 1, preg_match( Storage::NAME_PATTERN, 'siteferry-' . Storage::random_hex( 6 ) ) );
	}
);

exit( Sf_Test::finish( 'test-jobs' ) );
