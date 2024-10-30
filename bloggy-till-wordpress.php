<?php
/*
 Plugin Name: Bloggy till WordPress
 Plugin URI: http://borjablogga.se/bloggy-till-wordpress/
 Description: Swedish only! Importerar uppdateringar fr&aring;n mikrobloggar p&aring; Bloggy.se.
 Version: 2.1.1
 Author: David M&aring;rtensson
 Author URI: http://www.feedmeastraycat.net/
 */

/*  Copyright 2009  David Mårtennsson  (email: david.martensson@gmail.com)
	
	The content of this program is:
		bloggy-till-wordpress.php (this file)
		bloggy-till-wordpress-admin.css
		bloggy-till-wordpress-admin.js

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/*	Todo:

	Future features :)
	- Add option to import all Bloggy posts once/day to one big post. Instead of one
	  blog post per bloggy post. Requires better Bloggy API.
	- Import Bloggy comments to blog comments. Requires thread rss from Bloggy. 
*/


/**
 * Bloggy class
 */
class BloggyTWP {
	

	// Constant configs
	const VERSION = "2.1.1";
	const DB_POSTS_TABLE = "bloggytwp_posts";
	const DB_ACCOUNTS_TABLE = "bloggytwp_acounts";
	const BLOGGY_RSS_URL = "http://%ACCOUNT_NAME%.bloggy.se/rss?onlyposts=%ONLY_POSTS%&rssimports=no";
	const BLOGGY_API1_URL = "http://bloggy.se/api?login=%ACCOUNT_NAME%&p=%ACCOUNT_PASSWORD%&content=%CONTENT%&type=post";
	
	
	// Predef vars which is set in different functions
	public static $PLUGIN_URL;
	public $accounts = array();
	public $posts_fetched_on_last_run = 0;
	
	
	// Default config (if DB is empty, fall back to this)
	public static $default_config = array(
		'check_for_new_posts_interval' => 600, // No of seconds between each check for new posts at Bloggy
		'check_for_new_posts_last' => 0, // By default, no check has been made so new posts will be downloaded.
		'blog_post_category' => 0, // Default blog category to post to
		'blog_post_author' => 0, // Default author to post using 
		'skip_remove_memory_on_delete' => 1, // Skip remove from bloggy plugin post table when WP deletes a post
		'feed_output_posts' => 1, // Should Bloggy added posts be outputed in the rss/atom feeds
		'import_only_posts' => 1, // Skip replies on bloggy import
		'automatic_title_length' => 0, // Automaticly short title to number of characters
		'import_only_using_autoscript' => 0, // Import only using autoscript (not by visitors visiting the blog) See bloggy_actions() "automatic_update"
		'link_post_to_original_location' => 1, // Create link to the post orginal location at www.bloggy.se?
		'notify_on_new_post' => 0, // Send post to Bloggy on new blog post
		'notify_content_type' => 'headline', // What content type to choose from when sending from WP to Bloggy
		'notify_with_url' => 1, // Include shorten url in the end of the post when sending to Bloggy
		'import_to_blog' => 1, // Import bloggy posts (was on by default befor 2.0.0), defaults to yes.
	);
	
	
	// These are set by __construct() and are saved in WP db by add_option() ("bloggyTWP_+ var")
	public $check_for_new_posts_interval;
	public $check_for_new_posts_last;
	public $blog_post_category;
	public $blog_post_author;
	public $skip_remove_memory_on_delete;
	public $feed_output_posts;
	public $automatic_title_length;
	public $import_only_using_autoscript;
	public $link_post_to_original_location;
	public $notify_on_new_post;
	public $notify_content_type;
	public $notify_with_url;
	public $import_to_blog;
	
	
	
	/**
	 * Create new Bloggy object
	 */
	public function __construct() {
		global $wpdb;
		
		// Settings
		self::$PLUGIN_URL = WP_CONTENT_URL."/plugins/" . plugin_basename(dirname(__FILE__));
		
		// Check plugin version
		$installed_version = get_option('BloggyTWP_version', '1.0.0');
		if (version_compare($installed_version, self::VERSION, '<')) {
			self::__upgrade($installed_version);
		}
		
		// Get config
		foreach (self::$default_config AS $key => $value) {
			// Exists in db already?
			$set_value = get_option('BloggyTWP_'.$key);
			// Set default
			if (!isset($set_value)) {
				$set_value = $value;
				add_option('BloggyTWP_'.$key, $value);
				self::debug('Create missing db config key: '.$key);
			}
			// Set to object
			$this->$key = $set_value;
		}
		
	}
	
	
	
	// Publics
	
	
	
