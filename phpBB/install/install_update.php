<?php
/**
*
* @package install
* @version $Id$
* @copyright (c) 2006 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
* @todo check for writable cache/store/files directory
*/

/**
*/
if (!defined('IN_INSTALL'))
{
	// Someone has tried to access the file directly. This is not a good idea, so exit
	exit;
}

if (!empty($setmodules))
{
	// If phpBB is not installed we do not include this module
	if (@file_exists(PHPBB_ROOT_PATH . 'config.' . PHP_EXT) && !@file_exists(PHPBB_ROOT_PATH . 'cache/install_lock'))
	{
		include_once(PHPBB_ROOT_PATH . 'config.' . PHP_EXT);

		if (!phpbb::$base_config['installed'])
		{
			return;
		}
	}
	else
	{
		return;
	}

	$module[] = array(
		'module_type'		=> 'update',
		'module_title'		=> 'UPDATE',
		'module_filename'	=> substr(basename(__FILE__), 0, -strlen(PHP_EXT)-1),
		'module_order'		=> 30,
		'module_subs'		=> '',
		'module_stages'		=> array('INTRO', 'VERSION_CHECK', 'UPDATE_DB', 'FILE_CHECK', 'UPDATE_FILES'),
		'module_reqs'		=> ''
	);
}

/**
* Update Installation
* @package install
*/
class install_update extends module
{
	var $p_master;
	var $update_info;

	var $old_location;
	var $new_location;
	var $latest_version;
	var $current_version;
	var $unequal_version;

	// Set to false
	var $test_update = false;

	function install_update(&$p_master)
	{
		$this->p_master = &$p_master;
	}

