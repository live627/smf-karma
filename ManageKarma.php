<?php

/**
 * Karma
 *
 * This is the main file, handles the hooks, the actions, permissions, load needed files, etc.
 * @package   Karma mod
 * @version   1.0 Alpha
 * @author    John Rayes <live627@gmail.com>
 * @copyright Copyright (c) 2014, John Rayes
 * @license   http://opensource.org/licenses/MIT MIT
 */

if (!defined('SMF'))
	die('No direct access...');

/**
 * Config array for chaning the karma settings
 * Accessed  from ?action=admin;area=featuresettings;sa=karma;
 *
 * @param $return_config
 *
 * @return array
 */
function ModifyKarmaSettings($return_config = false)
{
	global $txt, $scripturl, $context, $modSettings;

	if (empty($modSettings['karmaMode']))
		$config_vars = array(
			array('select', 'karmaMode', explode('|', $txt['karma_options'])),
		);
	else
		$config_vars = array(
			// Karma - On or off?
			array('select', 'karmaMode', explode('|', $txt['karma_options'])),
			'',
			// Who can do it.... and who is restricted by time limits?
			array('int', 'karmaMinPosts', 6, 'postinput' => strtolower($txt['posts'])),
			array('float', 'karmaWaitTime', 6, 'postinput' => $txt['hours']),
			array('check', 'karmaTimeRestrictAdmins'),
		);

	call_integration_hook('integrate_karma_settings', array(&$config_vars));

	if ($return_config)
		return $config_vars;

	// Saving?
	if (isset($_GET['save']))
	{
		checkSession();

		call_integration_hook('integrate_save_karma_settings');

		saveDBSettings($config_vars);
		$_SESSION['adm-save'] = true;
		redirectexit('action=admin;area=featuresettings;sa=karma');
	}

	$context['post_url'] = $scripturl . '?action=admin;area=featuresettings;save;sa=karma';
	$context['settings_title'] = $txt['karma'];

	loadLanguage('ManageKarma');
	prepareDBSettingContext($config_vars);
}
