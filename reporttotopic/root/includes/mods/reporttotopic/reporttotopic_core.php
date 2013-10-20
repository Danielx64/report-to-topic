<?php
/**
 *
 * @package report2topic++
 * @copyright (c) 2010 report2topic++ http://github.com/report2topic
 * @author Erik Frèrejean ( N/A ) http://www.erikfrerejean.nl
 * @author David King (imkingdavid) http://www.phpbbdevelopers.net
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
 * The main report2topic++ class, handles all common stuff
 */
class report2topic_core
{
	/**@#+
	 * Some commonly used phpBB objects, by defining them here we don't need to
	 * globalize them all over the place.
	 */
	private $auth		= null;
	private $cache		= null;
	private $config		= array();
	private $db			= null;
	private $template	= null;
	private $user		= null;
	/**@#-*/

	/**
	 * @var report2topic_core Store an instance of this class.
	 */
	static private $instance = null;

	/**
	 * @var array The post data array
	 */
	private $post_data = array(
		'forum_id'	=> 0,
		'topic_id'	=> 0,
		'icon_id'	=> false,

		// Defining Post Options
		'enable_bbcode'		=> false,
		'enable_smilies'	=> false,
		'enable_urls'       => false,
		'enable_sig'        => false,

		// Message Body
		'message'		=> '',
		'message_md5'	=> '',

		// Values from generate_text_for_storage()
		'bbcode_bitfield'	=> '',
		'bbcode_uid'		=> '',

		// Other Options
		'post_edit_locked'	=> 1,
		'topic_title'		=> '',

		// Email Notification Settings
		'notify_set'	=> false,
		'notify'		=> false,
		'post_time'		=> 0,
		'forum_name'	=> '',

		// Indexing
		'enable_indexing' => true,
	);

	/**
	 * Construct the main class
	 */
	private function __construct()
	{
		// Globalize some of the main phpBB objects
		global $auth, $cache, $config, $db, $template, $user;
		$this->auth		= &$auth;
		$this->cache	= &$cache;
		$this->config	= &$config;
		$this->db		= &$db;
		$this->template	= &$template;
		$this->user		= &$user;

		// If needed make constants out of the phpBB paths
		if (!defined('PHPBB_ROOT_PATH'))
		{
			global $phpbb_root_path;
			define('PHPBB_ROOT_PATH', $phpbb_root_path);
		}

		if (!defined('PHP_EXT'))
		{
			global $phpEx;
			define('PHP_EXT', $phpEx);
		}
	}

	/**
	 * Get instance of the core class, acts as an singleton loader
	 * @return report2topic_core Instance of this class
	 */
	static public function getInstance()
	{
		if (self::$instance === null)
		{
			self::$instance = new report2topic_core();
		}

		return self::$instance;
	}

	/**
	 * Adjust the forum data array so that the report2topic destination forum
	 * is saved correctly
	 * @param	array	$forum_data	The forum data array passed from the manage method
	 * @param	array	$error		Array containing any encountered error messages
	 * @return	void
	 */
	public function acp_alter_forum_data(&$forum_data, &$error)
	{
		$dest_forum = request_var('report2topic_dest_forum', 0);

		// Make sure that this forum exists
		$sql = 'SELECT forum_id
			FROM ' . FORUMS_TABLE . '
			WHERE forum_id = ' . $dest_forum;
		$result	= $this->db->sql_query($sql);
		$fid	= $this->db->sql_fetchfield('forum_id', false, $result);
		$this->db->sql_freeresult($result);

		if ($dest_forum > 0 && !$fid)
		{
			$error[] = $this->user->lang('FORUM_NOT_EXISTS', $dest_forum);
			return;
		}

		// Merge in the $forum_data array
		$forum_data['r2t_report_forum'] = $dest_forum;
	}

