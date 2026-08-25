<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\acp;

/**
 * The settings page.
 *
 * Settings are declared as data rather than written out one by one in the
 * template and again in the save handler. The declaration carries the type, the
 * bounds and the group, which means a new setting cannot be added without also
 * getting validation, and the form and the save path can never drift apart.
 *
 * Bounds are enforced here as well as in the settings service. Duplicating the
 * check is deliberate: this one produces a helpful message for a person who
 * typed something odd, and the other one protects the queries from a value that
 * arrived some other way.
 */
class settings_module extends base_module
{
	/**
	 * The declared settings, grouped for display.
	 *
	 * type: bool, int, string, text.
	 * For int: [min, max].
	 *
	 * @return array<string, array>
	 */
	protected function schema()
	{
		return [
			'general' => [
				'fh_enabled'				=> ['type' => 'bool'],
				'fh_background_enabled'		=> ['type' => 'bool'],
				'fh_batch_size'				=> ['type' => 'int', 'range' => [10, 5000]],
				'fh_excluded_forums'		=> ['type' => 'string'],
				'fh_log_level'				=> ['type' => 'int', 'range' => [0, 2]],
			],
			'content' => [
				'fh_content_enabled'		=> ['type' => 'bool'],
				'fh_unanswered_hours'		=> ['type' => 'int', 'range' => [1, 8760]],
				'fh_unanswered_min_views'	=> ['type' => 'int', 'range' => [0, 1000000]],
				'fh_unanswered_max_age_days'=> ['type' => 'int', 'range' => [1, 3650]],
			],
			'duplicates' => [
				'fh_duplicates_enabled'		=> ['type' => 'bool'],
				'fh_duplicate_threshold'	=> ['type' => 'int', 'range' => [30, 100]],
				'fh_duplicate_high_threshold'=> ['type' => 'int', 'range' => [40, 100]],
				'fh_duplicate_window_days'	=> ['type' => 'int', 'range' => [1, 7300]],
				'fh_duplicate_same_forum_bonus'=> ['type' => 'int', 'range' => [0, 30]],
				'fh_user_warning_enabled'	=> ['type' => 'bool'],
				'fh_user_warning_threshold'	=> ['type' => 'int', 'range' => [30, 100]],
				'fh_user_warning_limit'		=> ['type' => 'int', 'range' => [1, 20]],
				'fh_related_topics_enabled'	=> ['type' => 'bool'],
				'fh_related_topics_limit'	=> ['type' => 'int', 'range' => [1, 20]],
			],
			'links' => [
				'fh_links_enabled'			=> ['type' => 'bool'],
				'fh_link_timeout'			=> ['type' => 'int', 'range' => [1, 60]],
				'fh_link_batch'				=> ['type' => 'int', 'range' => [1, 500]],
				'fh_link_recheck_days'		=> ['type' => 'int', 'range' => [1, 365]],
				'fh_link_retry_days'		=> ['type' => 'int', 'range' => [1, 90]],
				'fh_link_max_fails'			=> ['type' => 'int', 'range' => [1, 20]],
				'fh_link_max_redirects'		=> ['type' => 'int', 'range' => [0, 10]],
				'fh_link_delay_ms'			=> ['type' => 'int', 'range' => [0, 10000]],
				'fh_link_ignore_domains'	=> ['type' => 'text'],
				'fh_link_ignore_patterns'	=> ['type' => 'text'],
				'fh_link_allow_private_hosts'=> ['type' => 'bool'],
			],
			'freshness' => [
				'fh_freshness_enabled'		=> ['type' => 'bool'],
				'fh_freshness_months'		=> ['type' => 'int', 'range' => [1, 240]],
				'fh_freshness_min_views'	=> ['type' => 'int', 'range' => [0, 1000000]],
				'fh_solutions_enabled'		=> ['type' => 'bool'],
				'fh_solution_min_confidence'=> ['type' => 'int', 'range' => [30, 100]],
				'fh_recurring_enabled'		=> ['type' => 'bool'],
				'fh_recurring_min_topics'	=> ['type' => 'int', 'range' => [2, 1000]],
			],
			'community' => [
				'fh_community_enabled'		=> ['type' => 'bool'],
				'fh_newuser_reply_hours'	=> ['type' => 'int', 'range' => [1, 8760]],
				'fh_newuser_alert_threshold'=> ['type' => 'int', 'range' => [1, 10000]],
				'fh_activity_drop_percent'	=> ['type' => 'int', 'range' => [1, 99]],
				'fh_trend_period_days'		=> ['type' => 'int', 'range' => [7, 365]],
				'fh_moderator_load_threshold'=> ['type' => 'int', 'range' => [1, 100000]],
			],
			'alerts' => [
				'fh_alerts_enabled'			=> ['type' => 'bool'],
				'fh_alerts_max_per_run'		=> ['type' => 'int', 'range' => [10, 5000]],
				'fh_rules_enabled'			=> ['type' => 'bool'],
			],
			'scoring' => [
				'fh_weight_unanswered'		=> ['type' => 'int', 'range' => [0, 100]],
				'fh_weight_duplicates'		=> ['type' => 'int', 'range' => [0, 100]],
				'fh_weight_links'			=> ['type' => 'int', 'range' => [0, 100]],
				'fh_weight_freshness'		=> ['type' => 'int', 'range' => [0, 100]],
				'fh_weight_solutions'		=> ['type' => 'int', 'range' => [0, 100]],
				'fh_weight_participation'	=> ['type' => 'int', 'range' => [0, 100]],
				'fh_weight_responsiveness'	=> ['type' => 'int', 'range' => [0, 100]],
				'fh_weight_onboarding'		=> ['type' => 'int', 'range' => [0, 100]],
				'fh_weight_retention'		=> ['type' => 'int', 'range' => [0, 100]],
			],
			'privacy' => [
				'fh_privacy_analyse_pms'	=> ['type' => 'bool', 'locked' => true],
				'fh_privacy_user_metrics'	=> ['type' => 'bool'],
			],
			'retention' => [
				'fh_retain_alerts_days'		=> ['type' => 'int', 'range' => [7, 3650]],
				'fh_retain_links_days'		=> ['type' => 'int', 'range' => [7, 3650]],
				'fh_retain_metrics_days'	=> ['type' => 'int', 'range' => [30, 3650]],
				'fh_retain_relations_days'	=> ['type' => 'int', 'range' => [7, 3650]],
			],
		];
	}

