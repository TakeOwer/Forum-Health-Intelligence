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
 * Extension bootstrap.
 *
 * The extension deliberately declares no hard dependency on any other extension.
 * Meilisearch and AI Bots are optional and are resolved at runtime by the
 * integration registry; their absence never blocks enabling this extension.
 */
class ext extends \phpbb\extension\base
{
	/** Minimum supported phpBB version (inclusive). */
	const PHPBB_MIN = '3.3.0';

	/** First unsupported phpBB version (exclusive upper bound). */
	const PHPBB_MAX = '4.0.0@dev';

	/**
	 * {@inheritdoc}
	 */
	public function is_enableable()
	{
		$config = $this->container->get('config');

		$supported = phpbb_version_compare($config['version'], self::PHPBB_MIN, '>=')
			&& phpbb_version_compare($config['version'], self::PHPBB_MAX, '<');

		if ($supported)
		{
			return true;
		}

		$language = $this->container->get('language');
		$language->add_lang('common', 'salvocortesiano/forumhealth');

		return [$language->lang('FH_ERR_UNSUPPORTED_PHPBB', self::PHPBB_MIN)];
	}
}
