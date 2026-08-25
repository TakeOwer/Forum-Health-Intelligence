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

use salvocortesiano\forumhealth\service\integrations\ai\provider_interface as ai_interface;
use salvocortesiano\forumhealth\service\integrations\bridge\aireply_bridge;
use salvocortesiano\forumhealth\service\integrations\bridge\meilisearch_bridge;

/**
 * Tests the two bridges' parsing, against the response shapes the real
 * extensions actually produce.
 *
 * These are the parts of a bridge that break silently. A misread ranking score
 * produces a plausible wrong confidence; a model that answers in prose instead
 * of JSON produces either a null or, if the parser is too eager, a fabricated
 * verdict with nothing behind it. Both are worse than an error.
 */
class bridge_parsing_test extends \phpbb_test_case
{
	/**
	 * Reach a protected method for testing.
	 *
	 * @param object $object Instance.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	protected function call($object, $method, array $args)
	{
		$reflection = new \ReflectionMethod(get_class($object), $method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs($object, $args);
	}

	/**
	 * Build a Meilisearch bridge whose post-to-topic mapping is fixed.
	 *
	 * @param array $map Post id to topic id.
	 * @return meilisearch_bridge
	 */
	protected function search_bridge(array $map)
	{
		$container = $this->getMockBuilder('\Symfony\Component\DependencyInjection\ContainerInterface')->getMock();

		$posts = $this->getMockBuilder('\salvocortesiano\forumhealth\repository\post_repository')
			->disableOriginalConstructor()->getMock();
		$posts->method('topic_ids_for_posts')->willReturnCallback(function (array $ids) use ($map) {
			$out = [];

			foreach ($ids as $id)
			{
				if (isset($map[$id]))
				{
					$out[$id] = $map[$id];
				}
			}

			return $out;
		});

		$logger = $this->getMockBuilder('\salvocortesiano\forumhealth\service\logger')
			->disableOriginalConstructor()->getMock();

		return new meilisearch_bridge($container, $posts, $logger);
	}

	/**
	 * A ranking score of 0..1 becomes a confidence of 0-100.
	 *
	 * @return void
	 */
	public function test_ranking_score_is_converted_to_percent()
	{
		$bridge = $this->search_bridge([101 => 11]);

		$out = $this->call($bridge, 'to_topics', [
			['hits' => [['post_id' => 101, '_rankingScore' => 0.94]]],
			10,
			0,
		]);

		$this->assertSame([['topic_id' => 11, 'score' => 94]], $out);
	}

	/**
	 * A server too old to report a ranking score falls back to rank position,
	 * and never claims full confidence for doing so.
	 *
	 * @return void
	 */
	public function test_missing_ranking_score_falls_back_to_rank()
	{
		$bridge = $this->search_bridge([101 => 11, 102 => 12]);

		$out = $this->call($bridge, 'to_topics', [
			['hits' => [['post_id' => 101], ['post_id' => 102]]],
			10,
			0,
		]);

		$this->assertSame(90, $out[0]['score']);
		$this->assertSame(85, $out[1]['score']);
		$this->assertLessThan(100, $out[0]['score']);
	}

	/**
	 * Two matching posts in one topic yield one result, keeping the better rank.
	 *
	 * @return void
	 */
	public function test_topics_are_deduplicated()
	{
		$bridge = $this->search_bridge([201 => 22, 202 => 22]);

		$out = $this->call($bridge, 'to_topics', [
			['hits' => [
				['post_id' => 201, '_rankingScore' => 0.8],
				['post_id' => 202, '_rankingScore' => 0.7],
			]],
			10,
			0,
		]);

		$this->assertCount(1, $out);
		$this->assertSame(80, $out[0]['score']);
	}

	/**
	 * The excluded topic, malformed hits and deleted posts all drop out.
	 *
	 * @return void
	 */
	public function test_exclusions_and_malformed_hits()
	{
		$bridge = $this->search_bridge([301 => 33, 302 => 44]);

		$out = $this->call($bridge, 'to_topics', [
			['hits' => [
				['post_id' => 301, '_rankingScore' => 0.9],
				['post_id' => 302, '_rankingScore' => 0.5],
				// A post whose row no longer exists.
				['post_id' => 999, '_rankingScore' => 0.5],
				// A hit with no post_id at all.
				['topic_id' => 7],
			]],
			10,
			33,
		]);

		$this->assertSame([['topic_id' => 44, 'score' => 50]], $out);
	}

	/**
	 * An empty or malformed response is an empty list, never an error.
	 *
	 * @return void
	 */
	public function test_empty_response()
	{
		$bridge = $this->search_bridge([]);

		$this->assertSame([], $this->call($bridge, 'to_topics', [[], 10, 0]));
		$this->assertSame([], $this->call($bridge, 'to_topics', [['hits' => 'nonsense'], 10, 0]));
	}