	/**
	 * Settings whose change invalidates stored analysis.
	 *
	 * @var string[]
	 */
	protected static $analysis_affecting = [
		'fh_duplicate_threshold',
		'fh_duplicate_high_threshold',
		'fh_duplicate_same_forum_bonus',
		'fh_freshness_months',
		'fh_solution_min_confidence',
		'fh_excluded_forums',
	];

	/**
	 * Render or save the settings.
	 *
	 * @param int    $id   Module id.
	 * @param string $mode Module mode.
	 * @return void
	 */
	public function main($id, $mode)
	{
		$this->boot();
		$this->require_permission('a_fh_manage_content');

		$this->tpl_name = 'acp_fh_settings';
		$this->page_title = 'ACP_FH_SETTINGS';
		$this->assign_common($mode);

		add_form_key('fh_settings');

		if ($this->request->is_set_post('submit'))
		{
			$this->save();
		}

		$this->assign_form();
	}

	/**
	 * Build the form from the schema.
	 *
	 * @return void
	 */
	protected function assign_form()
	{
		foreach ($this->schema() as $group => $entries)
		{
			$this->template->assign_block_vars('fh_group', [
				'KEY'			=> $group,
				'NAME'			=> $this->language->lang('FH_GROUP_' . strtoupper($group)),
				'DESCRIPTION'	=> $this->language->lang('FH_GROUP_' . strtoupper($group) . '_DESC'),
			]);

			foreach ($entries as $key => $meta)
			{
				$type = $meta['type'];

				$this->template->assign_block_vars('fh_group.setting', [
					'KEY'			=> $key,
					'NAME'			=> $this->language->lang(strtoupper($key)),
					'DESCRIPTION'	=> $this->language->lang(strtoupper($key) . '_DESC'),
					'TYPE'			=> $type,
					'S_BOOL'		=> $type === 'bool',
					'S_INT'			=> $type === 'int',
					'S_STRING'		=> $type === 'string',
					'S_TEXT'		=> $type === 'text',
					// A locked setting is displayed so the answer is visible and
					// auditable, but it cannot be changed from this page.
					'S_LOCKED'		=> !empty($meta['locked']),
					'VALUE'			=> $type === 'bool'
										? (int) $this->settings->get_bool($key)
										: ($type === 'int'
											? $this->settings->get_int($key)
											: $this->settings->get_string($key)),
					'MIN'			=> isset($meta['range']) ? (int) $meta['range'][0] : 0,
					'MAX'			=> isset($meta['range']) ? (int) $meta['range'][1] : 0,
				]);
			}
		}

		$this->template->assign_vars([
			'S_SETTINGS'	=> true,
			'FH_VERSION'	=> $this->settings->get_string('fh_version'),
		]);
	}

