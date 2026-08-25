<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\rules;

use salvocortesiano\forumhealth\constants;
use salvocortesiano\forumhealth\repository\alert_repository;
use salvocortesiano\forumhealth\repository\rule_repository;
use salvocortesiano\forumhealth\service\settings;

/**
 * Evaluates administrator-defined rules against analysed topics.
 *
 * The engine is deliberately small and deliberately dull. A rule is a list of
 * field/operator/value triples, all of which must hold; the only action it can
 * take is to raise an alert. There is no expression parser, no callable, no
 * eval, and no way for a rule to reach anything the whitelist does not name.
 *
 * That constraint is the point. Rules are entered through a web form by whoever
 * holds an administrative account, and a rule language rich enough to be
 * interesting would also be rich enough to be a vulnerability.
 */
class rule_engine
{
	/** @var rule_repository */
	protected $rules;

	/** @var alert_repository */
	protected $alerts;

	/** @var settings */
	protected $settings;

	/**
	 * @param rule_repository  $rules    Rule repository.
	 * @param alert_repository $alerts   Alert repository.
	 * @param settings         $settings Extension settings.
	 */
	public function __construct(rule_repository $rules, alert_repository $alerts, settings $settings)
	{
		$this->rules = $rules;
		$this->alerts = $alerts;
		$this->settings = $settings;
	}

	/**
	 * Evaluate every enabled rule against a batch of analysed topics.
	 *
	 * @param array[] $metric_rows Stored metric rows.
	 * @return int Alerts raised.
	 */
	public function evaluate_batch(array $metric_rows)
	{
		if (!$this->settings->feature_enabled('rules') || empty($metric_rows))
		{
			return 0;
		}

		$rules = $this->rules->all(true);

		if (empty($rules))
		{
			return 0;
		}

		$raised = 0;
		$budget = $this->settings->get_int('fh_alerts_max_per_run');

		foreach ($metric_rows as $row)
		{
			$facts = $this->facts($row);

			foreach ($rules as $rule)
			{
				if ($raised >= $budget)
				{
					// A rule matching half the forum should not be able to fill
					// the alert table in a single cron run.
					return $raised;
				}

				if (!$this->matches($rule['rule_conditions'], $facts))
				{
					continue;
				}

				$created = $this->alerts->raise([
					'alert_type'	=> constants::ALERT_RULE,
					'severity'		=> (int) $rule['action_severity'],
					'entity_type'	=> 'topic',
					'entity_id'		=> (int) $row['topic_id'],
					'signature'		=> 'rule|' . (int) $rule['rule_id'] . '|' . (int) $row['topic_id'],
					'explain_key'	=> 'FH_ALERT_RULE_EXPLAIN',
					'explain_data'	=> [
						'rule'		=> (string) $rule['rule_name'],
						'topic_id'	=> (int) $row['topic_id'],
					],
					'action_key'	=> 'FH_ACTION_REVIEW_TOPIC',
					'source'		=> constants::SOURCE_RULE,
				]);

				if ($created)
				{
					$raised++;
				}
			}
		}

		return $raised;
	}

	/**
	 * Test one topic against one rule, for the rule preview in the ACP.
	 *
	 * @param array $rule       Rule with decoded conditions.
	 * @param array $metric_row Stored metric row.
	 * @return bool
	 */
	public function test($rule, array $metric_row)
	{
		$conditions = isset($rule['rule_conditions']) ? (array) $rule['rule_conditions'] : [];

		return $this->matches($conditions, $this->facts($metric_row));
	}

	/**
	 * Derive the testable facts of a topic from its stored metrics.
	 *
	 * Keeping this in one place means a rule field can never read a column
	 * directly, and the set of things a rule can see stays reviewable.
	 *
	 * @param array $row Stored metric row.
	 * @return array<string, int>
	 */
	protected function facts(array $row)
	{
		$now = time();

		return [
			'views'				=> (int) ($row['topic_views'] ?? 0),
			'replies'			=> (int) ($row['topic_replies'] ?? 0),
			'age_hours'			=> (int) floor(($now - (int) ($row['topic_time'] ?? $now)) / 3600),
			'idle_hours'		=> (int) floor(($now - (int) ($row['last_post_time'] ?? $now)) / 3600),
			'is_unanswered'		=> (int) ($row['is_unanswered'] ?? 0),
			'is_first_topic'	=> (int) ($row['is_first_topic'] ?? 0),
			'has_solution'		=> (int) ($row['solution_post_id'] ?? 0) > 0 ? 1 : 0,
			'freshness_conf'	=> (int) ($row['freshness_conf'] ?? 0),
			'forum_id'			=> (int) ($row['forum_id'] ?? 0),
		];
	}

	/**
	 * Whether every condition holds.
	 *
	 * @param array $conditions Decoded conditions.
	 * @param array $facts      Derived facts.
	 * @return bool
	 */
	protected function matches(array $conditions, array $facts)
	{
		if (empty($conditions))
		{
			// An empty rule matching everything would be a trap, not a feature.
			return false;
		}

		foreach ($conditions as $condition)
		{
			$field = (string) ($condition['field'] ?? '');

			if (!array_key_exists($field, $facts))
			{
				return false;
			}

			if (!$this->compare($facts[$field], (string) ($condition['operator'] ?? ''), (int) ($condition['value'] ?? 0)))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Apply one whitelisted operator.
	 *
	 * @param int    $actual   Fact value.
	 * @param string $operator Operator name.
	 * @param int    $expected Rule value.
	 * @return bool
	 */
	protected function compare($actual, $operator, $expected)
	{
		switch ($operator)
		{
			case 'eq':
				return $actual === $expected;

			case 'neq':
				return $actual !== $expected;

			case 'gt':
				return $actual > $expected;

			case 'gte':
				return $actual >= $expected;

			case 'lt':
				return $actual < $expected;

			case 'lte':
				return $actual <= $expected;

			default:
				// An unrecognised operator fails closed. It should be
				// unreachable: the repository validates on save.
				return false;
		}
	}
}