	function main($mode, $sub)
	{
		require PHPBB_ROOT_PATH . 'common.' . PHP_EXT;

		$this->tpl_name = 'install_update';
		$this->page_title = 'UPDATE_INSTALLATION';
		$this->unequal_version = false;

		$this->old_location = PHPBB_ROOT_PATH . 'install/update/old/';
		$this->new_location = PHPBB_ROOT_PATH . 'install/update/new/';

		// Force template recompile
		phpbb::$config['load_tplcompile'] = 1;

		// Start session management
		phpbb::$user->session_begin();
		phpbb::$acl->init(phpbb::$user->data);
		phpbb::$user->setup('viewforum');

		// If we are within the intro page we need to make sure we get up-to-date version info
		if ($sub == 'intro')
		{
			phpbb::$acm->destroy('version_info');
		}

		// Set custom template again. ;)
		phpbb::$template->set_custom_template('../adm/style', 'admin');

		// Get current and latest version
		if (($latest_version = phpbb::$acm->get('version_info')) === false)
		{
			$this->latest_version = $this->get_file('version_info');
			phpbb::$acm->put('version_info', $this->latest_version);
		}
		else
		{
			$this->latest_version = $latest_version;
		}

		// For the current version we trick a bit. ;)
		$this->current_version = (!empty(phpbb::$config['version_update_from'])) ? phpbb::$config['version_update_from'] : phpbb::$config['version'];

		$up_to_date = (version_compare(str_replace('rc', 'RC', strtolower($this->current_version)), str_replace('rc', 'RC', strtolower($this->latest_version)), '<')) ? false : true;

		// Check for a valid update directory, else point the user to the phpbb.com website
		if (!file_exists(PHPBB_ROOT_PATH . 'install/update') || !file_exists(PHPBB_ROOT_PATH . 'install/update/index.php') || !file_exists($this->old_location) || !file_exists($this->new_location))
		{
			phpbb::$template->assign_vars(array(
				'S_ERROR'		=> true,
				'ERROR_MSG'		=> ($up_to_date) ? phpbb::$user->lang['NO_UPDATE_FILES_UP_TO_DATE'] : sprintf(phpbb::$user->lang['NO_UPDATE_FILES_OUTDATED'], phpbb::$config['version'], $this->current_version, $this->latest_version),
			));

			return;
		}

		$this->update_info = $this->get_file('update_info');

		// Make sure the update directory holds the correct information
		// Since admins are able to run the update/checks more than once we only check if the current version is lower or equal than the version to which we update to.
		if (version_compare(str_replace('rc', 'RC', strtolower($this->current_version)), str_replace('rc', 'RC', strtolower($this->update_info['version']['to'])), '>'))
		{
			phpbb::$template->assign_vars(array(
				'S_ERROR'		=> true,
				'ERROR_MSG'		=> sprintf(phpbb::$user->lang['INCOMPATIBLE_UPDATE_FILES'], phpbb::$config['version'], $this->update_info['version']['from'], $this->update_info['version']['to']),
			));

			return;
		}

		// Check if the update files stored are for the latest version...
		if ($this->latest_version != $this->update_info['version']['to'])
		{
			$this->unequal_version = true;

			phpbb::$template->assign_vars(array(
				'S_WARNING'		=> true,
				'WARNING_MSG'	=> sprintf(phpbb::$user->lang['OLD_UPDATE_FILES'], $this->update_info['version']['from'], $this->update_info['version']['to'], $this->latest_version),
			));
		}

		// Fill DB version
		if (empty(phpbb::$config['dbms_version']))
		{
			set_config('dbms_version', phpbb::$db->sql_server_info(true));
		}

		if ($this->test_update === false)
		{
			// Got the updater template itself updated? If so, we are able to directly use it - but only if all three files are present
			if (in_array('adm/style/install_update.html', $this->update_info['files']))
			{
				$this->tpl_name = '../../install/update/new/adm/style/install_update';
			}

			// What about the language file? Got it updated?
			if (in_array('language/en/install.php', $this->update_info['files']))
			{
				$lang = array();
				include($this->new_location . 'language/en/install.php');
				// only add new keys to user's language in english
				$new_keys = array_diff(array_keys($lang), array_keys(phpbb::$user->lang));
				foreach ($new_keys as $i => $new_key)
				{
					phpbb::$user->lang[$new_key] = $lang[$new_key];
				}
			}
		}

		// Include renderer and engine
		$this->include_file('includes/diff/diff.' . PHP_EXT);
		$this->include_file('includes/diff/engine.' . PHP_EXT);
		$this->include_file('includes/diff/renderer.' . PHP_EXT);

		// Make sure we stay at the file check if checking the files again
		if (phpbb_request::variable('clean_up', false, false, phpbb_request::POST))
		{
			$sub = $this->p_master->sub = 'file_check';
		}

		switch ($sub)
		{
			case 'intro':
				$this->page_title = 'UPDATE_INSTALLATION';

				phpbb::$template->assign_vars(array(
					'S_INTRO'		=> true,
					'U_ACTION'		=> append_sid($this->p_master->module_url, "mode=$mode&amp;sub=version_check"),
				));

				// Make sure the update list is destroyed.
				phpbb::$acm->destroy('update_list');
				phpbb::$acm->destroy('diff_files');
			break;

			case 'version_check':
				$this->page_title = 'STAGE_VERSION_CHECK';

				phpbb::$template->assign_vars(array(
					'S_UP_TO_DATE'		=> $up_to_date,
					'S_VERSION_CHECK'	=> true,

					'U_ACTION'				=> append_sid($this->p_master->module_url, "mode=$mode&amp;sub=file_check"),
					'U_DB_UPDATE_ACTION'	=> append_sid($this->p_master->module_url, "mode=$mode&amp;sub=update_db"),

					'LATEST_VERSION'	=> $this->latest_version,
					'CURRENT_VERSION'	=> $this->current_version,
				));

				// Print out version the update package updates to
				if ($this->unequal_version)
				{
					phpbb::$template->assign_var('PACKAGE_VERSION', $this->update_info['version']['to']);
				}

			break;

			case 'update_db':

				// Make sure the database update is valid for the latest version
				$valid = false;
				$updates_to_version = '';

				if (file_exists(PHPBB_ROOT_PATH . 'install/database_update.' . PHP_EXT))
				{
					include_once(PHPBB_ROOT_PATH . 'install/database_update.' . PHP_EXT);

					if ($updates_to_version === $this->update_info['version']['to'])
					{
						$valid = true;
					}
				}

				// Should not happen at all
				if (!$valid)
				{
					trigger_error(phpbb::$user->lang['DATABASE_UPDATE_INFO_OLD'], E_USER_ERROR);
				}

				// Just a precaution
				phpbb::$acm->purge();

				// Redirect the user to the database update script with some explanations...
				phpbb::$template->assign_vars(array(
					'S_DB_UPDATE'			=> true,
					'S_DB_UPDATE_FINISHED'	=> (phpbb::$config['version'] == $this->update_info['version']['to']) ? true : false,
					'U_DB_UPDATE'			=> append_sid('install/database_update', 'type=1&amp;language=' . phpbb::$user->data['user_lang']),
					'U_DB_UPDATE_ACTION'	=> append_sid($this->p_master->module_url, "mode=$mode&amp;sub=update_db"),
					'U_ACTION'				=> append_sid($this->p_master->module_url, "mode=$mode&amp;sub=file_check"),
				));

			break;

			case 'file_check':

				// Make sure the previous file collection is no longer valid...
				phpbb::$acm->destroy('diff_files');

				$this->page_title = 'STAGE_FILE_CHECK';

				// Now make sure our update list is correct if the admin refreshes
				$action = request_var('action', '');

				// We are directly within an update. To make sure our update list is correct we check its status.
				$update_list = (phpbb_request::variable('clean_up', false, false, phpbb_request::POST)) ? false : phpbb::$acm->get('update_list');
				$modified = ($update_list !== false) ? phpbb::$acm->get_modified_date('data', 'update_list') : 0;

				// Make sure the list is up-to-date
				if ($update_list !== false)
				{
					$get_new_list = false;
					foreach ($this->update_info['files'] as $file)
					{
						if (file_exists(PHPBB_ROOT_PATH . $file) && filemtime(PHPBB_ROOT_PATH . $file) > $modified)
						{
							$get_new_list = true;
							break;
						}
					}
				}
				else
				{
					$get_new_list = true;
				}

				if (!$get_new_list && $update_list['status'] != -1)
				{
					$get_new_list = true;
				}

				if ($get_new_list)
				{
					$this->get_update_structure($update_list);
					phpbb::$acm->put('update_list', $update_list);

					// Refresh the page if we are still not finished...
					if ($update_list['status'] != -1)
					{
						$refresh_url = append_sid($this->p_master->module_url, "mode=$mode&amp;sub=file_check");
						meta_refresh(2, $refresh_url);

						phpbb::$template->assign_vars(array(
							'S_IN_PROGRESS'		=> true,
							'S_COLLECTED'		=> (int) $update_list['status'],
							'S_TO_COLLECT'		=> sizeof($this->update_info['files']),
							'L_IN_PROGRESS'				=> phpbb::$user->lang['COLLECTING_FILE_DIFFS'],
							'L_IN_PROGRESS_EXPLAIN'		=> sprintf(phpbb::$user->lang['NUMBER_OF_FILES_COLLECTED'], (int) $update_list['status'], sizeof($this->update_info['files'])),
						));

						return;
					}
				}

				if ($action == 'diff')
				{
					$this->show_diff($update_list);
					return;
				}

				if (sizeof($update_list['no_update']))
				{
					phpbb::$template->assign_vars(array(
						'S_NO_UPDATE_FILES'		=> true,
						'NO_UPDATE_FILES'		=> implode(', ', array_map('htmlspecialchars', $update_list['no_update'])),
					));
				}

				// Now assign the list to the template
				foreach ($update_list as $status => $filelist)
				{
					if ($status == 'no_update' || !sizeof($filelist) || $status == 'status')
					{
						continue;
					}

/*					phpbb::$template->assign_block_vars('files', array(
						'S_STATUS'		=> true,
						'STATUS'		=> $status,
						'L_STATUS'		=> phpbb::$user->lang['STATUS_' . strtoupper($status)],
						'TITLE'			=> phpbb::$user->lang['FILES_' . strtoupper($status)],
						'EXPLAIN'		=> phpbb::$user->lang['FILES_' . strtoupper($status) . '_EXPLAIN'],
						)
					);*/

					foreach ($filelist as $file_struct)
					{
						$s_binary = (!empty($this->update_info['binary']) && in_array($file_struct['filename'], $this->update_info['binary'])) ? true : false;

						$filename = htmlspecialchars($file_struct['filename']);
						if (strrpos($filename, '/') !== false)
						{
							$dir_part = substr($filename, 0, strrpos($filename, '/') + 1);
							$file_part = substr($filename, strrpos($filename, '/') + 1);
						}
						else
						{
							$dir_part = '';
							$file_part = $filename;
						}

						$diff_url = append_sid($this->p_master->module_url, "mode=$mode&amp;sub=file_check&amp;action=diff&amp;status=$status&amp;file=" . urlencode($file_struct['filename']));

						phpbb::$template->assign_block_vars($status, array(
							'STATUS'			=> $status,

							'FILENAME'			=> $filename,
							'DIR_PART'			=> $dir_part,
							'FILE_PART'			=> $file_part,
							'NUM_CONFLICTS'		=> (isset($file_struct['conflicts'])) ? $file_struct['conflicts'] : 0,

							'S_CUSTOM'			=> ($file_struct['custom']) ? true : false,
							'S_BINARY'			=> $s_binary,
							'CUSTOM_ORIGINAL'	=> ($file_struct['custom']) ? $file_struct['original'] : '',

							'U_SHOW_DIFF'		=> $diff_url,
							'L_SHOW_DIFF'		=> ($status != 'up_to_date') ? phpbb::$user->lang['SHOW_DIFF_' . strtoupper($status)] : '',

							'U_VIEW_MOD_FILE'		=> $diff_url . '&amp;op=' . MERGE_MOD_FILE,
							'U_VIEW_NEW_FILE'		=> $diff_url . '&amp;op=' . MERGE_NEW_FILE,
							'U_VIEW_NO_MERGE_MOD'	=> $diff_url . '&amp;op=' . MERGE_NO_MERGE_MOD,
							'U_VIEW_NO_MERGE_NEW'	=> $diff_url . '&amp;op=' . MERGE_NO_MERGE_NEW,
						));
					}
				}

				$all_up_to_date = true;
				foreach ($update_list as $status => $filelist)
				{
					if ($status != 'up_to_date' && $status != 'custom' && $status != 'status' && sizeof($filelist))
					{
						$all_up_to_date = false;
						break;
					}
				}

				phpbb::$template->assign_vars(array(
					'S_FILE_CHECK'			=> true,
					'S_ALL_UP_TO_DATE'		=> $all_up_to_date,
					'S_VERSION_UP_TO_DATE'	=> $up_to_date,
					'U_ACTION'				=> append_sid($this->p_master->module_url, "mode=$mode&amp;sub=file_check"),
					'U_UPDATE_ACTION'		=> append_sid($this->p_master->module_url, "mode=$mode&amp;sub=update_files"),
					'U_DB_UPDATE_ACTION'	=> append_sid($this->p_master->module_url, "mode=$mode&amp;sub=update_db"),
				));

				if ($all_up_to_date)
				{
					// Add database update to log
					add_log('admin', 'LOG_UPDATE_PHPBB', $this->current_version, $this->latest_version);

					// Refresh prosilver css data - this may cause some unhappy users, but
					$sql = 'SELECT *
						FROM ' . STYLES_THEME_TABLE . "
						WHERE theme_name = 'prosilver'";
					$result = phpbb::$db->sql_query($sql);
					$theme = phpbb::$db->sql_fetchrow($result);
					phpbb::$db->sql_freeresult($result);

					if ($theme)
					{
						$recache = (empty($theme['theme_data'])) ? true : false;
						$update_time = time();

						// We test for stylesheet.css because it is faster and most likely the only file changed on common themes
						if (!$recache && $theme['theme_mtime'] < @filemtime(PHPBB_ROOT_PATH . 'styles/' . $theme['theme_path'] . '/theme/stylesheet.css'))
						{
							$recache = true;
							$update_time = @filemtime(PHPBB_ROOT_PATH . 'styles/' . $theme['theme_path'] . '/theme/stylesheet.css');
						}
						else if (!$recache)
						{
							$last_change = $theme['theme_mtime'];
							$dir = @opendir(PHPBB_ROOT_PATH . "styles/{$theme['theme_path']}/theme");

							if ($dir)
							{
								while (($entry = readdir($dir)) !== false)
								{
									if (substr(strrchr($entry, '.'), 1) == 'css' && $last_change < @filemtime(PHPBB_ROOT_PATH . "styles/{$theme['theme_path']}/theme/{$entry}"))
									{
										$recache = true;
										break;
									}
								}
								closedir($dir);
							}
						}

						if ($recache)
						{
							include_once(PHPBB_ROOT_PATH . 'includes/acp/acp_styles.' . PHP_EXT);

							$theme['theme_data'] = acp_styles::db_theme_data($theme);
							$theme['theme_mtime'] = $update_time;

							// Save CSS contents
							$sql_ary = array(
								'theme_mtime'	=> $theme['theme_mtime'],
								'theme_data'	=> $theme['theme_data']
							);

							$sql = 'UPDATE ' . STYLES_THEME_TABLE . ' SET ' . phpbb::$db->sql_build_array('UPDATE', $sql_ary) . '
								WHERE theme_id = ' . $theme['theme_id'];
							phpbb::$db->sql_query($sql);

							phpbb::$acm->destroy_sql(STYLES_THEME_TABLE);
						}
					}

					phpbb::$db->sql_return_on_error(true);
					phpbb::$db->sql_query('DELETE FROM ' . CONFIG_TABLE . " WHERE config_name = 'version_update_from'");
					phpbb::$db->sql_return_on_error(false);

					phpbb::$acm->purge();
				}

			break;

			case 'update_files':

				$this->page_title = 'STAGE_UPDATE_FILES';

				$s_hidden_fields = '';
				$params = array();
				$conflicts = request_var('conflict', array('' => 0));
				$modified = request_var('modified', array('' => 0));

				foreach ($conflicts as $filename => $merge_option)
				{
					$s_hidden_fields .= '<input type="hidden" name="conflict[' . htmlspecialchars($filename) . ']" value="' . $merge_option . '" />';
					$params[] = 'conflict[' . urlencode($filename) . ']=' . urlencode($merge_option);
				}

				foreach ($modified as $filename => $merge_option)
				{
					if (!$merge_option)
					{
						continue;
					}
					$s_hidden_fields .= '<input type="hidden" name="modified[' . htmlspecialchars($filename) . ']" value="' . $merge_option . '" />';
					$params[] = 'modified[' . urlencode($filename) . ']=' . urlencode($merge_option);
				}

				$no_update = request_var('no_update', array(0 => ''));

				foreach ($no_update as $index => $filename)
				{
					$s_hidden_fields .= '<input type="hidden" name="no_update[]" value="' . htmlspecialchars($filename) . '" />';
					$params[] = 'no_update[]=' . urlencode($filename);
				}

				// Before the user is choosing his preferred method, let's create the content list...
				$update_list = phpbb::$acm->get('update_list');

				if ($update_list === false)
				{
					trigger_error(phpbb::$user->lang['NO_UPDATE_INFO'], E_USER_ERROR);
				}

				// Check if the conflicts data is valid
				if (sizeof($conflicts))
				{
					$conflict_filenames = array();
					foreach ($update_list['conflict'] as $files)
					{
						$conflict_filenames[] = $files['filename'];
					}

					$new_conflicts = array();
					foreach ($conflicts as $filename => $diff_method)
					{
						if (in_array($filename, $conflict_filenames))
						{
							$new_conflicts[$filename] = $diff_method;
						}
					}

					$conflicts = $new_conflicts;
				}

				// Build list for modifications
				if (sizeof($modified))
				{
					$modified_filenames = array();
					foreach ($update_list['modified'] as $files)
					{
						$modified_filenames[] = $files['filename'];
					}

					$new_modified = array();
					foreach ($modified as $filename => $diff_method)
					{
						if (in_array($filename, $modified_filenames))
						{
							$new_modified[$filename] = $diff_method;
						}
					}

					$modified = $new_modified;
				}

				// Check number of conflicting files, they need to be equal. For modified files the number can differ
				if (sizeof($update_list['conflict']) != sizeof($conflicts))
				{
					trigger_error(phpbb::$user->lang['MERGE_SELECT_ERROR'], E_USER_ERROR);
				}

				// Before we do anything, let us diff the files and store the raw file information "somewhere"
				$get_files = false;
				$file_list = phpbb::$acm->get('diff_files');

				if ($file_list === false || $file_list['status'] != -1)
				{
					$get_files = true;
				}

				if ($get_files)
				{
					if ($file_list === false)
					{
						$file_list = array(
							'status'	=> 0,
						);
					}

					$processed = 0;
					foreach ($update_list as $status => $files)
					{
						if (!is_array($files))
						{
							continue;
						}

						foreach ($files as $file_struct)
						{
							// Skip this file if the user selected to not update it
							if (in_array($file_struct['filename'], $no_update))
							{
								continue;
							}

							// Already handled... then skip of course...
							if (isset($file_list[$file_struct['filename']]))
							{
								continue;
							}

							// Refresh if we reach 5 diffs...
							if ($processed >= 5)
							{
								phpbb::$acm->put('diff_files', $file_list);

								if (request_var('download', false))
								{
									$params[] = 'download=1';
								}

								$redirect_url = append_sid($this->p_master->module_url, "mode=$mode&amp;sub=update_files&amp;" . implode('&amp;', $params));
								meta_refresh(3, $redirect_url);

								phpbb::$template->assign_vars(array(
									'S_IN_PROGRESS'			=> true,
									'L_IN_PROGRESS'			=> phpbb::$user->lang['MERGING_FILES'],
									'L_IN_PROGRESS_EXPLAIN'	=> phpbb::$user->lang['MERGING_FILES_EXPLAIN'],
								));

								return;
							}

							$original_filename = ($file_struct['custom']) ? $file_struct['original'] : $file_struct['filename'];

							switch ($status)
							{
								case 'modified':

									$option = (isset($modified[$file_struct['filename']])) ? $modified[$file_struct['filename']] : 0;

									switch ($option)
									{
										case MERGE_NO_MERGE_NEW:
											$contents = file_get_contents($this->new_location . $original_filename);
										break;

										case MERGE_NO_MERGE_MOD:
											$contents = file_get_contents(PHPBB_ROOT_PATH . $file_struct['filename']);
										break;

										default:
											$diff = $this->return_diff($this->old_location . $original_filename, PHPBB_ROOT_PATH . $file_struct['filename'], $this->new_location . $original_filename);

											$contents = implode("\n", $diff->merged_new_output());
											unset($diff);
										break;
									}

									$file_list[$file_struct['filename']] = 'file_' . md5($file_struct['filename']);
									phpbb::$acm->put($file_list[$file_struct['filename']], base64_encode($contents));

									$file_list['status']++;
									$processed++;

								break;

								case 'conflict':

									$option = $conflicts[$file_struct['filename']];
									$contents = '';

									switch ($option)
									{
										case MERGE_NO_MERGE_NEW:
											$contents = file_get_contents($this->new_location . $original_filename);
										break;

										case MERGE_NO_MERGE_MOD:
											$contents = file_get_contents(PHPBB_ROOT_PATH . $file_struct['filename']);
										break;

										default:

											$diff = $this->return_diff($this->old_location . $original_filename, PHPBB_ROOT_PATH . $file_struct['filename'], $this->new_location . $original_filename);

											if ($option == MERGE_NEW_FILE)
											{
												$contents = implode("\n", $diff->merged_new_output());
											}
											else if ($option == MERGE_MOD_FILE)
											{
												$contents = implode("\n", $diff->merged_orig_output());
											}
											else
											{
												unset($diff);
												break 2;
											}

											unset($diff);
										break;
									}

									$file_list[$file_struct['filename']] = 'file_' . md5($file_struct['filename']);
									phpbb::$acm->put($file_list[$file_struct['filename']], base64_encode($contents));

									$file_list['status']++;
									$processed++;

								break;
							}
						}
					}
				}

				$file_list['status'] = -1;
				phpbb::$acm->put('diff_files', $file_list);

				$this->include_file('includes/functions_compress.' . $phpEx);
				$this->include_file('includes/functions_transfer.' . $phpEx);

				$module_url = append_sid($this->p_master->module_url, "mode=$mode&amp;sub=update_files");
				foreach ($update_list as &$files)
				{
					if (!is_array($files))
					{
						continue;
					}
					for ($i = 0, $size = sizeof($files); $i < $size; $i++)
					{
						// Skip this file if the user selected to not update it
						if (in_array($files[$i]['filename'], $no_update))
						{
							unset($files[$i]['filename']);
						}
					}
					$files = array_values($files);
				}
				unset($files);
				$new_location = $this->new_location;
				$download_filename = 'update_' . $this->update_info['version']['from'] . '_to_' . $this->update_info['version']['to'];
				$check_params = "mode=$mode&amp;sub=file_check";

				$temp = process_transfer($module_url, $update_list, $new_location, $download_filename);
				if (is_string($temp))
				{
					$this->page_title = $temp;
				}

				phpbb::$template->assign_vars(array(
					'S_UPDATE_OPTIONS' => true,
					'S_CHECK_AGAIN' => true,
					// 'U_INITIAL_ACTION' isn't set because it's taken care of in the S_FILE_CHECK block of install_update.html
					'U_FINAL_ACTION' => append_sid($this->p_master->module_url, "mode=$mode&amp;sub=update_files"))
				);

			break;

		}
	}

