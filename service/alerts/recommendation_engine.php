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
use salvocortesiano\forumhealth\repository\topic_repository;
use salvocortesiano\forumhealth\service\community\community_analyser;
use salvocortesiano\forumhealth\service\integrations\registry;
use salvocortesiano\forumhealth\service\settings;

/**
 * Suggests what to do next, in priority order.
 *
 * The difference between this and the alert list is intent. An alert says
 * something is true. A recommendation says something is worth doing, and orders
 * the options so that an administrator with twenty spare minutes knows where to
 * spend them.
 *
 * Recommendations are computed on demand and never stored: they are a view over
 * current state, and a stale suggestion is worse than none. None of them
 * performs an action; each one links to the page where a person can decide.
 */
class recommendation_engine
{
	/** @var alert_manager */
	protected $alerts;

	/** @var topic_repository */
	protected $topics;

	/** @var community_analyser */
	protected $community;

	/** @var registry */
	protected $registry;

	/** @var settings */
	protected $settings;

	/**
	 * @param alert_manager      $alerts    Alert manager, for the workload figures.
	 * @param topic_repository   $topics    Topic repository.
	 * @param community_analyser $community Community analysis.
	 * @param registry           $registry  Integration registry.
	 * @param settings           $settings  Extension settings.
	 */
	public function __construct(
		alert_manager $alerts,
		topic_repository $topics,
		community_analyser $community,
		registry $registry,
		settings $settings
	)
	{
		$this->alerts = $alerts;
		$this->topics = $topics;
		$this->community = $community;
		$this->registry = $registry;
		$this->settings = $settings;
	}

	/**
	 * Build the current recommendation list.
	 *
	 * @param int $limit Maximum recommendations.
	 * @return array[] Rows of key, params, action_key, mode, priority.
	 */
	public function build($limit = 8)
	{
		$out = [];
		$workload = $this->alerts->pending_workload();

		$out = array_merge($out, $this->onboarding_recommendation());
		$out = array_merge($out, $this->unanswered_recommendation($workload));
		$out = array_merge($out, $this->recurring_recommendation());
		$out = array_merge($out, $this->duplicate_recommendation($workload));
		$out = array_merge($out, $this->link_recommendation($workload));
		$out = array_merge($out, $this->solution_recommendation());
		$out = array_merge($out, $this->integration_recommendation());

		usort($out, function ($a, $b) {
			return $b['priority'] <=> $a['priority'];
		});

		return array_slice($out, 0, (int) $limit);
	}

	/**
	 * Newcomers going unanswered is the highest-value thing to fix.
	 *
	 * A forum that does not answer first questions loses the people who ask
	 * them, and that loss compounds in a way a backlog of old topics does not.
	 *
	 * @return array[]
	 */
	protected function onboarding_recommendation()
	{
		if (!$this->settings->feature_enabled('community'))
		{
			return [];
		}

		$stats = $this->community->first_post_experience();

		if ($stats['total'] < 5 || $stats['unanswered'] === 0)
		{
			return [];
		}

		$percent = 100 - $stats['answered_percent'];

		if ($percent < 20)
		{
			return [];
		}

		return [[
			'key'		=> 'FH_REC_ONBOARDING',
			'params'	=> ['count' => $stats['unanswered'], 'percent' => $percent],
			'action'	=> 'FH_ACTION_REVIEW_NEWUSERS',
			'module'	=> 'community',
			'mode'		=> 'newusers',
			'priority'	=> 100,
		]];
	}

	/**
	 * A backlog of popular unanswered discussions.
	 *
	 * @param array $workload Pending workload figures.
	 * @return array[]
	 */
	protected function unanswered_recommendation(array $workload)
	{
		if ($workload['unanswered'] === 0)
		{
			return [];
		}

		return [[
			'key'		=> 'FH_REC_UNANSWERED',
			'params'	=> [
				'count' => $workload['unanswered'],
				'hours' => $this->settings->get_int('fh_unanswered_hours'),
			],
			'action'	=> 'FH_ACTION_REVIEW_UNANSWERED',
			'module'	=> 'content',
			'mode'		=> 'unanswered',
			'priority'	=> 90,
		]];
	}

