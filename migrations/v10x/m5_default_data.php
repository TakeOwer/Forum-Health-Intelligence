<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\migrations\v10x;

use salvocortesiano\forumhealth\constants;

/**
 * Seeds job bookkeeping rows and two starter rules.
 *
 * The starter rules are disabled on install: they exist as working examples of
 * the rule format, not as behaviour imposed on the administrator.
 */
class m5_default_data extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return ['\salvocortesiano\forumhealth\migrations\v10x\m4_acp_modules'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		$jobs = [];

		foreach (constants::job_names() as $name)
		{
			$jobs[] = ['custom', [[$this, 'insert_job'], [$name]]];
		}

		$jobs[] = ['custom', [[$this, 'insert_starter_rules']]];

		return $jobs;
	}

	/**
	 * Insert one job bookkeeping row if it does not already exist.
	 *
	 * @param string $name Job identifier.
	 * @return void
	 */
	public function insert_job($name)
	{
		$table = $this->table_prefix . 'fh_jobs';

		$sql = 'SELECT job_name FROM ' . $table . "
			WHERE job_name = '" . $this->db->sql_escape($name) . "'";
		$result = $this->db->sql_query($sql);
		$exists = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($exists)
		{
			return;
		}

		$this->db->sql_query('INSERT INTO ' . $table . ' ' . $this->db->sql_build_array('INSERT', [
			'job_name'		=> $name,
			'job_state'		=> constants::JOB_IDLE,
			'last_run'		=> 0,
			'last_duration'	=> 0,
			'last_message'	=> '',
			'processed'		=> 0,
			'cursor_value'	=> 0,
			'lock_expires'	=> 0,
		]));
	}

	/**
	 * Insert the disabled example rules.
	 *
	 * @return void
	 */
	public function insert_starter_rules()
	{
		$table = $this->table_prefix . 'fh_rules';

		$sql = 'SELECT rule_id FROM ' . $table;
		$result = $this->db->sql_query_limit($sql, 1);
		$exists = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($exists)
		{
			return;
		}

		$rules = [
			[
				'rule_name'			=> 'Popular topic with almost no replies',
				'rule_enabled'		=> 0,
				'rule_subject'		=> 'topic',
				'rule_conditions'	=> json_encode([
					['field' => 'views', 'operator' => 'gte', 'value' => 500],
					['field' => 'replies', 'operator' => 'lte', 'value' => 2],
				]),
				'action_type'		=> 'create_alert',
				'action_severity'	=> constants::SEVERITY_HIGH,
				'created_at'		=> time(),
			],
			[
				'rule_name'			=> 'First topic from a new member still unanswered',
				'rule_enabled'		=> 0,
				'rule_subject'		=> 'topic',
				'rule_conditions'	=> json_encode([
					['field' => 'is_first_topic', 'operator' => 'eq', 'value' => 1],
					['field' => 'replies', 'operator' => 'eq', 'value' => 0],
					['field' => 'age_hours', 'operator' => 'gte', 'value' => 24],
				]),
				'action_type'		=> 'create_alert',
				'action_severity'	=> constants::SEVERITY_MEDIUM,
				'created_at'		=> time(),
			],
		];

		foreach ($rules as $row)
		{
			$this->db->sql_query('INSERT INTO ' . $table . ' ' . $this->db->sql_build_array('INSERT', $row));
		}
	}
}
