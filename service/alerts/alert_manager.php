<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\alerts;

use salvocortesiano\forumhealth\constants;
use salvocortesiano\forumhealth\repository\alert_repository;
use salvocortesiano\forumhealth\repository\community_repository;
use salvocortesiano\forumhealth\repository\link_repository;
use salvocortesiano\forumhealth\repository\relation_repository;
use salvocortesiano\forumhealth\repository\topic_repository;
use salvocortesiano\forumhealth\service\community\community_analyser;
use salvocortesiano\forumhealth\service\integrations\registry;
use salvocortesiano\forumhealth\service\settings;

/**
 * Turns stored analysis into alerts worth an administrator's attention.
 *
 * This runs after the analysis passes, reads only what they wrote, and makes no
 * external call of its own. Two principles shape it:
 *
 * Alerts are aggregated, not per-item. "Twenty-seven popular topics have gone
 * unanswered" is one alert a person can act on; twenty-seven alerts is a list
 * nobody reads. Individual items live in the reports, which the alert links to.
 *
 * Nothing is stated as fact that is only a signal. The wording keys say
 * "possible" and "potentially" where the evidence is circumstantial, and every
 * alert carries the numbers that produced it so the judgement stays with the
 * person, not the extension.
 */
class alert_manager
{
	/** @var alert_repository */
	protected $alerts;

	/** @var topic_repository */
	protected $topics;

	/** @var relation_repository */
	protected $relations;

	/** @var link_repository */
	protected $links;

	/** @var community_repository */
	protected $community;

	/** @var community_analyser */
	protected $community_analyser;

	/** @var registry */
	protected $registry;

	/** @var settings */
	protected $settings;

	/**
	 * @param alert_repository     $alerts             Alert repository.
	 * @param topic_repository     $topics             Topic repository.
	 * @param relation_repository  $relations          Relation repository.
	 * @param link_repository      $links              Link repository.
	 * @param community_repository $community          Community repository.
	 * @param community_analyser   $community_analyser Community analysis.
	 * @param registry             $registry           Integration registry.
	 * @param settings             $settings           Extension settings.
	 */
	public function __construct(
		alert_repository $alerts,
		topic_repository $topics,
		relation_repository $relations,
		link_repository $links,
		community_repository $community,
		community_analyser $community_analyser,
		registry $registry,
		settings $settings
	)
	{
		$this->alerts = $alerts;
		$this->topics = $topics;
		$this->relations = $relations;
		$this->links = $links;
		$this->community = $community;
		$this->community_analyser = $community_analyser;
		$this->registry = $registry;
		$this->settings = $settings;
	}

	/**
	 * Regenerate all alerts from current stored analysis.
	 *
	 * @return int Number of newly raised alerts.
	 */
	public function generate()
	{
		if (!$this->settings->feature_enabled('alerts'))
		{
			return 0;
		}

		$raised = 0;

		$raised += $this->unanswered_alert();
		$raised += $this->duplicate_alert();
		$raised += $this->broken_link_alert();
		$raised += $this->outdated_alert();
		$raised += $this->onboarding_alert();
		$raised += $this->activity_alert();
		$raised += $this->moderator_load_alert();
		$raised += $this->integration_alerts();

		return $raised;
	}

	/**
	 * Popular discussions still without an answer.
	 *
	 * @return int
	 */
	protected function unanswered_alert()
	{
		if (!$this->settings->feature_enabled('content'))
		{
			return 0;
		}

		$hours = $this->settings->get_int('fh_unanswered_hours');
		$count = $this->topics->count_unanswered(
			$this->settings->get_int('fh_unanswered_min_views'),
			time() - ($hours * 3600),
			time() - ($this->settings->get_int('fh_unanswered_max_age_days') * 86400)
		);

		if ($count === 0)
		{
			$this->alerts->resolve_entity(constants::ALERT_UNANSWERED, 'global', 0);

			return 0;
		}

		return $this->raise([
			'alert_type'	=> constants::ALERT_UNANSWERED,
			// Severity tracks how large the backlog has become, so a handful of
			// stragglers does not shout as loudly as a systemic gap.
			'severity'		=> $this->scale($count, 10, 40),
			'entity_type'	=> 'global',
			'entity_id'		=> 0,
			'signature'		=> 'unanswered|' . $hours,
			'explain_key'	=> 'FH_ALERT_UNANSWERED_EXPLAIN',
			'explain_data'	=> ['count' => $count, 'hours' => $hours],
			'action_key'	=> 'FH_ACTION_REVIEW_UNANSWERED',
		]);
	}

