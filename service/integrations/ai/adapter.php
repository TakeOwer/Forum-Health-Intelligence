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

use salvocortesiano\forumhealth\service\integrations\registry;
use salvocortesiano\forumhealth\service\settings;

/**
 * The only place in the extension that requests an AI analysis.
 *
 * Four gates stand between a caller and an outbound analysis, and every one of
 * them is a hard stop:
 *
 *   1. the AI master switch, and the per-feature switch for this capability;
 *   2. the cache, which answers most repeat questions for free;
 *   3. the daily budget, so a misconfigured cron cannot spend without limit;
 *   4. the privacy setting, which decides whether post bodies may leave the
 *      forum at all, or only titles.
 *
 * When AI is switched off, this class makes no call of any kind. That is the
 * behaviour the specification requires and it is enforced here rather than being
 * left to each caller to remember.
 */
class adapter
{
	/** @var registry */
	protected $registry;

	/** @var cache */
	protected $cache;

	/** @var settings */
	protected $settings;

	/** @var bool */
	protected $failed_this_request = false;

	/**
	 * Per-feature switches, keyed by capability.
	 *
	 * @var array<string, string>
	 */
	protected static $feature_switch = [
		provider_interface::CAP_DUPLICATE	=> 'fh_ai_feature_duplicates',
		provider_interface::CAP_SOLUTION	=> 'fh_ai_feature_solutions',
		provider_interface::CAP_OUTDATED	=> 'fh_ai_feature_freshness',
		provider_interface::CAP_KNOWLEDGE	=> 'fh_ai_feature_knowledge',
		provider_interface::CAP_CONFLICT	=> 'fh_ai_feature_conflicts',
		provider_interface::CAP_SUMMARY	=> 'fh_ai_feature_knowledge',
	];

	/**
	 * @param registry $registry Integration registry.
	 * @param cache    $cache    Result cache.
	 * @param settings $settings Extension settings.
	 */
	public function __construct(registry $registry, cache $cache, settings $settings)
	{
		$this->registry = $registry;
		$this->cache = $cache;
		$this->settings = $settings;
	}

	/**
	 * Whether AI analysis is usable at all right now.
	 *
	 * @return bool
	 */
	public function is_available()
	{
		if ($this->failed_this_request || !$this->settings->get_bool('fh_ai_enabled'))
		{
			return false;
		}

		return $this->registry->ai_provider() !== null;
	}

	/**
	 * Whether a specific capability may be used.
	 *
	 * @param string $capability One of the provider capability constants.
	 * @return bool
	 */
	public function can($capability)
	{
		if (!$this->is_available())
		{
			return false;
		}

		if (isset(self::$feature_switch[$capability]) && !$this->settings->get_bool(self::$feature_switch[$capability]))
		{
			return false;
		}

		if ($this->budget_exhausted())
		{
			return false;
		}

		$provider = $this->registry->ai_provider();

		try
		{
			return $provider !== null && $provider->supports($capability);
		}
		catch (\Throwable $e)
		{
			$this->degrade(get_class($e));

			return false;
		}
	}

	/**
	 * Whether post bodies may be sent to the provider.
	 *
	 * When this is off, callers should send titles only. It is off by default:
	 * sending the text of a forum to a third party is a decision an administrator
	 * makes deliberately, not one this extension makes for them.
	 *
	 * @return bool
	 */
	public function may_send_content()
	{
		return $this->settings->get_bool('fh_privacy_send_content_to_ai');
	}

	/**
	 * Run one analysis, using the cache when possible.
	 *
	 * @param string $capability    Capability constant.
	 * @param string $entity_type   Entity type, for the cache key.
	 * @param int    $entity_id     Entity id, for the cache key.
	 * @param string $content_hash  Hash of the content being analysed.
	 * @param array  $payload       Capability specific input.
	 * @return array|null Structured result, or null when nothing was produced.
	 */
	public function analyse($capability, $entity_type, $entity_id, $content_hash, array $payload)
	{
		if (!$this->can($capability))
		{
			return null;
		}

		$provider = $this->registry->ai_provider();

		if ($provider === null)
		{
			return null;
		}

		try
		{
			$reference = (string) $provider->describe();
		}
		catch (\Throwable $e)
		{
			$this->degrade(get_class($e));

			return null;
		}

		$key = $this->cache->key($entity_type, $entity_id, $capability, $content_hash, $reference);
		$cached = $this->cache->get($key);

		if ($cached !== null)
		{
			return $cached;
		}

		try
		{
			$result = $provider->analyse($capability, $payload);
		}
		catch (\Throwable $e)
		{
			$this->degrade(get_class($e));

			return null;
		}

		$this->consume_budget();

		if (!is_array($result))
		{
			return null;
		}

		$clean = $this->sanitise($result);
		$this->cache->put($key, $entity_type, $entity_id, $capability, $content_hash, $reference, $clean);
		$this->registry->record_success('ai');

		return $clean;
	}

	/**
	 * Remaining analyses in today's budget.
	 *
	 * @return int Negative one when no limit is configured.
	 */
	public function budget_remaining()
	{
		$limit = $this->settings->get_int('fh_ai_daily_limit');

		if ($limit <= 0)
		{
			return -1;
		}

		return max(0, $limit - $this->used_today());
	}

	/**
	 * Analyses performed today.
	 *
	 * @return int
	 */
	public function used_today()
	{
		if ((int) $this->settings->get_string('fh_ai_used_day') !== $this->today())
		{
			return 0;
		}

		return (int) $this->settings->get_string('fh_ai_used_today');
	}

	/**
	 * Whether the daily budget has run out.
	 *
	 * @return bool
	 */
	protected function budget_exhausted()
	{
		$remaining = $this->budget_remaining();

		return $remaining === 0;
	}

	/**
	 * Count one analysis against today's budget.
	 *
	 * @return void
	 */
	protected function consume_budget()
	{
		$today = $this->today();

		if ((int) $this->settings->get_string('fh_ai_used_day') !== $today)
		{
			$this->settings->set('fh_ai_used_day', $today, false);
			$this->settings->set('fh_ai_used_today', 0, false);
		}

		$this->settings->set('fh_ai_used_today', $this->used_today() + 1, false);
	}

	/**
	 * Today's date as YYYYMMDD in the server's timezone.
	 *
	 * @return int
	 */
	protected function today()
	{
		return (int) gmdate('Ymd');
	}

	/**
	 * Reduce a provider result to the documented shape.
	 *
	 * Length limits matter here: the summary is rendered in the ACP, and a
	 * provider that returns an essay, or that leaks its internal reasoning,
	 * should not be able to fill the interface with it.
	 *
	 * @param array $result Raw provider result.
	 * @return array Clean result.
	 */
	protected function sanitise(array $result)
	{
		return [
			'confidence'	=> max(0, min(100, (int) ($result['confidence'] ?? 0))),
			'verdict'		=> utf8_substr((string) ($result['verdict'] ?? ''), 0, 32),
			'summary'		=> utf8_substr(trim((string) ($result['summary'] ?? '')), 0, 400),
			'reference'		=> max(0, (int) ($result['reference'] ?? 0)),
		];
	}

	/**
	 * Stop calling the provider for the rest of this request.
	 *
	 * @param string $reason Short technical reason for the log.
	 * @return void
	 */
	protected function degrade($reason)
	{
		$this->failed_this_request = true;
		$this->registry->record_failure('ai', $reason);
	}
}
