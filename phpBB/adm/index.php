<?php
/**
*
* @package acp
* @version $Id$
* @copyright (c) 2005 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
*/
define('IN_PHPBB', true);
define('ADMIN_START', true);
define('NEED_SID', true);

// Include files
if (!defined('PHPBB_ROOT_PATH')) define('PHPBB_ROOT_PATH', './../');
if (!defined('PHP_EXT')) define('PHP_EXT', substr(strrchr(__FILE__, '.'), 1));
if (!defined('PHPBB_ADMIN_PATH')) define('PHPBB_ADMIN_PATH', './');

include PHPBB_ROOT_PATH . 'common.' . PHP_EXT;
require PHPBB_ROOT_PATH . 'includes/functions_admin.' . PHP_EXT;
require PHPBB_ROOT_PATH . 'includes/functions_module.' . PHP_EXT;

// Start session management
phpbb::$user->session_begin();
phpbb::$acl->init(phpbb::$user->data);
phpbb::$user->setup('acp/common');
// End session management

// Have they authenticated (again) as an admin for this session?
if (!phpbb::$user->is_guest && (!isset(phpbb::$user->data['session_admin']) || !phpbb::$user->data['session_admin']))
{
	login_box('', phpbb::$user->lang['LOGIN_ADMIN_CONFIRM'], phpbb::$user->lang['LOGIN_ADMIN_SUCCESS'], true, false);
}
else if (phpbb::$user->is_guest)
{
	login_box('');
}

// Is user any type of admin? No, then stop here, each script needs to
// check specific permissions but this is a catchall
if (!phpbb::$acl->acl_get('a_'))
{
	trigger_error('NO_ADMIN');
}

// We define the admin variables now, because the user is now able to use the admin related features...
define('IN_ADMIN', true);

// Some oft used variables
$safe_mode		= (@ini_get('safe_mode') == '1' || strtolower(@ini_get('safe_mode')) === 'on') ? true : false;
$file_uploads	= (@ini_get('file_uploads') == '1' || strtolower(@ini_get('file_uploads')) === 'on') ? true : false;
$module_id		= request_var('i', '');
$mode			= request_var('mode', '');

// Set custom template for admin area
phpbb::$template->set_custom_template(PHPBB_ADMIN_PATH . 'style', 'admin');
phpbb::$template->assign_var('T_TEMPLATE_PATH', PHPBB_ADMIN_PATH . 'style');

// Define page header/footer to use
phpbb::$plugins->register_function('page_header', 'adm_page_header', phpbb::FUNCTION_OVERRIDE);
phpbb::$plugins->register_function('page_footer', 'adm_page_footer', phpbb::FUNCTION_OVERRIDE);

// And make the calls available
phpbb::$plugins->setup();

// Instantiate new module
$module = new p_master();

// Instantiate module system and generate list of available modules
$module->list_modules('acp');

// Select the active module
$module->set_active($module_id, $mode);

// Assign data to the template engine for the list of modules
// We do this before loading the active module for correct menu display in trigger_error
$module->assign_tpl_vars(phpbb::$url->append_sid(PHPBB_ADMIN_PATH . 'index.' . PHP_EXT));

// Load and execute the relevant module
$module->load_active();

// Generate the page
page_header($module->get_page_title());

phpbb::$template->set_filenames(array(
	'body' => $module->get_tpl_name(),
));

page_footer();

