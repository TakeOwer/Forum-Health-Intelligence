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
use salvocortesiano\forumhealth\service\community\community_analyser;
use salvocortesiano\forumhealth\service\logger;
use salvocortesiano\forumhealth\service\scoring\health_score;
use salvocortesiano\forumhealth\service\settings;

/**
 * Writes the daily community figures and the daily health indicators.
 *
 * Runs hourly but does almost nothing most of the time: a day that has already
 * been recorded is skipped, so the real work happens once per day whenever cron
 * first fires after midnight.
 */
class community_analysis extends base
{
	/** @var community_analyser */
	protected $community;

	/** @var health_score */
	protected $score;

	/**
	 * @param job_repository     $jobs      Job bookkeeping.
	 * @param settings           $settings  Extension settings.
	 * @param logger             $logger    Logger.
	 * @param community_analyser $community Community analysis.
	 * @param health_score       $score     Health scoring.
	 */
	public function __construct(
		job_repository $jobs,
		settings $settings,
		logger $logger,
		community_analyser $community,
		health_score $score
	)
	{
		parent::__construct($jobs, $settings, $logger);

		$this->community = $community;
		$this->score = $score;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function job_name()
	{
		return constants::JOB_COMMUNITY;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function interval()
	{
		return 3600;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function feature_enabled()
	{
		return parent::feature_enabled() && $this->settings->feature_enabled('community');
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute()
	{
		// Backfilling first keeps the history continuous after downtime, and
		// costs nothing when there is nothing missing.
		$filled = $this->community->backfill(7);
		$this->score->record_history();

		return [
			'processed'	=> $filled,
			'cursor'	=> 0,
			'state'		=> constants::JOB_OK,
			'message'	=> '',
		];
	}
}
