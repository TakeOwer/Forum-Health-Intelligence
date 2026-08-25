<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\event;

use phpbb\auth\auth;
use phpbb\controller\helper;
use phpbb\language\language;
use phpbb\template\template;
use phpbb\user;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use salvocortesiano\forumhealth\repository\relation_repository;
use salvocortesiano\forumhealth\repository\topic_repository;
use salvocortesiano\forumhealth\service\settings;

/**
 * The extension's only presence on the public side of the forum.
 *
 * Two optional, off-by-default features live here: a hint to someone starting a
 * topic that their question may already be answered, and a list of related
 * discussions at the bottom of a topic. Both read data the background jobs have
 * already stored.
 *
 * The performance rule for this file is absolute. A page request must never
 * trigger analysis, a search call or an AI call. Everything here is a bounded,
 * indexed read of prepared results, and if those results do not exist yet the
 * feature simply does not appear.
 */
class listener implements EventSubscriberInterface
{
	/** @var settings */
	protected $settings;

	/** @var relation_repository */
	protected $relations;

	/** @var topic_repository */
	protected $topics;

	/** @var template */
	protected $template;

	/** @var language */
	protected $language;

	/** @var auth */
	protected $auth;

	/** @var user */
	protected $user;

	/** @var helper */
	protected $helper;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return [
			'core.user_setup'					=> 'load_language',
			'core.permissions'					=> 'add_permissions',
			'core.viewtopic_modify_page_title'	=> 'show_related_topics',
			'core.posting_modify_template_vars'	=> 'prepare_duplicate_hint',
		];
	}

	/**
	 * @param settings            $settings  Extension settings.
	 * @param relation_repository $relations Relation repository.
	 * @param topic_repository    $topics    Topic repository.
	 * @param template            $template  Template engine.
	 * @param language            $language  Language service.
	 * @param auth                $auth      Permissions.
	 * @param user                $user      Current user.
	 * @param helper              $helper    Controller helper.
	 * @param string              $root_path phpBB root path.
	 * @param string              $php_ext   PHP file extension.
	 */
	public function __construct(
		settings $settings,
		relation_repository $relations,
		topic_repository $topics,
		template $template,
		language $language,
		auth $auth,
		user $user,
		helper $helper,
		$root_path,
		$php_ext
	)
	{
		$this->settings = $settings;
		$this->relations = $relations;
		$this->topics = $topics;
		$this->template = $template;
		$this->language = $language;
		$this->auth = $auth;
		$this->user = $user;
		$this->helper = $helper;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Register this extension's permissions with the ACP permission masks.
	 *
	 * Without this the seven permissions still work, but appear on the
	 * permission pages as raw strings like a_fh_view with no description, which
	 * makes them effectively unusable by anybody who did not write them.
	 *
	 * @param \phpbb\event\data $event Event data.
	 * @return void
	 */
	public function add_permissions($event)
	{
		$permissions = $event['permissions'];

		foreach ([
			'a_fh_view'					=> 'ACL_A_FH_VIEW',
			'a_fh_manage'				=> 'ACL_A_FH_MANAGE',
			'a_fh_manage_content'		=> 'ACL_A_FH_MANAGE_CONTENT',
			'a_fh_manage_community'		=> 'ACL_A_FH_MANAGE_COMMUNITY',
			'a_fh_manage_integrations'	=> 'ACL_A_FH_MANAGE_INTEGRATIONS',
			'a_fh_manage_ai'			=> 'ACL_A_FH_MANAGE_AI',
			'a_fh_manage_rules'			=> 'ACL_A_FH_MANAGE_RULES',
		] as $permission => $key)
		{
			$permissions[$permission] = ['lang' => $key, 'cat' => 'misc'];
		}

		$event['permissions'] = $permissions;
	}

	/**
	 * Register the extension's language file.
	 *
	 * @param \phpbb\event\data $event Event data.
	 * @return void
	 */
	public function load_language($event)
	{
		$lang_set_ext = $event['lang_set_ext'];
		$lang_set_ext[] = [
			'ext_name' => 'salvocortesiano/forumhealth',
			'lang_set' => 'common',
		];
		$event['lang_set_ext'] = $lang_set_ext;
	}

	/**
	 * Show discussions related to the one being read.
	 *
	 * @param \phpbb\event\data $event Event data.
	 * @return void
	 */
	public function show_related_topics($event)
	{
		if (!$this->settings->feature_enabled('related'))
		{
			return;
		}

		$topic_data = $event['topic_data'];
		$forum_id = (int) $topic_data['forum_id'];

		// Someone who cannot read the forum a related topic sits in must not be
		// shown that it exists.
		if (!$this->auth->acl_get('f_read', $forum_id))
		{
			return;
		}

		$relations = $this->relations->for_topic(
			(int) $topic_data['topic_id'],
			$this->settings->get_int('fh_user_warning_threshold'),
			$this->settings->get_int('fh_related_topics_limit')
		);

		if (empty($relations))
		{
			return;
		}

		$other_ids = array_map(function ($row) {
			return (int) $row['other_id'];
		}, $relations);

		$metrics = $this->topics->get_metrics($other_ids);
		$shown = 0;

		foreach ($relations as $relation)
		{
			$other_id = (int) $relation['other_id'];

			if (!isset($metrics[$other_id]))
			{
				continue;
			}

			// Permission is checked per topic, not once for the page: related
			// topics can live in forums the reader has no access to.
			if (!$this->auth->acl_get('f_read', (int) $metrics[$other_id]['forum_id']))
			{
				continue;
			}

			$this->template->assign_block_vars('fh_related', [
				'TITLE'	=> $metrics[$other_id]['title_normalised'],
				'U_URL'	=> append_sid("{$this->root_path}viewtopic.{$this->php_ext}", 't=' . $other_id),
			]);

			$shown++;
		}

		if ($shown > 0)
		{
			$this->template->assign_var('S_FH_RELATED', true);
		}
	}

	/**
	 * Prepare the duplicate hint shown while composing a new topic.
	 *
	 * The check itself runs over AJAX as the title is typed, because doing it
	 * during page assembly would mean querying on every posting page load
	 * whether or not the person is starting a topic.
	 *
	 * @param \phpbb\event\data $event Event data.
	 * @return void
	 */
	public function prepare_duplicate_hint($event)
	{
		if (!$this->settings->feature_enabled('user_warning'))
		{
			return;
		}

		// Only when starting a new discussion. Replies and edits have nothing to
		// duplicate.
		if ($event['mode'] !== 'post')
		{
			return;
		}

		$forum_id = (int) $event['forum_id'];

		if (!$this->auth->acl_get('f_read', $forum_id))
		{
			return;
		}

		$this->template->assign_vars([
			'S_FH_DUPLICATE_CHECK'	=> true,
			'U_FH_DUPLICATE_CHECK'	=> $this->helper->route('salvocortesiano_forumhealth_similar', [
				'f' => $forum_id,
			]),
			// Ties the lookup to this session, so the endpoint cannot be driven
			// from an arbitrary page or by a third-party site.
			'FH_SIMILAR_HASH'		=> generate_link_hash('fh_similar'),
		]);
	}
}
