<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\text;

use salvocortesiano\forumhealth\service\settings;

/**
 * Language-neutral text normalisation and similarity scoring.
 *
 * This is the whole of the native (level 1) duplicate engine's understanding of
 * text. It is intentionally simple and explainable: tokens are compared, not
 * meanings. Anything that requires meaning is the job of the optional AI layer,
 * and is clearly labelled as such in the interface.
 *
 * The stop word lists cover English and Italian, the two shipped languages. The
 * scoring degrades gracefully for other languages because stop words only remove
 * noise; they are not required for a match.
 */
class normaliser
{
	/** @var settings */
	protected $settings;

	/** @var string[]|null Lazily built stop word lookup. */
	protected $stopwords = null;

	/**
	 * Words carrying no topical signal in English.
	 *
	 * @var string[]
	 */
	protected static $stop_en = [
		'the', 'and', 'for', 'are', 'but', 'not', 'you', 'your', 'with', 'this',
		'that', 'from', 'have', 'has', 'was', 'were', 'can', 'cant', 'how', 'why',
		'what', 'when', 'where', 'who', 'which', 'will', 'would', 'should', 'could',
		'about', 'into', 'over', 'after', 'before', 'any', 'all', 'some', 'more',
		'need', 'help', 'please', 'thanks', 'thank', 'hello', 'question', 'problem',
		'issue', 'does', 'doesnt', 'did', 'get', 'got', 'use', 'using', 'new', 'old',
	];

	/**
	 * Words carrying no topical signal in Italian.
	 *
	 * @var string[]
	 */
	protected static $stop_it = [
		'che', 'con', 'per', 'non', 'una', 'uno', 'del', 'della', 'delle', 'dei',
		'degli', 'nel', 'nella', 'sul', 'sulla', 'come', 'quando', 'dove', 'perche',
		'quale', 'quali', 'questo', 'questa', 'quello', 'quella', 'sono', 'essere',
		'avere', 'fare', 'aiuto', 'grazie', 'ciao', 'salve', 'problema', 'domanda',
		'errore', 'posso', 'devo', 'vorrei', 'anche', 'solo', 'ancora', 'dopo',
		'prima', 'tutto', 'tutti', 'molto', 'nuovo', 'vecchio', 'ho', 'mio', 'mia',
	];

	/**
	 * @param settings $settings Extension settings.
	 */
	public function __construct(settings $settings)
	{
		$this->settings = $settings;
	}

	/**
	 * Reduce a title to a comparable form.
	 *
	 * Lowercases, strips accents, removes punctuation and collapses whitespace.
	 *
	 * @param string $title Raw topic title.
	 * @return string Normalised title, truncated to the stored column width.
	 */
	public function normalise($title)
	{
		$text = utf8_strtolower(trim((string) $title));
		$text = $this->strip_accents($text);

		// Keep letters, digits and spaces. Everything else becomes a separator.
		$text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
		$text = trim(preg_replace('/\s+/u', ' ', (string) $text));

		return utf8_substr((string) $text, 0, 255);
	}

	/**
	 * Extract significant tokens from a title.
	 *
	 * @param string $title Raw or normalised title.
	 * @return string[] Unique tokens, sorted for stable storage and comparison.
	 */
	public function tokenise($title)
	{
		$normalised = $this->normalise($title);

		if ($normalised === '')
		{
			return [];
		}

		$min = $this->settings->get_int('fh_min_token_length');
		$stop = $this->stopwords();
		$tokens = [];

		foreach (explode(' ', $normalised) as $token)
		{
			// Short tokens are dropped unless they are numeric: version numbers
			// such as "8" or "22" are among the strongest duplicate signals.
			if (utf8_strlen($token) < $min && !is_numeric($token))
			{
				continue;
			}

			if (isset($stop[$token]))
			{
				continue;
			}

			$tokens[$token] = true;
		}

		$tokens = array_keys($tokens);
		sort($tokens);

		return $tokens;
	}

