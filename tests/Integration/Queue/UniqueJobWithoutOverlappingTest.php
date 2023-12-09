<?php

namespace Illuminate\Tests\Integration\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase;

#[WithMigration('queue')]
class UniqueJobWithoutOverlappingTest extends TestCase
{
	use DatabaseMigrations;

	protected function setUp(): void
	{
		parent::setUp();

		UniqueJobWithoutOverlappingJobRunRecorder::reset();
	}

	public function testShouldBeUniqueUntilProcessingCombinedWithWithoutOverlap()
	{
		dispatch($firstJob = new OverlappingUniqueUntilProcessingJob('01'));
		dispatch($secondJob = new OverlappingUniqueUntilProcessingJob('02'));
		dispatch($thirdJob = new OverlappingUniqueUntilProcessingJob('03'));

		$this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($firstJob), 10)->get());
		$this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($secondJob), 10)->get());
		$this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($thirdJob), 10)->get());

		$this->artisan('queue:work', [
			'connection' => 'database',
			'--stop-when-empty' => true,
		]);

		$this->assertEquals(['01'], UniqueJobWithoutOverlappingJobRunRecorder::$results);
		$this->assertTrue($this->app->get(Cache::class)->lock($this->getLockKey($firstJob), 10)->get());
		$this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($secondJob), 10)->get());
		$this->assertFalse($this->app->get(Cache::class)->lock($this->getLockKey($thirdJob), 10)->get());
	}

	protected function getLockKey($job)
	{
		return 'laravel_unique_job:'.(is_string($job) ? $job : get_class($job));
	}
}

class OverlappingUniqueUntilProcessingJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
	use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

	public function __construct(private string $id)
	{
		$this->connection = 'database';
	}

	public function middleware()
	{
		return [(new WithoutOverlapping())->releaseAfter(8)];
	}

    public function handle()
    {
	    UniqueJobWithoutOverlappingJobRunRecorder::record($this->id);
    }
}

class UniqueJobWithoutOverlappingJobRunRecorder
{
	public static $results = [];

	public static $failures = [];

	public static function record(string $id)
	{
		self::$results[] = $id;
	}

	public static function recordFailure(string $message)
	{
		self::$failures[] = $message;

		return $message;
	}

	public static function reset()
	{
		self::$results = [];
		self::$failures = [];
	}
}

