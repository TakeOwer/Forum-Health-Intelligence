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

use salvocortesiano\forumhealth\service\community\community_analyser;

/**
 * The four community health reports.
 *
 * These pages describe the community, never its individual members. The one
 * place a name appears is the contributor list, which counts replies written to
 * other people's discussions: public activity, already visible on every post,
 * shown so that helpful members can be recognised.
 *
 * Where a comparison has no baseline the page says so instead of printing a
 * percentage. A forum that installed this extension last week has no thirty-day
 * trend, and inventing one would be worse than admitting it.
 */
class community_module extends base_module
{
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

		$this->assign_common($mode);

		if (!$this->settings->feature_enabled('community'))
		{
			$this->template->assign_var('S_FH_DISABLED', true);
		}

		switch ($mode)
		{
			case 'newusers':
				$this->tpl_name = 'acp_fh_newusers';
				$this->page_title = 'ACP_FH_NEWUSERS';
				$this->new_users();
				break;

			case 'trends':
				$this->tpl_name = 'acp_fh_trends';
				$this->page_title = 'ACP_FH_TRENDS';
				$this->trends();
				break;

			case 'contributors':
				$this->tpl_name = 'acp_fh_contributors';
				$this->page_title = 'ACP_FH_CONTRIBUTORS';
				$this->contributors();
				break;

			case 'overview':
			default:
				$this->tpl_name = 'acp_fh_community';
				$this->page_title = 'ACP_FH_COMMUNITY_OVERVIEW';
				$this->overview();
				break;
		}
	}

	/**
	 * Headline community figures for the current period.
	 *
	 * @return void
	 */
	protected function overview()
	{
		$analyser = $this->service('community.analyser');
		$days = $this->settings->get_int('fh_trend_period_days');

		if (!$analyser->has_history(7)) {
			$this->template->assign_var('S_FH_NO_HISTORY', true);
		}

		$metrics = [
			community_analyser::M_ACTIVE_POSTERS	=> 'FH_METRIC_ACTIVE_POSTERS',
			community_analyser::M_REGISTRATIONS		=> 'FH_METRIC_REGISTRATIONS',
			community_analyser::M_TOPICS			=> 'FH_METRIC_TOPICS',
			community_analyser::M_POSTS				=> 'FH_METRIC_POSTS',
		];

		foreach ($metrics as $metric => $label)
		{
			$comparison = $analyser->compare_periods($metric, $days);

			$this->template->assign_block_vars('fh_metric', [
				'NAME'			=> $this->language->lang($label),
				'CURRENT'		=> (int) $comparison['current'],
				'PREVIOUS'		=> (int) $comparison['previous'],
				'CHANGE'		=> (int) round($comparison['change']),
				'DIRECTION'		=> $comparison['direction'],
				'S_COMPARABLE'	=> (bool) $comparison['has_baseline'],
			]);
		}

		$response = $analyser->compare_periods(community_analyser::M_RESPONSE_SECONDS, $days);
		$first = $analyser->first_post_experience($days);
		$scores = $this->service('scoring.health')->community_health();

		$this->template->assign_vars([
			'FH_PERIOD_DAYS'		=> $days,
			'FH_RESPONSE_TIME'		=> $this->format_duration((int) $response['current']),
			'S_RESPONSE_KNOWN'		=> $response['current'] > 0,
			'FH_FIRST_TOTAL'		=> $first['total'],
			'FH_FIRST_ANSWERED_PCT'	=> $first['answered_percent'],
			'FH_SCORE_COMMUNITY'	=> (int) $scores['score'],
			'S_SCORE_AVAILABLE'		=> (bool) $scores['available'],
		]);

		foreach ($scores['factors'] as $factor)
		{
			$this->template->assign_block_vars('fh_factor', [
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

	/**
	 * What happens to somebody posting for the first time.
	 *
	 * @return void
	 */
	protected function new_users()
	{
		$analyser = $this->service('community.analyser');
		$days = $this->settings->get_int('fh_trend_period_days');
		$hours = $this->settings->get_int('fh_newuser_reply_hours');
		$stats = $analyser->first_post_experience($days);

		$this->template->assign_vars([
			'FH_PERIOD_DAYS'		=> $days,
			'FH_REPLY_HOURS'		=> $hours,
			'FH_FIRST_TOTAL'		=> $stats['total'],
			'FH_FIRST_ANSWERED'		=> $stats['answered'],
			'FH_FIRST_UNANSWERED'	=> $stats['unanswered'],
			'FH_FIRST_ANSWERED_PCT'	=> $stats['answered_percent'],
			'FH_FIRST_UNANSWERED_PCT'=> 100 - $stats['answered_percent'],
			'FH_AVG_RESPONSE'		=> $this->format_duration($stats['avg_seconds']),
			'S_RESPONSE_KNOWN'		=> $stats['avg_seconds'] > 0,
			'FH_RETURN_PCT'			=> $stats['return_percent'],
			'FH_RETURN_COHORT_TEXT'	=> $this->language->lang('FH_RETURN_COHORT_NOTE', (int) $stats['cohort']),
			'S_HAS_DATA'			=> $stats['total'] > 0,
		]);

		// The individual topics, so the finding can be acted on rather than
		// merely noted.
		$rows = $this->service('repository.community')->unanswered_first_topics(
			time() - ($days * 86400),
			time() - ($hours * 3600),
			50
		);

		foreach ($rows as $row)
		{
			$this->template->assign_block_vars('fh_topic', [
				'TITLE'		=> $row['topic_title'],
				'DATE'		=> $this->user->format_date((int) $row['topic_time']),
				'VIEWS'		=> (int) $row['topic_views'],
				'U_TOPIC'	=> $this->topic_url((int) $row['topic_id']),
			]);
		}

		$this->template->assign_var('S_HAS_ITEMS', !empty($rows));
	}

	/**
	 * Period-on-period comparisons with a daily series.
	 *
	 * @return void
	 */
	protected function trends()
	{
		$analyser = $this->service('community.analyser');
		$days = $this->request->variable('days', $this->settings->get_int('fh_trend_period_days'));

		// A view filter, so it is clamped rather than trusted.
		$days = max(7, min(365, $days));

		if (!$analyser->has_history(14))
		{
			$this->template->assign_var('S_FH_NO_HISTORY', true);
		}

		$metrics = [
			community_analyser::M_ACTIVE_POSTERS	=> 'FH_METRIC_ACTIVE_POSTERS',
			community_analyser::M_REGISTRATIONS		=> 'FH_METRIC_REGISTRATIONS',
			community_analyser::M_TOPICS			=> 'FH_METRIC_TOPICS',
			community_analyser::M_POSTS				=> 'FH_METRIC_POSTS',
			community_analyser::M_FIRST_TOPICS		=> 'FH_METRIC_FIRST_TOPICS',
		];

		foreach ($metrics as $metric => $label)
		{
			$comparison = $analyser->compare_periods($metric, $days);
			$series = $analyser->series($metric, $days);

			$this->template->assign_block_vars('fh_trend', [
				'NAME'			=> $this->language->lang($label),
				'CURRENT'		=> (int) $comparison['current'],
				'PREVIOUS'		=> (int) $comparison['previous'],
				'CHANGE'		=> (int) round($comparison['change']),
				'DIRECTION'		=> $comparison['direction'],
				'S_COMPARABLE'	=> (bool) $comparison['has_baseline'],
				// A compact sparkline series, rendered by the stylesheet with no
				// charting library and no external request.
				'SERIES'		=> $this->spark($series),
			]);
		}

		$response = $analyser->compare_periods(community_analyser::M_RESPONSE_SECONDS, $days);

		$this->template->assign_vars([
			'FH_PERIOD_DAYS'		=> $days,
			'FH_RESPONSE_CURRENT'	=> $this->format_duration((int) $response['current']),
			'FH_RESPONSE_PREVIOUS'	=> $this->format_duration((int) $response['previous']),
			'FH_RESPONSE_CHANGE'	=> (int) round($response['change']),
			'S_RESPONSE_COMPARABLE'	=> (bool) $response['has_baseline'],
			// Response time is the one metric where a smaller number is better,
			// so the template must not colour it like the others.
			'S_RESPONSE_INVERTED'	=> true,
		]);
	}

	/**
	 * Members who answer other people.
	 *
	 * @return void
	 */
	protected function contributors()
	{
		$data = $this->service('community.analyser')->contributors(20);
		$days = $this->settings->get_int('fh_trend_period_days');

		foreach ($data['responders'] as $row)
		{
			$this->template->assign_block_vars('fh_responder', [
				'USERNAME'	=> get_username_string('full', (int) $row['poster_id'], $row['username'], $row['user_colour']),
				'REPLIES'	=> (int) $row['replies'],
				'TOPICS'	=> (int) $row['topics_touched'],
				// Every classification states the observation behind it.
				'ROLE'		=> $this->language->lang('FH_ROLE_RESPONDER'),
				'REASON'	=> $this->language->lang('FH_ROLE_RESPONDER_REASON', (int) $row['replies']),
			]);
		}

		foreach ($data['newcomer_helpers'] as $row)
		{
			$this->template->assign_block_vars('fh_helper', [
				'USERNAME'	=> get_username_string('full', (int) $row['poster_id'], $row['username'], $row['user_colour']),
				'REPLIES'	=> (int) $row['replies'],
				'ROLE'		=> $this->language->lang('FH_ROLE_NEWCOMER_HELPER'),
				'REASON'	=> $this->language->lang('FH_ROLE_NEWCOMER_HELPER_REASON', (int) $row['replies']),
			]);
		}

		$this->template->assign_vars([
			'FH_CONTRIBUTORS_EXPLAIN_TEXT'	=> $this->language->lang('FH_CONTRIBUTORS_EXPLAIN', $days),
			'S_HAS_RESPONDERS'				=> !empty($data['responders']),
			'S_HAS_HELPERS'					=> !empty($data['newcomer_helpers']),
		]);
	}

	/**
	 * Reduce a daily series to comma separated percentages of its own maximum.
	 *
	 * @param array<int, float> $series Day bucket to value.
	 * @return string
	 */
	protected function spark(array $series)
	{
		if (empty($series))
		{
			return '';
		}

		$max = max($series);

		if ($max <= 0)
		{
			return '';
		}

		$points = [];

		foreach ($series as $value)
		{
			$points[] = (int) round(($value / $max) * 100);
		}

		return implode(',', array_slice($points, -60));
	}

	/**
	 * Render a duration in words rather than raw seconds.
	 *
	 * @param int $seconds Duration.
	 * @return string
	 */
	protected function format_duration($seconds)
	{
		$seconds = max(0, (int) $seconds);

		if ($seconds === 0)
		{
			return $this->language->lang('FH_NO_DATA');
		}

		if ($seconds < 3600)
		{
			return $this->language->lang('FH_DURATION_MINUTES', (int) round($seconds / 60));
		}

		$hours = (int) floor($seconds / 3600);
		$minutes = (int) round(($seconds % 3600) / 60);

		if ($hours < 48)
		{
			return $this->language->lang('FH_DURATION_HOURS', $hours, $minutes);
		}

		return $this->language->lang('FH_DURATION_DAYS', (int) round($hours / 24));
	}
}