	/**
	 * Init admin page
	 */
	public function initAdminPage() {
		global $wpdb;
		
		// Get accounts
		$this->__updateAccounts();
		
	}
	
	
	/**
	 * Update config
	 * @param string $key
	 * @param string $value
	 * @return bool
	 */
	public function updateConfig($key, $value) {
		global $wpdb;
		$this->$key = $update;
		return update_option('BloggyTWP_'.$key, $value);
	}
	
	
	/**
	 * Update accounts
	 * @param array $accounts
	 */
	public function updateAccounts($accounts) {
		global $wpdb;
		foreach ($accounts AS $index => $account) {
			// Set new name and active status
			$cols = array(
				'name' => $wpdb->escape($account->name),
				'active' => $account->active
			);
			// Set password?
			if ($account->password != "********") {
				$cols['password'] = $wpdb->escape($account->password);
			}
			// Create account
			if (empty($account->id)) {
				$sql = "
					INSERT INTO `".self::getTableName('accounts')."`
					(name, password, active)
					VALUES
					('".$cols['name']."', '".$cols['password']."', '".$cols['active']."') 
				";
				$wpdb->query($sql);
			}
			// Delete account
			elseIf ($account->delete) {
				$sql = "DELETE FROM `".self::getTableName('accounts')."` WHERE id='".$account->id."'";
				$wpdb->query($sql);
			}
			// Update account
			else {
				$sql = "UPDATE `".self::getTableName('accounts')."` SET ";
				foreach ($cols AS $col => $value) {
					$sql .= "`".$col."`='".$value."', ";
				}
				$sql = substr($sql, 0, strlen($sql)-2);
				$sql .= " WHERE id='".$account->id."'";
				$wpdb->query($sql);
			}
		}
		$this->__updateAccounts();
	}
	
	
	/**
	 * Fetch new posts
	 */
	public function fetchPosts() {
		global $wpdb;
		
		self::debug('Fetch posts');
		$this->__updateAccounts();
		$this->updateConfig('check_for_new_posts_last', time());
		$this->posts_fetched_on_last_run = 0;
		
		// Start looping through accounts 
		foreach ($this->accounts AS $account) {
			
			// Not active, skip
			if (!$account->active) {
				continue;
			}
			
			// Get posts
			$return = $this->__getPostsFromBloggy($account);
			if (!empty($return['posts'])) {
					
				// Get posts from db with ids from xml (to see which alread are posted)
				array_walk($return['post_ids'], "BloggyTWP_array_walk_fix_guid");
				$sql = "SELECT post_id, link FROM `".self::getTableName('posts')."` WHERE post_id IN (".implode(",", $return['post_ids']).")";
				$db_posts = $wpdb->get_results($sql);
				$db_post_ids = array();
				$db_post_links = array();
				if (!empty($db_posts)) {
					foreach ($db_posts AS $db_post) {
						$db_post_ids[] = $db_post->post_id;
						$db_post_links[$db_post->post_id] = $db_post->link;
					}
				}
				
				// Insert posts not already inserted
				foreach ($return['posts'] AS $post) {
					
					// Already imported
					if (in_array($post->id, $db_post_ids)) {
						// Update missing link
						if (empty($db_post_links[$post->id])) {
							$wpdb->update(self::getTableName('post'), array('link' => $post->link), array('post_id' => $post->id));
						}
						// Skip to next
						continue;
					}
					
					// Insert to own table
					$insert_sql = "
						INSERT INTO `".self::getTableName('post')."`
						(
							post_id,
							timestamp,
							link
						)
						VALUES
						(
							'".$wpdb->escape($post->id)."',
							'".(int)$post->timestamp."',
							'".$wpdb->escape($post->link)."'
						)
					";
					$result = $wpdb->query($insert_sql);
					
					// Success
					if ($result) {
						// Insert to WP table
						$inserted = $this->__insertPost($post);
						if ($inserted) {
							self::debug('Inserted post "'.$post->id.'"');
							$this->posts_fetched_on_last_run++;
						}
						else {
							$wpdb->get_results("DELETE FROM `".self::getTableName('posts')."` WHERE post_id='".$post->id."'");
						}
					}
					
				}
				
				unset($db_post_links);
				
			}
			
		}
		
	}
	
	
	/**
	 * Post to Bloggy (if not already posted)
	 * @param object $post
	 */
	public function sendToBloggy($post) {
		global $BloggyTWP;
		// Get content
		$content = "";
		switch (get_post_meta($post->ID, 'BloggyTWP_notify_content_type', true)) {
			case "content":
				$content = $post->post_content;
			break;
			case "excerpt":
				$content = $post->post_excerpt;
			break;
			default:
				$content = $post->post_title;
			break;
		}
		if (empty($content)) {
			return false;
		}
		// Url?
		$short_url = "";
		if (get_post_meta($post->ID, 'BloggyTWP_notify_with_url', true) == "1") {
			$short_url = self::__shortenUrl(get_permalink($post->ID));
		}
		// Has url
		if (empty($short_url)) {
			if (strlen($content) > 140) {
				$content = substr($content, 0, 136)." ...";
			}
		}
		// Only content
		else {
			if ((strlen($content) + strlen($short_url) + 1) > 140) {
				$content = substr($content, 0, (136-strlen($short_url)));
			}
			$content .= "... ".$short_url;
		}
		// Send to accounts
		$only_to = get_post_meta($post->id, 'BloggyTWP_notify_with_account', true);
		foreach ($this->accounts AS $account) {
			if (!empty($account->password) && $account->active && (empty($only_to) || $account->id == $only_to)) {
				$guid = self::__sendToBloggy($content, $account);
				if (!empty($guid)) {
					$this->__addSentPost($post, $guid);
				}
			}
		}
	}
	
	
	
	// Privates
	
	
	
	/**
	 * Update accounts
	 */
	private function __updateAccounts() {
		global $wpdb;
		$this->accounts = $wpdb->get_results("SELECT * FROM `".self::getTableName("accounts")."` ORDER BY id ASC");
	}
	
	
	/**
	 * Insert post
	 */
	private function __insertPost($post) {
		global $wpdb;
		$post_data = array(
			'post_content' => $this->__fixForContent($post),
			'post_title' => $this->__fixForTitle($wpdb->escape($post->title)),
			'post_date' => date('Y-m-d H:i:s', $post->timestamp),
			'post_category' => array($this->blog_post_category),
			'post_status' => 'publish',
			'post_author' => 1
		);
		$post_id = wp_insert_post($post_data);
		if (!empty($post_id)) {
			add_post_meta($post_id, 'BloggyTWP_post_guid', $post->id, true);
			return true;
		}
		else {
			return false;
		}
	}
	
	
	/**
	 * Fix bloggy post for title
	 * @param string $post
	 * @return string
	 */
	private function __fixForTitle($string) {
		if (!empty($this->automatic_title_length) && strlen($string) > ($this->automatic_title_length+3)) {
			$return = substr($string, 0, $this->automatic_title_length)."...";
		}
		else {
			$return = $string;
		}
		return $return;
	}
	
	
	/**
	 * Fix bloggy post for content
	 * @param object $post
	 * @return string
	 */
	private function __fixForContent($post) {
		global $wpdb;
		if ($this->link_post_to_original_location) {
			$return = "<a href=\"".$post->link."\" class=\"BloggyTWP-link\">".$wpdb->escape($post->title)."</a>";
		}
		else {
			$return = $wpdb->escape($post->title);
		}
		return $return;
	}
	
	
	/**
	 * Get post array (of objects). Returns empty on false
	 * @return array
	 */
	private function __getPostsFromBloggy($account) {
		global $BloggyTWP;
		$posts = array();
		$post_ids = array();
		
		// Update url
		$url = str_replace("%ACCOUNT_NAME%", $account->name, self::BLOGGY_RSS_URL);
		$url = str_replace("%ONLY_POSTS%", ($BloggyTWP->import_only_posts ? "yes":"no"), $url);
		
		// Get data
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_USERAGENT, "PHP ".phpversion()."/cURL BloggyTWP-WP-Plugin-".self::VERSION);
		$return = curl_exec($ch);
		$errno = curl_errno($ch);
		curl_close($ch);
		
		// No error reading RSS XML
		if ($errno == 0) {
			
			// Get XML object
			$xml = @simplexml_load_string($return);
			//print_r($xml);die;
			if (is_object($xml) && !empty($xml->channel->item)) {

				// Get posts
				foreach ($xml->channel->item AS $post) {
					$post_ids[] = (string)$post->guid;
					$ins_post = new stdClass;
					$ins_post->id = (string)$post->guid;
					$ins_post->title = (string)$post->title;
					$ins_post->timestamp = strtotime((string)$post->pubDate);
					$ins_post->link = (string)$post->link;
					$posts[] = $ins_post;
				}
				
			}
			
		}
		
