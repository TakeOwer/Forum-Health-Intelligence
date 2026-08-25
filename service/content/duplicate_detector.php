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
use salvocortesiano\forumhealth\repository\relation_repository;
use salvocortesiano\forumhealth\repository\topic_repository;
use salvocortesiano\forumhealth\service\integrations\ai\adapter as ai_adapter;
use salvocortesiano\forumhealth\service\integrations\ai\provider_interface;
use salvocortesiano\forumhealth\service\integrations\meilisearch\adapter as search_adapter;
use salvocortesiano\forumhealth\service\settings;
use salvocortesiano\forumhealth\service\text\normaliser;

/**
 * Finds discussions that may already cover the same ground.
 *
 * Three layers, each optional above the first:
 *
 *   Level 1, native. Tokens from the title are compared against candidates the
 *   database can find cheaply. Always available, costs nothing external, and on
 *   its own catches the common case of the same question asked in near-identical
 *   words.
 *
 *   Level 2, search. When a search provider is bound it proposes candidates the
 *   token comparison would miss, because it understands the body of the topic
 *   and not just its title. Its candidates are still scored natively, so a
 *   search provider can widen the net but cannot inflate confidence on its own.
 *
 *   Level 3, AI. Consulted only for pairs that are already plausible but not
 *   certain, which is the narrow band where semantic judgement changes the
 *   answer. Sending every pair to a model would be slow, expensive and no more
 *   accurate.
 *
 * Nothing here merges, deletes or edits anything. It records candidate pairs for
 * a person to review.
 */
class duplicate_detector
{
	/** @var topic_repository */
	protected $topics;

	/** @var relation_repository */
	protected $relations;

	/** @var normaliser */
	protected $normaliser;

	/** @var settings */
	protected $settings;

	/** @var search_adapter */
	protected $search;

	/** @var ai_adapter */
	protected $ai;

	/**
	 * @param topic_repository    $topics     Topic repository.
	 * @param relation_repository $relations  Relation repository.
	 * @param normaliser          $normaliser Text normaliser.
	 * @param settings            $settings   Extension settings.
	 * @param search_adapter      $search     Optional search adapter.
	 * @param ai_adapter          $ai         Optional AI adapter.
	 */
	public function __construct(
		topic_repository $topics,
		relation_repository $relations,
		normaliser $normaliser,
		settings $settings,
		search_adapter $search,
		ai_adapter $ai
	)
	{
		$this->topics = $topics;
		$this->relations = $relations;
		$this->normaliser = $normaliser;
		$this->settings = $settings;
		$this->search = $search;
		$this->ai = $ai;
	}

	/**
	 * Examine a batch of topics for duplicates.
	 *
	 * @param array[] $rows Raw topic rows from the scan.
	 * @return int Number of new relations recorded.
	 */
	public function analyse_batch(array $rows)
	{
		if (!$this->settings->feature_enabled('duplicates'))
		{
			return 0;
		}

		$stored = 0;

		foreach ($rows as $row)
		{
			$stored += $this->analyse_topic(
				(int) $row['topic_id'],
				(string) $row['topic_title'],
				(int) $row['forum_id'],
				(int) $row['topic_time']
			);
		}

		return $stored;
	}

	/**
	 * Examine one topic.
	 *
	 * @param int    $topic_id   Topic id.
	 * @param string $title      Topic title.
	 * @param int    $forum_id   Forum id.
	 * @param int    $topic_time Creation timestamp.
	 * @return int Number of new relations recorded.
	 */
	public function analyse_topic($topic_id, $title, $forum_id, $topic_time)
	{
		$matches = $this->find_candidates($topic_id, $title, $forum_id);
		$stored = 0;

		foreach ($matches as $match)
		{
			$created = $this->relations->store(
				$topic_id,
				$match['topic_id'],
				$match['confidence'],
				$match['source'],
				$match['reasons']
			);

			if ($created)
			{
				$stored++;
			}
		}

		return $stored;
	}

	/**
	 * Score candidate duplicates for a title.
	 *
	 * This is also the entry point for the posting-time warning, which needs the
	 * same answer before a topic exists.
	 *
	 * @param int    $topic_id Topic id, 0 when the topic is not yet created.
	 * @param string $title    Title to match.
	 * @param int    $forum_id Forum the topic is in.
	 * @param int    $limit    Maximum matches to return.
	 * @return array[] Rows of topic_id, title, confidence, source, reasons.
	 */
	public function find_candidates($topic_id, $title, $forum_id, $limit = 5)
	{
		$tokens = $this->normaliser->tokenise($title);

		// A title with almost no distinctive words cannot be matched reliably,
		// and guessing from one token produces noise rather than findings.
		if (count($tokens) < 2)
		{
			return [];
		}

		$window = time() - ($this->settings->get_int('fh_duplicate_window_days') * 86400);
		$threshold = $this->settings->get_int('fh_duplicate_threshold');

		$candidates = $this->topics->duplicate_candidates(
			['topic_id' => (int) $topic_id],
			$tokens,
			$window
		);

		$scored = [];

		foreach ($candidates as $candidate)
		{
			$assessment = $this->score_pair($tokens, $forum_id, $candidate);

			if ($assessment['confidence'] >= $threshold)
			{
				$scored[(int) $candidate['topic_id']] = $assessment;
			}
		}

		$scored = $this->add_search_candidates($scored, $topic_id, $title, $forum_id, $tokens, $threshold);
		$scored = $this->refine_with_ai($scored, $topic_id, $title);

		uasort($scored, function ($a, $b) {
			return $b['confidence'] <=> $a['confidence'];
		});

		return array_slice(array_values($scored), 0, (int) $limit);
	}

