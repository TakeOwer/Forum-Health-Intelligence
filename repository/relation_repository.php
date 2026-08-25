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
 * Persistence for detected relations between topics.
 *
 * A relation is stored once per unordered pair. The pair is normalised so that
 * "A resembles B" and "B resembles A" cannot both occupy a row and be reviewed
 * twice by a moderator.
 */
class relation_repository
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $table;

	/** @var string */
	protected $topics_table;

	/**
	 * @param driver_interface $db           Database driver.
	 * @param string           $table        Relations table.
	 * @param string           $topics_table phpBB topics table.
	 */
	public function __construct(driver_interface $db, $table, $topics_table)
	{
		$this->db = $db;
		$this->table = $table;
		$this->topics_table = $topics_table;
	}

	/**
	 * Record a relation, keeping the strongest evidence seen for the pair.
	 *
	 * A pair already dismissed by a human is never re-raised, no matter which
	 * layer proposes it again.
	 *
	 * @param int      $topic_id   One topic id.
	 * @param int      $related_id The other topic id.
	 * @param int      $confidence Confidence 0-100.
	 * @param string   $source     native, meilisearch or ai.
	 * @param string[] $reasons    Reason codes; resolved to language keys on output.
	 * @param string   $type       Relation type.
	 * @return bool True when a new relation row was created.
	 */
	public function store($topic_id, $related_id, $confidence, $source, array $reasons, $type = constants::RELATION_DUPLICATE)
	{
		list($low, $high) = $this->normalise_pair($topic_id, $related_id);

		if ($low === 0 || $low === $high)
		{
			return false;
		}

		$existing = $this->find_pair($low, $high, $type);

		if ($existing !== null)
		{
			if ($existing['relation_status'] === constants::RELATION_DISMISSED)
			{
				return false;
			}

			// Only overwrite when the new evidence is stronger, so a weak native
			// re-scan cannot downgrade a confident AI assessment.
			if ((int) $existing['confidence'] >= (int) $confidence)
			{
				return false;
			}

			$this->db->sql_query('UPDATE ' . $this->table . '
				SET ' . $this->db->sql_build_array('UPDATE', [
					'confidence'	=> (int) $confidence,
					'source'		=> (string) $source,
					'reasons'		=> json_encode(array_values($reasons)),
				]) . '
				WHERE relation_id = ' . (int) $existing['relation_id']);

			return false;
		}

		$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', [
			'topic_id'			=> $low,
			'related_topic_id'	=> $high,
			'relation_type'		=> (string) $type,
			'confidence'		=> (int) $confidence,
			'source'			=> (string) $source,
			'reasons'			=> json_encode(array_values($reasons)),
			'relation_status'	=> constants::RELATION_NEW,
			'created_at'		=> time(),
		]));

		return true;
	}

	/**
	 * Fetch one stored pair.
	 *
	 * @param int    $low  Lower topic id.
	 * @param int    $high Higher topic id.
	 * @param string $type Relation type.
	 * @return array|null
	 */
	public function find_pair($low, $high, $type)
	{
		$sql = 'SELECT relation_id, confidence, relation_status
			FROM ' . $this->table . '
			WHERE topic_id = ' . (int) $low . '
				AND related_topic_id = ' . (int) $high . "
				AND relation_type = '" . $this->db->sql_escape((string) $type) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: null;
	}

	/**
	 * List relations with both topic titles resolved.
	 *
	 * @param array $filters Accepts status, min_confidence, type.
	 * @param int   $start   Offset.
	 * @param int   $limit   Page size.
	 * @return array[]
	 */
	public function find(array $filters, $start = 0, $limit = 25)
	{
		$sql = 'SELECT r.*, t1.topic_title AS topic_title, t2.topic_title AS related_title,
					t1.forum_id AS topic_forum, t2.forum_id AS related_forum
			FROM ' . $this->table . ' r
			INNER JOIN ' . $this->topics_table . ' t1 ON (t1.topic_id = r.topic_id)
			INNER JOIN ' . $this->topics_table . ' t2 ON (t2.topic_id = r.related_topic_id)
			WHERE ' . $this->build_where($filters) . '
			ORDER BY r.confidence DESC, r.created_at DESC';
		$result = $this->db->sql_query_limit($sql, (int) $limit, (int) $start);
		$rows = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$reasons = json_decode((string) $row['reasons'], true);
			$row['reasons'] = is_array($reasons) ? $reasons : [];
			$rows[] = $row;
		}

		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Count relations matching filters.
	 *
	 * @param array $filters Same shape as find().
	 * @return int
	 */
	public function count(array $filters)
	{
		$sql = 'SELECT COUNT(*) AS num FROM ' . $this->table . ' r WHERE ' . $this->build_where($filters);
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (int) ($row['num'] ?? 0);
	}

	/**
	 * Relations involving a topic, used for the related-discussions display.
	 *
	 * @param int $topic_id       Topic id.
	 * @param int $min_confidence Minimum confidence.
	 * @param int $limit          Maximum rows.
	 * @return array[]
	 */
	public function for_topic($topic_id, $min_confidence, $limit)
	{
		$topic_id = (int) $topic_id;

		$sql = 'SELECT r.relation_id, r.confidence, r.reasons,
					CASE WHEN r.topic_id = ' . $topic_id . ' THEN r.related_topic_id ELSE r.topic_id END AS other_id
			FROM ' . $this->table . ' r
			WHERE (r.topic_id = ' . $topic_id . ' OR r.related_topic_id = ' . $topic_id . ')
				AND r.confidence >= ' . (int) $min_confidence . "
				AND r.relation_status <> '" . $this->db->sql_escape(constants::RELATION_DISMISSED) . "'
			ORDER BY r.confidence DESC";
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
	 * Set the review status of a relation.
	 *
	 * @param int    $relation_id Relation id.
	 * @param string $status      confirmed or dismissed.
	 * @return bool
	 */
	public function set_status($relation_id, $status)
	{
		$allowed = [constants::RELATION_NEW, constants::RELATION_CONFIRMED, constants::RELATION_DISMISSED];

		if (!in_array($status, $allowed, true))
		{
			return false;
		}

		$this->db->sql_query('UPDATE ' . $this->table . "
			SET relation_status = '" . $this->db->sql_escape($status) . "'
			WHERE relation_id = " . (int) $relation_id);

		return true;
	}

	/**
	 * Remove untouched relations older than the retention window.
	 *
	 * Confirmed and dismissed decisions are kept: they are human judgements, not
	 * derived data, and re-detecting them would waste review time.
	 *
	 * @param int $before Cut-off timestamp.
	 * @param int $limit  Maximum rows per pass.
	 * @return int
	 */
	public function prune($before, $limit = 500)
	{
		$sql = 'SELECT relation_id FROM ' . $this->table . '
			WHERE created_at < ' . (int) $before . "
				AND relation_status = '" . $this->db->sql_escape(constants::RELATION_NEW) . "'";
		$result = $this->db->sql_query_limit($sql, (int) $limit);
		$ids = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$ids[] = (int) $row['relation_id'];
		}

		$this->db->sql_freeresult($result);

		if (empty($ids))
		{
			return 0;
		}

		$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE ' . $this->db->sql_in_set('relation_id', $ids));

		return count($ids);
	}

	/**
	 * Delete relations that point at a topic which no longer exists.
	 *
	 * @param int $limit Maximum rows per pass.
	 * @return int
	 */
	public function prune_orphans($limit = 500)
	{
		$sql = 'SELECT r.relation_id
			FROM ' . $this->table . ' r
			LEFT JOIN ' . $this->topics_table . ' t ON (t.topic_id = r.topic_id)
			WHERE t.topic_id IS NULL';
		$result = $this->db->sql_query_limit($sql, (int) $limit);
		$ids = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$ids[] = (int) $row['relation_id'];
		}

		$this->db->sql_freeresult($result);

		if (empty($ids))
		{
			return 0;
		}

		$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE ' . $this->db->sql_in_set('relation_id', $ids));

		return count($ids);
	}

	/**
	 * Order a pair so that storage is direction independent.
	 *
	 * @param int $a First id.
	 * @param int $b Second id.
	 * @return int[] [lower, higher]
	 */
	protected function normalise_pair($a, $b)
	{
		$a = (int) $a;
		$b = (int) $b;

		return $a <= $b ? [$a, $b] : [$b, $a];
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

		if (!empty($filters['status']))
		{
			$where[] = "r.relation_status = '" . $this->db->sql_escape((string) $filters['status']) . "'";
		}

		if (!empty($filters['type']))
		{
			$where[] = "r.relation_type = '" . $this->db->sql_escape((string) $filters['type']) . "'";
		}

		if (!empty($filters['min_confidence']))
		{
			$where[] = 'r.confidence >= ' . (int) $filters['min_confidence'];
		}

		return implode(' AND ', $where);
	}
}
