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

use salvocortesiano\forumhealth\service\text\normaliser;

/**
 * Tests text normalisation and similarity.
 *
 * The interesting cases are the ones where a naive implementation gets it
 * wrong: two titles that share every word but mean opposite things, two that
 * share almost no words but ask the same question, and titles in Italian, where
 * accents and a different stopword set both matter.
 */
class normaliser_test extends \phpbb_test_case
{
	/** @var normaliser */
	protected $normaliser;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$settings = $this->getMockBuilder('\salvocortesiano\forumhealth\service\settings')
			->disableOriginalConstructor()->getMock();
		$settings->method('get_string')->willReturn('');
		$settings->method('get_int')->willReturn(3);

		$this->normaliser = new normaliser($settings);
	}

	/**
	 * Case, accents and punctuation should not change the tokens.
	 *
	 * @return void
	 */
	public function test_normalisation_is_stable()
	{
		$a = $this->normaliser->tokenise('Perché il Login NON funziona?!');
		$b = $this->normaliser->tokenise('perche il login non funziona');

		$this->assertSame($a, $b);
	}

	/**
	 * Stopwords are removed in both supported languages.
	 *
	 * @return void
	 */
	public function test_stopwords_removed()
	{
		$tokens = $this->normaliser->tokenise('how do i install the extension on a forum');

		$this->assertNotContains('the', $tokens);
		$this->assertNotContains('a', $tokens);
		$this->assertContains('install', $tokens);

		$italian = $this->normaliser->tokenise('come si installa una estensione sul forum');

		$this->assertNotContains('una', $italian);
		$this->assertContains('installa', $italian);
	}

	/**
	 * Near-identical questions score high.
	 *
	 * @return void
	 */
	public function test_similar_titles_score_high()
	{
		$score = $this->normaliser->similarity(
			'How do I reset my password?',
			'How can I reset my password'
		);

		$this->assertGreaterThanOrEqual(60, $score);
	}

	/**
	 * Unrelated questions score low even when they share common words.
	 *
	 * @return void
	 */
	public function test_unrelated_titles_score_low()
	{
		$score = $this->normaliser->similarity(
			'How do I reset my password?',
			'How do I change the board logo?'
		);

		$this->assertLessThan(45, $score);
	}

	/**
	 * A short title contained in a longer one is recognised.
	 *
	 * Containment matters because real duplicate titles are frequently one
	 * person's terse question and another's verbose version of it.
	 *
	 * @return void
	 */
	public function test_containment_is_recognised()
	{
		$score = $this->normaliser->similarity(
			'install extension',
			'how do i install extension on phpbb 3.3'
		);

		$this->assertGreaterThanOrEqual(50, $score);
	}

	/**
	 * Version fragments are extracted for the freshness signal.
	 *
	 * @return void
	 */
	public function test_version_fragments()
	{
		$this->assertNotEmpty($this->normaliser->version_fragments('Upgrading to phpBB 3.2.1'));
		$this->assertEmpty($this->normaliser->version_fragments('Welcome to the forum'));
	}

	/**
	 * An empty or whitespace-only title yields no tokens rather than an error.
	 *
	 * @return void
	 */
	public function test_empty_input()
	{
		$this->assertSame([], $this->normaliser->tokenise('   '));
		$this->assertSame(0, $this->normaliser->similarity('', ''));
	}
}
