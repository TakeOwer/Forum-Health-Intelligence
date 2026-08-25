<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\tests\unit;

use salvocortesiano\forumhealth\service\rules\rule_engine;

/**
 * Tests rule evaluation.
 *
 * The behaviours that matter are the negative ones. A rule engine that raises
 * an alert for a discussion it should not have is noise; a rule engine that
 * silently accepts an operator it does not understand is a way for a
 * misconfigured or crafted rule to match everything.
 */
class rule_engine_test extends \phpbb_test_case
{
	/** @var rule_engine */
	protected $engine;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$rules = $this->getMockBuilder('\salvocortesiano\forumhealth\repository\rule_repository')
			->disableOriginalConstructor()->getMock();
		$alerts = $this->getMockBuilder('\salvocortesiano\forumhealth\repository\alert_repository')
			->disableOriginalConstructor()->getMock();
		$settings = $this->getMockBuilder('\salvocortesiano\forumhealth\service\settings')
			->disableOriginalConstructor()->getMock();

		$settings->method('feature_enabled')->willReturn(true);
		$settings->method('get_int')->willReturn(500);

		$this->engine = new rule_engine($rules, $alerts, $settings);
	}

	/**
	 * A representative analysed topic.
	 *
	 * @param array $overrides Fields to change.
	 * @return array
	 */
	protected function topic(array $overrides = [])
	{
		return array_merge([
			'topic_id'			=> 42,
			'forum_id'			=> 3,
			'topic_views'		=> 1000,
			'topic_replies'		=> 0,
			'topic_time'		=> time() - (48 * 3600),
			'last_post_time'	=> time() - (48 * 3600),
			'is_unanswered'		=> 1,
			'is_first_topic'	=> 0,
			'solution_post_id'	=> 0,
			'freshness_conf'	=> 0,
		], $overrides);
	}

	/**
	 * A rule whose every condition holds should match.
	 *
	 * @return void
	 */
	public function test_all_conditions_must_hold()
	{
		$rule = ['rule_conditions' => [
			['field' => 'views', 'operator' => 'gte', 'value' => 500],
			['field' => 'is_unanswered', 'operator' => 'eq', 'value' => 1],
		]];

		$this->assertTrue($this->engine->test($rule, $this->topic()));
	}

	/**
	 * One failing condition is enough to reject the whole rule.
	 *
	 * @return void
	 */
	public function test_one_failing_condition_rejects()
	{
		$rule = ['rule_conditions' => [
			['field' => 'views', 'operator' => 'gte', 'value' => 500],
			['field' => 'is_unanswered', 'operator' => 'eq', 'value' => 1],
		]];

		$this->assertFalse($this->engine->test($rule, $this->topic(['topic_replies' => 4, 'is_unanswered' => 0])));
	}

	/**
	 * An empty rule must not match everything.
	 *
	 * @return void
	 */
	public function test_empty_rule_matches_nothing()
	{
		$this->assertFalse($this->engine->test(['rule_conditions' => []], $this->topic()));
	}

	/**
	 * An unknown field fails closed rather than being ignored.
	 *
	 * @return void
	 */
	public function test_unknown_field_fails_closed()
	{
		$rule = ['rule_conditions' => [
			['field' => 'user_password', 'operator' => 'eq', 'value' => 1],
		]];

		$this->assertFalse($this->engine->test($rule, $this->topic()));
	}

	/**
	 * An unknown operator fails closed rather than defaulting to equality.
	 *
	 * @return void
	 */
	public function test_unknown_operator_fails_closed()
	{
		$rule = ['rule_conditions' => [
			['field' => 'views', 'operator' => 'regex', 'value' => 1],
		]];

		$this->assertFalse($this->engine->test($rule, $this->topic()));
	}

	/**
	 * Every whitelisted operator behaves as its name says.
	 *
	 * @return void
	 */
	public function test_operators()
	{
		$cases = [
			['eq', 1000, true], ['eq', 999, false],
			['neq', 999, true], ['neq', 1000, false],
			['gt', 999, true], ['gt', 1000, false],
			['gte', 1000, true], ['gte', 1001, false],
			['lt', 1001, true], ['lt', 1000, false],
			['lte', 1000, true], ['lte', 999, false],
		];

		foreach ($cases as list($operator, $value, $expected))
		{
			$rule = ['rule_conditions' => [['field' => 'views', 'operator' => $operator, 'value' => $value]]];

			$this->assertSame(
				$expected,
				$this->engine->test($rule, $this->topic()),
				sprintf('views(1000) %s %d should be %s', $operator, $value, $expected ? 'true' : 'false')
			);
		}
	}

	/**
	 * Derived facts are computed, not read straight from a column.
	 *
	 * @return void
	 */
	public function test_derived_age_in_hours()
	{
		$rule = ['rule_conditions' => [['field' => 'age_hours', 'operator' => 'gte', 'value' => 47]]];

		$this->assertTrue($this->engine->test($rule, $this->topic()));
		$this->assertFalse($this->engine->test($rule, $this->topic(['topic_time' => time() - 3600])));
	}
}
