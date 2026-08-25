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
 * The five content health reports.
 *
 * Each mode is a paginated list of prepared findings with the evidence beside
 * them, and each row offers the same shape of choice: look at it, act on it, or
 * dismiss it. Nothing on these pages changes a topic or a post. The strongest
 * action available is to record a moderator's judgement about a finding, which
 * is why "mark as duplicate" here means "note that these two are duplicates",
 * not "merge them".
 */
class content_module extends base_module
{
	/** Rows per page across the content reports. */
	const PER_PAGE = 25;

	/**
	 * Dispatch to the requested report.
	 *
	 * @param int    $id   Module id.
	 * @param string $mode Module mode.
	 * @return void
	 */
	public function main($id, $mode)
	{
		$this->boot();
		$this->require_permission('a_fh_view');

		$this->page_title = 'ACP_FH_' . strtoupper($mode);
		$this->assign_common($mode);

		switch ($mode)
		{
			case 'duplicates':
				$this->tpl_name = 'acp_fh_duplicates';
				$this->duplicates();
				break;

			case 'links':
				$this->tpl_name = 'acp_fh_links';
				$this->links();
				break;

			case 'freshness':
				$this->tpl_name = 'acp_fh_freshness';
				$this->freshness();
				break;

			case 'solutions':
				$this->tpl_name = 'acp_fh_solutions';
				$this->solutions();
				break;

			case 'unanswered':
			default:
				$this->tpl_name = 'acp_fh_unanswered';
				$this->unanswered();
				break;
		}
	}

	/**
	 * Popular discussions nobody has answered.
	 *
	 * @return void
	 */
	protected function unanswered()
	{
		$start = $this->request->variable('start', 0);
		$forum_id = $this->request->variable('f', 0);
		$hours = $this->request->variable('hours', $this->settings->get_int('fh_unanswered_hours'));

		// The filter is a view preference, not a stored setting, so it is
		// clamped here rather than trusted.
		$hours = max(1, min(8760, $hours));

		$min_views = $this->settings->get_int('fh_unanswered_min_views');
		$older_than = time() - ($hours * 3600);
		$newer_than = time() - ($this->settings->get_int('fh_unanswered_max_age_days') * 86400);

		$repository = $this->service('repository.topics');
		$total = $repository->count_unanswered($min_views, $older_than, $newer_than, $forum_id);
		$rows = $repository->unanswered($min_views, $older_than, $newer_than, $start, self::PER_PAGE, $forum_id);

		foreach ($rows as $row)
		{
			$this->template->assign_block_vars('fh_topic', [
				'TITLE'			=> $row['topic_title'],
				'VIEWS'			=> (int) $row['topic_views'],
				'AGE'			=> $this->user->format_date((int) $row['topic_time']),
				'FORUM'			=> $this->forum_name((int) $row['forum_id']),
				'S_FIRST_TOPIC'	=> !empty($row['is_first_topic']),
				'U_TOPIC'		=> $this->topic_url((int) $row['topic_id']),
			]);
		}

		$this->template->assign_vars([
			'FH_FILTER_HOURS'		=> $hours,
			'FH_FILTER_FORUM'		=> $forum_id,
			'FH_MIN_VIEWS_NOTE_TEXT'=> $this->language->lang('FH_MIN_VIEWS_NOTE', $min_views),
			'S_FORUM_OPTIONS'		=> $this->forum_options($forum_id),
		]);

		$this->assign_pagination($total, self::PER_PAGE, $start, $this->u_action . '&amp;hours=' . $hours);
	}

	/**
	 * Possible duplicate pairs awaiting review.
	 *
	 * @return void
	 */
	protected function duplicates()
	{
		$this->handle_relation_action();

		$start = $this->request->variable('start', 0);
		$status = $this->request->variable('status', constants::RELATION_NEW);

		if (!in_array($status, [constants::RELATION_NEW, constants::RELATION_CONFIRMED, constants::RELATION_DISMISSED], true))
		{
			$status = constants::RELATION_NEW;
		}

		$filters = [
			'status'			=> $status,
			'min_confidence'	=> $this->settings->get_int('fh_duplicate_threshold'),
		];

		$repository = $this->service('repository.relations');
		$total = $repository->count($filters);
		$rows = $repository->find($filters, $start, self::PER_PAGE);
		$high = $this->settings->get_int('fh_duplicate_high_threshold');

		foreach ($rows as $row)
		{
			$this->template->assign_block_vars('fh_pair', [
				'ID'				=> (int) $row['relation_id'],
				'TITLE_A'			=> $row['topic_title'],
				'TITLE_B'			=> $row['related_title'],
				'U_TOPIC_A'			=> $this->topic_url((int) $row['topic_id']),
				'U_TOPIC_B'			=> $this->topic_url((int) $row['related_topic_id']),
				'CONFIDENCE'		=> (int) $row['confidence'],
				// A word, not just a number and a colour.
				'CONFIDENCE_LABEL'	=> $this->confidence_label((int) $row['confidence'], $high),
				'SOURCE'			=> $this->language->lang('FH_SOURCE_' . strtoupper($row['source'])),
				'REASONS'			=> $this->render_reasons($row['reasons']),
				'DATE'				=> $this->user->format_date((int) $row['created_at']),
			]);
		}

		$this->template->assign_vars([
			'FH_FILTER_STATUS'	=> $status,
			'S_FH_CAN_MANAGE'	=> $this->auth->acl_get('a_fh_manage'),
			'FH_FORM_KEY'		=> 'fh_relations',
		]);

		add_form_key('fh_relations');
		$this->assign_pagination($total, self::PER_PAGE, $start, $this->u_action . '&amp;status=' . $status);
	}