	/**
	 * High-confidence duplicate candidates awaiting review.
	 *
	 * @return int
	 */
	protected function duplicate_alert()
	{
		if (!$this->settings->feature_enabled('duplicates'))
		{
			return 0;
		}

		$threshold = $this->settings->get_int('fh_duplicate_high_threshold');
		$count = $this->relations->count([
			'status'			=> constants::RELATION_NEW,
			'min_confidence'	=> $threshold,
		]);

		if ($count === 0)
		{
			$this->alerts->resolve_entity(constants::ALERT_DUPLICATE, 'global', 0);

			return 0;
		}

		return $this->raise([
			'alert_type'	=> constants::ALERT_DUPLICATE,
			'severity'		=> $this->scale($count, 5, 25),
			'entity_type'	=> 'global',
			'entity_id'		=> 0,
			'signature'		=> 'duplicates|' . $threshold,
			'explain_key'	=> 'FH_ALERT_DUPLICATE_EXPLAIN',
			'explain_data'	=> ['count' => $count, 'threshold' => $threshold],
			'action_key'	=> 'FH_ACTION_REVIEW_DUPLICATES',
		]);
	}

	/**
	 * Links that no longer resolve.
	 *
	 * @return int
	 */
	protected function broken_link_alert()
	{
		if (!$this->settings->feature_enabled('links'))
		{
			return 0;
		}

		$states = $this->links->counts_by_state();
		$broken = isset($states[constants::LINK_BROKEN]) ? $states[constants::LINK_BROKEN] : 0;

		if ($broken === 0)
		{
			$this->alerts->resolve_entity(constants::ALERT_BROKEN_LINK, 'global', 0);

			return 0;
		}

		return $this->raise([
			'alert_type'	=> constants::ALERT_BROKEN_LINK,
			'severity'		=> $this->scale($broken, 10, 50),
			'entity_type'	=> 'global',
			'entity_id'		=> 0,
			'signature'		=> 'broken_links',
			'explain_key'	=> 'FH_ALERT_BROKEN_LINK_EXPLAIN',
			'explain_data'	=> ['count' => $broken],
			'action_key'	=> 'FH_ACTION_REVIEW_LINKS',
		]);
	}

	/**
	 * Content that may have been overtaken by events.
	 *
	 * @return int
	 */
	protected function outdated_alert()
	{
		if (!$this->settings->feature_enabled('freshness'))
		{
			return 0;
		}

		$counts = $this->topics->summary_counts();

		if ($counts['stale'] === 0)
		{
			$this->alerts->resolve_entity(constants::ALERT_OUTDATED, 'global', 0);

			return 0;
		}

		return $this->raise([
			'alert_type'	=> constants::ALERT_OUTDATED,
			// Deliberately capped below high: ageing content is a maintenance
			// task, not an incident.
			'severity'		=> constants::SEVERITY_LOW,
			'entity_type'	=> 'global',
			'entity_id'		=> 0,
			'signature'		=> 'outdated',
			'explain_key'	=> 'FH_ALERT_OUTDATED_EXPLAIN',
			'explain_data'	=> ['count' => $counts['stale']],
			'action_key'	=> 'FH_ACTION_REVIEW_FRESHNESS',
		]);
	}

	/**
	 * Newcomers whose first discussion went unanswered.
	 *
	 * @return int
	 */
	protected function onboarding_alert()
	{
		if (!$this->settings->feature_enabled('community'))
		{
			return 0;
		}

		$hours = $this->settings->get_int('fh_newuser_reply_hours');
		$window = $this->settings->get_int('fh_trend_period_days') * 86400;
		$rows = $this->community->unanswered_first_topics(time() - $window, time() - ($hours * 3600), 200);
		$count = count($rows);
		$threshold = $this->settings->get_int('fh_newuser_alert_threshold');

		if ($count < $threshold)
		{
			$this->alerts->resolve_entity(constants::ALERT_ONBOARDING, 'global', 0);

			return 0;
		}

		return $this->raise([
			'alert_type'	=> constants::ALERT_ONBOARDING,
			'severity'		=> constants::SEVERITY_HIGH,
			'entity_type'	=> 'global',
			'entity_id'		=> 0,
			'signature'		=> 'onboarding|' . $hours,
			'explain_key'	=> 'FH_ALERT_ONBOARDING_EXPLAIN',
			'explain_data'	=> ['count' => $count, 'hours' => $hours],
			'action_key'	=> 'FH_ACTION_REVIEW_NEWUSERS',
		]);
	}

	/**
	 * A pronounced change in participation.
	 *
	 * @return int
	 */
	protected function activity_alert()
	{
		if (!$this->settings->feature_enabled('community') || !$this->community_analyser->has_history(14))
		{
			// Without a baseline any percentage would be an artefact of the
			// extension having just been installed.
			return 0;
		}

		$days = $this->settings->get_int('fh_trend_period_days');
		$comparison = $this->community_analyser->compare_periods(community_analyser::M_ACTIVE_POSTERS, $days);

		if (!$comparison['has_baseline'])
		{
			return 0;
		}

		$drop = $this->settings->get_int('fh_activity_drop_percent');

		if ($comparison['change'] > -$drop)
		{
			$this->alerts->resolve_entity(constants::ALERT_ACTIVITY_DROP, 'global', 0);

			return 0;
		}

		return $this->raise([
			'alert_type'	=> constants::ALERT_ACTIVITY_DROP,
			'severity'		=> constants::SEVERITY_MEDIUM,
			'entity_type'	=> 'global',
			'entity_id'		=> 0,
			// The signature includes the period so a new month raises a fresh
			// alert rather than silently updating a stale one.
			'signature'		=> 'activity_drop|' . $days . '|' . gmdate('YW'),
			'explain_key'	=> 'FH_ALERT_ACTIVITY_DROP_EXPLAIN',
			'explain_data'	=> [
				'percent'	=> abs((int) round($comparison['change'])),
				'days'		=> $days,
			],
			'action_key'	=> 'FH_ACTION_REVIEW_TRENDS',
		]);
	}

