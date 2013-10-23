<?php
/**
*
* @package phpBB3 Report to topic
* @version $Id$
* @copyright (c) 2013 *Daniel
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

define('UMIL_AUTO', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : './';
$phpEx = substr(strrchr(__FILE__, '.'), 1);

define('IN_PHPBB', true);
include($phpbb_root_path . 'common.' . $phpEx);
$user->session_begin();
$auth->acl($user->data);
$user->setup(array('mods/reporttotopic/reporttotopic_acp', 'mods/reporttotopic/reporttotopic_common'));

if (!file_exists($phpbb_root_path . 'umil/umil_auto.' . $phpEx))
{
	trigger_error('Please download the latest UMIL (Unified MOD Install Library) from: <a href="http://www.phpbb.com/mods/umil/">phpBB.com/mods/umil</a>', E_USER_ERROR);
}

$mod_name = 'ACP_REPORTTOTOPIC';
$version_config_name = 'reporttotopic';

$versions = array(
	'0.1.0'		=> array(

		'table_column_add' => array(
			array(FORUMS_TABLE, 'r2t_report_forum', array('MTEXT_UNI', '')),
		),
		
		'config_add'	=> array(
						array('r2t_dest_forum', '1'),
						array('r2t_pm_dest_forum', '1'),
						array('r2t_pm_template', '0'),
						array('r2t_pm_template_bbcode', '0'),
						array('r2t_pm_template_sig', '0'),
						array('r2t_pm_template_smilies', '0'),
						array('r2t_pm_template_urls', '0'),
						array('r2t_pm_title', '480'),
						array('r2t_post_template', '0'),
						array('r2t_post_template_bbcode', '0'),
						array('r2t_post_template_sig', '0'),
						array('r2t_post_template_smilies', '0'),
						array('r2t_post_template_urls', '0'),
						array('r2t_post_title', '0'),

		),

		'module_add' => array(
			array('acp', 'ACP_BOARD_CONFIGURATION', array(
					'module_basename'	=> 'reporttotopic',
					'module_enabled'	=> 1,
					'module_display'	=> 1,					
					'module_langname'	=> 'ACP_REPORTTOTOPIC_CONFIG',
					'module_mode'		=> 'config',
					'module_auth'		=> '',
				),
			),
		),		

		'cache_purge'	=> array(
			array('imageset'),
			array('template'),
			array('theme'),
		),
	),
);

include($phpbb_root_path . 'umil/umil_auto.' . $phpEx);

?>