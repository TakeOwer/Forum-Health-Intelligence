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
 * ACP module descriptor for the rule editor.
 */
class rules_info
{
	/**
	 * {@inheritdoc}
	 */
	public function module()
	{
		return [
			'filename'	=> '\salvocortesiano\forumhealth\acp\rules_module',
			'title'		=> 'ACP_FH_RULES',
			'modes'		=> [
				'rules'	=> [
					'title'	=> 'ACP_FH_RULES',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_manage_rules',
					'cat'	=> ['ACP_FH_TITLE'],
				],
			],
		];
	}
}
