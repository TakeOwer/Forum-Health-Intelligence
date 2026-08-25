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
 * Persistence for alerts.
 *
 * Alerts are deduplicated by signature: the same finding raised on two
 * consecutive runs updates one row instead of accumulating. An alert that an
 * administrator has already dismissed is never silently resurrected.
 */
class alert_repository
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $table;

	/**
	 * @param driver_interface $db    Database driver.
	 * @param string           $table Alerts table.
	 */
	public function __construct(driver_interface $db, $table)
	{
		$this->db = $db;
		$this->table = $table;
	}

	/**
	 * Create an alert, or refresh the existing one with the same signature.
	 *
	 * @param array $data Alert fields. Requires alert_type, severity, signature,
	 *                    explain_key; explain_data is encoded here.
	 * @return bool True when a new alert row was created.
	 */
	public function raise(array $data)
	{
		$now = time();

		$row = [
			'alert_type'	=> (string) $data['alert_type'],
			'severity'		=> (int) $data['severity'],
			'alert_status'	=> constants::STATUS_NEW,
			'entity_type'	=> isset($data['entity_type']) ? (string) $data['entity_type'] : '',
			'entity_id'		=> isset($data['entity_id']) ? (int) $data['entity_id'] : 0,
			'signature'		=> sha1((string) $data['signature']),
			'explain_key'	=> (string) $data['explain_key'],
			'explain_data'	=> json_encode(isset($data['explain_data']) ? $data['explain_data'] : []),
			'action_key'	=> isset($data['action_key']) ? (string) $data['action_key'] : '',
			'source'		=> isset($data['source']) ? (string) $data['source'] : constants::SOURCE_NATIVE,
			'created_at'	=> $now,
			'updated_at'	=> $now,
		];

		$existing = $this->find_by_signature($row['signature']);

		if ($existing === null)
		{
			$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $row));

			return true;
		}

		// A dismissed alert stays dismissed: the administrator has already
		// judged this finding and should not be asked again.
		if ($existing['alert_status'] === constants::STATUS_DISMISSED)
		{
			return false;
		}

		$this->db->sql_query('UPDATE ' . $this->table . '
			SET ' . $this->db->sql_build_array('UPDATE', [
				'severity'		=> $row['severity'],
				'explain_data'	=> $row['explain_data'],
				'updated_at'	=> $now,
			]) . '
			WHERE alert_id = ' . (int) $existing['alert_id']);

		return false;
	}

	/**
	 * Look an alert up by its deduplication signature.
	 *
	 * @param string $signature Hashed signature.
	 * @return array|null
	 */
	public function find_by_signature($signature)
	{
		$sql = 'SELECT alert_id, alert_status FROM ' . $this->table . "
			WHERE signature = '" . $this->db->sql_escape((string) $signature) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: null;
	}

	/**
	 * Fetch a single alert.
	 *
	 * @param int $alert_id Alert id.
	 * @return array|null
	 */
	public function get($alert_id)
	{
		$sql = 'SELECT * FROM ' . $this->table . ' WHERE alert_id = ' . (int) $alert_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: null;
	}

	/**
	 * List alerts with optional filters.
	 *
	 * @param array $filters Accepts status, type, min_severity.
	 * @param int   $start   Offset.
	 * @param int   $limit   Page size.
	 * @return array[]
	 */
	public function find(array $filters, $start = 0, $limit = 25)
	{
		$sql = 'SELECT * FROM ' . $this->table . '
			WHERE ' . $this->build_where($filters) . '
			ORDER BY severity DESC, created_at DESC';
		$result = $this->db->sql_query_limit($sql, (int) $limit, (int) $start);
		$rows = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$row['explain_data'] = $this->decode($row['explain_data']);
			$rows[] = $row;
		}

		$this->db->sql_freeresult($result);

		return $rows;
	}

	/**
	 * Count alerts matching filters.
	 *
	 * @param array $filters Same shape as find().
	 * @return int
	 */
	public function count(array $filters)
	{
		$sql = 'SELECT COUNT(*) AS num FROM ' . $this->table . ' WHERE ' . $this->build_where($filters);
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (int) ($row['num'] ?? 0);
	}

	/**
	 * Open alert counts grouped by type.
	 *
	 * @return array<string, int>
	 */
	public function counts_by_type()
	{
		$sql = 'SELECT alert_type, COUNT(*) AS num
			FROM ' . $this->table . "
			WHERE alert_status IN ('" . $this->db->sql_escape(constants::STATUS_NEW) . "', '"
				. $this->db->sql_escape(constants::STATUS_ACKNOWLEDGED) . "')
			GROUP BY alert_type";
		$result = $this->db->sql_query($sql);
		$out = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$out[(string) $row['alert_type']] = (int) $row['num'];
		}

		$this->db->sql_freeresult($result);

		return $out;
	}

	/**
	 * Open alert counts grouped by severity.
	 *
	 * @return array<int, int>
	 */
	public function counts_by_severity()
	{
		$sql = 'SELECT severity, COUNT(*) AS num
			FROM ' . $this->table . "
			WHERE alert_status = '" . $this->db->sql_escape(constants::STATUS_NEW) . "'
			GROUP BY severity";
		$result = $this->db->sql_query($sql);
		$out = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$out[(int) $row['severity']] = (int) $row['num'];
		}

		$this->db->sql_freeresult($result);

		return $out;
	}

	/**
	 * Change the status of one alert.
	 *
	 * @param int    $alert_id Alert id.
	 * @param string $status   One of the settable statuses.
	 * @return bool True when the status was valid and applied.
	 */
	public function set_status($alert_id, $status)
	{
		if (!in_array($status, constants::settable_statuses(), true))
		{
			return false;
		}

		$this->db->sql_query('UPDATE ' . $this->table . "
			SET alert_status = '" . $this->db->sql_escape($status) . "',
				updated_at = " . time() . '
			WHERE alert_id = ' . (int) $alert_id);

		return true;
	}

	/**
	 * Resolve every open alert of a type that points at a given entity.
	 *
	 * Used when a finding stops being true, so the list reflects reality without
	 * the administrator having to tidy up manually.
	 *
	 * @param string $type        Alert type.
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity id.
	 * @return void
	 */
	public function resolve_entity($type, $entity_type, $entity_id)
	{
		$this->db->sql_query('UPDATE ' . $this->table . "
			SET alert_status = '" . $this->db->sql_escape(constants::STATUS_RESOLVED) . "',
				updated_at = " . time() . "
			WHERE alert_type = '" . $this->db->sql_escape((string) $type) . "'
				AND entity_type = '" . $this->db->sql_escape((string) $entity_type) . "'
				AND entity_id = " . (int) $entity_id . "
				AND alert_status IN ('" . $this->db->sql_escape(constants::STATUS_NEW) . "', '"
					. $this->db->sql_escape(constants::STATUS_ACKNOWLEDGED) . "')");
	}

	/**
	 * Delete closed alerts older than the retention window.
	 *
	 * Open alerts are never deleted by retention: an unresolved problem does not
	 * expire just because time passed.
	 *
	 * @param int $before Cut-off timestamp.
	 * @param int $limit  Maximum rows per pass.
	 * @return int Rows removed.
	 */
	public function prune($before, $limit = 500)
	{
		$sql = 'SELECT alert_id FROM ' . $this->table . "
			WHERE updated_at < " . (int) $before . "
				AND alert_status IN ('" . $this->db->sql_escape(constants::STATUS_RESOLVED) . "', '"
					. $this->db->sql_escape(constants::STATUS_DISMISSED) . "')";
		$result = $this->db->sql_query_limit($sql, (int) $limit);
		$ids = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$ids[] = (int) $row['alert_id'];
		}

		$this->db->sql_freeresult($result);

		if (empty($ids))
		{
			return 0;
		}

		$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE ' . $this->db->sql_in_set('alert_id', $ids));

		return count($ids);
	}

	/**
	 * Build the WHERE clause for a filter set.
	 *
	 * @param array $filters Filter values.
	 * @return string
	 */
	protected function build_where(array $filters)
	{
		$where = ['1 = 1'];

		if (!empty($filters['status']))
		{
			$where[] = "alert_status = '" . $this->db->sql_escape((string) $filters['status']) . "'";
		}

		if (!empty($filters['open_only']))
		{
			$where[] = "alert_status IN ('" . $this->db->sql_escape(constants::STATUS_NEW) . "', '"
				. $this->db->sql_escape(constants::STATUS_ACKNOWLEDGED) . "')";
		}

		if (!empty($filters['type']))
		{
			$where[] = "alert_type = '" . $this->db->sql_escape((string) $filters['type']) . "'";
		}

		if (!empty($filters['min_severity']))
		{
			$where[] = 'severity >= ' . (int) $filters['min_severity'];
		}

		return implode(' AND ', $where);
	}

	/**
	 * Decode stored explanation parameters.
	 *
	 * @param string $json Stored JSON.
	 * @return array
	 */
	protected function decode($json)
	{
		$data = json_decode((string) $json, true);

		return is_array($data) ? $data : [];
	}
}
