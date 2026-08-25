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
 * Aggregate community queries.
 *
 * Everything here returns counts and averages over time windows. No method
 * returns a per-user behavioural profile, and none of them reads private
 * messages: community health is measured at community level, which is both the
 * privacy-respecting choice and the one that answers the administrator's actual
 * question.
 *
 * The single exception is the contributor listing, which names users who reply
 * to others. That is public activity, already visible on every post, and it
 * exists so that helpful members can be recognised rather than ranked.
 */
class community_repository
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $users_table;

	/** @var string */
	protected $topics_table;

	/** @var string */
	protected $posts_table;

	/** @var string */
	protected $metrics_table;

	/**
	 * @param driver_interface $db            Database driver.
	 * @param string           $users_table   phpBB users table.
	 * @param string           $topics_table  phpBB topics table.
	 * @param string           $posts_table   phpBB posts table.
	 * @param string           $metrics_table Extension topic metrics table.
	 */
	public function __construct(driver_interface $db, $users_table, $topics_table, $posts_table, $metrics_table)
	{
		$this->db = $db;
		$this->users_table = $users_table;
		$this->topics_table = $topics_table;
		$this->posts_table = $posts_table;
		$this->metrics_table = $metrics_table;
	}

	/**
	 * Registrations in a time window.
	 *
	 * @param int $from Inclusive start timestamp.
	 * @param int $to   Exclusive end timestamp.
	 * @return int
	 */
	public function count_registrations($from, $to)
	{
		$sql = 'SELECT COUNT(*) AS num FROM ' . $this->users_table . '
			WHERE user_regdate >= ' . (int) $from . '
				AND user_regdate < ' . (int) $to . '
				AND user_type IN (' . USER_NORMAL . ', ' . USER_FOUNDER . ')';

		return $this->scalar($sql);
	}

	/**
	 * Distinct users who posted in a time window.
	 *
	 * @param int $from Inclusive start timestamp.
	 * @param int $to   Exclusive end timestamp.
	 * @return int
	 */
	public function count_active_posters($from, $to)
	{
		$sql = 'SELECT COUNT(DISTINCT poster_id) AS num FROM ' . $this->posts_table . '
			WHERE post_time >= ' . (int) $from . '
				AND post_time < ' . (int) $to . '
				AND post_visibility = ' . ITEM_APPROVED . '
				AND poster_id <> ' . ANONYMOUS;

		return $this->scalar($sql);
	}

	/**
	 * Topics created in a time window.
	 *
	 * @param int $from Inclusive start timestamp.
	 * @param int $to   Exclusive end timestamp.
	 * @return int
	 */
	public function count_topics($from, $to)
	{
		$sql = 'SELECT COUNT(*) AS num FROM ' . $this->topics_table . '
			WHERE topic_time >= ' . (int) $from . '
				AND topic_time < ' . (int) $to . '
				AND topic_visibility = ' . ITEM_APPROVED . '
				AND topic_moved_id = 0';

		return $this->scalar($sql);
	}

	/**
	 * Posts made in a time window.
	 *
	 * @param int $from Inclusive start timestamp.
	 * @param int $to   Exclusive end timestamp.
	 * @return int
	 */
	public function count_posts($from, $to)
	{
		$sql = 'SELECT COUNT(*) AS num FROM ' . $this->posts_table . '
			WHERE post_time >= ' . (int) $from . '
				AND post_time < ' . (int) $to . '
				AND post_visibility = ' . ITEM_APPROVED;

		return $this->scalar($sql);
	}

	/**
	 * First-post experience for topics started in a window.
	 *
	 * Reports, for topics that were their author's first: how many received a
	 * reply, how many did not, and how long the reply took on average.
	 *
	 * @param int $from         Inclusive start timestamp.
	 * @param int $to           Exclusive end timestamp.
	 * @param int $within_hours Reply window that counts as "answered".
	 * @return array{total:int,answered:int,unanswered:int,avg_seconds:int}
	 */
	public function first_post_experience($from, $to, $within_hours)
	{
		$cutoff = (int) $within_hours * 3600;

		$sql = 'SELECT COUNT(*) AS total,
				SUM(CASE WHEN first_reply_time > 0 AND (first_reply_time - topic_time) <= ' . $cutoff . ' THEN 1 ELSE 0 END) AS answered,
				SUM(CASE WHEN first_reply_time > 0 AND (first_reply_time - topic_time) <= ' . $cutoff . '
					THEN (first_reply_time - topic_time) ELSE 0 END) AS reply_seconds
			FROM ' . $this->metrics_table . '
			WHERE is_first_topic = 1
				AND topic_time >= ' . (int) $from . '
				AND topic_time < ' . (int) $to;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$total = (int) ($row['total'] ?? 0);
		$answered = (int) ($row['answered'] ?? 0);
		$seconds = (int) ($row['reply_seconds'] ?? 0);

		return [
			'total'			=> $total,
			'answered'		=> $answered,
			'unanswered'	=> max(0, $total - $answered),
			'avg_seconds'	=> $answered > 0 ? (int) round($seconds / $answered) : 0,
		];
	}

	/**
	 * First topics from the window that are still without any reply.
	 *
	 * @param int $from  Inclusive start timestamp.
	 * @param int $to    Exclusive end timestamp.
	 * @param int $limit Maximum rows.
	 * @return array[]
	 */
	public function unanswered_first_topics($from, $to, $limit = 50)
	{
		$sql = 'SELECT m.topic_id, m.topic_time, m.topic_views, t.topic_title, m.forum_id
			FROM ' . $this->metrics_table . ' m
			INNER JOIN ' . $this->topics_table . ' t ON (t.topic_id = m.topic_id)
			WHERE m.is_first_topic = 1
				AND m.is_unanswered = 1
				AND m.topic_time >= ' . (int) $from . '
				AND m.topic_time < ' . (int) $to . '
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
	 * Share of members who registered in a window and posted again later.
	 *
	 * Deliberately a ratio, not a list: the question is whether onboarding works,
	 * not which individuals came back.
	 *
	 * @param int $from       Inclusive start timestamp.
	 * @param int $to         Exclusive end timestamp.
	 * @param int $after_days Days after registration that count as "returned".
	 * @return array{cohort:int,returned:int}
	 */
	public function return_rate($from, $to, $after_days = 7)
	{
		$offset = (int) $after_days * 86400;

		$sql = 'SELECT COUNT(*) AS cohort,
				SUM(CASE WHEN u.user_lastpost_time >= (u.user_regdate + ' . $offset . ') THEN 1 ELSE 0 END) AS returned
			FROM ' . $this->users_table . ' u
			WHERE u.user_regdate >= ' . (int) $from . '
				AND u.user_regdate < ' . (int) $to . '
				AND u.user_type IN (' . USER_NORMAL . ', ' . USER_FOUNDER . ')
				AND u.user_posts > 0';
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return [
			'cohort'	=> (int) ($row['cohort'] ?? 0),
			'returned'	=> (int) ($row['returned'] ?? 0),
		];
	}

	/**
	 * Average seconds to first reply for topics created in a window.
	 *
	 * @param int $from Inclusive start timestamp.
	 * @param int $to   Exclusive end timestamp.
	 * @return int Zero when no topic in the window has a reply yet.
	 */
	public function average_response_seconds($from, $to)
	{
		$sql = 'SELECT AVG(first_reply_time - topic_time) AS avg_seconds
			FROM ' . $this->metrics_table . '
			WHERE first_reply_time > 0
				AND topic_time >= ' . (int) $from . '
				AND topic_time < ' . (int) $to;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (int) round((float) ($row['avg_seconds'] ?? 0));
	}

	/**
	 * Members who answer other people's discussions.
	 *
	 * Counts replies written in topics the member did not start, which is the
	 * observable behaviour behind the word "helper". No inference is made about
	 * the person beyond that count.
	 *
	 * @param int $from  Inclusive start timestamp.
	 * @param int $to    Exclusive end timestamp.
	 * @param int $limit Maximum rows.
	 * @return array[] Rows of poster_id, username, replies, topics_touched.
	 */
	public function top_responders($from, $to, $limit = 20)
	{
		$sql = 'SELECT p.poster_id, u.username, u.user_colour,
					COUNT(*) AS replies, COUNT(DISTINCT p.topic_id) AS topics_touched
			FROM ' . $this->posts_table . ' p
			INNER JOIN ' . $this->topics_table . ' t ON (t.topic_id = p.topic_id)
			INNER JOIN ' . $this->users_table . ' u ON (u.user_id = p.poster_id)
			WHERE p.post_time >= ' . (int) $from . '
				AND p.post_time < ' . (int) $to . '
				AND p.post_visibility = ' . ITEM_APPROVED . '
				AND p.poster_id <> ' . ANONYMOUS . '
				AND p.post_id <> t.topic_first_post_id
				AND t.topic_poster <> p.poster_id
			GROUP BY p.poster_id, u.username, u.user_colour
			ORDER BY replies DESC';
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
	 * Replies written to topics started by members registered recently.
	 *
	 * Identifies who is welcoming newcomers, which is the signal an administrator
	 * needs when onboarding metrics decline.
	 *
	 * @param int $from             Inclusive start timestamp.
	 * @param int $to               Exclusive end timestamp.
	 * @param int $newcomer_seconds How recently the topic author registered.
	 * @param int $limit            Maximum rows.
	 * @return array[]
	 */
	public function newcomer_helpers($from, $to, $newcomer_seconds, $limit = 10)
	{
		$sql = 'SELECT p.poster_id, u.username, u.user_colour, COUNT(*) AS replies
			FROM ' . $this->posts_table . ' p
			INNER JOIN ' . $this->topics_table . ' t ON (t.topic_id = p.topic_id)
			INNER JOIN ' . $this->users_table . ' author ON (author.user_id = t.topic_poster)
			INNER JOIN ' . $this->users_table . ' u ON (u.user_id = p.poster_id)
			WHERE p.post_time >= ' . (int) $from . '
				AND p.post_time < ' . (int) $to . '
				AND p.post_visibility = ' . ITEM_APPROVED . '
				AND p.poster_id <> ' . ANONYMOUS . '
				AND p.post_id <> t.topic_first_post_id
				AND t.topic_poster <> p.poster_id
				AND (t.topic_time - author.user_regdate) <= ' . (int) $newcomer_seconds . '
			GROUP BY p.poster_id, u.username, u.user_colour
			ORDER BY replies DESC';
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
	 * Run a query returning a single count column.
	 *
	 * @param string $sql Prepared SQL built from cast integers only.
	 * @return int
	 */
	protected function scalar($sql)
	{
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (int) ($row['num'] ?? 0);
	}
}
