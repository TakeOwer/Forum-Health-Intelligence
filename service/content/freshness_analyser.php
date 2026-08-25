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

use salvocortesiano\forumhealth\repository\post_repository;
use salvocortesiano\forumhealth\repository\topic_repository;
use salvocortesiano\forumhealth\service\integrations\ai\adapter as ai_adapter;
use salvocortesiano\forumhealth\service\integrations\ai\provider_interface;
use salvocortesiano\forumhealth\service\settings;
use salvocortesiano\forumhealth\service\text\normaliser;

/**
 * Flags content that may deserve a human review.
 *
 * The word "may" is the whole design. Age alone means nothing: a good answer
 * from 2015 can still be correct. What this looks for is age combined with a
 * reason to doubt, and it reports the reason rather than a verdict. Nothing here
 * ever edits, hides or marks content as wrong.
 *
 * Native signals, in increasing order of strength:
 *   - the topic is old and still read, so a wrong answer would still mislead;
 *   - the topic names a software version, which dates it in a checkable way;
 *   - the topic contains links that the scanner has since found broken.
 */
class freshness_analyser
{
	/** @var topic_repository */
	protected $topics;

	/** @var post_repository */
	protected $posts;

	/** @var normaliser */
	protected $normaliser;

	/** @var settings */
	protected $settings;

	/** @var ai_adapter */
	protected $ai;

	/**
	 * @param topic_repository $topics     Topic repository.
	 * @param post_repository  $posts      Post repository.
	 * @param normaliser       $normaliser Text normaliser.
	 * @param settings         $settings   Extension settings.
	 * @param ai_adapter       $ai         Optional AI adapter.
	 */
	public function __construct(
		topic_repository $topics,
		post_repository $posts,
		normaliser $normaliser,
		settings $settings,
		ai_adapter $ai
	)
	{
		$this->topics = $topics;
		$this->posts = $posts;
		$this->normaliser = $normaliser;
		$this->settings = $settings;
		$this->ai = $ai;
	}

	/**
	 * Assess a batch of topic rows.
	 *
	 * @param array[] $rows Raw topic rows from the scan.
	 * @return int Number of topics flagged.
	 */
	public function analyse_batch(array $rows)
	{
		$threshold = time() - ($this->settings->get_int('fh_freshness_months') * 30 * 86400);
		$min_views = $this->settings->get_int('fh_freshness_min_views');
		$flagged = 0;

		$candidates = [];

		foreach ($rows as $row)
		{
			// Only old, still-read topics are worth the cost of looking closer.
			if ((int) $row['topic_last_post_time'] > $threshold || (int) $row['topic_views'] < $min_views)
			{
				continue;
			}

			$candidates[(int) $row['topic_id']] = $row;
		}

		if (empty($candidates))
		{
			return 0;
		}

		$first_posts = $this->posts->first_posts(array_keys($candidates));

		foreach ($candidates as $topic_id => $row)
		{
			$text = $row['topic_title'];

			if (isset($first_posts[$topic_id]))
			{
				$text .= ' ' . $this->posts->to_plain_text($first_posts[$topic_id]['post_text'], 1200);
			}

			list($confidence, $reason) = $this->assess($topic_id, $row, $text);

			if ($confidence > 0)
			{
				$this->topics->set_freshness($topic_id, $confidence, $reason);
				$flagged++;
			}
		}

		return $flagged;
	}

	/**
	 * Judge one topic.
	 *
	 * @param int    $topic_id Topic id.
	 * @param array  $row      Topic row.
	 * @param string $text     Title plus opening post, already plain text.
	 * @return array{0:int,1:string} Confidence and reason code.
	 */
	protected function assess($topic_id, array $row, $text)
	{
		$age_years = max(0, (time() - (int) $row['topic_last_post_time']) / (365 * 86400));

		// Age contributes, but on its own it tops out below the threshold that
		// produces an alert. Something else has to corroborate it.
		$confidence = (int) min(45, round($age_years * 12));
		$reason = 'AGE';

		$versions = $this->normaliser->version_fragments($text);

		if (!empty($versions))
		{
			$confidence += 25;
			$reason = 'VERSION';
		}

		$confidence = min(100, $confidence);

		// AI is consulted only for topics that already look doubtful and only
		// when the administrator has enabled that specific feature.
		if ($confidence >= 40 && $this->ai->can(provider_interface::CAP_OUTDATED))
		{
			$payload = [
				'title'		=> (string) $row['topic_title'],
				'age_days'	=> (int) ((time() - (int) $row['topic_last_post_time']) / 86400),
				'versions'	=> $versions,
			];

			if ($this->ai->may_send_content())
			{
				$payload['content'] = utf8_substr($text, 0, 1500);
			}

			$result = $this->ai->analyse(
				provider_interface::CAP_OUTDATED,
				'topic',
				$topic_id,
				$this->normaliser->content_hash($text),
				$payload
			);

			if ($result !== null && (int) $result['confidence'] > 0)
			{
				return [(int) $result['confidence'], 'AI'];
			}
		}

		return [$confidence, $reason];
	}
}
