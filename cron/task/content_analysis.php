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
use salvocortesiano\forumhealth\repository\topic_repository;
use salvocortesiano\forumhealth\service\content\content_analyser;
use salvocortesiano\forumhealth\service\content\duplicate_detector;
use salvocortesiano\forumhealth\service\logger;
use salvocortesiano\forumhealth\service\rules\rule_engine;
use salvocortesiano\forumhealth\service\settings;

/**
 * Walks the topic table, storing metrics and finding duplicate candidates.
 *
 * One batch per run. On a forum of a million topics with the default batch of
 * two hundred, a full sweep takes a while, and that is the intended trade: the
 * alternative is a cron run that exceeds the PHP time limit and never completes
 * at all.
 */
class content_analysis extends base
{
	/** @var content_analyser */
	protected $analyser;

	/** @var duplicate_detector */
	protected $duplicates;

	/** @var rule_engine */
	protected $rules;

	/** @var topic_repository */
	protected $topics;

	/**
	 * @param job_repository     $jobs       Job bookkeeping.
	 * @param settings           $settings   Extension settings.
	 * @param logger             $logger     Logger.
	 * @param content_analyser   $analyser   Content analysis.
	 * @param duplicate_detector $duplicates Duplicate detection.
	 * @param rule_engine        $rules      Rule engine.
	 * @param topic_repository   $topics     Topic repository.
	 */
	public function __construct(
		job_repository $jobs,
		settings $settings,
		logger $logger,
		content_analyser $analyser,
		duplicate_detector $duplicates,
		rule_engine $rules,
		topic_repository $topics
	)
	{
		parent::__construct($jobs, $settings, $logger);

		$this->analyser = $analyser;
		$this->duplicates = $duplicates;
		$this->rules = $rules;
		$this->topics = $topics;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function job_name()
	{
		return constants::JOB_CONTENT;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function interval()
	{
		return 900;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function feature_enabled()
	{
		return parent::feature_enabled() && $this->settings->feature_enabled('content');
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute()
	{
		$result = $this->analyser->run_batch();

		if ($result['processed'] === 0)
		{
			// The sweep reached the end of the table and starts again next run.
			return [
				'processed'	=> 0,
				'cursor'	=> 0,
				'state'		=> constants::JOB_OK,
				'message'	=> 'FH_JOB_MSG_SWEEP_COMPLETE',
			];
		}

		$topic_ids = [];
		$rows = $this->topics->fetch_batch(
			$result['cursor'] - $this->settings->get_int('fh_batch_size'),
			$this->settings->get_int('fh_batch_size'),
			$this->settings->excluded_forums()
		);

		foreach ($rows as $row)
		{
			$topic_ids[] = (int) $row['topic_id'];
		}

		$this->duplicates->analyse_batch($rows);
		$this->rules->evaluate_batch(array_values($this->topics->get_metrics($topic_ids)));

		return [
			'processed'	=> $result['processed'],
			'cursor'	=> $result['cursor'],
			'state'		=> constants::JOB_OK,
			'message'	=> '',
		];
	}
}
