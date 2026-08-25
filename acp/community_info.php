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
 * ACP module descriptor for the community health reports.
 */
class community_info
{
	/**
	 * {@inheritdoc}
	 */
	public function module()
	{
		return [
			'filename'	=> '\salvocortesiano\forumhealth\acp\community_module',
			'title'		=> 'ACP_FH_COMMUNITY',
			'modes'		=> [
				'overview'		=> [
					'title'	=> 'ACP_FH_COMMUNITY_OVERVIEW',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
				'newusers'		=> [
					'title'	=> 'ACP_FH_NEWUSERS',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
				'trends'		=> [
					'title'	=> 'ACP_FH_TRENDS',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
				'contributors'	=> [
					'title'	=> 'ACP_FH_CONTRIBUTORS',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
			],
		];
	}
}
