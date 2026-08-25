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
 * ACP module descriptor for alerts and recommendations.
 */
class alerts_info
{
	/**
	 * {@inheritdoc}
	 */
	public function module()
	{
		return [
			'filename'	=> '\salvocortesiano\forumhealth\acp\alerts_module',
			'title'		=> 'ACP_FH_ALERTS',
			'modes'		=> [
				'alerts'			=> [
					'title'	=> 'ACP_FH_ALERTS',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
				'recommendations'	=> [
					'title'	=> 'ACP_FH_RECOMMENDATIONS',
					'auth'	=> 'ext_salvocortesiano/forumhealth && acl_a_fh_view',
					'cat'	=> ['ACP_FH_TITLE'],
				],
			],
		];
	}
}