		// Return
		return array('posts' => $posts, 'post_ids' => $post_ids);
	}
	
	
	/**
	 * Insert post that was sent to bloggy
	 * @param object $post
	 * @param string $guid Inserted as post_id
	 */
	private function __addSentPost($post, $guid) {
		global $wpdb;
		$sql = "
			INSERT INTO `".self::getTableName('post')."`
			(
				post_id,
				wp_post_id,
				timestamp
			)
			VALUES
			(
				'".$wpdb->escape($guid)."',
				'".(int)$post->ID."',
				'".time()."'
			)
		";
		$result = $wpdb->query($sql);
		if ($result) {
			// Also add guid to post meta data
			add_post_meta($post->ID, 'BloggyTWP_post_guid', $guid, true);
		}
	}
	
	
	
	// Public static
	

	
	/**
	 * Install Bloggy plugin (on Activate in WP Admin)
	 */
	public static function install() {
		global $wpdb;
		
		// Get installed DBs
		$db_tables = $wpdb->get_col("SHOW TABLES");

		// Install DB
		$charset_collate = '';
		if ( version_compare(mysql_get_server_info(), '4.1.0', '>=') ) {
			if (!empty($wpdb->charset)) {
				$charset_collate .= " DEFAULT CHARACTER SET $wpdb->charset";
			}
			if (!empty($wpdb->collate)) {
				$charset_collate .= " COLLATE $wpdb->collate";
			}
		}
		// Create posts DB
		if (!in_array(self::getTableName('posts'), $db_tables)) {
			$sql = "
				CREATE TABLE `".self::getTableName('posts')."` (
					`id` INT( 10 ) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
					`post_id` VARCHAR( 255 ) NOT NULL ,
					`wp_post_id` BIGINT UNSIGNED NOT NULL DEFAULT '0' ,
					`timestamp` INT UNSIGNED NOT NULL ,
					`link` VARCHAR( 255 ) NOT NULL ,
					INDEX ( `post_id`, `wp_post_id` )
				) ".$charset_collate."
			";
			$result = $wpdb->query($sql);
			if ($result === false) {
				return false;
			}
		}
		// Create accounts DB
		if (!in_array(self::getTableName('accounts'), $db_tables)) {
			$sql = "
				CREATE TABLE `".self::getTableName('accounts')."` (
					`id` INT( 10 ) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
					`name` VARCHAR( 255 ) NOT NULL ,
					`password` VARCHAR( 255 ) NULL DEFAULT NULL ,
					`active` ENUM( '0', '1' ) NOT NULL DEFAULT '0' ,
					INDEX ( `name` )
				) ".$charset_collate."
			";
			$result = $wpdb->query($sql);
			if ($result === false) {
				$sql = "DROP TABLE `".self::getTableName('posts')."` ";
				$result = $wpdb->query($sql);
				return false;
			}
		}
		
		// Save version
		if (!update_option('BloggyTWP_version', self::VERSION)) {
			add_option('BloggyTWP_version', self::VERSION);
		}
		
		// Check config DB
		foreach (self::$default_config AS $key => $value) {
			self::debug('Check config db key: '.$key);
			// Exists in db already?
			$db_value = get_option('BloggyTWP_'.$key);
			// Set default
			if (empty($db_value)) {
				if (!update_option('BloggyTWP_'.$key, $value)) {
					add_option('BloggyTWP_'.$key, $value);
				}
			}
		}
			
	}

	
	/**
	 * Check if Bloggy plugin is installed
	 * @return bool
	 */
	public static function installed() {
		global $wpdb;
		$db_tables = $wpdb->get_col("SHOW TABLES");
		$plugin_tables = array();
		$plugin_tables[] = self::getTableName('posts');
		$plugin_tables[] = self::getTableName('accounts');
		foreach ($plugin_tables AS $table) {
			if (!in_array($table, $db_tables)) {
				return false;
			}
		}
		return true;
	}

	
	/**
	 * Get WP table name
	 * @param mixed $table
	 * @return string
	 */
	public static function getTableName($table='') {
		global $wpdb;
		switch ($table) {
			case "account":
			case "accounts":
				return $wpdb->prefix.self::DB_ACCOUNTS_TABLE;
			break;
			case "post":
			case "posts":
			default:
				return $wpdb->prefix.self::DB_POSTS_TABLE;
			break;
		}
	}

	
	/**
	 * Debug. Just output whatever is given to it. Output will be
	 * removed when I dont want to debug anymore. Simple, eh?!
	 * @param string $string
	 */
	public static function debug($string) {
		if ($_SERVER['HTTP_HOST'] == "feedmeastraycat.no-ip.net") {
			//echo $string."<hr/>";
		}
	}
	
	
	
	// Private statics
	
	
	
	/**
	 * Upgrade Bloggy till WordPress
	 */
	private static function __upgrade($installed_version) {
		global $wpdb;
		// 1.x.x to 2.0.0 upgrade
		if (version_compare($installed_version, '2.0.0', '<')) {
			$wpdb->query("
				ALTER TABLE `".self::getTableName('posts')."`
				ADD `wp_post_id` BIGINT UNSIGNED NOT NULL DEFAULT '0' 
				AFTER `post_id`
			");
			$wpdb->query("
				ALTER TABLE `".self::getTableName('posts')."`
				ADD INDEX ( `wp_post_id` )
			");
			$wpdb->query("
				ALTER TABLE `".self::getTableName('accounts')."`
				ADD `password` VARCHAR( 255 ) NULL DEFAULT NULL
				AFTER `name`
			");
		}
		
		// Check config DB
		foreach (self::$default_config AS $key => $value) {
			self::debug('Check config db key: '.$key);
			// Exists in db already?
			$db_value = get_option('BloggyTWP_'.$key);
			// Set default
			if (empty($db_value)) {
				if (!update_option('BloggyTWP_'.$key, $value)) {
					add_option('BloggyTWP_'.$key, $value);
				}
			}
		}
		
		// Uppdate/Add version
		if (!update_option('BloggyTWP_version', self::VERSION)) {
			add_option('BloggyTWP_version', self::VERSION);
		}
	}
	
	
	/**
	 * Shorten url with is.gd
	 * @param string $url
	 * @return string
	 */
	private static function __shortenUrl($url) {
		if (empty($url)) {
			return "";
		}
		// Call api using cURL
		$call_url = "http://is.gd/api.php?longurl=".urlencode($url);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $call_url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_USERAGENT, "PHP ".phpversion()."/cURL BloggyTWP-WP-Plugin-".self::VERSION);
		$return = curl_exec($ch);
		$errno = curl_errno($ch);
		curl_close($ch);
		
		// Return ok
		if ($errno == 0 || strpos(strtolower($return), "error") !== false) {
			$return_url = $return;
			$return_error = "";
		}
		else {
			$return_url = "";
			$return_error = $return;
		}
		
		// Return
		return $return_url;
	}

	
	/**
	 * Send to Bloggy using cURL
	 * @param string $content
	 * @param object $account
	 * @return string
	 */
	private static function __sendToBloggy($content, $account) {
		if (empty($content)) {
			return "";
		}
		
		$url = str_replace("%ACCOUNT_NAME%", $account->name, self::BLOGGY_API1_URL);
		$url = str_replace("%ACCOUNT_PASSWORD%", $account->password, $url);
		$url = str_replace("%CONTENT%", urlencode($content), $url);
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 0);
		curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
		curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_USERAGENT, "PHP ".phpversion()."/cURL BloggyTWP-WP-Plugin-".self::VERSION);
		$return = curl_exec($ch);
		$errno = curl_errno($ch);
		curl_close($ch);
		
		// Return ok
		if ($errno == 0 && strpos($return, "OK: post created") !== false) {
			$temp = explode("OK: post created with guid", $return);
			$guid = trim($temp[1]);
			return $guid;
		}
		
		return "";
	}
	
}



