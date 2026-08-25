<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\integrations\bridge;

use Symfony\Component\DependencyInjection\ContainerInterface;
use salvocortesiano\forumhealth\repository\post_repository;
use salvocortesiano\forumhealth\service\integrations\meilisearch\provider_interface;
use salvocortesiano\forumhealth\service\logger;

/**
 * Bridge to salvocortesiano/meilisearch 1.6.
 *
 * Written against that extension's actual source rather than an assumed API.
 * Everything specific to it is in this one file, so if its interface changes the
 * failure is here and visible rather than somewhere inside Forum Health.
 *
 * Two things about that extension shape this class.
 *
 * Its index sets `displayedAttributes` to `['post_id']`, so a search returns post
 * ids and nothing else. Asking for `topic_id` back returns nothing even though
 * the field is filterable, because a field has to be displayable too. The
 * post-to-topic mapping is therefore done in SQL afterwards.
 *
 * Its client returns `false` on any failure rather than throwing, and exposes
 * the reason through `last_error()`. So every call here checks for `false`
 * explicitly; there is no exception to catch on the normal failure path.
 */
class meilisearch_bridge implements provider_interface
{
	/** Service id of the indexer in salvocortesiano/meilisearch. */
	const INDEXER_SERVICE = 'salvocortesiano.meilisearch.indexer';

	/** Service id of the HTTP client in salvocortesiano/meilisearch. */
	const CLIENT_SERVICE = 'salvocortesiano.meilisearch.client';

	/** @var ContainerInterface */
	protected $container;

	/** @var post_repository */
	protected $posts;

	/** @var logger */
	protected $logger;

	/** @var object|null Resolved indexer. */
	protected $indexer;

	/** @var object|null Resolved client. */
	protected $client;

	/** @var bool Whether resolution has been attempted this request. */
	protected $booted = false;

	/**
	 * @param ContainerInterface $container Service container.
	 * @param post_repository    $posts     Post repository, for id mapping.
	 * @param logger             $logger    Logger.
	 */
	public function __construct(ContainerInterface $container, post_repository $posts, logger $logger)
	{
		$this->container = $container;
		$this->posts = $posts;
		$this->logger = $logger;
	}

