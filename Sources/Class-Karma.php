<?php

/**
 * Karma
 *
 * This is the main file, handles the hooks, the actions, permissions, load needed files, etc.
 * @package Karma mod
 * @version 1.0 Alpha
 * @author John Rayes <live627@gmail.com>
 * @copyright Copyright (c) 2014, John Rayes
 * @license http://opensource.org/licenses/MIT MIT
 */

if (!defined('SMF'))
	die('Hacking attempt...');

class KarmaIntegration
{
	public static function load_theme()
	{
		loadLanguage('Karma+ManageKarma');
	}

	public static function actions(&$action_array)
	{
		$action_array['karma'] = array('Class-Karma.php', 'Karma::init#');
	}

	public static function modify_features(&$subActions)
	{
		global $sourcedir;

		require_once($sourcedir . '/ManageKarma.php');

		$subActions['karma'] = 'ModifyKarmaSettings';
	}

	public static function admin_areas(&$admin_areas)
	{
		global $txt;

		$admin_areas['config']['areas']['featuresettings']['subsections']['karma'] = array($txt['mods_cat_karma']);
	}

	public static function admin_search(&$language_files, &$include_files, &$settings_search)
	{
		$language_files[]  = 'ManageKarma';
		$include_files[]   = 'ManageKarma';
		$settings_search[] = array('ModifyKarmaSettings', 'area=featuresettings;sa=karma');
	}

	public static function load_permissions(&$permissionGroups, &$permissionList, &$leftPermissionGroups, &$hiddenPermissions, &$relabelPermissions)
	{
		global $modSettings;

		$permissionList['membergroup']['karma_edit'] = array(false, 'general');

		if (empty($modSettings['karmaMode']))
			$hiddenPermissions[] = 'karma_edit';
	}

	public static function load_permission_levels(&$groupLevels, &$boardLevels)
	{
		$groupLevels['global']['standard'][] = 'karma_edit';
	}

	public static function illegal_guest_permissions()
	{
		global $context;

		$context['non_guest_permissions'][] = 'karma_edit';
	}

	public static function reports_groupperm(&$disabled_permissions)
	{
		global $modSettings;

		if (empty($modSettings['karmaMode']))
			$disabled_permissions[] = 'karma_edit';
	}

	public static function member_context(&$memData, $memID, $display_custom_fields)
	{
		global $context, $modSettings, $memberContext, $scripturl, $txt;
		static $karma;

		if (!empty($modSettings['karmaMode']) && $display_custom_fields) {
			if (empty($karma[$memID]))
				$karma = loadMemberCustomFields($memID, array('karma_good', 'karma_bad'));

			if (empty($karma[$memID]['karma_good']['value']))
				$karma[$memID]['karma_good']['value'] = 0;

			if (empty($karma[$memID]['karma_bad']['value']))
				$karma[$memID]['karma_bad']['value'] = 0;

			// Total or +/-?
			if ($modSettings['karmaMode'] == 1)
				$value = $karma[$memID]['karma_good']['value'] - $karma[$memID]['karma_bad']['value'];
			elseif ($modSettings['karmaMode'] == 2)
				$value = '+' . $karma[$memID]['karma_good']['value'] . ' / -' .  $karma[$memID]['karma_bad']['value'];

			$memberContext[$memID]['custom_fields'][] = array(
				'title'     => $txt['karma'],
				'col_name'  => 'karma',
				'value'     => $value,
				'placement' => 6
			);

			$memberContext[$memID]['custom_fields'][] = array(
				'title'     => '',
				'col_name'  => 'karma_labels',
				'value'     => '
					<a href = "' . $scripturl . '?action=karma;sa=applaud;uid=' . $memID . ';' . $context['session_var'] . '=' . $context['session_id'] . '">' . $txt['karmaApplaudLabel'] . '</a>
					<a href = "' . $scripturl . '?action=karma;sa=smite;uid=' . $memID . ';' . $context['session_var'] . '=' . $context['session_id'] . '">' . $txt['karmaSmiteLabel'] . '</a>',
				'placement' =>  6
			);
		}
	}
}

