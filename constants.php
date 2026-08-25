<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth;

/**
 * Shared vocabulary of the extension.
 *
 * Everything that is written to the database as a short identifier lives here,
 * so that stored values, template output and language keys cannot drift apart.
 */
final class constants
{
	// --- Alert severity ---------------------------------------------------
	// Numeric so alerts can be ordered in SQL without a lookup table.
	const SEVERITY_INFO		= 10;
	const SEVERITY_LOW		= 20;
	const SEVERITY_MEDIUM	= 30;
	const SEVERITY_HIGH		= 40;
	const SEVERITY_CRITICAL	= 50;

	// --- Alert status -----------------------------------------------------
	const STATUS_NEW			= 'new';
	const STATUS_ACKNOWLEDGED	= 'acknowledged';
	const STATUS_RESOLVED		= 'resolved';
	const STATUS_DISMISSED		= 'dismissed';

	// --- Alert types ------------------------------------------------------
	const ALERT_DUPLICATE			= 'possible_duplicate';
	const ALERT_UNANSWERED			= 'high_view_unanswered';
	const ALERT_BROKEN_LINK			= 'broken_link';
	const ALERT_OUTDATED			= 'potentially_outdated';
	const ALERT_SOLUTION			= 'solution_detected';
	const ALERT_KNOWLEDGE			= 'knowledge_candidate';
	const ALERT_RECURRING			= 'recurring_question';
	const ALERT_ACTIVITY_DROP		= 'activity_drop';
	const ALERT_ONBOARDING			= 'onboarding_issue';
	const ALERT_MODERATOR_LOAD		= 'moderator_load';
	const ALERT_INTEGRATION_FAILURE	= 'integration_failure';
	const ALERT_RULE				= 'rule_match';

	// --- Analysis sources -------------------------------------------------
	const SOURCE_NATIVE			= 'native';
	const SOURCE_MEILISEARCH	= 'meilisearch';
	const SOURCE_AI				= 'ai';
	const SOURCE_RULE			= 'rule';

	// --- Relation types and statuses ---------------------------------------
	const RELATION_DUPLICATE	= 'duplicate';
	const RELATION_SIMILAR		= 'similar';

	const RELATION_NEW			= 'new';
	const RELATION_CONFIRMED	= 'confirmed';
	const RELATION_DISMISSED	= 'dismissed';

	// --- Link states --------------------------------------------------------
	const LINK_PENDING	= 'pending';
	const LINK_OK		= 'ok';
	const LINK_REDIRECT	= 'redirect';
	const LINK_BROKEN	= 'broken';
	const LINK_WARNING	= 'warning';
	const LINK_SKIPPED	= 'skipped';
	const LINK_UNSAFE	= 'unsafe';

	// --- Integration states -------------------------------------------------
	// The five states the specification requires the ACP to distinguish.
	const INT_NOT_INSTALLED		= 'not_installed';
	const INT_DISABLED			= 'disabled';
	const INT_ENABLED_NO_BIND	= 'enabled_not_bound';
	const INT_UNAVAILABLE		= 'unavailable';
	const INT_OPERATIONAL		= 'operational';
	const INT_DEGRADED			= 'degraded';

	// --- Job states ----------------------------------------------------------
	const JOB_IDLE		= 'idle';
	const JOB_RUNNING	= 'running';
	const JOB_OK		= 'ok';
	const JOB_DEGRADED	= 'degraded';
	const JOB_ERROR		= 'error';
	const JOB_DISABLED	= 'disabled';

	// --- Job identifiers ------------------------------------------------------
	const JOB_CONTENT	= 'content_analysis';
	const JOB_COMMUNITY	= 'community_analysis';
	const JOB_LINKS		= 'link_scanner';
	const JOB_ALERTS	= 'alert_generation';
	const JOB_CLEANUP	= 'cleanup';

	/** Maximum minutes a job lock is honoured before it is considered stale. */
	const JOB_LOCK_MINUTES = 30;

	/**
	 * All job identifiers.
	 *
	 * @return string[]
	 */
	public static function job_names()
	{
		return [
			self::JOB_CONTENT,
			self::JOB_COMMUNITY,
			self::JOB_LINKS,
			self::JOB_ALERTS,
			self::JOB_CLEANUP,
		];
	}

	/**
	 * Severity value to language-key suffix.
	 *
	 * @return array<int, string>
	 */
	public static function severity_map()
	{
		return [
			self::SEVERITY_INFO		=> 'INFO',
			self::SEVERITY_LOW		=> 'LOW',
			self::SEVERITY_MEDIUM	=> 'MEDIUM',
			self::SEVERITY_HIGH		=> 'HIGH',
			self::SEVERITY_CRITICAL	=> 'CRITICAL',
		];
	}

	/**
	 * Alert statuses an administrator may set manually.
	 *
	 * @return string[]
	 */
	public static function settable_statuses()
	{
		return [self::STATUS_ACKNOWLEDGED, self::STATUS_RESOLVED, self::STATUS_DISMISSED];
	}
}
