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
 * All topic-level reads and writes.
 *
 * Every method here is written for forums with a million topics: queries are
 * bounded by an indexed cursor or an explicit limit, results are streamed row by
 * row, and no method ever loads an unbounded set into memory.
 */
class topic_repository
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $metrics_table;

	/** @var string */
	protected $topics_table;

	/** @var string */
	protected $posts_table;

	/** @var string */
	protected $users_table;

	/**
	 * @param driver_interface $db            Database driver.
	 * @param string           $metrics_table Extension topic metrics table.
	 * @param string           $topics_table  phpBB topics table.
	 * @param string           $posts_table   phpBB posts table.
	 * @param string           $users_table   phpBB users table.
	 */
	public function __construct(driver_interface $db, $metrics_table, $topics_table, $posts_table, $users_table)
	{
		$this->db = $db;
		$this->metrics_table = $metrics_table;
		$this->topics_table = $topics_table;
		$this->posts_table = $posts_table;
		$this->users_table = $users_table;
	}

	/**
	 * Fetch a batch of visible topics with an id greater than the cursor.
	 *
	 * Ordering by primary key makes the scan resumable and index-only.
	 *
	 * @param int   $after            Cursor: last processed topic id.
	 * @param int   $limit            Batch size.
	 * @param int[] $excluded_forums  Forum ids to skip.
	 * @return array[] Raw topic rows.
	 */
	public function fetch_batch($after, $limit, array $excluded_forums = [])
	{
		$sql_array = [
			'SELECT'	=> 't.topic_id, t.forum_id, t.topic_poster, t.topic_title, t.topic_time,
							t.topic_last_post_time, t.topic_posts_approved, t.topic_views, t.topic_status,
							t.topic_first_post_id, t.topic_last_post_id',
			'FROM'		=> [$this->topics_table => 't'],
			'WHERE'		=> 't.topic_id > ' . (int) $after . '
							AND t.topic_visibility = ' . ITEM_APPROVED . '
							AND t.topic_moved_id = 0',
			'ORDER_BY'	=> 't.topic_id ASC',
		];

		if (!empty($excluded_forums))
		{
			$sql_array['WHERE'] .= ' AND ' . $this->db->sql_in_set('t.forum_id', array_map('intval', $excluded_forums), true);
		}

		$sql = $this->db->sql_build_query('SELECT', $sql_array);
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
	 * Highest topic id currently present.
	 *
	 * Used to decide when an incremental scan has reached the end and should wrap.
	 *
	 * @return int
	 */
	public function max_topic_id()
	{
		$result = $this->db->sql_query('SELECT MAX(topic_id) AS max_id FROM ' . $this->topics_table);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (int) ($row['max_id'] ?? 0);
	}

	/**
	 * Time of the first reply for each of the given topics.
	 *
	 * A reply is any approved post that is not the topic's first post.
	 *
	 * @param int[] $topic_ids Topic ids.
	 * @return array<int, int> topic_id => timestamp of first reply.
	 */
	public function first_reply_times(array $topic_ids)
	{
		if (empty($topic_ids))
		{
			return [];
		}

		$sql = 'SELECT p.topic_id, MIN(p.post_time) AS first_reply
			FROM ' . $this->posts_table . ' p
			INNER JOIN ' . $this->topics_table . ' t ON (t.topic_id = p.topic_id)
			WHERE ' . $this->db->sql_in_set('p.topic_id', array_map('intval', $topic_ids)) . '
				AND p.post_visibility = ' . ITEM_APPROVED . '
				AND p.post_id <> t.topic_first_post_id
			GROUP BY p.topic_id';
		$result = $this->db->sql_query($sql);
		$out = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$out[(int) $row['topic_id']] = (int) $row['first_reply'];
		}

		$this->db->sql_freeresult($result);

		return $out;
	}

	/**
	 * Determine which of the given topics are their author's first topic.
	 *
	 * Answers the onboarding question "was this person's very first discussion
	 * ever answered?" without profiling anybody: only the topic id is retained.
	 *
	 * @param array<int, int> $topic_poster topic_id => poster user id.
	 * @return array<int, bool> topic_id => true when it is the author's first.
	 */
	public function flag_first_topics(array $topic_poster)
	{
		if (empty($topic_poster))
		{
			return [];
		}

		$posters = array_values(array_unique(array_map('intval', $topic_poster)));
		$posters = array_filter($posters, function ($id) {
			return $id > 0;
		});

		if (empty($posters))
		{
			return [];
		}

		$sql = 'SELECT topic_poster, MIN(topic_id) AS first_topic
			FROM ' . $this->topics_table . '
			WHERE ' . $this->db->sql_in_set('topic_poster', $posters) . '
				AND topic_visibility = ' . ITEM_APPROVED . '
			GROUP BY topic_poster';
		$result = $this->db->sql_query($sql);
		$first_by_user = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$first_by_user[(int) $row['topic_poster']] = (int) $row['first_topic'];
		}

		$this->db->sql_freeresult($result);

		$out = [];

		foreach ($topic_poster as $topic_id => $poster_id)
		{
			$poster_id = (int) $poster_id;
			$out[(int) $topic_id] = isset($first_by_user[$poster_id]) && $first_by_user[$poster_id] === (int) $topic_id;
		}

		return $out;
	}

	/**
	 * Insert or update a batch of topic metric rows.
	 *
	 * Existing rows are updated rather than deleted so that analysis produced by
	 * earlier runs (solution confidence, freshness) survives a re-scan.
	 *
	 * @param array[] $rows Metric rows keyed by column name.
	 * @return int Number of rows written.
	 */
	public function store_metrics(array $rows)
	{
		if (empty($rows))
		{
			return 0;
		}

		$ids = array_map(function ($row) {
			return (int) $row['topic_id'];
		}, $rows);

		$sql = 'SELECT topic_id FROM ' . $this->metrics_table . '
			WHERE ' . $this->db->sql_in_set('topic_id', $ids);
		$result = $this->db->sql_query($sql);
		$existing = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$existing[(int) $row['topic_id']] = true;
		}

		$this->db->sql_freeresult($result);

		$insert = [];

		foreach ($rows as $row)
		{
			if (isset($existing[(int) $row['topic_id']]))
			{
				$topic_id = (int) $row['topic_id'];
				unset($row['topic_id']);

				$this->db->sql_query('UPDATE ' . $this->metrics_table . '
					SET ' . $this->db->sql_build_array('UPDATE', $row) . '
					WHERE topic_id = ' . $topic_id);
			}
			else
			{
				$insert[] = $row;
			}
		}

		if (!empty($insert))
		{
			$this->db->sql_multi_insert($this->metrics_table, $insert);
		}

		return count($rows);
	}

	/**
	 * Remove metric rows whose topic no longer exists.
	 *
	 * @param int $limit Maximum rows to remove in one pass.
	 * @return int Rows removed.
	 */
	public function prune_orphans($limit = 500)
	{
		$sql = 'SELECT m.topic_id
			FROM ' . $this->metrics_table . ' m
			LEFT JOIN ' . $this->topics_table . ' t ON (t.topic_id = m.topic_id)
			WHERE t.topic_id IS NULL';
		$result = $this->db->sql_query_limit($sql, (int) $limit);
		$ids = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$ids[] = (int) $row['topic_id'];
		}

		$this->db->sql_freeresult($result);

		if (empty($ids))
		{
			return 0;
		}

		$this->db->sql_query('DELETE FROM ' . $this->metrics_table . '
			WHERE ' . $this->db->sql_in_set('topic_id', $ids));

		return count($ids);
	}

	/**
	 * Unanswered topics ordered by administrative priority.
	 *
	 * Priority is views first: an unanswered discussion nobody reads matters far
	 * less than an unanswered discussion many people have opened.
	 *
	 * @param int $min_views Minimum view count.
	 * @param int $older_than Only topics created before this timestamp.
	 * @param int $newer_than Only topics created after this timestamp.
	 * @param int $start      Offset for pagination.
	 * @param int $limit      Page size.
	 * @param int $forum_id   Optional forum filter, 0 for all.
	 * @return array[] Rows including the topic title.
	 */
	public function unanswered($min_views, $older_than, $newer_than, $start, $limit, $forum_id = 0)
	{
		$where = 'm.is_unanswered = 1
			AND m.topic_views >= ' . (int) $min_views . '
			AND m.topic_time <= ' . (int) $older_than . '
			AND m.topic_time >= ' . (int) $newer_than;

		if ($forum_id > 0)
		{
			$where .= ' AND m.forum_id = ' . (int) $forum_id;
		}

		$sql = 'SELECT m.topic_id, m.forum_id, m.topic_views, m.topic_time, m.topic_poster,
					m.is_first_topic, t.topic_title
			FROM ' . $this->metrics_table . ' m
			INNER JOIN ' . $this->topics_table . ' t ON (t.topic_id = m.topic_id)
			WHERE ' . $where . '
			ORDER BY m.topic_views DESC, m.topic_time ASC';
		$result = $this->db->sql_query_limit($sql, (int) $limit, (int) $start);
		$rows = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}

		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Count unanswered topics matching the priority thresholds.
	 *
	 * @param int $min_views  Minimum view count.
	 * @param int $older_than Only topics created before this timestamp.
	 * @param int $newer_than Only topics created after this timestamp.
	 * @param int $forum_id   Optional forum filter, 0 for all.
	 * @return int
	 */
	public function count_unanswered($min_views, $older_than, $newer_than, $forum_id = 0)
	{
		$where = 'is_unanswered = 1
			AND topic_views >= ' . (int) $min_views . '
			AND topic_time <= ' . (int) $older_than . '
			AND topic_time >= ' . (int) $newer_than;

		if ($forum_id > 0)
		{
			$where .= ' AND forum_id = ' . (int) $forum_id;
		}

		return $this->count_where($where);
	}

	/**
	 * Duplicate candidates: stored metric rows sharing at least one token.
	 *
	 * The SQL narrows the field cheaply (same forum window, recent enough, at
	 * least one token in common); the precise score is then computed in PHP by
	 * the normaliser. This keeps an expensive comparison off the database.
	 *
	 * @param array  $topic     Metric row of the topic being examined.
	 * @param string[] $tokens  Its tokens.
	 * @param int    $window    Oldest topic timestamp to consider.
	 * @param int    $limit     Maximum candidates.
	 * @return array[] Candidate rows.
	 */
	public function duplicate_candidates(array $topic, array $tokens, $window, $limit = 60)
	{
		if (empty($tokens))
		{
			return [];
		}

		// Compare against the rarest-looking tokens first: long tokens are far
		// more selective than short ones, which keeps the LIKE scan narrow.
		usort($tokens, function ($a, $b) {
			return utf8_strlen($b) <=> utf8_strlen($a);
		});

		$probe = array_slice($tokens, 0, 3);
		$conditions = [];

		foreach ($probe as $token)
		{
			$conditions[] = 'm.title_tokens ' . $this->db->sql_like_expression(
				$this->db->get_any_char() . $token . $this->db->get_any_char()
			);
		}

		$sql = 'SELECT m.topic_id, m.forum_id, m.title_tokens, m.title_normalised,
					m.topic_time, m.topic_replies, m.topic_views, t.topic_title
			FROM ' . $this->metrics_table . ' m
			INNER JOIN ' . $this->topics_table . ' t ON (t.topic_id = m.topic_id)
			WHERE m.topic_id <> ' . (int) $topic['topic_id'] . '
				AND m.topic_time >= ' . (int) $window . '
				AND (' . implode(' OR ', $conditions) . ')
			ORDER BY m.topic_time DESC';
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
	 * Metric rows for topics that may be outdated.
	 *
	 * @param int $before    Topics whose last post predates this timestamp.
	 * @param int $min_views Minimum views, so dormant unread topics are ignored.
	 * @param int $start     Offset.
	 * @param int $limit     Page size.
	 * @return array[]
	 */
	public function stale_topics($before, $min_views, $start, $limit)
	{
		$sql = 'SELECT m.topic_id, m.forum_id, m.topic_views, m.last_post_time,
					m.freshness_conf, m.freshness_reason, t.topic_title
			FROM ' . $this->metrics_table . ' m
			INNER JOIN ' . $this->topics_table . ' t ON (t.topic_id = m.topic_id)
			WHERE m.last_post_time <= ' . (int) $before . '
				AND m.topic_views >= ' . (int) $min_views . '
			ORDER BY m.freshness_conf DESC, m.topic_views DESC';
		$result = $this->db->sql_query_limit($sql, (int) $limit, (int) $start);
		$rows = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}

		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Topics with a detected solution candidate.
	 *
	 * @param int $min_confidence Minimum stored confidence.
	 * @param int $start          Offset.
	 * @param int $limit          Page size.
	 * @return array[]
	 */
	public function solution_candidates($min_confidence, $start, $limit)
	{
		$sql = 'SELECT m.topic_id, m.forum_id, m.solution_post_id, m.solution_conf, t.topic_title
			FROM ' . $this->metrics_table . ' m
			INNER JOIN ' . $this->topics_table . ' t ON (t.topic_id = m.topic_id)
			WHERE m.solution_conf >= ' . (int) $min_confidence . '
				AND m.solution_post_id > 0
			ORDER BY m.solution_conf DESC, m.topic_id DESC';
		$result = $this->db->sql_query_limit($sql, (int) $limit, (int) $start);
		$rows = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[] = $row;
		}

		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Aggregate counters used by the health score and dashboard.
	 *
	 * One grouped query rather than five counts.
	 *
	 * @return array{total:int,unanswered:int,solved:int,stale:int}
	 */
	public function summary_counts()
	{
		$sql = 'SELECT COUNT(*) AS total,
				SUM(CASE WHEN is_unanswered = 1 THEN 1 ELSE 0 END) AS unanswered,
				SUM(CASE WHEN solution_post_id > 0 THEN 1 ELSE 0 END) AS solved,
				SUM(CASE WHEN freshness_conf >= 60 THEN 1 ELSE 0 END) AS stale
			FROM ' . $this->metrics_table;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return [
			'total'			=> (int) ($row['total'] ?? 0),
			'unanswered'	=> (int) ($row['unanswered'] ?? 0),
			'solved'		=> (int) ($row['solved'] ?? 0),
			'stale'			=> (int) ($row['stale'] ?? 0),
		];
	}

	/**
	 * Store a detected solution candidate.
	 *
	 * @param int $topic_id   Topic id.
	 * @param int $post_id    Candidate post id.
	 * @param int $confidence Confidence 0-100.
	 * @return void
	 */
	public function set_solution($topic_id, $post_id, $confidence)
	{
		$this->db->sql_query('UPDATE ' . $this->metrics_table . '
			SET solution_post_id = ' . (int) $post_id . ',
				solution_conf = ' . (int) $confidence . '
			WHERE topic_id = ' . (int) $topic_id);
	}

	/**
	 * Store a freshness assessment.
	 *
	 * @param int    $topic_id   Topic id.
	 * @param int    $confidence Confidence 0-100.
	 * @param string $reason     Reason code, resolved to a language key on output.
	 * @return void
	 */
	public function set_freshness($topic_id, $confidence, $reason)
	{
		$this->db->sql_query('UPDATE ' . $this->metrics_table . "
			SET freshness_conf = " . (int) $confidence . ",
				freshness_reason = '" . $this->db->sql_escape((string) $reason) . "'
			WHERE topic_id = " . (int) $topic_id);
	}

	/**
	 * Fetch stored metric rows by topic id.
	 *
	 * @param int[] $topic_ids Topic ids.
	 * @return array<int, array> Keyed by topic id.
	 */
	public function get_metrics(array $topic_ids)
	{
		if (empty($topic_ids))
		{
			return [];
		}

		$sql = 'SELECT * FROM ' . $this->metrics_table . '
			WHERE ' . $this->db->sql_in_set('topic_id', array_map('intval', $topic_ids));
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
	 * Topics sharing a token, used to surface recurring questions.
	 *
	 * @param int $min_topics Minimum group size to qualify.
	 * @param int $limit      Maximum groups.
	 * @param int $since      Only consider topics created after this timestamp.
	 * @return array[] Rows of token => count.
	 */
	public function recurring_token_groups($min_topics, $limit, $since)
	{
		// Tokens are stored packed, so grouping happens in PHP over a bounded
		// window rather than with a database-specific split function.
		$sql = 'SELECT title_tokens
			FROM ' . $this->metrics_table . '
			WHERE topic_time >= ' . (int) $since . "
				AND title_tokens <> ''
			ORDER BY topic_time DESC";
		$result = $this->db->sql_query_limit($sql, 5000);
		$counts = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			foreach (explode(' ', $row['title_tokens']) as $token)
			{
				if ($token === '')
				{
					continue;
				}

				$counts[$token] = isset($counts[$token]) ? $counts[$token] + 1 : 1;
			}
		}

		$this->db->sql_freeresult($result);

		$counts = array_filter($counts, function ($count) use ($min_topics) {
			return $count >= (int) $min_topics;
		});

		arsort($counts);

		$out = [];

		foreach (array_slice($counts, 0, (int) $limit, true) as $token => $count)
		{
			$out[] = ['token' => $token, 'topics' => $count];
		}

		return $out;
	}

	/**
	 * Count rows in the metrics table matching a prepared condition.
	 *
	 * @param string $where Prepared SQL condition built from cast integers only.
	 * @return int
	 */
	protected function count_where($where)
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS num FROM ' . $this->metrics_table . ' WHERE ' . $where);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (int) ($row['num'] ?? 0);
	}
}
