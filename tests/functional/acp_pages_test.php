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
 * Every ACP page loads on an empty forum.
 *
 * The empty case is the one that breaks. A report written against a populated
 * database frequently divides by a count that is zero on a fresh installation,
 * or renders a chart from a series that does not exist yet. Since a fresh
 * installation is precisely what an administrator sees first, these pages get
 * checked in exactly that state.
 *
 * @group functional
 */
class acp_pages_test extends \phpbb_functional_test_case
{
	/**
	 * {@inheritdoc}
	 */
	protected static function setup_extensions()
	{
		return ['salvocortesiano/forumhealth'];
	}

	/**
	 * Every module and mode the extension registers.
	 *
	 * @return array[]
	 */
	public function acp_modes()
	{
		return [
			'dashboard'			=> ['dashboard', 'dashboard'],
			'unanswered'		=> ['content', 'unanswered'],
			'duplicates'		=> ['content', 'duplicates'],
			'links'				=> ['content', 'links'],
			'freshness'			=> ['content', 'freshness'],
			'solutions'			=> ['content', 'solutions'],
			'community'			=> ['community', 'overview'],
			'newusers'			=> ['community', 'newusers'],
			'trends'			=> ['community', 'trends'],
			'contributors'		=> ['community', 'contributors'],
			'alerts'			=> ['alerts', 'alerts'],
			'recommendations'	=> ['alerts', 'recommendations'],
			'rules'				=> ['rules', 'rules'],
			'integrations'		=> ['integrations', 'integrations'],
			'ai'				=> ['integrations', 'ai'],
			'jobs'				=> ['integrations', 'jobs'],
			'settings'			=> ['settings', 'settings'],
		];
	}

	/**
	 * @dataProvider acp_modes
	 *
	 * @param string $module Module name.
	 * @param string $mode   Mode name.
	 * @return void
	 */
	public function test_page_loads_on_empty_forum($module, $mode)
	{
		$this->login();
		$this->admin_login();

		$crawler = self::request('GET', sprintf(
			'adm/index.php?i=-salvocortesiano-forumhealth-acp-%s_module&mode=%s&sid=%s',
			$module,
			$mode,
			$this->sid
		));

		$text = $crawler->text();

		$this->assertGreaterThan(0, $crawler->filter('h1')->count(), $module . '/' . $mode . ' should render a heading');
		$this->assertStringNotContainsString('Fatal error', $text);
		$this->assertStringNotContainsString('Undefined', $text);

		// An untranslated key leaking onto the page is a bug, not a cosmetic
		// issue: it means a code path references a string nobody wrote.
		$this->assertDoesNotMatchRegularExpression('/\bFH_[A-Z_]{3,}\b/', $text, 'untranslated key on ' . $module . '/' . $mode);
	}

	/**
	 * A user without the view permission cannot reach the reports.
	 *
	 * @return void
	 */
	public function test_permission_is_enforced()
	{
		$this->login();

		// A logged-in ordinary member has no administrative session at all.
		$this->request('GET', 'adm/index.php?i=-salvocortesiano-forumhealth-acp-dashboard_module&mode=dashboard');

		$this->assertNotEquals(200, self::$client->getResponse()->getStatusCode());
	}
}