	/**
	 * A new report is created, create the report topic
	 * @param	Integer	$pm_id		ID of the reported PM
	 * @param	Integer	$post_id	ID of the reported post
	 * @return	void
	 */
	public function submit_report_post($pm_id = 0, $post_id = 0)
	{
		// Some mode specific data
		if ($pm_id > 0)
		{
			$subject = 'r2t_pm_title';
			$template = 'r2t_pm_template';

			// Can't use {REPORT_POST} here!
			unset($this->user->lang['r2t_tokens']['REPORT_POST']);

			// Destination forum
			$this->post_data['forum_id'] = $this->config['r2t_pm_dest_forum'];

			// Post options
			$this->post_data['enable_bbcode']	= ($this->config['r2t_pm_template_bbcode']) ? true : false;
			$this->post_data['enable_smilies']	= ($this->config['r2t_pm_template_smilies']) ? true : false;
			$this->post_data['enable_urls']		= ($this->config['r2t_pm_template_urls']) ? true : false;
			$this->post_data['enable_sig']		= ($this->config['r2t_pm_template_sig']) ? true : false;
		}
		else if ($post_id > 0)
		{
			$subject = 'r2t_post_title';
			$template = 'r2t_post_template';

			// Destination forum
			global $forum_data;
			$this->post_data['forum_id'] = ($forum_data['r2t_report_forum'] > 0) ? $forum_data['r2t_report_forum'] : $this->config['r2t_dest_forum'];

			// Post options
			$this->post_data['enable_bbcode']	= ($this->config['r2t_post_template_bbcode']) ? true : false;
			$this->post_data['enable_smilies']	= ($this->config['r2t_post_template_smilies']) ? true : false;
			$this->post_data['enable_urls']		= ($this->config['r2t_post_template_urls']) ? true : false;
			$this->post_data['enable_sig']		= ($this->config['r2t_post_template_sig']) ? true : false;
		}
		else
		{
			// No report, shouldn't happen but hey ;)
			return;
		}

		// Fetch the report data
		$report_data = $this->get_report_data($pm_id, $post_id);

		// Prepare token replacements
		$replacing = $tokens = $tokens_replacement = array();
		$this->prepare_tokens($tokens_replacement, $report_data);
		foreach (array_keys($this->user->lang['r2t_tokens']) as $token)
		{
			$tokens[]		= '{' . $token . '}';
			$replacing[]	= $tokens_replacement[$token];
		}

		// Get the message parser
		if (!class_exists('parse_message'))
		{
			global $phpbb_root_path, $phpEx;	//	<!-- Required otherwise the message parser whines
			require PHPBB_ROOT_PATH . 'includes/message_parser.' . PHP_EXT;
		}

		// Prepare the post
		$post = str_replace($tokens, $replacing, $this->config[$template]);

		// Load the message parser
		$report_parser = new parse_message($post);

		// Parse the post
		$report_parser->parse($this->post_data['enable_bbcode'], $this->post_data['enable_urls'], $this->post_data['enable_smilies']);

		// Set the message
		$this->post_data['bbcode_bitfield']	= $report_parser->bbcode_bitfield;
        $this->post_data['bbcode_uid']		= $report_parser->bbcode_uid;
		$this->post_data['message']			= $report_parser->message;
		$this->post_data['message_md5']		= md5($report_parser->message);
		$this->post_data['topic_title'] 	= censor_text(str_replace($tokens, $replacing, $this->config[$subject]));

		// Only here to not break "submit_post()"
		$poll_data = array();

		// And finally submit
		if (!function_exists('submit_post'))
		{
			require PHPBB_ROOT_PATH . 'includes/functions_posting.' . PHP_EXT;
		}
		submit_post('post', $this->post_data['topic_title'], '', POST_NORMAL, $poll_data, $this->post_data);
	}