class Karma
{
	/**
	 * Modify a user's karma.
	 * It redirects back to the referrer afterward, whether by javascript or the passed parameters.
	 * Requires the karma_edit permission, and that the user isn't a guest.
	 * It depends on the karmaMode, karmaWaitTime, and karmaTimeRestrictAdmins settings.
	 * It is accessed via ?action=karma.
	 *
	 * @return void
	 */
	public function init()
	{
		global $modSettings, $txt, $user_info, $topic, $smcFunc, $context, $sourcedir;

		// If the mod is disabled, show an error.
		if (empty($modSettings['karmaMode']))
			fatal_lang_error('feature_disabled', true);

		// If you're a guest or can't do this, blow you off...
		is_not_guest();
		isAllowedTo('karma_edit');

		checkSession('get');

		// If you don't have enough posts, tough luck.
		// @todo Should this be dropped in favor of post group permissions?
		// Should this apply to the member you are smiting/applauding?
		if (!$user_info['is_admin'] && $user_info['posts'] < $modSettings['karmaMinPosts'])
			fatal_lang_error('not_enough_posts_karma', true, array($modSettings['karmaMinPosts']));

		// And you can't modify your own, punk! (use the profile if you need to.)
		if (empty($_REQUEST['uid']) || (int) $_REQUEST['uid'] == $user_info['id'])
			fatal_lang_error('cant_change_own_karma', false);

		// The user ID _must_ be a number, no matter what.
		$memID = (int) $_REQUEST['uid'];

		// Applauding or smiting?
		$dir = $_REQUEST['sa'] != 'applaud' ? -1 : 1;

		if (($dir == 1 && empty($txt['karmaApplaudLabel'])) || ($dir == -1 && empty($txt['karmaSmiteLabel'])))
			fatal_lang_error('feature_disabled', false);

		// Delete any older items from the log. (karmaWaitTime is by hour.)
		$smcFunc['db_query']('', '
			DELETE FROM {db_prefix}log_karma
			WHERE {int:current_time} - log_time > {int:wait_time}',
			array(
				'wait_time'    => (int) ($modSettings['karmaWaitTime'] * 3600),
				'current_time' => time()
			)
		);

		// Start off with no change in karma.
		$action = 0;

		// Not an administrator... or one who is restricted as well.
		if (!empty($modSettings['karmaTimeRestrictAdmins']) || !allowedTo('moderate_forum')) {
			// Find out if this user has done this recently...
			$request = $smcFunc['db_query']('', '
				SELECT action
				FROM {db_prefix}log_karma
				WHERE id_target = {int:id_target}
					AND id_executor = {int:current_member}
				LIMIT 1',
				array(
					'current_member' => $user_info['id'],
					'id_target'      => $memID
				)
			);

			if ($smcFunc['db_num_rows']($request) > 0)
				list ($action) = $smcFunc['db_fetch_row']($request);
			$smcFunc['db_free_result']($request);
		}

		$karma = loadMemberCustomFields($memID, array('karma_good', 'karma_bad'));

		if (empty($karma[$memID]['karma_good']['value']))
			$karma[$memID]['karma_good']['value'] = 0;

		if (empty($karma[$memID]['karma_bad']['value']))
			$karma[$memID]['karma_bad']['value'] = 0;

		$changes = array();
		$log_changes = array();
		$col_name = $dir == 1 ? 'karma_good' : 'karma_bad';
		$value = $karma[$memID][$col_name]['value'] + 1;

		// They haven't, not before now, anyhow.
		if (empty($action) || empty($modSettings['karmaWaitTime'])) {
			// Put it in the log.
			$smcFunc['db_insert']('replace',
				'{db_prefix}log_karma',
				array('action' => 'int', 'id_target' => 'int', 'id_executor' => 'int', 'log_time' => 'int'),
				array($dir, $memID, $user_info['id'], time()),
				array('id_target', 'id_executor')
			);

			// Change by one.
			$log_changes[] = array(
				'action' => $col_name,
				'log_type' => 'user',
				'extra' => array(
					'value'           => $value,
					'applicator'      => $user_info['id'],
					'member_affected' => $memID
				)
			);

			$changes[] = array(1, $col_name, $value, $memID);
		} else {
			// If you are gonna try to repeat.... don't allow it.
			if ($action == $dir)
				fatal_lang_error('karma_wait_time', false, array($modSettings['karmaWaitTime'], ($modSettings['karmaWaitTime'] == 1 ? strtolower($txt['hour']) : $txt['hours'])));

			// You decided to go back on your previous choice?
			$smcFunc['db_query']('', '
				UPDATE {db_prefix}log_karma
				SET action = {int:action}, log_time = {int:current_time}
				WHERE id_target = {int:id_target}
					AND id_executor = {int:current_member}',
				array(
					'current_member' => $user_info['id'],
					'action'         => $dir,
					'current_time'   => time(),
					'id_target'      => $memID
				)
			);

			// It was recently changed the OTHER way... so... reverse it!
			$log_changes[] = array(
				'action' => $col_name,
				'log_type' => 'user',
				'extra' => array(
					'value'           => $value,
					'applicator'      => $user_info['id'],
					'member_affected' => $memID
				)
			);

			$changes[] = array(1, $col_name, $value, $memID);
			$changes[] = array(1, $dir == 1 ? 'karma_good' : 'karma_bad', $karma[$memID][$dir == 1 ? 'karma_good' : 'karma_bad']['value'] - 1, $memID);
		}

		// Make those changes!
		if (!empty($changes)) {
			$smcFunc['db_insert']('replace',
				'{db_prefix}themes',
				array('id_theme' => 'int', 'variable' => 'string-255', 'value' => 'string-65534', 'id_member' => 'int'),
				$changes,
				array('id_theme', 'variable', 'id_member')
			);

			if (!empty($log_changes) && !empty($modSettings['modlog_enabled'])) {
				require_once($sourcedir . '/Logging.php');
				logActions($log_changes);
			}
		}

		if (true)
			redirectexit($_SERVER['HTTP_REFERER']);
		else {
			echo '<!DOCTYPE html>
	<html', $context['right_to_left'] ? ' dir="rtl"' : '', '>
		<head>
			<title>...</title>
			<script><!-- // --><![CDATA[
				history.go(-1);
			// ]]></script>
		</head>
		<body>&laquo;</body>
	</html>';

			obExit(false);
		}
	}
}
