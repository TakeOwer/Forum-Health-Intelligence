<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service;

use phpbb\config\config;

/**
 * Typed, validated access to the extension configuration.
 *
 * phpBB stores configuration as strings. Reading raw values all over the code
 * base invites silent type bugs and lets an out-of-range value reach a query, so
 * every consumer goes through this service instead, and every numeric setting is
 * clamped to a sane range on read.
 */
class settings
{
	/** @var config */
	protected $config;

	/**
	 * Bounds for numeric settings: key => [min, max, default].
	 *
	 * @var array<string, array{0:int,1:int,2:int}>
	 */
	protected static $bounds = [
		'fh_batch_size'					=> [10, 5000, 200],
		'fh_cache_ttl'					=> [0, 86400, 900],
		'fh_log_level'					=> [0, 2, 1],
		'fh_unanswered_hours'			=> [1, 8760, 48],
		'fh_unanswered_min_views'		=> [0, 1000000, 100],
		'fh_unanswered_max_age_days'	=> [1, 3650, 180],
		'fh_duplicate_threshold'		=> [30, 100, 62],
		'fh_duplicate_high_threshold'	=> [40, 100, 82],
		'fh_duplicate_window_days'		=> [1, 7300, 730],
		'fh_duplicate_same_forum_bonus'	=> [0, 30, 8],
		'fh_min_token_length'			=> [2, 8, 3],
		'fh_user_warning_threshold'		=> [30, 100, 70],
		'fh_user_warning_limit'			=> [1, 20, 5],
		'fh_related_topics_limit'		=> [1, 20, 5],
		'fh_link_timeout'				=> [1, 60, 8],
		'fh_link_batch'					=> [1, 500, 25],
		'fh_link_recheck_days'			=> [1, 365, 30],
		'fh_link_retry_days'			=> [1, 90, 2],
		'fh_link_max_fails'				=> [1, 20, 3],
		'fh_link_max_redirects'			=> [0, 10, 3],
		'fh_link_delay_ms'				=> [0, 10000, 250],
		'fh_freshness_months'			=> [1, 240, 24],
		'fh_freshness_min_views'		=> [0, 1000000, 50],
		'fh_solution_min_confidence'	=> [30, 100, 60],
		'fh_recurring_min_topics'		=> [2, 1000, 5],
		'fh_newuser_reply_hours'		=> [1, 8760, 48],
		'fh_newuser_alert_threshold'	=> [1, 10000, 10],
		'fh_activity_drop_percent'		=> [1, 99, 25],
		'fh_trend_period_days'			=> [7, 365, 30],
		'fh_moderator_load_threshold'	=> [1, 100000, 100],
		'fh_alerts_max_per_run'			=> [10, 5000, 200],
		'fh_ai_daily_limit'				=> [0, 100000, 200],
		// [min, max, default]. The default is 0, meaning no bot chosen, which
		// is what keeps AI analysis unavailable until an administrator picks one.
		'fh_ai_bot_id'				=> [0, 999999, 0],
		'fh_ai_cache_days'				=> [1, 365, 30],
		'fh_ai_min_candidate_conf'		=> [0, 100, 50],
		'fh_retain_alerts_days'			=> [7, 3650, 90],
		'fh_retain_links_days'			=> [7, 3650, 180],
		'fh_retain_metrics_days'		=> [30, 3650, 400],
		'fh_retain_relations_days'		=> [7, 3650, 180],
		'fh_weight_unanswered'			=> [0, 100, 25],
		'fh_weight_duplicates'			=> [0, 100, 15],
		'fh_weight_links'				=> [0, 100, 20],
		'fh_weight_freshness'			=> [0, 100, 20],
		'fh_weight_solutions'			=> [0, 100, 20],
		'fh_weight_participation'		=> [0, 100, 30],
		'fh_weight_responsiveness'		=> [0, 100, 30],
		'fh_weight_onboarding'			=> [0, 100, 25],
		'fh_weight_retention'			=> [0, 100, 15],
	];

	/**
	 * @param config $config phpBB configuration.
	 */
	public function __construct(config $config)
	{
		$this->config = $config;
	}

	/**
	 * Read an integer setting, clamped to its declared range.
	 *
	 * @param string $key Configuration key.
	 * @return int
	 */
	public function get_int($key)
	{
		$raw = isset($this->config[$key]) ? (int) $this->config[$key] : 0;

		if (!isset(self::$bounds[$key]))
		{
			return $raw;
		}

		list($min, $max, $default) = self::$bounds[$key];

		if (!isset($this->config[$key]))
		{
			return $default;
		}

		return max($min, min($max, $raw));
	}

