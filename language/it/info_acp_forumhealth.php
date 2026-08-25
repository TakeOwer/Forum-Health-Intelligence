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
 * phpBB carica ogni file info_acp_*.php delle estensioni attive quando costruisce
 * the administration menu, before any module has run. The module titles have to
 * live here rather than in acp_forumhealth.php, which is only loaded once a page
 * of this extension is already open — by which point the menu has been drawn.
 */
$lang = array_merge($lang, [
	'ACP_FH_TITLE'					=> 'Forum Health &amp; Intelligence',
	'ACP_FH_DASHBOARD'				=> 'Panoramica',
	'ACP_FH_CONTENT'				=> 'Salute dei contenuti',
	'ACP_FH_UNANSWERED'				=> 'Discussioni senza risposta',
	'ACP_FH_DUPLICATES'				=> 'Possibili duplicati',
	'ACP_FH_LINKS'					=> 'Collegamenti non funzionanti',
	'ACP_FH_FRESHNESS'				=> 'Contenuti forse obsoleti',
	'ACP_FH_SOLUTIONS'				=> 'Soluzioni',
	'ACP_FH_COMMUNITY'				=> 'Salute della comunità',
	'ACP_FH_COMMUNITY_OVERVIEW'		=> 'Quadro generale',
	'ACP_FH_NEWUSERS'				=> 'Esperienza dei nuovi iscritti',
	'ACP_FH_TRENDS'					=> 'Andamento delle attività',
	'ACP_FH_CONTRIBUTORS'			=> 'Chi contribuisce',
	'ACP_FH_ALERTS'					=> 'Segnalazioni',
	'ACP_FH_RECOMMENDATIONS'		=> 'Suggerimenti',
	'ACP_FH_RULES'					=> 'Regole',
	'ACP_FH_INTEGRATIONS'			=> 'Integrazioni',
	'ACP_FH_AI'						=> 'Analisi con IA',
	'ACP_FH_JOBS'					=> 'Elaborazioni in background',
	'ACP_FH_SETTINGS'				=> 'Impostazioni',
]);
