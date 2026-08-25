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
	// Identità dell'estensione
	'FH_EXTENSION_NAME'		=> 'Forum Health &amp; Intelligence',
	'FH_ERR_UNSUPPORTED_PHPBB'	=> 'Forum Health &amp; Intelligence richiede phpBB %s o successivo.',

	// Visibile nella parte pubblica del forum
	'FH_USER_WARNING_HEADING'	=> 'Abbiamo trovato discussioni che potrebbero già rispondere alla tua domanda.',
	'FH_USER_WARNING_CONTINUE'	=> 'Pubblica comunque',
	'FH_USER_WARNING_VIEW'		=> 'Vedi le discussioni',
	'FH_USER_WARNING_DISMISS'	=> 'Ignora questo suggerimento',
	'FH_RELATED_TOPICS'			=> 'Discussioni correlate',


	// Descrizioni dei permessi.
	//
	// Presenti anche in acp_forumhealth.php, di proposito. Le maschere dei
	// permessi sono generate dal modulo di phpBB, che non carica mai il file di
	// lingua ACP di questa estensione: le chiavi devono quindi stare nel file
	// caricato da core.user_setup a ogni richiesta.
	'ACL_A_FH_VIEW'					=> 'PuÃ² consultare i report di Forum Health',
	'ACL_A_FH_MANAGE'				=> 'PuÃ² intervenire sulle rilevazioni (presa in carico, chiusura, rifiuto)',
	'ACL_A_FH_MANAGE_CONTENT'		=> 'PuÃ² modificare le impostazioni di analisi dei contenuti',
	'ACL_A_FH_MANAGE_COMMUNITY'		=> 'PuÃ² modificare le impostazioni di analisi della comunitÃ ',
	'ACL_A_FH_MANAGE_INTEGRATIONS'	=> 'PuÃ² collegare e configurare le integrazioni',
	'ACL_A_FH_MANAGE_AI'			=> 'PuÃ² attivare lâanalisi IA e utilizzarne il budget',
	'ACL_A_FH_MANAGE_RULES'			=> 'PuÃ² creare e modificare le regole',

	// Vocabolario condiviso
	'FH_NEVER'		=> 'Mai',
	'FH_NO_DATA'	=> 'Nessun dato',
	'FH_YES'		=> 'Sì',
	'FH_NO'			=> 'No',
]);
