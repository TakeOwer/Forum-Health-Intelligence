<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\scoring;

use salvocortesiano\forumhealth\constants;
use salvocortesiano\forumhealth\repository\link_repository;
use salvocortesiano\forumhealth\repository\metric_repository;
use salvocortesiano\forumhealth\repository\relation_repository;
use salvocortesiano\forumhealth\repository\topic_repository;
use salvocortesiano\forumhealth\service\community\community_analyser;
use salvocortesiano\forumhealth\service\settings;

/**
 * Produces the health indicators, and shows its working.
 *
 * A single number summarising a forum is a convenience, not a truth, and it is
 * presented that way throughout: the wording is "health indicator", never
 * "quality". What makes it defensible is that every component is visible. Each
 * factor returns its own score, the weight applied to it, and the figures behind
 * it, so an administrator can open "why is this 84?" and see the arithmetic
 * rather than a claim.
 *
 * Weights are configurable, because what counts as healthy differs between a
 * support forum and a social one. A factor whose weight is zero disappears from
 * the calculation and from the explanation.
 *
 * Where there is not enough data, the factor says so and is excluded rather than
 * scored as if it were zero.
 */
class health_score
{
	/** @var topic_repository */
	protected $topics;

	/** @var relation_repository */
	protected $relations;

	/** @var link_repository */
	protected $links;

	/** @var metric_repository */
	protected $metrics;

	/** @var community_analyser */
	protected $community;

	/** @var settings */
	protected $settings;

	/** Minimum topics before a content score means anything. */
	const MIN_TOPICS = 20;

	/**
	 * @param topic_repository    $topics    Topic repository.
	 * @param relation_repository $relations Relation repository.
	 * @param link_repository     $links     Link repository.
	 * @param metric_repository   $metrics   Metric history.
	 * @param community_analyser  $community Community analysis.
	 * @param settings            $settings  Extension settings.
	 */
	public function __construct(
		topic_repository $topics,
		relation_repository $relations,
		link_repository $links,
		metric_repository $metrics,
		community_analyser $community,
		settings $settings
	)
	{
		$this->topics = $topics;
		$this->relations = $relations;
		$this->links = $links;
		$this->metrics = $metrics;
		$this->community = $community;
		$this->settings = $settings;
	}

	/**
	 * The three headline indicators with their components.
	 *
	 * @return array{content:array,community:array,overall:array}
	 */
	public function calculate()
	{
		$content = $this->content_health();
		$community = $this->community_health();

		$scores = [];

		if ($content['available'])
		{
			$scores[] = $content['score'];
		}

		if ($community['available'])
		{
			$scores[] = $community['score'];
		}

		$overall = [
			'available'	=> !empty($scores),
			'score'		=> !empty($scores) ? (int) round(array_sum($scores) / count($scores)) : 0,
		];

		return [
			'content'	=> $content,
			'community'	=> $community,
			'overall'	=> $overall,
		];
	}

