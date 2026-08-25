<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\service\security;

use salvocortesiano\forumhealth\service\settings;

/**
 * Decides whether a URL found in a post may be contacted.
 *
 * The link scanner is the only part of this extension that makes outbound
 * requests, and the URLs it handles come from posts, which are untrusted input.
 * Without this gate the scanner would be a server-side request forgery tool: any
 * member could post a link to an internal address and have the forum fetch it.
 *
 * The rule enforced here is that a URL is safe only if every address its host
 * resolves to is a public one. That check is repeated after every redirect,
 * because a public host is free to redirect to a private address, and the
 * hostname that was validated a moment ago says nothing about where the
 * redirect leads.
 */
class url_validator
{
	/** @var settings */
	protected $settings;

	/**
	 * Host names that always denote the local machine.
	 *
	 * @var string[]
	 */
	protected static $local_hosts = ['localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback'];

	/**
	 * Cloud instance metadata endpoints.
	 *
	 * These resolve to link-local addresses and are therefore already covered by
	 * the range checks; they are listed separately so that the reason reported to
	 * the administrator is specific rather than generic.
	 *
	 * @var string[]
	 */
	protected static $metadata_hosts = [
		'metadata.google.internal',
		'metadata.goog',
		'instance-data',
	];

	/**
	 * CIDR blocks that must never be contacted.
	 *
	 * @var string[]
	 */
	protected static $blocked_v4 = [
		'0.0.0.0/8',			// this host on this network
		'10.0.0.0/8',			// private
		'100.64.0.0/10',		// carrier grade NAT
		'127.0.0.0/8',			// loopback
		'169.254.0.0/16',		// link local, includes cloud metadata
		'172.16.0.0/12',		// private
		'192.0.0.0/24',		// IETF protocol assignments
		'192.0.2.0/24',		// documentation
		'192.168.0.0/16',		// private
		'198.18.0.0/15',		// benchmarking
		'198.51.100.0/24',	// documentation
		'203.0.113.0/24',		// documentation
		'224.0.0.0/4',			// multicast
		'240.0.0.0/4',			// reserved
	];

	/**
	 * IPv6 blocks that must never be contacted.
	 *
	 * @var string[]
	 */
	protected static $blocked_v6 = [
		'::/128',			// unspecified
		'::1/128',			// loopback
		'::ffff:0:0/96',	// IPv4 mapped, handled again after unwrapping
		'fc00::/7',		// unique local
		'fe80::/10',		// link local
		'ff00::/8',		// multicast
		'2001:db8::/32',	// documentation
	];

	/**
	 * @param settings $settings Extension settings.
	 */
	public function __construct(settings $settings)
	{
		$this->settings = $settings;
	}

	/**
	 * Normalise a URL extracted from a post.
	 *
	 * Strips fragments, lowercases the scheme and host, and removes the default
	 * port, so that the same destination written three ways is checked once.
	 *
	 * @param string $url Raw URL.
	 * @return string Normalised URL, or an empty string when unusable.
	 */
	public function normalise($url)
	{
		$url = trim(html_entity_decode((string) $url, ENT_QUOTES, 'UTF-8'));

		if ($url === '' || utf8_strlen($url) > 2000)
		{
			return '';
		}

		$parts = @parse_url($url);

		if ($parts === false || empty($parts['scheme']) || empty($parts['host']))
		{
			return '';
		}

		$scheme = strtolower($parts['scheme']);

		if ($scheme !== 'http' && $scheme !== 'https')
		{
			return '';
		}

		$host = strtolower($parts['host']);
		$normalised = $scheme . '://' . $host;

		if (!empty($parts['port']))
		{
			$port = (int) $parts['port'];
			$default = ($scheme === 'https') ? 443 : 80;

			if ($port !== $default)
			{
				$normalised .= ':' . $port;
			}
		}

		$normalised .= isset($parts['path']) ? $parts['path'] : '/';

		if (isset($parts['query']) && $parts['query'] !== '')
		{
			$normalised .= '?' . $parts['query'];
		}

		// The fragment is deliberately dropped: it never reaches the server.
		return $normalised;
	}

