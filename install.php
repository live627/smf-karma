<?php

if (file_exists(dirname(__FILE__) . '/SSI.php') && !defined('SMF'))
{
	$ssi = true;
	require_once(dirname(__FILE__) . '/SSI.php');
}
elseif (!defined('SMF'))
	exit('<b>Error:</b> Cannot install - please verify you put this in the same place as SMF\'s index.php.');

if (!array_key_exists('db_add_column', $smcFunc))
	db_extend('packages');

$columns = array(
	array(
		'name' => 'id_target',
		'type' => 'mediumint',
		'size' => 8,
		'unsigned' => true
	),
	array(
		'name' => 'id_executor',
		'type' => 'mediumint',
		'size' => 8,
		'unsigned' => true
	),
	array(
		'name' => 'log_time',
		'type' => 'int',
		'size' => 10,
		'unsigned' => true
	),
	array(
		'name' => 'action',
		'type' => 'tinyint',
		'size' => 3
	)
);

$indexes = array(
	array(
		'type' => 'primary',
		'columns' => array('id_target', 'id_executor')
	),
	array(
		'type' => 'unique',
		'columns' => array('log_time')
	)
);

$smcFunc['db_create_table']('{db_prefix}log_karma', $columns, $indexes, array(), 'update_remove');

$perms = array('karma_edit');
$request = $smcFunc['db_query']('', '
	SELECT id_group
	FROM {db_prefix}permissions
	WHERE permission IN ({array_string:perms})',
	array(
		'perms' => $perms
	)
);

$num = $smcFunc['db_num_rows']($request);
$smcFunc['db_free_result']($request);

if (empty($num)) {
	$request = $smcFunc['db_query']('', '
		SELECT id_group
		FROM {db_prefix}membergroups
		WHERE id_group NOT IN ({array_int:exclude_groups})' . (empty($modSettings['permission_enable_postgroups']) ? '
			AND min_posts = {int:min_posts}' : ''),
		array(
			'exclude_groups' => array(1, 3),
			'min_posts'      => -1
		)
	);

	$groups = array();
	while ($row = $smcFunc['db_fetch_assoc']($request))
		foreach ($perms as $perm)
			$groups[] = array($row['id_group'], $perm, empty($modSettings['permission_enable_deny']) ? 1 : -1);

	foreach ($perms as $perm)
	{
		$groups[] = array(-1, $perm, !empty($modSettings['permission_enable_deny']) ? 1 : -1);
		$groups[] = array(0, $perm, !empty($modSettings['permission_enable_deny']) ? 1 : -1);
	}

	$smcFunc['db_insert']('ignore',
		'{db_prefix}permissions',
		array('id_group' => 'int', 'permission' => 'string', 'add_deny' => 'int'),
		$groups,
		array('id_group', 'permission')
	);
}

$smcFunc['db_insert']('ignore',
	'{db_prefix}custom_fields',
	array(
		'col_name' => 'string', 'field_type' => 'string', 'show_profile' => 'string',
		'private' => 'int', 'mask' => 'string', 'placement' => 'int'
	),
	array(
		array(
			'karma_good', 'text', 'none', 3, 'number', 6
		),
		array(
			'karma_bad', 'text', 'none', 3, 'number', 6
		)
	),
	array('id_field')
);

updateSettings(array(
	'karmaMode'               => '0',
	'karmaTimeRestrictAdmins' => '1',
	'karmaWaitTime'           => '1',
	'karmaMinPosts'           => '0'
));

if (!empty($ssi))
	echo 'Database installation complete!';
