<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\tests\unit;

/**
 * Tests that the two shipped languages stay in step.
 *
 * Bilingual support degrades quietly: somebody adds a key in English, forgets
 * the Italian, and an Italian administrator sees a raw FH_SOMETHING on a page
 * months later. This test makes that a build failure instead.
 *
 * It also compares printf placeholders, because a translated string that drops
 * a %s or reorders arguments without positional syntax produces either a
 * mangled sentence or a PHP warning.
 */
class language_completeness_test extends \phpbb_test_case
{
	/**
	 * Load one language file in isolation.
	 *
	 * @param string $path Relative path from the extension root.
	 * @return array
	 */
	protected function load($path)
	{
		$lang = [];

		if (!defined('IN_PHPBB'))
		{
			define('IN_PHPBB', true);
		}

		require dirname(__DIR__) . '/../' . $path;

		return $lang;
	}

	/**
	 * The file pairs that must match.
	 *
	 * @return array[]
	 */
	public function language_files()
	{
		return [
			'common'	=> ['language/en/common.php', 'language/it/common.php'],
			'acp'		=> ['language/en/acp_forumhealth.php', 'language/it/acp_forumhealth.php'],
		];
	}

	/**
	 * @dataProvider language_files
	 *
	 * @param string $en English file.
	 * @param string $it Italian file.
	 * @return void
	 */
	public function test_no_key_is_missing($en, $it)
	{
		$english = $this->load($en);
		$italian = $this->load($it);

		$this->assertSame(
			[],
			array_keys(array_diff_key($english, $italian)),
			'Keys present in English but missing from Italian'
		);

		$this->assertSame(
			[],
			array_keys(array_diff_key($italian, $english)),
			'Keys present in Italian but missing from English'
		);
	}

	/**
	 * @dataProvider language_files
	 *
	 * @param string $en English file.
	 * @param string $it Italian file.
	 * @return void
	 */
	public function test_placeholders_match($en, $it)
	{
		$english = $this->load($en);
		$italian = $this->load($it);

		foreach ($english as $key => $value)
		{
			if (!is_string($value) || !isset($italian[$key]) || !is_string($italian[$key]))
			{
				continue;
			}

			$this->assertSame(
				preg_match_all('/%(?:\d+\$)?[sd]/', $value),
				preg_match_all('/%(?:\d+\$)?[sd]/', $italian[$key]),
				'Placeholder count differs for ' . $key
			);
		}
	}

	/**
	 * No translated string may be left as a copy of the English.
	 *
	 * A handful of strings legitimately match: proper nouns, "OK", and the
	 * extension's own name.
	 *
	 * @return void
	 */
	public function test_translations_are_actually_translated()
	{
		$english = $this->load('language/en/acp_forumhealth.php');
		$italian = $this->load('language/it/acp_forumhealth.php');

		$allowed_identical = ['ACP_FH_TITLE', 'FH_EXTENSION_NAME', 'FH_JOB_STATE_OK', 'FH_ACP_FH_AI'];
		$identical = [];

		foreach ($english as $key => $value)
		{
			if (!isset($italian[$key]) || in_array($key, $allowed_identical, true))
			{
				continue;
			}

			// Very short labels can legitimately coincide between the two
			// languages; anything substantial should not.
			if ($value === $italian[$key] && mb_strlen($value) > 12)
			{
				$identical[] = $key;
			}
		}

		$this->assertSame([], $identical, 'Italian strings identical to English');
	}
}