/**
* Header for acp pages
*/
function adm_page_header($page_title)
{
	if (defined('HEADER_INC'))
	{
		return;
	}

	define('HEADER_INC', true);

	// gzip_compression
	if (phpbb::$config['gzip_compress'])
	{
		if (@extension_loaded('zlib') && !headers_sent())
		{
			ob_start('ob_gzhandler');
		}
	}

	phpbb::$template->assign_vars(array(
		'PAGE_TITLE'			=> $page_title,
		'USERNAME'				=> (!phpbb::$user->is_guest) ? phpbb::$user->data['username'] : '',

		'SESSION_ID'			=> phpbb::$user->session_id,
		'ROOT_PATH'				=> PHPBB_ADMIN_PATH,

		'U_LOGOUT'				=> phpbb::$url->append_sid('ucp', 'mode=logout'),
		'U_ADM_LOGOUT'			=> phpbb::$url->append_sid(PHPBB_ADMIN_PATH . 'index.' . PHP_EXT, 'action=admlogout'),
		'U_ADM_INDEX'			=> phpbb::$url->append_sid(PHPBB_ADMIN_PATH . 'index.' . PHP_EXT),
		'U_INDEX'				=> phpbb::$url->append_sid('index'),

		'S_USER_ADMIN'			=> phpbb::$user->data['session_admin'],
		'S_USER_LOGGED_IN'		=> (phpbb::$user->is_registered),

		'T_IMAGES_PATH'			=> PHPBB_ROOT_PATH . 'images/',
		'T_SMILIES_PATH'		=> PHPBB_ROOT_PATH . phpbb::$config['smilies_path'] . '/',
		'T_AVATAR_PATH'			=> PHPBB_ROOT_PATH . phpbb::$config['avatar_path'] . '/',
		'T_AVATAR_GALLERY_PATH'	=> PHPBB_ROOT_PATH . phpbb::$config['avatar_gallery_path'] . '/',
		'T_ICONS_PATH'			=> PHPBB_ROOT_PATH . phpbb::$config['icons_path'] . '/',
		'T_RANKS_PATH'			=> PHPBB_ROOT_PATH . phpbb::$config['ranks_path'] . '/',
		'T_UPLOAD_PATH'			=> PHPBB_ROOT_PATH . phpbb::$config['upload_path'] . '/',

		'ICON_MOVE_UP'				=> '<img src="' . PHPBB_ADMIN_PATH . 'images/icon_up.gif" alt="' . phpbb::$user->lang['MOVE_UP'] . '" title="' . phpbb::$user->lang['MOVE_UP'] . '" />',
		'ICON_MOVE_UP_DISABLED'		=> '<img src="' . PHPBB_ADMIN_PATH . 'images/icon_up_disabled.gif" alt="' . phpbb::$user->lang['MOVE_UP'] . '" title="' . phpbb::$user->lang['MOVE_UP'] . '" />',
		'ICON_MOVE_DOWN'			=> '<img src="' . PHPBB_ADMIN_PATH . 'images/icon_down.gif" alt="' . phpbb::$user->lang['MOVE_DOWN'] . '" title="' . phpbb::$user->lang['MOVE_DOWN'] . '" />',
		'ICON_MOVE_DOWN_DISABLED'	=> '<img src="' . PHPBB_ADMIN_PATH . 'images/icon_down_disabled.gif" alt="' . phpbb::$user->lang['MOVE_DOWN'] . '" title="' . phpbb::$user->lang['MOVE_DOWN'] . '" />',
		'ICON_EDIT'					=> '<img src="' . PHPBB_ADMIN_PATH . 'images/icon_edit.gif" alt="' . phpbb::$user->lang['EDIT'] . '" title="' . phpbb::$user->lang['EDIT'] . '" />',
		'ICON_EDIT_DISABLED'		=> '<img src="' . PHPBB_ADMIN_PATH . 'images/icon_edit_disabled.gif" alt="' . phpbb::$user->lang['EDIT'] . '" title="' . phpbb::$user->lang['EDIT'] . '" />',
		'ICON_DELETE'				=> '<img src="' . PHPBB_ADMIN_PATH . 'images/icon_delete.gif" alt="' . phpbb::$user->lang['DELETE'] . '" title="' . phpbb::$user->lang['DELETE'] . '" />',
		'ICON_DELETE_DISABLED'		=> '<img src="' . PHPBB_ADMIN_PATH . 'images/icon_delete_disabled.gif" alt="' . phpbb::$user->lang['DELETE'] . '" title="' . phpbb::$user->lang['DELETE'] . '" />',
		'ICON_SYNC'					=> '<img src="' . PHPBB_ADMIN_PATH . 'images/icon_sync.gif" alt="' . phpbb::$user->lang['RESYNC'] . '" title="' . phpbb::$user->lang['RESYNC'] . '" />',
		'ICON_SYNC_DISABLED'		=> '<img src="' . PHPBB_ADMIN_PATH . 'images/icon_sync_disabled.gif" alt="' . phpbb::$user->lang['RESYNC'] . '" title="' . phpbb::$user->lang['RESYNC'] . '" />',

		'S_USER_LANG'			=> phpbb::$user->lang['USER_LANG'],
		'S_CONTENT_DIRECTION'	=> phpbb::$user->lang['DIRECTION'],
		'S_CONTENT_ENCODING'	=> 'UTF-8',
		'S_CONTENT_FLOW_BEGIN'	=> (phpbb::$user->lang['DIRECTION'] == 'ltr') ? 'left' : 'right',
		'S_CONTENT_FLOW_END'	=> (phpbb::$user->lang['DIRECTION'] == 'ltr') ? 'right' : 'left',
	));

	// application/xhtml+xml not used because of IE
	header('Content-type: text/html; charset=UTF-8');

	header('Cache-Control: private, no-cache="set-cookie"');
	header('Expires: 0');
	header('Pragma: no-cache');

	return;
}

