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
 * Persistence and validation for administrator-defined rules.
 *
 * A rule is data, never code. Fields, operators and actions are drawn from fixed
 * whitelists defined here, and anything outside them is rejected on save rather
 * than being stored and discovered at evaluation time.
 */
class rule_repository
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $table;

	/**
	 * Fields a topic rule may test, mapped to their value type.
	 *
	 * @var array<string, string>
	 */
	protected static $fields = [
		'views'				=> 'int',
		'replies'			=> 'int',
		'age_hours'			=> 'int',
		'idle_hours'		=> 'int',
		'is_unanswered'		=> 'bool',
		'is_first_topic'	=> 'bool',
		'has_solution'		=> 'bool',
		'freshness_conf'	=> 'int',
		'forum_id'			=> 'int',
	];

	/**
	 * Permitted comparison operators.
	 *
	 * @var string[]
	 */
	protected static $operators = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'];

	/**
	 * Permitted actions. Every one of them is non-destructive by design.
	 *
	 * @var string[]
	 */
	protected static $actions = ['create_alert'];

	/**
	 * @param driver_interface $db    Database driver.
	 * @param string           $table Rules table.
	 */
	public function __construct(driver_interface $db, $table)
	{
		$this->db = $db;
		$this->table = $table;
	}

	/**
	 * Testable fields and their types.
	 *
	 * @return array<string, string>
	 */
	public static function fields()
	{
		return self::$fields;
	}

	/**
	 * Permitted operators.
	 *
	 * @return string[]
	 */
	public static function operators()
	{
		return self::$operators;
	}

	/**
	 * Permitted action types.
	 *
	 * @return string[]
	 */
	public static function actions()
	{
		return self::$actions;
	}

	/**
	 * All rules, optionally only the enabled ones.
	 *
	 * @param bool $enabled_only Whether to filter on the enabled flag.
	 * @return array[] Rules with decoded conditions.
	 */
	public function all($enabled_only = false)
	{
		$sql = 'SELECT * FROM ' . $this->table
			. ($enabled_only ? ' WHERE rule_enabled = 1' : '')
			. ' ORDER BY rule_id ASC';
		$result = $this->db->sql_query($sql);
		$rows = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$conditions = json_decode((string) $row['rule_conditions'], true);
			$row['rule_conditions'] = is_array($conditions) ? $conditions : [];
			$rows[] = $row;
		}

		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Fetch one rule.
	 *
	 * @param int $rule_id Rule id.
	 * @return array|null
	 */
	public function get($rule_id)
	{
		$sql = 'SELECT * FROM ' . $this->table . ' WHERE rule_id = ' . (int) $rule_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			return null;
		}

		$conditions = json_decode((string) $row['rule_conditions'], true);
		$row['rule_conditions'] = is_array($conditions) ? $conditions : [];

		return $row;
	}

	/**
	 * Create or update a rule after validating it.
	 *
	 * @param int   $rule_id Rule id, 0 to create.
	 * @param array $data    Fields: rule_name, rule_enabled, rule_conditions,
	 *                       action_type, action_severity.
	 * @return array{0:bool,1:string} Success flag and error language key.
	 */
	public function save($rule_id, array $data)
	{
		$name = trim((string) ($data['rule_name'] ?? ''));

		if ($name === '')
		{
			return [false, 'FH_RULE_ERR_NAME'];
		}

		$conditions = $this->sanitise_conditions(isset($data['rule_conditions']) ? (array) $data['rule_conditions'] : []);

		if (empty($conditions))
		{
			return [false, 'FH_RULE_ERR_CONDITIONS'];
		}

		$action = (string) ($data['action_type'] ?? 'create_alert');

		if (!in_array($action, self::$actions, true))
		{
			return [false, 'FH_RULE_ERR_ACTION'];
		}

		$severity = (int) ($data['action_severity'] ?? constants::SEVERITY_MEDIUM);

		if (!array_key_exists($severity, constants::severity_map()))
		{
			return [false, 'FH_RULE_ERR_SEVERITY'];
		}

		$row = [
			'rule_name'			=> utf8_substr($name, 0, 255),
			'rule_enabled'		=> !empty($data['rule_enabled']) ? 1 : 0,
			'rule_subject'		=> 'topic',
			'rule_conditions'	=> json_encode($conditions),
			'action_type'		=> $action,
			'action_severity'	=> $severity,
		];

		if ((int) $rule_id > 0)
		{
			$this->db->sql_query('UPDATE ' . $this->table . '
				SET ' . $this->db->sql_build_array('UPDATE', $row) . '
				WHERE rule_id = ' . (int) $rule_id);
		}
		else
		{
			$row['created_at'] = time();
			$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $row));
		}

		return [true, ''];
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $rule_id Rule id.
	 * @return void
	 */
	public function delete($rule_id)
	{
		$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE rule_id = ' . (int) $rule_id);
	}

	/**
	 * Toggle a rule on or off.
	 *
	 * @param int  $rule_id Rule id.
	 * @param bool $enabled Desired state.
	 * @return void
	 */
	public function set_enabled($rule_id, $enabled)
	{
		$this->db->sql_query('UPDATE ' . $this->table . '
			SET rule_enabled = ' . ($enabled ? 1 : 0) . '
			WHERE rule_id = ' . (int) $rule_id);
	}

	/**
	 * Reduce submitted conditions to the whitelisted subset.
	 *
	 * Anything unrecognised is dropped silently rather than stored, so no
	 * unexpected structure can ever reach the evaluator.
	 *
	 * @param array $raw Submitted conditions.
	 * @return array[] Clean conditions.
	 */
	protected function sanitise_conditions(array $raw)
	{
		$clean = [];

		foreach ($raw as $condition)
		{
			if (!is_array($condition))
			{
				continue;
			}

			$field = (string) ($condition['field'] ?? '');
			$operator = (string) ($condition['operator'] ?? '');

			if (!isset(self::$fields[$field]) || !in_array($operator, self::$operators, true))
			{
				continue;
			}

			$value = $condition['value'] ?? 0;
			$value = self::$fields[$field] === 'bool' ? (int) ((bool) $value) : (int) $value;

			$clean[] = [
				'field'		=> $field,
				'operator'	=> $operator,
				'value'		=> $value,
			];

			// A rule with dozens of clauses is unreadable and almost certainly a
			// mistake; ten is generous for the fields available.
			if (count($clean) >= 10)
			{
				break;
			}
		}

		return $clean;
	}
}