	/**
	* Show file diff
	*/
	function show_diff(&$update_list)
	{
		$this->tpl_name = 'install_update_diff';

		// Got the diff template itself updated? If so, we are able to directly use it
		if (in_array('adm/style/install_update_diff.html', $this->update_info['files']))
		{
			$this->tpl_name = '../../install/update/new/adm/style/install_update_diff';
		}

		$this->page_title = 'VIEWING_FILE_DIFF';

		$status = request_var('status', '');
		$file = request_var('file', '');
		$diff_mode = request_var('diff_mode', 'inline');

		// First of all make sure the file is within our file update list with the correct status
		$found_entry = array();
		foreach ($update_list[$status] as $index => $file_struct)
		{
			if ($file_struct['filename'] === $file)
			{
				$found_entry = $update_list[$status][$index];
			}
		}

		if (empty($found_entry))
		{
			trigger_error(phpbb::$user->lang['FILE_DIFF_NOT_ALLOWED'], E_USER_ERROR);
		}

		// If the status is 'up_to_date' then we do not need to show a diff
		if ($status == 'up_to_date')
		{
			trigger_error(phpbb::$user->lang['FILE_ALREADY_UP_TO_DATE'], E_USER_ERROR);
		}

		$original_file = ($found_entry['custom']) ? $found_entry['original'] : $file;

		// Get the correct diff
		switch ($status)
		{
			case 'conflict':
				$option = request_var('op', 0);

				switch ($option)
				{
					case MERGE_NO_MERGE_NEW:
					case MERGE_NO_MERGE_MOD:

						$diff = $this->return_diff(array(), ($option == MERGE_NO_MERGE_NEW) ? $this->new_location . $original_file : PHPBB_ROOT_PATH . $file);

						phpbb::$template->assign_var('S_DIFF_NEW_FILE', true);
						$diff_mode = 'inline';
						$this->page_title = 'VIEWING_FILE_CONTENTS';

					break;

/*
						$diff = $this->return_diff($this->old_location . $original_file, PHPBB_ROOT_PATH . $file, $this->new_location . $original_file);

						$tmp = array(
							'file1'		=> array(),
							'file2'		=> ($option == MERGE_NEW_FILE) ? implode("\n", $diff->merged_new_output()) : implode("\n", $diff->merged_orig_output()),
						);

						$diff = new diff($tmp['file1'], $tmp['file2']);

						unset($tmp);

						phpbb::$template->assign_var('S_DIFF_NEW_FILE', true);
						$diff_mode = 'inline';
						$this->page_title = 'VIEWING_FILE_CONTENTS';

					break;
*/
					// Merge differences and use new phpBB code for conflicted blocks
					case MERGE_NEW_FILE:
					case MERGE_MOD_FILE:

						$diff = $this->return_diff($this->old_location . $original_file, PHPBB_ROOT_PATH . $file, $this->new_location . $original_file);

						phpbb::$template->assign_vars(array(
							'S_DIFF_CONFLICT_FILE'	=> true,
							'NUM_CONFLICTS'			=> $diff->get_num_conflicts(),
						));

						$diff = $this->return_diff(PHPBB_ROOT_PATH . $file, ($option == MERGE_NEW_FILE) ? $diff->merged_new_output() : $diff->merged_orig_output());
					break;

					// Download conflict file
					default:

						$diff = $this->return_diff($this->old_location . $original_file, $phpbb_root_path . $file, $this->new_location . $original_file);

						header('Pragma: no-cache');
						header("Content-Type: application/octetstream; name=\"$file\"");
						header("Content-disposition: attachment; filename=$file");

						@set_time_limit(0);

						echo implode("\n", $diff->get_conflicts_content());

						flush();
						exit;

					break;
				}

			break;

			case 'modified':
				$option = request_var('op', 0);

				switch ($option)
				{
					case MERGE_NO_MERGE_NEW:
					case MERGE_NO_MERGE_MOD:

						$diff = $this->return_diff(array(), ($option == MERGE_NO_MERGE_NEW) ? $this->new_location . $original_file : PHPBB_ROOT_PATH . $file);

						phpbb::$template->assign_var('S_DIFF_NEW_FILE', true);
						$diff_mode = 'inline';
						$this->page_title = 'VIEWING_FILE_CONTENTS';

					break;

					default:
						$diff = $this->return_diff($this->old_location . $original_file, PHPBB_ROOT_PATH . $original_file, $this->new_location . $file);
					break;
				}
			break;

			case 'not_modified':
			case 'new_conflict':
				$diff = $this->return_diff(PHPBB_ROOT_PATH . $file, $this->new_location . $original_file);
			break;

			case 'new':

				$diff = $this->return_diff(array(), $this->new_location . $original_file);

				phpbb::$template->assign_var('S_DIFF_NEW_FILE', true);
				$diff_mode = 'inline';
				$this->page_title = 'VIEWING_FILE_CONTENTS';

			break;
		}

		$diff_mode_options = '';
		foreach (array('side_by_side', 'inline', 'unified', 'raw') as $option)
		{
			$diff_mode_options .= '<option value="' . $option . '"' . (($diff_mode == $option) ? ' selected="selected"' : '') . '>' . phpbb::$user->lang['DIFF_' . strtoupper($option)] . '</option>';
		}

		// Now the correct renderer
		$render_class = 'diff_renderer_' . $diff_mode;

		if (!class_exists($render_class))
		{
			trigger_error('Chosen diff mode is not supported', E_USER_ERROR);
		}

		$renderer = new $render_class();

		phpbb::$template->assign_vars(array(
			'DIFF_CONTENT'			=> $renderer->get_diff_content($diff),
			'DIFF_MODE'				=> $diff_mode,
			'S_DIFF_MODE_OPTIONS'	=> $diff_mode_options,
			'S_SHOW_DIFF'			=> true,
		));

		unset($diff, $renderer);
	}

