<?php
/*
Plugin Name: CF Email This
Plugin URI: http://crowdfavorite.com
Description: Creates a nice 'Email-This' link with hooks to customize to your liking
Version: 1.0 
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

/*
Template Tags:
	cfet_[get_]email_this_link($text, $args);
		$text = the text to have in the link
		$args = array(various configuration options)
*/

// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

// jump through hoops to get a file name and path
if (!defined('PLUGINDIR')) {
	define('PLUGINDIR','wp-content/plugins');
}
if (is_file(trailingslashit(ABSPATH.PLUGINDIR).'cf-email-this.php')) {
	define('CFET_FILE', trailingslashit(ABSPATH.PLUGINDIR).'cf-email-this.php');
}
else if (is_file(trailingslashit(ABSPATH.PLUGINDIR).'cf-email-this/cf-email-this.php')) {
	define('CFET_FILE', trailingslashit(ABSPATH.PLUGINDIR).'cf-email-this/cf-email-this.php');
}

if (!defined('PLUGINDIR')) {
	define('PLUGINDIR','wp-content/plugins');
}

load_plugin_textdomain('cf-email-this');
global $wpdb;
if (isset($wpdb->base_prefix)) {
	define('CFET_TABLE_NAME',$wpdb->base_prefix.'email_this');
}
else {
	define('CFET_TABLE_NAME',$wpdb->prefix.'email_this');
}

/* Should the plugin keep track of the stats?? */
define('CFET_KEEP_STATS', apply_filters('cfet_keep_stats', true));

/* Should the plugin show any admin page? */
/* If you turn off the admin page, you have to filter the BODY and
* 	ALT_BODY */
define('CFET_SHOW_ADMIN_PAGE', apply_filters('cfet_show_admin_page', true));

/* Should the plugin show the post meta box on the post/page edit screen
* 	to allow for a specific post to be shown or not? */
define('CFET_ALLOW_POST_META_BOX', apply_filters('cfet_show_post_meta_box', true));

