<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\integrations;

use phpbb\extension\manager as ext_manager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use salvocortesiano\forumhealth\constants;
use salvocortesiano\forumhealth\service\logger;
use salvocortesiano\forumhealth\service\settings;

/**
 * Discovers and reports the state of the optional integrations.
 *
 * Detection happens in two independent steps, and the distinction matters:
 *
 *  1. Is a relevant extension present and enabled? This is answered by phpBB's
 *     own extension manager, not by looking for files on disk, so an extension
 *     that is installed but disabled is reported as such rather than as missing.
 *
 *  2. Is a provider bound? An installed search or AI extension does not
 *     automatically expose an interface this extension can call. A bridge
 *     service implementing our provider contract must exist, either tagged in
 *     the service container or named by the administrator.
 *
 * Keeping the two apart is what produces an honest ACP: "installed but not
 * bound" is a real and common state, and telling the administrator that plainly
 * is better than either guessing at an API or pretending the extension is
 * missing.
 */
class registry
{
	/** Tag a bridge service can carry to be discovered automatically. */
	const TAG_SEARCH = 'salvocortesiano.forumhealth.search_provider';

	/** Tag an AI bridge service can carry to be discovered automatically. */
	const TAG_AI = 'salvocortesiano.forumhealth.ai_provider';

	/** @var ContainerInterface */
	protected $container;

	/** @var ext_manager */
	protected $ext_manager;

	/** @var settings */
	protected $settings;

	/** @var logger */
	protected $logger;

	/**
	 * Search providers collected at compile time from the service tag.
	 *
	 * phpBB's runtime container is a dumped Container, not a ContainerBuilder,
	 * so findTaggedServiceIds() does not exist there. Tagged services have to be
	 * gathered into a service_collection while the container is being built,
	 * which is what config/services.yml does.
	 *
	 * @var \Traversable|null
	 */
	protected $search_collection;

	/** @var \Traversable|null */
	protected $ai_collection;

	/** @var array<string, array>|null Per-request memoisation. */
	protected $cache = null;

	/**
	 * Name fragments that identify a search extension.
	 *
	 * Used only to tell the administrator "this looks like the extension you
	 * mean". Nothing is ever called on an extension matched this way.
	 *
	 * @var string[]
	 */
	protected static $search_hints = ['meilisearch', 'meili'];

	/**
	 * Extensions this build ships a verified bridge for.
	 *
	 * Matching one of these means the administrator needs to write nothing at
	 * all: the bridge is already present and registers itself by tag.
	 *
	 * @var array<string, string>
	 */
	protected static $known_bridges = [
		'search'	=> 'salvocortesiano/meilisearch',
		'ai'		=> 'salvocortesiano/aireply',
	];

	/**
	 * Name fragments that identify an AI extension.
	 *
	 * @var string[]
	 */
	protected static $ai_hints = ['aireply', 'ai_reply', 'aibot', 'ai_bot', 'aibots', 'ai_bots', 'aiassistant'];

	/**
	 * @param ContainerInterface $container         Service container.
	 * @param ext_manager        $ext_manager       phpBB extension manager.
	 * @param \Traversable|null  $search_collection Tagged search providers.
	 * @param \Traversable|null  $ai_collection     Tagged AI providers.
	 * @param settings           $settings    Extension settings.
	 * @param logger             $logger      Logger.
	 */
	public function __construct(
		ContainerInterface $container,
		ext_manager $ext_manager,
		settings $settings,
		logger $logger,
		$search_collection = null,
		$ai_collection = null
	)
	{
		$this->search_collection = $search_collection;
		$this->ai_collection = $ai_collection;

		$this->container = $container;
		$this->ext_manager = $ext_manager;
		$this->settings = $settings;
		$this->logger = $logger;
	}

	/**
	 * Full state of the search integration.
	 *
	 * @return array{state:string,extension:string,enabled:bool,installed:bool,
	 *               bound:bool,description:string,failures:int}
	 */
	public function search_status()
	{
		return $this->status('search');
	}

	/**
	 * Full state of the AI integration.
	 *
	 * @return array{state:string,extension:string,enabled:bool,installed:bool,
	 *               bound:bool,description:string,failures:int}
	 */
	public function ai_status()
	{
		return $this->status('ai');
	}

	/**
	 * The bound search provider, when one is available and switched on.
	 *
	 * @return \salvocortesiano\forumhealth\service\integrations\meilisearch\provider_interface|null
	 */
	public function search_provider()
	{
		if (!$this->settings->get_bool('fh_meilisearch_enabled'))
		{
			return null;
		}

		$provider = $this->resolve_provider(
			'fh_meilisearch_service',
			self::TAG_SEARCH,
			'\salvocortesiano\forumhealth\service\integrations\meilisearch\provider_interface'
		);

		return $provider;
	}