// Output functions



/**
 * Output bloggy options page
 */
function BloggyTWP_options_page() {
	global $BloggyTWP;
	
	// Choose category options html
	$category_options = "";
	$categories = get_categories('hide_empty=0');
	$selected_category = ($BloggyTWP->blog_post_category ? $BloggyTWP->blog_post_category:get_option('default_category'));
	foreach ($categories as $category) {
		$category_options .= '<option value="'.$category->cat_ID.'" '.($category->cat_ID == $selected_category ? 'selected="selected"':'').'>'.$category->name.'</option>';
	}
	
	// Choose author options html
	$author_options = "";
	$authors = get_users_of_blog();
	foreach ($authors AS $user) {
		$usero = new WP_User($user->user_id);
		$author = $usero->data;
		$author_options .= '<option value="'.$author->ID.'" '.($author->ID == $BloggyTWP->blog_post_author ? 'selected="selected"':'').'>'.$author->user_nicename.' ("'.$author->nickname.'")</option>';
	}
	
	// Check if any active accounts (and with password exists)
	$active_accounts = false;
	$active_accounts_with_pass = false;
	foreach ($BloggyTWP->accounts AS $account) {
		if ($account->active) {
			$active_accounts = true;
		}
		if ($account->active && !empty($account->password)) {
			$active_accounts_with_pass = true;
		}
	}
	
	// Get options page html
	?>
	<div class="wrap" id="BloggyTWP-options">
		<h2>Bloggy till WordPress-inst&auml;llningar</h2><?php
		if ($BloggyTWP->import_to_blog && !$active_accounts) {
			?>
			<div class="error">
				<p>
					<strong>Inga aktiva konton!</strong>
					Du har st&auml;llt in att h&auml;mta fr&aring;n Bloggy till din blogg men du har inga aktiva konton.
				</p>
			</div>
			<?php
		}
		if ($BloggyTWP->notify_on_new_post && !$active_accounts_with_pass) {
			?>
			<div class="error">
				<p>
					<strong>Inga aktiva konton med l&ouml;senord!</strong>
					Du har st&auml;llt in att inl&auml;gg ska skickas till Bloggy n&auml;r du skriver men
					du har inga aktiva konton med l&ouml;senord inlagt.
				</p>
			</div>
			<?php
		}
		?>
		<p>
			<strong>Introduktion</strong><br/>
			Pluginen fungerar s&aring; att varje g&aring;ng en sida laddas i WordPress, s&aring; kollar den mot databasen
			n&auml;r senaste uppdateringen gjordes och senaste inl&auml;ggen h&auml;mtades fr&aring;n Bloggy. Om den tiden, plus
			antal sekunder i <em>Uppdateringsfrekvens</em>, &auml;r mindre &auml;n vad tiden &auml;r n&auml;r scriptet k&ouml;rs
			s&aring; h&auml;mtas de senaste inl&auml;ggen och l&auml;ggs in i din blogg.
		</p>
		<p>
			<strong>Tidsinst&auml;lld importering</strong><br/>
			F&ouml;r n&auml;rvarande s&aring; kan pluginen bara h&auml;mta de <strong>senaste 20</strong> inl&auml;ggen. S&aring; om
			du har f&aring; bes&ouml;kare, eller skriver v&auml;ldigt m&aring;nga inl&auml;gg, s&aring; kan det betyda att inl&auml;gg
			aldrig h&auml;mtas in. Om du hinner skriva 21 inl&auml;gg innan uppdatering sker s&aring; kommer allts&aring;
			ett inl&auml;gg att missas. Detta kan du l&ouml;sa om du har m&ouml;jlighet att s&auml;tta upp ett automatiskt
			script som pekar mot <strong><?=get_option('siteurl')?>/?BloggyTWP_action=automatic_update</strong><br/>
		</p>
		<p>
			<strong>Skicka till Bloggy</strong><br/>
			Om du vill s&aring; kan pluginen skicka inl&auml;gg du skriver till ditt Bloggy-konto.
			F&ouml;r att det ska fungera s&aring; m&aring;ste du ange l&ouml;senord p&ouml; de konton
			du vill kunna skriva till samt kryssa i rutan <em>Skicka till Bloggy</em>.
		</p>
		<p>
			L&auml;s mer p&aring; <a href="http://borjablogga.se/bloggy-till-wordpress/">http://borjablogga.se/bloggy-till-wordpress/</a>
		</p>
		<p>
			<input 
				type="button" 
				name="ToggleAdvanceSettings"
				id="BloggyTWP-toggle-advance-settings-show" 
				class="button-secondary" 
				value="Visa avancerade inst&auml;llningar"
				onclick="javascript:BloggyTWP.toggleAdvanceSettings(1);" 
			/><input 
				type="button" 
				name="ToggleAdvanceSettings"
				id="BloggyTWP-toggle-advance-settings-hide" 
				class="button-secondary" 
				value="G&ouml;m avancerade inst&auml;llningar"
				onclick="javascript:BloggyTWP.toggleAdvanceSettings(0);"
				style="display: none;" 
			/>
		</p>
		<form method="post" action="options-general.php">
		<input type="hidden" name="BloggyTWP_action" value="BloggyTWP_update_options" />
		<h3 style="margin-bottom: 0px;">Import-inst&auml;llningar</h3>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><label for="notify_on_new_post">H&auml;mta fr&aring;n Bloggy</label></th>
				<td>
					<input
						name="import_to_blog" 
						type="checkbox"
						id="import_to_blog" 
						value="1"
						<?=($BloggyTWP->import_to_blog ? 'checked="checked"':'')?>
					/> <label for="import_to_blog">Ja</label>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="blogname">Uppdateringsfrekvens</label></th>
				<td>
					<input 
						name="check_for_new_posts_interval" 
						type="text" 
						id="check_for_new_posts_interval" 
						value="<?=$BloggyTWP->check_for_new_posts_interval?>" 
						class="small-text" 
					/> <span class="setting-description">(sekunder)</span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="blog_post_category">Posta till kategori</label></th>
				<td>
					<select name="blog_post_category" id="blog_post_category">
						<?=$category_options?>
					</select>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="blog_post_author">V&auml;lj f&ouml;rfattare</label></th>
				<td>
					<select name="blog_post_author" id="blog_post_author">
						<?=$author_options?>
					</select>
				</td>
			</tr>
			<tr valign="top" class="BloggyTWP-advance-settings" style="display: none;">
				<th scope="row"><label for="import_only_posts">H&auml;mta bara mina inl&auml;gg</label></th>
				<td>
					<input
						name="import_only_posts" 
						type="checkbox"
						id="import_only_posts" 
						value="1"
						<?=($BloggyTWP->import_only_posts ? 'checked="checked"':'')?>
					/> <label for="import_only_posts"><span class="setting-description">(Avmarkera f&ouml;r att h&auml;mta b&aring;de dina
					inl&auml;gg och svar p&aring; andras inl&auml;gg.)</span></label><br/>
				</td>
			</tr>
			<tr valign="top" class="BloggyTWP-advance-settings" style="display: none;">
				<th scope="row"><label for="import_only_posts">H&auml;mta bara med script</label></th>
				<td>
					<input
						name="import_only_using_autoscript" 
						type="checkbox"
						id="import_only_using_autoscript" 
						value="1"
						<?=($BloggyTWP->import_only_using_autoscript ? 'checked="checked"':'')?>
					/> <label for="import_only_using_autoscript"><span class="setting-description">(H&auml;mta bara inl&auml;gg med hj&auml;lp 
					av URL beskriven i <strong>Tidsinst&auml;lld importering</strong>.)</span></label><br/>
				</td>
			</tr>
			<tr valign="top" class="BloggyTWP-advance-settings" style="display: none;">
				<th scope="row"><label for="automatic_title_length">Korta automatiskt titeln till</th>
				<td>
					<input 
						name="automatic_title_length" 
						type="text" 
						id="automatic_title_length" 
						value="<?=(int)$BloggyTWP->automatic_title_length?>" 
						class="small-text" 
					/> tecken <label for="automatic_title_length"><span class="setting-description">(0 eller tomt f&ouml;r ingen automatisk avkortning)</span></label>
				</td>
			</tr>
			<tr valign="top" class="BloggyTWP-advance-settings" style="display: none;">
				<th scope="row"><label for="import_only_posts">L&auml;nka automatiskt</label></th>
				<td>
					<input
						name="link_post_to_original_location" 
						type="checkbox"
						id="link_post_to_original_location" 
						value="1"
						<?=($BloggyTWP->link_post_to_original_location ? 'checked="checked"':'')?>
					/> <label for="link_post_to_original_location"><span class="setting-description">(Skapar automatiskt en l&auml;nk till inl&auml;ggets orginalplats
					p&aring; www.bloggy.se. L&auml;nkarna f&aring;r css-klassen <strong>BloggyTWP-link</strong>.)</span></label><br/>
				</td>
			</tr>
		</table>
		<h3 style="margin-bottom: 0px;">WordPress till Bloggy-inst&auml;llningar</h3>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><label for="notify_on_new_post">Skicka till Bloggy</label></th>
				<td>
					<input
						name="notify_on_new_post" 
						type="checkbox"
						id="notify_on_new_post" 
						value="1"
						<?=($BloggyTWP->notify_on_new_post ? 'checked="checked"':'')?>
					/> <label for="notify_on_new_post">Ja</label>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">Inneh&aring;ll att skicka</th>
				<td>
					<input
						name="notify_content_type" 
						type="radio"
						id="notify_content_type_headline" 
						value="headline"
						<?=($BloggyTWP->notify_content_type == "headline" || empty($BloggyTWP->notify_content_type) ? 'checked="checked"':'')?>
					/> <label for="notify_content_type_headline">Rubrik <em>(Headline)</em></label><br/>
					<input
						name="notify_content_type" 
						type="radio"
						id="notify_content_type_excerpt" 
						value="excerpt"
						<?=($BloggyTWP->notify_content_type == "excerpt" ? 'checked="checked"':'')?>
					/> <label for="notify_content_type_excerpt">Utdrag <em>(Excerpt)</em></label><br/>
					<input
						name="notify_content_type" 
						type="radio"
						id="notify_content_type_content" 
						value="content"
						<?=($BloggyTWP->notify_content_type == "content" ? 'checked="checked"':'')?>
					/> <label for="notify_content_type_content">Inneh&aring;ll</label><br/>
					<span class="setting-description">Oavsett vad du v&auml;ljer s&aring; kortas inneh&aring;llet ned till max
					140 tecken, inklusive eventuell f&ouml;rkortad l&auml;nk till inl&auml;gget.</span>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><label for="notify_with_url">Skicka med l&auml;nk</label></th>
				<td>
					<input
						name="notify_with_url" 
						type="checkbox"
						id="notify_with_url" 
						value="1"
						<?=($BloggyTWP->notify_with_url ? 'checked="checked"':'')?>
					/> <label for="notify_with_url">Ja</label><br/> 
					<span class="setting-description">Skickar med en f&ouml;rkortad l&auml;nk (<a href="http://is.gd" target="_blank">is.gd</a>)
					i slutet av inl&auml;gget.</span><br/>
				</td>
			</tr>
		</table>
		<div class="BloggyTWP-advance-settings" style="display: none;">
			<h3 style="margin-bottom: 0px;">N&auml;r ett inl&auml;gg tas bort</h3>
			<p><em>Observera att detta bara fungerar n&auml;r Bloggy-pluginen &auml;r aktiverad!</em></p>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="skip_remove_memory_on_delete">Importera ej igen</label></th>
					<td>
						<input
							name="skip_remove_memory_on_delete" 
							type="checkbox"
							id="skip_remove_memory_on_delete" 
							value="1"
							<?=($BloggyTWP->skip_remove_memory_on_delete ? 'checked="checked"':'')?>
						/> <label for="skip_remove_memory_on_delete"><span class="setting-description">(Ser till att bortagna inl&auml;gg fr&aring;n bloggen ej importeras
						igen om de ligger kvar i Bloggy.)</span></label>
					</td>
				</tr>
			</table>
			<h3 style="margin-bottom: 0px;">RSS-inst&auml;llningar</h3>
			<table class="form-table">
				<tr valign="top">
					<th scope="row"><label for="feed_output_posts">Inkludera Bloggy-inl&auml;gg</label></th>
					<td>
						<input
							name="feed_output_posts" 
							type="checkbox"
							id="feed_output_posts" 
							value="1"
							<?=($BloggyTWP->feed_output_posts ? 'checked="checked"':'')?>
						/> <label for="feed_output_posts"><span class="setting-description">(Avmarkera f&ouml;r att ta bort alla inl&auml;gg, i vald Bloggy-kategori,
						fr&aring;n bloggens rss/atom-feeds. Stoppar ocks&aring; WordPress fr&aring;n att pinga via Update Service.)</span></label><br/>
					</td>
				</tr>
			</table>
		</div>
		<h3>Bloggykonton</h3>
		<p>
			L&auml;gg till vilka konton som du vill ska l&auml;ggas in i din blogg. Fyll i ditt
			kontonamn f&ouml;r Bloggy, exempelvis <strong>dmr</strong>.bloggy.se.<br/>
		</p>
		<div id="BloggyTWP-accounts-container">
			<?php
			if (empty($BloggyTWP->accounts)) {
				?>
				<p><em>Inga konton tillagda.</em></p>
				<?php
			}
			else {
				$counter = 1;
				foreach ($BloggyTWP->accounts AS $index => $account) {
					?>
					<div id="BloggyTWP-account-<?=$counter?>" class="BloggyTWP-account">
						<input type="hidden" name="BloggyTWP_account_db_id[<?=$counter?>]" value="<?=$account->id?>" />
						<table class="form-table">
							<tr valign="top">
								<th scope="row"><label for="BloggyTWP_account_name_<?=$counter?>">Kontonamn</label></th>
								<td>
									<input
										name="BloggyTWP_account_name[<?=$counter?>]" 
										type="text"
										id="BloggyTWP_account_name_<?=$counter?>" 
										value="<?=$account->name?>"
										class="large-text"
									/>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="BloggyTWP_account_password_<?=$counter?>">L&ouml;senord</label></th>
								<td>
									<input
										name="BloggyTWP_account_password[<?=$counter?>]" 
										type="password"
										id="BloggyTWP_account_password_<?=$counter?>" 
										value="********"
										class="large-text"
									/> <span class="setting-description">(L&ouml;senordet sparas i klartext i databasen.
									<a href="http://borjablogga.se/bloggy-till-wordpress/">L&auml;s mer om det h&auml;r.</a>)</span>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="BloggyTWP_account_active_<?=$counter?>">Aktiverat</label></th>
								<td>
									<input
										name="BloggyTWP_account_active[<?=$counter?>]" 
										type="checkbox"
										id="BloggyTWP_account_active_<?=$counter?>" 
										value="1"
										<?=($account->active ? 'checked="checked"':'')?>
									/> <label for="BloggyTWP_account_active_<?=$counter?>">Ja</label>
									<div style="float: right;">
										<input
											name="BloggyTWP_account_delete[<?=$counter?>]" 
											type="checkbox"
											id="BloggyTWP_account_delete_<?=$counter?>" 
											value="1"
										/> <label for="BloggyTWP_account_delete_<?=$counter?>">Ta bort konto</label>
									</div>
								</td>
							</tr>
						</table>
					</div>
					<?php
					$counter++;
				}
			}
			?>
		</div>
		<p>
			<input 
				type="button" 
				name="AddAccount" 
				class="button-secondary" 
				value="L&auml;gg till konto"
				onclick="javascript:BloggyTWP.addAccount();" 
			/>
		</p>
		<p class="submit">
			<input type="submit" name="Submit" class="button-primary" value="Spara f&ouml;r&auml;ndringar" />
		</p>
		</form>
	</div>
	<?php
}


