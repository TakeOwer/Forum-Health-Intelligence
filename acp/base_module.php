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
 * Shared plumbing for every ACP module in this extension.
 *
 * phpBB constructs ACP modules itself rather than through the container, so each
 * one pulls what it needs from the global container here, in one place, instead
 * of repeating the same six lines in seven files.
 *
 * It also centralises the two checks that must never be forgotten: the
 * permission required for the page, and the form token on anything that writes.
 * Having them in a helper means a new page cannot quietly ship without them.
 */
abstract class base_module
{
	/** @var string Template file for the current page. */
	public $tpl_name;

	/** @var string Page title language key. */
	public $page_title;

	/** @var string Current mode. */
	public $u_action;

	/** @var \Symfony\Component\DependencyInjection\ContainerInterface */
	protected $container;

	/** @var \phpbb\language\language */
	protected $language;

	/** @var \phpbb\template\template */
	protected $template;

	/** @var \phpbb\request\request_interface */
	protected $request;

	/** @var \phpbb\user */
	protected $user;

	/** @var \phpbb\auth\auth */
	protected $auth;

	/** @var \salvocortesiano\forumhealth\service\settings */
	protected $settings;

	/**
	 * Resolve the shared services from the global container.
	 *
	 * @return void
	 */
	protected function boot()
	{
		global $phpbb_container;

		$this->container = $phpbb_container;
		$this->language = $phpbb_container->get('language');
		$this->template = $phpbb_container->get('template');
		$this->request = $phpbb_container->get('request');
		$this->user = $phpbb_container->get('user');
		$this->auth = $phpbb_container->get('auth');
		$this->settings = $phpbb_container->get('salvocortesiano.forumhealth.settings');

		$this->language->add_lang('common', 'salvocortesiano/forumhealth');
		$this->language->add_lang('acp_forumhealth', 'salvocortesiano/forumhealth');
	}

	/**
	 * Fetch an extension service.
	 *
	 * @param string $id Service id without the common prefix.
	 * @return object
	 */
	protected function service($id)
	{
		return $this->container->get('salvocortesiano.forumhealth.' . $id);
	}

	/**
	 * Stop unless the current user holds the permission.
	 *
	 * @param string $permission Permission name, for example a_fh_manage.
	 * @return void
	 */
	protected function require_permission($permission)
	{
		if (!$this->auth->acl_get($permission))
		{
			trigger_error($this->language->lang('NO_AUTH_OPERATION') . adm_back_link($this->u_action), E_USER_WARNING);
		}
	}

	/**
	 * Stop unless the submitted form token is valid.
	 *
	 * @param string $form_key Form key used when the form was rendered.
	 * @return void
	 */
	protected function require_form_token($form_key)
	{
		if (!check_form_key($form_key))
		{
			trigger_error($this->language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
		}
	}

	/**
	 * Assign the variables every page of this extension needs.
	 *
	 * @param string $mode Current mode.
	 * @return void
	 */
	protected function assign_common($mode)
	{
		global $phpbb_root_path;

		$this->template->assign_vars([
			'U_ACTION'			=> $this->u_action,
			'FH_MODE'			=> $mode,
			'S_FH_ENABLED'		=> $this->settings->is_enabled(),
			'S_FH_CAN_MANAGE'	=> $this->auth->acl_get('a_fh_manage'),

			// The ACP has no INCLUDECSS, and phpBB does not pick up an
			// extension's ACP stylesheet by convention, so the link has to be
			// built by hand. Doing it here rather than in the template means the
			// path comes from phpBB's own root variable instead of relying on
			// {ROOT_PATH} being assigned, and it is only set on this
			// extension's own pages rather than across the whole ACP.
			'U_FH_ACP_CSS'		=> $phpbb_root_path . 'ext/salvocortesiano/forumhealth/adm/style/forumhealth.css',
		]);
	}

	/**
	 * Standard pagination for a report page.
	 *
	 * @param int    $total    Total rows.
	 * @param int    $per_page Rows per page.
	 * @param int    $start    Current offset.
	 * @param string $base_url Base URL for page links.
	 * @return void
	 */
	protected function assign_pagination($total, $per_page, $start, $base_url)
	{
		$this->container->get('pagination')->generate_template_pagination(
			$base_url,
			'pagination',
			'start',
			(int) $total,
			(int) $per_page,
			(int) $start
		);

		$this->template->assign_vars([
			'TOTAL_ITEMS'	=> (int) $total,
			'S_HAS_ITEMS'	=> $total > 0,
		]);
	}

	/**
	 * Format an integer for display in the reader's locale.
	 *
	 * FH_COVERAGE takes its count as %1$s rather than %1$d precisely so it can
	 * be grouped; passing a raw integer would print 148213 where the reader
	 * expects 148,213.
	 *
	 * @param int $number Value.
	 * @return string
	 */
	protected function format_number($number)
	{
		// PHP's own formatter rather than anything from the language service:
		// phpBB does not guarantee a number formatter there, and a fatal error
		// on the dashboard would be a poor trade for a thousands separator.
		return number_format((int) $number);
	}

	/**
	 * Build a link to a topic in the public forum.
	 *
	 * @param int $topic_id Topic id.
	 * @param int $post_id  Optional post id to anchor on.
	 * @return string
	 */
	protected function topic_url($topic_id, $post_id = 0)
	{
		global $phpbb_root_path, $phpEx;

		$params = 't=' . (int) $topic_id;

		if ($post_id > 0)
		{
			$params = 'p=' . (int) $post_id . '#p' . (int) $post_id;
		}

		return append_sid($phpbb_root_path . 'viewtopic.' . $phpEx, $params);
	}

	/**
	 * Render a severity as a label, an icon name and a css class.
	 *
	 * Severity is never communicated by colour alone: every caller receives a
	 * translated word to display alongside whatever colour the stylesheet adds.
	 *
	 * @param int $severity Severity constant.
	 * @return array{label:string,class:string}
	 */
	protected function severity_display($severity)
	{
		$map = \salvocortesiano\forumhealth\constants::severity_map();
		$suffix = isset($map[(int) $severity]) ? $map[(int) $severity] : 'LOW';

		return [
			'label'	=> $this->language->lang('FH_SEVERITY_' . $suffix),
			'class'	=> 'fh-sev-' . strtolower($suffix),
		];
	}
}