	/**
	 * Links that no longer resolve.
	 *
	 * @return void
	 */
	protected function links()
	{
		if (!$this->settings->feature_enabled('links'))
		{
			// The report still opens, but says plainly why it is empty rather
			// than showing an empty table with no explanation.
			$this->template->assign_var('S_FH_LINKS_DISABLED', true);
		}

		$start = $this->request->variable('start', 0);
		$state = $this->request->variable('state', '');

		$valid_states = [
			constants::LINK_BROKEN,
			constants::LINK_WARNING,
			constants::LINK_OK,
			constants::LINK_UNSAFE,
			constants::LINK_SKIPPED,
		];

		$filters = in_array($state, $valid_states, true)
			? ['state' => $state]
			: ['problems_only' => true];

		$repository = $this->service('repository.links');
		$total = $repository->count($filters);
		$rows = $repository->find($filters, $start, self::PER_PAGE);

		foreach ($rows as $row)
		{
			$occurrences = $repository->occurrences((int) $row['link_id'], 3);
			$block = 'fh_link';

			$this->template->assign_block_vars($block, [
				'URL'			=> $row['url'],
				'HOST'			=> $row['url_host'],
				'STATUS'		=> (int) $row['status_code'],
				'STATE'			=> $this->language->lang('FH_LINK_STATE_' . strtoupper($row['link_state'])),
				'STATE_CLASS'	=> 'fh-link-' . $row['link_state'],
				'OCCURRENCES'	=> (int) $row['occurrences'],
				'CHECKED'		=> (int) $row['last_checked'] > 0
									? $this->user->format_date((int) $row['last_checked'])
									: $this->language->lang('FH_NEVER'),
				// A repeated failure is what turns a warning into a verdict, so
				// the count is shown rather than hidden.
				'FAILURES'		=> (int) $row['fail_count'],
				'FAILURES_TEXT'	=> $this->language->lang('FH_FAILURES_COUNT', (int) $row['fail_count']),
			]);

			foreach ($occurrences as $occurrence)
			{
				$this->template->assign_block_vars($block . '.topic', [
					'TITLE'		=> $occurrence['topic_title'],
					'U_TOPIC'	=> $this->topic_url((int) $occurrence['topic_id'], (int) $occurrence['post_id']),
				]);
			}
		}

		$counts = $repository->counts_by_state();

		$this->template->assign_vars([
			'FH_FILTER_STATE'	=> $state,
			'FH_COUNT_BROKEN'	=> isset($counts[constants::LINK_BROKEN]) ? $counts[constants::LINK_BROKEN] : 0,
			'FH_COUNT_WARNING'	=> isset($counts[constants::LINK_WARNING]) ? $counts[constants::LINK_WARNING] : 0,
			'FH_COUNT_OK'		=> isset($counts[constants::LINK_OK]) ? $counts[constants::LINK_OK] : 0,
			'FH_COUNT_PENDING'	=> isset($counts[constants::LINK_PENDING]) ? $counts[constants::LINK_PENDING] : 0,
			'FH_COUNT_UNSAFE'	=> isset($counts[constants::LINK_UNSAFE]) ? $counts[constants::LINK_UNSAFE] : 0,
		]);

		$this->assign_pagination($total, self::PER_PAGE, $start, $this->u_action . '&amp;state=' . urlencode($state));
	}

	/**
	 * Content that may deserve a review.
	 *
	 * @return void
	 */
	protected function freshness()
	{
		$start = $this->request->variable('start', 0);
		$months = $this->settings->get_int('fh_freshness_months');
		$before = time() - ($months * 30 * 86400);
		$min_views = $this->settings->get_int('fh_freshness_min_views');

		$repository = $this->service('repository.topics');
		$rows = $repository->stale_topics($before, $min_views, $start, self::PER_PAGE);
		$total = $repository->summary_counts()['stale'];

		foreach ($rows as $row)
		{
			$this->template->assign_block_vars('fh_topic', [
				'TITLE'			=> $row['topic_title'],
				'VIEWS'			=> (int) $row['topic_views'],
				'LAST_POST'		=> $this->user->format_date((int) $row['last_post_time']),
				'CONFIDENCE'	=> (int) $row['freshness_conf'],
				// The reason is the whole value of this report: "old" alone is
				// not a finding.
				'REASON'		=> $row['freshness_reason'] !== ''
									? $this->language->lang('FH_FRESH_REASON_' . strtoupper($row['freshness_reason']))
									: '',
				'U_TOPIC'		=> $this->topic_url((int) $row['topic_id']),
			]);
		}

		$this->template->assign_vars([
			'FH_FRESHNESS_MONTHS'	=> $months,
			'S_FH_DISABLED'			=> !$this->settings->feature_enabled('freshness'),
		]);

		$this->assign_pagination($total, self::PER_PAGE, $start, $this->u_action);
	}