/**
 * Print to WP head
 */
function BloggyTWP_admin_head() {
	global $BloggyTWP;
	echo '
		<link rel="stylesheet" href="'.BloggyTWP::$PLUGIN_URL.'/bloggy-till-wordpress-admin.css?ver='.BloggyTWP::VERSION.'" type="text/css" media="all" />
		<script language="javascript" type="text/javascript">
		var BloggyTWP_no_accounts = '.count($BloggyTWP->accounts).';
		</script>
		<script language="javascript" type="text/javascript" src="'.BloggyTWP::$PLUGIN_URL.'/bloggy-till-wordpress-admin.js?ver='.BloggyTWP::VERSION.'"></script>
	';
}
add_action('admin_head', 'BloggyTWP_admin_head');


/**
 * Add check box if new post should be posted to Bloggy
 */
function BloggyTWP_post_options() {
	global $BloggyTWP, $post;
	if ($BloggyTWP->notify_on_new_post) {
		// Auto draft - Force default settings
		if ($post->post_status == "auto-draft") {
			$checked = 'checked="checked"';
			$notify_with_url = $BloggyTWP->notify_with_url;
			$notify_content_type = $BloggyTWP->notify_content_type;
		}
		// Real post - Get post settings, fall back to default settings
		else {
			if (get_post_meta($post->ID, 'BloggyTWP_notify_on_new_post', true) == "0") {
				$checked = '';
			}
			else {
				$checked = 'checked="checked"';
			}
			$notify_with_url = get_post_meta($post->ID, 'BloggyTWP_notify_with_url', true);
			$notify_with_url = ($notify_with_url == "" ? $BloggyTWP->notify_with_url:$notify_with_url);
			$notify_content_type = get_post_meta($post->ID, 'BloggyTWP_notify_content_type', true);
			$notify_content_type = ($notify_content_type == "" ? $BloggyTWP->notify_content_type:$notify_content_type);
		}
		// Check if any active accounts (and with password exists)
		$active_accounts_with_pass = false;
		foreach ($BloggyTWP->accounts AS $account) {
			if ($account->active && !empty($account->password)) {
				$active_accounts_with_pass = true;
			}
		}
		?>
		<div id="BloggyTWPdiv" class="postbox" title="Klicka f&ouml;r att g&ouml;mma" style="cursor: pointer;">
			<h3 class='hndle'><span>Bloggy till WordPress</span></h3>
			<div class="inside">
				<?php
				if (!$active_accounts_with_pass) {
					?>
					<p>
						<strong>Inga aktiva konton med l&ouml;senord! Ditt inl&auml;gg kan ej skickas till Bloggy.</strong>	
					</p>
					<?php
				}
				?>
				<p>
					Om ditt inl&auml;gg &auml;r l&auml;ngr &auml;n 140 tecken 
					s&aring; kommer det att kortas ned.
					<span class="setting-description">P&aring; Bloggy till WordPress-inst&auml;llningarna
					kan du &auml;ndra dessa inst&auml;llningarna permanent.</span>
				</p>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><label for="BloggyTWP_notify_on_new_post">Skicka detta inl&auml;gg till Bloggy</label></th>
						<td>
							<input 
								type="checkbox" 
								name="BloggyTWP_notify_on_new_post" 
								id="BloggyTWP_notify_on_new_post" 
								value="yes"
								<?=$checked?>
							/> <label for="BloggyTWP_notify_on_new_post">Ja</label>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Inneh&aring;ll att skicka</th>
						<td>
							<input
								name="BloggyTWP_notify_content_type" 
								type="radio"
								id="BloggyTWP_notify_content_type_headline" 
								value="headline"
								<?=($notify_content_type == "headline" ? 'checked="checked"':'')?>
							/> <label for="BloggyTWP_notify_content_type_headline">Rubrik <em>(Headline)</em></label><br/>
							<input
								name="BloggyTWP_notify_content_type" 
								type="radio"
								id="BloggyTWP_notify_content_type_excerpt" 
								value="excerpt"
								<?=($notify_content_type == "excerpt" ? 'checked="checked"':'')?>
							/> <label for="BloggyTWP_notify_content_type_excerpt">Utdrag <em>(Excerpt)</em></label><br/>
							<input
								name="BloggyTWP_notify_content_type" 
								type="radio"
								id="BloggyTWP_notify_content_type_content" 
								value="content"
								<?=($notify_content_type == "content" ? 'checked="checked"':'')?>
							/> <label for="BloggyTWP_notify_content_type_content">Inneh&aring;ll</label><br/>
						</td>
					</tr> 
					<tr valign="top">
						<th scope="row"><label for="BloggyTWP_notify_with_url">Skicka med l&auml;nk</label></th>
						<td>
							<input
								name="BloggyTWP_notify_with_url" 
								type="checkbox"
								id="BloggyTWP_notify_with_url" 
								value="1"
								<?=($notify_with_url ? 'checked="checked"':'')?>
							/> <label for="BloggyTWP_notify_with_url">Ja</label><br/>
							<span class="setting-description">Skickar med en f&ouml;rkortad l&auml;nk (<a href="http://is.gd" target="_blank">is.gd</a>)
							i slutet av inl&auml;gget.</span><br/>
						</td>
					</tr>
					<?php
					$active_accounts = 0;
					foreach ($BloggyTWP->accounts AS $account) {
						if ($account->active) {
							$active_accounts++;
						}
					}
					if ($active_accounts > 1) {
						?>
						<tr valign="top">
							<th scope="row"><label for="BloggyTWP_notify_with_account">Begr&auml;nsa till konto</label></th>
							<td>
								<select name="BloggyTWP_notify_with_account" id="BloggyTWP_notify_with_account">
									<option value="0">Nej</option>
									<?php
									foreach ($BloggyTWP->accounts AS $account) {
										if (!$account->active) {
											continue;
										}
										?>
										<option value="<?=$account->id?>"><?=htmlspecialchars($account->name)?></option>
										<?php
									}
									?>
								</select>
							</td>
						</tr>
						<?php
					}
					?>
				</table>
			</div>
		</div>
		<?php
	}
}
add_action('edit_form_advanced', 'BloggyTWP_post_options');



