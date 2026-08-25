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
 * Identifies the reply that most likely resolved a discussion.
 *
 * The strongest native signal on a phpBB forum is not the wording of the answer
 * but the reaction of the person who asked. When the author of a topic replies
 * near the end saying it works, the post immediately before theirs is almost
 * always the solution. That pattern is language independent in structure, and
 * the confirming phrases are matched in both shipped languages.
 *
 * A detected solution is a suggestion for review. The topic is never marked
 * solved automatically unless an administrator has explicitly turned that on and
 * a compatible mechanism exists, which this extension does not assume.
 */
class solution_detector
{
	/** @var topic_repository */
	protected $topics;

	/** @var post_repository */
	protected $posts;

	/** @var settings */
	protected $settings;

	/** @var normaliser */
	protected $normaliser;

	/** @var ai_adapter */
	protected $ai;

	/**
	 * Phrases the asker uses to confirm a fix, in English and Italian.
	 *
	 * @var string[]
	 */
	protected static $confirmations = [
		'solved', 'resolved', 'fixed', 'that worked', 'it works', 'works now',
		'thanks that', 'thank you that', 'perfect thanks', 'problem solved',
		'risolto', 'funziona', 'ha funzionato', 'perfetto grazie', 'grazie mille',
		'era proprio', 'problema risolto', 'sistemato',
	];

	/**
	 * Phrases in a reply that suggest it is an answer rather than a follow-up
	 * question.
	 *
	 * @var string[]
	 */
	protected static $solution_markers = [
		'try', 'you need to', 'you should', 'the fix is', 'change the', 'set the',
		'edit the', 'run the', 'install', 'replace', 'the problem is',
		'prova', 'devi', 'basta', 'la soluzione', 'modifica', 'imposta',
		'sostituisci', 'il problema è', 'esegui',
	];

	/**
	 * @param topic_repository $topics     Topic repository.
	 * @param post_repository  $posts      Post repository.
	 * @param settings         $settings   Extension settings.
	 * @param normaliser       $normaliser Text normaliser.
	 * @param ai_adapter       $ai         Optional AI adapter.
	 */
	public function __construct(
		topic_repository $topics,
		post_repository $posts,
		settings $settings,
		normaliser $normaliser,
		ai_adapter $ai
	)
	{
		$this->topics = $topics;
		$this->posts = $posts;
		$this->settings = $settings;
		$this->normaliser = $normaliser;
		$this->ai = $ai;
	}

	/**
	 * Examine a batch of topics.
	 *
	 * @param array[] $rows Raw topic rows from the scan.
	 * @return int Number of solutions recorded.
	 */
	public function analyse_batch(array $rows)
	{
		$found = 0;

		foreach ($rows as $row)
		{
			// A discussion with no replies cannot contain a solution, and one
			// with very many is usually a conversation rather than a question.
			$replies = max(0, (int) $row['topic_posts_approved'] - 1);

			if ($replies < 1 || $replies > 60)
			{
				continue;
			}

			$result = $this->detect((int) $row['topic_id'], $row);

			if ($result !== null)
			{
				$this->topics->set_solution((int) $row['topic_id'], $result['post_id'], $result['confidence']);
				$found++;
			}
		}

		return $found;
	}

	/**
	 * Find the likely solution in one topic.
	 *
	 * @param int   $topic_id Topic id.
	 * @param array $row      Topic row.
	 * @return array{post_id:int,confidence:int}|null
	 */
	public function detect($topic_id, array $row)
	{
		$replies = $this->posts->replies($topic_id, 60);

		if (empty($replies))
		{
			return null;
		}

		$asker = (int) $row['topic_poster'];
		$candidate = null;

		foreach ($replies as $index => $reply)
		{
			if ((int) $reply['poster_id'] !== $asker || $index === 0)
			{
				continue;
			}

			$text = utf8_strtolower($this->posts->to_plain_text($reply['post_text'], 400));

			if (!$this->contains_any($text, self::$confirmations))
			{
				continue;
			}

			// The answer is the last reply from someone else before the
			// confirmation.
			for ($back = $index - 1; $back >= 0; $back--)
			{
				if ((int) $replies[$back]['poster_id'] !== $asker)
				{
					$candidate = [
						'post_id'		=> (int) $replies[$back]['post_id'],
						'confidence'	=> 85,
						'reason'		=> 'AUTHOR_CONFIRMED',
					];
					break 2;
				}
			}
		}

		if ($candidate === null)
		{
			$candidate = $this->weak_candidate($replies, $asker);
		}

		if ($candidate === null)
		{
			return null;
		}

		$minimum = $this->settings->get_int('fh_solution_min_confidence');

		// AI is asked only about the uncertain middle: cases the native rules
		// already answer confidently do not need to cost anything.
		if ($candidate['confidence'] < 80 && $this->ai->can(provider_interface::CAP_SOLUTION))
		{
			$refined = $this->refine_with_ai($topic_id, $row, $replies, $candidate);

			if ($refined !== null)
			{
				$candidate = $refined;
			}
		}

		return $candidate['confidence'] >= $minimum
			? ['post_id' => $candidate['post_id'], 'confidence' => $candidate['confidence']]
			: null;
	}

