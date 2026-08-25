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

$lang = array_merge($lang, [
	// Extension identity
	'FH_EXTENSION_NAME'		=> 'Forum Health &amp; Intelligence',
	'FH_ERR_UNSUPPORTED_PHPBB'	=> 'Forum Health &amp; Intelligence requires phpBB %s or later.',

	// Shown on the public side of the forum
	'FH_USER_WARNING_HEADING'	=> 'We found discussions that may already cover your question.',
	'FH_USER_WARNING_CONTINUE'	=> 'Post anyway',
	'FH_USER_WARNING_VIEW'		=> 'View discussions',
	'FH_USER_WARNING_DISMISS'	=> 'Dismiss this suggestion',
	'FH_RELATED_TOPICS'			=> 'Related discussions',


	// Permission descriptions.
	//
	// Also present in acp_forumhealth.php, deliberately. The ACP permission
	// masks are rendered by phpBB's own module, which never loads this
	// extension's ACP language file, so the keys have to be in the file that
	// core.user_setup loads on every request.
	'ACL_A_FH_VIEW'					=> 'Can view Forum Health reports',
	'ACL_A_FH_MANAGE'				=> 'Can act on findings (acknowledge, dismiss, resolve)',
	'ACL_A_FH_MANAGE_CONTENT'		=> 'Can change content analysis settings',
	'ACL_A_FH_MANAGE_COMMUNITY'		=> 'Can change community analysis settings',
	'ACL_A_FH_MANAGE_INTEGRATIONS'	=> 'Can connect and configure integrations',
	'ACL_A_FH_MANAGE_AI'			=> 'Can enable AI analysis and spend its budget',
	'ACL_A_FH_MANAGE_RULES'			=> 'Can create and edit rules',

	// Shared vocabulary
	'FH_NEVER'		=> 'Never',
	'FH_NO_DATA'	=> 'No data',
	'FH_YES'		=> 'Yes',
	'FH_NO'			=> 'No',
]);
