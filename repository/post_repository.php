<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\repository;

use phpbb\db\driver\driver_interface;

/**
 * Reads post content for analysis.
 *
 * Only approved posts in public forums are ever read, and private messages are
 * not touched by any query in this class. Post text is the largest data this
 * extension handles, so every method here is bounded and none of them returns
 * whole topics unless a caller asks for a specific, small one.
 */
class post_repository
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $posts_table;

	/** @var string */
	protected $topics_table;

	/**
	 * @param driver_interface $db           Database driver.
	 * @param string           $posts_table  phpBB posts table.
	 * @param string           $topics_table phpBB topics table.
	 */
	public function __construct(driver_interface $db, $posts_table, $topics_table)
	{
		$this->db = $db;
		$this->posts_table = $posts_table;
		$this->topics_table = $topics_table;
	}

	/**
	 * A batch of posts with an id greater than the cursor.
	 *
	 * Used by the link scanner's discovery pass.
	 *
	 * @param int   $after           Cursor.
	 * @param int   $limit           Batch size.
	 * @param int[] $excluded_forums Forums to skip.
	 * @return array[]
	 */
	public function fetch_batch($after, $limit, array $excluded_forums = [])
	{
		$where = 'p.post_id > ' . (int) $after . '
			AND p.post_visibility = ' . ITEM_APPROVED;

		if (!empty($excluded_forums))
		{
			$where .= ' AND ' . $this->db->sql_in_set('p.forum_id', array_map('intval', $excluded_forums), true);
		}

		$sql = 'SELECT p.post_id, p.topic_id, p.forum_id, p.post_text, p.post_time
			FROM ' . $this->posts_table . ' p
			WHERE ' . $where . '
			ORDER BY p.post_id ASC';
		$result = $this->db->sql_query_limit($sql, (int) $limit);
		$rows = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}

		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Highest post id present.
	 *
	 * @return int
	 */
	public function max_post_id()
	{
		$result = $this->db->sql_query('SELECT MAX(post_id) AS max_id FROM ' . $this->posts_table);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (int) ($row['max_id'] ?? 0);
	}

	/**
	 * The replies of a topic, oldest first.
	 *
	 * @param int $topic_id Topic id.
	 * @param int $limit    Maximum replies to read.
	 * @return array[] Rows of post_id, poster_id, post_text, post_time.
	 */
	public function replies($topic_id, $limit = 50)
	{
		$sql = 'SELECT p.post_id, p.poster_id, p.post_text, p.post_time, t.topic_poster
			FROM ' . $this->posts_table . ' p
			INNER JOIN ' . $this->topics_table . ' t ON (t.topic_id = p.topic_id)
			WHERE p.topic_id = ' . (int) $topic_id . '
				AND p.post_visibility = ' . ITEM_APPROVED . '
				AND p.post_id <> t.topic_first_post_id
			ORDER BY p.post_time ASC';
		$result = $this->db->sql_query_limit($sql, (int) $limit);
		$rows = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}

		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * The opening post of a topic.
	 *
	 * @param int $topic_id Topic id.
	 * @return array|null
	 */
	public function first_post($topic_id)
	{
		$sql = 'SELECT p.post_id, p.poster_id, p.post_text, p.post_time
			FROM ' . $this->posts_table . ' p
			INNER JOIN ' . $this->topics_table . ' t ON (t.topic_first_post_id = p.post_id)
			WHERE t.topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: null;
	}

	/**
	 * Opening posts of several topics at once.
	 *
	 * @param int[] $topic_ids Topic ids.
	 * @return array<int, array> topic_id => post row.
	 */
	public function first_posts(array $topic_ids)
	{
		if (empty($topic_ids))
		{
			return [];
		}

		$sql = 'SELECT t.topic_id, p.post_id, p.post_text, p.post_time
			FROM ' . $this->topics_table . ' t
			INNER JOIN ' . $this->posts_table . ' p ON (p.post_id = t.topic_first_post_id)
			WHERE ' . $this->db->sql_in_set('t.topic_id', array_map('intval', $topic_ids)) . '
				AND p.post_visibility = ' . ITEM_APPROVED;
		$result = $this->db->sql_query($sql);
		$out = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$out[(int) $row['topic_id']] = $row;
		}

		$this->db->sql_freeresult($result);

		return $out;
	}

	/**
	 * Map post ids onto the topics that contain them.
	 *
	 * The Meilisearch index restricts displayedAttributes to post_id, so a
	 * search returns post ids and nothing else — asking it for topic_id back
	 * returns nothing, because a field must be displayable as well as
	 * filterable. The mapping therefore has to happen here, in SQL.
	 *
	 * Input order is preserved, because that order is the search engine's
	 * relevance ranking and discarding it would throw away the entire value of
	 * having asked.
	 *
	 * @param int[] $post_ids Post ids in relevance order.
	 * @return array<int, int> Topic id keyed by post id, ordered as given.
	 */
	public function topic_ids_for_posts(array $post_ids)
	{
		$post_ids = array_values(array_unique(array_map('intval', $post_ids)));

		if (empty($post_ids))
		{
			return [];
		}

		// A hard ceiling: this is fed from an external search response, and a
		// misconfigured index could return a great many hits.
		$post_ids = array_slice($post_ids, 0, 500);

		$sql = 'SELECT post_id, topic_id
			FROM ' . $this->posts_table . '
			WHERE ' . $this->db->sql_in_set('post_id', $post_ids);

		$result = $this->db->sql_query($sql);
		$found = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$found[(int) $row['post_id']] = (int) $row['topic_id'];
		}

		$this->db->sql_freeresult($result);

		$ordered = [];

		foreach ($post_ids as $post_id)
		{
			if (isset($found[$post_id]))
			{
				$ordered[$post_id] = $found[$post_id];
			}
		}

		return $ordered;
	}

	/**
	 * Strip bbcode, uid markers, quotes and urls down to readable text.
	 *
	 * Quoted passages are removed on purpose: a reply that quotes the question
	 * back would otherwise look exactly like the question when compared.
	 *
	 * @param string $text  Raw post_text as stored by phpBB.
	 * @param int    $limit Maximum characters to return.
	 * @return string Plain text.
	 */
	public function to_plain_text($text, $limit = 2000)
	{
		$text = (string) $text;

		// Drop quoted blocks including their content.
		$text = preg_replace('#\[quote(?:=[^\]]*)?(?::[a-z0-9]+)?\].*?\[/quote(?::[a-z0-9]+)?\]#is', ' ', $text);

		// Drop code blocks: they are rarely useful signal and often huge.
		$text = preg_replace('#\[code(?::[a-z0-9]+)?\].*?\[/code(?::[a-z0-9]+)?\]#is', ' ', (string) $text);

		// Remaining bbcode tags, including phpBB's uid suffixes.
		$text = preg_replace('#\[/?[a-z0-9\*]+(?:=[^\]]*)?(?::[a-z0-9]+)?\]#i', ' ', (string) $text);

		$text = str_replace(['&quot;', '&amp;', '&lt;', '&gt;', '&nbsp;'], ['"', '&', '<', '>', ' '], (string) $text);
		$text = preg_replace('/\s+/u', ' ', $text);

		return utf8_substr(trim((string) $text), 0, (int) $limit);
	}
}
