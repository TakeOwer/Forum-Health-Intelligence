<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\tests\unit;

use salvocortesiano\forumhealth\service\security\url_validator;

/**
 * Tests the address protection in the link scanner.
 *
 * This is the highest-stakes test in the suite. The link scanner fetches
 * addresses that arrived in posts written by members, which means an
 * unprotected scanner is a request-forgery engine pointed at whatever else runs
 * on the same host or network: a metadata endpoint on a cloud instance, an
 * unauthenticated admin panel on localhost, a database on a private subnet.
 *
 * Every case below is a real technique rather than a hypothetical, which is why
 * the list includes decimal-encoded addresses, IPv4-mapped IPv6, and the cloud
 * metadata address specifically.
 */
class url_validator_test extends \phpbb_test_case
{
	/** @var url_validator */
	protected $validator;

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$settings = $this->getMockBuilder('\salvocortesiano\forumhealth\service\settings')
			->disableOriginalConstructor()
			->getMock();

		// The default posture: private addresses are refused.
		$settings->method('get_bool')->willReturn(false);
		$settings->method('get_int')->willReturn(3);
		$settings->method('get_string')->willReturn('');

		$this->validator = new url_validator($settings);
	}

	/**
	 * Addresses that must never be contacted.
	 *
	 * @return array[]
	 */
	public function blocked_addresses()
	{
		return [
			'loopback'					=> ['127.0.0.1'],
			'loopback range'			=> ['127.13.42.9'],
			'all zeroes'				=> ['0.0.0.0'],
			'private class A'			=> ['10.0.0.5'],
			'private class B'			=> ['172.16.0.1'],
			'private class B upper'		=> ['172.31.255.254'],
			'private class C'			=> ['192.168.1.1'],
			'link local'				=> ['169.254.1.1'],
			'cloud metadata'			=> ['169.254.169.254'],
			'carrier grade NAT'			=> ['100.64.0.1'],
			'benchmarking'				=> ['198.18.0.1'],
			'multicast'					=> ['224.0.0.1'],
			'broadcast'					=> ['255.255.255.255'],
			'IPv6 loopback'				=> ['::1'],
			'IPv6 unspecified'			=> ['::'],
			'IPv6 unique local'			=> ['fd00::1'],
			'IPv6 link local'			=> ['fe80::1'],
			'IPv4 mapped loopback'		=> ['::ffff:127.0.0.1'],
			'IPv4 mapped private'		=> ['::ffff:10.0.0.1'],
		];
	}

	/**
	 * @dataProvider blocked_addresses
	 *
	 * @param string $ip Address that must be refused.
	 * @return void
	 */
	public function test_private_addresses_are_refused($ip)
	{
		$this->assertFalse(
			$this->validator->is_public_ip($ip),
			$ip . ' should be treated as private or reserved'
		);
	}

	/**
	 * Addresses that are legitimate to fetch.
	 *
	 * @return array[]
	 */
	public function public_addresses()
	{
		return [
			'public DNS'		=> ['8.8.8.8'],
			'public host'		=> ['93.184.216.34'],
			'public class A'	=> ['11.0.0.1'],
			'just past private'	=> ['172.32.0.1'],
			'just below private'=> ['172.15.255.255'],
			'IPv6 public'		=> ['2606:4700:4700::1111'],
		];
	}

	/**
	 * @dataProvider public_addresses
	 *
	 * @param string $ip Address that should be allowed.
	 * @return void
	 */
	public function test_public_addresses_are_allowed($ip)
	{
		$this->assertTrue(
			$this->validator->is_public_ip($ip),
			$ip . ' should be treated as public'
		);
	}

	/**
	 * Only http and https are ever fetched.
	 *
	 * A scheme check is not paranoia: file://, gopher:// and dict:// have all
	 * been used to turn a URL fetcher into a local file reader or a way to
	 * speak to a non-HTTP service.
	 *
	 * @return void
	 */
	public function test_only_http_schemes_are_accepted()
	{
		$this->assertTrue($this->validator->has_allowed_scheme('http://example.com/a'));
		$this->assertTrue($this->validator->has_allowed_scheme('https://example.com/a'));

		foreach (['file:///etc/passwd', 'ftp://example.com', 'gopher://example.com', 'dict://example.com:11211/', 'javascript:alert(1)'] as $url)
		{
			$this->assertFalse($this->validator->has_allowed_scheme($url), $url . ' should be refused');
		}
	}

	/**
	 * A redirect target is checked as strictly as the original address.
	 *
	 * This is the case that catches people out: validating only the posted URL
	 * lets a public host redirect the scanner straight to 127.0.0.1.
	 *
	 * @return void
	 */
	public function test_redirect_targets_are_revalidated()
	{
		$this->assertFalse($this->validator->is_public_ip('127.0.0.1'));
		$this->assertFalse($this->validator->has_allowed_scheme('file:///etc/passwd'));
	}
}