/**
* Page footer for acp pages
*/
function adm_page_footer($copyright_html = true)
{
	global $starttime;

	// Output page creation time
	if (phpbb::$base_config['debug'])
	{
		$mtime = explode(' ', microtime());
		$totaltime = $mtime[0] + $mtime[1] - $starttime;

		if (phpbb_request::variable('explain', false) && phpbb::$acl->acl_get('a_') && phpbb::$base_config['debug_extra'] && method_exists(phpbb::$db, 'sql_report'))
		{
			phpbb::$db->sql_report('display');
		}

		$debug_output = sprintf('Time : %.3fs | ' . phpbb::$db->sql_num_queries() . ' Queries | GZIP : ' . ((phpbb::$config['gzip_compress']) ? 'On' : 'Off') . ((phpbb::$user->system['load']) ? ' | Load : ' . phpbb::$user->system['load'] : ''), $totaltime);

		if (phpbb::$acl->acl_get('a_') && phpbb::$base_config['debug_extra'])
		{
			if (function_exists('memory_get_usage'))
			{
				if ($memory_usage = memory_get_usage())
				{
					$memory_usage -= phpbb::$base_config['memory_usage'];
					$memory_usage = get_formatted_filesize($memory_usage);

					$debug_output .= ' | Memory Usage: ' . $memory_usage;
				}
			}

			$debug_output .= ' | <a href="' . phpbb::$url->build_url() . '&amp;explain=1">Explain</a>';
		}
	}

	phpbb::$template->assign_vars(array(
		'DEBUG_OUTPUT'		=> (phpbb::$base_config['debug']) ? $debug_output : '',
		'TRANSLATION_INFO'	=> (!empty(phpbb::$user->lang['TRANSLATION_INFO'])) ? phpbb::$user->lang['TRANSLATION_INFO'] : '',
		'S_COPYRIGHT_HTML'	=> $copyright_html,
		'VERSION'			=> phpbb::$config['version'])
	);

	phpbb::$template->display('body');

	garbage_collection();
	exit_handler();
}

/**
* Generate back link for acp pages
*/
function adm_back_link($u_action)
{
	return '<br /><br /><a href="' . $u_action . '">&laquo; ' . phpbb::$user->lang['BACK_TO_PREV'] . '</a>';
}

/**
* Build select field options in acp pages
*/
function build_select($option_ary, $option_default = false)
{
	$html = '';
	foreach ($option_ary as $value => $title)
	{
		$selected = ($option_default !== false && $value == $option_default) ? ' selected="selected"' : '';
		$html .= '<option value="' . $value . '"' . $selected . '>' . phpbb::$user->lang[$title] . '</option>';
	}

	return $html;
}