	/**
	 * Score one candidate against the source tokens.
	 *
	 * @param string[] $tokens    Source tokens.
	 * @param int      $forum_id  Source forum id.
	 * @param array    $candidate Candidate metric row.
	 * @return array Assessment row.
	 */
	protected function score_pair(array $tokens, $forum_id, array $candidate)
	{
		$candidate_tokens = $this->normaliser->unpack_tokens($candidate['title_tokens']);
		$confidence = $this->normaliser->similarity($tokens, $candidate_tokens);
		$reasons = ['SIMILAR_TERMS'];

		if ((int) $candidate['forum_id'] === (int) $forum_id)
		{
			// The same question in the same forum is more likely to be a genuine
			// duplicate than the same words in an unrelated section.
			$confidence += $this->settings->get_int('fh_duplicate_same_forum_bonus');
			$reasons[] = 'SAME_FORUM';
		}

		$shared = $this->normaliser->shared_tokens($tokens, $candidate_tokens);

		if (count($shared) >= 3)
		{
			$reasons[] = 'SHARED_KEYWORDS';
		}

		return [
			'topic_id'		=> (int) $candidate['topic_id'],
			'title'			=> (string) $candidate['topic_title'],
			'confidence'	=> min(100, $confidence),
			'source'		=> constants::SOURCE_NATIVE,
			'reasons'		=> $reasons,
			'shared'		=> $shared,
		];
	}

	/**
	 * Add candidates proposed by the search provider.
	 *
	 * Their similarity is still scored natively; the provider contributes reach,
	 * not authority.
	 *
	 * @param array[]  $scored    Candidates found so far, keyed by topic id.
	 * @param int      $topic_id  Source topic id.
	 * @param string   $title     Source title.
	 * @param int      $forum_id  Source forum id.
	 * @param string[] $tokens    Source tokens.
	 * @param int      $threshold Minimum confidence.
	 * @return array[]
	 */
	protected function add_search_candidates(array $scored, $topic_id, $title, $forum_id, array $tokens, $threshold)
	{
		if (!$this->search->is_available())
		{
			return $scored;
		}

		$rows = $this->search->find_similar_topics($title, 10, (int) $topic_id, 0);

		if (empty($rows))
		{
			return $scored;
		}

		$new_ids = [];

		foreach ($rows as $row)
		{
			if (!isset($scored[(int) $row['topic_id']]))
			{
				$new_ids[] = (int) $row['topic_id'];
			}
		}

		if (empty($new_ids))
		{
			return $scored;
		}

		$metrics = $this->topics->get_metrics($new_ids);

		foreach ($rows as $row)
		{
			$candidate_id = (int) $row['topic_id'];

			if (isset($scored[$candidate_id]) || !isset($metrics[$candidate_id]))
			{
				continue;
			}

			$metric = $metrics[$candidate_id];
			$candidate_tokens = $this->normaliser->unpack_tokens($metric['title_tokens']);
			$native = $this->normaliser->similarity($tokens, $candidate_tokens);

			// Blend the two views: the provider saw the body, the token score saw
			// the title. Either alone is weaker than both agreeing.
			$confidence = (int) round(($native * 0.6) + ((int) $row['score'] * 0.4));

			if ((int) $metric['forum_id'] === (int) $forum_id)
			{
				$confidence += $this->settings->get_int('fh_duplicate_same_forum_bonus');
			}

			$confidence = min(100, $confidence);

			if ($confidence < $threshold)
			{
				continue;
			}

			$scored[$candidate_id] = [
				'topic_id'		=> $candidate_id,
				'title'			=> (string) $metric['title_normalised'],
				'confidence'	=> $confidence,
				'source'		=> constants::SOURCE_MEILISEARCH,
				'reasons'		=> ['SIMILAR_TERMS', 'SEARCH_MATCH'],
				'shared'		=> $this->normaliser->shared_tokens($tokens, $candidate_tokens),
			];
		}

		return $scored;
	}

	/**
	 * Ask AI about the genuinely uncertain pairs.
	 *
	 * Only pairs above the candidate floor and below high confidence are sent:
	 * below the floor the pair is noise, above it the answer is already clear.
	 *
	 * @param array[] $scored   Candidates keyed by topic id.
	 * @param int     $topic_id Source topic id.
	 * @param string  $title    Source title.
	 * @return array[]
	 */
	protected function refine_with_ai(array $scored, $topic_id, $title)
	{
		if (empty($scored) || !$this->ai->can(provider_interface::CAP_DUPLICATE))
		{
			return $scored;
		}

		$floor = $this->settings->get_int('fh_ai_min_candidate_conf');
		$ceiling = $this->settings->get_int('fh_duplicate_high_threshold');
		$examined = 0;

		foreach ($scored as $id => $candidate)
		{
			if ($examined >= 3)
			{
				// A cap per topic keeps a single odd title from consuming the
				// whole daily budget.
				break;
			}

			if ($candidate['confidence'] < $floor || $candidate['confidence'] >= $ceiling)
			{
				continue;
			}

			$hash = $this->normaliser->content_hash($title . '|' . $candidate['title']);

			$result = $this->ai->analyse(
				provider_interface::CAP_DUPLICATE,
				'topic_pair',
				(int) $topic_id,
				$hash,
				[
					'title_a' => (string) $title,
					'title_b' => (string) $candidate['title'],
				]
			);

			$examined++;

			if ($result === null)
			{
				continue;
			}

			$scored[$id]['confidence'] = (int) $result['confidence'];
			$scored[$id]['source'] = constants::SOURCE_AI;
			$scored[$id]['reasons'][] = 'AI_SEMANTIC';

			if ($result['summary'] !== '')
			{
				$scored[$id]['ai_summary'] = $result['summary'];
			}
		}

		return $scored;
	}
}
