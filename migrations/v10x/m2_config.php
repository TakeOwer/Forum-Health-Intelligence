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
 * Installs configuration defaults.
 *
 * Every default is deliberately conservative: the extension installs in a state
 * that is safe on a large forum and that performs no external request at all
 * until an administrator opts in.
 */
class m2_config extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return ['\salvocortesiano\forumhealth\migrations\v10x\m1_initial_schema'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return isset($this->config['fh_version']);
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return [
			// --- General ---------------------------------------------------
			['config.add', ['fh_version', '1.0.0']],
			// Bumped whenever analysis-relevant settings change; part of the AI
			// cache key so stale results are never reused after a config change.
			['config.add', ['fh_config_version', 1]],
			['config.add', ['fh_enabled', 1]],
			['config.add', ['fh_background_enabled', 1]],
			['config.add', ['fh_batch_size', 200]],
			['config.add', ['fh_cache_ttl', 900]],
			['config.add', ['fh_log_level', 1]],

			// --- Content health --------------------------------------------
			['config.add', ['fh_content_enabled', 1]],
			// Forums excluded from all analysis (comma separated forum ids).
			['config.add', ['fh_excluded_forums', '']],
			['config.add', ['fh_unanswered_hours', 48]],
			['config.add', ['fh_unanswered_min_views', 100]],
			['config.add', ['fh_unanswered_max_age_days', 180]],

			// --- Duplicate detection ---------------------------------------
			['config.add', ['fh_duplicates_enabled', 1]],
			// Similarity percentage below which a candidate is discarded.
			['config.add', ['fh_duplicate_threshold', 62]],
			['config.add', ['fh_duplicate_high_threshold', 82]],
			['config.add', ['fh_duplicate_window_days', 730]],
			['config.add', ['fh_duplicate_same_forum_bonus', 8]],
			['config.add', ['fh_min_token_length', 3]],

			// --- User-facing duplicate warning -----------------------------
			['config.add', ['fh_user_warning_enabled', 0]],
			['config.add', ['fh_user_warning_threshold', 70]],
			['config.add', ['fh_user_warning_limit', 5]],
			['config.add', ['fh_related_topics_enabled', 0]],
			['config.add', ['fh_related_topics_limit', 5]],

			// --- Broken links ----------------------------------------------
			// Off by default: it is the only feature that makes outbound requests.
			['config.add', ['fh_links_enabled', 0]],
			['config.add', ['fh_link_timeout', 8]],
			['config.add', ['fh_link_batch', 25]],
			['config.add', ['fh_link_recheck_days', 30]],
			['config.add', ['fh_link_retry_days', 2]],
			['config.add', ['fh_link_max_fails', 3]],
			['config.add', ['fh_link_max_redirects', 3]],
			['config.add', ['fh_link_delay_ms', 250]],
			['config.add', ['fh_link_ignore_domains', '']],
			['config.add', ['fh_link_ignore_patterns', '']],
			['config.add', ['fh_link_allow_private_hosts', 0]],

			// --- Freshness --------------------------------------------------
			['config.add', ['fh_freshness_enabled', 1]],
			['config.add', ['fh_freshness_months', 24]],
			['config.add', ['fh_freshness_min_views', 50]],

			// --- Solution detection ------------------------------------------
			['config.add', ['fh_solutions_enabled', 1]],
			['config.add', ['fh_solution_min_confidence', 60]],
			['config.add', ['fh_solution_auto_mark', 0]],

			// --- Recurring questions / knowledge -------------------------------
			['config.add', ['fh_recurring_enabled', 1]],
			['config.add', ['fh_recurring_min_topics', 5]],
			['config.add', ['fh_knowledge_enabled', 1]],

			// --- Community health ---------------------------------------------
			['config.add', ['fh_community_enabled', 1]],
			['config.add', ['fh_newuser_reply_hours', 48]],
			['config.add', ['fh_newuser_alert_threshold', 10]],
			['config.add', ['fh_activity_drop_percent', 25]],
			['config.add', ['fh_trend_period_days', 30]],
			['config.add', ['fh_moderator_load_threshold', 100]],

			// --- Alerts ---------------------------------------------------------
			['config.add', ['fh_alerts_enabled', 1]],
			['config.add', ['fh_alerts_max_per_run', 200]],

			// --- Rules -----------------------------------------------------------
			['config.add', ['fh_rules_enabled', 1]],

			// --- Health scoring weights (transparent and configurable) ------------
			['config.add', ['fh_weight_unanswered', 25]],
			['config.add', ['fh_weight_duplicates', 15]],
			['config.add', ['fh_weight_links', 20]],
			['config.add', ['fh_weight_freshness', 20]],
			['config.add', ['fh_weight_solutions', 20]],
			['config.add', ['fh_weight_participation', 30]],
			['config.add', ['fh_weight_responsiveness', 30]],
			['config.add', ['fh_weight_onboarding', 25]],
			['config.add', ['fh_weight_retention', 15]],

			// --- Meilisearch integration (opt-in, requires explicit binding) -------
			['config.add', ['fh_meilisearch_enabled', 0]],
			// Service id of the host extension's search/index service. Empty means
			// "not bound": no service name is ever guessed.
			['config.add', ['fh_meilisearch_service', '']],
			['config.add', ['fh_meilisearch_state', 'unknown']],
			['config.add', ['fh_meilisearch_checked', 0]],
			['config.add', ['fh_meilisearch_failures', 0]],

			// --- AI Bots integration (opt-in, requires explicit binding) -----------
			['config.add', ['fh_ai_enabled', 0]],
			['config.add', ['fh_ai_service', '']],

			// Which AI Reply bot supplies the credentials, model and endpoint.
			// Zero means none chosen, which is why AI stays unavailable until
			// an administrator picks one even after enabling the integration.
			['config.add', ['fh_ai_bot_id', 0]],
			['config.add', ['fh_ai_state', 'unknown']],
			['config.add', ['fh_ai_checked', 0]],
			['config.add', ['fh_ai_failures', 0]],
			['config.add', ['fh_ai_daily_limit', 200]],
			['config.add', ['fh_ai_used_today', 0]],
			['config.add', ['fh_ai_used_day', 0]],
			['config.add', ['fh_ai_cache_days', 30]],
			['config.add', ['fh_ai_min_candidate_conf', 50]],
			['config.add', ['fh_ai_feature_duplicates', 1]],
			['config.add', ['fh_ai_feature_solutions', 1]],
			['config.add', ['fh_ai_feature_freshness', 0]],
			['config.add', ['fh_ai_feature_knowledge', 1]],
			['config.add', ['fh_ai_feature_conflicts', 0]],

			// --- Privacy -----------------------------------------------------------
			// Private messages are never analysed. The switch exists so the answer
			// is visible and auditable in the ACP rather than implicit.
			['config.add', ['fh_privacy_analyse_pms', 0]],
			['config.add', ['fh_privacy_send_content_to_ai', 0]],
			['config.add', ['fh_privacy_user_metrics', 1]],

			// --- Retention ---------------------------------------------------------
			['config.add', ['fh_retain_alerts_days', 90]],
			['config.add', ['fh_retain_links_days', 180]],
			['config.add', ['fh_retain_metrics_days', 400]],
			['config.add', ['fh_retain_relations_days', 180]],
		];
	}
}
