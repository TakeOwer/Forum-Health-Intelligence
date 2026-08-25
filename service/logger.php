<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service;

use phpbb\log\log_interface;
use phpbb\user;

/**
 * Logging facade.
 *
 * Two jobs: respect the configured verbosity, and make it structurally hard to
 * log something that should not be logged. Messages are language keys with
 * scalar parameters, so no caller can pass a post body, a private message or a
 * credential into the admin log by accident.
 */
class logger
{
	/** Only failures are recorded. */
	const LEVEL_ERRORS = 0;

	/** Failures and notable events. */
	const LEVEL_NORMAL = 1;

	/** Everything, including routine job completion. */
	const LEVEL_VERBOSE = 2;

	/** @var log_interface */
	protected $log;

	/** @var user */
	protected $user;

	/** @var settings */
	protected $settings;

	/**
	 * @param log_interface $log      phpBB log service.
	 * @param user          $user     Current user.
	 * @param settings      $settings Extension settings.
	 */
	public function __construct(log_interface $log, user $user, settings $settings)
	{
		$this->log = $log;
		$this->user = $user;
		$this->settings = $settings;
	}

	/**
	 * Record a failure. Always written, whatever the verbosity.
	 *
	 * @param string $key    Language key of the log message.
	 * @param array  $params Scalar parameters only.
	 * @return void
	 */
	public function error($key, array $params = [])
	{
		$this->write($key, $params, self::LEVEL_ERRORS);
	}

	/**
	 * Record a notable event.
	 *
	 * @param string $key    Language key of the log message.
	 * @param array  $params Scalar parameters only.
	 * @return void
	 */
	public function notice($key, array $params = [])
	{
		$this->write($key, $params, self::LEVEL_NORMAL);
	}

	/**
	 * Record routine detail.
	 *
	 * @param string $key    Language key of the log message.
	 * @param array  $params Scalar parameters only.
	 * @return void
	 */
	public function debug($key, array $params = [])
	{
		$this->write($key, $params, self::LEVEL_VERBOSE);
	}

	/**
	 * Write to the phpBB admin log when the verbosity allows it.
	 *
	 * @param string $key      Language key.
	 * @param array  $params   Parameters.
	 * @param int    $required Minimum configured level for this message.
	 * @return void
	 */
	protected function write($key, array $params, $required)
	{
		if ($this->settings->get_int('fh_log_level') < $required)
		{
			return;
		}

		$this->log->add(
			'admin',
			(int) $this->user->data['user_id'],
			$this->user->ip ?: '',
			(string) $key,
			time(),
			$this->scalars($params)
		);
	}

	/**
	 * Reduce parameters to short scalars.
	 *
	 * Arrays and objects are discarded rather than serialised, and strings are
	 * truncated, so a log entry can never become a data leak.
	 *
	 * @param array $params Raw parameters.
	 * @return array Safe parameters.
	 */
	protected function scalars(array $params)
	{
		$safe = [];

		foreach ($params as $param)
		{
			if (is_int($param) || is_float($param) || is_bool($param))
			{
				$safe[] = $param;
				continue;
			}

			if (is_string($param))
			{
				$safe[] = utf8_substr($param, 0, 120);
			}
		}

		return $safe;
	}
}