	/**
	 * Host component of a normalised URL.
	 *
	 * @param string $url Normalised URL.
	 * @return string Lowercase host, empty when unparseable.
	 */
	public function host_of($url)
	{
		$parts = @parse_url((string) $url);

		return isset($parts['host']) ? strtolower($parts['host']) : '';
	}

	/**
	 * Decide whether a URL may be fetched.
	 *
	 * @param string $url Normalised URL.
	 * @return array{allowed:bool,reason:string} Reason is a language key suffix.
	 */
	public function validate($url)
	{
		$host = $this->host_of($url);

		if ($host === '')
		{
			return $this->deny('MALFORMED');
		}

		if (in_array($host, self::$local_hosts, true))
		{
			return $this->deny('LOCALHOST');
		}

		if (in_array($host, self::$metadata_hosts, true))
		{
			return $this->deny('METADATA');
		}

		// A host with no dot is an internal short name on most networks.
		if (strpos($host, '.') === false && !$this->is_ip($host))
		{
			return $this->deny('INTERNAL_HOST');
		}

		// Reserved suffixes reserved by RFC 6761 and RFC 8375 for local use.
		foreach (['.local', '.internal', '.localdomain', '.home.arpa', '.lan', '.intranet', '.corp'] as $suffix)
		{
			if (substr($host, -strlen($suffix)) === $suffix)
			{
				return $this->deny('INTERNAL_HOST');
			}
		}

		if ($this->is_ignored_domain($host))
		{
			return $this->deny('IGNORED_DOMAIN');
		}

		if ($this->matches_ignored_pattern($url))
		{
			return $this->deny('IGNORED_PATTERN');
		}

		// The escape hatch exists for forums that legitimately link to an
		// intranet. It is off by default and clearly labelled in the ACP.
		if ($this->settings->get_bool('fh_link_allow_private_hosts'))
		{
			return ['allowed' => true, 'reason' => ''];
		}

		return $this->validate_resolution($host);
	}

	/**
	 * Re-validate a redirect target.
	 *
	 * Separated from validate() only for readability at the call site: the check
	 * itself is deliberately identical, because a redirect target deserves
	 * exactly as much suspicion as the original URL.
	 *
	 * @param string $url Redirect location, already normalised.
	 * @return array{allowed:bool,reason:string}
	 */
	public function validate_redirect($url)
	{
		return $this->validate($url);
	}

	/**
	 * Check every address a host resolves to.
	 *
	 * All records are examined, not just the first: a host that resolves to one
	 * public and one private address must be refused.
	 *
	 * @param string $host Lowercase host or literal IP.
	 * @return array{allowed:bool,reason:string}
	 */
	protected function validate_resolution($host)
	{
		$addresses = $this->resolve($host);

		if (empty($addresses))
		{
			return $this->deny('DNS_FAILED');
		}

		foreach ($addresses as $address)
		{
			if (!$this->is_public_ip($address))
			{
				return $this->deny('PRIVATE_ADDRESS');
			}
		}

		return ['allowed' => true, 'reason' => ''];
	}

	/**
	 * Resolve a host to its IPv4 and IPv6 addresses.
	 *
	 * @param string $host Host name or literal IP.
	 * @return string[] Addresses, empty on failure.
	 */
	protected function resolve($host)
	{
		if ($this->is_ip($host))
		{
			return [$host];
		}

		$addresses = [];

		$v4 = @gethostbynamel($host);

		if (is_array($v4))
		{
			$addresses = array_merge($addresses, $v4);
		}

		if (function_exists('dns_get_record'))
		{
			$records = @dns_get_record($host, DNS_AAAA);

			if (is_array($records))
			{
				foreach ($records as $record)
				{
					if (!empty($record['ipv6']))
					{
						$addresses[] = $record['ipv6'];
					}
				}
			}
		}

		return array_values(array_unique($addresses));
	}