/**
* Build radio fields in acp pages
*/
function h_radio($name, &$input_ary, $input_default = false, $id = false, $key = false)
{
	$html = '';
	$id_assigned = false;
	foreach ($input_ary as $value => $title)
	{
		$selected = ($input_default !== false && $value == $input_default) ? ' checked="checked"' : '';
		$html .= '<label><input type="radio" name="' . $name . '"' . (($id && !$id_assigned) ? ' id="' . $id . '"' : '') . ' value="' . $value . '"' . $selected . (($key) ? ' accesskey="' . $key . '"' : '') . ' class="radio" /> ' . phpbb::$user->lang[$title] . '</label>';
		$id_assigned = true;
	}

	return $html;
}

/**
* Build configuration template for acp configuration pages
*/
function build_cfg_template($tpl_type, $key, &$new, $config_key, $vars)
{
	global $module;

	$tpl = '';
	$name = 'config[' . $config_key . ']';

	switch ($tpl_type[0])
	{
		case 'text':
		case 'password':
			$size = (int) $tpl_type[1];
			$maxlength = (int) $tpl_type[2];

			$tpl = '<input id="' . $key . '" type="' . $tpl_type[0] . '"' . (($size) ? ' size="' . $size . '"' : '') . ' maxlength="' . (($maxlength) ? $maxlength : 255) . '" name="' . $name . '" value="' . $new[$config_key] . '" />';
		break;

		case 'dimension':
			$size = (int) $tpl_type[1];
			$maxlength = (int) $tpl_type[2];

			$tpl = '<input id="' . $key . '" type="text"' . (($size) ? ' size="' . $size . '"' : '') . ' maxlength="' . (($maxlength) ? $maxlength : 255) . '" name="config[' . $config_key . '_width]" value="' . $new[$config_key . '_width'] . '" /> x <input type="text"' . (($size) ? ' size="' . $size . '"' : '') . ' maxlength="' . (($maxlength) ? $maxlength : 255) . '" name="config[' . $config_key . '_height]" value="' . $new[$config_key . '_height'] . '" />';
		break;

		case 'textarea':
			$rows = (int) $tpl_type[1];
			$cols = (int) $tpl_type[2];

			$tpl = '<textarea id="' . $key . '" name="' . $name . '" rows="' . $rows . '" cols="' . $cols . '">' . $new[$config_key] . '</textarea>';
		break;

		case 'radio':
			$key_yes	= ($new[$config_key]) ? ' checked="checked"' : '';
			$key_no		= (!$new[$config_key]) ? ' checked="checked"' : '';

			$tpl_type_cond = explode('_', $tpl_type[1]);
			$type_no = ($tpl_type_cond[0] == 'disabled' || $tpl_type_cond[0] == 'enabled') ? false : true;

			$tpl_no = '<label><input type="radio" name="' . $name . '" value="0"' . $key_no . ' class="radio" /> ' . (($type_no) ? phpbb::$user->lang['NO'] : phpbb::$user->lang['DISABLED']) . '</label>';
			$tpl_yes = '<label><input type="radio" id="' . $key . '" name="' . $name . '" value="1"' . $key_yes . ' class="radio" /> ' . (($type_no) ? phpbb::$user->lang['YES'] : phpbb::$user->lang['ENABLED']) . '</label>';

			$tpl = ($tpl_type_cond[0] == 'yes' || $tpl_type_cond[0] == 'enabled') ? $tpl_yes . $tpl_no : $tpl_no . $tpl_yes;
		break;

		case 'select':
		case 'select_multiple':
		case 'custom':

			$return = '';

			if (isset($vars['method']))
			{
				$call = array($module->module, $vars['method']);
			}
			else if (isset($vars['function']))
			{
				$call = $vars['function'];
			}
			else
			{
				break;
			}

			if (isset($vars['params']))
			{
				$args = array();
				foreach ($vars['params'] as $value)
				{
					switch ($value)
					{
						case '{CONFIG_VALUE}':
							$value = $new[$config_key];
						break;

						case '{KEY}':
							$value = $key;
						break;
					}

					$args[] = $value;
				}
			}
			else
			{
				if ($tpl_type[0] == 'select_multiple')
				{
					$new[$config_key] = @unserialize(trim($new[$config_key]));
				}

				$args = array($new[$config_key], $key);
			}

			$return = call_user_func_array($call, $args);

			if ($tpl_type[0] == 'select_multiple')
			{
				$tpl = '<select id="' . $key . '" name="' . $name . '[]" multiple="multiple">' . $return . '</select>';
			}
			else if ($tpl_type[0] == 'select')
			{
				$tpl = '<select id="' . $key . '" name="' . $name . '">' . $return . '</select>';
			}
			else
			{
				$tpl = $return;
			}

		break;

		default:
		break;
	}

	if (isset($vars['append']))
	{
		$tpl .= $vars['append'];
	}

	return $tpl;
}

