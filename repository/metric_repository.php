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
 * Daily metric history.
 *
 * Every trend in the interface is a comparison between two ranges of this table.
 * Storing one aggregated row per metric per day keeps trend queries constant in
 * cost regardless of how busy the forum is.
 */
class metric_repository
{
	/** @var driver_interface */
	protected $db;

	/** @var string */
	protected $table;

	/**
	 * @param driver_interface $db    Database driver.
	 * @param string           $table Metrics history table.
	 */
	public function __construct(driver_interface $db, $table)
	{
		$this->db = $db;
		$this->table = $table;
	}

	/**
	 * Store or overwrite one data point.
	 *
	 * @param string $key        Metric key.
	 * @param int    $day        Day bucket as YYYYMMDD.
	 * @param float  $value      Value.
	 * @param string $scope_type Scope type, default global.
	 * @param int    $scope_id   Scope id, default 0.
	 * @return void
	 */
	public function record($key, $day, $value, $scope_type = 'global', $scope_id = 0)
	{
		$where = "metric_key = '" . $this->db->sql_escape((string) $key) . "'
			AND metric_day = " . (int) $day . "
			AND scope_type = '" . $this->db->sql_escape((string) $scope_type) . "'
			AND scope_id = " . (int) $scope_id;

		$result = $this->db->sql_query('SELECT metric_id FROM ' . $this->table . ' WHERE ' . $where);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($row)
		{
			$this->db->sql_query('UPDATE ' . $this->table . '
				SET metric_value = ' . (float) $value . '
				WHERE metric_id = ' . (int) $row['metric_id']);

			return;
		}

		$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', [
			'metric_key'	=> (string) $key,
			'metric_day'	=> (int) $day,
			'metric_value'	=> (float) $value,
			'scope_type'	=> (string) $scope_type,
			'scope_id'		=> (int) $scope_id,
		]));
	}

	/**
	 * Series of values for one metric over a day range.
	 *
	 * @param string $key       Metric key.
	 * @param int    $from_day  Inclusive start, YYYYMMDD.
	 * @param int    $to_day    Inclusive end, YYYYMMDD.
	 * @return array<int, float> day => value.
	 */
	public function series($key, $from_day, $to_day)
	{
		$sql = 'SELECT metric_day, metric_value
			FROM ' . $this->table . "
			WHERE metric_key = '" . $this->db->sql_escape((string) $key) . "'
				AND metric_day >= " . (int) $from_day . '
				AND metric_day <= ' . (int) $to_day . "
				AND scope_type = 'global'
			ORDER BY metric_day ASC";
		$result = $this->db->sql_query($sql);
		$out = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$out[(int) $row['metric_day']] = (float) $row['metric_value'];
		}

		$this->db->sql_freeresult($result);

		return $out;
	}

	/**
	 * Sum of a metric over a day range.
	 *
	 * @param string $key      Metric key.
	 * @param int    $from_day Inclusive start.
	 * @param int    $to_day   Inclusive end.
	 * @return float
	 */
	public function sum($key, $from_day, $to_day)
	{
		return $this->aggregate('SUM', $key, $from_day, $to_day);
	}

	/**
	 * Average of a metric over a day range.
	 *
	 * @param string $key      Metric key.
	 * @param int    $from_day Inclusive start.
	 * @param int    $to_day   Inclusive end.
	 * @return float
	 */
	public function average($key, $from_day, $to_day)
	{
		return $this->aggregate('AVG', $key, $from_day, $to_day);
	}

	/**
	 * Most recent recorded value of a metric.
	 *
	 * @param string $key Metric key.
	 * @return float|null Null when the metric has never been recorded.
	 */
	public function latest($key)
	{
		$sql = 'SELECT metric_value
			FROM ' . $this->table . "
			WHERE metric_key = '" . $this->db->sql_escape((string) $key) . "'
				AND scope_type = 'global'
			ORDER BY metric_day DESC";
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ? (float) $row['metric_value'] : null;
	}

	/**
	 * Delete data points older than the retention window.
	 *
	 * @param int $before_day Cut-off day bucket.
	 * @return void
	 */
	public function prune($before_day)
	{
		$this->db->sql_query('DELETE FROM ' . $this->table . ' WHERE metric_day < ' . (int) $before_day);
	}

	/**
	 * Run a bounded aggregate over a day range.
	 *
	 * @param string $function SQL aggregate, whitelisted by the caller.
	 * @param string $key      Metric key.
	 * @param int    $from_day Inclusive start.
	 * @param int    $to_day   Inclusive end.
	 * @return float
	 */
	protected function aggregate($function, $key, $from_day, $to_day)
	{
		$function = in_array($function, ['SUM', 'AVG', 'MAX', 'MIN'], true) ? $function : 'SUM';

		$sql = 'SELECT ' . $function . '(metric_value) AS val
			FROM ' . $this->table . "
			WHERE metric_key = '" . $this->db->sql_escape((string) $key) . "'
				AND metric_day >= " . (int) $from_day . '
				AND metric_day <= ' . (int) $to_day . "
				AND scope_type = 'global'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (float) ($row['val'] ?? 0);
	}
}
