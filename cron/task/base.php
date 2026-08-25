<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\cron\task;

use salvocortesiano\forumhealth\constants;
use salvocortesiano\forumhealth\repository\job_repository;
use salvocortesiano\forumhealth\service\logger;
use salvocortesiano\forumhealth\service\settings;

/**
 * Shared behaviour for every background task.
 *
 * The contract each task inherits: take the lock or stand down, run a bounded
 * amount of work, always release the lock, and never let an exception escape
 * into phpBB's cron runner. A failing analysis task must not be able to break
 * page rendering for visitors, which is what an uncaught exception in cron would
 * do on a forum using the default cron trigger.
 */
abstract class base extends \phpbb\cron\task\base
{
	/** @var job_repository */
	protected $jobs;

	/** @var settings */
	protected $settings;

	/** @var logger */
	protected $logger;

	/**
	 * @param job_repository $jobs     Job bookkeeping.
	 * @param settings       $settings Extension settings.
	 * @param logger         $logger   Logger.
	 */
	public function __construct(job_repository $jobs, settings $settings, logger $logger)
	{
		$this->jobs = $jobs;
		$this->settings = $settings;
		$this->logger = $logger;
	}

	/**
	 * The job identifier this task owns.
	 *
	 * @return string
	 */
	abstract protected function job_name();

	/**
	 * How often the task should run, in seconds.
	 *
	 * @return int
	 */
	abstract protected function interval();

	/**
	 * The work itself.
	 *
	 * @return array{processed:int,cursor:int,state:string,message:string}
	 */
	abstract protected function execute();

	/**
	 * Whether the feature behind this task is switched on.
	 *
	 * @return bool
	 */
	protected function feature_enabled()
	{
		return $this->settings->is_enabled() && $this->settings->feature_enabled('background');
	}

	/**
	 * {@inheritdoc}
	 */
	public function run()
	{
		if (!$this->feature_enabled())
		{
			$this->jobs->mark_disabled($this->job_name());

			return;
		}

		if (!$this->jobs->acquire($this->job_name()))
		{
			// Another run is already in progress. Overlapping runs would double
			// the work and could interleave cursor writes.
			return;
		}

		$started = time();

		try
		{
			$result = $this->execute();

			$this->jobs->release(
				$this->job_name(),
				isset($result['state']) ? $result['state'] : constants::JOB_OK,
				isset($result['processed']) ? (int) $result['processed'] : 0,
				isset($result['cursor']) ? (int) $result['cursor'] : 0,
				time() - $started,
				isset($result['message']) ? $result['message'] : ''
			);

			$this->logger->debug('FH_LOG_JOB_COMPLETED', [
				$this->job_name(),
				isset($result['processed']) ? (int) $result['processed'] : 0,
			]);
		}
		catch (\Throwable $e)
		{
			// The lock is released whatever happened, otherwise one failure
			// would stop the task for the whole lock window.
			$this->jobs->release(
				$this->job_name(),
				constants::JOB_ERROR,
				0,
				0,
				time() - $started,
				get_class($e)
			);

			$this->logger->error('FH_LOG_JOB_FAILED', [$this->job_name(), get_class($e)]);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function should_run()
	{
		if (!$this->feature_enabled())
		{
			return false;
		}

		$job = $this->jobs->get($this->job_name());

		return (int) $job['last_run'] < (time() - $this->interval());
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_runnable()
	{
		return $this->settings->is_enabled();
	}
}
