<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\content;

use salvocortesiano\forumhealth\constants;
use salvocortesiano\forumhealth\repository\job_repository;
use salvocortesiano\forumhealth\repository\link_repository;
use salvocortesiano\forumhealth\repository\post_repository;
use salvocortesiano\forumhealth\service\logger;
use salvocortesiano\forumhealth\service\security\url_validator;
use salvocortesiano\forumhealth\service\settings;

/**
 * Discovers URLs in posts and checks whether they still resolve.
 *
 * Two independent passes. Discovery walks posts by id and records every URL it
 * finds. Checking takes the URLs that are due and contacts them. Separating the
 * two means the expensive network work is bounded by a batch size that has
 * nothing to do with how many posts exist.
 *
 * Three rules govern the checking pass, and each exists for a specific reason:
 *
 *   Redirects are followed manually. Handing the redirect chain to cURL would
 *   mean a public URL could redirect to an internal address and be fetched
 *   without ever being validated. Every hop is validated as if it were the
 *   original URL.
 *
 *   A timeout is not a broken link. Servers are slow, networks fail, and rate
 *   limiting is common. A URL must fail repeatedly before it is called broken,
 *   and 429 and 5xx are recorded as warnings, never as failures.
 *
 *   Requests are paced. A forum can easily contain thousands of links to the
 *   same host, and a scanner that fires them off as fast as it can is
 *   indistinguishable from an attack.
 */
class link_scanner
{
	/** @var link_repository */
	protected $links;

	/** @var post_repository */
	protected $posts;

	/** @var job_repository */
	protected $jobs;

	/** @var url_validator */
	protected $validator;

	/** @var settings */
	protected $settings;

	/** @var logger */
	protected $logger;

	/** @var string */
	protected $user_agent;

	/**
	 * @param link_repository $links      Link repository.
	 * @param post_repository $posts      Post repository.
	 * @param job_repository  $jobs       Job bookkeeping.
	 * @param url_validator   $validator  URL safety gate.
	 * @param settings        $settings   Extension settings.
	 * @param logger          $logger     Logger.
	 */
	public function __construct(
		link_repository $links,
		post_repository $posts,
		job_repository $jobs,
		url_validator $validator,
		settings $settings,
		logger $logger
	)
	{
		$this->links = $links;
		$this->posts = $posts;
		$this->jobs = $jobs;
		$this->validator = $validator;
		$this->settings = $settings;
		$this->logger = $logger;
		$this->user_agent = 'Mozilla/5.0 (compatible; ForumHealthLinkCheck/1.0; +phpBB extension)';
	}

	/**
	 * Discover URLs in the next batch of posts.
	 *
	 * @return array{processed:int,found:int,cursor:int,wrapped:bool}
	 */
	public function discover_batch()
	{
		$job = $this->jobs->get(constants::JOB_LINKS);
		$cursor = (int) $job['cursor_value'];
		$batch = $this->settings->get_int('fh_batch_size');

		$rows = $this->posts->fetch_batch($cursor, $batch, $this->settings->excluded_forums());

		if (empty($rows))
		{
			return ['processed' => 0, 'found' => 0, 'cursor' => 0, 'wrapped' => true];
		}

		$found = 0;
		$last_id = $cursor;

		foreach ($rows as $row)
		{
			$last_id = max($last_id, (int) $row['post_id']);

			foreach ($this->extract_urls((string) $row['post_text']) as $url)
			{
				$normalised = $this->validator->normalise($url);

				if ($normalised === '')
				{
					continue;
				}

				$this->links->register(
					$normalised,
					$this->validator->host_of($normalised),
					(int) $row['post_id'],
					(int) $row['topic_id'],
					(int) $row['forum_id']
				);

				$found++;
			}
		}

		return [
			'processed'	=> count($rows),
			'found'		=> $found,
			'cursor'	=> $last_id,
			'wrapped'	=> false,
		];
	}

	/**
	 * Check the URLs that are due.
	 *
	 * @return array{checked:int,broken:int,skipped:int}
	 */
	public function check_batch()
	{
		$batch = $this->settings->get_int('fh_link_batch');
		$rows = $this->links->due_for_check(time(), $batch);

		$checked = 0;
		$broken = 0;
		$skipped = 0;
		$delay = $this->settings->get_int('fh_link_delay_ms') * 1000;

		foreach ($rows as $row)
		{
			$verdict = $this->validator->validate($row['url']);

			if (!$verdict['allowed'])
			{
				// Refused URLs are recorded with a far-off recheck so they are
				// not re-examined on every run.
				$state = in_array($verdict['reason'], ['IGNORED_DOMAIN', 'IGNORED_PATTERN'], true)
					? constants::LINK_SKIPPED
					: constants::LINK_UNSAFE;

				$this->links->record_result(
					(int) $row['link_id'],
					$state,
					0,
					time() + (365 * 86400),
					(int) $row['fail_count']
				);

				$skipped++;
				continue;
			}

			$result = $this->request($row['url']);
			$checked++;

			$outcome = $this->interpret($result, (int) $row['fail_count']);

			$this->links->record_result(
				(int) $row['link_id'],
				$outcome['state'],
				$outcome['status'],
				$outcome['next_check'],
				$outcome['fail_count']
			);

			if ($outcome['state'] === constants::LINK_BROKEN)
			{
				$broken++;
			}

			if ($delay > 0)
			{
				usleep($delay);
			}
		}

		return ['checked' => $checked, 'broken' => $broken, 'skipped' => $skipped];
	}

