<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2013 The facileManager Team                               |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | facileManager: Easy System Administration                               |
 +-------------------------------------------------------------------------+
 | http://www.facilemanager.com/                                           |
 +-------------------------------------------------------------------------+
*/

class fm_tools {
	
	/**
	 * Installs a module
	 *
	 * @since 1.0
	 * @package facileManager
	 */
	function installModule($module_name = null) {
		global $__FM_CONFIG;
		
		if (!$module_name) {
			return sprintf('<p>%s</p>', _('No module was selected to be installed.'));
		}
		
		$install_file = ABSPATH . 'fm-modules' . DIRECTORY_SEPARATOR . $module_name . DIRECTORY_SEPARATOR . 'install.php';
		if (file_exists($install_file)) {
			include($install_file);
			
			/** Include module variables */
			@include(ABSPATH . 'fm-modules/' . $module_name . '/variables.inc.php');
			
			$function = 'install' . $module_name . 'Schema';
			if (function_exists($function)) {
				$output = $function(null, $__FM_CONFIG['db']['name'], $module_name, false);
			}
			if (strpos($output, 'Success') === false) {
				$error = (!getOption('show_errors')) ? "<p>$output</p>" : null;
				return sprintf('<p>' . _('%s installation failed!') . '</p>%s', $module_name, $error);
			}
			
			addLogEntry(sprintf(_('%s %s was born.'), $module_name, $__FM_CONFIG[$module_name]['version']), $module_name);
		} else return sprintf('<p>' . _('No installation file found for %s.') . '</p>', $module_name);
		
		return sprintf('<p>' . _('%s was installed successfully!') . '</p>', $module_name);
	}
	
	/**
	 * Upgrades a module
	 *
	 * @since 1.0
	 * @package facileManager
	 */
	function upgradeModule($module_name = null) {
		global $fmdb;
		
		if (!$module_name) {
			return sprintf('<p>%s</p>', _('No module was selected to be upgraded.'));
		}
		
		$upgrade_file = ABSPATH . 'fm-modules' . DIRECTORY_SEPARATOR . $module_name . DIRECTORY_SEPARATOR . 'upgrade.php';
		if (file_exists($upgrade_file)) {
			include($upgrade_file);
			
			/** Include module variables */
			@include(ABSPATH . 'fm-modules/' . $module_name . '/variables.inc.php');
			
			$function = 'upgrade' . $module_name . 'Schema';
			if (function_exists($function)) {
				$output = $function($module_name);
			}
			if ($output !== true) {
				$error = (!getOption('show_errors')) ? "<p>$output</p>" : null;
				return sprintf('<p>' . _('%s upgrade failed!') . '</p>%s', $module_name, $error);
			} else {
				setOption('version', $__FM_CONFIG[$module_name]['version'], 'auto', false, 0, $module_name);
				if ($fmdb->last_error) {
					$error = (!getOption('show_errors')) ? '<p>' . $fmdb->last_error . '</p>' : null;
					return sprintf('<p>' . _('%s upgrade failed!') . '</p>%s', $module_name, $error);
				}
				setOption('version_check', array('timestamp' => date("Y-m-d H:i:s", strtotime("2 days ago")), 'data' => null), 'update', true, 0, $module_name);
			}

			addLogEntry(sprintf(_('%s was upgraded to %s.'), $module_name, $__FM_CONFIG[$module_name]['version']), $module_name);
		}
		
		return sprintf('<p>' . _('%s was upgraded successfully! Make sure you upgrade your clients with the updated client files (if applicable).') . '</p>', $module_name);
	}
	