// Bloggy system functions



/**
 * Activation hook
 */
function BloggyTWP_activate() {
	
	// Installed?
	if (!BloggyTWP::installed()) {
		BloggyTWP::debug('install');
		BloggyTWP::install();
	}
	else {
		BloggyTWP::debug('installed');
	}
	
}
register_activation_hook(__FILE__, 'BloggyTWP_activate');


/**
 * Init bloggy, run it at WP action init
 */
function BloggyTWP_init() {
	global $wpdb, $BloggyTWP, $wp_rewrite;

	BloggyTWP::debug('init');
	
	// Startup bloggy
	$BloggyTWP = new BloggyTWP();
	
	// Admin page startup
	if (is_admin()) {
		$BloggyTWP->initAdminPage();
	}
	
	// Run admin action
	if (is_admin() && !empty($_POST['BloggyTWP_action'])) {
		BloggyTWP_admin_actions();
	}
	
	// Run standard actions
	if (!is_admin() && !empty($_GET['BloggyTWP_action'])) {
		BloggyTWP_actions();
	}
	
	// Time to import? (Never import when POST action is in progress)
	if ($BloggyTWP->import_to_blog && !$BloggyTWP->import_only_using_autoscript && ($BloggyTWP->check_for_new_posts_last + $BloggyTWP->check_for_new_posts_interval) < time() && empty($_POST)) {
		$BloggyTWP->fetchPosts();
	}
	
	// Make sure prototype gets loaded (admin page only)
	if (is_admin()) {
		wp_enqueue_script('prototype');
		
		// Global Admin Warning - Install error
		if (!BloggyTWP::installed()) {
			add_action('admin_notices', 'BloggyTWP_admin_warnings__not_installed');
		}
	}
	
	// And last but not least. Clean up. We don't need Bloggy object anymore. :)
	unset($BloggyTWP);

}
add_action('init', 'BloggyTWP_init');