	/**
	* Collect all file status infos we need for the update by diffing all files
	*/
	function get_update_structure(&$update_list)
	{
		if ($update_list === false)
		{
			$update_list = array(
				'up_to_date'	=> array(),
				'new'			=> array(),
				'not_modified'	=> array(),
				'modified'		=> array(),
				'new_conflict'	=> array(),
				'conflict'		=> array(),
				'no_update'		=> array(),
				'status'		=> 0,
			);
		}

		/* if (!empty($this->update_info['custom']))
		{
			foreach ($this->update_info['custom'] as $original_file => $file_ary)
			{
				foreach ($file_ary as $index => $file)
				{
					$this->make_update_diff($update_list, $original_file, $file, true);
				}
			}
		} */

		// Get a list of those files which are completely new by checking with file_exists...
		$num_bytes_processed = 0;

		foreach ($this->update_info['files'] as $index => $file)
		{
			if (is_int($update_list['status']) && $index < $update_list['status'])
			{
				continue;
			}

			if ($num_bytes_processed >= 500 * 1024)
			{
				return;
			}

			if (!file_exists(PHPBB_ROOT_PATH . $file))
			{
				// Make sure the update files are consistent by checking if the file is in new_files...
				if (!file_exists($this->new_location . $file))
				{
					trigger_error(phpbb::$user->lang['INCOMPLETE_UPDATE_FILES'], E_USER_ERROR);
				}

				// If the file exists within the old directory the file got removed and we will write it back
				// not a biggie, but we might want to state this circumstance separately later.
				//	if (file_exists($this->old_location . $file))
				//	{
				//		$update_list['removed'][] = $file;
				//	}

				/* Only include a new file as new if the underlying path exist
				// The path normally do not exist if the original style or language has been removed
				if (file_exists(PHPBB_ROOT_PATH . dirname($file)))
				{
					$this->get_custom_info($update_list['new'], $file);
					$update_list['new'][] = array('filename' => $file, 'custom' => false);
				}
				else
				{
					// Do not include style-related or language-related content
					if (strpos($file, 'styles/') !== 0 && strpos($file, 'language/') !== 0)
					{
						$update_list['no_update'][] = $file;
					}
				}*/

				if (file_exists(PHPBB_ROOT_PATH . dirname($file)) || (strpos($file, 'styles/') !== 0 && strpos($file, 'language/') !== 0))
				{
					$this->get_custom_info($update_list['new'], $file);
					$update_list['new'][] = array('filename' => $file, 'custom' => false);
				}

				// unset($this->update_info['files'][$index]);
			}
			else
			{
				// not modified?
				$this->make_update_diff($update_list, $file, $file);
			}

			$num_bytes_processed += (file_exists($this->new_location . $file)) ? filesize($this->new_location . $file) : 100 * 1024;
			$update_list['status']++;
		}

		$update_list['status'] = -1;
/*		if (!sizeof($this->update_info['files']))
		{
			return $update_list;
		}

		// Now diff the remaining files to get information about their status (not modified/modified/up-to-date)

		// not modified?
		foreach ($this->update_info['files'] as $index => $file)
		{
			$this->make_update_diff($update_list, $file, $file);
		}

		// Now to the styles...
		if (empty($this->update_info['custom']))
		{
			return $update_list;
		}

		foreach ($this->update_info['custom'] as $original_file => $file_ary)
		{
			foreach ($file_ary as $index => $file)
			{
				$this->make_update_diff($update_list, $original_file, $file, true);
			}
		}

		return $update_list;*/
	}

