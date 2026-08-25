<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\integrations\meilisearch;

/**
 * The contract a search provider must satisfy to enhance duplicate detection.
 *
 * This interface exists because of a deliberate constraint: this extension does
 * not know the internals of whichever Meilisearch extension is installed, and
 * guessing its service or method names would produce code that breaks the moment
 * that extension is updated. So the dependency is inverted. Forum Health defines
 * what it needs; a small bridge service supplies it.
 *
 * A bridge is a few dozen lines. It can live in the search extension itself, or
 * in a separate glue extension, and it is registered either by tagging the
 * service with "salvocortesiano.forumhealth.search_provider" or by naming it in
 * the ACP integration page. INTEGRATIONS.md contains a complete worked example.
 *
 * Implementations must never throw for ordinary conditions such as an empty
 * result or an unreachable server. Return an empty array and report the failure
 * through is_operational(); the adapter degrades from there.
 */
interface provider_interface
{
	/**
	 * Whether the underlying search service is reachable and usable right now.
	 *
	 * Cheap to call: the adapter calls it before every batch, not once per topic.
	 *
	 * @return bool
	 */
	public function is_operational();

	/**
	 * Short identifier of the backing implementation, shown in the ACP.
	 *
	 * Must not contain credentials, host names or any other secret.
	 *
	 * @return string For example "meilisearch 1.7 via acme/search".
	 */
	public function describe();

	/**
	 * Topics similar to the given text.
	 *
	 * @param string $text     Topic title, optionally with the first post.
	 * @param int    $limit    Maximum results.
	 * @param int    $exclude  Topic id to exclude from results, 0 for none.
	 * @param int    $forum_id Restrict to a forum, 0 for all forums.
	 * @return array[] Rows of ['topic_id' => int, 'score' => int 0-100].
	 *                 An empty array means "no candidates", never an error.
	 */
	public function find_similar_topics($text, $limit, $exclude = 0, $forum_id = 0);
}
