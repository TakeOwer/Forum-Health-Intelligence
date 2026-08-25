<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\tests\functional;

/**
 * Installation, defaults and clean removal.
 *
 * The defaults test is the one that matters most. This extension can make
 * outbound requests and can send text to a third party, and the promise is that
 * it does neither until an administrator asks. A default flipped by accident
 * would be a serious breach of that promise and would be invisible in normal
 * use, because everything would simply appear to work.
 *
 * @group functional
 */
class install_test extends \phpbb_functional_test_case
{
	/**
	 * {@inheritdoc}
	 */
	protected static function setup_extensions()
	{
		return ['salvocortesiano/forumhealth'];
	}

	/**
	 * Every table the migrations declare actually exists.
	 *
	 * @return void
	 */
	public function test_tables_created()
	{
		$db = $this->get_db();
		$prefix = $this->get_container()->getParameter('core.table_prefix');

		$tables = [
			'fh_topic_metrics', 'fh_topic_relations', 'fh_links', 'fh_link_occurrences',
			'fh_alerts', 'fh_metrics_history', 'fh_rules', 'fh_ai_cache', 'fh_jobs',
		];

		foreach ($tables as $table)
		{
			$result = $db->sql_query_limit('SELECT * FROM ' . $prefix . $table, 1);
			$this->assertNotFalse($result, $prefix . $table . ' should exist');
			$db->sql_freeresult($result);
		}
	}

	/**
	 * Nothing that reaches outside the forum is on by default.
	 *
	 * @return void
	 */
	public function test_outbound_features_are_off_by_default()
	{
		$config = $this->get_container()->get('config');

		$this->assertEquals(0, $config['fh_links_enabled'], 'link scanning must be off by default');
		$this->assertEquals(0, $config['fh_ai_enabled'], 'AI must be off by default');
		$this->assertEquals(0, $config['fh_meilisearch_enabled'], 'search integration must be off by default');
		$this->assertEquals(0, $config['fh_privacy_send_content_to_ai'], 'post text must not be sent by default');
		$this->assertEquals(0, $config['fh_privacy_analyse_pms'], 'private messages must never be analysed');
		$this->assertEquals(0, $config['fh_link_allow_private_hosts'], 'private hosts must be refused by default');
	}

	/**
	 * Analysis itself is on, because an extension that does nothing on
	 * installation is a support ticket waiting to happen.
	 *
	 * @return void
	 */
	public function test_local_analysis_is_on_by_default()
	{
		$config = $this->get_container()->get('config');

		$this->assertEquals(1, $config['fh_enabled']);
		$this->assertEquals(1, $config['fh_background_enabled']);
		$this->assertEquals(1, $config['fh_content_enabled']);
		$this->assertEquals(1, $config['fh_community_enabled']);
	}

	/**
	 * The seeded jobs are present and the example rules are disabled.
	 *
	 * Shipping an enabled example rule would mean a fresh installation starts
	 * generating alerts nobody asked for.
	 *
	 * @return void
	 */
	public function test_seed_data()
	{
		$container = $this->get_container();

		$this->assertCount(5, $container->get('salvocortesiano.forumhealth.repository.jobs')->all());

		foreach ($container->get('salvocortesiano.forumhealth.repository.rules')->all() as $rule)
		{
			$this->assertEquals(0, (int) $rule['rule_enabled'], 'example rules must ship disabled');
		}
	}

	/**
	 * The ACP modules were registered under the extension's own category.
	 *
	 * @return void
	 */
	public function test_acp_modules_registered()
	{
		$this->login();
		$this->admin_login();

		$crawler = self::request('GET', 'adm/index.php?sid=' . $this->sid);

		$this->assertStringContainsString('Forum Health', $crawler->text());
	}

	/**
	 * All seven permissions exist.
	 *
	 * @return void
	 */
	public function test_permissions_registered()
	{
		$db = $this->get_db();
		$prefix = $this->get_container()->getParameter('core.table_prefix');

		$result = $db->sql_query(
			'SELECT auth_option FROM ' . $prefix . "acl_options WHERE auth_option LIKE 'a_fh_%'"
		);
		$found = [];

		while ($row = $db->sql_fetchrow($result))
		{
			$found[] = $row['auth_option'];
		}

		$db->sql_freeresult($result);

		$this->assertCount(7, $found);
	}
}
