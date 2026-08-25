<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\community;

use salvocortesiano\forumhealth\repository\community_repository;
use salvocortesiano\forumhealth\repository\metric_repository;
use salvocortesiano\forumhealth\service\settings;

/**
 * Measures the community rather than its members.
 *
 * Everything produced here is an aggregate over a time window: how many people
 * took part, how quickly questions were answered, whether newcomers were made
 * welcome. Nothing is inferred about any individual, and no attribute beyond
 * observable posting activity is derived. That is a privacy decision, and it is
 * also the more useful framing: an administrator can act on "first-post replies
 * are down a third this month" in a way they cannot act on a list of names.
 *
 * The daily figures are written once per day and read from history afterwards,
 * so a trend comparison costs two small range queries no matter how large the
 * forum is.
 */
class community_analyser
{
	/** @var community_repository */
	protected $community;

	/** @var metric_repository */
	protected $metrics;

	/** @var settings */
	protected $settings;

	/** Registrations in the period. */
	const M_REGISTRATIONS = 'registrations';

	/** Distinct members who posted. */
	const M_ACTIVE_POSTERS = 'active_posters';

	/** Topics created. */
	const M_TOPICS = 'topics';

	/** Posts made. */
	const M_POSTS = 'posts';

	/** Average seconds to a first reply. */
	const M_RESPONSE_SECONDS = 'response_seconds';

	/** First topics by new members. */
	const M_FIRST_TOPICS = 'first_topics';

	/** First topics that received a reply in time. */
	const M_FIRST_ANSWERED = 'first_answered';

	/**
	 * @param community_repository $community Community repository.
	 * @param metric_repository    $metrics   Metric history.
	 * @param settings             $settings  Extension settings.
	 */
	public function __construct(community_repository $community, metric_repository $metrics, settings $settings)
	{
		$this->community = $community;
		$this->metrics = $metrics;
		$this->settings = $settings;
	}

	/**
	 * Record yesterday's figures.
	 *
	 * Yesterday rather than today, because a partial day would produce a
	 * misleading point on every chart it appears in.
	 *
	 * @param int|null $day_start Optional explicit day start, for backfilling.
	 * @return int The day bucket that was written.
	 */
	public function record_day($day_start = null)
	{
		$day_start = $day_start === null ? strtotime('yesterday midnight') : (int) $day_start;
		$day_end = $day_start + 86400;
		$bucket = (int) gmdate('Ymd', $day_start);

		$this->metrics->record(self::M_REGISTRATIONS, $bucket, $this->community->count_registrations($day_start, $day_end));
		$this->metrics->record(self::M_ACTIVE_POSTERS, $bucket, $this->community->count_active_posters($day_start, $day_end));
		$this->metrics->record(self::M_TOPICS, $bucket, $this->community->count_topics($day_start, $day_end));
		$this->metrics->record(self::M_POSTS, $bucket, $this->community->count_posts($day_start, $day_end));
		$this->metrics->record(self::M_RESPONSE_SECONDS, $bucket, $this->community->average_response_seconds($day_start, $day_end));

		$first = $this->community->first_post_experience(
			$day_start,
			$day_end,
			$this->settings->get_int('fh_newuser_reply_hours')
		);

		$this->metrics->record(self::M_FIRST_TOPICS, $bucket, $first['total']);
		$this->metrics->record(self::M_FIRST_ANSWERED, $bucket, $first['answered']);

		return $bucket;
	}

	/**
	 * Fill in any missing days since the last recorded one.
	 *
	 * Keeps history continuous when cron has not run for a while, without ever
	 * recomputing days that are already stored.
	 *
	 * @param int $max_days Maximum days to backfill in one run.
	 * @return int Days written.
	 */
	public function backfill($max_days = 7)
	{
		$written = 0;

		for ($offset = $max_days; $offset >= 1; $offset--)
		{
			$day_start = strtotime('today midnight') - ($offset * 86400);
			$bucket = (int) gmdate('Ymd', $day_start);

			$existing = $this->metrics->series(self::M_POSTS, $bucket, $bucket);

			if (!empty($existing))
			{
				continue;
			}

			$this->record_day($day_start);
			$written++;
		}

		return $written;
	}

