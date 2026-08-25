<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

/**
 * ACP navigation titles.
 *
 * phpBB loads every info_acp_*.php file from enabled extensions when it builds
 * the administration menu, before any module has run. The module titles have to
 * live here rather than in acp_forumhealth.php, which is only loaded once a page
 * of this extension is already open — by which point the menu has been drawn.
 */
$lang = array_merge($lang, [
	'ACP_FH_TITLE'					=> 'Forum Health &amp; Intelligence',
	'ACP_FH_DASHBOARD'				=> 'Dashboard',
	'ACP_FH_CONTENT'				=> 'Content health',
	'ACP_FH_UNANSWERED'				=> 'Unanswered topics',
	'ACP_FH_DUPLICATES'				=> 'Possible duplicates',
	'ACP_FH_LINKS'					=> 'Broken links',
	'ACP_FH_FRESHNESS'				=> 'Potentially outdated',
	'ACP_FH_SOLUTIONS'				=> 'Solutions',
	'ACP_FH_COMMUNITY'				=> 'Community health',
	'ACP_FH_COMMUNITY_OVERVIEW'		=> 'Overview',
	'ACP_FH_NEWUSERS'				=> 'New member experience',
	'ACP_FH_TRENDS'					=> 'Activity trends',
	'ACP_FH_CONTRIBUTORS'			=> 'Contributors',
	'ACP_FH_ALERTS'					=> 'Alerts',
	'ACP_FH_RECOMMENDATIONS'		=> 'Recommendations',
	'ACP_FH_RULES'					=> 'Rules',
	'ACP_FH_INTEGRATIONS'			=> 'Integrations',
	'ACP_FH_AI'						=> 'AI analysis',
	'ACP_FH_JOBS'					=> 'Background jobs',
	'ACP_FH_SETTINGS'				=> 'Settings',
]);