	/**
	 * Read a boolean setting.
	 *
	 * @param string $key Configuration key.
	 * @return bool
	 */
	public function get_bool($key)
	{
		return isset($this->config[$key]) && (int) $this->config[$key] === 1;
	}

	/**
	 * Read a string setting.
	 *
	 * @param string $key Configuration key.
	 * @return string
	 */
	public function get_string($key)
	{
		return isset($this->config[$key]) ? (string) $this->config[$key] : '';
	}

	/**
	 * Persist a value.
	 *
	 * @param string $key    Configuration key.
	 * @param mixed  $value  New value.
	 * @param bool   $cached Whether the value may be cached by phpBB.
	 * @return void
	 */
	public function set($key, $value, $cached = true)
	{
		$this->config->set($key, $value, $cached);
	}

	/**
	 * Whether the extension as a whole is switched on.
	 *
	 * @return bool
	 */
	public function is_enabled()
	{
		return $this->get_bool('fh_enabled');
	}

	/**
	 * Whether a named feature may run.
	 *
	 * The master switch always wins: if the extension is off, no feature is on.
	 *
	 * @param string $feature One of content, community, duplicates, links,
	 *                        freshness, solutions, alerts, rules, background,
	 *                        recurring, knowledge.
	 * @return bool
	 */
	public function feature_enabled($feature)
	{
		if (!$this->is_enabled())
		{
			return false;
		}

		$map = [
			'content'		=> 'fh_content_enabled',
			'community'		=> 'fh_community_enabled',
			'duplicates'	=> 'fh_duplicates_enabled',
			'links'			=> 'fh_links_enabled',
			'freshness'		=> 'fh_freshness_enabled',
			'solutions'		=> 'fh_solutions_enabled',
			'recurring'		=> 'fh_recurring_enabled',
			'knowledge'		=> 'fh_knowledge_enabled',
			'alerts'		=> 'fh_alerts_enabled',
			'rules'			=> 'fh_rules_enabled',
			'background'	=> 'fh_background_enabled',
			'user_warning'	=> 'fh_user_warning_enabled',
			'related'		=> 'fh_related_topics_enabled',
		];

		if (!isset($map[$feature]))
		{
			return false;
		}

		// Content sub-features additionally require the content module.
		$content_children = ['duplicates', 'links', 'freshness', 'solutions', 'recurring', 'knowledge'];

		if (in_array($feature, $content_children, true) && !$this->get_bool('fh_content_enabled'))
		{
			return false;
		}

		return $this->get_bool($map[$feature]);
	}

	/**
	 * Forum ids excluded from every analysis.
	 *
	 * @return int[]
	 */
	public function excluded_forums()
	{
		$raw = trim($this->get_string('fh_excluded_forums'));

		if ($raw === '')
		{
			return [];
		}

		$ids = array_map('intval', explode(',', $raw));

		return array_values(array_unique(array_filter($ids, function ($id) {
			return $id > 0;
		})));
	}

	/**
	 * Domains the link scanner must never contact.
	 *
	 * @return string[] Lowercase host names.
	 */
	public function ignored_link_domains()
	{
		return $this->split_lines($this->get_string('fh_link_ignore_domains'), true);
	}

	/**
	 * Substring patterns that exclude a URL from scanning.
	 *
	 * @return string[]
	 */
	public function ignored_link_patterns()
	{
		return $this->split_lines($this->get_string('fh_link_ignore_patterns'), false);
	}

	/**
	 * Configuration version, part of the AI cache key.
	 *
	 * @return int
	 */
	public function config_version()
	{
		return (int) $this->get_string('fh_config_version');
	}

	/**
	 * Invalidate derived analysis by bumping the configuration version.
	 *
	 * Called whenever a setting that changes the meaning of a stored result is
	 * saved, so cached AI answers computed under the old settings are not reused.
	 *
	 * @return void
	 */
	public function bump_config_version()
	{
		$this->config->increment('fh_config_version', 1);
	}

	/**
	 * Split a textarea setting into clean lines.
	 *
	 * @param string $value      Raw setting value.
	 * @param bool   $lowercase  Whether to lowercase each entry.
	 * @return string[]
	 */
	protected function split_lines($value, $lowercase)
	{
		$parts = preg_split('/[\r\n,]+/', (string) $value);
		$out = [];

		foreach ((array) $parts as $part)
		{
			$part = trim($part);

			if ($part === '')
			{
				continue;
			}

			$out[] = $lowercase ? utf8_strtolower($part) : $part;
		}

		return array_values(array_unique($out));
	}
}