	/**
	 * Content health and its factors.
	 *
	 * @return array{available:bool,score:int,factors:array[]}
	 */
	public function content_health()
	{
		$counts = $this->topics->summary_counts();

		if ($counts['total'] < self::MIN_TOPICS)
		{
			return ['available' => false, 'score' => 0, 'factors' => [], 'reason' => 'FH_NOT_ENOUGH_DATA'];
		}

		$factors = [];

		// Answered ratio. The clearest single statement about whether a forum
		// does what a forum is for.
		$answered = $counts['total'] - $counts['unanswered'];
		$factors[] = $this->factor(
			'FH_FACTOR_ANSWERED',
			'fh_weight_unanswered',
			(int) round(($answered / $counts['total']) * 100),
			['answered' => $answered, 'total' => $counts['total']]
		);

		// Duplicate pressure, expressed as the share of topics awaiting review.
		$pending_duplicates = $this->relations->count(['status' => constants::RELATION_NEW]);
		$factors[] = $this->factor(
			'FH_FACTOR_DUPLICATES',
			'fh_weight_duplicates',
			$this->inverse_ratio($pending_duplicates, $counts['total'], 5),
			['count' => $pending_duplicates]
		);

		// Link health, scored only when the scanner has actually run.
		$states = $this->links->counts_by_state();
		$checked = array_sum($states) - (isset($states[constants::LINK_PENDING]) ? $states[constants::LINK_PENDING] : 0);

		if ($this->settings->feature_enabled('links') && $checked >= 10)
		{
			$broken = isset($states[constants::LINK_BROKEN]) ? $states[constants::LINK_BROKEN] : 0;
			$factors[] = $this->factor(
				'FH_FACTOR_LINKS',
				'fh_weight_links',
				(int) round((($checked - $broken) / $checked) * 100),
				['broken' => $broken, 'checked' => $checked]
			);
		}

		// Freshness: the share of content not currently flagged for review.
		$factors[] = $this->factor(
			'FH_FACTOR_FRESHNESS',
			'fh_weight_freshness',
			(int) round((($counts['total'] - $counts['stale']) / $counts['total']) * 100),
			['stale' => $counts['stale'], 'total' => $counts['total']]
		);

		// Solution coverage, measured against answered topics rather than all
		// topics: a discussion thread was never meant to have a solution.
		if ($this->settings->feature_enabled('solutions') && $answered > 0)
		{
			$factors[] = $this->factor(
				'FH_FACTOR_SOLUTIONS',
				'fh_weight_solutions',
				(int) round(min(100, ($counts['solved'] / $answered) * 100)),
				['solved' => $counts['solved'], 'answered' => $answered]
			);
		}

		return $this->combine($factors);
	}

	/**
	 * Community health and its factors.
	 *
	 * @return array{available:bool,score:int,factors:array[]}
	 */
	public function community_health()
	{
		if (!$this->community->has_history(14))
		{
			return ['available' => false, 'score' => 0, 'factors' => [], 'reason' => 'FH_NOT_ENOUGH_HISTORY'];
		}

		$days = $this->settings->get_int('fh_trend_period_days');
		$factors = [];

		// Participation, scored as the trend rather than the absolute number:
		// a hundred active members is healthy for one forum and alarming for
		// another, but a third fewer than last month means the same thing to
		// both.
		$participation = $this->community->compare_periods(community_analyser::M_ACTIVE_POSTERS, $days);

		if ($participation['has_baseline'])
		{
			$factors[] = $this->factor(
				'FH_FACTOR_PARTICIPATION',
				'fh_weight_participation',
				$this->trend_score($participation['change']),
				[
					'current'	=> (int) $participation['current'],
					'change'	=> (int) round($participation['change']),
				]
			);
		}

		// Responsiveness, from the average time to a first reply.
		$response = $this->community->compare_periods(community_analyser::M_RESPONSE_SECONDS, $days);

		if ($response['current'] > 0)
		{
			$factors[] = $this->factor(
				'FH_FACTOR_RESPONSIVENESS',
				'fh_weight_responsiveness',
				$this->response_score((int) $response['current']),
				['hours' => (int) round($response['current'] / 3600)]
			);
		}

		// Onboarding: the share of first discussions that received a reply.
		$first = $this->community->first_post_experience($days);

		if ($first['total'] >= 5)
		{
			$factors[] = $this->factor(
				'FH_FACTOR_ONBOARDING',
				'fh_weight_onboarding',
				$first['answered_percent'],
				['answered' => $first['answered'], 'total' => $first['total']]
			);
		}

		// Retention of the newcomer cohort.
		if ($first['cohort'] >= 5)
		{
			$factors[] = $this->factor(
				'FH_FACTOR_RETENTION',
				'fh_weight_retention',
				$first['return_percent'],
				['percent' => $first['return_percent'], 'cohort' => $first['cohort']]
			);
		}

		return $this->combine($factors);
	}