	/**
	* Compare files for storage in update_list
	*/
	function make_update_diff(&$update_list, $original_file, $file, $custom = false)
	{
		$update_ary = array('filename' => $file, 'custom' => $custom);

		if ($custom)
		{
			$update_ary['original'] = $original_file;
		}

		// On a successfull update the new location file exists but the old one does not exist.
		// Check for this circumstance, the new file need to be up-to-date with the current file then...
		if (!file_exists($this->old_location . $original_file) && file_exists($this->new_location . $original_file) && file_exists(PHPBB_ROOT_PATH . $file))
		{
			$tmp = array(
				'file1'		=> file_get_contents($this->new_location . $original_file),
				'file2'		=> file_get_contents(PHPBB_ROOT_PATH . $file),
			);

			// We need to diff the contents here to make sure the file is really the one we expect
			$diff = new diff($tmp['file1'], $tmp['file2'], false);
			$empty = $diff->is_empty();

			unset($tmp, $diff);

			// if there are no differences we have an up-to-date file...
			if ($empty)
			{
				$update_list['up_to_date'][] = $update_ary;
				return;
			}

			// If no other status matches we have another file in the way...
			$update_list['new_conflict'][] = $update_ary;
			return;
		}

		// Old file removed?
		if (file_exists($this->old_location . $original_file) && !file_exists($this->new_location . $original_file))
		{
			return;
		}

		// Check for existance, else abort immediately
		if (!file_exists($this->old_location . $original_file) || !file_exists($this->new_location . $original_file))
		{
			trigger_error(phpbb::$user->lang['INCOMPLETE_UPDATE_FILES'], E_USER_ERROR);
		}

		$tmp = array(
			'file1'		=> file_get_contents($this->old_location . $original_file),
			'file2'		=> file_get_contents(PHPBB_ROOT_PATH . $file),
		);

		// We need to diff the contents here to make sure the file is really the one we expect
		$diff = new diff($tmp['file1'], $tmp['file2'], false);
		$empty_1 = $diff->is_empty();

		unset($tmp, $diff);

		$tmp = array(
			'file1'		=> file_get_contents($this->new_location . $original_file),
			'file2'		=> file_get_contents(PHPBB_ROOT_PATH . $file),
		);

		// We need to diff the contents here to make sure the file is really the one we expect
		$diff = new diff($tmp['file1'], $tmp['file2'], false);
		$empty_2 = $diff->is_empty();

		unset($tmp, $diff);

		// If the file is not modified we are finished here...
		if ($empty_1)
		{
			// Further check if it is already up to date - it could happen that non-modified files
			// slip through
			if ($empty_2)
			{
				$update_list['up_to_date'][] = $update_ary;
				return;
			}

			$update_list['not_modified'][] = $update_ary;
			return;
		}

		// If the file had been modified then we need to check if it is already up to date

		// if there are no differences we have an up-to-date file...
		if ($empty_2)
		{
			$update_list['up_to_date'][] = $update_ary;
			return;
		}

		// if the file is modified we try to make sure a merge succeed
		$tmp = array(
			'file1'		=> file_get_contents($this->old_location . $original_file),
			'file2'		=> file_get_contents(PHPBB_ROOT_PATH . $file),
			'file3'		=> file_get_contents($this->new_location . $original_file),
		);

		$diff = new diff3($tmp['file1'], $tmp['file2'], $tmp['file3'], false);

		unset($tmp);

		if ($diff->get_num_conflicts())
		{
			$update_ary['conflicts'] = $diff->get_num_conflicts();

			// There is one special case... users having merged with a conflicting file... we need to check this
			$tmp = array(
				'file1'		=> file_get_contents(PHPBB_ROOT_PATH . $file),
				'file2'		=> implode("\n", $diff->merged_orig_output()),
			);

			$diff = new diff($tmp['file1'], $tmp['file2'], false);
			$empty = $diff->is_empty();

			if ($empty)
			{
				unset($update_ary['conflicts']);
				unset($diff);
				$update_list['up_to_date'][] = $update_ary;
				return;
			}

			$update_list['conflict'][] = $update_ary;
			unset($diff);

			return;
		}

		$tmp = array(
			'file1'		=> file_get_contents(PHPBB_ROOT_PATH . $file),
			'file2'		=> implode("\n", $diff->merged_new_output()),
		);

		// now compare the merged output with the original file to see if the modified file is up to date
		$diff = new diff($tmp['file1'], $tmp['file2'], false);
		$empty = $diff->is_empty();

		if ($empty)
		{
			unset($diff);

			$update_list['up_to_date'][] = $update_ary;
			return;
		}

		// If no other status matches we have a modified file...
		$update_list['modified'][] = $update_ary;
	}

