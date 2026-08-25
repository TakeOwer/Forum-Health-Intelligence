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
use salvocortesiano\forumhealth\constants;

/**
 * Persistence for discovered URLs and their occurrences.
 *
 * URLs are stored once and checked once, however many posts reference them. On a
 * large forum this is the difference between tens of thousands of outbound
 * requests and a few hundred.
 */
class link_repository
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $links_table;

	/** @var string */
	protected $occurrences_table;

	/** @var string */
	protected $topics_table;

	/**
	 * @param driver_interface $db                Database driver.
	 * @param string           $links_table       Links table.
	 * @param string           $occurrences_table Occurrences table.
	 * @param string           $topics_table      phpBB topics table.
	 */
	public function __construct(driver_interface $db, $links_table, $occurrences_table, $topics_table)
	{
		$this->db = $db;
		$this->links_table = $links_table;
		$this->occurrences_table = $occurrences_table;
		$this->topics_table = $topics_table;
	}

	/**
	 * Register a URL and the post it was found in.
	 *
	 * @param string $url      Normalised absolute URL.
	 * @param string $host     Lowercase host.
	 * @param int    $post_id  Post id.
	 * @param int    $topic_id Topic id.
	 * @param int    $forum_id Forum id.
	 * @return int Link id.
	 */
	public function register($url, $host, $post_id, $topic_id, $forum_id)
	{
		$hash = sha1($url);
		$link_id = $this->find_id_by_hash($hash);

		if ($link_id === 0)
		{
			$this->db->sql_query('INSERT INTO ' . $this->links_table . ' ' . $this->db->sql_build_array('INSERT', [
				'url_hash'		=> $hash,
				'url'			=> $url,
				'url_host'		=> utf8_substr($host, 0, 255),
				'status_code'	=> 0,
				'link_state'	=> constants::LINK_PENDING,
				'fail_count'	=> 0,
				'last_checked'	=> 0,
				'next_check'	=> time(),
				'occurrences'	=> 0,
			]));

			$link_id = (int) $this->db->sql_nextid();
		}

		$sql = 'SELECT link_id FROM ' . $this->occurrences_table . '
			WHERE link_id = ' . (int) $link_id . '
				AND post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$known = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$known)
		{
			$this->db->sql_query('INSERT INTO ' . $this->occurrences_table . ' ' . $this->db->sql_build_array('INSERT', [
				'link_id'	=> (int) $link_id,
				'post_id'	=> (int) $post_id,
				'topic_id'	=> (int) $topic_id,
				'forum_id'	=> (int) $forum_id,
			]));

			$this->db->sql_query('UPDATE ' . $this->links_table . '
				SET occurrences = occurrences + 1
				WHERE link_id = ' . (int) $link_id);
		}

		return (int) $link_id;
	}

	/**
	 * Links whose next check is due.
	 *
	 * @param int $now   Current timestamp.
	 * @param int $limit Batch size.
	 * @return array[]
	 */
	public function due_for_check($now, $limit)
	{
		$sql = 'SELECT link_id, url, url_host, fail_count, link_state
			FROM ' . $this->links_table . '
			WHERE next_check <= ' . (int) $now . "
				AND link_state <> '" . $this->db->sql_escape(constants::LINK_SKIPPED) . "'
			ORDER BY next_check ASC";
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
	 * Record the outcome of a check.
	 *
	 * @param int    $link_id     Link id.
	 * @param string $state       New state.
	 * @param int    $status_code HTTP status, 0 when no response.
	 * @param int    $next_check  When to look again.
	 * @param int    $fail_count  Consecutive failure counter.
	 * @return void
	 */
	public function record_result($link_id, $state, $status_code, $next_check, $fail_count)
	{
		$this->db->sql_query('UPDATE ' . $this->links_table . '
			SET ' . $this->db->sql_build_array('UPDATE', [
				'link_state'	=> (string) $state,
				'status_code'	=> (int) $status_code,
				'last_checked'	=> time(),
				'next_check'	=> (int) $next_check,
				'fail_count'	=> (int) $fail_count,
			]) . '
			WHERE link_id = ' . (int) $link_id);
	}

	/**
	 * Broken and warning links, with one example topic each.
	 *
	 * @param array $filters Accepts state.
	 * @param int   $start   Offset.
	 * @param int   $limit   Page size.
	 * @return array[]
	 */
	public function find(array $filters, $start = 0, $limit = 25)
	{
		$sql = 'SELECT l.link_id, l.url, l.url_host, l.status_code, l.link_state,
					l.last_checked, l.occurrences, l.fail_count
			FROM ' . $this->links_table . ' l
			WHERE ' . $this->build_where($filters) . '
			ORDER BY l.occurrences DESC, l.link_id ASC';
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
	 * Count links matching filters.
	 *
	 * @param array $filters Same shape as find().
	 * @return int
	 */
	public function count(array $filters)
	{
		$sql = 'SELECT COUNT(*) AS num FROM ' . $this->links_table . ' l WHERE ' . $this->build_where($filters);
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (int) ($row['num'] ?? 0);
	}

	/**
	 * Topics in which a link appears.
	 *
	 * @param int $link_id Link id.
	 * @param int $limit   Maximum rows.
	 * @return array[]
	 */
	public function occurrences($link_id, $limit = 10)
	{
		$sql = 'SELECT o.topic_id, o.post_id, o.forum_id, t.topic_title
			FROM ' . $this->occurrences_table . ' o
			INNER JOIN ' . $this->topics_table . ' t ON (t.topic_id = o.topic_id)
			WHERE o.link_id = ' . (int) $link_id . '
			ORDER BY o.post_id ASC';
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
	 * Counts by link state, for the dashboard and the health score.
	 *
	 * @return array<string, int>
	 */
	public function counts_by_state()
	{
		$sql = 'SELECT link_state, COUNT(*) AS num FROM ' . $this->links_table . ' GROUP BY link_state';
		$result = $this->db->sql_query($sql);
		$out = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$out[(string) $row['link_state']] = (int) $row['num'];
		}

		$this->db->sql_freeresult($result);

		return $out;
	}

	/**
	 * Remove occurrences pointing at deleted posts, then orphaned links.
	 *
	 * @param string $posts_table phpBB posts table.
	 * @param int    $limit       Maximum rows per pass.
	 * @return int Rows removed.
	 */
	public function prune_orphans($posts_table, $limit = 500)
	{
		$sql = 'SELECT o.link_id, o.post_id
			FROM ' . $this->occurrences_table . ' o
			LEFT JOIN ' . $posts_table . ' p ON (p.post_id = o.post_id)
			WHERE p.post_id IS NULL';
		$result = $this->db->sql_query_limit($sql, (int) $limit);
		$pairs = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$pairs[] = [(int) $row['link_id'], (int) $row['post_id']];
		}

		$this->db->sql_freeresult($result);

		foreach ($pairs as $pair)
		{
			$this->db->sql_query('DELETE FROM ' . $this->occurrences_table . '
				WHERE link_id = ' . $pair[0] . ' AND post_id = ' . $pair[1]);

			$this->db->sql_query('UPDATE ' . $this->links_table . '
				SET occurrences = occurrences - 1
				WHERE link_id = ' . $pair[0] . ' AND occurrences > 0');
		}

		// A URL nobody references any more has nothing left to report on.
		$this->db->sql_query('DELETE FROM ' . $this->links_table . ' WHERE occurrences = 0');

		return count($pairs);
	}

	/**
	 * Find a link id by URL hash.
	 *
	 * @param string $hash SHA-1 of the normalised URL.
	 * @return int Zero when unknown.
	 */
	protected function find_id_by_hash($hash)
	{
		$sql = 'SELECT link_id FROM ' . $this->links_table . "
			WHERE url_hash = '" . $this->db->sql_escape($hash) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (int) ($row['link_id'] ?? 0);
	}

	/**
	 * Build a WHERE clause from filters.
	 *
	 * @param array $filters Filter values.
	 * @return string
	 */
	protected function build_where(array $filters)
	{
		$where = ['1 = 1'];

		if (!empty($filters['state']))
		{
			$where[] = "l.link_state = '" . $this->db->sql_escape((string) $filters['state']) . "'";
		}

		if (!empty($filters['problems_only']))
		{
			$where[] = "l.link_state IN ('" . $this->db->sql_escape(constants::LINK_BROKEN) . "', '"
				. $this->db->sql_escape(constants::LINK_WARNING) . "', '"
				. $this->db->sql_escape(constants::LINK_UNSAFE) . "')";
		}

		return implode(' AND ', $where);
	}
}