	/**
	 * The bound AI provider, when one is available and switched on.
	 *
	 * @return \salvocortesiano\forumhealth\service\integrations\ai\provider_interface|null
	 */
	public function ai_provider()
	{
		if (!$this->settings->get_bool('fh_ai_enabled'))
		{
			return null;
		}

		return $this->resolve_provider(
			'fh_ai_service',
			self::TAG_AI,
			'\salvocortesiano\forumhealth\service\integrations\ai\provider_interface'
		);
	}

	/**
	 * Extensions that look like they could supply a given integration.
	 *
	 * Offered to the administrator as a hint on the integration page; never used
	 * to call anything automatically.
	 *
	 * @param string $kind search or ai.
	 * @return string[] Extension names.
	 */
	public function candidate_extensions($kind)
	{
		$hints = ($kind === 'ai') ? self::$ai_hints : self::$search_hints;
		$found = [];

		foreach ($this->all_extension_names() as $name)
		{
			$haystack = strtolower(str_replace(['-', '.'], '_', $name));

			foreach ($hints as $hint)
			{
				if (strpos($haystack, $hint) !== false)
				{
					$found[] = $name;
					break;
				}
			}
		}

		return array_values(array_unique($found));
	}

	/**
	 * Record a runtime failure of an integration.
	 *
	 * The counter drives the "degraded" state, so a search server that goes down
	 * shows up in the ACP as degraded rather than as silently absent.
	 *
	 * @param string $kind    search or ai.
	 * @param string $message Short reason, stored only in the log.
	 * @return void
	 */
	public function record_failure($kind, $message = '')
	{
		$key = ($kind === 'ai') ? 'fh_ai_failures' : 'fh_meilisearch_failures';
		$this->settings->set($key, $this->settings->get_int($key) + 1, false);
		$this->cache = null;

		$this->logger->error(
			($kind === 'ai') ? 'FH_LOG_AI_FAILURE' : 'FH_LOG_SEARCH_FAILURE',
			[(string) $message]
		);
	}

	/**
	 * Clear the failure counter after a successful call.
	 *
	 * @param string $kind search or ai.
	 * @return void
	 */
	public function record_success($kind)
	{
		$key = ($kind === 'ai') ? 'fh_ai_failures' : 'fh_meilisearch_failures';

		if ($this->settings->get_int($key) > 0)
		{
			$this->settings->set($key, 0, false);
			$this->cache = null;
		}
	}

	/**
	 * Refresh and persist the detected state of both integrations.
	 *
	 * Called from the integration page and from cron, so the ACP can show a
	 * "last checked" time rather than probing the container on every page view.
	 *
	 * @return void
	 */
	public function refresh()
	{
		$this->cache = null;

		$search = $this->status('search');
		$ai = $this->status('ai');

		$this->settings->set('fh_meilisearch_state', $search['state'], false);
		$this->settings->set('fh_meilisearch_checked', time(), false);
		$this->settings->set('fh_ai_state', $ai['state'], false);
		$this->settings->set('fh_ai_checked', time(), false);
	}

	/**
	 * Compute the state of one integration.
	 *
	 * @param string $kind search or ai.
	 * @return array
	 */
	protected function status($kind)
	{
		if (isset($this->cache[$kind]))
		{
			return $this->cache[$kind];
		}

		$is_ai = ($kind === 'ai');
		$enabled = $this->settings->get_bool($is_ai ? 'fh_ai_enabled' : 'fh_meilisearch_enabled');
		$failures = $this->settings->get_int($is_ai ? 'fh_ai_failures' : 'fh_meilisearch_failures');

		$candidates = $this->candidate_extensions($kind);
		$installed = !empty($candidates);
		$ext_enabled = false;
		$extension = $installed ? $candidates[0] : '';

		foreach ($candidates as $name)
		{
			if ($this->is_extension_enabled($name))
			{
				$ext_enabled = true;
				$extension = $name;
				break;
			}
		}

		$provider = $this->resolve_provider(
			$is_ai ? 'fh_ai_service' : 'fh_meilisearch_service',
			$is_ai ? self::TAG_AI : self::TAG_SEARCH,
			$is_ai
				? '\salvocortesiano\forumhealth\service\integrations\ai\provider_interface'
				: '\salvocortesiano\forumhealth\service\integrations\meilisearch\provider_interface'
		);

		$bound = ($provider !== null);
		$description = '';
		$operational = false;

		if ($bound)
		{
			try
			{
				$operational = (bool) $provider->is_operational();
				$description = (string) $provider->describe();
			}
			catch (\Throwable $e)
			{
				// A bridge that throws on a health check is not usable, but it
				// must not take the ACP page down with it.
				$operational = false;
				$description = '';
			}
		}

		$state = $this->derive_state($installed, $ext_enabled, $bound, $operational, $failures);

		$status = [
			'state'			=> $state,
			'extension'		=> $extension,
			'candidates'	=> $candidates,
			'installed'		=> $installed,
			'enabled'		=> $ext_enabled,
			'switched_on'	=> $enabled,
			'bound'			=> $bound,
			'description'	=> utf8_substr($description, 0, 120),
			'failures'		=> $failures,
		];

		$this->cache[$kind] = $status;

		return $status;
	}

