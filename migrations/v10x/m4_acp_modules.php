<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\migrations\v10x;

/**
 * Builds the ACP navigation tree.
 *
 * The tree mirrors the product information architecture: a top level category
 * with one entry per area of responsibility, so that no single page has to carry
 * every feature.
 */
class m4_acp_modules extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return ['\salvocortesiano\forumhealth\migrations\v10x\m3_permissions'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return [
			// Top level category under ACP -> General.
			['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_FH_TITLE']],

			['module.add', ['acp', 'ACP_FH_TITLE', [
				'module_basename'	=> '\salvocortesiano\forumhealth\acp\dashboard_module',
				'modes'				=> ['dashboard'],
			]]],

			['module.add', ['acp', 'ACP_FH_TITLE', [
				'module_basename'	=> '\salvocortesiano\forumhealth\acp\content_module',
				'modes'				=> ['unanswered', 'duplicates', 'links', 'freshness', 'solutions'],
			]]],

			['module.add', ['acp', 'ACP_FH_TITLE', [
				'module_basename'	=> '\salvocortesiano\forumhealth\acp\community_module',
				'modes'				=> ['overview', 'newusers', 'trends', 'contributors'],
			]]],

			['module.add', ['acp', 'ACP_FH_TITLE', [
				'module_basename'	=> '\salvocortesiano\forumhealth\acp\alerts_module',
				'modes'				=> ['alerts', 'recommendations'],
			]]],

			['module.add', ['acp', 'ACP_FH_TITLE', [
				'module_basename'	=> '\salvocortesiano\forumhealth\acp\rules_module',
				'modes'				=> ['rules'],
			]]],

			['module.add', ['acp', 'ACP_FH_TITLE', [
				'module_basename'	=> '\salvocortesiano\forumhealth\acp\integrations_module',
				'modes'				=> ['integrations', 'ai', 'jobs'],
			]]],

			['module.add', ['acp', 'ACP_FH_TITLE', [
				'module_basename'	=> '\salvocortesiano\forumhealth\acp\settings_module',
				'modes'				=> ['settings'],
			]]],
		];
	}
}