	/**
	 * Manages a module
	 *
	 * @since 1.0
	 * @package facileManager
	 */
	function manageModule($action = null, $module_name = null) {
		global $__FM_CONFIG;
		
		if (!$module_name || !in_array($module_name, getAvailableModules())) {
			return false;
		}
		
		$current_active_modules = getOption('fm_active_modules', $_SESSION['user']['account_id']);
		$command = is_array($current_active_modules) ? 'update' : 'insert';
		
		switch($action) {
			case 'activate':
				if (in_array($module_name, getActiveModules())) return;
				
				$current_active_modules[] = $module_name;
				return setOption('fm_active_modules', $current_active_modules, 'auto', true, $_SESSION['user']['account_id']);

				break;
			case 'deactivate':
				if (!in_array($module_name, getActiveModules())) return;
				
				$new_array = array();
				foreach ($current_active_modules as $module) {
					if ($module == $module_name) continue;
					$new_array[] = $module;
				}

				return setOption('fm_active_modules', $new_array, 'update', true, $_SESSION['user']['account_id']);

				break;
			case 'uninstall':
				if (!in_array($module_name, getAvailableModules())) return;
				
				if (function_exists('uninstallModuleSchema')) {
					$output = uninstallModuleSchema($__FM_CONFIG['db']['name'], $module_name);
				}
				if ($output != 'Success') return false;
				
				return true;

				break;
		}
		
		return false;
	}
	
	/**
	 * Cleans up the database
	 *
	 * @since 1.0
	 * @package facileManager
	 */
	function cleanupDatabase() {
		global $fmdb, $__FM_CONFIG, $fm_name;
		
		$record_count = 0;
		
		/** Remove deleted items */
		$fmdb->get_results("SHOW TABLES");
		
		$raw_table_list = $fmdb->last_result;
		foreach ($raw_table_list as $table_object) {
			$table_array = get_object_vars($table_object);
			$array_keys = array_keys($table_array);
			$table = $table_array[$array_keys[0]];
			if (array_key_exists($table, $__FM_CONFIG['clean']['prefixes'])) {
				$query = 'DELETE FROM ' . $table  . ' WHERE ' . $__FM_CONFIG['clean']['prefixes'][$table] . '_status = "deleted"';
				$fmdb->query($query);
				$record_count += $fmdb->rows_affected;
			}
		}
		
		/** Remove old password reset requests */
		$time = date("Y-m-d H:i:s", strtotime($__FM_CONFIG['clean']['days'] . ' days ago'));
		$query = 'DELETE FROM `fm_pwd_resets` WHERE `pwd_timestamp`<"' . $time . '"';
		$fmdb->query($query);
		$record_count += $fmdb->rows_affected;
		
		addLogEntry(_('Cleaned up the database.'), $fm_name);
		return sprintf(_('Total number of records purged from the database: <b>%d</b>'), $record_count);
	}

	/**
	 * Backs up the database
	 *
	 * @since 1.0
	 * @package facileManager
	 */
	function backupDatabase() {
		global $__FM_CONFIG, $fm_name;
		
		if (!currentUserCan('run_tools')) return sprintf('<p class="error">%s</p>', _('You are not authorized to run these tools.'));
		
		/** Temporary fix for MySQL 5.6 warnings */
		$exclude_warnings = array('Warning: Using a password on the command line interface can be insecure.' . "\n");
		
		$curdate = date("Y-m-d_H.i.s");
		$sql_file = '/tmp/' . $__FM_CONFIG['db']['name'] . '_' . $curdate . '.sql';
		$error_log = str_replace('.sql', '.err', $sql_file);
		
		$mysqldump = findProgram('mysqldump');
		if (!$mysqldump) return sprintf('<p class="error">' . _('mysqldump is not found on %s.') . '</p>', php_uname('n'));
		
		$command_string = "$mysqldump --opt -Q -h {$__FM_CONFIG['db']['host']} -u {$__FM_CONFIG['db']['user']} -p{$__FM_CONFIG['db']['pass']} {$__FM_CONFIG['db']['name']} > /tmp/{$__FM_CONFIG['db']['name']}_$curdate.sql 2>$error_log";
		@system($command_string, $retval);
		$retarr = @file_get_contents($error_log);
		
		if ($retval) {
			@unlink($error_log);
			@unlink($sql_file);
			return '<p class="error">' . nl2br(str_replace($exclude_warnings, '', $retarr)) . '</p>';
		}
		
		compressFile($sql_file, @file_get_contents($sql_file));
		@unlink($error_log);
		@unlink($sql_file);
		
		addLogEntry(_('Backed up the database.'), $fm_name);

		sendFileToBrowser($sql_file . '.gz');
	}

}

if (!isset($fm_tools))
	$fm_tools = new fm_tools();

?>