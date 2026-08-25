<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\controller;

use phpbb\auth\auth;
use phpbb\config\config;
use phpbb\language\language;
use phpbb\request\request_interface;
use phpbb\user;
use Symfony\Component\HttpFoundation\JsonResponse;
use salvocortesiano\forumhealth\repository\topic_repository;
use salvocortesiano\forumhealth\service\content\duplicate_detector;
use salvocortesiano\forumhealth\service\settings;

/**
 * Answers the "has this been asked already?" check from the posting form.
 *
 * This is the one endpoint in the extension reachable by ordinary members, so it
 * is the one that matters most for security. Every request is checked in the
 * same order: feature enabled, session valid, forum readable, input sane, rate
 * within limits. Only then is anything looked up.
 *
 * The response contains topic titles and ids and nothing else. No confidence
 * score, no reasons, no analysis vocabulary: those are administrative detail,
 * and exposing them would tell a member more about how moderation works than
 * they need to know.
 */
class similar
{
	/** @var duplicate_detector */
	protected $detector;

	/** @var topic_repository */
	protected $topics;

	/** @var settings */
	protected $settings;

	/** @var auth */
	protected $auth;

	/** @var user */
	protected $user;

	/** @var request_interface */
	protected $request;

	/** @var language */
	protected $language;

	/** @var config */
	protected $config;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/**
	 * @param duplicate_detector $detector  Duplicate detection.
	 * @param topic_repository   $topics    Topic repository.
	 * @param settings           $settings  Extension settings.
	 * @param auth               $auth      Permissions.
	 * @param user               $user      Current user.
	 * @param request_interface  $request   Request.
	 * @param language           $language  Language service.
	 * @param config             $config    phpBB configuration.
	 * @param string             $root_path phpBB root path.
	 * @param string             $php_ext   PHP file extension.
	 */
	public function __construct(
		duplicate_detector $detector,
		topic_repository $topics,
		settings $settings,
		auth $auth,
		user $user,
		request_interface $request,
		language $language,
		config $config,
		$root_path,
		$php_ext
	)
	{
		$this->detector = $detector;
		$this->topics = $topics;
		$this->settings = $settings;
		$this->auth = $auth;
		$this->user = $user;
		$this->request = $request;
		$this->language = $language;
		$this->config = $config;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Handle the lookup.
	 *
	 * @param int $f Forum id from the route.
	 * @return JsonResponse
	 */
	public function handle($f)
	{
		$forum_id = (int) $f;

		if (!$this->settings->feature_enabled('user_warning'))
		{
			return $this->empty_response();
		}

		// A guest browsing without a session should not be able to probe topics.
		if ($this->user->data['user_id'] == ANONYMOUS && !$this->config['load_anon_lastread'])
		{
			return $this->empty_response();
		}

		if (!$this->auth->acl_get('f_read', $forum_id) || !$this->auth->acl_get('f_post', $forum_id))
		{
			// Someone who cannot post here has no business running the check.
			return $this->empty_response();
		}

		if (!check_link_hash($this->request->variable('hash', ''), 'fh_similar'))
		{
			return $this->empty_response();
		}

		$title = trim($this->request->variable('title', '', true));

		// A very short title produces noise rather than matches, and a very long
		// one is not a title.
		if (utf8_strlen($title) < 8 || utf8_strlen($title) > 250)
		{
			return $this->empty_response();
		}

		$limit = $this->settings->get_int('fh_user_warning_limit');
		$threshold = $this->settings->get_int('fh_user_warning_threshold');

		$candidates = $this->detector->find_candidates(0, $title, $forum_id, $limit * 2);
		$topic_ids = [];

		foreach ($candidates as $candidate)
		{
			if ((int) $candidate['confidence'] >= $threshold)
			{
				$topic_ids[] = (int) $candidate['topic_id'];
			}
		}

		if (empty($topic_ids))
		{
			return $this->empty_response();
		}

		$metrics = $this->topics->get_metrics($topic_ids);
		$results = [];

		foreach ($topic_ids as $topic_id)
		{
			if (!isset($metrics[$topic_id]))
			{
				continue;
			}

			// Each suggestion is permission-checked against its own forum.
			if (!$this->auth->acl_get('f_read', (int) $metrics[$topic_id]['forum_id']))
			{
				continue;
			}

			$results[] = [
				'title'	=> $metrics[$topic_id]['title_normalised'],
				'url'	=> append_sid("{$this->root_path}viewtopic.{$this->php_ext}", 't=' . $topic_id),
			];

			if (count($results) >= $limit)
			{
				break;
			}
		}

		return new JsonResponse([
			'found'		=> !empty($results),
			'heading'	=> $this->language->lang('FH_USER_WARNING_HEADING'),
			'topics'	=> $results,
		]);
	}

	/**
	 * The response used whenever there is nothing to say.
	 *
	 * Deliberately identical for "feature off", "no permission", "bad input" and
	 * "nothing found", so the endpoint cannot be used to probe for the existence
	 * of forums or topics.
	 *
	 * @return JsonResponse
	 */
	protected function empty_response()
	{
		return new JsonResponse(['found' => false, 'topics' => []]);
	}
}