	/**
	 * Extract candidate URLs from raw post text.
	 *
	 * @param string $text Raw post text.
	 * @return string[] Distinct URLs, capped per post.
	 */
	public function extract_urls($text)
	{
		$urls = [];

		// bbcode url tags, both forms.
		if (preg_match_all('#\[url=([^\]]+)\]#i', $text, $m))
		{
			$urls = array_merge($urls, $m[1]);
		}

		if (preg_match_all('#\[url(?::[a-z0-9]+)?\](.*?)\[/url(?::[a-z0-9]+)?\]#is', $text, $m))
		{
			$urls = array_merge($urls, $m[1]);
		}

		// Bare links, which phpBB leaves in place when magic urls are off.
		if (preg_match_all('#\bhttps?://[^\s\[\]<>"\']{4,}#i', $text, $m))
		{
			$urls = array_merge($urls, $m[0]);
		}

		$clean = [];

		foreach ($urls as $url)
		{
			$url = trim(strip_tags((string) $url));
			$url = rtrim($url, '.,;:)]');

			if ($url !== '' && stripos($url, 'http') === 0)
			{
				$clean[$url] = true;
			}

			// A post with hundreds of links is a link dump; recording twenty is
			// enough to characterise it.
			if (count($clean) >= 20)
			{
				break;
			}
		}

		return array_keys($clean);
	}

	/**
	 * Perform one HTTP request, following redirects manually.
	 *
	 * @param string $url Validated URL.
	 * @return array{status:int,error:string,redirects:int}
	 */
	protected function request($url)
	{
		$max_redirects = $this->settings->get_int('fh_link_max_redirects');
		$redirects = 0;
		$current = $url;

		while (true)
		{
			$response = $this->send($current);

			if ($response['error'] !== '')
			{
				return ['status' => 0, 'error' => $response['error'], 'redirects' => $redirects];
			}

			$status = (int) $response['status'];

			if (!in_array($status, [301, 302, 303, 307, 308], true) || $response['location'] === '')
			{
				return ['status' => $status, 'error' => '', 'redirects' => $redirects];
			}

			if ($redirects >= $max_redirects)
			{
				return ['status' => $status, 'error' => 'too_many_redirects', 'redirects' => $redirects];
			}

			$next = $this->resolve_location($current, $response['location']);
			$next = $this->validator->normalise($next);

			if ($next === '')
			{
				return ['status' => $status, 'error' => 'bad_redirect', 'redirects' => $redirects];
			}

			// The critical step: the destination of a redirect is untrusted
			// input in exactly the way the original URL was.
			$verdict = $this->validator->validate_redirect($next);

			if (!$verdict['allowed'])
			{
				$this->logger->debug('FH_LOG_REDIRECT_BLOCKED', [$verdict['reason']]);

				return ['status' => 0, 'error' => 'blocked_redirect', 'redirects' => $redirects];
			}

			$current = $next;
			$redirects++;
		}
	}

	/**
	 * Send a single request without following redirects.
	 *
	 * A HEAD request is tried first because it costs the remote server almost
	 * nothing; servers that reject HEAD are retried with a ranged GET.
	 *
	 * @param string $url Validated URL.
	 * @return array{status:int,location:string,error:string}
	 */
	protected function send($url)
	{
		if (!function_exists('curl_init'))
		{
			return ['status' => 0, 'location' => '', 'error' => 'no_http_client'];
		}

		$result = $this->curl_request($url, true);

		// 405 and 501 mean "method not supported", which says nothing about the
		// resource itself.
		if (in_array($result['status'], [405, 501], true) || ($result['status'] === 0 && $result['error'] === ''))
		{
			$result = $this->curl_request($url, false);
		}

		return $result;
	}