	/**
	 * A rejected filter is reported differently from an unreachable server.
	 *
	 * These two look identical from the outside — no results, no visible error —
	 * and need completely different responses. Naming them apart is the whole
	 * reason the classifier exists.
	 *
	 * @return void
	 */
	public function test_index_misconfiguration_is_distinguished()
	{
		$bridge = $this->search_bridge([]);

		foreach ([
			'Meilisearch API error [invalid_search_filter]: Attribute `is_first_post` is not filterable.',
			'Meilisearch API error [index_not_found]: Index `phpbb_posts` not found.',
		] as $error)
		{
			$this->assertTrue(
				$this->call($bridge, 'is_index_configuration_error', [$error]),
				'should be recognised as a configuration problem: ' . $error
			);
		}

		foreach ([
			'cURL error: Failed to connect to localhost port 7700: Connection refused',
			'Meilisearch URL is not configured.',
			'',
		] as $error)
		{
			$this->assertFalse(
				$this->call($bridge, 'is_index_configuration_error', [$error]),
				'should not be mistaken for a configuration problem: ' . $error
			);
		}
	}

	/**
	 * Build an AI bridge for parser testing.
	 *
	 * @return aireply_bridge
	 */
	protected function ai_bridge()
	{
		$container = $this->getMockBuilder('\Symfony\Component\DependencyInjection\ContainerInterface')->getMock();
		$settings = $this->getMockBuilder('\salvocortesiano\forumhealth\service\settings')
			->disableOriginalConstructor()->getMock();
		$logger = $this->getMockBuilder('\salvocortesiano\forumhealth\service\logger')
			->disableOriginalConstructor()->getMock();

		return new aireply_bridge($container, $settings, $logger);
	}

	/**
	 * Model outputs and what should come of them.
	 *
	 * @return array[]
	 */
	public function model_outputs()
	{
		return [
			'clean json' => [
				'{"confidence":82,"verdict":"duplicate","summary":"Both ask how to reset a password.","reference":0}',
				82,
			],
			'markdown fenced' => [
				"```json\n{\"confidence\":40,\"verdict\":\"related\",\"summary\":\"Same area.\",\"reference\":0}\n```",
				40,
			],
			'wrapped in prose' => [
				'Sure! Here is the analysis: {"confidence":15,"verdict":"unrelated","summary":"Different topics.","reference":0} Hope that helps.',
				15,
			],
			// The model ignored the instruction entirely. Nothing may be
			// salvaged from this: a guess would look like a finding.
			'prose only' => ['These two topics appear to be duplicates.', null],
			'empty' => ['', null],
			'no confidence field' => ['{"verdict":"duplicate","summary":"x"}', null],
			'confidence out of range' => ['{"confidence":250,"verdict":"duplicate","summary":"x"}', null],
			'truncated json' => ['{"confidence":80,"verdict":"dup', null],
		];
	}

	/**
	 * @dataProvider model_outputs
	 *
	 * @param string   $output   Raw model text.
	 * @param int|null $expected Expected confidence, or null for rejection.
	 * @return void
	 */
	public function test_ai_output_parsing($output, $expected)
	{
		$result = $this->call($this->ai_bridge(), 'parse', [ai_interface::CAP_DUPLICATE, $output]);

		if ($expected === null)
		{
			$this->assertNull($result);

			return;
		}

		$this->assertIsArray($result);
		$this->assertSame($expected, $result['confidence']);
	}

	/**
	 * A solution verdict carries the post id back.
	 *
	 * @return void
	 */
	public function test_reference_is_preserved()
	{
		$result = $this->call($this->ai_bridge(), 'parse', [
			ai_interface::CAP_SOLUTION,
			'{"confidence":91,"verdict":"solved","summary":"Post 4471 gives the fix.","reference":4471}',
		]);

		$this->assertSame(4471, $result['reference']);
		$this->assertSame('solved', $result['verdict']);
	}

	/**
	 * A payload missing what the question needs produces no question at all,
	 * so no request is made and no budget is spent.
	 *
	 * @return void
	 */
	public function test_incomplete_payload_asks_nothing()
	{
		$bridge = $this->ai_bridge();

		$this->assertNull($this->call($bridge, 'question', [ai_interface::CAP_DUPLICATE, ['a' => 'only one side']]));
		$this->assertNull($this->call($bridge, 'question', [ai_interface::CAP_SOLUTION, ['question' => 'x']]));
		$this->assertNull($this->call($bridge, 'question', ['not_a_capability', ['a' => 'x', 'b' => 'y']]));
	}

	/**
	 * The question states the permitted verdicts, so the model has a closed set
	 * to choose from rather than inventing vocabulary.
	 *
	 * @return void
	 */
	public function test_question_constrains_the_verdict()
	{
		$question = $this->call($this->ai_bridge(), 'question', [
			ai_interface::CAP_DUPLICATE,
			['a' => 'How do I reset my password', 'b' => 'Cannot sign in any more'],
		]);

		$this->assertStringContainsString('duplicate, related, unrelated', $question);
	}
}