	/**
	 * Map the detection facts onto one of the reported states.
	 *
	 * @param bool $installed   An extension of this kind exists.
	 * @param bool $ext_enabled That extension is enabled in phpBB.
	 * @param bool $bound       A provider implementing our contract was found.
	 * @param bool $operational That provider reports itself usable.
	 * @param int  $failures    Consecutive runtime failures.
	 * @return string One of the constants::INT_* values.
	 */
	protected function derive_state($installed, $ext_enabled, $bound, $operational, $failures)
	{
		// A bound provider is what actually matters. It can exist without any
		// recognisable extension being installed, for instance when a site
		// integrates its own search service.
		if ($bound && $operational)
		{
			return ($failures >= 3) ? constants::INT_DEGRADED : constants::INT_OPERATIONAL;
		}

		if ($bound && !$operational)
		{
			return constants::INT_DEGRADED;
		}

		if (!$installed)
		{
			return constants::INT_NOT_INSTALLED;
		}

		if (!$ext_enabled)
		{
			return constants::INT_DISABLED;
		}

		return constants::INT_ENABLED_NO_BIND;
	}

	/**
	 * Find a provider by explicit service id first, then by tag.
	 *
	 * The explicit id wins so an administrator can always override discovery.
	 *
	 * @param string $config_key Configuration key holding the service id.
	 * @param string $tag        Service tag to search for.
	 * @param string $interface  Interface the service must implement.
	 * @return object|null
	 */
	protected function resolve_provider($config_key, $tag, $interface)
	{
		$service_id = trim($this->settings->get_string($config_key));

		if ($service_id !== '')
		{
			$provider = $this->fetch_service($service_id);

			if ($provider !== null && $provider instanceof $interface)
			{
				return $provider;
			}

			// A configured id that does not satisfy the contract is a
			// misconfiguration worth surfacing, not something to work around.
			if ($provider !== null)
			{
				$this->logger->debug('FH_LOG_PROVIDER_CONTRACT', [$service_id]);
			}

			return null;
		}

		// No explicit id: fall back to whatever registered itself by tag. The
		// two bridges shipped with this extension are registered this way, so
		// an administrator who installs Meilisearch or AI Reply gets a working
		// integration without typing a service id at all.
		foreach ($this->tagged_providers($tag) as $provider)
		{
			if ($provider instanceof $interface && $provider->is_operational())
			{
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Service ids carrying a given tag.
	 *
	 * Tag metadata is only readable from an uncompiled container, so this is a
	 * best-effort path; the explicit service id setting is the reliable one and
	 * is what the documentation recommends.
	 *
	 * @param string $tag Tag name.
	 * @return string[]
	 */
	protected function tagged_providers($tag)
	{
		$collection = ($tag === self::TAG_AI) ? $this->ai_collection : $this->search_collection;

		if ($collection === null)
		{
			return [];
		}

		$providers = [];

		try
		{
			foreach ($collection as $provider)
			{
				$providers[] = $provider;
			}
		}
		catch (\Throwable $e)
		{
			// A provider whose own construction throws must not take the
			// registry down with it; it simply does not appear.
			$this->logger->debug('FH_LOG_PROVIDER_CONTRACT', [get_class($e)]);
		}

		return $providers;
	}

	/**
	 * Fetch a service without letting a broken definition escape.
	 *
	 * @param string $service_id Service id.
	 * @return object|null
	 */
	protected function fetch_service($service_id)
	{
		try
		{
			if (!$this->container->has($service_id))
			{
				return null;
			}

			return $this->container->get($service_id);
		}
		catch (\Throwable $e)
		{
			// A third-party service that fails to construct must not be able to
			// break this extension's pages.
			return null;
		}
	}

	/**
	 * Names of every extension known to phpBB.
	 *
	 * @return string[]
	 */
	protected function all_extension_names()
	{
		try
		{
			return array_keys($this->ext_manager->all_available());
		}
		catch (\Throwable $e)
		{
			return [];
		}
	}

	/**
	 * Whether phpBB reports an extension as enabled.
	 *
	 * @param string $name Extension name.
	 * @return bool
	 */
	protected function is_extension_enabled($name)
	{
		try
		{
			return $this->ext_manager->is_enabled($name);
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}
}
