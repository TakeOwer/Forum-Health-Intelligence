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

/**
 * The alert queue and the recommendation list.
 *
 * Alerts are things that are true; recommendations are things worth doing. They
 * live on separate pages because they invite different responses: an alert is
 * triaged, a recommendation is chosen.
 *
 * Triage writes nothing except the alert's own status. Acknowledging an alert
 * about broken links does not touch a link, and dismissing one does not hide the
 * underlying finding from the report it came from.
 */
class alerts_module extends base_module
{
	/** Alerts per page. */
	const PER_PAGE = 25;

	/**
	 * Dispatch to the requested page.
	 *
	 * @param int    $id   Module id.
	 * @param string $mode Module mode.
	 * @return void
	 */
	public function main($id, $mode)
	{
		$this->boot();
		$this->require_permission('a_fh_view');

		$this->assign_common($mode);

		if ($mode === 'recommendations')
		{
			$this->tpl_name = 'acp_fh_recommendations';
			$this->page_title = 'ACP_FH_RECOMMENDATIONS';
			$this->recommendations();

			return;
		}

		$this->tpl_name = 'acp_fh_alerts';
		$this->page_title = 'ACP_FH_ALERTS';
		$this->alerts();
	}

	/**
	 * The alert queue.
	 *
	 * @return void
	 */
	protected function alerts()
	{
		$this->handle_action();

		add_form_key('fh_alerts');

		$start = $this->request->variable('start', 0);
		$status = $this->request->variable('status', '');
		$type = $this->request->variable('type', '');

		$filters = [];

		if (in_array($status, [constants::STATUS_NEW, constants::STATUS_ACKNOWLEDGED, constants::STATUS_RESOLVED, constants::STATUS_DISMISSED], true))
		{
			$filters['status'] = $status;
		}
		else
		{
			// The default view is work still to do, not everything ever found.
			$filters['open_only'] = true;
			$status = '';
		}

		if ($type !== '' && in_array($type, $this->known_types(), true))
		{
			$filters['type'] = $type;
		}
		else
		{
			$type = '';
		}

		$repository = $this->service('repository.alerts');
		$total = $repository->count($filters);
		$rows = $repository->find($filters, $start, self::PER_PAGE);

		foreach ($rows as $row)
		{
			$severity = $this->severity_display($row['severity']);

			$this->template->assign_block_vars('fh_alert', [
				'ID'				=> (int) $row['alert_id'],
				'TYPE'				=> $this->language->lang('FH_ALERT_TYPE_' . strtoupper($row['alert_type'])),
				'TEXT'				=> $this->render_explanation($row),
				'ACTION_TEXT'		=> $row['action_key'] !== '' ? $this->language->lang($row['action_key']) : '',
				'SEVERITY'			=> $severity['label'],
				'SEVERITY_CLASS'	=> $severity['class'],
				'STATUS'			=> $this->language->lang('FH_STATUS_' . strtoupper($row['alert_status'])),
				'STATUS_KEY'		=> $row['alert_status'],
				'SOURCE'			=> $this->language->lang('FH_SOURCE_' . strtoupper($row['source'])),
				'CREATED'			=> $this->user->format_date((int) $row['created_at']),
				'UPDATED'			=> $this->user->format_date((int) $row['updated_at']),
				'S_OPEN'			=> in_array($row['alert_status'], [constants::STATUS_NEW, constants::STATUS_ACKNOWLEDGED], true),
				'U_ENTITY'			=> $row['entity_type'] === 'topic' && (int) $row['entity_id'] > 0
										? $this->topic_url((int) $row['entity_id'])
										: '',
			]);
		}

		$by_severity = $repository->counts_by_severity();
		$by_type = $repository->counts_by_type();

		foreach ($by_type as $alert_type => $count)
		{
			$this->template->assign_block_vars('fh_type_filter', [
				'KEY'		=> $alert_type,
				'NAME'		=> $this->language->lang('FH_ALERT_TYPE_' . strtoupper($alert_type)),
				'COUNT'		=> (int) $count,
				'S_ACTIVE'	=> $type === $alert_type,
			]);
		}

		$this->template->assign_vars([
			'FH_FILTER_STATUS'	=> $status,
			'FH_FILTER_TYPE'	=> $type,
			'FH_COUNT_CRITICAL'	=> isset($by_severity[constants::SEVERITY_CRITICAL]) ? $by_severity[constants::SEVERITY_CRITICAL] : 0,
			'FH_COUNT_HIGH'		=> isset($by_severity[constants::SEVERITY_HIGH]) ? $by_severity[constants::SEVERITY_HIGH] : 0,
			'FH_COUNT_MEDIUM'	=> isset($by_severity[constants::SEVERITY_MEDIUM]) ? $by_severity[constants::SEVERITY_MEDIUM] : 0,
			'FH_COUNT_LOW'		=> isset($by_severity[constants::SEVERITY_LOW]) ? $by_severity[constants::SEVERITY_LOW] : 0,
		]);

		$this->assign_pagination(
			$total,
			self::PER_PAGE,
			$start,
			$this->u_action . '&amp;status=' . urlencode($status) . '&amp;type=' . urlencode($type)
		);
	}

