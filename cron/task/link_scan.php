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
use salvocortesiano\forumhealth\service\content\link_scanner;
use salvocortesiano\forumhealth\service\logger;
use salvocortesiano\forumhealth\service\settings;

/**
 * Discovers URLs and checks the ones that are due.
 *
 * Both passes run in the same task but discovery is cheap and checking is not,
 * so the batch sizes are independent. The task is the only thing in the
 * extension that touches the network, and it does nothing at all unless link
 * scanning has been explicitly enabled.
 */
class link_scan extends base
{
	/** @var link_scanner */
	protected $scanner;

	/**
	 * @param job_repository $jobs     Job bookkeeping.
	 * @param settings       $settings Extension settings.
	 * @param logger         $logger   Logger.
	 * @param link_scanner   $scanner  Link scanner.
	 */
	public function __construct(job_repository $jobs, settings $settings, logger $logger, link_scanner $scanner)
	{
		parent::__construct($jobs, $settings, $logger);

		$this->scanner = $scanner;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function job_name()
	{
		return constants::JOB_LINKS;
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
		return parent::feature_enabled() && $this->settings->feature_enabled('links');
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute()
	{
		$discovery = $this->scanner->discover_batch();
		$check = $this->scanner->check_batch();

		return [
			'processed'	=> $discovery['processed'] + $check['checked'],
			'cursor'	=> $discovery['cursor'],
			'state'		=> constants::JOB_OK,
			'message'	=> '',
		];
	}
}