	/**
	 * Get the report data of the reported post or PM
	 * @param	Integer	$pm_id		ID of the reported PM
	 * @param	Integer	$post_id	ID of the reported post
	 * @return	Array	The report data
	 */
	private function get_report_data($pm_id = 0, $post_id = 0)
	{
		// The global query
		$sql_ary = array(
			'SELECT'	=> 'r.user_id, r.report_id, r.report_closed, r.report_time, r.report_text, rr.reason_title, rr.reason_description, u.username, u.username_clean, u.user_colour',
			'FROM'		=> array(
				REPORTS_TABLE			=> 'r',
				REPORTS_REASONS_TABLE	=> 'rr',
				USERS_TABLE				=> 'u',
			),
			'ORDER_BY'	=> 'report_closed ASC',
		);

		// Type specific
		if ($post_id > 0)
		{
			$sql_ary['SELECT']	.= ', p.post_subject, r.post_id';
			$sql_ary['FROM']	+= array(POSTS_TABLE => 'p');
			$sql_ary['WHERE']	= "r.post_id = {$post_id}
				AND rr.reason_id = r.reason_id
				AND r.user_id = u.user_id
				AND r.pm_id = 0
				AND p.post_id = r.post_id";
		}
		else
		{
			$sql_ary['SELECT']	.= ', pm.message_subject, r.pm_id';
			$sql_ary['FROM']	+= array(PRIVMSGS_TABLE => 'pm');
			$sql_ary['WHERE']	= "r.pm_id = {$pm_id}
				AND rr.reason_id = r.reason_id
				AND r.user_id = u.user_id
				AND r.post_id = 0
				AND pm.msg_id = r.pm_id";
		}

		// Build and run the query
		$sql	= $this->db->sql_build_query('SELECT', $sql_ary);
		$result	= $this->db->sql_query($sql);
		$report	= $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $report;
	}

	/**
	 * Create an array containing all data that *might* be used in the report
	 * post. The tokens will be replaced later on
	 * @param	Array	$tokens	An array that will be filled with the token replacements for this report
	 * @param	Array	$report	An array containing the report data
	 * @return	void
	 */
	public function prepare_tokens(&$tokens, $report)
	{
		if (!function_exists('get_username_string'))
		{
			require PHPBB_ROOT_PATH . 'includes/functions_content.' . PHP_EXT;
		}

		// Build the data
		$reporter		= get_username_string('username', $report['user_id'], $report['username'], $report['user_colour']);
		$reporter_full	= get_username_string('full', $report['user_id'], $report['username'], $report['user_colour']);
		$report_reason	= censor_text($report['reason_title']);
		$report_text	= censor_text($report['report_text']);
		$report_time	= $this->user->format_date($report['report_time']);
		$title			= (isset($report['post_id'])) ? censor_text($report['post_subject']) : censor_text($report['message_subject']);

		$report_link_params = array(
			'i'		=> (isset($report['post_id']))  ? 'reports' : 'pm_reports',
			'mode'	=> (isset($report['post_id']))  ? 'report_details' : 'pm_report_details',
			'r'		=> $report['report_id'],
		);
		$report_link = append_sid(generate_board_url() . '/mcp.' . PHP_EXT, $report_link_params);

		// Fill the array
		$tokens = array(
			'REPORTER'		=> $reporter,
			'REPORTER_FULL'	=> $reporter_full,
			'REPORT_LINK'	=> $report_link,
			'REPORT_REASON'	=> $report_reason,
			'REPORT_TEXT'	=> $report_text,
			'REPORT_TIME'	=> $report_time,
			'TITLE'			=> $title,
		);

		if (isset($report['post_id']))
		{
				$report_post_link_params = array(
				'p'	=> $report['post_id'],
				'#'	=> 'p' . $report['post_id'],
			);
			$report_post_link = append_sid(generate_board_url() . '/viewtopic.' . PHP_EXT, $report_post_link_params);

			$tokens['REPORT_POST'] = $report_post_link;
		}
	}


	//-- Magic methods
	/**
	 * Is triggered when your class instance (and inherited classes) does not contain the member or method name
	 * @param	String	$name	The name of the requested member
	 * @return	mixed	Value of the requested member
	 */
	public function __get($name)
	{
		// phpBB objects are returned always
		if (in_array($name, array('auth', 'cache', 'config', 'db', 'template', 'user')))
		{
			return $this->$name;
		}
	}
}