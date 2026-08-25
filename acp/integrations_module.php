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
 * The integration centre, the AI settings, and the job status page.
 *
 * The integration page has an unusual job: most of the time it explains why
 * something is not connected. Five states are distinguished, and the difference
 * between them is the difference between an administrator who knows what to do
 * next and one who is staring at the word "unavailable".
 *
 * The AI page is where money gets spent, so it shows the budget, the cache hit
 * situation and a per-capability switch rather than a single on/off. Turning AI
 * off here guarantees no request is made by anything in this extension.
 */
class integrations_module extends base_module
{
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
		$this->assign_common($mode);

		switch ($mode)
		{
			case 'ai':
				$this->require_permission('a_fh_manage_ai');
				$this->tpl_name = 'acp_fh_ai';
				$this->page_title = 'ACP_FH_AI';
				$this->ai();
				break;

			case 'jobs':
				$this->require_permission('a_fh_view');
				$this->tpl_name = 'acp_fh_jobs';
				$this->page_title = 'ACP_FH_JOBS';
				$this->jobs();
				break;

			case 'integrations':
			default:
				$this->require_permission('a_fh_manage_integrations');
				$this->tpl_name = 'acp_fh_integrations';
				$this->page_title = 'ACP_FH_INTEGRATIONS';
				$this->integrations();
				break;
		}
	}

	/**
	 * The integration centre.
	 *
	 * @return void
	 */
	protected function integrations()
	{
		add_form_key('fh_integrations');

		if ($this->request->is_set_post('submit'))
		{
			$this->save_integrations();
		}

		if ($this->request->variable('action', '') === 'refresh')
		{
			$this->service('integrations.registry')->refresh();

			trigger_error($this->language->lang('FH_INT_REFRESHED') . adm_back_link($this->u_action));
		}

		$registry = $this->service('integrations.registry');

		$this->assign_integration('search', $registry->search_status(), $registry->candidate_extensions('search'));
		$this->assign_integration('ai', $registry->ai_status(), $registry->candidate_extensions('ai'));

		$this->template->assign_vars([
			'S_SEARCH_ON'		=> $this->settings->get_bool('fh_meilisearch_enabled'),
			'S_AI_ON'			=> $this->settings->get_bool('fh_ai_enabled'),
			'FH_SEARCH_SERVICE'	=> $this->settings->get_string('fh_meilisearch_service'),
			'FH_AI_SERVICE'		=> $this->settings->get_string('fh_ai_service'),
			'FH_SEARCH_CHECKED_TEXT'	=> $this->language->lang('FH_INT_LAST_CHECKED', $this->format_checked('fh_meilisearch_checked')),
			'FH_AI_CHECKED_TEXT'		=> $this->language->lang('FH_INT_LAST_CHECKED', $this->format_checked('fh_ai_checked')),
			'U_REFRESH'			=> $this->u_action . '&amp;action=refresh',
		]);
	}

	/**
	 * Assign one integration panel.
	 *
	 * @param string   $kind       search or ai.
	 * @param array    $status     Registry status.
	 * @param string[] $candidates Extensions that look relevant.
	 * @return void
	 */
	protected function assign_integration($kind, array $status, array $candidates)
	{
		$prefix = strtoupper($kind);
		$state = $status['state'];

		$this->template->assign_vars([
			'FH_' . $prefix . '_STATE'			=> $this->language->lang('FH_INT_STATE_' . strtoupper($state)),
			'FH_' . $prefix . '_STATE_KEY'		=> $state,
			'FH_' . $prefix . '_STATE_CLASS'	=> 'fh-int-' . str_replace('_', '-', $state),
			// The advice line is what turns a status into an instruction.
			'FH_' . $prefix . '_ADVICE'			=> $this->language->lang('FH_INT_ADVICE_' . strtoupper($state)),
			'FH_' . $prefix . '_EXTENSION'		=> $status['extension'],
			'FH_' . $prefix . '_DESCRIPTION'	=> $status['description'],
			// Each of these strings carries a placeholder, so it is composed
			// here; printing the key directly would show a bare %s or %d.
			'FH_' . $prefix . '_PROVIDER_TEXT'	=> $this->language->lang('FH_INT_PROVIDER', (string) $status['description']),
			'FH_' . $prefix . '_FAILURES_TEXT'	=> $this->language->lang('FH_INT_FAILURES', (int) $status['failures']),
			'FH_' . $prefix . '_FAILURES'		=> (int) $status['failures'],
			'S_' . $prefix . '_OPERATIONAL'		=> $state === constants::INT_OPERATIONAL,
			'S_' . $prefix . '_BOUND'			=> (bool) $status['bound'],
			'S_' . $prefix . '_CANDIDATES'		=> !empty($candidates),
			'FH_' . $prefix . '_CANDIDATES_TEXT'=> $this->language->lang('FH_INT_CANDIDATES', implode(', ', $candidates)),
		]);
	}

	/**
	 * Persist the integration bindings.
	 *
	 * @return void
	 */
	protected function save_integrations()
	{
		$this->require_form_token('fh_integrations');

		$search_service = trim($this->request->variable('search_service', ''));
		$ai_service = trim($this->request->variable('ai_service', ''));

		// A service id is an identifier, not free text. Anything outside this
		// shape cannot name a real service and is rejected rather than stored.
		foreach ([$search_service, $ai_service] as $candidate)
		{
			if ($candidate !== '' && !preg_match('/^[A-Za-z0-9_.\\\\-]{3,255}$/', $candidate))
			{
				trigger_error($this->language->lang('FH_ERR_SERVICE_ID') . adm_back_link($this->u_action), E_USER_WARNING);
			}
		}

		$previous_ai = $this->settings->get_string('fh_ai_service');

		$this->settings->set('fh_meilisearch_service', $search_service);
		$this->settings->set('fh_ai_service', $ai_service);
		$this->settings->set('fh_meilisearch_enabled', $this->request->variable('search_enabled', 0));
		$this->settings->set('fh_ai_enabled', $this->request->variable('ai_enabled', 0));

		// Failure counters describe the previous binding and would be misleading
		// against a new one.
		$this->settings->set('fh_meilisearch_failures', 0);
		$this->settings->set('fh_ai_failures', 0);

		// Cached answers came from the old provider and must not be attributed
		// to the new one.
		if ($previous_ai !== $ai_service)
		{
			$this->service('integrations.ai_cache')->clear();
		}

		$this->service('integrations.registry')->refresh();
		$this->service('logger')->notice('FH_LOG_INTEGRATIONS_SAVED');

		trigger_error($this->language->lang('FH_INT_SAVED') . adm_back_link($this->u_action));
	}

	/**
	 * The AI page.
	 *
	 * @return void
	 */
	protected function ai()
	{
		add_form_key('fh_ai');

		if ($this->request->is_set_post('submit'))
		{
			$this->save_ai();
		}

		if ($this->request->variable('action', '') === 'clear_cache')
		{
			$this->require_form_token('fh_ai');
			$this->service('integrations.ai_cache')->clear();

			trigger_error($this->language->lang('FH_AI_CACHE_CLEARED') . adm_back_link($this->u_action));
		}

		$adapter = $this->service('integrations.ai');
		$status = $this->service('integrations.registry')->ai_status();
		$remaining = $adapter->budget_remaining();

		$this->template->assign_vars([
			'S_AI_ON'				=> $this->settings->get_bool('fh_ai_enabled'),
			'S_AI_AVAILABLE'		=> $adapter->is_available(),
			'FH_AI_STATE'			=> $this->language->lang('FH_INT_STATE_' . strtoupper($status['state'])),
			'FH_AI_DESCRIPTION'		=> $status['description'],
			'FH_AI_DAILY_LIMIT'		=> $this->settings->get_int('fh_ai_daily_limit'),
			'FH_AI_BOT_ID'			=> $this->settings->get_int('fh_ai_bot_id'),
			'FH_AI_USED_TODAY'		=> $adapter->used_today(),
			'FH_AI_REMAINING'		=> $remaining,
			'S_AI_UNLIMITED'		=> $remaining === -1,
			'FH_AI_CACHE_SIZE'		=> $this->service('integrations.ai_cache')->count(),
			'FH_AI_CACHE_DAYS'		=> $this->settings->get_int('fh_ai_cache_days'),
			'FH_AI_MIN_CANDIDATE'	=> $this->settings->get_int('fh_ai_min_candidate_conf'),
			'S_AI_SEND_CONTENT'		=> $this->settings->get_bool('fh_privacy_send_content_to_ai'),
			'S_AI_F_DUPLICATES'		=> $this->settings->get_bool('fh_ai_feature_duplicates'),
			'S_AI_F_SOLUTIONS'		=> $this->settings->get_bool('fh_ai_feature_solutions'),
			'S_AI_F_FRESHNESS'		=> $this->settings->get_bool('fh_ai_feature_freshness'),
			'S_AI_F_KNOWLEDGE'		=> $this->settings->get_bool('fh_ai_feature_knowledge'),
			'S_AI_F_CONFLICTS'		=> $this->settings->get_bool('fh_ai_feature_conflicts'),
			'U_CLEAR_CACHE'			=> $this->u_action . '&amp;action=clear_cache',
		]);
	}

	/**
	 * Persist the AI settings.
	 *
	 * @return void
	 */
	protected function save_ai()
	{
		$this->require_form_token('fh_ai');

		$this->settings->set('fh_ai_enabled', $this->request->variable('ai_enabled', 0));
		$this->settings->set('fh_ai_daily_limit', max(0, min(100000, $this->request->variable('daily_limit', 200))));

		// The bot supplies the key, model and endpoint. Changing it changes what
		// a cached answer means, which bump_config_version() below invalidates.
		$this->settings->set('fh_ai_bot_id', max(0, $this->request->variable('bot_id', 0)));
		$this->settings->set('fh_ai_cache_days', max(1, min(365, $this->request->variable('cache_days', 30))));
		$this->settings->set('fh_ai_min_candidate_conf', max(0, min(100, $this->request->variable('min_candidate', 50))));

		// The privacy switch decides whether post bodies leave the forum, so it
		// is recorded in the admin log even at low verbosity.
		$send_content = $this->request->variable('send_content', 0);

		if ((int) $send_content !== (int) $this->settings->get_bool('fh_privacy_send_content_to_ai'))
		{
			$this->service('logger')->notice(
				$send_content ? 'FH_LOG_AI_CONTENT_ENABLED' : 'FH_LOG_AI_CONTENT_DISABLED'
			);
		}

		$this->settings->set('fh_privacy_send_content_to_ai', $send_content);

		foreach (['duplicates', 'solutions', 'freshness', 'knowledge', 'conflicts'] as $feature)
		{
			$this->settings->set('fh_ai_feature_' . $feature, $this->request->variable('feature_' . $feature, 0));
		}

		// These settings change what a cached answer means, so anything cached
		// under the old configuration is invalidated.
		$this->settings->bump_config_version();

		trigger_error($this->language->lang('FH_AI_SAVED') . adm_back_link($this->u_action));
	}

	/**
	 * The job status page.
	 *
	 * @return void
	 */
	protected function jobs()
	{
		$jobs = $this->service('repository.jobs')->all();

		foreach ($jobs as $job)
		{
			$this->template->assign_block_vars('fh_job', [
				'NAME'			=> $this->language->lang('FH_JOB_' . strtoupper($job['job_name'])),
				'DESCRIPTION'	=> $this->language->lang('FH_JOB_' . strtoupper($job['job_name']) . '_DESC'),
				'STATE'			=> $this->language->lang('FH_JOB_STATE_' . strtoupper($job['job_state'])),
				'STATE_CLASS'	=> 'fh-job-' . $job['job_state'],
				'LAST_RUN'		=> (int) $job['last_run'] > 0
									? $this->user->format_date((int) $job['last_run'])
									: $this->language->lang('FH_NEVER'),
				'DURATION'		=> (int) $job['last_duration'],
				'PROCESSED'		=> (int) $job['processed'],
				'CURSOR'		=> (int) $job['cursor_value'],
				'S_RUNNING'		=> $job['job_state'] === constants::JOB_RUNNING,
				'S_DISABLED'	=> $job['job_state'] === constants::JOB_DISABLED,
				'MESSAGE'		=> $this->job_message($job['last_message']),
			]);
		}

		$coverage = $this->service('content.analyser')->coverage();

		$this->template->assign_vars([
			'FH_COVERAGE_PERCENT'	=> (int) $coverage['percent'],
			'FH_COVERAGE_ANALYSED'	=> $this->format_number((int) $coverage['analysed']),
			'FH_COVERAGE_TOTAL'		=> $this->format_number((int) $coverage['total']),
			'S_BACKGROUND_ON'		=> $this->settings->feature_enabled('background'),
			'FH_BATCH_SIZE'			=> $this->settings->get_int('fh_batch_size'),
		]);
	}

	/**
	 * Render a stored job message.
	 *
	 * Messages are either a language key or a short technical token; both are
	 * displayed, but only the former is translated.
	 *
	 * @param string $message Stored message.
	 * @return string
	 */
	protected function job_message($message)
	{
		$message = (string) $message;

		if ($message === '')
		{
			return '';
		}

		return strpos($message, 'FH_') === 0 ? $this->language->lang($message) : $message;
	}

	/**
	 * Format the last detection time of an integration.
	 *
	 * @param string $key Configuration key holding the timestamp.
	 * @return string
	 */
	protected function format_checked($key)
	{
		$time = (int) $this->settings->get_string($key);

		return $time > 0 ? $this->user->format_date($time) : $this->language->lang('FH_NEVER');
	}
}