	/**
	 * Resolve the other extension's services, if it is present at all.
	 *
	 * This is deliberately done at runtime through the container rather than as
	 * a constructor argument in services.yml. A `@salvocortesiano.meilisearch.indexer`
	 * argument would be a compile-time reference, and on a board where that
	 * extension is not installed the container would fail to build — taking the
	 * whole forum down, not just this feature.
	 *
	 * @return bool Whether both services were found.
	 */
	protected function boot()
	{
		if ($this->booted)
		{
			return $this->indexer !== null && $this->client !== null;
		}

		$this->booted = true;

		try
		{
			if (!$this->container->has(self::INDEXER_SERVICE) || !$this->container->has(self::CLIENT_SERVICE))
			{
				return false;
			}

			$this->indexer = $this->container->get(self::INDEXER_SERVICE);
			$this->client = $this->container->get(self::CLIENT_SERVICE);
		}
		catch (\Throwable $e)
		{
			$this->indexer = null;
			$this->client = null;

			return false;
		}

		// Guard against a same-named service belonging to something else.
		if (!method_exists($this->indexer, 'get_index_uid') || !method_exists($this->client, 'search'))
		{
			$this->indexer = null;
			$this->client = null;

			return false;
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_operational()
	{
		if (!$this->boot())
		{
			return false;
		}

		// is_configured() only checks that a URL was entered. It is cheap and
		// runs on every call; health() is a network round trip and is not.
		return (bool) $this->client->is_configured();
	}

	/**
	 * {@inheritdoc}
	 */
	public function describe()
	{
		if (!$this->boot())
		{
			return '';
		}

		return 'Meilisearch (' . $this->indexer->get_index_uid() . ')';
	}

	/**
	 * {@inheritdoc}
	 */
	public function find_similar_topics($text, $limit, $exclude = 0, $forum_id = 0)
	{
		if (!$this->is_operational())
		{
			return [];
		}

		$text = trim((string) $text);
		$limit = max(1, min(50, (int) $limit));

		if ($text === '')
		{
			return [];
		}

		$filters = [
			// Only opening posts. Forum Health compares discussions, and a
			// matching reply half way down an unrelated topic is not a
			// duplicate of anything.
			'is_first_post = 1',
			// Approved posts only. post_visibility 1 is ITEM_APPROVED.
			'post_visibility = 1',
		];

		if ((int) $forum_id > 0)
		{
			$filters[] = 'forum_id = ' . (int) $forum_id;
		}

		$excluded = $this->excluded_forums();

		if (!empty($excluded))
		{
			$filters[] = 'forum_id NOT IN [' . implode(', ', $excluded) . ']';
		}

		$payload = [
			'q'						=> $text,
			// Ask for more than needed: the excluded topic and posts whose rows
			// have since been deleted both thin the list out.
			'limit'					=> $limit + 5,
			'attributesToRetrieve'	=> ['post_id'],
			'attributesToSearchOn'	=> ['post_subject', 'post_text'],
			'filter'				=> implode(' AND ', $filters),
			// Gives a real relevance figure instead of guessing one from rank.
			// Meilisearch adds it to each hit independently of
			// displayedAttributes.
			'showRankingScore'		=> true,
		];

		$locales = $this->locales();

		if (!empty($locales))
		{
			$payload['locales'] = $locales;
		}

		$response = $this->query($payload);

		if ($response === false)
		{
			return [];
		}

		return $this->to_topics($response, $limit, (int) $exclude);
	}

	/**
	 * Run the query, retrying once without the options older servers reject.
	 *
	 * `locales` needs Meilisearch 1.10 and `showRankingScore` an older but still
	 * non-ancient build. Both come back as a flat failure rather than a
	 * distinguishable error, so the retry drops them together and accepts a
	 * slightly worse result rather than no result.
	 *
	 * @param array $payload Search payload.
	 * @return array|false
	 */
	protected function query(array $payload)
	{
		$index = $this->indexer->get_index_uid();
		$response = $this->client->search($index, $payload);

		if ($response !== false)
		{
			return $response;
		}

		if (isset($payload['locales']) || isset($payload['showRankingScore']))
		{
			unset($payload['locales'], $payload['showRankingScore']);
			$response = $this->client->search($index, $payload);

			if ($response !== false)
			{
				return $response;
			}
		}

		$error = (string) $this->client->last_error();

		// The client puts Meilisearch's own error code in the message, which
		// lets one very specific and very confusing case be named properly: an
		// index whose settings were never applied rejects the filter on
		// is_first_post, and the resulting silence looks exactly like a server
		// that is down. The advice for the two is completely different.
		if ($this->is_index_configuration_error($error))
		{
			$this->logger->debug('FH_LOG_SEARCH_INDEX_SETTINGS', [$error]);
		}
		else
		{
			$this->logger->debug('FH_LOG_SEARCH_FAILURE', [$error]);
		}

		return false;
	}

	/**
	 * Whether a failure means the index is misconfigured rather than absent.
	 *
	 * Deliberately does not retry without the filter. Dropping `is_first_post`
	 * would make the search match replies, and a reply half way down an
	 * unrelated topic proposed as a duplicate is worse than no proposal at all.
	 *
	 * @param string $error Message from the client.
	 * @return bool
	 */
	protected function is_index_configuration_error($error)
	{
		foreach (['invalid_search_filter', 'not_filterable', 'not filterable', 'attributes_to_search_on', 'index_not_found'] as $needle)
		{
			if (stripos($error, $needle) !== false)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Turn a Meilisearch response into scored topic ids.
	 *
	 * @param array $response Decoded response.
	 * @param int   $limit    Maximum results.
	 * @param int   $exclude  Topic id to leave out.
	 * @return array[] Rows of topic_id and score.
	 */
	protected function to_topics(array $response, $limit, $exclude)
	{
		if (empty($response['hits']) || !is_array($response['hits']))
		{
			return [];
		}

		$post_ids = [];
		$scores = [];
		$rank = 0;

		foreach ($response['hits'] as $hit)
		{
			if (!isset($hit['post_id']))
			{
				continue;
			}

			$post_id = (int) $hit['post_id'];
			$post_ids[] = $post_id;

			if (isset($hit['_rankingScore']))
			{
				// Meilisearch reports 0..1; the interface asks for 0-100.
				$scores[$post_id] = (int) round(max(0.0, min(1.0, (float) $hit['_rankingScore'])) * 100);
			}
			else
			{
				// No ranking score available, so derive one from position. It
				// is a weaker signal and is scored as such: the top hit gets 90
				// rather than 100, because rank order alone never justifies
				// full confidence.
				$scores[$post_id] = (int) max(30, 90 - ($rank * 5));
			}

			$rank++;
		}

		$map = $this->posts->topic_ids_for_posts($post_ids);
		$out = [];
		$seen = [];

		foreach ($map as $post_id => $topic_id)
		{
			if ($topic_id === $exclude || isset($seen[$topic_id]))
			{
				continue;
			}

			$seen[$topic_id] = true;

			$out[] = [
				'topic_id'	=> $topic_id,
				'score'		=> $scores[$post_id],
			];

			if (count($out) >= $limit)
			{
				break;
			}
		}

		return $out;
	}

	/**
	 * Forums the search extension has been told to leave out.
	 *
	 * Honoured so the two extensions cannot disagree about what is searchable.
	 * Forum Health applies its own exclusions separately; this is in addition.
	 *
	 * @return int[]
	 */
	protected function excluded_forums()
	{
		if (!method_exists($this->indexer, 'get_excluded_forum_ids'))
		{
			return [];
		}

		try
		{
			return array_map('intval', (array) $this->indexer->get_excluded_forum_ids());
		}
		catch (\Throwable $e)
		{
			return [];
		}
	}

	/**
	 * Locale hints configured in the search extension.
	 *
	 * @return string[]
	 */
	protected function locales()
	{
		if (!method_exists($this->indexer, 'get_locales'))
		{
			return [];
		}

		try
		{
			return (array) $this->indexer->get_locales();
		}
		catch (\Throwable $e)
		{
			return [];
		}
	}
}