	/**
	 * Compare a period against the one immediately before it.
	 *
	 * @param string $metric Metric key.
	 * @param int    $days   Length of the period in days.
	 * @return array{current:float,previous:float,change:float,direction:string,
	 *               has_baseline:bool}
	 */
	public function compare_periods($metric, $days)
	{
		$days = max(1, (int) $days);

		$current_from = (int) gmdate('Ymd', strtotime('today midnight') - ($days * 86400));
		$current_to = (int) gmdate('Ymd', strtotime('yesterday midnight'));
		$previous_from = (int) gmdate('Ymd', strtotime('today midnight') - ($days * 2 * 86400));
		$previous_to = (int) gmdate('Ymd', strtotime('today midnight') - (($days + 1) * 86400));

		$averaged = ($metric === self::M_RESPONSE_SECONDS);

		$current = $averaged
			? $this->metrics->average($metric, $current_from, $current_to)
			: $this->metrics->sum($metric, $current_from, $current_to);

		$previous = $averaged
			? $this->metrics->average($metric, $previous_from, $previous_to)
			: $this->metrics->sum($metric, $previous_from, $previous_to);

		// Without a baseline a percentage would be meaningless, and reporting
		// "+100%" against zero would be worse than saying nothing.
		$has_baseline = $previous > 0;
		$change = $has_baseline ? (($current - $previous) / $previous) * 100 : 0.0;

		return [
			'current'		=> $current,
			'previous'		=> $previous,
			'change'		=> round($change, 1),
			'direction'		=> $this->direction($change, $has_baseline),
			'has_baseline'	=> $has_baseline,
		];
	}

	/**
	 * Current first-post experience over the configured window.
	 *
	 * @param int|null $days Window length, defaults to the trend period.
	 * @return array{total:int,answered:int,unanswered:int,avg_seconds:int,
	 *               answered_percent:int,return_percent:int}
	 */
	public function first_post_experience($days = null)
	{
		$days = $days === null ? $this->settings->get_int('fh_trend_period_days') : (int) $days;
		$to = time();
		$from = $to - ($days * 86400);

		$stats = $this->community->first_post_experience($from, $to, $this->settings->get_int('fh_newuser_reply_hours'));
		$stats['answered_percent'] = $stats['total'] > 0
			? (int) round(($stats['answered'] / $stats['total']) * 100)
			: 0;

		// The return cohort is offset by a week so that everyone in it has had
		// the chance to come back that the metric claims to measure.
		$cohort = $this->community->return_rate($from - (7 * 86400), $to - (7 * 86400), 7);
		$stats['return_percent'] = $cohort['cohort'] > 0
			? (int) round(($cohort['returned'] / $cohort['cohort']) * 100)
			: 0;
		$stats['cohort'] = $cohort['cohort'];

		return $stats;
	}

	/**
	 * Members who reply to other people, over the configured window.
	 *
	 * @param int $limit Maximum rows.
	 * @return array{responders:array[],newcomer_helpers:array[]}
	 */
	public function contributors($limit = 20)
	{
		$days = $this->settings->get_int('fh_trend_period_days');
		$to = time();
		$from = $to - ($days * 86400);

		return [
			'responders'		=> $this->community->top_responders($from, $to, $limit),
			'newcomer_helpers'	=> $this->community->newcomer_helpers($from, $to, 30 * 86400, 10),
		];
	}

	/**
	 * A metric's daily series over the last n days, for charting.
	 *
	 * @param string $metric Metric key.
	 * @param int    $days   Number of days.
	 * @return array<int, float> day bucket => value.
	 */
	public function series($metric, $days = 30)
	{
		$from = (int) gmdate('Ymd', strtotime('today midnight') - ((int) $days * 86400));
		$to = (int) gmdate('Ymd', strtotime('yesterday midnight'));

		return $this->metrics->series($metric, $from, $to);
	}

	/**
	 * Whether enough history exists to draw conclusions.
	 *
	 * @param int $days Days required.
	 * @return bool
	 */
	public function has_history($days = 14)
	{
		$from = (int) gmdate('Ymd', strtotime('today midnight') - ((int) $days * 86400));
		$to = (int) gmdate('Ymd', strtotime('yesterday midnight'));

		return count($this->metrics->series(self::M_POSTS, $from, $to)) >= max(2, (int) ($days / 2));
	}

	/**
	 * Describe a change without asserting a cause.
	 *
	 * @param float $change       Percentage change.
	 * @param bool  $has_baseline Whether a baseline existed.
	 * @return string up, down or flat.
	 */
	protected function direction($change, $has_baseline)
	{
		if (!$has_baseline || abs($change) < 5)
		{
			return 'flat';
		}

		return $change > 0 ? 'up' : 'down';
	}
}
