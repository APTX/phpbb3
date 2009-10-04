<?php
/**
*
* @package ucp
* @version $Id$
* @copyright (c) 2005 phpBB Group
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/
if (!defined('IN_PHPBB'))
{
	exit;
}

/**
* ucp_remind
* Sending password reminders
* @package ucp
*/
class ucp_remind
{
	var $u_action;

	function main($id, $mode)
	{
		$username	= request_var('username', '', true);
		$email		= strtolower(request_var('email', ''));
		$submit		= phpbb_request::is_set_post('submit');

		if ($submit)
		{
			$sql = 'SELECT user_id, username, user_permissions, user_email, user_jabber, user_notify_type, user_type, user_lang, user_inactive_reason
				FROM ' . USERS_TABLE . "
				WHERE user_email = '" . phpbb::$db->sql_escape($email) . "'
					AND username_clean = '" . phpbb::$db->sql_escape(utf8_clean_string($username)) . "'";
			$result = phpbb::$db->sql_query($sql);
			$user_row = phpbb::$db->sql_fetchrow($result);
			phpbb::$db->sql_freeresult($result);

			if (!$user_row)
			{
				trigger_error('NO_EMAIL_USER');
			}

			if ($user_row['user_type'] == phpbb::USER_IGNORE)
			{
				trigger_error('NO_USER');
			}

			if ($user_row['user_type'] == phpbb::USER_INACTIVE)
			{
				if ($user_row['user_inactive_reason'] == INACTIVE_MANUAL)
				{
					trigger_error('ACCOUNT_DEACTIVATED');
				}
				else
				{
					trigger_error('ACCOUNT_NOT_ACTIVATED');
				}
			}

			// Check users permissions
			$auth2 = new auth();
			$auth2->acl($user_row);

			if (!$auth2->acl_get('u_chgpasswd'))
			{
				trigger_error('NO_AUTH_PASSWORD_REMINDER');
			}

			$server_url = generate_board_url();

			$key_len = 54 - strlen($server_url);
			$key_len = max(6, $key_len); // we want at least 6
			$key_len = (phpbb::$config['max_pass_chars']) ? min($key_len, phpbb::$config['max_pass_chars']) : $key_len; // we want at most phpbb::$config['max_pass_chars']
			$user_actkey = substr(gen_rand_string(10), 0, $key_len);
			$user_password = gen_rand_string(8);

			$sql = 'UPDATE ' . USERS_TABLE . "
				SET user_newpasswd = '" . phpbb::$db->sql_escape(phpbb_hash($user_password)) . "', user_actkey = '" . phpbb::$db->sql_escape($user_actkey) . "'
				WHERE user_id = " . $user_row['user_id'];
			phpbb::$db->sql_query($sql);

			include_once(PHPBB_ROOT_PATH . 'includes/functions_messenger.' . PHP_EXT);

			$messenger = new messenger(false);

			$messenger->template('user_activate_passwd', $user_row['user_lang']);

			$messenger->to($user_row['user_email'], $user_row['username']);
			$messenger->im($user_row['user_jabber'], $user_row['username']);

			$messenger->assign_vars(array(
				'USERNAME'		=> htmlspecialchars_decode($user_row['username']),
				'PASSWORD'		=> htmlspecialchars_decode($user_password),
				'U_ACTIVATE'	=> "$server_url/ucp." . PHP_EXT . "?mode=activate&u={$user_row['user_id']}&k=$user_actkey")
			);

			$messenger->send($user_row['user_notify_type']);

			meta_refresh(3, append_sid('index'));

			$message = phpbb::$user->lang['PASSWORD_UPDATED'] . '<br /><br />' . sprintf(phpbb::$user->lang['RETURN_INDEX'], '<a href="' . append_sid('index') . '">', '</a>');
			trigger_error($message);
		}

		phpbb::$template->assign_vars(array(
			'USERNAME'			=> $username,
			'EMAIL'				=> $email,
			'S_PROFILE_ACTION'	=> append_sid('ucp', 'mode=sendpassword'),
		));

		$this->tpl_name = 'ucp_remind';
		$this->page_title = 'UCP_REMIND';
	}
}

?>