	/**
	 * Store-ready token string.
	 *
	 * @param string[] $tokens Token list.
	 * @return string Space separated tokens, truncated to the column width.
	 */
	public function pack_tokens(array $tokens)
	{
		return utf8_substr(implode(' ', $tokens), 0, 255);
	}

	/**
	 * Read back a stored token string.
	 *
	 * @param string $packed Stored value.
	 * @return string[]
	 */
	public function unpack_tokens($packed)
	{
		$packed = trim((string) $packed);

		return $packed === '' ? [] : explode(' ', $packed);
	}

	/**
	 * Similarity between two token sets, as a percentage.
	 *
	 * Combines two complementary measures:
	 *  - Jaccard overlap, which is strict and rewards sets that are alike overall;
	 *  - containment, which catches a short title fully contained in a longer one
	 *    ("smtp error" inside "smtp error after server migration").
	 *
	 * @param string[] $a First token set.
	 * @param string[] $b Second token set.
	 * @return int Score from 0 to 100.
	 */
	public function similarity(array $a, array $b)
	{
		if (empty($a) || empty($b))
		{
			return 0;
		}

		$intersection = count(array_intersect($a, $b));

		if ($intersection === 0)
		{
			return 0;
		}

		$union = count(array_unique(array_merge($a, $b)));
		$jaccard = $union > 0 ? ($intersection / $union) : 0;
		$containment = $intersection / min(count($a), count($b));

		// Containment is weighted slightly higher because forum titles vary in
		// length far more than they vary in vocabulary.
		$score = (0.45 * $jaccard) + (0.55 * $containment);

		return (int) round($score * 100);
	}

	/**
	 * Tokens shared by two sets, for the explanation panel.
	 *
	 * @param string[] $a First token set.
	 * @param string[] $b Second token set.
	 * @param int      $limit Maximum tokens to return.
	 * @return string[]
	 */
	public function shared_tokens(array $a, array $b, $limit = 6)
	{
		$shared = array_values(array_intersect($a, $b));

		return array_slice($shared, 0, max(1, (int) $limit));
	}

	/**
	 * Stable content hash used for cache invalidation.
	 *
	 * @param string $content Arbitrary content.
	 * @return string 40 character hex digest.
	 */
	public function content_hash($content)
	{
		return sha1((string) $content);
	}

	/**
	 * Detect version-like fragments, used as a freshness signal.
	 *
	 * Recognises patterns such as "php 7.4", "v2.1.3" or "2016".
	 *
	 * @param string $text Text to inspect.
	 * @return string[] Distinct fragments, at most ten.
	 */
	public function version_fragments($text)
	{
		$found = [];

		if (preg_match_all('/\b[a-z]{2,20}\s?v?\d{1,2}\.\d{1,2}(\.\d{1,3})?\b/iu', (string) $text, $m))
		{
			$found = array_merge($found, $m[0]);
		}

		if (preg_match_all('/\bv\d{1,3}\.\d{1,3}(\.\d{1,3})?\b/iu', (string) $text, $m))
		{
			$found = array_merge($found, $m[0]);
		}

		$found = array_map(function ($item) {
			return utf8_strtolower(trim($item));
		}, $found);

		return array_slice(array_values(array_unique($found)), 0, 10);
	}

	/**
	 * Build the stop word lookup once per request.
	 *
	 * @return array<string, bool>
	 */
	protected function stopwords()
	{
		if ($this->stopwords === null)
		{
			$this->stopwords = array_fill_keys(array_merge(self::$stop_en, self::$stop_it), true);
		}

		return $this->stopwords;
	}

	/**
	 * Fold accented Latin characters to their base letter.
	 *
	 * Done with an explicit map rather than iconv, whose behaviour depends on the
	 * host locale and which can silently drop characters.
	 *
	 * @param string $text Lowercased text.
	 * @return string
	 */
	protected function strip_accents($text)
	{
		static $map = [
			'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
			'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
			'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
			'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
			'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
			'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'ÿ' => 'y',
		];

		return strtr($text, $map);
	}
}