	/**
	 * The total review backlog across every area.
	 *
	 * @return int
	 */
	protected function moderator_load_alert()
	{
		$pending = $this->pending_workload();
		$threshold = $this->settings->get_int('fh_moderator_load_threshold');

		if ($pending['total'] < $threshold)
		{
			$this->alerts->resolve_entity(constants::ALERT_MODERATOR_LOAD, 'global', 0);

			return 0;
		}

		return $this->raise([
			'alert_type'	=> constants::ALERT_MODERATOR_LOAD,
			'severity'		=> constants::SEVERITY_MEDIUM,
			'entity_type'	=> 'global',
			'entity_id'		=> 0,
			'signature'		=> 'moderator_load|' . gmdate('YW'),
			'explain_key'	=> 'FH_ALERT_MODERATOR_LOAD_EXPLAIN',
			'explain_data'	=> ['count' => $pending['total']],
			'action_key'	=> 'FH_ACTION_REVIEW_ALERTS',
		]);
	}

	/**
	 * An integration that has been switched on but is not working.
	 *
	 * @return int
	 */
	protected function integration_alerts()
	{
		$raised = 0;

		foreach (['search' => 'fh_meilisearch_enabled', 'ai' => 'fh_ai_enabled'] as $kind => $switch)
		{
			$status = ($kind === 'ai') ? $this->registry->ai_status() : $this->registry->search_status();

			// Only complain about an integration the administrator asked for.
			// A missing extension nobody enabled is not a problem.
			if (!$this->settings->get_bool($switch))
			{
				$this->alerts->resolve_entity(constants::ALERT_INTEGRATION_FAILURE, 'integration', $kind === 'ai' ? 2 : 1);
				continue;
			}

			if ($status['state'] === constants::INT_OPERATIONAL)
			{
				$this->alerts->resolve_entity(constants::ALERT_INTEGRATION_FAILURE, 'integration', $kind === 'ai' ? 2 : 1);
				continue;
			}

			$raised += $this->raise([
				'alert_type'	=> constants::ALERT_INTEGRATION_FAILURE,
				'severity'		=> constants::SEVERITY_MEDIUM,
				'entity_type'	=> 'integration',
				'entity_id'		=> $kind === 'ai' ? 2 : 1,
				'signature'		=> 'integration|' . $kind . '|' . $status['state'],
				'explain_key'	=> 'FH_ALERT_INTEGRATION_EXPLAIN',
				'explain_data'	=> [
					'name'	=> $kind === 'ai' ? 'FH_INT_AI' : 'FH_INT_SEARCH',
					'state'	=> 'FH_INT_STATE_' . strtoupper($status['state']),
				],
				'action_key'	=> 'FH_ACTION_REVIEW_INTEGRATIONS',
			]);
		}

		return $raised;
	}

	/**
	 * The pending review workload, broken down by area.
	 *
	 * @return array{duplicates:int,links:int,unanswered:int,outdated:int,total:int}
	 */
	public function pending_workload()
	{
		$states = $this->links->counts_by_state();

		$workload = [
			'duplicates'	=> $this->relations->count(['status' => constants::RELATION_NEW]),
			'links'			=> (isset($states[constants::LINK_BROKEN]) ? $states[constants::LINK_BROKEN] : 0)
								+ (isset($states[constants::LINK_WARNING]) ? $states[constants::LINK_WARNING] : 0),
			'unanswered'	=> $this->topics->count_unanswered(
				$this->settings->get_int('fh_unanswered_min_views'),
				time() - ($this->settings->get_int('fh_unanswered_hours') * 3600),
				time() - ($this->settings->get_int('fh_unanswered_max_age_days') * 86400)
			),
			'outdated'		=> $this->topics->summary_counts()['stale'],
		];

		$workload['total'] = array_sum($workload);

		return $workload;
	}

	/**
	 * Raise one alert.
	 *
	 * @param array $data Alert fields.
	 * @return int 1 when newly created, 0 when it already existed.
	 */
	protected function raise(array $data)
	{
		return $this->alerts->raise($data) ? 1 : 0;
	}

	/**
	 * Choose a severity from a count and two thresholds.
	 *
	 * @param int $count  Observed count.
	 * @param int $medium Count at which the finding becomes medium.
	 * @param int $high   Count at which it becomes high.
	 * @return int Severity constant.
	 */
	protected function scale($count, $medium, $high)
	{
		if ($count >= $high)
		{
			return constants::SEVERITY_HIGH;
		}

		if ($count >= $medium)
		{
			return constants::SEVERITY_MEDIUM;
		}

		return constants::SEVERITY_LOW;
	}
}