	/**
	 * Record today's scores so the indicator itself can be trended.
	 *
	 * @return void
	 */
	public function record_history()
	{
		$scores = $this->calculate();
		$day = (int) gmdate('Ymd');

		if ($scores['content']['available'])
		{
			$this->metrics->record('score_content', $day, $scores['content']['score']);
		}

		if ($scores['community']['available'])
		{
			$this->metrics->record('score_community', $day, $scores['community']['score']);
		}

		if ($scores['overall']['available'])
		{
			$this->metrics->record('score_overall', $day, $scores['overall']['score']);
		}
	}

	/**
	 * Build one factor.
	 *
	 * @param string $key        Language key naming the factor.
	 * @param string $weight_key Configuration key holding its weight.
	 * @param int    $score      Factor score, 0-100.
	 * @param array  $data       Figures behind the score, for the explanation.
	 * @return array
	 */
	protected function factor($key, $weight_key, $score, array $data)
	{
		$score = max(0, min(100, (int) $score));

		return [
			'key'		=> $key,
			'score'		=> $score,
			'weight'	=> $this->settings->get_int($weight_key),
			'data'		=> $data,
			// Used by the interface to sort factors into what is helping and
			// what is holding the score back.
			'positive'	=> $score >= 70,
		];
	}

	/**
	 * Weighted mean of the factors that carry weight.
	 *
	 * @param array[] $factors Factors.
	 * @return array{available:bool,score:int,factors:array[]}
	 */
	protected function combine(array $factors)
	{
		$total_weight = 0;
		$weighted = 0;
		$used = [];

		foreach ($factors as $factor)
		{
			if ($factor['weight'] <= 0)
			{
				// A weight of zero is an explicit instruction to ignore the
				// factor, so it is left out of the explanation too.
				continue;
			}

			$total_weight += $factor['weight'];
			$weighted += $factor['score'] * $factor['weight'];
			$used[] = $factor;
		}

		if ($total_weight === 0)
		{
			return ['available' => false, 'score' => 0, 'factors' => [], 'reason' => 'FH_NO_WEIGHTS'];
		}

		return [
			'available'	=> true,
			'score'		=> (int) round($weighted / $total_weight),
			'factors'	=> $used,
		];
	}

	/**
	 * Score a share, inverted, against a tolerance.
	 *
	 * A small number of pending items is normal and should not be punished; the
	 * score falls as the share approaches the tolerance.
	 *
	 * @param int $count     Observed count.
	 * @param int $total     Population.
	 * @param int $tolerance Percentage at which the score reaches zero.
	 * @return int
	 */
	protected function inverse_ratio($count, $total, $tolerance)
	{
		if ($total <= 0)
		{
			return 100;
		}

		$percent = ($count / $total) * 100;

		return (int) round(max(0, 100 - (($percent / max(1, $tolerance)) * 100)));
	}

	/**
	 * Convert a percentage change into a score around a neutral midpoint.
	 *
	 * Stable participation scores 75, not 100: steady is good, growing is
	 * better, and a forum should not be told it is perfect for standing still.
	 *
	 * @param float $change Percentage change.
	 * @return int
	 */
	protected function trend_score($change)
	{
		return (int) round(max(0, min(100, 75 + ($change * 1.5))));
	}

	/**
	 * Convert an average first-reply time into a score.
	 *
	 * The bands are generous by design. Forums are not support desks, and a
	 * reply within a day is a good outcome for most communities.
	 *
	 * @param int $seconds Average seconds to first reply.
	 * @return int
	 */
	protected function response_score($seconds)
	{
		$hours = $seconds / 3600;

		if ($hours <= 2)
		{
			return 100;
		}

		if ($hours <= 6)
		{
			return 90;
		}

		if ($hours <= 24)
		{
			return 75;
		}

		if ($hours <= 72)
		{
			return 55;
		}

		if ($hours <= 168)
		{
			return 35;
		}

		return 15;
	}
}
