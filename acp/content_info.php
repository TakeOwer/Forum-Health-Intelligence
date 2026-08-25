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
 * ACP module descriptor for the content health reports.
 */
class content_info
{
	/**
	 * {@inheritdoc}
	 */
	public function module()
	{
		return [
			'filename'	=> '\salvocortesiano\forumhealth\acp\content_module',
			'title'		=> 'ACP_FH_CONTENT',
			'modes'		=> [
				'unanswered'	=> [
					'title'	=> 'ACP_FH_UNANSWERED',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
				'duplicates'	=> [
					'title'	=> 'ACP_FH_DUPLICATES',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
				'links'			=> [
					'title'	=> 'ACP_FH_LINKS',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
				'freshness'		=> [
					'title'	=> 'ACP_FH_FRESHNESS',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
				'solutions'		=> [
					'title'	=> 'ACP_FH_SOLUTIONS',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
			],
		];
	}
}