/**
* Going through a config array and validate values, writing errors to $error. The validation method  accepts parameters separated by ':' for string and int.
* The first parameter defines the type to be used, the second the lower bound and the third the upper bound. Only the type is required.
*/
function validate_config_vars($config_vars, &$cfg_array, &$error)
{
	$type	= 0;
	$min	= 1;
	$max	= 2;

	foreach ($config_vars as $config_name => $config_definition)
	{
		if (!isset($cfg_array[$config_name]) || strpos($config_name, 'legend') !== false)
		{
			continue;
		}

		if (!isset($config_definition['validate']))
		{
			continue;
		}

		$validator = explode(':', $config_definition['validate']);

		// Validate a bit. ;) (0 = type, 1 = min, 2= max)
		switch ($validator[$type])
		{
			case 'string':
				$length = strlen($cfg_array[$config_name]);

				// the column is a VARCHAR
				$validator[$max] = (isset($validator[$max])) ? min(255, $validator[$max]) : 255;

				if (isset($validator[$min]) && $length < $validator[$min])
				{
					$error[] = sprintf(phpbb::$user->lang['SETTING_TOO_SHORT'], phpbb::$user->lang[$config_definition['lang']], $validator[$min]);
				}
				else if (isset($validator[$max]) && $length > $validator[2])
				{
					$error[] = sprintf(phpbb::$user->lang['SETTING_TOO_LONG'], phpbb::$user->lang[$config_definition['lang']], $validator[$max]);
				}
			break;

			case 'bool':
				$cfg_array[$config_name] = ($cfg_array[$config_name]) ? 1 : 0;
			break;

			case 'int':
				$cfg_array[$config_name] = (int) $cfg_array[$config_name];

				if (isset($validator[$min]) && $cfg_array[$config_name] < $validator[$min])
				{
					$error[] = sprintf(phpbb::$user->lang['SETTING_TOO_LOW'], phpbb::$user->lang[$config_definition['lang']], $validator[$min]);
				}
				else if (isset($validator[$max]) && $cfg_array[$config_name] > $validator[$max])
				{
					$error[] = sprintf(phpbb::$user->lang['SETTING_TOO_BIG'], phpbb::$user->lang[$config_definition['lang']], $validator[$max]);
				}
			break;

			// Absolute path
			case 'script_path':
				if (!$cfg_array[$config_name])
				{
					break;
				}

				$destination = str_replace('\\', '/', $cfg_array[$config_name]);

				if ($destination !== '/')
				{
					// Adjust destination path (no trailing slash)
					if (substr($destination, -1, 1) == '/')
					{
						$destination = substr($destination, 0, -1);
					}

					$destination = str_replace(array('../', './'), '', $destination);

					if ($destination[0] != '/')
					{
						$destination = '/' . $destination;
					}
				}

				$cfg_array[$config_name] = trim($destination);

			break;

			// Absolute path
			case 'lang':
				if (!$cfg_array[$config_name])
				{
					break;
				}

				$cfg_array[$config_name] = basename($cfg_array[$config_name]);

				if (!file_exists(PHPBB_ROOT_PATH . 'language/' . $cfg_array[$config_name] . '/'))
				{
					$error[] = phpbb::$user->lang['WRONG_DATA_LANG'];
				}
			break;

			// Relative path (appended PHPBB_ROOT_PATH)
			case 'rpath':
			case 'rwpath':
				if (!$cfg_array[$config_name])
				{
					break;
				}

				$destination = $cfg_array[$config_name];

				// Adjust destination path (no trailing slash)
				if (substr($destination, -1, 1) == '/' || substr($destination, -1, 1) == '\\')
				{
					$destination = substr($destination, 0, -1);
				}

				$destination = str_replace(array('../', '..\\', './', '.\\'), '', $destination);
				if ($destination && ($destination[0] == '/' || $destination[0] == "\\"))
				{
					$destination = '';
				}

				$cfg_array[$config_name] = trim($destination);

			// Path being relative (still prefixed by phpbb_root_path), but with the ability to escape the root dir...
			case 'path':
			case 'wpath':

				if (!$cfg_array[$config_name])
				{
					break;
				}

				$cfg_array[$config_name] = trim($cfg_array[$config_name]);

				// Make sure no NUL byte is present...
				if (strpos($cfg_array[$config_name], "\0") !== false || strpos($cfg_array[$config_name], '%00') !== false)
				{
					$cfg_array[$config_name] = '';
					break;
				}

				if (!file_exists(PHPBB_ROOT_PATH . $cfg_array[$config_name]))
				{
					$error[] = sprintf(phpbb::$user->lang['DIRECTORY_DOES_NOT_EXIST'], $cfg_array[$config_name]);
				}

				if (file_exists(PHPBB_ROOT_PATH . $cfg_array[$config_name]) && !is_dir(PHPBB_ROOT_PATH . $cfg_array[$config_name]))
				{
					$error[] = sprintf(phpbb::$user->lang['DIRECTORY_NOT_DIR'], $cfg_array[$config_name]);
				}

				// Check if the path is writable
				if ($config_definition['validate'] == 'wpath' || $config_definition['validate'] == 'rwpath')
				{
					if (file_exists(PHPBB_ROOT_PATH . $cfg_array[$config_name]) && !@is_writable(PHPBB_ROOT_PATH . $cfg_array[$config_name]))
					{
						$error[] = sprintf(phpbb::$user->lang['DIRECTORY_NOT_WRITABLE'], $cfg_array[$config_name]);
					}
				}

			break;
		}
	}

	return;
}