	/**
	 * Issue one cURL request.
	 *
	 * @param string $url  Validated URL.
	 * @param bool   $head Whether to use HEAD.
	 * @return array{status:int,location:string,error:string}
	 */
	protected function curl_request($url, $head)
	{
		$timeout = $this->settings->get_int('fh_link_timeout');
		$handle = curl_init();

		curl_setopt_array($handle, [
			CURLOPT_URL				=> $url,
			CURLOPT_NOBODY			=> $head,
			CURLOPT_RETURNTRANSFER	=> true,
			CURLOPT_HEADER			=> true,
			// Never delegate redirects: they must be validated individually.
			CURLOPT_FOLLOWLOCATION	=> false,
			CURLOPT_CONNECTTIMEOUT	=> min(10, $timeout),
			CURLOPT_TIMEOUT			=> $timeout,
			CURLOPT_USERAGENT		=> $this->user_agent,
			CURLOPT_SSL_VERIFYPEER	=> true,
			CURLOPT_SSL_VERIFYHOST	=> 2,
			// Only these two schemes, even after a redirect line is parsed.
			CURLOPT_PROTOCOLS		=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS	=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
			// A response body is never needed, so cap what can be pulled down.
			CURLOPT_BUFFERSIZE		=> 16384,
			CURLOPT_HTTPHEADER		=> ['Accept: */*', 'Range: bytes=0-2047'],
		]);

		$raw = curl_exec($handle);
		$status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
		$error = curl_error($handle);
		$errno = curl_errno($handle);
		curl_close($handle);

		$location = '';

		if (is_string($raw) && preg_match('/^Location:\s*(.+)$/mi', $raw, $m))
		{
			$location = trim($m[1]);
		}

		return [
			'status'	=> $status,
			'location'	=> $location,
			'error'		=> $errno !== 0 ? $this->classify_curl_error($errno, $error) : '',
		];
	}

	/**
	 * Turn a cURL error into a stable short code.
	 *
	 * @param int    $errno   cURL error number.
	 * @param string $message cURL error message, not stored.
	 * @return string
	 */
	protected function classify_curl_error($errno, $message)
	{
		switch ($errno)
		{
			case CURLE_OPERATION_TIMEOUTED:
				return 'timeout';

			case CURLE_COULDNT_RESOLVE_HOST:
				return 'dns';

			case CURLE_COULDNT_CONNECT:
				return 'connect';

			case CURLE_SSL_CONNECT_ERROR:
			case CURLE_SSL_CERTPROBLEM:
			case CURLE_SSL_CIPHER:
			case CURLE_SSL_PEER_CERTIFICATE:
				return 'ssl';

			default:
				return 'request_failed';
		}
	}

	/**
	 * Decide what a response means for a link's state.
	 *
	 * @param array $result     Request outcome.
	 * @param int   $fail_count Consecutive failures so far.
	 * @return array{state:string,status:int,next_check:int,fail_count:int}
	 */
	protected function interpret(array $result, $fail_count)
	{
		$now = time();
		$recheck = $this->settings->get_int('fh_link_recheck_days') * 86400;
		$retry = $this->settings->get_int('fh_link_retry_days') * 86400;
		$max_fails = $this->settings->get_int('fh_link_max_fails');
		$status = (int) $result['status'];

		// A definitive absence. These two codes mean the resource is gone, and
		// no amount of retrying changes that.
		if ($status === 404 || $status === 410)
		{
			return [
				'state'			=> constants::LINK_BROKEN,
				'status'		=> $status,
				'next_check'	=> $now + $recheck,
				'fail_count'	=> $fail_count + 1,
			];
		}

		if ($status >= 200 && $status < 300)
		{
			return [
				'state'			=> constants::LINK_OK,
				'status'		=> $status,
				'next_check'	=> $now + $recheck,
				'fail_count'	=> 0,
			];
		}

		// Access controls and rate limits describe the relationship between this
		// server and that one, not the health of the link.
		if (in_array($status, [401, 403, 429], true))
		{
			return [
				'state'			=> constants::LINK_WARNING,
				'status'		=> $status,
				'next_check'	=> $now + $recheck,
				'fail_count'	=> 0,
			];
		}

		if ($status >= 500 && $status < 600)
		{
			return [
				'state'			=> constants::LINK_WARNING,
				'status'		=> $status,
				'next_check'	=> $now + $retry,
				'fail_count'	=> $fail_count + 1,
			];
		}

		// Transport level failures, including timeouts. Only a repeated failure
		// is treated as breakage.
		$fail_count++;

		if ($fail_count >= $max_fails)
		{
			return [
				'state'			=> constants::LINK_BROKEN,
				'status'		=> $status,
				'next_check'	=> $now + $recheck,
				'fail_count'	=> $fail_count,
			];
		}

		return [
			'state'			=> constants::LINK_WARNING,
			'status'		=> $status,
			'next_check'	=> $now + $retry,
			'fail_count'	=> $fail_count,
		];
	}

	/**
	 * Resolve a possibly relative Location header against the current URL.
	 *
	 * @param string $base     Current absolute URL.
	 * @param string $location Location header value.
	 * @return string Absolute URL.
	 */
	protected function resolve_location($base, $location)
	{
		if (preg_match('#^https?://#i', $location))
		{
			return $location;
		}

		$parts = @parse_url($base);

		if ($parts === false || empty($parts['scheme']) || empty($parts['host']))
		{
			return '';
		}

		$root = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');

		if (strpos($location, '/') === 0)
		{
			return $root . $location;
		}

		$path = isset($parts['path']) ? $parts['path'] : '/';
		$directory = substr($path, 0, (int) strrpos($path, '/') + 1);

		return $root . $directory . $location;
	}
}
