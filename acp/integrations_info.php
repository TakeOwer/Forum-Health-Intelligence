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
 * ACP module descriptor for integrations, AI and job status.
 */
class integrations_info
{
	/**
	 * {@inheritdoc}
	 */
	public function module()
	{
		return [
			'filename'	=> '\salvocortesiano\forumhealth\acp\integrations_module',
			'title'		=> 'ACP_FH_INTEGRATIONS',
			'modes'		=> [
				'integrations'	=> [
					'title'	=> 'ACP_FH_INTEGRATIONS',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_manage_integrations',
					'cat'	=> ['ACP_FH_TITLE'],
				],
				'ai'			=> [
					'title'	=> 'ACP_FH_AI',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_manage_ai',
					'cat'	=> ['ACP_FH_TITLE'],
				],
				'jobs'			=> [
					'title'	=> 'ACP_FH_JOBS',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
			],
		];
	}
}
