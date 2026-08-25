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

use salvocortesiano\forumhealth\constants;

/**
 * Every combination of the two optional integrations being absent, broken and
 * working.
 *
 * This is the most important test in the extension. The central promise is that
 * Forum Health does something useful on a plain phpBB installation with nothing
 * else present, and that a search server going down or an AI provider throwing
 * an exception degrades a feature rather than breaking a page.
 *
 * That promise is easy to make and easy to break: it takes one adapter call
 * outside a try/catch, or one report that assumes a provider returned an array.
 * Nine combinations is not thoroughness for its own sake — each column here is a
 * real state a real forum will be in.
 *
 * @group functional
 */
class integration_matrix_test extends \phpbb_functional_test_case
{
	/**
	 * {@inheritdoc}
	 */
	protected static function setup_extensions()
	{
		return ['salvocortesiano/forumhealth'];
	}

	/**
	 * The three states each integration can be in.
	 *
	 * absent  — nothing bound at all, the default on a fresh forum
	 * broken  — a provider is bound but every call throws
	 * working — a provider is bound and answers
	 *
	 * @return array[]
	 */
	public function integration_states()
	{
		$states = ['absent', 'broken', 'working'];
		$matrix = [];

		foreach ($states as $search)
		{
			foreach ($states as $ai)
			{
				$matrix[$search . '/' . $ai] = [$search, $ai];
			}
		}

		return $matrix;
	}

	/**
	 * The dashboard renders in every combination.
	 *
	 * @dataProvider integration_states
	 *
	 * @param string $search Search integration state.
	 * @param string $ai     AI integration state.
	 * @return void
	 */
	public function test_dashboard_renders($search, $ai)
	{
		$this->bind($search, $ai);
		$this->login();
		$this->admin_login();

		$crawler = self::request('GET', 'adm/index.php?i=-salvocortesiano-forumhealth-acp-dashboard_module&mode=dashboard&sid=' . $this->sid);

		$this->assertGreaterThan(0, $crawler->filter('h1')->count());
		$this->assertStringNotContainsString('Fatal error', $crawler->text());
		$this->assertStringNotContainsString('Exception', $crawler->text());
	}

	/**
	 * Duplicate detection produces results with no integration at all.
	 *
	 * If this ever fails, the extension has quietly become dependent on
	 * something it advertises as optional.
	 *
	 * @return void
	 */
	public function test_native_duplicate_detection_without_any_integration()
	{
		$this->bind('absent', 'absent');

		$detector = $this->get_container()->get('salvocortesiano.forumhealth.content.duplicates');
		$candidates = $detector->find_candidates(0, 'how do i reset my password', 2, 5);

		$this->assertIsArray($candidates);
	}

	/**
	 * A failing provider is recorded and the caller still gets an answer.
	 *
	 * @return void
	 */
	public function test_broken_provider_degrades_rather_than_throws()
	{
		$this->bind('broken', 'broken');

		$container = $this->get_container();
		$registry = $container->get('salvocortesiano.forumhealth.integrations.registry');
		$registry->refresh();

		$detector = $container->get('salvocortesiano.forumhealth.content.duplicates');

		// The call must return, not raise. A search server that has fallen over
		// should cost the forum some accuracy, not an error page.
		$candidates = $detector->find_candidates(0, 'how do i reset my password', 2, 5);

		$this->assertIsArray($candidates);

		$status = $registry->search_status();
		$this->assertContains($status['state'], [constants::INT_DEGRADED, constants::INT_UNAVAILABLE]);
	}

	/**
	 * With AI switched off, no AI call is made whatever else is configured.
	 *
	 * @return void
	 */
	public function test_ai_disabled_makes_no_calls()
	{
		$this->bind('absent', 'working');

		$container = $this->get_container();
		$container->get('salvocortesiano.forumhealth.settings')->set('fh_ai_enabled', 0);

		$adapter = $container->get('salvocortesiano.forumhealth.integrations.ai');

		$this->assertFalse($adapter->is_available());
		$this->assertSame(0, $adapter->used_today());
	}

	/**
	 * The public duplicate endpoint answers identically when unavailable.
	 *
	 * @return void
	 */
	public function test_public_endpoint_is_not_probeable()
	{
		$this->bind('absent', 'absent');
		$this->login();

		$content = self::request(
			'GET',
			'app.php/forumhealth/similar/1?title=something+plausible+here',
			[],
			false
		);

		$body = $content->getResponse()->getContent();
		$data = json_decode($body, true);

		$this->assertIsArray($data);
		$this->assertArrayHasKey('found', $data);
		$this->assertFalse($data['found']);
	}

	/**
	 * Configure the two integrations into the requested state.
	 *
	 * A "working" or "broken" provider is a stub implementing this extension's
	 * own interface. That is the correct thing to test against: the interface is
	 * the contract, and nothing in the extension knows or cares what lies behind
	 * a real bridge.
	 *
	 * @param string $search Search integration state.
	 * @param string $ai     AI integration state.
	 * @return void
	 */
	protected function bind($search, $ai)
	{
		$container = $this->get_container();
		$settings = $container->get('salvocortesiano.forumhealth.settings');

		$settings->set('fh_meilisearch_enabled', $search === 'absent' ? 0 : 1);
		$settings->set('fh_ai_enabled', $ai === 'absent' ? 0 : 1);

		$settings->set('fh_meilisearch_service', $search === 'absent' ? '' : 'test.forumhealth.stub_search_' . $search);
		$settings->set('fh_ai_service', $ai === 'absent' ? '' : 'test.forumhealth.stub_ai_' . $ai);

		$settings->set('fh_meilisearch_failures', 0);
		$settings->set('fh_ai_failures', 0);
	}
}