	/**
	 * Discussions that appear to contain a solution.
	 *
	 * @return void
	 */
	protected function solutions()
	{
		$start = $this->request->variable('start', 0);
		$minimum = $this->settings->get_int('fh_solution_min_confidence');

		$repository = $this->service('repository.topics');
		$rows = $repository->solution_candidates($minimum, $start, self::PER_PAGE);
		$total = $repository->summary_counts()['solved'];

		foreach ($rows as $row)
		{
			$this->template->assign_block_vars('fh_topic', [
				'TITLE'			=> $row['topic_title'],
				'CONFIDENCE'	=> (int) $row['solution_conf'],
				'U_TOPIC'		=> $this->topic_url((int) $row['topic_id']),
				'U_POST'		=> $this->topic_url((int) $row['topic_id'], (int) $row['solution_post_id']),
				'POST_ID'		=> (int) $row['solution_post_id'],
			]);
		}

		$this->template->assign_vars([
			'S_FH_DISABLED'		=> !$this->settings->feature_enabled('solutions'),
			'FH_MIN_CONFIDENCE'	=> $minimum,
		]);

		$this->assign_pagination($total, self::PER_PAGE, $start, $this->u_action);
	}

	/**
	 * Apply a moderator's decision about a duplicate pair.
	 *
	 * @return void
	 */
	protected function handle_relation_action()
	{
		$action = $this->request->variable('action', '');

		if ($action === '')
		{
			return;
		}

		$this->require_permission('a_fh_manage');
		$this->require_form_token('fh_relations');

		$relation_id = $this->request->variable('relation_id', 0);

		if ($relation_id <= 0)
		{
			return;
		}

		$status = ($action === 'confirm') ? constants::RELATION_CONFIRMED : constants::RELATION_DISMISSED;

		if (!in_array($action, ['confirm', 'dismiss'], true))
		{
			trigger_error($this->language->lang('FH_ERR_UNKNOWN_ACTION') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$this->service('repository.relations')->set_status($relation_id, $status);

		trigger_error($this->language->lang('FH_RELATION_UPDATED') . adm_back_link($this->u_action));
	}

	/**
	 * Turn stored reason codes into one readable line.
	 *
	 * Returned as a joined string rather than an array: phpBB's template engine
	 * can only iterate assigned blocks, not plain arrays, and a block per reason
	 * would be a lot of machinery for three short phrases.
	 *
	 * @param array $reasons Reason codes.
	 * @return string
	 */
	protected function render_reasons($reasons)
	{
		$out = [];

		foreach ((array) $reasons as $reason)
		{
			$out[] = $this->language->lang('FH_REASON_' . strtoupper((string) $reason));
		}

		return implode(' · ', $out);
	}

	/**
	 * Describe a confidence value in words.
	 *
	 * @param int $confidence Confidence 0-100.
	 * @param int $high       Threshold for high confidence.
	 * @return string
	 */
	protected function confidence_label($confidence, $high)
	{
		if ($confidence >= $high)
		{
			return $this->language->lang('FH_CONFIDENCE_HIGH');
		}

		if ($confidence >= ($high - 15))
		{
			return $this->language->lang('FH_CONFIDENCE_MEDIUM');
		}

		return $this->language->lang('FH_CONFIDENCE_LOW');
	}

	/**
	 * Name of a forum, resolved once per request.
	 *
	 * @param int $forum_id Forum id.
	 * @return string
	 */
	protected function forum_name($forum_id)
	{
		static $names = null;

		if ($names === null)
		{
			$names = [];
			$db = $this->container->get('dbal.conn');
			$result = $db->sql_query('SELECT forum_id, forum_name FROM ' . FORUMS_TABLE);

			while ($row = $db->sql_fetchrow($result))
			{
				$names[(int) $row['forum_id']] = $row['forum_name'];
			}

			$db->sql_freeresult($result);
		}

		return isset($names[(int) $forum_id]) ? $names[(int) $forum_id] : '';
	}

	/**
	 * Forum select options for the filter.
	 *
	 * @param int $selected Currently selected forum id.
	 * @return string
	 */
	protected function forum_options($selected)
	{
		return make_forum_select($selected, false, false, true);
	}
}