	/**
	 * Whether an address is routable on the public internet.
	 *
	 * @param string $address IPv4 or IPv6 address.
	 * @return bool
	 */
	public function is_public_ip($address)
	{
		$address = trim((string) $address);

		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
		{
			return !$this->in_any_range($address, self::$blocked_v4, false);
		}

		if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
		{
			// An IPv4 address wrapped in IPv6 notation must be judged as IPv4,
			// otherwise ::ffff:127.0.0.1 would slip past the v4 ranges.
			$unwrapped = $this->unwrap_mapped($address);

			if ($unwrapped !== null)
			{
				return !$this->in_any_range($unwrapped, self::$blocked_v4, false);
			}

			return !$this->in_any_range($address, self::$blocked_v6, true);
		}

		return false;
	}

	/**
	 * Extract the IPv4 address from an IPv4-mapped IPv6 address.
	 *
	 * @param string $address IPv6 address.
	 * @return string|null IPv4 address, or null when not mapped.
	 */
	protected function unwrap_mapped($address)
	{
		if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $m))
		{
			return $m[1];
		}

		$packed = @inet_pton($address);

		if ($packed !== false && strlen($packed) === 16 && substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff")
		{
			return inet_ntop(substr($packed, 12, 4));
		}

		return null;
	}

	/**
	 * Whether an address falls inside any of the given CIDR blocks.
	 *
	 * @param string   $address Address.
	 * @param string[] $ranges  CIDR blocks.
	 * @param bool     $is_v6   Whether the comparison is IPv6.
	 * @return bool
	 */
	protected function in_any_range($address, array $ranges, $is_v6)
	{
		foreach ($ranges as $range)
		{
			if ($this->in_range($address, $range, $is_v6))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * CIDR containment test, working on packed binary for both families.
	 *
	 * @param string $address Address.
	 * @param string $cidr    CIDR block.
	 * @param bool   $is_v6   Whether the comparison is IPv6.
	 * @return bool
	 */
	protected function in_range($address, $cidr, $is_v6)
	{
		list($subnet, $bits) = array_pad(explode('/', $cidr, 2), 2, null);
		$bits = (int) $bits;

		$packed_address = @inet_pton($address);
		$packed_subnet = @inet_pton($subnet);

		if ($packed_address === false || $packed_subnet === false)
		{
			return false;
		}

		if (strlen($packed_address) !== strlen($packed_subnet))
		{
			return false;
		}

		$full_bytes = intdiv($bits, 8);
		$remainder = $bits % 8;

		if ($full_bytes > 0 && strncmp($packed_address, $packed_subnet, $full_bytes) !== 0)
		{
			return false;
		}

		if ($remainder === 0)
		{
			return true;
		}

		$mask = chr(0xFF << (8 - $remainder) & 0xFF);

		return ($packed_address[$full_bytes] & $mask) === ($packed_subnet[$full_bytes] & $mask);
	}

	/**
	 * Whether a string is a literal IP address.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	protected function is_ip($value)
	{
		return (bool) filter_var($value, FILTER_VALIDATE_IP);
	}

	/**
	 * Whether a host is on the administrator's ignore list.
	 *
	 * A listed domain also covers its subdomains.
	 *
	 * @param string $host Lowercase host.
	 * @return bool
	 */
	protected function is_ignored_domain($host)
	{
		foreach ($this->settings->ignored_link_domains() as $domain)
		{
			$domain = ltrim($domain, '.');

			if ($domain === '')
			{
				continue;
			}

			if ($host === $domain || substr($host, -(strlen($domain) + 1)) === '.' . $domain)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a URL matches an ignored substring pattern.
	 *
	 * Plain substrings, not regular expressions: an administrator should not be
	 * able to hang the scanner with a pathological pattern.
	 *
	 * @param string $url Normalised URL.
	 * @return bool
	 */
	protected function matches_ignored_pattern($url)
	{
		$haystack = utf8_strtolower($url);

		foreach ($this->settings->ignored_link_patterns() as $pattern)
		{
			$pattern = utf8_strtolower(trim($pattern));

			if ($pattern !== '' && strpos($haystack, $pattern) !== false)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a refusal result.
	 *
	 * @param string $reason Reason code.
	 * @return array{allowed:bool,reason:string}
	 */
	protected function deny($reason)
	{
		return ['allowed' => false, 'reason' => $reason];
	}
}
