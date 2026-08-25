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
use salvocortesiano\forumhealth\service\alerts\alert_manager;
use salvocortesiano\forumhealth\service\integrations\registry;
use salvocortesiano\forumhealth\service\logger;
use salvocortesiano\forumhealth\service\settings;

/**
 * Turns stored analysis into alerts, and refreshes integration state.
 *
 * Reads only what the analysis tasks have already written, so it is fast and
 * safe to run often.
 */
class alert_generation extends base
{
	/** @var alert_manager */
	protected $alerts;

	/** @var registry */
	protected $registry;

	/**
	 * @param job_repository $jobs     Job bookkeeping.
	 * @param settings       $settings Extension settings.
	 * @param logger         $logger   Logger.
	 * @param alert_manager  $alerts   Alert manager.
	 * @param registry       $registry Integration registry.
	 */
	public function __construct(
		job_repository $jobs,
		settings $settings,
		logger $logger,
		alert_manager $alerts,
		registry $registry
	)
	{
		parent::__construct($jobs, $settings, $logger);

		$this->alerts = $alerts;
		$this->registry = $registry;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function job_name()
	{
		return constants::JOB_ALERTS;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function interval()
	{
		return 1800;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function feature_enabled()
	{
		return parent::feature_enabled() && $this->settings->feature_enabled('alerts');
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute()
	{
		$this->registry->refresh();
		$raised = $this->alerts->generate();

		return [
			'processed'	=> $raised,
			'cursor'	=> 0,
			'state'		=> constants::JOB_OK,
			'message'	=> '',
		];
	}
}