/**
 * Print BloggyTWP warnings
 */
function BloggyTWP_admin_warnings__not_installed() {
	echo "
	<div id='BloggyTWP-warning' class='error'><p><strong>Bloggy till WordPress har uppt&auml;ckt ett problem:</strong> Pluginen kunde inte installeras korrekt. Prova att deaktivera den och aktivera den igen.</p></div>
	";
}


/**
 * Add bloggy to admin menu, un it at WP action admin_menu
 */
function BloggyTWP_admin_menu() {
	if (current_user_can('manage_options')) {
		add_options_page(
			'Bloggy till WordPress',
			'Bloggy till WordPress',
			1,
			basename(__FILE__),
			'BloggyTWP_options_page'
		);
	}
}
add_action('admin_menu', 'BloggyTWP_admin_menu');


/**
 * Deletes a WP post, run it at WP action delete_post 
 * @param int $post_ID
 */
function BloggyTWP_delete_post($post_ID) {
	global $wpdb, $BloggyTWP;
	if (!$BloggyTWP->skip_remove_memory_on_delete) {
		$bloggy_post_id = get_post_meta($post_ID, 'BloggyTWP_post_guid', true);
		if (!empty($bloggy_post_id)) {
			$wpdb->get_results("DELETE FROM `".BloggyTWP::getTableName('posts')."` WHERE post_id='".$wpdb->escape($bloggy_post_id)."'");
			delete_post_meta($post_ID, 'BloggyTWP_post_guid');
		}
	}
}
add_action('delete_post', 'BloggyTWP_delete_post');