/**
* Checks whatever or not a variable is OK for use in the Database
* param mixed $value_ary An array of the form array(array('lang' => ..., 'value' => ..., 'column_type' =>))'
* param mixed $error The error array
*/
function validate_range($value_ary, &$error)
{
	$column_types = array(
		'BOOL'	=> array('php_type' => 'int', 		'min' => 0, 				'max' => 1),
		'USINT'	=> array('php_type' => 'int',		'min' => 0, 				'max' => 65535),
		'UINT'	=> array('php_type' => 'int', 		'min' => 0, 				'max' => (int) 0x7fffffff),
		'INT'	=> array('php_type' => 'int', 		'min' => (int) 0x80000000, 	'max' => (int) 0x7fffffff),
		'TINT'	=> array('php_type' => 'int',		'min' => -128,				'max' => 127),

		'VCHAR'	=> array('php_type' => 'string', 	'min' => 0, 				'max' => 255),
	);
	foreach ($value_ary as $value)
	{
		$column = explode(':', $value['column_type']);
		$max = $min = 0;
		$type = 0;
		if (!isset($column_types[$column[0]]))
		{
			continue;
		}
		else
		{
			$type = $column_types[$column[0]];
		}

		switch ($type['php_type'])
		{
			case 'string' :
				$max = (isset($column[1])) ? min($column[1],$type['max']) : $type['max'];
				if (strlen($value['value']) > $max)
				{
					$error[] = sprintf(phpbb::$user->lang['SETTING_TOO_LONG'], phpbb::$user->lang[$value['lang']], $max);
				}
			break;

			case 'int':
				$min = (isset($column[1])) ? max($column[1],$type['min']) : $type['min'];
				$max = (isset($column[2])) ? min($column[2],$type['max']) : $type['max'];
				if ($value['value'] < $min)
				{
					$error[] = sprintf(phpbb::$user->lang['SETTING_TOO_LOW'], phpbb::$user->lang[$value['lang']], $min);
				}
				else if ($value['value'] > $max)
				{
					$error[] = sprintf(phpbb::$user->lang['SETTING_TOO_BIG'], phpbb::$user->lang[$value['lang']], $max);
				}
			break;
		}
	}
}

?>