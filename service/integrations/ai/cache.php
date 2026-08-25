<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\integrations\ai;

use phpbb\db\driver\driver_interface;
use salvocortesiano\forumhealth\service\settings;

/**
 * Persistent cache for AI analysis results.
 *
 * AI calls cost money and time, and the same question tends to be asked again on
 * every scan. The cache key therefore covers everything that could change the
 * answer: which entity, which analysis, the content itself, the configuration
 * version and the provider identity. If any of those differ the analysis runs
 * again; if none of them do, it does not.
 *
 * Only results are stored. No prompt, no credential, no provider endpoint.
 */
class cache
{
	/** @var driver_interface */
	protected $db;

	/** @var settings */
	protected $settings;

	/** @var string */
	protected $table;

	/**
	 * @param driver_interface $db       Database driver.
	 * @param settings         $settings Extension settings.
	 * @param string           $table    AI cache table.
	 */
	public function __construct(driver_interface $db, settings $settings, $table)
	{
		$this->db = $db;
		$this->settings = $settings;
		$this->table = $table;
	}

	/**
	 * Build the cache key for one analysis.
	 *
	 * @param string $entity_type   Entity type.
	 * @param int    $entity_id     Entity id.
	 * @param string $analysis_type Capability name.
	 * @param string $content_hash  Hash of the analysed content.
	 * @param string $provider_ref  Provider identity string.
	 * @return string 40 character key.
	 */
	public function key($entity_type, $entity_id, $analysis_type, $content_hash, $provider_ref)
	{
		return sha1(implode('|', [
			(string) $entity_type,
			(int) $entity_id,
			(string) $analysis_type,
			(string) $content_hash,
			(int) $this->settings->config_version(),
			(string) $provider_ref,
		]));
	}

	/**
	 * Read a cached result.
	 *
	 * @param string $key Cache key.
	 * @return array|null Decoded result, or null when absent or expired.
	 */
	public function get($key)
	{
		$sql = 'SELECT result_data, expires_at FROM ' . $this->table . "
			WHERE cache_key = '" . $this->db->sql_escape((string) $key) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			return null;
		}

		if ((int) $row['expires_at'] > 0 && (int) $row['expires_at'] < time())
		{
			return null;
		}

		$data = json_decode((string) $row['result_data'], true);

		return is_array($data) ? $data : null;
	}

	/**
	 * Store a result.
	 *
	 * @param string $key           Cache key.
	 * @param string $entity_type   Entity type.
	 * @param int    $entity_id     Entity id.
	 * @param string $analysis_type Capability name.
	 * @param string $content_hash  Content hash.
	 * @param string $provider_ref  Provider identity.
	 * @param array  $result        Structured result.
	 * @return void
	 */
	public function put($key, $entity_type, $entity_id, $analysis_type, $content_hash, $provider_ref, array $result)
	{
		$now = time();
		$ttl = $this->settings->get_int('fh_ai_cache_days') * 86400;

		$row = [
			'cache_key'		=> (string) $key,
			'entity_type'	=> (string) $entity_type,
			'entity_id'		=> (int) $entity_id,
			'analysis_type'	=> (string) $analysis_type,
			'content_hash'	=> (string) $content_hash,
			'config_version'=> $this->settings->config_version(),
			'provider_ref'	=> utf8_substr((string) $provider_ref, 0, 64),
			'result_data'	=> json_encode($result),
			'created_at'	=> $now,
			'expires_at'	=> $now + $ttl,
		];

		$sql = 'SELECT cache_id FROM ' . $this->table . "
			WHERE cache_key = '" . $this->db->sql_escape((string) $key) . "'";
		$result_set = $this->db->sql_query($sql);
		$existing = $this->db->sql_fetchrow($result_set);
		$this->db->sql_freeresult($result_set);

		if ($existing)
		{
			unset($row['cache_key'], $row['created_at']);

			$this->db->sql_query('UPDATE ' . $this->table . '
				SET ' . $this->db->sql_build_array('UPDATE', $row) . '
				WHERE cache_id = ' . (int) $existing['cache_id']);

			return;
		}

		$this->db->sql_query('INSERT INTO ' . $this->table . ' ' . $this->db->sql_build_array('INSERT', $row));
	}

	/**
	 * Cached results for an entity, regardless of analysis type.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity id.
	 * @return array<string, array> analysis_type => result.
	 */
	public function for_entity($entity_type, $entity_id)
	{
		$sql = 'SELECT analysis_type, result_data FROM ' . $this->table . "
			WHERE entity_type = '" . $this->db->sql_escape((string) $entity_type) . "'
				AND entity_id = " . (int) $entity_id . '
				AND (expires_at = 0 OR expires_at >= ' . time() . ')';
		$result = $this->db->sql_query($sql);
		$out = [];

		while ($row = $this->db->sql_fetchrow($result))
		{
			$data = json_decode((string) $row['result_data'], true);

			if (is_array($data))
			{
				$out[(string) $row['analysis_type']] = $data;
			}
		}

		$this->db->sql_freeresult($result);

		return $out;
	}

	/**
	 * Delete expired entries.
	 *
	 * @return void
	 */
	public function prune()
	{
		$this->db->sql_query('DELETE FROM ' . $this->table . '
			WHERE expires_at > 0 AND expires_at < ' . time());
	}

	/**
	 * Empty the cache, used when an administrator changes provider.
	 *
	 * @return void
	 */
	public function clear()
	{
		$this->db->sql_query('DELETE FROM ' . $this->table);
	}

	/**
	 * Number of live entries.
	 *
	 * @return int
	 */
	public function count()
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS num FROM ' . $this->table);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return (int) ($row['num'] ?? 0);
	}
}