	/**
	 * The recommendation list.
	 *
	 * @return void
	 */
	protected function recommendations()
	{
		$recommendations = $this->service('alerts.recommendations')->build(10);

		foreach ($recommendations as $recommendation)
		{
			$this->template->assign_block_vars('fh_recommendation', [
				'TEXT'		=> $this->language->lang(
                    $recommendation['key'],
                    ...array_values($recommendation['params'])
                ),
				'ACTION'	=> $this->language->lang($recommendation['action']),
				'U_ACTION'	=> $this->module_url($recommendation['module'], $recommendation['mode']),
				'PRIORITY'	=> (int) $recommendation['priority'],
			]);
		}

		$workload = $this->service('alerts.manager')->pending_workload();

		$this->template->assign_vars([
			'S_HAS_ITEMS'			=> !empty($recommendations),
			'FH_LOAD_TOTAL_TEXT'	=> $this->language->lang('FH_LOAD_TOTAL', $workload['total']),
			'FH_LOAD_DUPLICATES'	=> $workload['duplicates'],
			'FH_LOAD_LINKS'			=> $workload['links'],
			'FH_LOAD_UNANSWERED'	=> $workload['unanswered'],
			'FH_LOAD_OUTDATED'		=> $workload['outdated'],
		]);
	}

	/**
	 * Apply a triage decision.
	 *
	 * @return void
	 */
	protected function handle_action()
	{
		$action = $this->request->variable('action', '');

		if ($action === '')
		{
			return;
		}

		$this->require_permission('a_fh_manage');
		$this->require_form_token('fh_alerts');

		$map = [
			'acknowledge'	=> constants::STATUS_ACKNOWLEDGED,
			'resolve'		=> constants::STATUS_RESOLVED,
			'dismiss'		=> constants::STATUS_DISMISSED,
		];

		if (!isset($map[$action]))
		{
			trigger_error($this->language->lang('FH_ERR_UNKNOWN_ACTION') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$alert_ids = $this->request->variable('alert_ids', [0]);
		$single = $this->request->variable('alert_id', 0);

		if ($single > 0)
		{
			$alert_ids[] = $single;
		}

		$alert_ids = array_filter(array_map('intval', (array) $alert_ids));

		if (empty($alert_ids))
		{
			trigger_error($this->language->lang('FH_ERR_NO_SELECTION') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$repository = $this->service('repository.alerts');
		$changed = 0;

		// Bulk triage is bounded so a crafted request cannot rewrite the whole
		// table in one call.
		foreach (array_slice($alert_ids, 0, 200) as $alert_id)
		{
			if ($repository->set_status($alert_id, $map[$action]))
			{
				$changed++;
			}
		}

		trigger_error($this->language->lang('FH_ALERTS_UPDATED', $changed) . adm_back_link($this->u_action));
	}

	/**
	 * Render an alert's explanation from its key and stored parameters.
	 *
	 * @param array $alert Alert row with decoded explain_data.
	 * @return string
	 */
	protected function render_explanation(array $alert)
	{
		$data = is_array($alert['explain_data']) ? $alert['explain_data'] : [];

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
	 * Alert types this build knows how to name.
	 *
	 * @return string[]
	 */
	protected function known_types()
	{
		return [
			constants::ALERT_DUPLICATE,
			constants::ALERT_UNANSWERED,
			constants::ALERT_BROKEN_LINK,
			constants::ALERT_OUTDATED,
			constants::ALERT_SOLUTION,
			constants::ALERT_KNOWLEDGE,
			constants::ALERT_RECURRING,
			constants::ALERT_ACTIVITY_DROP,
			constants::ALERT_ONBOARDING,
			constants::ALERT_MODERATOR_LOAD,
			constants::ALERT_INTEGRATION_FAILURE,
			constants::ALERT_RULE,
		];
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
