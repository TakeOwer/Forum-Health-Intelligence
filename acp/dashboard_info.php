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
 * ACP module descriptor for the dashboard.
 */
class dashboard_info
{
	/**
	 * {@inheritdoc}
	 */
	public function module()
	{
		return [
			'filename'	=> '\salvocortesiano\forumhealth\acp\dashboard_module',
			'title'		=> 'ACP_FH_DASHBOARD',
			'modes'		=> [
				'dashboard'	=> [
					'title'	=> 'ACP_FH_DASHBOARD',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
			],
		];
	}
}
