<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\content;

use salvocortesiano\forumhealth\constants;
use salvocortesiano\forumhealth\repository\job_repository;
use salvocortesiano\forumhealth\repository\topic_repository;
use salvocortesiano\forumhealth\service\settings;
use salvocortesiano\forumhealth\service\text\normaliser;

/**
 * The incremental pass that turns topics into stored metrics.
 *
 * This runs in the background and nowhere else. Every figure the dashboard shows
 * was computed here and written to a table; no page request ever recomputes it.
 *
 * The scan is a resumable cursor over topic ids. It processes one batch per run,
 * remembers where it stopped, and wraps back to the beginning when it reaches
 * the end, so a forum with a million topics is covered gradually rather than in
 * one query that would time out.
 */
class content_analyser
{
	/** @var topic_repository */
	protected $topics;

	/** @var job_repository */
	protected $jobs;

	/** @var normaliser */
	protected $normaliser;

	/** @var settings */
	protected $settings;

	/** @var freshness_analyser */
	protected $freshness;

	/** @var solution_detector */
	protected $solutions;

	/**
	 * @param topic_repository   $topics     Topic repository.
	 * @param job_repository     $jobs       Job bookkeeping.
	 * @param normaliser         $normaliser Text normaliser.
	 * @param settings           $settings   Extension settings.
	 * @param freshness_analyser $freshness  Freshness analysis.
	 * @param solution_detector  $solutions  Solution detection.
	 */
	public function __construct(
		topic_repository $topics,
		job_repository $jobs,
		normaliser $normaliser,
		settings $settings,
		freshness_analyser $freshness,
		solution_detector $solutions
	)
	{
		$this->topics = $topics;
		$this->jobs = $jobs;
		$this->normaliser = $normaliser;
		$this->settings = $settings;
		$this->freshness = $freshness;
		$this->solutions = $solutions;
	}

	/**
	 * Process one batch of topics.
	 *
	 * @return array{processed:int,cursor:int,wrapped:bool}
	 */
	public function run_batch()
	{
		$job = $this->jobs->get(constants::JOB_CONTENT);
		$cursor = (int) $job['cursor_value'];
		$batch = $this->settings->get_int('fh_batch_size');

		$rows = $this->topics->fetch_batch($cursor, $batch, $this->settings->excluded_forums());

		if (empty($rows))
		{
			// End of the table: start again from the beginning next time so that
			// older topics are re-examined as the forum changes around them.
			return ['processed' => 0, 'cursor' => 0, 'wrapped' => true];
		}

		$topic_ids = [];
		$posters = [];

		foreach ($rows as $row)
		{
			$topic_ids[] = (int) $row['topic_id'];
			$posters[(int) $row['topic_id']] = (int) $row['topic_poster'];
		}

		$first_replies = $this->topics->first_reply_times($topic_ids);
		$first_topics = $this->topics->flag_first_topics($posters);

		$now = time();
		$metrics = [];
		$last_id = $cursor;

		foreach ($rows as $row)
		{
			$topic_id = (int) $row['topic_id'];
			$last_id = max($last_id, $topic_id);

			$tokens = $this->normaliser->tokenise($row['topic_title']);
			$replies = max(0, (int) $row['topic_posts_approved'] - 1);

			$metrics[] = [
				'topic_id'			=> $topic_id,
				'forum_id'			=> (int) $row['forum_id'],
				'topic_poster'		=> (int) $row['topic_poster'],
				'topic_time'		=> (int) $row['topic_time'],
				'last_post_time'	=> (int) $row['topic_last_post_time'],
				'topic_replies'		=> $replies,
				'topic_views'		=> (int) $row['topic_views'],
				'title_normalised'	=> $this->normaliser->normalise($row['topic_title']),
				'title_tokens'		=> $this->normaliser->pack_tokens($tokens),
				'is_unanswered'		=> $replies === 0 ? 1 : 0,
				'is_first_topic'	=> !empty($first_topics[$topic_id]) ? 1 : 0,
				'first_reply_time'	=> isset($first_replies[$topic_id]) ? (int) $first_replies[$topic_id] : 0,
				'content_hash'		=> $this->normaliser->content_hash($row['topic_title'] . '|' . $row['topic_last_post_time']),
				'analysed_at'		=> $now,
			];
		}

		$this->topics->store_metrics($metrics);

		// Freshness and solutions are judged per topic and only for the subset
		// that could plausibly qualify, which keeps this pass cheap.
		if ($this->settings->feature_enabled('freshness'))
		{
			$this->freshness->analyse_batch($rows);
		}

		if ($this->settings->feature_enabled('solutions'))
		{
			$this->solutions->analyse_batch($rows);
		}

		return [
			'processed'	=> count($rows),
			'cursor'	=> $last_id,
			'wrapped'	=> false,
		];
	}

	/**
	 * Analyse one topic immediately.
	 *
	 * Used when an administrator asks for a single topic to be re-examined, and
	 * by the posting-time duplicate check, which needs metrics for a topic that
	 * the background pass has not reached yet.
	 *
	 * @param int $topic_id Topic id.
	 * @return bool True when the topic was found and stored.
	 */
	public function analyse_topic($topic_id)
	{
		$rows = $this->topics->fetch_batch((int) $topic_id - 1, 1, []);

		if (empty($rows) || (int) $rows[0]['topic_id'] !== (int) $topic_id)
		{
			return false;
		}

		$row = $rows[0];
		$replies = max(0, (int) $row['topic_posts_approved'] - 1);
		$tokens = $this->normaliser->tokenise($row['topic_title']);
		$first_replies = $this->topics->first_reply_times([(int) $topic_id]);
		$first_topics = $this->topics->flag_first_topics([(int) $topic_id => (int) $row['topic_poster']]);

		$this->topics->store_metrics([[
			'topic_id'			=> (int) $topic_id,
			'forum_id'			=> (int) $row['forum_id'],
			'topic_poster'		=> (int) $row['topic_poster'],
			'topic_time'		=> (int) $row['topic_time'],
			'last_post_time'	=> (int) $row['topic_last_post_time'],
			'topic_replies'		=> $replies,
			'topic_views'		=> (int) $row['topic_views'],
			'title_normalised'	=> $this->normaliser->normalise($row['topic_title']),
			'title_tokens'		=> $this->normaliser->pack_tokens($tokens),
			'is_unanswered'		=> $replies === 0 ? 1 : 0,
			'is_first_topic'	=> !empty($first_topics[(int) $topic_id]) ? 1 : 0,
			'first_reply_time'	=> isset($first_replies[(int) $topic_id]) ? (int) $first_replies[(int) $topic_id] : 0,
			'content_hash'		=> $this->normaliser->content_hash($row['topic_title'] . '|' . $row['topic_last_post_time']),
			'analysed_at'		=> time(),
		]]);

		return true;
	}

	/**
	 * Coverage of the analysis, for the job status page.
	 *
	 * @return array{analysed:int,total:int,percent:int}
	 */
	public function coverage()
	{
		$counts = $this->topics->summary_counts();
		$max = $this->topics->max_topic_id();

		// Topic ids are not dense after deletions, so this is an approximation
		// and is labelled as such in the interface.
		$percent = $max > 0 ? (int) round(min(100, ($counts['total'] / max(1, $max)) * 100)) : 0;

		return [
			'analysed'	=> $counts['total'],
			'total'		=> $max,
			'percent'	=> $percent,
		];
	}
}
