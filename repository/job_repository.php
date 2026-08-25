<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\repository;

use phpbb\db\driver\driver_interface;
use salvocortesiano\forumhealth\constants;

/**
 * Job bookkeeping: cursor, lock and last outcome.
 *
 * The lock is advisory and time bounded. phpBB's cron can be triggered by any
 * page view, so two runs of the same job can overlap; the lock makes that
 * harmless without requiring a database-specific locking primitive.
 */
class job_repository
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $table;

	/**
	 * @param driver_interface $db    Database driver.
	 * @param string           $table Jobs table.
	 */
	public function __construct(driver_interface $db, $table)
	{
		$this->db = $db;
		$this->table = $table;
	}

	/**
	 * Read one job row, creating it if the seed migration predates the job.
	 *
	 * @param string $name Job name.
	 * @return array
	 */
	public function get($name)
	{
		$sql = 'SELECT * FROM ' . $this->table . "
			WHERE job_name = '" . $this->db->sql_escape((string) $name) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($row)
		{
			return $row;
		}

		$row = [
			'job_name'		=> (string) $name,
			'job_state'		=> constants::JOB_IDLE,
			'last_run'		=> 0,
			'last_duration'	=> 0,
			'last_message'	=> '',
			'processed'		=> 0,
			'cursor_value'	=> 0,
			'lock_expires'	=> 0,
		];

		$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $row));

		return $row;
	}

	/**
	 * All job rows, for the job status page.
	 *
	 * @return array[]
	 */
	public function all()
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->table . ' ORDER BY job_name ASC');
		$rows = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}

		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Attempt to take the lock for a job.
	 *
	 * @param string $name Job name.
	 * @return bool False when another run holds a live lock.
	 */
	public function acquire($name)
	{
		$job = $this->get($name);
		$now = time();

		if ((int) $job['lock_expires'] > $now)
		{
			return false;
		}

		$this->db->sql_query('UPDATE ' . $this->table . " SET
			job_state = '" . $this->db->sql_escape(constants::JOB_RUNNING) . "',
			lock_expires = " . ($now + (constants::JOB_LOCK_MINUTES * 60)) . "
			WHERE job_name = '" . $this->db->sql_escape((string) $name) . "'");

		return true;
	}

	/**
	 * Release the lock and record the outcome.
	 *
	 * @param string $name      Job name.
	 * @param string $state     Terminal state.
	 * @param int    $processed Items handled this run.
	 * @param int    $cursor    New cursor value.
	 * @param int    $duration  Seconds taken.
	 * @param string $message   Short outcome message or language key.
	 * @return void
	 */
	public function release($name, $state, $processed, $cursor, $duration, $message = '')
	{
		$this->db->sql_query('UPDATE ' . $this->table . '
			SET ' . $this->db->sql_build_array('UPDATE', [
				'job_state'		=> (string) $state,
				'last_run'		=> time(),
				'last_duration'	=> (int) $duration,
				'last_message'	=> utf8_substr((string) $message, 0, 255),
				'processed'		=> (int) $processed,
				'cursor_value'	=> (int) $cursor,
				'lock_expires'	=> 0,
			]) . "
			WHERE job_name = '" . $this->db->sql_escape((string) $name) . "'");
	}

	/**
	 * Mark a job as disabled by configuration.
	 *
	 * @param string $name Job name.
	 * @return void
	 */
	public function mark_disabled($name)
	{
		$this->db->sql_query('UPDATE ' . $this->table . " SET
			job_state = '" . $this->db->sql_escape(constants::JOB_DISABLED) . "',
			lock_expires = 0
			WHERE job_name = '" . $this->db->sql_escape((string) $name) . "'");
	}

	/**
	 * Store a cursor mid-run so an interrupted job resumes where it stopped.
	 *
	 * @param string $name   Job name.
	 * @param int    $cursor Cursor value.
	 * @return void
	 */
	public function save_cursor($name, $cursor)
	{
		$this->db->sql_query('UPDATE ' . $this->table . '
			SET cursor_value = ' . (int) $cursor . "
			WHERE job_name = '" . $this->db->sql_escape((string) $name) . "'");
	}
}
