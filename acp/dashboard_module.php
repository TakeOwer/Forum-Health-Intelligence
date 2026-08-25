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

use salvocortesiano\forumhealth\constants;
use salvocortesiano\forumhealth\service\community\community_analyser;

/**
 * The page an administrator opens first.
 *
 * Its job is to answer one question in a single screen: what deserves attention
 * today? Three indicators, the open alerts that matter, the trends that changed,
 * the state of the optional integrations, and a short list of suggested actions.
 *
 * Every figure on it was computed by a background job and is read from a table.
 * Nothing here queries the forum's content, calls a search server or an AI
 * provider, or performs analysis of any kind.
 */
class dashboard_module extends base_module
{
	/**
	 * Render the dashboard.
	 *
	 * @param int    $id   Module id.
	 * @param string $mode Module mode.
	 * @return void
	 */
	public function main($id, $mode)
	{
		$this->boot();
		$this->require_permission('a_fh_view');

		$this->tpl_name = 'acp_fh_dashboard';
		$this->page_title = 'ACP_FH_DASHBOARD';
		$this->assign_common($mode);

		$scores = $this->service('scoring.health')->calculate();

		$this->assign_scores($scores);
		$this->assign_alerts();
		$this->assign_trends();
		$this->assign_recommendations();
		$this->assign_integrations();
		$this->assign_jobs();

		$workload = $this->service('alerts.manager')->pending_workload();

		$this->template->assign_vars([
			'FH_LOAD_DUPLICATES'	=> $workload['duplicates'],
			'FH_LOAD_LINKS'			=> $workload['links'],
			'FH_LOAD_UNANSWERED'	=> $workload['unanswered'],
			'FH_LOAD_OUTDATED'		=> $workload['outdated'],
			// Rendered here rather than printed as {L_FH_LOAD_TOTAL}: a language
			// string carrying a printf placeholder has to be given its argument
			// in PHP, or the template shows the raw %d.
			'FH_LOAD_TOTAL_TEXT'	=> $this->language->lang('FH_LOAD_TOTAL', $workload['total']),
		]);
	}

	/**
	 * Assign the three indicators and their factor breakdowns.
	 *
	 * @param array $scores Result of the scoring service.
	 * @return void
	 */
	protected function assign_scores(array $scores)
	{
		foreach (['content', 'community', 'overall'] as $key)
		{
			$this->template->assign_vars([
				'FH_SCORE_' . strtoupper($key)			=> (int) $scores[$key]['score'],
				'S_FH_SCORE_' . strtoupper($key) . '_OK'	=> (bool) $scores[$key]['available'],
			]);
		}

		// The factors are what make a score defensible rather than decorative,
		// so they are always sent to the template, not hidden behind a toggle.
		foreach (['content', 'community'] as $key)
		{
			if (empty($scores[$key]['factors']))
			{
				continue;
			}

			foreach ($scores[$key]['factors'] as $factor)
			{
				$this->template->assign_block_vars('fh_factor_' . $key, [
					'NAME'		=> $this->language->lang($factor['key']),
					'DETAIL'	=> $this->language->lang(
                    $factor['key'] . '_DETAIL',
                    ...array_values($factor['data'])
                ),
					'SCORE'		=> (int) $factor['score'],
					'WEIGHT_TEXT'=> $this->language->lang('FH_FACTOR_WEIGHT', (int) $factor['weight']),
					'S_POSITIVE'=> (bool) $factor['positive'],
				]);
			}
		}

		$this->template->assign_vars([
			'FH_CONTENT_REASON'		=> isset($scores['content']['reason'])
										? $this->language->lang($scores['content']['reason']) : '',
			'FH_COMMUNITY_REASON'	=> isset($scores['community']['reason'])
										? $this->language->lang($scores['community']['reason']) : '',
		]);
	}

	/**
	 * Assign the highest priority open alerts.
	 *
	 * @return void
	 */
	protected function assign_alerts()
	{
		$alerts = $this->service('repository.alerts')->find([
			'open_only'		=> true,
			'min_severity'	=> constants::SEVERITY_MEDIUM,
		], 0, 6);

		foreach ($alerts as $alert)
		{
			$severity = $this->severity_display($alert['severity']);

			$this->template->assign_block_vars('fh_alert', [
				'ID'			=> (int) $alert['alert_id'],
				'TEXT'			=> $this->render_explanation($alert),
				'SEVERITY'		=> $severity['label'],
				'SEVERITY_CLASS'=> $severity['class'],
				'U_VIEW'		=> $this->alert_target($alert),
			]);
		}

		$this->template->assign_vars([
			'S_FH_HAS_ALERTS'	=> !empty($alerts),
			'FH_VIEW_ALL_ALERTS_TEXT'	=> $this->language->lang(
				'FH_VIEW_ALL_ALERTS',
				$this->service('repository.alerts')->count(['open_only' => true])
			),
		]);
	}

	/**
	 * Assign the period-on-period comparisons.
	 *
	 * @return void
	 */
	protected function assign_trends()
	{
		$community = $this->service('community.analyser');

		if (!$community->has_history(14))
		{
			$this->template->assign_var('S_FH_NO_HISTORY', true);

			return;
		}

		$days = $this->settings->get_int('fh_trend_period_days');

		$metrics = [
			community_analyser::M_ACTIVE_POSTERS	=> 'FH_METRIC_ACTIVE_POSTERS',
			community_analyser::M_TOPICS			=> 'FH_METRIC_TOPICS',
			community_analyser::M_POSTS				=> 'FH_METRIC_POSTS',
			community_analyser::M_REGISTRATIONS		=> 'FH_METRIC_REGISTRATIONS',
		];

		foreach ($metrics as $metric => $label)
		{
			$comparison = $community->compare_periods($metric, $days);

			$this->template->assign_block_vars('fh_trend', [
				'NAME'			=> $this->language->lang($label),
				'CURRENT'		=> (int) $comparison['current'],
				'CHANGE'		=> (int) round($comparison['change']),
				'DIRECTION'		=> $comparison['direction'],
				// Without a baseline the percentage is meaningless and is not
				// shown at all rather than shown as zero.
				'S_COMPARABLE'	=> (bool) $comparison['has_baseline'],
			]);
		}

		$this->template->assign_var('FH_TREND_PERIOD_TEXT', $this->language->lang('FH_TREND_PERIOD', $days));
	}

