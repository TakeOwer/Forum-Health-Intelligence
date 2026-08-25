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
 * Creates the extension schema.
 *
 * Design notes:
 * - Nine tables only. Each one exists because a distinct read pattern needs it.
 * - Every table that is scanned incrementally carries an index on the cursor
 *   column so background jobs never perform a full table scan.
 * - No phpBB core table is altered.
 */
class m1_initial_schema extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v330\v330'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'fh_alerts');
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return [
			'add_tables' => [
				// Per-topic derived metrics. One row per analysed topic.
				$this->table_prefix . 'fh_topic_metrics' => [
					'COLUMNS' => [
						'topic_id'			=> ['UINT', 0],
						'forum_id'			=> ['UINT', 0],
						'topic_poster'		=> ['UINT', 0],
						'topic_time'		=> ['TIMESTAMP', 0],
						'last_post_time'	=> ['TIMESTAMP', 0],
						'topic_replies'		=> ['UINT', 0],
						'topic_views'		=> ['UINT', 0],
						// Normalised title used by native duplicate detection.
						'title_normalised'	=> ['VCHAR_UNI:255', ''],
						// Sorted significant tokens, used for overlap scoring.
						'title_tokens'		=> ['VCHAR_UNI:255', ''],
						'is_unanswered'		=> ['BOOL', 0],
						'is_first_topic'	=> ['BOOL', 0],
						'first_reply_time'	=> ['TIMESTAMP', 0],
						'solution_post_id'	=> ['UINT', 0],
						'solution_conf'		=> ['USINT', 0],
						'freshness_conf'	=> ['USINT', 0],
						'freshness_reason'	=> ['VCHAR:64', ''],
						'content_hash'		=> ['CHAR:40', ''],
						'analysed_at'		=> ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'topic_id',
					'KEYS' => [
						'fh_tm_forum'		=> ['INDEX', ['forum_id', 'topic_time']],
						'fh_tm_unanswered'	=> ['INDEX', ['is_unanswered', 'topic_views']],
						'fh_tm_analysed'	=> ['INDEX', ['analysed_at']],
						'fh_tm_fresh'		=> ['INDEX', ['freshness_conf']],
						'fh_tm_solution'	=> ['INDEX', ['solution_conf']],
					],
				],

				// Detected relations between topics (duplicate / similar candidates).
				$this->table_prefix . 'fh_topic_relations' => [
					'COLUMNS' => [
						'relation_id'		=> ['UINT', null, 'auto_increment'],
						'topic_id'			=> ['UINT', 0],
						'related_topic_id'	=> ['UINT', 0],
						'relation_type'		=> ['VCHAR:32', 'duplicate'],
						'confidence'		=> ['USINT', 0],
						// native | meilisearch | ai
						'source'			=> ['VCHAR:32', 'native'],
						// JSON array of language-key reason codes. Never free text.
						'reasons'			=> ['STEXT_UNI', ''],
						// new | confirmed | dismissed
						'relation_status'	=> ['VCHAR:16', 'new'],
						'created_at'		=> ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'relation_id',
					'KEYS' => [
						'fh_tr_pair'	=> ['UNIQUE', ['topic_id', 'related_topic_id', 'relation_type']],
						'fh_tr_status'	=> ['INDEX', ['relation_status', 'confidence']],
						'fh_tr_related'	=> ['INDEX', ['related_topic_id']],
					],
				],

				// Distinct external URLs and their last known state.
				$this->table_prefix . 'fh_links' => [
					'COLUMNS' => [
						'link_id'		=> ['UINT', null, 'auto_increment'],
						'url_hash'		=> ['CHAR:40', ''],
						'url'			=> ['TEXT_UNI', ''],
						'url_host'		=> ['VCHAR:255', ''],
						'status_code'	=> ['USINT', 0],
						// pending | ok | redirect | broken | warning | skipped | unsafe
						'link_state'	=> ['VCHAR:16', 'pending'],
						'fail_count'	=> ['USINT', 0],
						'last_checked'	=> ['TIMESTAMP', 0],
						'next_check'	=> ['TIMESTAMP', 0],
						'occurrences'	=> ['UINT', 0],
					],
					'PRIMARY_KEY' => 'link_id',
					'KEYS' => [
						'fh_l_hash'		=> ['UNIQUE', ['url_hash']],
						'fh_l_due'		=> ['INDEX', ['next_check']],
						'fh_l_state'	=> ['INDEX', ['link_state']],
					],
				],

				// Where each URL appears. Kept separate so a URL is checked once
				// no matter how many posts reference it.
				$this->table_prefix . 'fh_link_occurrences' => [
					'COLUMNS' => [
						'link_id'	=> ['UINT', 0],
						'post_id'	=> ['UINT', 0],
						'topic_id'	=> ['UINT', 0],
						'forum_id'	=> ['UINT', 0],
					],
					'PRIMARY_KEY' => ['link_id', 'post_id'],
					'KEYS' => [
						'fh_lo_topic'	=> ['INDEX', ['topic_id']],
						'fh_lo_post'	=> ['INDEX', ['post_id']],
					],
				],

				// Unified alert store.
				$this->table_prefix . 'fh_alerts' => [
					'COLUMNS' => [
						'alert_id'		=> ['UINT', null, 'auto_increment'],
						'alert_type'	=> ['VCHAR:48', ''],
						// 10 informational .. 50 critical, see constants::SEVERITY_*
						'severity'		=> ['USINT', 20],
						// new | acknowledged | resolved | dismissed
						'alert_status'	=> ['VCHAR:16', 'new'],
						'entity_type'	=> ['VCHAR:16', ''],
						'entity_id'		=> ['UINT', 0],
						// Deduplication key, stops the same finding piling up.
						'signature'		=> ['CHAR:40', ''],
						// Language key + JSON parameters. No pre-rendered text.
						'explain_key'	=> ['VCHAR:64', ''],
						'explain_data'	=> ['TEXT_UNI', ''],
						'action_key'	=> ['VCHAR:64', ''],
						'source'		=> ['VCHAR:32', 'native'],
						'created_at'	=> ['TIMESTAMP', 0],
						'updated_at'	=> ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'alert_id',
					'KEYS' => [
						'fh_a_sig'		=> ['UNIQUE', ['signature']],
						'fh_a_list'		=> ['INDEX', ['alert_status', 'severity', 'created_at']],
						'fh_a_type'		=> ['INDEX', ['alert_type', 'alert_status']],
						'fh_a_entity'	=> ['INDEX', ['entity_type', 'entity_id']],
					],
				],

				// Daily aggregated metrics, used for trends and score history.
				$this->table_prefix . 'fh_metrics_history' => [
					'COLUMNS' => [
						'metric_id'		=> ['UINT', null, 'auto_increment'],
						'metric_key'	=> ['VCHAR:48', ''],
						// Day bucket as YYYYMMDD, cheap to range-scan.
						'metric_day'	=> ['UINT:8', 0],
						'metric_value'	=> ['DECIMAL:2', 0],
						'scope_type'	=> ['VCHAR:16', 'global'],
						'scope_id'		=> ['UINT', 0],
					],
					'PRIMARY_KEY' => 'metric_id',
					'KEYS' => [
						'fh_mh_point'	=> ['UNIQUE', ['metric_key', 'metric_day', 'scope_type', 'scope_id']],
						'fh_mh_range'	=> ['INDEX', ['metric_key', 'metric_day']],
					],
				],

				// Administrator-defined rules. Conditions are structured data only,
				// never executable code.
				$this->table_prefix . 'fh_rules' => [
					'COLUMNS' => [
						'rule_id'			=> ['UINT', null, 'auto_increment'],
						'rule_name'			=> ['VCHAR_UNI:255', ''],
						'rule_enabled'		=> ['BOOL', 1],
						'rule_subject'		=> ['VCHAR:32', 'topic'],
						// JSON: [{field, operator, value}, ...] validated on save.
						'rule_conditions'	=> ['TEXT_UNI', ''],
						'action_type'		=> ['VCHAR:32', 'create_alert'],
						'action_severity'	=> ['USINT', 30],
						'created_at'		=> ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'rule_id',
					'KEYS' => [
						'fh_r_enabled' => ['INDEX', ['rule_enabled', 'rule_subject']],
					],
				],

				// Cache of optional AI analysis. Holds results only, never secrets.
				$this->table_prefix . 'fh_ai_cache' => [
					'COLUMNS' => [
						'cache_id'		=> ['UINT', null, 'auto_increment'],
						'cache_key'		=> ['CHAR:40', ''],
						'entity_type'	=> ['VCHAR:16', ''],
						'entity_id'		=> ['UINT', 0],
						'analysis_type'	=> ['VCHAR:48', ''],
						'content_hash'	=> ['CHAR:40', ''],
						'config_version'=> ['UINT', 0],
						'provider_ref'	=> ['VCHAR:64', ''],
						'result_data'	=> ['MTEXT_UNI', ''],
						'created_at'	=> ['TIMESTAMP', 0],
						'expires_at'	=> ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'cache_id',
					'KEYS' => [
						'fh_ac_key'		=> ['UNIQUE', ['cache_key']],
						'fh_ac_expiry'	=> ['INDEX', ['expires_at']],
						'fh_ac_entity'	=> ['INDEX', ['entity_type', 'entity_id']],
					],
				],

				// Background job bookkeeping: cursor, state and observability.
				$this->table_prefix . 'fh_jobs' => [
					'COLUMNS' => [
						'job_name'		=> ['VCHAR:48', ''],
						// idle | running | ok | degraded | error | disabled
						'job_state'		=> ['VCHAR:16', 'idle'],
						'last_run'		=> ['TIMESTAMP', 0],
						'last_duration'	=> ['UINT', 0],
						'last_message'	=> ['VCHAR:255', ''],
						'processed'		=> ['UINT', 0],
						'cursor_value'	=> ['UINT', 0],
						'lock_expires'	=> ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'job_name',
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only tables created by this extension are dropped. No phpBB table and no
	 * table belonging to another extension is ever touched.
	 */
	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'fh_topic_metrics',
				$this->table_prefix . 'fh_topic_relations',
				$this->table_prefix . 'fh_links',
				$this->table_prefix . 'fh_link_occurrences',
				$this->table_prefix . 'fh_alerts',
				$this->table_prefix . 'fh_metrics_history',
				$this->table_prefix . 'fh_rules',
				$this->table_prefix . 'fh_ai_cache',
				$this->table_prefix . 'fh_jobs',
			],
		];
	}
}
