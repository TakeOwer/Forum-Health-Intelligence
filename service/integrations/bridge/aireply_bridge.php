<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\integrations\bridge;

use Symfony\Component\DependencyInjection\ContainerInterface;
use salvocortesiano\forumhealth\service\integrations\ai\provider_interface;
use salvocortesiano\forumhealth\service\logger;
use salvocortesiano\forumhealth\service\settings;

/**
 * Bridge to salvocortesiano/aireply 1.0.8.
 *
 * Written against that extension's actual source. The awkward part of the
 * adaptation is worth stating: AI Reply exists to write forum posts. Its
 * providers take a system prompt and a conversation and return prose. Forum
 * Health needs a structured verdict — a confidence figure and a one-line reason.
 *
 * So this bridge asks for JSON in the system prompt and parses what comes back,
 * defensively. A model that ignores the instruction and answers in prose
 * produces a null result, which the adapter already treats as "no analysis
 * available" and falls back to native detection. That is the correct outcome:
 * a guess extracted from prose would be worse than no answer.
 *
 * Credentials, model and endpoint come from an AI Reply bot chosen by the
 * administrator. Forum Health deliberately does not ask for a second copy of an
 * API key: duplicating a secret across two extensions is a worse outcome than
 * borrowing one.
 */
class aireply_bridge implements provider_interface
{
	/** Service id of the provider manager in salvocortesiano/aireply. */
	const MANAGER_SERVICE = 'salvocortesiano.aireply.provider_manager';

	/** Service id of the key resolver in salvocortesiano/aireply. */
	const KEY_SERVICE = 'salvocortesiano.aireply.key_manager';

	/** Service id of the bot repository in salvocortesiano/aireply. */
	const BOTS_SERVICE = 'salvocortesiano.aireply.bot_repository';

	/** Capabilities this bridge can express as a JSON question. */
	protected static $supported = [
		provider_interface::CAP_DUPLICATE,
		provider_interface::CAP_SOLUTION,
		provider_interface::CAP_OUTDATED,
		provider_interface::CAP_SUMMARY,
		provider_interface::CAP_KNOWLEDGE,
		provider_interface::CAP_CONFLICT,
	];

	/** @var ContainerInterface */
	protected $container;

	/** @var settings */
	protected $settings;

	/** @var logger */
	protected $logger;

	/** @var object|null */
	protected $manager;

	/** @var object|null */
	protected $keys;

	/** @var object|null */
	protected $bots;

	/** @var bool */
	protected $booted = false;

	/** @var object|null Cached bot for this request. */
	protected $bot;

	/**
	 * @param ContainerInterface $container Service container.
	 * @param settings           $settings  Extension settings.
	 * @param logger             $logger    Logger.
	 */
	public function __construct(ContainerInterface $container, settings $settings, logger $logger)
	{
		$this->container = $container;
		$this->settings = $settings;
		$this->logger = $logger;
	}

	/**
	 * Resolve AI Reply's services, if that extension is installed.
	 *
	 * Resolved at runtime rather than declared in services.yml for the same
	 * reason as the search bridge: a compile-time reference to a service that
	 * does not exist stops the whole container from building, which would break
	 * the board rather than just this feature.
	 *
	 * @return bool
	 */
	protected function boot()
	{
		if ($this->booted)
		{
			return $this->manager !== null;
		}

		$this->booted = true;

		try
		{
			foreach ([self::MANAGER_SERVICE, self::KEY_SERVICE, self::BOTS_SERVICE] as $id)
			{
				if (!$this->container->has($id))
				{
					return false;
				}
			}

			$this->manager = $this->container->get(self::MANAGER_SERVICE);
			$this->keys = $this->container->get(self::KEY_SERVICE);
			$this->bots = $this->container->get(self::BOTS_SERVICE);
		}
		catch (\Throwable $e)
		{
			$this->manager = null;

			return false;
		}

		if (!method_exists($this->manager, 'get') || !method_exists($this->bots, 'get_by_id'))
		{
			$this->manager = null;

			return false;
		}

		// The value objects are constructed by name in analyse(). If the
		// services resolved but these did not autoload, something is very wrong
		// with the other extension and calling into it would be worse than
		// reporting unavailable.
		if (!class_exists('\\salvocortesiano\\aireply\\provider\\ai_request')
			|| !class_exists('\\salvocortesiano\\aireply\\provider\\ai_message'))
		{
			$this->manager = null;

			return false;
		}

		return true;
	}