	/**
	 * Validate and persist the submitted settings.
	 *
	 * @return void
	 */
	protected function save()
	{
		$this->require_form_token('fh_settings');

		$errors = [];
		$changed_analysis = false;

		foreach ($this->schema() as $entries)
		{
			foreach ($entries as $key => $meta)
			{
				if (!empty($meta['locked']))
				{
					// Never read from the request: a locked setting must not be
					// changeable by crafting a field name.
					continue;
				}

				$previous = $this->settings->get_string($key);

				switch ($meta['type'])
				{
					case 'bool':
						$value = $this->request->variable($key, 0) ? 1 : 0;
						break;

					case 'int':
						$value = $this->request->variable($key, 0);
						list($min, $max) = $meta['range'];

						if ($value < $min || $value > $max)
						{
							$errors[] = $this->language->lang(
								'FH_ERR_OUT_OF_RANGE',
								$this->language->lang(strtoupper($key)),
								$min,
								$max
							);

							continue 2;
						}

						break;

					case 'text':
					case 'string':
					default:
						$value = $this->request->variable($key, '', true);
						$value = utf8_substr(trim($value), 0, 2000);
						break;
				}

				if ((string) $previous !== (string) $value)
				{
					if (in_array($key, self::$analysis_affecting, true))
					{
						$changed_analysis = true;
					}

					$this->settings->set($key, $value);
				}
			}
		}

		if (!empty($errors))
		{
			trigger_error(implode('<br>', $errors) . adm_back_link($this->u_action), E_USER_WARNING);
		}

		// A weight set of all zeroes would make the indicator undefined, which
		// the scoring service handles, but warning here is more useful than
		// letting the dashboard go blank.
		if ($this->all_weights_zero())
		{
			$errors[] = $this->language->lang('FH_ERR_ALL_WEIGHTS_ZERO');
		}

		if ($changed_analysis)
		{
			// Stored AI answers were produced under the previous thresholds.
			$this->settings->bump_config_version();
		}

		$this->service('logger')->notice('FH_LOG_SETTINGS_SAVED');

		$message = $this->language->lang('FH_SETTINGS_SAVED');

		if (!empty($errors))
		{
			$message .= '<br>' . implode('<br>', $errors);
		}

		trigger_error($message . adm_back_link($this->u_action));
	}

	/**
	 * Whether every scoring weight is zero.
	 *
	 * @return bool
	 */
	protected function all_weights_zero()
	{
		$total = 0;

		foreach (array_keys($this->schema()['scoring']) as $key)
		{
			$total += $this->settings->get_int($key);
		}

		return $total === 0;
	}
}
