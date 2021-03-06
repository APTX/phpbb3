#!/usr/bin/env php
<?php
/**
*
* @package build
* @version $Id$
* @copyright (c) 2010 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

// The only thing i need to adjust. ;)
// Please always add the latest version number to the end
// Only have 5 releases here...
// If RC8 drops remove the install/data directory
//$versions = array('3.0.2', '3.0.3-RC1', '3.0.3', '3.0.4-RC1', '3.0.4', '3.0.5-RC1', '3.0.5', '3.0.6-RC1', '3.0.6-RC2', '3.0.6-RC3');
//$versions = array('3.0.2', '3.0.3', '3.0.4', '3.0.5', '3.0.6', '3.0.7-RC1', '3.0.7');
$versions = array('3.0.2', '3.0.3', '3.0.4', '3.0.5', '3.0.6', '3.0.7-RC1', '3.0.7-RC2', '3.0.7');
$verbose = false;

require('build_helper.php');

$package = new build_package($versions, $verbose);

echo "Building Release Packages\n";
echo "Now all three package types (patch, files, release) are built as well as the update package (update).\n";

$package->begin_status('Remove temporary files');

// Cleanup...
$package->run_command('rm -Rv ' . $package->get('dest_dir'));
$package->run_command('rm -Rv ' . $package->get('diff_dir'));
$package->run_command('rm -Rv ' . $package->get('patch_directory'));
$package->run_command('rm -Rv ' . $package->get('files_directory'));
$package->run_command('rm -Rv ' . $package->get('update_directory'));
$package->run_command('rm -Rv ' . $package->get('release_directory'));

$package->begin_status('Create new directories');

// Make sure the directories got removed
while (file_exists($package->get('update_directory')))
{
	sleep(1);
}

if (!file_exists($package->get('dest_dir')))
{
	$package->run_command('mkdir ' . $package->get('dest_dir'));
}

if (!file_exists($package->get('diff_dir')))
{
	$package->run_command('mkdir ' . $package->get('diff_dir'));
}

if (!file_exists($package->get('patch_directory')))
{
	$package->run_command('mkdir ' . $package->get('patch_directory'));
}

if (!file_exists($package->get('files_directory')))
{
	$package->run_command('mkdir ' . $package->get('files_directory'));
}

if (!file_exists($package->get('update_directory')))
{
	$package->run_command('mkdir ' . $package->get('update_directory'));
}

if (!file_exists($package->get('release_directory')))
{
	$package->run_command('mkdir ' . $package->get('release_directory'));
}

$package->begin_status('Copy release files to clean release directory');

// Create config.php file
$package->run_command('touch ' . $package->get('dest_dir') . '/config.php');
//$package->run_command('sudo chown www-data:www-data ' . $package->get('dest_dir') . '/config.php');

// Create new directory structure
foreach ($package->clean_directory_structure as $dir => $dir_struct)
{
	$package->create_directory($package->get('dest_dir') . '/' . $dir, $dir_struct);
}

// First step is to copy the new version over (clean structure)
foreach ($package->files_to_copy as $file)
{
	$source_file = $package->locations['new_version'] . $file;
	$dest_file = $package->get('dest_dir') . '/' . $file;

	$package->run_command("cp -p $source_file $dest_file");
}

// fix line endings
chdir($package->get('dest_dir'));
$package->run_command($package->locations['new_version'] . 'develop/fix_files.sh');

// Now clean up the permissions
$package->begin_status('Adjust permissions');

$package->adjust_permissions($package->get('dest_dir'));

// Now create a version for diffing the version - copy the tree over to old_versions...
$package->begin_status('Create diff directory for obtaining file differences');

$package->run_command('cp -Rp ' . $package->get('dest_dir') . '/* ' . $package->get('diff_dir'));
$package->run_command('cp -Rp ' . $package->get('dest_dir') . '/.htaccess ' . $package->get('diff_dir'));

// Cleanup diff directory (only contents to diff)
foreach ($package->remove_from_diff_structure as $remove_dir)
{
	$package->run_command('rm -Rv ' . $package->get('diff_dir') . '/' . $remove_dir);
}

// Now, first of all we need to rebuild all old packages we want to support
foreach ($package->old_packages as $package_name => $tag_name)
{
	$package->begin_status('Create old packages directory for diffing to ' . $package_name);

	chdir($package->locations['old_versions']);

	if (is_dir($package->locations['old_versions'] . $package_name))
	{
		$package->run_command('rm -Rv ' . $package->locations['old_versions'] . $package_name);
	}

	// Now, create a new one...
	$tag_name = 'release_' . str_replace(array('.', '_to_'), array('_', ''), $tag_name);

	$package->run_command('svn export --non-interactive http://code.phpbb.com/svn/phpbb/tags/' . $tag_name . '/phpBB/ ' . $package_name);

	$location = $package->locations['old_versions'] . $package_name;
	chdir($location . '/');

	$package->run_command($package->locations['new_version'] . 'develop/fix_files.sh');

	// Now clean up the permissions
	$package->begin_status('Adjust permissions for package ' . $package_name);

	$package->adjust_permissions($location);

	// Cleanup diff directory (only contents to diff)
	foreach ($package->remove_from_diff_structure as $remove_dir)
	{
		$package->run_command('rm -Rv ' . $location . '/' . $remove_dir);
	}
}

// Go trough all versions making a diff if we even have old versions
// For phpBB 3.0.x we might choose a different update method, rendering the things below useless...
if (sizeof($package->old_packages))
{
	chdir($package->locations['old_versions']);

	// This array is for holding the filenames change
	$diff_file_changes = array();

	foreach ($package->old_packages as $_package_name => $dest_package_filename)
	{
		$package->begin_status('Creating patch/diff files for phpBB-' . $dest_package_filename . $package->get('new_version_number'));

		$dest_package_filename = $package->get('patch_directory') . '/phpBB-' . $dest_package_filename . $package->get('new_version_number') . '.patch';
		$package->run_command('diff ' . $package->diff_options . ' ' . $_package_name . ' ' . $package->get('simple_name') . ' > ' . $dest_package_filename);

		// Parse this diff to determine file changes from the checked versions and save them
		$diff_file_changes[$_package_name] = $package->collect_diff_files($dest_package_filename, $_package_name);
	}

	// Now put those files determined within the correct directories
	foreach ($diff_file_changes as $_package_name => $file_contents)
	{
		$package->begin_status('Creating files-only informations for ' . $package->old_packages[$_package_name] . $package->get('new_version_number'));

		$dest_filename_dir = $package->get('files_directory') . '/' . $package->old_packages[$_package_name] . $package->get('new_version_number');

		if (!file_exists($dest_filename_dir))
		{
			$package->run_command('mkdir ' . $dest_filename_dir);
		}

		// Now copy the file contents
		foreach ($file_contents['all'] as $file)
		{
			$source_filename = $package->get('dest_dir') . '/' . $file;
			$dest_filename = $dest_filename_dir . '/' . $file;

			// Create Directories along the way?
			$file = explode('/', $file);
			// Remove filename portion
			$file[sizeof($file)-1] = '';

			chdir($dest_filename_dir);
			foreach ($file as $entry)
			{
				$entry = trim($entry);
				if ($entry)
				{
					if (!file_exists('./' . $entry))
					{
						$package->run_command('mkdir ' . $entry);
					}
					chdir('./' . $entry);
				}
			}

			$package->run_command('cp ' . $source_filename . ' ' . $dest_filename);
		}
	}

	// Because there might be binary changes, we re-create the patch files... without parsing file differences.
	$package->run_command('rm -Rv ' . $package->get('patch_directory'));

	if (!file_exists($package->get('patch_directory')))
	{
		$package->run_command('mkdir ' . $package->get('patch_directory'));
	}

	chdir($package->locations['old_versions']);

	foreach ($package->old_packages as $_package_name => $dest_package_filename)
	{
		$package->begin_status('Creating patch/diff files for phpBB-' . $dest_package_filename . $package->get('new_version_number'));

		$dest_package_filename = $package->get('patch_directory') . '/phpBB-' . $dest_package_filename . $package->get('new_version_number') . '.patch';
		$package->run_command('diff ' . $package->diff_options_long . ' ' . $_package_name . ' ' . $package->get('simple_name') . ' > ' . $dest_package_filename);
	}

	$packages = $diff_file_changes;

	foreach ($packages as $_package_name => $file_contents)
	{
		$package->begin_status('Building specific update files for ' . $package->old_packages[$_package_name] . $package->get('new_version_number'));

		$dest_filename_dir = $package->get('update_directory') . '/' . $package->old_packages[$_package_name] . $package->get('new_version_number');

		if (!file_exists($dest_filename_dir))
		{
			$package->run_command('mkdir ' . $dest_filename_dir);
		}

		$package->run_command('cp -Rp ' . $package->get('dest_dir') . '/docs ' . $dest_filename_dir);
		$package->run_command('cp -Rp ' . $package->get('dest_dir') . '/install ' . $dest_filename_dir);

		$package->run_command('mkdir ' . $dest_filename_dir . '/install/update');
		$package->run_command('mkdir ' . $dest_filename_dir . '/install/update/old');
		$package->run_command('mkdir ' . $dest_filename_dir . '/install/update/new');

		// Remove some files
		$package->run_command('rm -v ' . $dest_filename_dir . '/install/install_install.php');
		$package->run_command('rm -v ' . $dest_filename_dir . '/install/install_convert.php');
		$package->run_command('rm -Rv ' . $dest_filename_dir . '/install/schemas');
		$package->run_command('rm -Rv ' . $dest_filename_dir . '/install/convertors');

		foreach ($file_contents['all'] as $index => $file)
		{
			if (strpos($file, 'recode_cjk') !== false)
			{
				unset($file_contents['all'][$index]);
			}
		}

		// First of all, fill the 'old' directory
		foreach ($file_contents['all'] as $file)
		{
			$source_filename = $package->locations['old_versions'] . $_package_name . '/' . $file;
			$dest_filename = $dest_filename_dir . '/install/update/old/' . $file;

			if (!file_exists($source_filename))
			{
				continue;
			}

			// Create Directories along the way?
			$file = explode('/', $file);
			// Remove filename portion
			$file[sizeof($file)-1] = '';

			chdir($dest_filename_dir . '/install/update/old');
			foreach ($file as $entry)
			{
				$entry = trim($entry);
				if ($entry)
				{
					if (!file_exists('./' . $entry))
					{
						$package->run_command('mkdir ' . $entry);
					}
					chdir('./' . $entry);
				}
			}

			$package->run_command('cp ' . $source_filename . ' ' . $dest_filename);
		}

		// Then fill the 'new' directory
		foreach ($file_contents['all'] as $file)
		{
			$source_filename = $package->locations['old_versions'] . $package->get('simple_name') . '/' . $file;
			$dest_filename = $dest_filename_dir . '/install/update/new/' . $file;

			if (!file_exists($source_filename))
			{
				continue;
			}

			// Create Directories along the way?
			$file = explode('/', $file);
			// Remove filename portion
			$file[sizeof($file)-1] = '';

			chdir($dest_filename_dir . '/install/update/new');
			foreach ($file as $entry)
			{
				$entry = trim($entry);
				if ($entry)
				{
					if (!file_exists('./' . $entry))
					{
						$package->run_command('mkdir ' . $entry);
					}
					chdir('./' . $entry);
				}
			}

			$package->run_command('cp ' . $source_filename . ' ' . $dest_filename);
		}

		// Build index.php file for holding the file structure
		$index_contents = '<?php

if (!defined(\'IN_PHPBB\'))
{
	exit;
}

// Set update info with file structure to update
$update_info = array(
	\'version\'	=> array(\'from\' => \'' . str_replace('_to_', '', $package->old_packages[$_package_name]) . '\', \'to\' => \'' . $package->get('new_version_number') . '\'),
';

		if (sizeof($file_contents['all']))
		{
			$index_contents .= '\'files\'		=> array(\'' . implode("',\n\t'", $file_contents['all']) . '\'),
';
		}
		else
		{
			$index_contents .= '\'files\'		=> array(),
';
		}

		if (sizeof($file_contents['binary']))
		{
			$index_contents .= '\'binary\'		=> array(\'' . implode("',\n\t'", $file_contents['binary']) . '\'),
';
		}
		else
		{
			$index_contents .= '\'binary\'		=> array(),
';
		}

		$index_contents .= ');

?' . '>';

		$fp = fopen($dest_filename_dir . '/install/update/index.php', 'wt');
		fwrite($fp, $index_contents);
		fclose($fp);
	}
	unset($diff_file_changes);

	$package->begin_status('Clean up all install files');

	// Copy the install files to their respective locations
	$package->run_command('cp -Rp ' . $package->get('dest_dir') . '/docs ' . $package->get('patch_directory'));
	$package->run_command('cp -Rp ' . $package->get('dest_dir') . '/install ' . $package->get('patch_directory'));

	// Remove some files
	chdir($package->get('patch_directory') . '/install');

	$package->run_command('rm -v install_install.php');
	$package->run_command('rm -v install_update.php');
	$package->run_command('rm -v install_convert.php');
	$package->run_command('rm -Rv schemas');
	$package->run_command('rm -Rv convertors');
}

// Build Main phpBB Release
$compress_programs = array(
//	'tar.gz'	=> 'tar -czf',
	'tar.bz2'	=> 'tar -cjf',
	'zip'		=> 'zip -r'
);

if (sizeof($package->old_packages))
{
	// Build Patch Files
	chdir($package->get('patch_directory'));

	foreach ($compress_programs as $extension => $compress_command)
	{
		$package->begin_status('Packaging phpBB Patch Files for ' . $extension);
		$package->run_command('rm -v ../release_files/' . $package->get('release_filename') . '-patch.' . $extension);

		// Build Package
		$package->run_command($compress_command . ' ../release_files/' . $package->get('release_filename') . '-patch.' . $extension . ' *');

		// Build MD5 Sum
		$package->run_command('md5sum ../release_files/' . $package->get('release_filename') . '-patch.' . $extension . ' > ../release_files/' . $package->get('release_filename') . '-patch.' . $extension . '.md5');
	}

	// Build Files Package
	chdir($package->get('files_directory'));

	foreach ($compress_programs as $extension => $compress_command)
	{
		$package->begin_status('Packaging phpBB Files for ' . $extension);

		$package->run_command('rm -v ../release_files/' . $package->get('release_filename') . '-files.' . $extension);
		$package->run_command('mkdir ' . $package->get('files_directory') . '/release');
		$package->run_command('cp -Rp ' . $package->get('dest_dir') . '/docs ' . $package->get('files_directory') . '/release');
		$package->run_command('cp -Rp ' . $package->get('dest_dir') . '/install ' . $package->get('files_directory') . '/release');

		$package->run_command('rm -v ' . $package->get('files_directory') . '/release/install/install_install.php');
		$package->run_command('rm -v ' . $package->get('files_directory') . '/release/install/install_update.php');
		$package->run_command('rm -v ' . $package->get('files_directory') . '/release/install/install_convert.php');
		$package->run_command('rm -Rv ' . $package->get('files_directory') . '/release/install/schemas');
		$package->run_command('rm -Rv ' . $package->get('files_directory') . '/release/install/convertors');

		// Pack files
		foreach ($package->old_packages as $_package_name => $package_path)
		{
			chdir($package_path . $package->get('new_version_number'));
			$command = ($extension == 'zip') ? 'zip -r' : 'tar cf';
			$_ext = ($extension == 'zip') ? 'zip' : 'tar';
			$package->run_command("$command ../release/phpBB-$package_path" . $package->get('new_version_number') . ".$_ext *");
			chdir('..');
		}

		chdir('./release');
		$package->run_command("$compress_command ../../release_files/" . $package->get('release_filename') . '-files.' . $extension . ' *');
		// Build MD5 Sum
		$package->run_command('md5sum ../../release_files/' . $package->get('release_filename') . '-files.' . $extension . ' > ../../release_files/' . $package->get('release_filename') . '-files.' . $extension . '.md5');
		chdir('..');

		$package->run_command('rm -Rv ' . $package->get('files_directory') . '/release');
	}

	// Build Update Package
	foreach ($compress_programs as $extension => $compress_command)
	{
		chdir($package->get('update_directory'));

		$package->begin_status('Packaging phpBB Update for ' . $extension);

		$package->run_command('rm -v ../release_files/' . $package->get('release_filename') . '-update.' . $extension);
		$package->run_command('mkdir ' . $package->get('update_directory') . '/release');

		// Pack update files
		$packages = $package->old_packages;

		foreach ($packages as $_package_name => $package_path)
		{
			chdir($package_path . $package->get('new_version_number'));

			$package->run_command('rm -v install/install_install.php');
			$package->run_command('rm -v install/install_convert.php');
			$package->run_command('rm -v includes/utf/data/recode_cjk.php');
			$package->run_command('rm -Rv install/schemas');
			$package->run_command('rm -Rv install/convertors');

			$command = ($extension == 'zip') ? 'zip -r' : 'tar cf';
			$_ext = ($extension == 'zip') ? 'zip' : 'tar';
			$package->run_command("$command ../release/$package_path" . $package->get('new_version_number') . ".$_ext *");
			chdir('..');

			$last_version = $package_path . $package->get('new_version_number');

//			chdir('./release');
//			$package->run_command("$compress_command ../../release_files/" . $package->get('release_filename') . '-update.' . $extension . ' *');
//			chdir('..');

			chdir('./' . $last_version);
			// Copy last package over...
			$package->run_command('rm -v ../release_files/phpBB-' . $last_version . ".$extension");
			$package->run_command("$compress_command ../../release_files/phpBB-$last_version.$extension *");

			// Build MD5 Sum
			$package->run_command("md5sum ../../release_files/phpBB-$last_version.$extension > ../../release_files/phpBB-$last_version.$extension.md5");
			chdir('..');
		}

		$package->run_command('rm -Rv ' . $package->get('update_directory') . '/release');
	}

}

// Delete updater and convertor from main archive
chdir($package->get('dest_dir') . '/install');

// $package->run_command('rm -v database_update.php');
$package->run_command('rm -v install_update.php');

chdir($package->locations['package_dir']);
foreach ($compress_programs as $extension => $compress_command)
{
	$package->begin_status('Packaging phpBB for ' . $extension);
	$package->run_command('rm -v ./release_files/' . $package->get('release_filename') . ".{$extension}");

	// Build Package
	$package->run_command("$compress_command ./release_files/" . $package->get('release_filename') . '.' . $extension . ' ' . $package->get('package_name'));

	// Build MD5 Sum
	$package->run_command('md5sum ./release_files/' . $package->get('release_filename') . '.' . $extension . ' > ./release_files/' . $package->get('release_filename') . '.' . $extension . '.md5');
}

// verify results
chdir($package->locations['root']);
$package->begin_status('********** Verifying packages **********');
$package->run_command('./compare.sh ' . $package->package_infos['release_filename']);

echo "Done.\n";
