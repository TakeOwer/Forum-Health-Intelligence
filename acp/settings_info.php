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
 * ACP module descriptor for the settings page.
 */
class settings_info
{
	/**
	 * {@inheritdoc}
	 */
	public function module()
	{
		return [
			'filename'	=> '\salvocortesiano\forumhealth\acp\settings_module',
			'title'		=> 'ACP_FH_SETTINGS',
			'modes'		=> [
				'settings'	=> [
					'title'	=> 'ACP_FH_SETTINGS',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_manage_content',
					'cat'	=> ['ACP_FH_TITLE'],
				],
			],
		];
	}
}