	/**
	 * The same question being asked over and over.
	 *
	 * This is the recommendation with the highest leverage in the whole product:
	 * a guide written once removes work permanently, where answering the same
	 * question for the twentieth time removes it until tomorrow.
	 *
	 * @return array[]
	 */
	protected function recurring_recommendation()
	{
		if (!$this->settings->feature_enabled('recurring'))
		{
			return [];
		}

		$min = $this->settings->get_int('fh_recurring_min_topics');
		$window = time() - (365 * 86400);
		$groups = $this->topics->recurring_token_groups(max($min, 8), 3, $window);

		if (empty($groups))
		{
			return [];
		}

		$out = [];

		foreach ($groups as $group)
		{
			$out[] = [
				'key'		=> 'FH_REC_RECURRING',
				'params'	=> ['count' => (int) $group['topics'], 'term' => (string) $group['token']],
				'action'	=> 'FH_ACTION_CREATE_GUIDE',
				'module'	=> 'content',
				'mode'		=> 'duplicates',
				'priority'	=> 80,
			];
		}

		return $out;
	}

	/**
	 * Duplicate candidates awaiting a decision.
	 *
	 * @param array $workload Pending workload figures.
	 * @return array[]
	 */
	protected function duplicate_recommendation(array $workload)
	{
		if ($workload['duplicates'] < 3)
		{
			return [];
		}

		return [[
			'key'		=> 'FH_REC_DUPLICATES',
			'params'	=> ['count' => $workload['duplicates']],
			'action'	=> 'FH_ACTION_REVIEW_DUPLICATES',
			'module'	=> 'content',
			'mode'		=> 'duplicates',
			'priority'	=> 60,
		]];
	}

	/**
	 * Broken links, weighted by how many topics they sit in.
	 *
	 * @param array $workload Pending workload figures.
	 * @return array[]
	 */
	protected function link_recommendation(array $workload)
	{
		if (!$this->settings->feature_enabled('links') || $workload['links'] === 0)
		{
			return [];
		}

		return [[
			'key'		=> 'FH_REC_LINKS',
			'params'	=> ['count' => $workload['links']],
			'action'	=> 'FH_ACTION_REVIEW_LINKS',
			'module'	=> 'content',
			'mode'		=> 'links',
			'priority'	=> 50,
		]];
	}

	/**
	 * Discussions that appear to have been solved without being marked.
	 *
	 * @return array[]
	 */
	protected function solution_recommendation()
	{
		if (!$this->settings->feature_enabled('solutions'))
		{
			return [];
		}

		$candidates = $this->topics->solution_candidates(
			max(75, $this->settings->get_int('fh_solution_min_confidence')),
			0,
			51
		);

		$count = count($candidates);

		if ($count < 5)
		{
			return [];
		}

		return [[
			'key'		=> 'FH_REC_SOLUTIONS',
			'params'	=> ['count' => $count >= 51 ? 50 : $count],
			'action'	=> 'FH_ACTION_REVIEW_SOLUTIONS',
			'module'	=> 'content',
			'mode'		=> 'solutions',
			'priority'	=> 40,
		]];
	}

	/**
	 * Suggest an optional integration when it would measurably help.
	 *
	 * Only offered when the forum is large enough for the difference to matter.
	 * On a small forum native analysis is already sufficient, and recommending
	 * software nobody needs is noise.
	 *
	 * @return array[]
	 */
	protected function integration_recommendation()
	{
		$counts = $this->topics->summary_counts();

		if ($counts['total'] < 5000)
		{
			return [];
		}

		$status = $this->registry->search_status();

		if ($status['state'] === constants::INT_OPERATIONAL)
		{
			return [];
		}

		return [[
			'key'		=> $status['state'] === constants::INT_NOT_INSTALLED
							? 'FH_REC_INSTALL_SEARCH'
							: 'FH_REC_BIND_SEARCH',
			'params'	=> ['count' => $counts['total']],
			'action'	=> 'FH_ACTION_REVIEW_INTEGRATIONS',
			'module'	=> 'integrations',
			'mode'		=> 'integrations',
			'priority'	=> 20,
		]];
	}
}