	/**
	 * Assign the recommended actions.
	 *
	 * @return void
	 */
	protected function assign_recommendations()
	{
		$recommendations = $this->service('alerts.recommendations')->build(5);

		foreach ($recommendations as $recommendation)
		{
			$this->template->assign_block_vars('fh_recommendation', [
				'TEXT'		=> $this->language->lang(
                    $recommendation['key'],
                    ...array_values($recommendation['params'])
                ),
				'ACTION'	=> $this->language->lang($recommendation['action']),
				'U_ACTION'	=> $this->module_url($recommendation['module'], $recommendation['mode']),
			]);
		}

		$this->template->assign_var('S_FH_HAS_RECOMMENDATIONS', !empty($recommendations));
	}

	/**
	 * Assign a compact integration status summary.
	 *
	 * @return void
	 */
	protected function assign_integrations()
	{
		$registry = $this->service('integrations.registry');

		foreach (['search' => $registry->search_status(), 'ai' => $registry->ai_status()] as $kind => $status)
		{
			$this->template->assign_block_vars('fh_integration', [
				'NAME'			=> $this->language->lang($kind === 'ai' ? 'FH_INT_AI' : 'FH_INT_SEARCH'),
				'STATE'			=> $this->language->lang('FH_INT_STATE_' . strtoupper($status['state'])),
				'STATE_CLASS'	=> 'fh-int-' . str_replace('_', '-', $status['state']),
				'S_OPERATIONAL'	=> $status['state'] === constants::INT_OPERATIONAL,
			]);
		}
	}

	/**
	 * Assign the freshness of the background analysis.
	 *
	 * An administrator should never have to guess whether the numbers in front
	 * of them are current.
	 *
	 * @return void
	 */
	protected function assign_jobs()
	{
		$job = $this->service('repository.jobs')->get(constants::JOB_CONTENT);
		$coverage = $this->service('content.analyser')->coverage();

		$this->template->assign_vars([
			'FH_LAST_ANALYSIS_TEXT'	=> $this->language->lang(
				'FH_LAST_ANALYSIS',
				(int) $job['last_run'] > 0
					? $this->user->format_date((int) $job['last_run'])
					: $this->language->lang('FH_NEVER')
			),
			'FH_COVERAGE_TEXT'		=> $this->language->lang(
				'FH_COVERAGE',
				$this->format_number((int) $coverage['analysed']),
				(int) $coverage['percent']
			),
			'S_FH_JOB_DISABLED'		=> $job['job_state'] === constants::JOB_DISABLED,
		]);
	}

	/**
	 * Render an alert's explanation from its language key and stored parameters.
	 *
	 * Storing keys and parameters rather than rendered text is what lets the
	 * same alert read correctly in English and Italian.
	 *
	 * @param array $alert Alert row with decoded explain_data.
	 * @return string
	 */
	protected function render_explanation(array $alert)
	{
		$data = is_array($alert['explain_data']) ? $alert['explain_data'] : [];

		// Some parameters are themselves language keys, for instance the name of
		// an integration. They are translated before substitution.
		foreach ($data as $name => $value)
		{
			if (is_string($value) && strpos($value, 'FH_') === 0)
			{
				$data[$name] = $this->language->lang($value);
			}
		}

		return $this->language->lang(
                    $alert['explain_key'],
                    ...array_values($data)
                );
	}

	/**
	 * Where an alert should send the administrator.
	 *
	 * @param array $alert Alert row.
	 * @return string
	 */
	protected function alert_target(array $alert)
	{
		$targets = [
			constants::ALERT_UNANSWERED			=> ['content', 'unanswered'],
			constants::ALERT_DUPLICATE			=> ['content', 'duplicates'],
			constants::ALERT_BROKEN_LINK		=> ['content', 'links'],
			constants::ALERT_OUTDATED			=> ['content', 'freshness'],
			constants::ALERT_SOLUTION			=> ['content', 'solutions'],
			constants::ALERT_ONBOARDING			=> ['community', 'newusers'],
			constants::ALERT_ACTIVITY_DROP		=> ['community', 'trends'],
			constants::ALERT_INTEGRATION_FAILURE=> ['integrations', 'integrations'],
		];

		if ($alert['alert_type'] === constants::ALERT_RULE && (int) $alert['entity_id'] > 0)
		{
			return $this->topic_url((int) $alert['entity_id']);
		}

		if (!isset($targets[$alert['alert_type']]))
		{
			return $this->module_url('alerts', 'alerts');
		}

		return $this->module_url($targets[$alert['alert_type']][0], $targets[$alert['alert_type']][1]);
	}

	/**
	 * Build an ACP link to another module of this extension.
	 *
	 * @param string $module Module name.
	 * @param string $mode   Mode name.
	 * @return string
	 */
	protected function module_url($module, $mode)
	{
		return append_sid(
			'index.php',
			'i=-salvocortesiano-forumhealth-acp-' . $module . '_module&amp;mode=' . $mode,
			true,
			$this->user->session_id
		);
	}
}