/**
 * Fix query output, run on action pre_get_posts
 * @param WP_Query $wp_query
 */
function BloggyTWP_pre_get_posts($wp_query) {
	global $BloggyTWP;
	if ($wp_query->is_feed && !$BloggyTWP->feed_output_posts && !empty($BloggyTWP->blog_post_category)) {
		$wp_query->query_vars['category__not_in'][] = $BloggyTWP->blog_post_category;
	}
}
add_action('pre_get_posts', 'BloggyTWP_pre_get_posts');


/**
 * Action on store post
 *
 * @param int $post_id
 * @param object $post
 * @return bool
 */
function BloggyTWP_store_post($post_id, $post = false) {
	global $BloggyTWP;
	// Do nothing on revisions or empty post data
	if (!$post || $post->post_type == 'revision') {
		return true;
	}
	// Save settings
	$notify = (!empty($_POST['BloggyTWP_notify_on_new_post']) ? "1":"0");
	add_post_meta($post_id, 'BloggyTWP_notify_on_new_post', $notify, true);
	add_post_meta($post_id, 'BloggyTWP_notify_content_type', $_POST['BloggyTWP_notify_content_type'], true);
	add_post_meta($post_id, 'BloggyTWP_notify_with_url', ($_POST['BloggyTWP_notify_with_url'] ? 1:0), true);
	if (!empty($_POST['BloggyTWP_notify_with_account'])) {
		add_post_meta($post_id, 'BloggyTWP_notify_with_account', $_POST['BloggyTWP_notify_with_account'], true);
	}
	// Do on publish
	if ($post->post_status == 'publish' && $notify) {		
		// Publish
		$guid = get_post_meta($post_id, 'BloggyTWP_post_guid', true); // See if already posted to Bloggy
		if (empty($guid)) {
			$BloggyTWP->sendToBloggy($post);
		}
	}
	return true;
}
add_action('draft_post', 'BloggyTWP_store_post', 1, 2);
add_action('publish_post', 'BloggyTWP_store_post', 1, 2);
add_action('save_post', 'BloggyTWP_store_post', 1, 2	);


/**
 * Bloggy admin action
 */
function BloggyTWP_admin_actions() {
	global $wpdb, $BloggyTWP;
	switch ($_POST['BloggyTWP_action']) {
		
		// Update options
		case "BloggyTWP_update_options":
			
			// Update general config
			// - Check for new posts interval
			$BloggyTWP->updateConfig(
				'check_for_new_posts_interval', 
				((int)$_POST['check_for_new_posts_interval'] ? (int)$_POST['check_for_new_posts_interval']:BloggyTWP::$default_config['check_for_new_posts_interval'])
			);
			// - Post to category id
			$BloggyTWP->updateConfig(
				'blog_post_category',
				(int)$_POST['blog_post_category']
			);
			// - Post author id
			$BloggyTWP->updateConfig(
				'blog_post_author',
				(int)$_POST['blog_post_author']
			);
			// - Import only posts, not replies
			$BloggyTWP->updateConfig(
				'import_only_posts',
				($_POST['import_only_posts'] == "1" ? "1":"0")
			);
			// - Import only using autoscript
			$BloggyTWP->updateConfig(
				'import_only_using_autoscript',
				($_POST['import_only_using_autoscript'] == "1" ? "1":"0")
			);
			// - Automatic title shortening
			$BloggyTWP->updateConfig(
				'automatic_title_length',
				(int)$_POST['automatic_title_length']
			);
			// - Automaticly create links to the orginal post at bloggy.se?
			$BloggyTWP->updateConfig(
				'link_post_to_original_location',
				($_POST['link_post_to_original_location'] == "1" ? "1":"0")
			);
			// - Keep plugin memory post on post delete
			$BloggyTWP->updateConfig(
				'skip_remove_memory_on_delete',
				($_POST['skip_remove_memory_on_delete'] == "1" ? "1":"0")
			);
			// - Add bloggy posts to feed
			$BloggyTWP->updateConfig(
				'feed_output_posts',
				($_POST['feed_output_posts'] == "1" ? "1":"0")
			);
			// - Add bloggy posts to feed
			$BloggyTWP->updateConfig(
				'notify_on_new_post',
				($_POST['notify_on_new_post'] == "1" ? "1":"0")
			);
			// - Type of content to send to Bloggy
			$BloggyTWP->updateConfig(
				'notify_content_type',
				$_POST['notify_content_type']
			);
			// - Add bloggy posts to feed
			$BloggyTWP->updateConfig(
				'notify_with_url',
				($_POST['notify_with_url'] == "1" ? "1":"0")
			);
			// - Import posts
			$BloggyTWP->updateConfig(
				'import_to_blog',
				($_POST['import_to_blog'] == "1" ? "1":"0")
			);
			// Update accounts
			if (is_array($_POST['BloggyTWP_account_name'])) {
				$accounts = array();
				foreach ($_POST['BloggyTWP_account_name'] AS $index => $account_name) {
					$account_id = (int)$_POST['BloggyTWP_account_db_id'][$index];
					if (empty($account_name) && empty($account_id)) {
						continue; // Skip new account with empty account name
					}
					$account = new stdClass;
					$account->id = $account_id;
					$account->name = $account_name;
					$account->password = $_POST['BloggyTWP_account_password'][$index];
					$account->active = ($_POST['BloggyTWP_account_active'][$index] == "1" ? "1":"0");
					$account->delete = ($_POST['BloggyTWP_account_delete'][$index] == "1" ? "1":"0");
					$accounts[] = $account;
				}
				$BloggyTWP->updateAccounts($accounts);
			}
			
			// Relocate back
			header("Location: options-general.php?page=".basename(__FILE__)."&updated=true");
			exit;
			
		break;
		
	}
}


/**
 * Bloggy actions (non admin)
 */
function BloggyTWP_actions() {
	global $wpdb, $BloggyTWP;
	switch ($_GET['BloggyTWP_action']) {
		
		// Autoscript update fetch posts
		case "automatic_update":
			if ($BloggyTWP->import_to_blog) {
				$BloggyTWP->fetchPosts();
			}
			print("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
			print("<xml>Bloggy Till Wordpress updated with ".$BloggyTWP->posts_fetched_on_last_run." new posts.</xml>\n");
			die;
		break;
		
	}
}


/**
 * Fix array with guid to fit WHERE post_id IN (here)
 */
function BloggyTWP_array_walk_fix_guid(&$array, $key) {
	$array = "'".$array."'";
}

























?>