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

use salvocortesiano\forumhealth\service\scoring\health_score;

/**
 * Tests the health indicator arithmetic.
 *
 * A wrong score does not throw; it just quietly misleads somebody into
 * spending an afternoon on the wrong problem. The cases below pin down the
 * three behaviours that make the number defensible: a zero weight removes a
 * factor entirely, too little data produces "unavailable" rather than zero, and
 * the weighted mean is actually a weighted mean.
 */
class health_score_test extends \phpbb_test_case
{
	/**
	 * Build a scorer whose repositories return fixed figures.
	 *
	 * @param array $counts   Topic summary counts.
	 * @param array $weights  Weight key to value.
	 * @return health_score
	 */
	protected function scorer(array $counts, array $weights)
	{
		$topics = $this->getMockBuilder('\salvocortesiano\forumhealth\repository\topic_repository')
			->disableOriginalConstructor()->getMock();
		$topics->method('summary_counts')->willReturn($counts);

		$relations = $this->getMockBuilder('\salvocortesiano\forumhealth\repository\relation_repository')
			->disableOriginalConstructor()->getMock();
		$relations->method('count')->willReturn(0);

		$links = $this->getMockBuilder('\salvocortesiano\forumhealth\repository\link_repository')
			->disableOriginalConstructor()->getMock();
		$links->method('counts_by_state')->willReturn([]);

		$metrics = $this->getMockBuilder('\salvocortesiano\forumhealth\repository\metric_repository')
			->disableOriginalConstructor()->getMock();

		$community = $this->getMockBuilder('\salvocortesiano\forumhealth\service\community\community_analyser')
			->disableOriginalConstructor()->getMock();
		$community->method('has_history')->willReturn(false);

		$settings = $this->getMockBuilder('\salvocortesiano\forumhealth\service\settings')
			->disableOriginalConstructor()->getMock();
		$settings->method('feature_enabled')->willReturn(false);
		$settings->method('get_int')->willReturnCallback(function ($key) use ($weights) {
			return isset($weights[$key]) ? $weights[$key] : 0;
		});

		return new health_score($topics, $relations, $links, $metrics, $community, $settings);
	}

	/**
	 * A forum with too few topics reports unavailable, not zero.
	 *
	 * Showing a confident 0/100 to somebody who installed the extension five
	 * minutes ago would be both wrong and discouraging.
	 *
	 * @return void
	 */
	public function test_insufficient_data_is_not_a_zero_score()
	{
		$score = $this->scorer(
			['total' => 5, 'unanswered' => 1, 'stale' => 0, 'solved' => 0],
			['fh_weight_unanswered' => 100]
		)->content_health();

		$this->assertFalse($score['available']);
		$this->assertSame('FH_NOT_ENOUGH_DATA', $score['reason']);
	}

	/**
	 * A factor weighted zero is excluded from both the maths and the display.
	 *
	 * @return void
	 */
	public function test_zero_weight_removes_a_factor()
	{
		$score = $this->scorer(
			['total' => 100, 'unanswered' => 50, 'stale' => 0, 'solved' => 0],
			['fh_weight_unanswered' => 100, 'fh_weight_duplicates' => 0, 'fh_weight_freshness' => 0]
		)->content_health();

		$this->assertTrue($score['available']);
		$this->assertCount(1, $score['factors']);
		$this->assertSame('FH_FACTOR_ANSWERED', $score['factors'][0]['key']);
		$this->assertSame(50, $score['score']);
	}

	/**
	 * With every weight at zero there is nothing to average.
	 *
	 * @return void
	 */
	public function test_all_weights_zero_is_unavailable()
	{
		$score = $this->scorer(
			['total' => 100, 'unanswered' => 10, 'stale' => 0, 'solved' => 0],
			[]
		)->content_health();

		$this->assertFalse($score['available']);
		$this->assertSame('FH_NO_WEIGHTS', $score['reason']);
	}

	/**
	 * Weights actually weight.
	 *
	 * Answered ratio is 100 with weight 75; freshness is 0 with weight 25.
	 * A plain mean would give 50; the weighted mean gives 75.
	 *
	 * @return void
	 */
	public function test_weighted_mean()
	{
		$score = $this->scorer(
			['total' => 100, 'unanswered' => 0, 'stale' => 100, 'solved' => 0],
			['fh_weight_unanswered' => 75, 'fh_weight_freshness' => 25, 'fh_weight_duplicates' => 0]
		)->content_health();

		$this->assertSame(75, $score['score']);
	}

	/**
	 * Every factor carries the figures that produced it.
	 *
	 * Without this the "why is this number?" panel would have nothing to show,
	 * and the score would be exactly the opaque verdict it is meant not to be.
	 *
	 * @return void
	 */
	public function test_factors_expose_their_evidence()
	{
		$score = $this->scorer(
			['total' => 200, 'unanswered' => 40, 'stale' => 0, 'solved' => 0],
			['fh_weight_unanswered' => 100]
		)->content_health();

		$factor = $score['factors'][0];

		$this->assertArrayHasKey('data', $factor);
		$this->assertSame(160, $factor['data']['answered']);
		$this->assertSame(200, $factor['data']['total']);
		$this->assertSame(100, $factor['weight']);
	}
}