/**
 * cfet_setup_plugin
 *
 * Creates the table if not already created
 * 
 * @return void
*/
function cfet_setup_plugin() {
	global $wpdb;
	
	if (CFET_KEEP_STATS) {
		/* Only create table if we need stats */
		$charset_collate = '';
		if (version_compare(mysql_get_server_info(), '4.1.0', '>=')) {
			if (!empty($wpdb->charset)) {
				$charset_collate .= " DEFAULT CHARACTER SET $wpdb->charset";
			}
			if (!empty($wpdb->collate)) {
				$charset_collate .= " COLLATE $wpdb->collate";
			}
		}
	
		// make db table now, to hold email info
		$sql = '
			CREATE TABLE IF NOT EXISTS '.CFET_TABLE_NAME.' (
				`id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY, 
				`user_id` BIGINT ( 20 ) NOT NULL ,
				`to_name` VARCHAR( 100 ) NOT NULL ,
				`to_email` VARCHAR( 150 ) NOT NULL ,
				`site_id` BIGINT( 20 ) ,
				`blog_id` BIGINT( 20 ) ,
				`post_id` BIGINT( 20 ) ,
				`time` BIGINT( 20 ) NOT NULL ,
				INDEX blog_id ( `blog_id`, `post_id` ) ,
				INDEX user_id ( `user_id` ) ,
				INDEX to_name ( `to_name` )
			) '.$charset_collate.'
		';
		$result = $wpdb->query($sql);
	}
	
	// Set default options now
	add_option('cfet_email_subject','An article from '.get_option('blogname'));
	add_option('cfet_email_from_name', 'Administrator');
	add_option('cfet_email_from_address', get_bloginfo('admin_email'));
	add_option('cfet_email_body', '###PERSONAL_MESSAGE###');
	add_option('cfet_email_personal_msg_header', 'Here\'s a message from your friend');
	add_option('cfet_popup_who_to_info_header', 'Who are you sending this page to?');
	add_option('cfet_popup_who_from_info_header', 'Tell us about yourself:');
	add_option('cfet_popup_personal_msg_header', 'Personal Message');
}
register_activation_hook(CFET_FILE,'cfet_setup_plugin');

function cfet_request_handler() {
	if (!empty($_GET['cf_action'])) {
		switch ($_GET['cf_action']) {
			case 'cfet_admin_js':
				cfet_admin_js();
				break;
			case 'cfet_admin_css':
				cfet_admin_css();
				die();
				break;
			case 'cfet_change_tb_close_image':
				cfet_change_tb_close_image();
				die();
			case 'email_this_window':
				$cfet_post_id = stripslashes(strip_tags($_GET['cfet_post_id']));
				echo cfet_get_email_this_window($cfet_post_id);	
				die();
				break;
			case 'cfet_download_csv':
				if (isset($_GET['start_year']) && isset($_GET['start_month']) && isset($_GET['end_year']) && isset($_GET['end_month'])) {

					$start_year = strip_tags(stripslashes($_GET['start_year']));
					$start_month = strip_tags(stripslashes($_GET['start_month']));
					$end_year = strip_tags(stripslashes($_GET['end_year']));
					$end_month = strip_tags(stripslashes($_GET['end_month']));
					$start = mktime(0,0,0,$start_month, 1, $start_year);
					$end = mktime(23,59,59,$end_month, date('t',mktime(0,0,0,$end_month, 1, $end_year)), $end_year);
				}
				else {
					$start = '';
					$end = '';
				}
				header("Content-type: text/x-csv"); 
				header("Content-disposition: attachment; filename=email-this-export-".date('Ymd-His',time()).".csv");
				
				echo cfet_compile_csv($start, $end);
				die();
			case 'cfet_paginate_email_review_details':
				$id = $_GET['id'];
				$page = $_GET['page'];
				$results = cfet_retrieve_item_data($id, $page);
				echo cfet_get_render_results($results);
				global $wpmu_version;
				if (isset($wpmu_version)) {
					restore_current_blog();
				}
				die();
		}
	}
	if (!empty($_POST['cf_action'])) {
		switch ($_POST['cf_action']) {
			case 'cfet_update_settings':
				cfet_save_settings();
				wp_redirect(trailingslashit(get_bloginfo('wpurl')).'wp-admin/options-general.php?page=cf-email-this.php&updated=true');
				die();
			case 'cfet_send_email':
				$email_info['to_name'] = stripslashes(strip_tags($_POST['to_name']));
				$email_info['to_email'] = stripslashes(strip_tags($_POST['to_email']));
				$email_info['personal_msg'] = stripslashes(strip_tags($_POST['personal_msg']));
				$email_info['cfet_post_id'] = stripslashes(strip_tags($_POST['cfet_post_id']));
				
				/* Get user info */
				global $user_ID;
				$email_info['user_data'] = get_userdata($user_ID);
				
				/* Gather subject, etc... */
				$email_info['subject'] = get_option('cfet_email_subject');
				$email_info['from_email'] = get_option('cfet_email_from_address');
				$email_info['from_name'] = get_option('cfet_email_from_name');
				

				
				/* Allow to change the email information */
				$email_info = apply_filters('cfet_email_info', $email_info);
				
				/* Put together email's body */
				$email_info['body'] = cfet_make_message(get_option('cfet_email_body'), $email_info, $email_info['user_data']->user_nicename, $email_info['user_data']->user_email);
				$email_info['alt_body'] = cfet_make_message(get_option('cfet_email_body'), $email_info, $email_info['user_data']->user_nicename, $email_info['user_data']->user_email,true);
				
				/* validate then send */
				if (!cfet_validate_fields($email_info)) {
					// results already echo'd in validate function, just need to die
					die();
				}
				if (cfet_send_email($email_info)) {
					if (CFET_KEEP_STATS) {
						cfet_save_email($email_info);
					}
				}
				die();
			case 'cfet_email_review_details':
				$id = $_POST['item_id'];
				$results = cfet_retrieve_item_data($id);
				echo cfet_get_render_results($results);
				global $wpmu_version;
				if (isset($wpmu_version)) {
					restore_current_blog();
				}
				die();

		}
	}
}
add_action('init', 'cfet_request_handler');

function cfet_validate_fields($email_info) {
	if (!is_array($email_info)) {
		echo "There was a problem with the form, please try again later.";
		return false;
	}
	$errors = array();
	
	// validate name
	if (empty($email_info['to_name'])) {
		$errors[] = 'Name field is required';
	}
	
	// validate email address
	if (!cfet_validate_email_address($email_info['to_email'])) {
		$errors[] = 'Valid email address required';
	}
	
	/* Allow other plugins to validate additional fields */
	$errors = apply_filters('cfet_validate_fields', $errors, $email_info);
	
	if (count($errors) == 0) {
		return true;
	}
	$form_html = '
		<form name="email_this_form" id="email_this_form" onsubmit="cfet_jquery_send_email(this.cfet_to_name.value, this.cfet_to_email.value, this.cfet_personal_message.value, this.cfet_post_id.value); return false;">
			<h3>'.htmlspecialchars(get_option('cfet_popup_who_to_info_header')).'</h3>
			<p><label for="cfet_to_name">Name:</label><input type="text" name="cfet_to_name" id="cfet_to_name" value="'.$email_info['to_name'].'" /></p>
			<p><label for="cfet_to_email">Email:</label><input type="text" name="cfet_to_email" id="cfet_to_email" value="'.$email_info['to_email'].'" class="cfet_email" /></p>
			<h3 class="inline-h3">'.htmlspecialchars(get_option('cfet_popup_personal_msg_header')).'</h3><span class="small">(optional)</span>
			<p><textarea name="cfet_personal_message" id="cfet_personal_message">'.$email_info['personal_msg'].'</textarea></p>
			<p><input type="hidden" name="cfet_post_id" value="'.$email_info['cfet_post_id'].'" /></p>
			<p><input type="submit" name="cfet_submit_email_form" id="cfet_submit_email_form" value="send email" />&nbsp;&nbsp;<span class="small">or</span>&nbsp;&nbsp;<a href="cancel" onclick="tb_remove(); return false;"><span class="small">Cancel</span></a></p>
		</form>
	';
	$error_section = '
		<div id="cfet-error-list" class="cfet-error-div">
			<ul>
	';
	foreach ($errors as $error) {
			$error_section .= '<li>'.$error.'</li>';
	}
	$error_section .= '	
			</ul>
		</div>
	';
	$form_html = apply_filters('cfet_invalid_form_html', $form_html, false, false, $email_info['cfet_post_id'], $email_info);
	echo $error_section.$form_html;
	return false;
}
function cfet_validate_email_address($email) {
	// validate email address here
	if (empty($email)) {
		return false;
	}
	
	// more complex email matching
	if (preg_match('|^[a-z0-9]+([\+\._a-z0-9-]+)*@[a-z0-9]+([\._a-z0-9-]+)*(\.[a-z]{2,8})$|i',$email) !== 1) {
		return false;
	}
	
	return true;
}

/*
function cfet_compile_csv($start, $end) {
	global $wpdb, $wpmu_version;
	$conditions = '';	
	if (!empty($start) && !empty($end)) {
		$conditions = 'WHERE time BETWEEN "'.$wpdb->escape($start).'" AND "'.$wpdb->escape($end).'"';
	}
	$sql = '
		SELECT *
		FROM '.CFET_TABLE_NAME.'
		'.$conditions.'
	';
	$results = $wpdb->get_results($sql);
	if (empty($results)) {
		return;
	}
	$output .= '"row_id","user_id","user_name","to_name","to_email",';
	if (isset($wpmu_version)) {
		$output .= '"site_id","site_name","blog_id","blog_name",';
	}
	$output .= '"post_id","post_title","date_sent"'."\n";
	foreach ($results as $result) {	
		if (isset($wpmu_version)) {
			switch_to_blog($result->blog_id);
			$old_site_id = $wpdb->siteid;
			$wpdb->siteid = $result->site_id;
			$site_and_post_fields = ''.
				str_replace('"','\"',$result->site_id).'","'.
				str_replace('"','\"',get_site_option('site_name')).'","'.
				str_replace('"','\"',$result->blog_id).'","'.
				str_replace('"','\"',get_bloginfo('name')).'","';
			$wpdb->siteid = $old_site_id;
		}
		else {
			$site_and_post_fields = '';
		}
		$user_data = get_userdata($result->user_id);
		$post = get_post($result->post_id);
		$post_link = get_permalink($result->post_id);
		$output .='"'.
			str_replace('"','\"',$result->id).'","'.
			str_replace('"','\"',$result->user_id).'","'.
			str_replace('"','\"',$user_data->user_nicename).'","'.
			str_replace('"','\"',$result->to_name).'","'.
			str_replace('"','\"',$result->to_email).'","'.
			$site_and_post_fields.
			str_replace('"','\"',$result->post_id).'","'.
			str_replace('"','\"',$post->post_title).'","'.
			date('Y-m-d', $result->time).'"'.
			"\n";
	}
	restore_current_blog();
	return $output;
}
*/

/**
 * cfet_save_email
 * 
 * Saves the email information (not content itself to the db)
 *
 * @param array $email_info 
 * @return boolean (True for email saved, False for email NOT saved)
*/
function cfet_save_email($email_info) {
	global $wpdb, $site_id, $blog_id, $user_ID;

	if ($email_info['cfet_post_id'] == 'none') {
		$cfet_post_id = 'NULL';
	}
	else {
		$cfet_post_id = $email_info['cfet_post_id'];
	}
	// $sql = '
	// 	INSERT INTO '.CFET_TABLE_NAME.'
	// 	VALUES (
	// 		NULL,
	// 		"'.$user_ID.'",
	// 		"'.$wpdb->escape($email_info['to_name']).'", 
	// 		"'.$wpdb->escape($email_info['to_email']).'", 
	// 		"'.$site_id.'", 
	// 		"'.$blog_id.'", 
	// 		"'.$cfet_post_id.'",
	// 		"'.time().'"
	// 	)
	// ';
	// $result = $wpdb->query($sql);
	// if ($result > 0) {
		return true;
	// }
	// else {
	// 	return false;
	// }
}

/**
 * cfet_send_email
 *
 * @param array $email_info 
 * @return bool true on mail sent success, bool false on mail send error
*/
function cfet_send_email($email_info) {
	if (!class_exists('PHPMailer')) {
		include_once(trailingslashit(ABSPATH).'wp-includes/class-phpmailer.php');
	}				
 	$cfet_email = new PHPMailer();
	$cfet_email->AddAddress($email_info['to_email'],$email_info['to_name']);
	$cfet_email->IsMail();
	$cfet_email->Subject = $email_info['subject'];
	$cfet_email->From = $email_info['from_email'];
	$cfet_email->FromName = $email_info['from_name'];
	$cfet_email->IsHTML(true);
	$cfet_email->Body = $email_info['body'];
	$cfet_email->AltBody = $email_info['alt_body'];
	if ($cfet_email->send()) {
		echo apply_filters('cfet_success_message', '<h3>Message Sent Successfully!</h3>');
		echo $cfet_email->ErrorInfo;
		return true;
	}
	else {
		echo apply_filters('cfet_fail_message', '<h3>I apologize, we had trouble sending your message, please try later.</h3>');
		echo $cfet_email->ErrorInfo;
		return false;
	}
}

function cfet_get_the_excerpt($excerpt,$post_content) {
	$excerpt = trim($excerpt);
	if((empty($excerpt) || $excerpt == '<br />')) {
		$excerpt = strip_tags($post_content);
		if(strlen($excerpt) > 500) {
			$excerpt = substr($excerpt, 0, 500).'[&hellip;]';
		}
	}
	return $excerpt;
}

/**
 * Single function for producing messages
 *
 * @param string $html 
 * @param array $email_info 
 * @param string $from_name 
 * @param string $from_email 
 * @param bool $convert_to_text 
 * @return string
 */
function cfet_make_message($html, $email_info, $from_name = '', $from_email = '', $convert_to_text = false) {
	$post = get_post($email_info['cfet_post_id']);
	
	$find = array(
		'###POST_TITLE###',
		'###POST_CONTENT###',
		'###POST_EXCERPT###',
		'###POST_PERMALINK###'
	);
	$replace = array(
		esc_html($post->post_title), 
		apply_filters('the_content',$post->post_content),
		cfet_get_the_excerpt($post->post_excerpt,$post->post_content),
		'Full article: <a href="'.get_permalink($post->ID).'">'.wp_specialchars($post->post_title).'</a>'
	);
	
	$html = str_replace($find, $replace, $html);
	
	if (empty($email_info['personal_msg'])) {
		$personal_msg_section = '';
	}
	else {
		$personal_msg_header = get_option('cfet_email_personal_msg_header');
		if (!$personal_msg_header) {
			$personal_msg_header = "Here's a message from your friend";
		}
		else {
			$personal_msg_header = str_replace(array('###FROM_NAME###', '###FROM_EMAIL###'), array($from_name, $from_email), $personal_msg_header);
		}
		$personal_msg_section = '<hr />';
		$personal_msg_section .= '<h3>'.wp_specialchars($personal_msg_header).'</h3>';
		$personal_msg_section .= '<p>'.nl2br($email_info['personal_msg']).'</p>';
	}
	
	$html = '<html><body>'.str_replace('###PERSONAL_MESSAGE###',$personal_msg_section, $html).'</body></html>';

	if($convert_to_text) {
		$text_find = array(
			'<hr />',
			'<hr>',
			'<br />',
			'<br>'
		);
		$text_replace = array(
			'----------',
			'----------',
			"\n",
			"\n"
		);
		$html = str_replace($text_find,$text_replace,$html);
		$html = preg_replace('/<a href="(.*?)">(.*?)<\/a>/','$2 ($1)',$html);
		$html = wp_specialchars_decode(strip_tags($html), ENT_QUOTES);
		$html = html_entity_decode($html);
	}

	return apply_filters('cfet_make_message', $html, $email_info, $personal_msg_header, $personal_msg_section, $from_name, $from_email, $convert_to_text);
}

function cfet_enqueue_thickbox() {
	if (true == apply_filters('cfet_enqueue_thickbox',true)) {
		wp_enqueue_script('thickbox');
		wp_enqueue_style('thickbox');
	}
}
add_action('init','cfet_enqueue_thickbox');

/**
 * cfet_get_email_this_window
 *
 * @param string $post_id
 * @return string of JavaScript and HTML for Email This popup window
 * @filters 
 * 		apply_filters('cfet_email_this_form_setup', $output, $script_output, $html_output);
*/
function cfet_get_email_this_window($cfet_post_id) {
	$script_output = '
		<script type="text/javascript">
			//////// Validate email address now ///////
			// thanks to http://homepage.ntlworld.com/kayseycarvey/jss3p7.html for the base of the function //
			checkEmail = function(email) {
				AtPos = email.indexOf("@")
				StopPos = email.lastIndexOf(".")
				if ((email == "") || (AtPos == -1 || StopPos == -1) || (StopPos < AtPos) || (StopPos - AtPos == 1)) {
					return false;
				}
				return true;
			}
			//////////////////////////////////////////////
			cfet_jquery_send_email = function(toName, toEmail, personalMsg, postID) {
				data = {cf_action:"cfet_send_email", to_name:toName, to_email:toEmail, personal_msg:personalMsg, cfet_post_id:postID};
				if (toName == "" || toEmail == "") {
					alert("Both name and address fields are required.\n\nPlease try again.");
					return false;
				}
				if (checkEmail(toEmail) == false) {
					alert("Please enter a valid email address");
					return false;
				}
				jQuery.post(
					"index.php", 
					data, 
					function(r){
						'.apply_filters('cfet_success_callback','jQuery("#email_this_div").html(r);
						var error_div = jQuery("#cfet-error-list").html();
						if ( error_div == null ) {
							var t =  setTimeout("jQuery(\'#TB_window\').fadeOut(\'slow\',function(){jQuery(\'#TB_window,#TB_overlay,#TB_HideSelect\').trigger(\'unload\').unbind().remove();});",1750);
						}
						return false;').'
					},
					"html"
				);
			}
		</script>
	';
	$html_output = '
		<div id="email_this_div" class="content">
			<form name="email_this_form" id="email_this_form" onsubmit="cfet_jquery_send_email(this.cfet_to_name.value, this.cfet_to_email.value, this.cfet_personal_message.value, this.cfet_post_id.value); return false;">
				<h3>'.htmlspecialchars(get_option('cfet_popup_who_to_info_header')).'</h3>
				<p><label for="cfet_to_name">Name:</label><input type="text" name="cfet_to_name" id="cfet_to_name" value="" /></p>
				<p><label for="cfet_to_email">Email:</label><input type="text" name="cfet_to_email" id="cfet_to_email" value="" class="cfet_email" /></p>
				<h3 class="inline-h3">'.htmlspecialchars(get_option('cfet_popup_personal_msg_header')).'</h3><span class="small">(optional)</span>
				<p><textarea name="cfet_personal_message" id="cfet_personal_message"></textarea></p>
				<p><input type="hidden" name="cfet_post_id" value="'.$cfet_post_id.'" /></p>
				<p><input type="submit" name="cfet_submit_email_form" id="cfet_submit_email_form" value="send email" />&nbsp;&nbsp;<span class="small">or</span>&nbsp;&nbsp;<a href="cancel" onclick="'.apply_filters('cfet_form_cancel','tb_remove(); return false;').'"><span class="small">Cancel</span></a></p>
			</form>
		</div>
	';
	$script_output = apply_filters('cfet_email_this_form_script', $script_output);
	$html_output = apply_filters('cfet_email_this_form_html', $html_output);
	$output = $script_output.' '.$html_output;
	
	/* Allow entire form to be filtered. */
	$output = apply_filters('cfet_email_this_form_setup', $output, $script_output, $html_output, $cfet_post_id);
	return $output;
}

function cfet_email_this_link($text = 'Email This!', $args = array('display_type' => 'thickbox')) {
	echo cfet_get_email_this_link($text, $args);
}
/**
 * cfet_get_email_this_link
 *
 * Creates the Link that displays the Email This window
 * 
 * @param string $text 
 * @param array $args 
 * @return string $final_output
 * @filters
 * 		apply_filters('cfet_email_this_link',$output, $args);
*/
function cfet_get_email_this_link($text = 'Email This!', $args = array()) {
	$defaults = array(
		'display_type' => 'thickbox',
		'link_class' => '',
		'title' => '',
		'width' => '320',
		'height' => '440'
	);
	if (!is_array($args)) {
		/* We need an array for the args */
		return false;
	}
	
	/* Override the defaults with the passed args */
	$args = array_merge($defaults, $args);
	
	/* Allow for a string of classes, instead of an array */
	if (!empty($args['link_class']) && !is_array($args['link_class'])) {
		$args['link_class'] = explode(' ', $args['link_class']);
	}
	
	/* Determine how we're going to be displaying the form */
	switch ($args['display_type']) {
		case 'thickbox':
			if (!is_array($args['link_class']) || !in_array('thickbox', $args['link_class'])){
				$args['link_class'][] = 'thickbox';
			}
			break;
		case 'expand':
			// This will be for a jQuery popdown section of the screen.  Not Yet implemented!
			break;
	}
	
	/* Grab the post ID b/c typically we're emailing an article */
	global $post;
	if (!$post->ID) {
		$post_id = 'none';
	}
	else {
		$post_id = $post->ID;
	}
	
	$output = '
		<div class="cfet_email_this_link">
			<a 
				href="'.trailingslashit(get_bloginfo('url')).'index.php?cf_action=email_this_window&amp;cfet_post_id='.$post_id.'&amp;width='.$args['width'].'&amp;height='.$args['height'].'" class="'.implode(' ', $args['link_class']).'" title="'.$args['title'].'">'.htmlspecialchars($text).'</a>
		</div>';
	$output = apply_filters('cfet_email_this_link',$output, $text, $args);
	return $output;
}

wp_enqueue_script('jquery');

/**
 * cfet_append_email_this_link
 *
 * Tacks the email-this link on to the end of the content of a post/page
 * 
 * @param string $content 
 * @return string $content
*/
function cfet_append_email_this_link($content) {
	global $post;
	if (get_post_meta($post->ID, '_cfet_show_email_this_link', true) == 'yes') {
		$content .= cfet_get_email_this_link();
	}
	return $content;
}
add_filter('the_content','cfet_append_email_this_link');

function cfet_admin_js() {
	header('Content-type: text/javascript');
	?>
	<!--
	var maxWidth = "600";
	tinyMCE.init({
		theme : "advanced",
		mode : "exact",
		elements : "cfet_email_body",
		theme_advanced_toolbar_location : "top",
		skin : "wp_theme",
		dialog_type : "modal",
		width : maxWidth,
		height : "400"
	});
	
	jQuery(document).ready(function(){
		jQuery("#cfet_settings_form input[type=text]").css("width","250px");
		jQuery("#cfet_email_body").parent().append('<label class="left_label">&nbsp;</label><span class="sidenote">Variables: ###POST_TITLE###, ###POST_CONTENT###, ###POST_EXCERPT###, ###POST_PERMALINK###, ###PERSONAL_MESSAGE###</span>');
		jQuery("#cfet_email_personal_msg_header").css("width",(maxWidth-10)+"px").after('<br /><span class="sidenote">You can use ###FROM_NAME### or ###FROM_EMAIL### to use the values that the visitor enters</span>');
	});
	-->
	<?php
	die();
}
function cfet_admin_css() {
	header('Content-type: text/css');
	?>
	#cfet_settings_form .sidenote {
		font-size: 0.8em;
	}
	fieldset.options div.option {
		margin-bottom: 8px;
		padding: 10px;
	}
	fieldset.options div.option label.left_label {
		display: block;
		float: left;
		font-weight: bold;
		margin-right: 10px;
		width: 150px;
	}
	fieldset.options div.option input {
		width: 500px;
	}
	fieldset.options div.option textarea {
		width: 510px;
		height: 300px;
	}
	fieldset.options div.option input.autowidth {
		width: auto;
	}
	fieldset.options div.option span.help {
		color: #666;
		font-size: 11px;
		margin-left: 8px;
	}
	div#cfet_email_review_wrap {
		background: transparent url('<?php echo trailingslashit(get_bloginfo('url')).trailingslashit(PLUGINDIR);?>cf-email-this/images/cfet-admin-bottom-background.jpg') no-repeat;
		background-position: bottom right;
		padding-bottom: 1px;
		width: 941px;
		
	}
	div#cfet_email_review {
		background: #000 url('<?php echo trailingslashit(get_bloginfo('url')).trailingslashit(PLUGINDIR);?>cf-email-this/images/cfet-admin-review-bg.jpg') repeat-y;
	}
	div.cfet_export_csv fieldset {
		padding-top: 10px;
	}
	div#cfet_most_popular {
		float: left;
		overflow: hidden;
		width: 150px;
	}
	div#cfet_most_popular ul li,
	div#cfet_most_popular ul li h3 {
		margin-top: 0;
		margin-bottom: 0;
	}
	div.cfet_popular_content h3 {
		border-right: 1px solid #CCC;
		padding: 1em 0;
	}
	div.cfet_popular_content ul li,
	div.cfet_popular_content ul li.cfet-not-selected{
		border:0;
		border-right: 1px solid #CCC;
		padding: 5px 10px;
	}
	div.cfet_popular_content ul li.cfet-selected {
		border:1px solid #CCC;
		border-right:1px solid #FFF;
		background-color: #FFF;
	}
	div#cfet_info_details {
		float: left;
		padding: 20px;
		min-height: 402px;
		width: 750px;
		background-color: #FFF;
		border-top: 1px solid #CCC;
		border-right: 1px solid #CCC;
	}
	div#cfet_info_details table th {
		text-align: left;
	}
	div#cfet_info_details table td {
		border: 1px solid #CCC;
		padding: 5px 10px;
		text-align: left;
	}
	div.cfet-pagination-links {
		margin: 10px 0;
	}
	div.cfet-pagination-links a {
		border: 1px solid #CCC;
		padding: 3px 5px;
		margin: 0 5px;
	}
	div.cfet-pagination-links a.cfet_paginate_link_current,
	div.cfet-pagination-links a.cfet_elipse {
		border: 1px solid #000;
		text-decoration: none;
		color: #000;
	}
	div.cfet-total-records {
		float: right;
	}
	div.clearer {
		clear: both;
	}
	<?php 
	die();
}

// Admin Head
	function cfet_admin_head() {
		echo '<script type="text/javascript" src="'.trailingslashit(get_bloginfo('wpurl')).'wp-includes/js/tinymce/tiny_mce.js"></script>';
		echo '<link rel="stylesheet" type="text/css" href="'.trailingslashit(get_bloginfo('siteurl')).'?cf_action=cfet_admin_css" />';
		echo '<script type="text/javascript" src="'.trailingslashit(get_bloginfo('siteurl')).'?cf_action=cfet_admin_js"></script>';
	}
	if($_GET['page'] == 'cf-email-this.php') {
		add_action('admin_head','cfet_admin_head');
	}

/* Addmin functions not needed for our purposes
function cfet_admin_head() {
	echo '<link rel="stylesheet" type="text/css" href="'.trailingslashit(get_bloginfo('siteurl')).'?cf_action=cfet_admin_css" />';
	// echo '<script type="text/javascript" src="'.trailingslashit(get_bloginfo('wpurl')).'wp-includes/js/tinymce/tiny_mce.js"></script>';
	echo '<script type="text/javascript" src="'.trailingslashit(get_bloginfo('siteurl')).'?cf_action=cfet_admin_js"></script>';
	?>
	<script type="text/javascript">
	jQuery(document).ready(function(){
		jQuery(".cfet_popular_content ul li a").css('cursor','pointer').click(function(){
			jQuery("#cfet_most_popular .cfet_popular_content li").attr('class','cfet-not-selected');
			jQuery(this).parent().attr('class','cfet-selected');
			var cfet_div_html = jQuery("#cfet_info_details").html();
			jQuery.post(
				"index.php",
				{cf_action:"cfet_email_review_details", item_id:this.id},
				function(r) {
					jQuery("#cfet_info_details").html(r);
				},
				"html"
			);
			return false;
		});
	});
	</script>
	<?php 
}
if (CFET_SHOW_ADMIN_PAGE) {
	add_action('admin_head','cfet_admin_head');
}

function cfet_admin_notice() {
	if (get_option('cfet_email_body') == '') {
		$message = sprintf( __( 'Warning: Please set the email body field in the options for the CF Email This Plugin!' ), wp_specialchars( $last_user_name ) );
		$message = str_replace( "'", "\'", "<div class='error'><p>$message</p></div>" );
		add_action('admin_notices', create_function( '', "echo '$message';" ) );
	}
}
if (CFET_SHOW_ADMIN_PAGE) {
	add_action('admin_head','cfet_admin_notice');
}

function cfet_admin_email_review() {
	global $wpmu_version, $wpdb;
	
	$sql = '
		SELECT MAX(TIME) AS yearMax, MIN(TIME) AS yearMin
		FROM wp_email_this
	';
	$year_range = $wpdb->get_row($sql);
	$year_min = date('Y',$year_range->yearMin);
	$year_max = date('Y',$year_range->yearMax);
	?>
	<div class="wrap">
		<h2>Email This - Email Review Page</h2>
		<h3>Export Data</h3>
		<div class="cfet_export_csv">
			<script type="text/javascript">
				function cfet_export_csv(startYear, startMonth, endYear, endMonth){
					if (startYear === undefined || startMonth === undefined || endYear === undefined || endMonth === undefined) {
						var yn = confirm('This could take a while, are you sure you want to download all the records?');
						if (yn == true) {
							window.location='index.php?cf_action=cfet_download_csv';					
						}
						return;										
					}
					window.location='index.php?cf_action=cfet_download_csv&start_year='+startYear+'&start_month='+startMonth+'&end_year='+endYear+'&end_month='+endMonth;					
					return;
				}
				jQuery(document).ready(function(){
					jQuery("#cfet-export-csv-range").hide();
					jQuery("#cfet-display-range-div").click(function(){
						jQuery("#cfet-export-csv-range").slideToggle();
						return false;
					});
					jQuery("#cfet-display-range-div-cancel").click(function(){
						jQuery("#cfet-export-csv-range").slideToggle();
						return false;						
					});
				});
			</script>
			<input type="button" name="export_csv" id="export_csv" class="button-secondary" value="Export All to CSV" onclick="cfet_export_csv();" />
			 or <a href="#" id="cfet-display-range-div">Export a date range</a>
			<fieldset id="cfet-export-csv-range">
				<p>
				<label for="start-month">Start Date: </label>
				<select name="start-month" id="start-month">
					<?php 
					for ($i = 1; $i <= 12; $i++) {
						echo '<option>'.$i.'</option>';				
					}
					?>
				</select>
				<select name="start-year" id="start-year">
					<?php 
					for ($i = $year_min; $i <= $year_max; $i++) {
						echo '<option>'.$i.'</option>';				
					}
					?>
				</select>
				</p>
				<p>
				<label for="end-month">End Date: </label>
				<select name="end-month" id="end-month">
					<?php 
					for ($i = 1; $i <= 12; $i++) {
						echo '<option>'.$i.'</option>';				
					}
					?>
				</select>
				<select name="end-year" id="end-year">
					<?php 
					for ($i = $year_min; $i <= $year_max; $i++) {
						echo '<option>'.$i.'</option>';				
					}
					?>
				</select>
				</p>
				<p><input type="button" name="export_csv" id="export_csv" class="button-secondary" value="Export date range to CSV" onclick="cfet_export_csv(document.getElementById('start-year').value, document.getElementById('start-month').value, document.getElementById('end-year').value, document.getElementById('end-month').value);" /> or <a href="#" id="cfet-display-range-div-cancel" class="small">Cancel</a></p>
			</fieldset><!-- #cfet-export-csv-range -->
		</div> <!-- .cfet_export_csv -->
		<hr />
		<div class="clearer"></div>
		<?php $email_list = cfet_get_sent_emails(); ?>
		<div id="cfet_email_review_wrap">
		<h3>Quick-look at the most popular items</h3>
		<div id="cfet_email_review">
			<div id="cfet_most_popular">
				<ul>
				<?php 
				if (isset($wpmu_version)) {
					echo '
					<li>
					<div class="cfet_popular_content">
						<h3>Sites</h3>
						<ul>';
					foreach (cfet_get_most_popular('sites') as $site) {
						echo '<li><a href="" id="'.$site['id'].'">'.$site['name'].'</a></li>';
					}
					echo '</ul>
					</div>
					</li>
					<li>
					<div class="cfet_popular_content">
						<h3>Blogs</h3>
						<ul>';
					foreach (cfet_get_most_popular('blogs') as $blog) {
						echo '<li><a href="" id="'.$blog['id'].'">'.$blog['name'].'</a></li>';
					}
					echo '</ul>
					</div>
					</li>
					';
					echo'
					<li>
					<div class="cfet_popular_content">
						<h3>Posts</h3>
						<ul>';
					foreach (cfet_get_most_popular('mu_posts') as $post) {
						echo '<li><a href="" id="'.$post['id'].'">'.$post['name'].'</a></li>';
					}
					echo '</ul>
					</div>
					</li>
					';
					
				}
				else {
					echo '
					<li>
					<div class="cfet_popular_content">
						<h3>Posts</h3>
						<ul>';
					foreach (cfet_get_most_popular('posts') as $post) {
						echo '<li><a href="" id="'.$post['id'].'">'.$post['name'].'</a></li>';
					}
					echo '</ul>
					</div>
					</li>
					';
				}
				echo '
					<li>
					<div class="cfet_popular_content">
						<h3>Users</h3>
						<ul>';
				foreach (cfet_get_most_popular('users') as $user) {
					echo '<li><a href="" id="'.$user['id'].'">'.$user['name'].'</a></li>';
				}
				echo '</ul>
					</div>
					</li>
				';
				?>
				</ul>
			</div><!-- #cfet_most_popular -->
			<div id="cfet_info_details">
				<h3>General Statistics</h3>
				<ul>
					<?php
					foreach(cfet_get_general_stats() as $key => $stat) {
						echo '<li><dt>'.$key.': </dt><dd>'.$stat.'</dd></li>';
					} 
					?>
				</ul>
				<p>Click an item on the left to view its details</p>
				<div class="clearer">&nbsp;</div>
			</div><!-- #cfet_info_details -->
			<div class="clearer">
			</div>
		</div><!-- #cfet_email_review -->
		</div><!-- #cfet_email_review_wrap -->
	</div><!-- .wrap -->
	<?php
}
function cfet_get_general_stats() {
	global $wpdb;
	$sql1 = '
		SELECT count(id) as total
		FROM '.CFET_TABLE_NAME.'
	';
	$stats['Total emails sent'] = $wpdb->get_var($sql1);
	$thirty_days_ago = strtotime('-30 days');
	$sql2 = '
		SELECT count(time) as count
		FROM '.CFET_TABLE_NAME.'
		WHERE time >= "'.$thirty_days_ago.'"
	';
	$stats['Last 30 Days'] = $wpdb->get_var($sql2);
	return $stats;
}
function cfet_get_most_popular($item) {
	global $wpdb;
	$max_results = 5;
	switch ($item) {
		case 'sites':
			$sql = '
				SELECT site_id, count(site_id) as count 
				FROM '.CFET_TABLE_NAME.'
				GROUP BY site_id
				ORDER BY count DESC
				LIMIT '.$max_results.'
			';
			break;
		case 'blogs':
			$sql = '
				SELECT blog_id, count(blog_id) as count
				FROM '.CFET_TABLE_NAME.'
				GROUP BY blog_id
				ORDER BY count DESC
				LIMIT '.$max_results.'
			';
			break;
		case 'mu_posts':
			$sql = '
				SELECT blog_id, post_id, count(post_id) as count 
				FROM '.CFET_TABLE_NAME.'
				GROUP BY blog_id, post_id
				ORDER BY count DESC
				LIMIT '.$max_results.'
			';
			break;
		case 'posts':
			$sql = '
				SELECT post_id, count(post_id) as count
				FROM '.CFET_TABLE_NAME.'
				GROUP BY post_id
				ORDER BY count DESC
				LIMIT '.$max_results.'
			';
			break;
		case 'users':
			$sql = '
				SELECT user_id, count(user_id) as count
				FROM '.CFET_TABLE_NAME.'
				GROUP BY user_id
				ORDER BY count DESC
				LIMIT '.$max_results.'
			';
			break;
		default:
			return;
	}
	$results = $wpdb->get_results($sql);
	
	return cfet_get_popular_info($item, $results);
}
function cfet_get_render_results($results) {
	global $wpmu_version;
	$js = '
	<script type="text/javascript">
		function change_page(id, page) {
			jQuery.get(
				"index.php",
				{cf_action:"cfet_paginate_email_review_details", id:id, page:page},
				function(r){
					jQuery("#cfet_info_details").html(r);
				},
				"html"
			);
		}
	</script>';
	$output = $js;
	if (is_array($results['links']) && !empty($results['links'])) {
		$output .= '<div class="cfet-pagination-links">';
		foreach ($results['links'] as $link) {
			$output .= $link.' ';
		}
		$output .= '</div><!-- .cfet-pagination-links -->';
	}
	$output .= '<table style="border: 1px solid #CCC; width: 750px;"><tr><th>From</th><th>To</th><th>Post</th><th>Date</th></tr>';
	foreach ($results['rows'] as $result) {
		if (isset($wpmu_version)) {
			switch_to_blog($result->blog_id);
		}
		$user_data = get_userdata($result->user_id);
		$post = get_post($result->post_id);
		$post_link = get_permalink($result->post_id);
		$output .= '<tr><td>'.$user_data->user_nicename.'</td><td>'.$result->to_name.'</td><td><a href="'.$post_link.'" title="Go to '.attribute_escape($post->post_title).'">'.$post->post_title.'</a></td><td>'.date('Y-m-d', $result->time).'</td></tr>';
	}
	$output .= '</table>';
	if (is_array($results['links']) && !empty($results['links'])) {
		$output .= '<div class="cfet-pagination-links">';
		foreach ($results['links'] as $link) {
			$output .= $link.' ';
		}
		$output .= '</div><!-- .cfet-pagination-links -->';
	}
	$output .= '<div class="cfet-total-records">Total Records: '.$results['total_records'].'</div>';
	return $output;
}
function cfet_get_where_conditions($id) {
	$first_dash = (stripos($id, '-', 0)+1);
	$second_dash = (stripos($id, '-', $first_dash));
	switch (substr($id, 0, 1)) {
		case 's':
			// it's a site
			$val = substr($id, $first_dash, ($second_dash - $first_dash));
			$where_conditions = 'site_id = "'.$val.'"';
			break;
		case 'b':
			// it's a blog
			$val = substr($id, $first_dash, ($second_dash - $first_dash));
			$where_conditions = 'blog_id = "'.$val.'"';
			break;
		case 'p':
			// it's a post, we need the blog id as well
			$third_dash = (stripos($id, '-', $second_dash)+3);
			$fourth_dash = (stripos($id, '-', $third_dash));
			$val = substr($id, $first_dash, ($second_dash - $first_dash));
			$val2 = substr($id, $third_dash, ($fourth_dash - $third_dash));
			$where_conditions = 'post_id = "'.$val.'" AND blog_id = "'.$val2.'"';
			break;
		case 'u':
			// it's a user
			$val = substr($id, $first_dash, ($second_dash - $first_dash));
			$where_conditions = 'user_id = "'.$val.'"';
			break;
		default:
			return;
	}
	return $where_conditions;
}
function cfet_retrieve_item_data($id, $page = false) {
	global $wpdb;
	$where_conditions = cfet_get_where_conditions($id);
	$sql = '
		SELECT count(id)
		FROM '.CFET_TABLE_NAME.'
		WHERE '.$where_conditions.'
	';
	$result['total_records'] = $wpdb->get_var($sql);
	$results_per_page = 15;
	$last_page = ceil($result['total_records']/$results_per_page); 
	if (!$page) { 
		// set the page number to one if not set already
		$page = 1; 
	}
	// troubleshooting pagination here
	// print('total records :: '.$result['total_records']."<br />".'results per page :: '.$results_per_page."<br />".'last page :: '.$last_page."<br />".'page :: '.$page."<br />");

	$limit_start = ($page - 1) * $results_per_page;
	if ($page > $last_page) {
		$page = $last_page;
	}
	$spacer_amount = 1;
	$backwards_elipse_done = false;
	$fowards_elipse_done = false;
	for ($i = 1; $i <= $last_page; $i++) {
		switch ($i) {
			case ($last_page == 1):
				continue;
				break;
			case $page:
				$result['links'][] = '<a href="#" class="cfet_paginate_link_current" onclick="return false;">'.$i.'</a>';				
				break;
			case (($i < ($page - $spacer_amount)) && $i != 1):
				if (!$backwards_elipse_done){
					//$result['links'][] = '<a href="#" class="cfet_elipse" onclick="return false;">...</a>';
					$result['links'][] = '...';
					$backwards_elipse_done = true;
				}
				break;
			case (($i > ($page + $spacer_amount)) && $i != $last_page):
				if (!$forwards_elipse_done){
					//$result['links'][] = '<a href="#" class="cfet_elipse" onclick="return false;">...</a>';
					$result['links'][] = '...';
					$forwards_elipse_done = true;
				}
				break;
			default: 
				$result['links'][] = '<a href="#" class="cfet_paginate_link" onclick="change_page(\''.$id.'\', \''.$i.'\'); return false;" title="Go to Page '.$i.'">'.$i.'</a>';				
		}
	}

	$sql = '
		SELECT * 
		FROM '.CFET_TABLE_NAME.'
		WHERE '.$where_conditions.'
		ORDER BY time DESC, to_name ASC
		LIMIT '.$limit_start.', '.$results_per_page.'
	';
	
	$result['rows'] = $wpdb->get_results($sql);
	return $result;
}
*/

/*
 * cfet_post_meta_box
 *
 * Defines the post meta box for the admin screen
 * 
 * @param string $post 
 * @param string $box 
 * @return void

function cfet_post_meta_box($post, $box) {
	$cfet_show_link = get_post_meta($post->ID, '_cfet_show_email_this_link', true);
	$cfet_add_to_post_default = get_option('cfet_add_to_post_default');
	if ($cfet_show_link == 'yes') {
		$yes_selected = ' selected="selected"';
		$no_selected = '';
	}
	else if ($cfet_show_link == 'no'){
		$yes_selected = '';
		$no_selected = ' selected="selected"';
	}
	else if ($cfet_add_to_post_default == 'yes') {
		$yes_selected = ' selected="selected"';
		$no_selected = '';
	}
	else if ($cfet_add_to_post_default == 'no') {
		$yes_selected = '';
		$no_selected = ' selected="selected"';
	}
	else {
		$yes_selected = '';
		$no_selected = '';
	}
	?>
	<div>
		<p>Select whether or not you want to add an Email This Link to this <?php echo $post->post_type; ?>:</p> 
		<p>
			<select name="_cfet_show_email_this_link" id="_cfet_show_email_this_link">
				<option value="no"<?php echo $no_selected; ?>>No</option>
				<option value="yes"<?php echo $yes_selected; ?>>Yes</option>
			</select>
			<input type="hidden" name="cf_action" value="save_email_this_post_meta" />
		</p>
	</div>
	<?php
}
*/
/*
 * cfet_add_custom_meta_box
 * 
 * Adds the meta boxes to the post and page admin screens
 *
 * @return void

function cfet_add_custom_meta_box() {
	global $wp_version;
	//add post meta box here
	if (version_compare($wp_version,'2.7','<')) {
		$cfet_meta_box_location = 'normal';
	}
	else {
		$cfet_meta_box_location = 'side';
	}
	add_meta_box('cfet_display_link', 'Add an Email This link?', 'cfet_post_meta_box', 'post', $cfet_meta_box_location, 'default');
	add_meta_box('cfet_display_link', 'Add an Email This link?', 'cfet_post_meta_box', 'page', $cfet_meta_box_location, 'default');
}
if (CFET_ALLOW_POST_META_BOX) {
	add_action('admin_init', 'cfet_add_custom_meta_box');
}
*/
/*

function cfet_get_popular_info($item, $results) {
	global $wpdb;
	if (!isset($wpdb->base_prefix)) {
		$wpdb->base_prefix = $wpdb->prefix;
	}
	$counter = '0';
	$info = array();
	switch ($item) {
		case 'sites':
			foreach ($results as $result) {
				$counter++;
				$sql = '
					SELECT meta_value
					FROM '.$wpdb->base_prefix.'sitemeta
					WHERE meta_key = "site_name"
					AND site_id = "'.$result->site_id.'"
				';
				$info[$counter]['name'] = $wpdb->get_var($sql);
				$info[$counter]['id'] = 's-'.$result->site_id.'-';
			}
			break;
		case 'blogs':
			foreach ($results as $result) {
				$counter++;
				switch_to_blog($result->blog_id);
				$info[$counter]['name'] = get_bloginfo('name');
				$info[$counter]['id'] = 'b-'.$result->blog_id.'-';
			}
			restore_current_blog();
			break;
		case 'mu_posts':
			foreach ($results as $result) {
				$counter++;
				switch_to_blog($result->blog_id);
				$post = get_post($result->post_id);
				$info[$counter]['name'] = $post->post_title;
				$info[$counter]['id'] = 'p-'.$result->post_id.'-'.'b-'.$result->blog_id.'-';
			}
			restore_current_blog();
			break;
		case 'posts':
			foreach ($results as $result) {
				$counter++;
				$post = get_post($result->post_id);
				$info[$counter]['name'] = $post->post_title;
				$info[$counter]['id'] = 'p-'.$result->post_id.'-';
			}
			break;
		case 'users':
			foreach ($results as $result) {
				$counter++;
				$user = get_userdata($result->user_id);
				$info[$counter]['name'] = $user->user_nicename;
				$info[$counter]['id'] = 'u-'.$result->user_id.'-';
			}
			break;
		default:
			return;
	}
	return $info;
}
*/

/*
 * cfet_save_post_meta
 *
 * Updates the post meta accoring to $_POST variable or default option
 * 
 * @param $post_id 
 * @return void

function cfet_save_post_meta($post_id) {
	$post = get_post($post_id);
	if (!$post || $post->post_type == 'revision') {
		return;
	}
	if (isset($_POST['cf_action']) && $_POST['cf_action'] == 'save_email_this_post_meta') {
		update_post_meta($post_id, '_cfet_show_email_this_link', $_POST['_cfet_show_email_this_link']);
		return;
	}
	update_post_meta($post_id, '_cfet_show_email_this_link', get_option('cfet_add_to_post_default'));
	return;
}
add_action('save_post','cfet_save_post_meta');

function cfet_get_sent_emails() {
	global $wpdb;
	
	$sql = '
		SELECT *
		FROM '.CFET_TABLE_NAME.'
	';
	$results = $wpdb->get_results($sql);
	return $results;
}
*/

/*
$example_settings = array(
	'key' => array(
		'type' => 'int',
		'label' => 'Label',
		'default' => 5,
		'help' => 'Some help text here',
	),
	'key' => array(
		'type' => 'select',
		'label' => 'Label',
		'default' => 'val',
		'help' => 'Some help text here',
		'options' => array(
			'value' => 'Display'
		),
	),
);
* 
*/
$cfet_settings = array(
	'cfet_email_subject' => array(
		'type' => 'string',
		'label' => 'Email Subject: ',
		'default' => '',
		'help' => '',
	),
	'cfet_email_from_name' => array(
		'type' => 'string',
		'label' => 'Email From Name: ',
		'default' => '',
		'help' => '',
	),
	'cfet_email_from_address' => array(
		'type' => 'string',
		'label' => 'Email From Address: ',
		'default' => '',
		'help' => '',
	),
	'cfet_email_body' => array(
		'type' => 'textarea',
		'label' => 'Email Body: ',
		'default' => '',
		'help' => '',
	),
	'cfet_email_personal_msg_header' => array(
		'type' => 'string',
		'label' => 'Text header for personal message in email: ',
		'default' => "Here's a message from your friend",
		'help' => '',
	),
	'cfet_popup_who_to_info_header' => array(
		'type' => 'string',
		'label' => 'Text for the \'Who To\' area header of popup window: ',
		'default' => 'Who are you sending this page to?',
		'help' => '',
	),
	'cfet_popup_who_from_info_header' => array(
		'type' => 'string',
		'label' => 'Text for the \'Who From\' area header of popup window: ',
		'default' => 'Tell us about yourself:',
		'help' => '',
	),
	'cfet_popup_personal_msg_header' => array(
		'type' => 'string',
		'label' => 'Text for the \'Personal Message\' area header of popup window',
		'default' => 'Personal Message:',
		'help' => '',
	),
);

function cfet_setting($option) {
	$value = get_option($option);
	if (empty($value)) {
		global $cfet_settings;
		$value = $cfet_settings[$option]['default'];
	}
	return $value;
}

function cfet_admin_menu() {
	if (current_user_can('manage_options')) {
		if (CFET_SHOW_ADMIN_PAGE) {
			add_options_page(
				__('CF Email This', 'cf-email-this')
				, __('CF Email This', 'cf-email-this')
				, 10
				, basename(__FILE__)
				, 'cfet_settings_form'
			);
		}
		if (CFET_KEEP_STATS) {
			add_dashboard_page(
				__('CF Email This Review', 'cf-email-this')
				, __('CF Email This Review', ' cf-email-this')
				, 10
				, basename(__FILE__)
				, 'cfet_admin_email_review'
			);
		}
	}
}
add_action('admin_menu', 'cfet_admin_menu');

function cfet_plugin_action_links($links, $file) {
	$plugin_file = basename(__FILE__);
	if ($file == $plugin_file) {
		$settings_link = '<a href="options-general.php?page='.$plugin_file.'">'.__('Settings', 'cf-email-this').'</a>';
		array_unshift($links, $settings_link);
	}
	return $links;
}
if (CFET_SHOW_ADMIN_PAGE) {
	add_filter('plugin_action_links', 'cfet_plugin_action_links', 10, 2);
}

if (!function_exists('cf_settings_field')) {
	function cf_settings_field($key, $config) {
		$option = get_option($key);
		if (empty($option) && !empty($config['default'])) {
			$option = $config['default'];
		}
		$label = '<label for="'.$key.'" class="left_label">'.$config['label'].'</label>';
		$help = '<span class="help">'.$config['help'].'</span>';
		switch ($config['type']) {
			case 'select':
				$output = $label.'<select name="'.$key.'" id="'.$key.'">';
				foreach ($config['options'] as $val => $display) {
					$option == $val ? $sel = ' selected="selected"' : $sel = '';
					$output .= '<option value="'.$val.'"'.$sel.'>'.htmlspecialchars($display).'</option>';
				}
				$output .= '</select>'.$help;
				break;
			case 'textarea':
				$output = $label.'<textarea name="'.$key.'" id="'.$key.'">'.htmlspecialchars($option).'</textarea>'.$help;
				break;
			case 'string':
			case 'int':
			default:
				$output = $label.'<input name="'.$key.'" id="'.$key.'" value="'.htmlspecialchars($option).'" />'.$help;
				break;
		}
		return '<div class="option">'.$output.'<div class="clear"></div></div>';
	}
}

function cfet_settings_form() {
	global $cfet_settings;

	print('
<div class="wrap">
	<h2>'.__('Email This Settings', 'cf-email-this').'</h2>
	<form id="cfet_settings_form" name="cfet_settings_form" action="'.get_bloginfo('wpurl').'/wp-admin/options-general.php" method="post">
		<input type="hidden" name="cf_action" value="cfet_update_settings" />
		<fieldset class="options">
	');
	foreach ($cfet_settings as $key => $config) {
		echo cf_settings_field($key, $config);
	}
	$add_link_default = get_option('cfet_add_to_post_default');
	switch ($add_link_default) {
		case 'yes':
			$cfet_default_yes = 'checked';
			$cfet_default_no = '';
			break;
		case 'no':
			$cfet_default_yes = '';
			$cfet_default_no = 'checked';
			break;
		default:
			$cfet_default_yes = '';
			$cfet_default_no = 'checked';
			break;
	}
	print('
		<div class="option">
			<label class="left_label">Add Link to post (Default Setting):</label>
			<input class="autowidth" type="radio" name="cfet_add_to_post_default" id="cfet_add_to_post_default_yes" value="yes" '.$cfet_default_yes.' /><label for="cfet_add_to_post_default_yes" class="admin_unlabel"> yes </label>
			&nbsp;&nbsp;<input class="autowidth" type="radio" name="cfet_add_to_post_default" id="cfet_add_to_post_default_no" value="no" '.$cfet_default_no.' /><label for="cfet_add_to_post_default_no" class="admin_unlabel"> no </label>
		</div>
		');
	print('
		</fieldset>
		<p class="submit">
			<input type="submit" name="submit" value="'.__('Save Changes', 'cf-email-this').'" />
		</p>
	</form>
</div>
	');
}

function cfet_save_settings() {
	if (!current_user_can('manage_options')) {
		return;
	}
	global $cfet_settings;
	foreach ($cfet_settings as $key => $option) {
		$value = '';
		switch ($option['type']) {
			case 'int':
				$value = intval($_POST[$key]);
				break;
			case 'select':
				$test = stripslashes($_POST[$key]);
				if (isset($option['options'][$test])) {
					$value = $test;
				}
				break;
			case 'string':
			case 'textarea':
			default:
				$value = stripslashes($_POST[$key]);
				break;
		}
		update_option($key, $value);
	}
	// update the one-off ones now
	update_option('cfet_add_to_post_default',$_POST['cfet_add_to_post_default']);
}

?>