	/**
	 * Fall back to the most answer-like reply.
	 *
	 * @param array[] $replies Replies in chronological order.
	 * @param int     $asker   User id of the topic author.
	 * @return array{post_id:int,confidence:int,reason:string}|null
	 */
	protected function weak_candidate(array $replies, $asker)
	{
		$best = null;

		foreach ($replies as $reply)
		{
			if ((int) $reply['poster_id'] === $asker)
			{
				continue;
			}

			$text = utf8_strtolower($this->posts->to_plain_text($reply['post_text'], 800));

			if (!$this->contains_any($text, self::$solution_markers))
			{
				continue;
			}

			// Substance matters: a one-line "try again" is not an answer.
			$length = utf8_strlen($text);
			$confidence = 55;

			if ($length > 200)
			{
				$confidence += 5;
			}

			if ($length > 600)
			{
				$confidence += 5;
			}

			if ($best === null || $confidence > $best['confidence'])
			{
				$best = [
					'post_id'		=> (int) $reply['post_id'],
					'confidence'	=> $confidence,
					'reason'		=> 'ANSWER_LANGUAGE',
				];
			}
		}

		return $best;
	}

	/**
	 * Ask the AI provider to adjudicate an uncertain case.
	 *
	 * @param int     $topic_id  Topic id.
	 * @param array   $row       Topic row.
	 * @param array[] $replies   Replies.
	 * @param array   $candidate Current native candidate.
	 * @return array|null Refined candidate, or null to keep the native one.
	 */
	protected function refine_with_ai($topic_id, array $row, array $replies, array $candidate)
	{
		if (!$this->ai->may_send_content())
		{
			// Without post bodies there is nothing useful to ask about, so the
			// native judgement stands rather than a call being wasted.
			return null;
		}

		$excerpts = [];

		foreach (array_slice($replies, 0, 12) as $reply)
		{
			$excerpts[] = [
				'post_id'	=> (int) $reply['post_id'],
				'text'		=> $this->posts->to_plain_text($reply['post_text'], 500),
			];
		}

		$hash = $this->normaliser->content_hash(json_encode($excerpts));

		$result = $this->ai->analyse(
			provider_interface::CAP_SOLUTION,
			'topic',
			$topic_id,
			$hash,
			[
				'title'		=> (string) $row['topic_title'],
				'replies'	=> $excerpts,
			]
		);

		if ($result === null || (int) $result['reference'] <= 0)
		{
			return null;
		}

		$valid_ids = array_map(function ($reply) {
			return (int) $reply['post_id'];
		}, $replies);

		// A provider must not be able to point at a post from another topic.
		if (!in_array((int) $result['reference'], $valid_ids, true))
		{
			return null;
		}

		return [
			'post_id'		=> (int) $result['reference'],
			'confidence'	=> (int) $result['confidence'],
			'reason'		=> 'AI',
		];
	}

	/**
	 * Whether any needle appears in the haystack.
	 *
	 * @param string   $haystack Lowercased text.
	 * @param string[] $needles  Phrases.
	 * @return bool
	 */
	protected function contains_any($haystack, array $needles)
	{
		foreach ($needles as $needle)
		{
			if (strpos($haystack, $needle) !== false)
			{
				return true;
			}
		}

		return false;
	}
}
