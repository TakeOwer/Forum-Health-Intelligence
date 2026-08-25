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
use salvocortesiano\forumhealth\repository\alert_repository;
use salvocortesiano\forumhealth\repository\job_repository;
use salvocortesiano\forumhealth\repository\link_repository;
use salvocortesiano\forumhealth\repository\metric_repository;
use salvocortesiano\forumhealth\repository\relation_repository;
use salvocortesiano\forumhealth\repository\topic_repository;
use salvocortesiano\forumhealth\service\integrations\ai\cache as ai_cache;
use salvocortesiano\forumhealth\service\logger;
use salvocortesiano\forumhealth\service\settings;

/**
 * Enforces retention and removes rows whose subject no longer exists.
 *
 * Two distinct duties. Retention deletes derived data older than the configured
 * window, so the extension does not accumulate history indefinitely on a
 * database the administrator has to back up. Orphan removal deletes analysis
 * whose topic or post has been deleted, so the reports never point at content
 * that is not there.
 *
 * Human decisions survive both. A dismissed alert and a confirmed duplicate are
 * judgements, not derived data, and re-detecting something a person has already
 * ruled on would waste their time twice.
 */
class cleanup extends base
{
	/** @var alert_repository */
	protected $alerts;

	/** @var relation_repository */
	protected $relations;

	/** @var link_repository */
	protected $links;

	/** @var metric_repository */
	protected $metrics;

	/** @var topic_repository */
	protected $topics;

	/** @var ai_cache */
	protected $ai_cache;

	/** @var string */
	protected $posts_table;

	/**
	 * @param job_repository      $jobs        Job bookkeeping.
	 * @param settings            $settings    Extension settings.
	 * @param logger              $logger      Logger.
	 * @param alert_repository    $alerts      Alert repository.
	 * @param relation_repository $relations   Relation repository.
	 * @param link_repository     $links       Link repository.
	 * @param metric_repository   $metrics     Metric history.
	 * @param topic_repository    $topics      Topic repository.
	 * @param ai_cache            $ai_cache    AI result cache.
	 * @param string              $posts_table phpBB posts table.
	 */
	public function __construct(
		job_repository $jobs,
		settings $settings,
		logger $logger,
		alert_repository $alerts,
		relation_repository $relations,
		link_repository $links,
		metric_repository $metrics,
		topic_repository $topics,
		ai_cache $ai_cache,
		$posts_table
	)
	{
		parent::__construct($jobs, $settings, $logger);

		$this->alerts = $alerts;
		$this->relations = $relations;
		$this->links = $links;
		$this->metrics = $metrics;
		$this->topics = $topics;
		$this->ai_cache = $ai_cache;
		$this->posts_table = $posts_table;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function job_name()
	{
		return constants::JOB_CLEANUP;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function interval()
	{
		return 86400;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Cleanup runs even when background analysis is switched off: data that is
	 * already stored still ages, and retention is a promise to the administrator
	 * rather than a feature they toggle.
	 */
	protected function feature_enabled()
	{
		return $this->settings->is_enabled();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute()
	{
		$now = time();
		$removed = 0;

		$removed += $this->alerts->prune($now - ($this->settings->get_int('fh_retain_alerts_days') * 86400));
		$removed += $this->relations->prune($now - ($this->settings->get_int('fh_retain_relations_days') * 86400));

		$this->metrics->prune((int) gmdate('Ymd', $now - ($this->settings->get_int('fh_retain_metrics_days') * 86400)));
		$this->ai_cache->prune();

		// Orphan removal is bounded per run so a mass topic deletion cannot turn
		// one cron pass into a very long transaction.
		$removed += $this->topics->prune_orphans(500);
		$removed += $this->relations->prune_orphans(500);
		$removed += $this->links->prune_orphans($this->posts_table, 500);

		return [
			'processed'	=> $removed,
			'cursor'	=> 0,
			'state'		=> constants::JOB_OK,
			'message'	=> '',
		];
	}
}