	/**
	* Update update_list with custom new files
	*/
	function get_custom_info(&$update_list, $file)
	{
		if (empty($this->update_info['custom']))
		{
			return;
		}

		if (isset($this->update_info['custom'][$file]))
		{
			foreach ($this->update_info['custom'][$file] as $_file)
			{
				$update_list[] = array('filename' => $_file, 'custom' => true, 'original' => $file);
			}
		}
	}

	/**
	* Get remote file
	*/
	function get_file($mode)
	{
		$errstr = '';
		$errno = 0;

		switch ($mode)
		{
			case 'version_info':
				$info = get_remote_file('www.phpbb.com', '/updatecheck', ((defined('PHPBB_QA')) ? '30x_qa.txt' : '30x.txt'), $errstr, $errno);

				if ($info !== false)
				{
					$info = explode("\n", $info);
					$info = trim($info[0]);
				}

				if ($this->test_update !== false)
				{
					$info = $this->test_update;
				}

				// If info is false the fsockopen function may not be working. Instead get the latest version from our update file (and pray it is up-to-date)
				if ($info === false)
				{
					$update_info = array();
					include(PHPBB_ROOT_PATH . 'install/update/index.php');
					$info = (empty($update_info) || !is_array($update_info)) ? false : $update_info;

					if ($info !== false)
					{
						$info = (!empty($info['version']['to'])) ? trim($info['version']['to']) : false;
					}
				}
			break;

			case 'update_info':
				$update_info = array();
				include(PHPBB_ROOT_PATH . 'install/update/index.php');

				$info = (empty($update_info) || !is_array($update_info)) ? false : $update_info;
				$errstr = ($info === false) ? phpbb::$user->lang['WRONG_INFO_FILE_FORMAT'] : '';

				if ($info !== false)
				{
					// Adjust the update info file to hold some specific style-related information
					$info['custom'] = array();
/*
					// Get custom installed styles...
					$sql = 'SELECT template_name, template_path
						FROM ' . STYLES_TEMPLATE_TABLE . "
						WHERE LOWER(template_name) NOT IN ('subsilver2', 'prosilver')";
					$result = phpbb::$db->sql_query($sql);

					$templates = array();
					while ($row = phpbb::$db->sql_fetchrow($result))
					{
						$templates[] = $row;
					}
					phpbb::$db->sql_freeresult($result);

					if (sizeof($templates))
					{
						foreach ($info['files'] as $filename)
						{
							// Template update?
							if (strpos(strtolower($filename), 'styles/prosilver/template/') === 0)
							{
								foreach ($templates as $row)
								{
									$info['custom'][$filename][] = str_replace('/prosilver/', '/' . $row['template_path'] . '/', $filename);
								}
							}
						}
					}
*/
				}
			break;

			default:
				trigger_error('Mode for getting remote file not specified', E_USER_ERROR);
			break;
		}

		if ($info === false)
		{
			trigger_error($errstr, E_USER_ERROR);
		}

		return $info;
	}

	/**
	* Function for including files...
	*/
	function include_file($filename)
	{
		if (!empty($this->update_info['files']) && in_array($filename, $this->update_info['files']))
		{
			include_once($this->new_location . $filename);
		}
		else
		{
			include_once(PHPBB_ROOT_PATH . $filename);
		}
	}

	/**
	* Wrapper for returning a diff object
	*/
	function &return_diff()
	{
		$args = func_get_args();
		$three_way_diff = (func_num_args() > 2) ? true : false;

		$file1 = array_shift($args);
		$file2 = array_shift($args);

		$tmp['file1'] = (!empty($file1) && is_string($file1)) ? file_get_contents($file1) : $file1;
		$tmp['file2'] = (!empty($file2) && is_string($file2)) ? file_get_contents($file2) : $file2;

		if ($three_way_diff)
		{
			$file3 = array_shift($args);
			$tmp['file3'] = (!empty($file3) && is_string($file3)) ? file_get_contents($file3) : $file3;

			$diff = new diff3($tmp['file1'], $tmp['file2'], $tmp['file3']);
		}
		else
		{
			$diff = new diff($tmp['file1'], $tmp['file2']);
		}

		unset($tmp);

		return $diff;
	}
}

?>