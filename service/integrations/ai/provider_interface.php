<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\integrations\ai;

/**
 * The contract an AI provider must satisfy.
 *
 * Forum Health asks for an analysis and receives a structured answer. It never
 * learns which model produced it, never holds an API key, never builds an HTTP
 * client and never manages tokens or retries: all of that belongs to the AI
 * extension that already does it. A bridge service adapts that extension to this
 * interface. INTEGRATIONS.md contains a worked example.
 *
 * The capability constants are the complete vocabulary of requests this
 * extension makes. A provider that cannot serve one of them says so through
 * supports(); the corresponding feature then stays on native analysis instead of
 * failing.
 */
interface provider_interface
{
	/** Compare two topics and judge whether they ask the same thing. */
	const CAP_DUPLICATE = 'detect_duplicate';

	/** Identify which reply, if any, resolved the discussion. */
	const CAP_SOLUTION = 'detect_solution';

	/** Judge whether a discussion has been overtaken by events. */
	const CAP_OUTDATED = 'detect_outdated';

	/** Produce a short neutral summary of a discussion. */
	const CAP_SUMMARY = 'summarize_topic';

	/** Draft a knowledge article from a resolved discussion. */
	const CAP_KNOWLEDGE = 'extract_knowledge';

	/** Detect contradictory claims between two discussions. */
	const CAP_CONFLICT = 'detect_conflict';

	/**
	 * Whether the provider is configured and reachable right now.
	 *
	 * @return bool
	 */
	public function is_operational();

	/**
	 * Whether a capability is offered.
	 *
	 * @param string $capability One of the CAP_* constants.
	 * @return bool
	 */
	public function supports($capability);

	/**
	 * Short identifier of the backing implementation, shown in the ACP.
	 *
	 * Must not contain API keys, endpoints or any other secret. A model family
	 * name is fine; a credential is not.
	 *
	 * @return string
	 */
	public function describe();

	/**
	 * Perform one analysis.
	 *
	 * The payload contains only forum content the administrator has agreed to
	 * send, already trimmed by the caller. Implementations must not enrich it
	 * with anything else.
	 *
	 * The return value is a structured result, not prose, and must not include
	 * the model's intermediate reasoning:
	 *
	 *   [
	 *     'confidence' => int 0-100,
	 *     'verdict'    => string,   // capability specific, e.g. 'duplicate'
	 *     'summary'    => string,   // one or two sentences, shown to the admin
	 *     'reference'  => int,      // optional entity id, e.g. a post id
	 *   ]
	 *
	 * @param string $capability One of the CAP_* constants.
	 * @param array  $payload    Capability specific input.
	 * @return array|null Structured result, or null when the analysis could not
	 *                    be produced. Null is expected and handled; exceptions
	 *                    are caught by the adapter but should not be routine.
	 */
	public function analyse($capability, array $payload);
}