	/**
	 * The AI Reply bot whose credentials and model this bridge borrows.
	 *
	 * @return object|null
	 */
	protected function bot()
	{
		if ($this->bot !== null)
		{
			return $this->bot;
		}

		if (!$this->boot())
		{
			return null;
		}

		$bot_id = $this->settings->get_int('fh_ai_bot_id');

		if ($bot_id <= 0)
		{
			return null;
		}

		try
		{
			$bot = $this->bots->get_by_id($bot_id);
		}
		catch (\Throwable $e)
		{
			return null;
		}

		if ($bot === null || empty($bot->provider) || empty($bot->model))
		{
			return null;
		}

		// A bot disabled in AI Reply is left alone. The administrator turned it
		// off there, and quietly using it from another extension would defeat
		// the point of that switch.
		if (empty($bot->enabled))
		{
			return null;
		}

		$this->bot = $bot;

		return $bot;
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_operational()
	{
		$bot = $this->bot();

		if ($bot === null)
		{
			return false;
		}

		try
		{
			if (!$this->manager->has($bot->provider))
			{
				return false;
			}

			// A key that cannot be resolved — an env: reference to a variable
			// that is not set, for instance — would fail on the first real call.
			// Better to report unavailable now than to burn a request finding out.
			return (bool) $this->keys->is_resolvable($bot->api_key);
		}
		catch (\Throwable $e)
		{
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function supports($capability)
	{
		return in_array($capability, self::$supported, true) && $this->is_operational();
	}

	/**
	 * {@inheritdoc}
	 */
	public function describe()
	{
		$bot = $this->bot();

		if ($bot === null)
		{
			return '';
		}

		// Never the key, never the endpoint: this string is rendered in the ACP.
		return 'AI Reply — ' . $bot->provider . ' / ' . $bot->model;
	}

	/**
	 * {@inheritdoc}
	 */
	public function analyse($capability, array $payload)
	{
		if (!$this->supports($capability))
		{
			return null;
		}

		$bot = $this->bot();
		$question = $this->question($capability, $payload);

		if ($question === null)
		{
			return null;
		}

		try
		{
			$provider = $this->manager->get($bot->provider);
			$api_key = $this->keys->resolve($bot->api_key);
		}
		catch (\Throwable $e)
		{
			$this->logger->debug('FH_LOG_AI_FAILURE', [get_class($e)]);

			return null;
		}

		if ($api_key === '')
		{
			return null;
		}

		$request = new \salvocortesiano\aireply\provider\ai_request();
		$request->api_key = $api_key;
		$request->base_url = (string) $bot->base_url;
		$request->model = (string) $bot->model;
		$request->system_prompt = $this->system_prompt();
		$request->max_output_tokens = 400;

		// AI Reply's own worker respects this, and OpenAI's reasoning models
		// reject the parameter outright with a 400. Sending it blindly would
		// turn a working configuration into a hard failure.
		if (method_exists($provider, 'supports_temperature') && $provider->supports_temperature($bot->model))
		{
			// Low, not the bot's conversational default: this is a
			// classification task, and creativity is not wanted.
			$request->temperature = 0.1;
		}

		$request->timeout = min(60, max(10, (int) $bot->request_timeout));
		$request->add_message(\salvocortesiano\aireply\provider\ai_message::user($question));

		try
		{
			$result = $provider->generate($request);
		}
		catch (\Throwable $e)
		{
			$this->logger->debug('FH_LOG_AI_FAILURE', [get_class($e)]);

			return null;
		}

		if (!$result->success)
		{
			$this->logger->debug('FH_LOG_AI_FAILURE', [(string) $result->error_code]);

			return null;
		}

		return $this->parse($capability, (string) $result->text);
	}

	/**
	 * The instruction that turns a prose generator into a classifier.
	 *
	 * @return string
	 */
	protected function system_prompt()
	{
		return "You are an analysis component inside forum software. "
			. "You answer only with a single JSON object and nothing else: no prose, no explanation, no markdown fences. "
			. "The object has exactly these keys: "
			. '"confidence" (integer 0-100), '
			. '"verdict" (string, one of the values listed in the question), '
			. '"summary" (one short sentence, at most 25 words, stating the reason), '
			. '"reference" (integer, 0 when not applicable). '
			. "If the evidence is weak or ambiguous, say so with a low confidence rather than guessing. "
			. "Never invent facts that are not in the material you are given.";
	}

	/**
	 * Build the capability-specific question.
	 *
	 * @param string $capability Capability constant.
	 * @param array  $payload    Caller-supplied material.
	 * @return string|null Null when the payload lacks what the question needs.
	 */
	protected function question($capability, array $payload)
	{
		switch ($capability)
		{
			case provider_interface::CAP_DUPLICATE:
				if (empty($payload['a']) || empty($payload['b']))
				{
					return null;
				}

				return "Do these two forum discussions ask the same question?\n\n"
					. "Verdict must be one of: duplicate, related, unrelated.\n"
					. "reference must be 0.\n\n"
					. "A: " . $this->clip($payload['a']) . "\n\n"
					. "B: " . $this->clip($payload['b']);

			case provider_interface::CAP_SOLUTION:
				if (empty($payload['question']) || empty($payload['replies']) || !is_array($payload['replies']))
				{
					return null;
				}

				$replies = '';

				foreach (array_slice($payload['replies'], 0, 15) as $reply)
				{
					$replies .= "\n[post " . (int) ($reply['post_id'] ?? 0) . "] " . $this->clip($reply['text'] ?? '', 600);
				}

				return "Which reply, if any, resolves the question?\n\n"
					. "Verdict must be one of: solved, unsolved.\n"
					. "reference must be the post id of the solving reply, or 0.\n\n"
					. "Question: " . $this->clip($payload['question']) . "\n\nReplies:" . $replies;

			case provider_interface::CAP_OUTDATED:
				if (empty($payload['text']))
				{
					return null;
				}

				return "Is this forum content likely to be out of date today?\n\n"
					. "Verdict must be one of: outdated, current, unclear.\n"
					. "reference must be 0.\n"
					. "Base the judgement on what the text itself says — superseded software versions, "
					. "deprecated instructions, references to services that no longer exist. "
					. "Age alone is not evidence.\n\n"
					. "Posted: " . (string) ($payload['posted'] ?? 'unknown') . "\n"
					. "Content: " . $this->clip($payload['text'], 2500);

			case provider_interface::CAP_SUMMARY:
				if (empty($payload['text']))
				{
					return null;
				}

				return "Summarise this discussion in one or two sentences.\n\n"
					. "Verdict must be: summary.\n"
					. "Put the summary in the summary field. reference must be 0.\n\n"
					. $this->clip($payload['text'], 4000);

			case provider_interface::CAP_KNOWLEDGE:
				if (empty($payload['text']))
				{
					return null;
				}

				return "Would this discussion make a useful permanent guide for the forum?\n\n"
					. "Verdict must be one of: worthwhile, marginal, no.\n"
					. "reference must be 0.\n\n"
					. $this->clip($payload['text'], 4000);

			case provider_interface::CAP_CONFLICT:
				if (empty($payload['a']) || empty($payload['b']))
				{
					return null;
				}

				return "Do these two discussions give contradictory answers to the same question?\n\n"
					. "Verdict must be one of: contradictory, consistent, unrelated.\n"
					. "reference must be 0.\n\n"
					. "A: " . $this->clip($payload['a']) . "\n\n"
					. "B: " . $this->clip($payload['b']);
		}

		return null;
	}

	/**
	 * Parse the model's answer into the structured result the interface promises.
	 *
	 * Deliberately strict. Anything that is not recognisable JSON with a
	 * plausible confidence becomes null, and the adapter falls back to native
	 * analysis. Salvaging a number out of prose would produce a confident-looking
	 * result with nothing behind it.
	 *
	 * @param string $capability Capability constant.
	 * @param string $text       Raw model output.
	 * @return array|null
	 */
	protected function parse($capability, $text)
	{
		$text = trim($text);

		if ($text === '')
		{
			return null;
		}

		// Models routinely wrap JSON in markdown fences despite being told not
		// to. That is a formatting habit rather than a failure to answer, so it
		// is stripped rather than rejected.
		$text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);

		$start = strpos($text, '{');
		$end = strrpos($text, '}');

		if ($start === false || $end === false || $end <= $start)
		{
			return null;
		}

		$decoded = json_decode(substr($text, $start, $end - $start + 1), true);

		if (!is_array($decoded) || !isset($decoded['confidence']))
		{
			return null;
		}

		$confidence = (int) $decoded['confidence'];

		if ($confidence < 0 || $confidence > 100)
		{
			return null;
		}

		return [
			'confidence'	=> $confidence,
			'verdict'		=> $this->clip((string) ($decoded['verdict'] ?? ''), 40),
			'summary'		=> $this->clip((string) ($decoded['summary'] ?? ''), 300),
			'reference'		=> max(0, (int) ($decoded['reference'] ?? 0)),
		];
	}

	/**
	 * Trim text to a sane length before it leaves the server.
	 *
	 * @param string $text  Input.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	protected function clip($text, $limit = 1200)
	{
		$text = trim((string) $text);

		if (function_exists('mb_substr'))
		{
			return mb_substr($text, 0, $limit);
		}

		return substr($text, 0, $limit);
	}
}
