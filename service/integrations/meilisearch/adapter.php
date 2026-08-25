<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\integrations\meilisearch;

use salvocortesiano\forumhealth\service\integrations\registry;

/**
 * The only place in the extension that talks to a search provider.
 *
 * Every method answers with a usable value whatever happens on the other side.
 * A missing provider, a provider that throws, a search server that has gone away
 * and a search that simply found nothing are all the same to the caller: an
 * empty array. The duplicate detector therefore never needs to know whether
 * Meilisearch exists, and native analysis continues unchanged.
 */
class adapter
{
	/** @var registry */
	protected $registry;

	/** @var bool Set once a call has failed, to stop hammering a dead service. */
	protected $failed_this_request = false;

	/**
	 * @param registry $registry Integration registry.
	 */
	public function __construct(registry $registry)
	{
		$this->registry = $registry;
	}

	/**
	 * Whether enhanced search is usable right now.
	 *
	 * @return bool
	 */
	public function is_available()
	{
		if ($this->failed_this_request)
		{
			return false;
		}

		return $this->registry->search_provider() !== null;
	}

	/**
	 * Candidate topics similar to the given text.
	 *
	 * @param string $text     Text to match, normally a topic title.
	 * @param int    $limit    Maximum candidates.
	 * @param int    $exclude  Topic id to exclude.
	 * @param int    $forum_id Restrict to one forum, 0 for all.
	 * @return array[] Rows of topic_id and score, empty when unavailable.
	 */
	public function find_similar_topics($text, $limit, $exclude = 0, $forum_id = 0)
	{
		$provider = $this->failed_this_request ? null : $this->registry->search_provider();

		if ($provider === null)
		{
			return [];
		}

		try
		{
			if (!$provider->is_operational())
			{
				$this->degrade('not operational');

				return [];
			}

			$rows = $provider->find_similar_topics((string) $text, (int) $limit, (int) $exclude, (int) $forum_id);

			if (!is_array($rows))
			{
				$this->degrade('malformed response');

				return [];
			}

			$this->registry->record_success('search');

			return $this->sanitise($rows, (int) $limit);
		}
		catch (\Throwable $e)
		{
			// The provider is third-party code. Whatever it throws stops here.
			$this->degrade(get_class($e));

			return [];
		}
	}

	/**
	 * Normalise provider output to the shape the rest of the code expects.
	 *
	 * A bridge is third-party code and may return anything; only well formed
	 * rows survive, and scores are clamped so a provider cannot inject a
	 * confidence of 500 into the interface.
	 *
	 * @param array[] $rows  Raw provider rows.
	 * @param int     $limit Maximum rows to keep.
	 * @return array[]
	 */
	protected function sanitise(array $rows, $limit)
	{
		$clean = [];

		foreach ($rows as $row)
		{
			if (!is_array($row) || empty($row['topic_id']))
			{
				continue;
			}

			$topic_id = (int) $row['topic_id'];

			if ($topic_id <= 0)
			{
				continue;
			}

			$clean[] = [
				'topic_id'	=> $topic_id,
				'score'		=> max(0, min(100, (int) ($row['score'] ?? 0))),
			];

			if (count($clean) >= $limit)
			{
				break;
			}
		}

		return $clean;
	}

	/**
	 * Mark the integration as failing for the rest of this request.
	 *
	 * @param string $reason Short technical reason for the log.
	 * @return void
	 */
	protected function degrade($reason)
	{
		$this->failed_this_request = true;
		$this->registry->record_failure('search', $reason);
	}
}
