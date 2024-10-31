<?php
/*
Plugin Name: newhaze
Plugin URI: http://developers.newhaze.com/help/documentation/tools/wordpress
Description: Add games to your website extremely easily.
Version: 2.0
Author: newhaze
Author URI: http://www.newhaze.com
License: GPL2
*/?>
<?php
/****************************************
newhaze API Version:	2.0
PHP Client Version:		1.0
Version released on:	19th August 2012

Features:
			cURL and non-cURL Support
			OAuth Protocol
			Easy login support

Information:			developers.newhaze.com
Documentation:			developers.newhaze.com/help/documentation/libraries/php

© newhaze.com

****************************************/
if(!function_exists('json_decode')) {
	throw new Exception('json_decode function is required by the newhaze SDK');
}

class newhazeException extends Exception {
	protected $result;
	public function __construct($message, $code = 0) {
		parent::__construct($message, $code);
	}
	public function __toString() {
		if ($this->code != 0) {
			$str .= $this->code . ': ';
		}
		return 'newhaze API: '.$str . $this->message;
	}
}
class newhaze {
	private $app_id;
	private $app_secret;
	private $access_token;
	private $current_user='';
	private $version='1.0';
	/**
		* Start the newhaze SDK service
		*
		* @param array $options A set of options to begin the SDK with
	*/
	public function __construct($options=array()) {
		if(isset($options['app_id'])) {
			$this->app_id = $options['app_id'];
		}
		if(isset($options['app_secret'])) {
			$this->app_secret = $options['app_secret'];
			$this->access_token = $options['app_secret'];
		}
		if(isset($options['access_token'])) {
			$this->set_access_token($options['access_token']);
		}
		if(isset($options['provide_auth'])) {
			if(!session_id()) {
				session_start();
			}
			if(isset($_COOKIE['nhs'])) {
				$this->set_access_token($_COOKIE['nhs']);
			}
			if(isset($_GET['token']) and isset($_GET['state']) and isset($_SESSION['nh_state'])) {
				if($_GET['state']==$_SESSION['nh_state']) {
					unset($_SESSION['nh_state']);
					try {
						$response=$this->api('authorize',array('token'=>$_GET['token']));
					}catch(newhazeException $e) {
						$response=array('error');
					}
					if(isset($response['data']['access_token'])) {
						$this->set_access_token($response['data']['access_token']);
						if($this->access_token) {
							setcookie('nhs',$this->access_token);
						}
					}
				}
			}
		}
	}
	/**
		* Sets the access token for a user
		*
		* @param string $token The access token to use
	*/
	function set_access_token($token) {
		$this->access_token=$token;
		try {
			$r=$this->api('/me');
		}catch(newhazeException $e) {
			$r=array();
		}
		if(isset($r['data']['id'])) {
			$this->current_user=$r['data']['id'];
		}else{
			$this->current_user='';
			$this->access_token='';
		}
	}
	/**
		* Returns the URL to redirect the user to in order to begin the authorization routine
		*
		* @param array $scope A list of the permissions to request
		* @param string $redirect_uri The address to redirect the user to after authorization. Default is the current URL.
		*
		* @return string The URL to take the user to
	*/
	public function get_login_url($scope=array(),$redirect_uri=false) {
		if(!$redirect_uri) {
			$redirect_uri=$this->get_current_url();
		}
		if(!session_id()) {
			session_start();
		}
		if(!isset($_SESSION['nh_state'])) {
			$_SESSION['nh_state']=substr(md5(rand()),0,8);
		}
		return 'https://www.newhaze.com/authorize?app_key='.$this->app_id
		.'&scope='.implode(',',$scope).'&redirect_uri='.urlencode($redirect_uri).'&state='.$_SESSION['nh_state'];
	}
	/**
		* Returns the URL to redirect the user to in order to log them out of newhaze and your website
		*
		* @param string $redirect_uri The address to redirect the user to after log out. Default is the current URL.
		*
		* @return string The URL to take the user to
	*/
	public function get_logout_url($redirect_uri=false) {
		if(!$redirect_uri) {
			$redirect_uri=$this->get_current_url();
		}
		return 'https://www.newhaze.com/logout?r='.$redirect_uri;
	}
	/**
		* Gets the ID of the current logged in user
		*
		* @return string The ID of the user. If not logged in, an empty string is returned
	*/
	public function get_logged_in_user() {
		return $current_user;
	}
	/**
		* Used to find out if the user is currently logged in
		*
		* @return bool TRUE if the user is logged in, otherwise FALSE
	*/
	public function is_logged_in() {
		if($this->get_logged_in_user()) {
			return true;
		}
		return false;
	}
	/**
		* Makes an API call using the relevant access tokens and API keys
		*
		* @param string $url The URL of the API to access
		* @param string $method The HTTP method to connect to the API with (get, post, delete)
		* @param array $params The parameters to send with the request
		*
		* @return array The API response
		* @throws newhazeException
	*/
	public function api(/*polymorphic*/) {
		$args = func_get_args();
		$url='';
		$method='get';
		$parameters=array();
		if(count($args)) {
			$url=$args[0];
		}
		if(count($args)>1) {
			if(is_array($args[1])) {
				$parameters=$args[1];
			}else{
				$method=strtolower($args[1]);
			}
		}
		if(count($args)>2) {
			if(is_array($args[2])) {
				$parameters=$args[2];
			}else{
				$method=strtolower($args[2]);
			}
		}
		//finish params
		if($this->access_token) {
			$parameters['access_token']=$this->access_token;
		}
		$parameters=http_build_query($parameters);
		$url='https://api.newhaze.com/'.trim($url,'/');
		if($method=='get') {
			if(strlen(parse_url($url,PHP_URL_QUERY))>0) {
				$url.="&";
			}else{
				$url.="?";
			}
			$url.=$parameters;
			$parameters='';
			$url.='&code='.$this->generate_access_code($url);
		}else{
			$parameters.='&code='.$this->generate_access_code($url);
		}
		$response=$this->make_request($url,$parameters,strtoupper($method));
		return $response;
	}
	/*
	
	The following methods are reserved solely for the use of this SDK, and have therefore been set to private.
	
	*/
	private function generate_access_code($url) {
		return hash_hmac('sha256',$url,$this->app_secret);
	}
	private function get_current_url() {
		$pageURL = 'http';
		if($_SERVER["HTTPS"]=="on") {
			$pageURL.="s";
		}
		$pageURL.="://";
		if($_SERVER["SERVER_PORT"]!="80") {
			$pageURL.=$_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
		}else{
			$pageURL.=$_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
		}
		return $pageURL;
	}
	private function make_request($url,$post_string,$method) {
		if(function_exists('curl_init')) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_USERAGENT, 'newhaze API PHP5 Client '.$this->version.' (curl) ' . phpversion());
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($ch, CURLOPT_TIMEOUT, 30);
			$result = curl_exec($ch);
			curl_close($ch);
		}else{
			$result = $this->non_curl($url,$post_string,$method);
		}
		$result=json_decode($result,true);
		if(!$result) {
			throw new newhazeException('api error', 500);
		}else{
			if(isset($result['error'])) {
				throw new newhazeException($result['error']['message'], $result['error']['code']);
			}else{
				return $result;
			}
		}
	}
	private function non_curl($url,$post_string,$method) {
		$context_id = stream_context_create(array('http' =>array('method' => $method,'user_agent' => 'NewHaze API PHP5 Client '.$this->version.' (non-curl) ' . phpversion(),'header' => 'Content-Type: application/x-www-form-urlencoded' . "\r\n" .'Content-Length: ' . strlen($string),'content' => $post_string)));
		$sock = fopen($url, 'r', false, $context_id);
		$result = '';
		if($sock) {
			while(!feof($sock)) {
				$result .= fgets($sock, 4096);
			}
			fclose($sock);
		}
		return $result;
	}
}
add_action('admin_menu','newhaze_admin_menu');
add_action('create_category','nh_addcategory');
add_action('deleted_post','nh_removegame');
add_action('widgets_init', create_function('', 'return register_widget("widget_nh_advert");'));
add_action('admin_head', 'newhaze_admin_css');
add_action('wp_head', 'newhaze_page_script');
register_activation_hook(__FILE__,'newhaze_install');
if (!defined('WP_PLUGIN_URL')) {
	define('WP_PLUGIN_URL',WP_CONTENT_URL.'/plugins');
}
function newhaze_page_script() {?>
	<script>
	window.newhaze_load=function() {
		newhaze.init({
			app_id:'<? echo get_option('newhaze_consumer_key');?>',
			cookie:false,
			auto_login:false
		});
	};
	(function(d){
	     var js, id = 'nhjssdk', ref = d.getElementsByTagName('script')[0];
	     if (d.getElementById(id)) {return;}
	     js = d.createElement('script'); js.id = id; js.async = true;
	     js.src = "//client.newhaze.com/js/en-us.js";
	     ref.parentNode.insertBefore(js, ref);
	}(document));
	</script>
<? }
function newhaze_admin_css() {?>
	<style type="text/css">
	.nh_dialog_outer {
	    text-align: center;
	    margin-top: 30px;
	}
	.nh_dialog {
	    width: 430px;
	    border: 1px solid #888;
	    margin-left: auto;
	    margin-right: auto;
	    border-radius: 3px;
	    box-shadow: 0 1px 1px rgba(0,0,0,0.15);
	}
	.nh_dialog_inner {
	    border-top: 1px solid #F8F8F8;
	    background-color: #F1F1F1;
	    padding: 20px 10px;
	    border-radius: 2px;
	    color: #333;
	}
	.nh_dialog_title {
	    font-weight: bold;
	    font-size: 14px;
	    margin-bottom: 15px;
	}
	.nh_dialog_note {
	    font-style: italic;
	    color: #666;
	    margin-bottom: 10px;
	}
	.nh_dialog_buttons {
	    border-top: 1px solid #ccc;
	    padding-top: 5px;
	}
	.nh_error {
		border: 1px solid #C00;
		border-radius: 5px;
		margin-bottom:20px;
	}
	.nh_error .nh_inner {
		border-top: 1px solid #fee;
		border-radius:4px;
		padding: 5px 10px;
		background-color: #FBB;
		text-align: center;
	}
	.nh_success {
		border: 1px solid #FCCD69;
		border-radius: 5px;
		margin-bottom:20px;
	}
	.nh_success .nh_inner {
		border-top: 1px solid #FFF9EE;
		border-radius:4px;
		padding: 5px 10px;
		background-color: #FEEECD;
		text-align: center;
	}
	.nh_featured li {
	    display: inline-block;
	    width: 19%;
	    margin: 0;
	    padding: 0;
	    text-align: center;
	}
	.nh_featured ul {
	    margin: 0;
	    padding: 0;
	}
	.nh_featured {
	    margin: 5px;
	    margin-bottom: 15px;
	}
	.nh_featured .nh_img {
	    border-radius: 4px;
	    border-top: 1px solid rgba(255, 255, 255, 0.5);
	    padding-top: 0px;
	    width: 150px;
	    height: 99px;
	    background-position: 0 -1px;
	}
	.nh_featured a {
	    border: 1px solid #999;
	    border-radius: 5px;
	    display: inline-block;
	    box-shadow: 0 1px 2px rgba(0,0,0,0.2);
	}
	</style>
<? }
function newhaze_admin_menu() {
	add_menu_page(__('Manage Games'), __('Games'), 8, 'newhaze-manage-games', 'newhaze_manage_games',WP_PLUGIN_URL .'/newhaze/nh_16.png',7);
	add_submenu_page('newhaze-manage-games', __('Categories'), __('Categories'), 8, 'newhaze-manage-categories', 'newhaze_manage_categories');
	add_submenu_page('newhaze-manage-games', __('Game Library'), __('Game Library'), 8, 'newhaze-game-library', 'newhaze_game_library');
	add_options_page(__('newhaze Settings'), __('newhaze Settings'), 8, 'newhaze-settings', 'newhaze_show_settings');
}
function newhaze_auth_needed() {
	if(strlen(get_option('newhaze_consumer_key')) and strlen(get_option('newhaze_consumer_secret'))==16) {
		return true;
	}else{?>
		<div class="nh_dialog_outer">
			<div class="nh_dialog">
				<div class="nh_dialog_inner">
					<div class="nh_dialog_title">
						<? _e('You need to enter your app key and app secret for your site in order to continue');?>
					</div>
					<div class="nh_dialog_note">
						<? _e('Just go to "newhaze Settings" in the settings menu to make this change.');?>
					</div>
				</div>
			</div>
		</div>
	<?	return false;
	}
}
function newhaze_show_settings() {?>
	<div class="wrap">
		<div id="icon-options-general" class="icon32"><br></div>
		<h2><? _e('newhaze Settings');?></h2>
		<? if(isset($_POST['feedaction']) and isset($_POST['consumer_key']) and isset($_POST['consumer_secret'])) {
			update_option('newhaze_consumer_key', $_POST['consumer_key']);
			update_option('newhaze_consumer_secret', $_POST['consumer_secret']);
			update_option('newhaze_maxdims', $_POST['maxdims']);
			$newhaze=new newhaze(array('app_id'=>$_POST['consumer_key'],'app_secret'=>$_POST['consumer_secret']));
			update_option('nh_default_code',stripslashes($_POST['default_code']));
			echo'<div class="nh_success"><div class="nh_inner">'.__('Site settings have been saved!').'</div></div>';
		}?>
		<form method="post" name="editsettings">
			<input type="hidden" name="feedaction" value="save" />
			<table class="form-table">
				<tbody>
				<tr valign="top">
					<th scope="row"><label for="consumer_key"><? _e('App Key');?></label></th>
					<td>
						<input type="text" class="regular-text" id="consumer_key" name="consumer_key" value="<?php echo get_option('newhaze_consumer_key');?>" />
						<span class="description"><? _e('The app key for the site');?></span>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="consumer_secret"><? _e('App Secret');?></label></th>
					<td>
						<input type="text" class="regular-text" id="consumer_secret" name="consumer_secret" value="<?php echo get_option('newhaze_consumer_secret');?>" />
						<span class="description"><? _e('The app secret for the site');?></span>
					</td>
				</tr><? /*
				<tr>
					<td><b><? _e('Maximum game dimensions');?>:</b></td>
					<td><input type="text" name="maxdims" value="<?php echo get_option('newhaze_maxdims');?>"></td>
					<td><em><? _e('Either just enter maximum width (in pixels), or separate the width and height with "x", eg.550x400');?></em></td>
				</tr>
				*/?>
				<tr valign="top">
					<th scope="row"><label for="default_code"><? _e('Default post HTML');?>:</label></th>
					<td>
						<textarea id="default_code" rows="10" cols="50" name="default_code" class="large-text code"><? echo get_option('nh_default_code');?></textarea>
						<p>
							Use the following tokens to customize the post:<br />
							<tt>{name}</tt> - outputs the game name<br />
							<tt>{description}</tt> - outputs the game description<br />
							<tt>{swf}</tt> - outputs the game code (playable)<br />
							<tt>{fav-button}</tt> - outputs the favorite button (for newhaze accounts)<br />
							<tt>{comments}</tt> - outputs the game comments (for newhaze accounts)<br />
							<tt>{picture-50x70}</tt> - outputs a game image 50 pixels high by 70 pixels wide (adjust values for different sizes)
						</p>
					</td>
				</tr>
				</tbody>
			</table>
			<p class="submit">
				<input type="submit" name="submit" id="submit" class="button-primary" value="<? _e('Save Changes');?>" />
			</p>
		</form>
		<h3><? _e('Help');?></h3>
		<p><? _e("If you don't already have a <a href=\"https://developers.newhaze.com\" target=\"_blank\">newhaze Developers</a> account, <a href=\"https://developers.newhaze.com/register\" target=\"_blank\">sign up for one for free now</a>. Once you've signed up, go to the \"Sites\" page and click \"Add a Website\". Enter the URL of this blog. It should then appear on that page. Click on the site and copy and paste the app key and app secret into the correct boxes on this page. Click \"Save Settings\" and you should be able to start adding games to your site by going to \"Game Library\" under \"Games\" in the left hand menu.");?></p>
		<p><? _e("We recommend implementing this on a brand new website. If this blog or your newhaze site have games on, then they are likely to be out of sync and so will be more difficult to manage.");?></p>
	</div>
<?
}
function nh_addcategory($cat_id) {
	/*if(strlen(get_option('newhaze_consumer_key')) and strlen(get_option('newhaze_consumer_secret'))==16) {
		$newhaze=new newhaze(array('app_id'=>get_option('newhaze_consumer_key'),'app_secret'=>get_option('newhaze_consumer_secret')));
		$name=get_cat_name($cat_id);
		if($name) {
			$result = $newhaze->api('this/categories','post',array('name'=>$name));
		}
	}*/
}
function nh_removegame($post_id) {
	if(strlen(get_option('newhaze_consumer_key')) and strlen(get_option('newhaze_consumer_secret'))==16) {
		$newhaze=new newhaze(array('app_id'=>get_option('newhaze_consumer_key'),'app_secret'=>get_option('newhaze_consumer_secret')));
		global $wpdb;
		$game_table=$wpdb->prefix."newhaze_gamemap";
		$newhaze_id = $wpdb->get_var("SELECT newhaze_id FROM ".$game_table." WHERE post_id = '$post_id'");
		if($newhaze_id) {
			$query = "DELETE FROM ".$game_table." WHERE post_id='$post_id')";
			$wpdb->query($query);
			$result = $newhaze->api($newhaze_id,'delete');
		}
	}
}

function newhaze_manage_games() {
	if(newhaze_auth_needed()) {?>
		<div class="wrap">
			<div class="icon32" style="background:transparent url(<? echo WP_PLUGIN_URL;?>/newhaze/game_32.png) no-repeat;"><br></div>
	    	<h2><? _e('Manage Games');?> <a href="?page=newhaze-game-library" class="button add-new-h2"><? _e('Add More');?></a> <span class="subtitle"><? echo $sub;?></span></h2>
		<?
		$newhaze=new newhaze(array('app_id'=>get_option('newhaze_consumer_key'),'app_secret'=>get_option('newhaze_consumer_secret')));
		global $wpdb;
		$blog_url=get_bloginfo('url');
		$game_table=$wpdb->prefix . "newhaze_gamemap";
		$action = $_POST['action'];
		if($action=='delete' and isset($_POST['submit'])) {
			$id=$_POST['id'];
			$result = $newhaze->api($id,'delete');
			if(isset($result['success']) and $result['success']) {
				$post_id = $wpdb->get_var("SELECT post_id FROM ".$game_table." WHERE newhaze_id = '$id'");
				if($post_id) {
					$query = "DELETE FROM ".$game_table." WHERE post_id='$post_id')";
					$wpdb->query($query);
					$response=wp_delete_post($post_id,true);
					if($response) {
						echo '<div class="nh_success"><div class="nh_inner">'.__('This game has been successfully removed.').'</div></div>';
					}else{
						echo '<div class="nh_error"><div class="nh_inner">'.__('The game has been removed, but the post still exists on your site. You may need to delete it manually.').'</div></div>';
					}
				}else{
					echo '<div class="nh_error"><div class="nh_inner">'.__('The game has been removed, but the post still exists on your site. You may need to delete it manually.').'</div></div>';
				}
			}else{
				echo '<div class="nh_error"><div class="nh_inner">'.__('There was an error removing this game.').'</div></div>';
			}
		}
		$action = $_GET['action'];
		if($action=='delete') {
			$id=$_GET['id'];
			$result=$newhaze->api($id);
			if(isset($result['data'])) {?>
				<div class="nh_dialog_outer">
					<div class="nh_dialog">
						<div class="nh_dialog_inner">
							<form method="post" name="renamecat" action="?page=newhaze-manage-games">
								<input type="hidden" name="action" value="delete" />
								<input type="hidden" name="id" value="<? echo $result['data']['id'];?>" />
								<div class="nh_dialog_title">
									<? echo __('Are you sure you want to remove the game ').$result['data']['name'].__(' from your site?');?>
								</div>
								<div class="nh_dialog_buttons">
									<input class="button-primary" type="submit" name="submit" value="<? _e('Yes');?>" />
									<input class="button-secondary" type="submit" name="cancel" value="<? _e('No');?>" />
								</div>
							</form>
						</div>
					</div>
				</div>
		<?	}
		}else{
			if($action=='upload') {
				$error=true;
				$id=$_GET['id'];
				$post_id = $wpdb->get_var("SELECT post_id FROM ".$game_table." WHERE newhaze_id = '$id'");
				$swf_url = get_post_meta($post_id, 'swf_url', true);
				$pos = strpos($swf_url,$blog_url);
				if($pos===false) {
					if($file_contents=file_get_contents($swf_url)) {
						$result=wp_upload_bits("nh_".$post_id.".swf", null, $file_contents);
						if($result['error']===false && $result['url']) {
							$finres=update_post_meta($post_id, 'swf_url', $result['url']);
							if($finres) {
								echo '<div class="nh_success"><div class="nh_inner">'.__('This game has been transferred across to your server.').'</div></div>';
								$error=false;
							}
						}
					}
				}else{
					$error=false;
					echo '<div class="nh_error"><div class="nh_inner">'.__('This game is already on your server.').'</div></div>';
				}
				if($error) {
					echo '<div class="nh_error"><div class="nh_inner">'.__('There was an error transferring this game to your server.').'</div></div>';
				}
			}
			$cat_id='';
			$term='';
			$sub='';
			$page=1;
			if(isset($_GET['term'])) {
				$term=$_GET['term'];
				if(strlen($term)>0) {
					$sub=__('Search results for').' “'.$term.'”';
				}
			}
			$cats=array();
			try {
				$lcats=$newhaze->api('this/categories');
			}catch(newhazeException $ex) {}
			if(isset($lcats['data'])) {
				foreach($lcats['data'] as $lc) {
					$cats[$lc['id']]=$lc['name'];
				}
			}
			if(isset($_GET['cat_id'])) {
				$cat_id=$_GET['cat_id'];
				if($cat_id>0) {
					if(strlen($sub)>0) {
						$sub.=__(' in the category ').$cats[$cat_id];
					}else{
						$sub=__('Games in the category ').$cats[$cat_id];
					}
				}
			}
			$n=1;
			if(isset($_GET['pager'])) {
				$n=(int)$_GET['pager'];
			}
			$postids=array();
			$gdb = $wpdb->get_results("SELECT newhaze_id, post_id FROM ".$game_table);
			foreach($gdb as $ginfo) {
				$postids[$ginfo->newhaze_id]=$ginfo->post_id;
			}
			$q='';
			if($cat_id) {
				$q.='/category.id:'.$cat_id;
			}
			if($term) {
				$q.='/'.$term;
			}
			if(strlen($q)) {
				$q.='/';
			}
			$games=array();
			try {
				$response=$newhaze->api('this/games','get',array('query'=>$q,'limit'=>25,'offset'=>($n*25)-25,'info'=>true));
				if(isset($response['data'])) {
					$games=$response['data'];
				}
			}catch(newhazeException $e) {}
			$f=1;
			$l=ceil($response['info']['total']/25);?>
			<form id="posts-filter" action="" method="get">
				<p class="search-box">
					<label class="screen-reader-text" for="post-search-input"><? _e('Search Games');?>:</label>
					<input id="post-search-input" name="term" value="<? echo $term;?>" type="text" />
					<input name="page" value="newhaze-manage-games" type="hidden" />
					<input value="<? _e('Search Games');?>" class="button" type="submit" />
				</p>
				<div class="tablenav">
					<div class="alignleft actions">
						<select name="cat_id" id="cat" class="postform">
							<option value=""<? if(!$cat_id) {echo' selected';}?>><? _e('View all categories');?></option>
							<? foreach($cats as $id=>$cat) {?>
								<option class="level-0" value="<? echo $id;?>"<? if($cat_id==$id){echo' selected';}?>><? echo $cat;?></option>
							<? }?>
						</select>
						<input id="post-query-submit" value="<? _e('Filter');?>" class="button-secondary" type="submit" />
					</div>
					<div class="tablenav-pages">
						<span class="displaying-num"><? _e('Displaying');?> <? echo (($n*25)-24).'-'.((($n*25)-25)+count($games))." ".__('of').' '.($response['info']['total']);?></span>
						<? newhaze_pagination($n,$f,$l,'?page=newhaze-manage-games&term='.$term.'&cat_id='.$cat_id.'&pager=');?>
					</div>
				</div>
			</form>
			<table class="widefat" cellspacing="0">
				<thead>
					<tr> 
						<th scope="col" class="manage-column column-title"></th>
						<th scope="col" class="manage-column column-title"><? _e('Game');?></th>
						<th scope="col" class="manage-column column-title"><? _e('Category');?></th>
						<th scope="col" class="manage-column column-title"><? _e('Gameplays');?></th>
					</tr>
				</thead>
				<? foreach($games as $game) {?>
					<tr>
						<td scope="col"><img src="//api.newhaze.com/<?php echo $game['id'];?>/picture/50x50" height="50" width="50" /></td>
						<td class="post-title page-title column-title">
							<strong><a href="<?php echo $game['url'];?>" target="_blank"><?php echo $game['name']; ?></a></strong>
							<p><?php echo $game['description']; ?></p>
							<div class="row-actions">
								<? if(strpos(get_post_meta($postids[$game['id']],'swf_url', true),$blog_url)===false) {
									echo'<span class="edit"><a href="?page=newhaze-manage-games&action=upload&id='.$game['id'].'" title="Transfer this game\'s file">'.__('Transfer').'</a> | </span>';
								}?>
								<span class="trash"><a href="?page=newhaze-manage-games&action=delete&id=<?php echo $game['id'];?>" class="submitdelete" title="Permanently delete this game" href="">Delete Permanently</a></span>
							</div>
						</td>
						<td scope="col"><a href="?page=newhaze-manage-games&cat_id=<?php echo $game['category']['id'];?>"><?php echo $game['category']['name']; ?></a></td>
						<td scope="col"><?php echo $game['plays']; ?></td>
					</tr>
				<? }?>
				<tfoot>
					<tr> 
						<th scope="col" class="manage-column column-title"></th>
						<th scope="col" class="manage-column column-title"><? _e('Game');?></th>
						<th scope="col" class="manage-column column-title"><? _e('Category');?></th>
						<th scope="col" class="manage-column column-title"><? _e('Gameplays');?></th>
					</tr>
				</tfoot>
			</table>
			<h3><? _e('About "Transfer SWF"');?></h3>
			<p><? _e('"Tranfer SWF" enables you to transfer the game file to your own server. It means that you aren\'t reliant on newhaze, and if there was a problem with newhaze then your site would still work. Also, if people host games themselves, then newhaze will be quicker - otherwise everyone will be waiting for slow games! If you like, you can make a bit of money - if you use <a href="https://www.mochimedia.com/r/fdc824146fad7034" target="_blank">Mochi Ads</a>, you can set this domain up so you earn 10% from in-game advert clicks! It\'s so easy that there\'s no reason not to!');?></p>
<?		}?>
		</div>
	<? }else{
		;
	}
}
function newhaze_pagination($n,$f,$l,$url) {
	if($n>1) {
		echo'<a class="prev page-numbers" href="'.$url.($n-1).'">«</a> ';
	}
	if($n==$f) {
		echo'<span class="page-numbers current">'.$n.'</span> ';
	}else{
		echo'<a class="page-numbers" href="'.$url.$f.'">'.$f.'</a> ';
	}
	if(($n-2)>($f+1)) {
		echo'<span class="page-numbers dots">...</span> ';
	}
	for($x=($n-2);$x<=($n+2);$x++) {
		if($x>$f) {
			if($x<$l) {
				if($x==$n) {
					echo'<span class="page-numbers current">'.$n.'</span> ';
				}else{
					echo'<a class="page-numbers" href="'.$url.$x.'">'.$x.'</a> ';
				}
			}
		}
	}
	if($n+2<$l-1) {
		echo'<span class="page-numbers dots">...</span> ';
	}
	if($l>$f) {
		if($n==$l) {
			echo'<span class="page-numbers current">'.$n.'</span> ';
		}else{
			echo'<a class="page-numbers" href="'.$url.$l.'">'.$l.'</a> ';
		}
	}
	if($n<$l) {
		echo'<a class="next page-numbers" href="'.$url.($n+1).'">»</a> ';
	}
}
function newhaze_game_library() {
	if(newhaze_auth_needed()) {?>
		<div class="wrap">
			<div class="icon32" style="background:transparent url(<? echo WP_PLUGIN_URL;?>/newhaze/game_32.png) no-repeat;"><br></div>
		    <h2><? _e('Game Library');?> <span class="subtitle"><? echo $sub;?></span></h2>
		<? $newhaze=new newhaze(array('app_id'=>get_option('newhaze_consumer_key'),'app_secret'=>get_option('newhaze_consumer_secret')));
		$consumer_key=get_option('newhaze_consumer_key');
		if(isset($_POST['addtosite'])) {
			$success=0;
			global $wpdb;
			$game_table=$wpdb->prefix . "newhaze_gamemap";
			$ids=array();
			foreach($_POST as $key=>$value) {
				if($value) {
					$result=$newhaze->api("$value/games",'post',array('id'=>$key));
					if(isset($result['success']) and $result['success']) {
						$game=$newhaze->api($result['id']);
						if(isset($game['data'])) {
							$game=$game['data'];
							$post = array();
						    $post['post_title']=$game['name'];
						    $post['post_content']=get_option('nh_default_code');
							$post['post_content']=str_replace("{name}", $game['name'], $post['post_content']);
							$post['post_content']=str_replace("{description}", $game['description'], $post['post_content']);
							//select the swf
							$game['flash']=current($game['game_versions']);
							$post['post_content']=str_replace("{swf}", '<embed src="'.$game['flash']['file_url'].'" width="'.$game['flash']['width'].'" height="'.$game['flash']['height'].'" type="application/x-shockwave-flash">', $post['post_content']);
							$post['post_content']=str_replace("{fav-button}", "<div class=\"nh-fav\" data-game-id=\"".$game['id']."\"></div>", $post['post_content']);
							$post['post_content']=str_replace("{comments}", "<div class=\"nh-comments\" data-game-id=\"".$game['id']."\"></div>", $post['post_content']);
							$post['post_content']=preg_replace('/\{picture-(.*?)x(.*?)\}/i', '//api.newhaze.com/'.$game['id'].'/picture/$1x$2', $post['post_content']);
						    $post['post_status']='publish';
						    $post['post_type']='post';
							$wp_cat_id=get_cat_ID($game['category']['name']);
							if($wp_cat_id) {
								$post['post_category']=array($wp_cat_id);
							}
							$post_id = wp_insert_post($post);
							add_post_meta($post_id,'description',$game['description']);
						    add_post_meta($post_id,'height',$game['flash']['height']);
						    add_post_meta($post_id,'width',$game['flash']['width']);
						    add_post_meta($post_id,'swf_url',$game['flash']['file_url']);
							add_post_meta($post_id,'thumbnail_url','//api.newhaze.com/'.$game['id'].'/picture/100x100');
							add_post_meta($post_id,'nh_local_id',$game['id']);
							$query = "INSERT INTO ".$game_table." (post_id,newhaze_id) values ('$post_id','{$game['id']}')";
							$wpdb->query($query);
							$newhaze->api($game['id'],'post',array('url'=>get_permalink($post_id)));
							$success++;
						}
					}
				}
			}
			echo '<div class="nh_success"><div class="nh_inner">'.$success.' '.__('games were added successfully!').'</div></div>';
		}
		$cat_id='';
		if(isset($_GET['cat_id'])) {
			$cat_id=$_GET['cat_id'];
		}
		$cat_id='';
		$term='';
		$sub='';
		if(isset($_GET['term'])) {
			$term=$_GET['term'];
			if(strlen($term)>0) {
				$sub=__('Search results for').' “'.$term.'”';
			}
		}
		$cats=array();
		try {
			$c=$newhaze->api('newhaze/categories');
			if(isset($c['data'])) {
				foreach($c['data'] as $v) {
					$cats[$v['id']]=$v['name'];
				}
			}
		}catch(newhazeException $e) {}
		$already_added=array();
		try {
			$c=$newhaze->api('this/games',array('limit'=>0));
			if(isset($c['data'])) {
				foreach($c['data'] as $v) {
					$already_added[]=$v['parent_id'];
				}
			}
		}catch(newhazeException $e) {}
		if(isset($_GET['cat_id'])) {
			$cat_id=$_GET['cat_id'];
			if($cat_id>0) {
				if(strlen($sub)>0) {
					$sub.=__(' in the category '.$cats[$cat_id]);
				}else{
					$sub=__('Games in the category '.$cats[$cat_id]);
				}
			}
		}
		$n=1;
		if(isset($_GET['pager'])) {
			$n=(int)$_GET['pager'];
			if($n==0) {
				$n=1;
			}
		}
		$start=($n*100)-100;
		$games=array();
		$q='';
		if($cat_id) {
			$q.='/category.id:'.$cat_id;
		}
		if($term) {
			$q.='/'.$term;
		}
		if(strlen($q)) {
			$q.='/';
		}
		$total_games=0;
		try {
			$response=$newhaze->api('games',array('query'=>$q,'limit'=>100,'offset'=>$start,'info'=>true));
			if(isset($response['data'])) {
				$games=$response['data'];
				$total_games=$response['info']['total'];
			}
		}catch(newhazeException $e) {
		}
		$f=1;
		$l=ceil($total_games/100);
		$output='<option value="0" selected>'.__('None').'</option>';
		try {
			$usercats=$newhaze->api('this/categories');
		}catch(newhazeException $ex) {}
		if(isset($usercats['data'])) {
			foreach($usercats['data'] as $cat) {
				$output.='<option value="'.$cat['id'].'">'.$cat['name'].'</option>';
			}
		?>
		<p><? _e('This is the entire list of games on the newhaze network. Use the library to add games to your site. To add a game, choose the category from the menu on the right next to the game you want to add, then at the bottom of the page, there\'s a button which says "Add to Site" - click it to add the selected games to your site.');?></p>
		<?
		$featured=array();
		try {
			$fe=$newhaze->api('games/featured',array('limit'=>5));
			if(isset($fe['data'])) {
				$featured=$fe['data'];
			}
		}catch(newhazeException $e) {}
		if(count($featured)) {?>
		<div class="nh_featured">
		<ul>
			<? foreach($featured as $game) {?>
				<li><a href="?page=newhaze-game-library&term=id%3A<? echo $game['id'];?>"><div class="nh_img" style="background-image:url(//api.newhaze.com/<? echo $game['id'];?>/picture/150x100);"></div></a></li>
			<? }?>
		</ul>
		</div>
		<? }?>
		<form id="posts-filter" action="" method="get">
		<p class="search-box">
			<label class="screen-reader-text" for="post-search-input"><? _e('Search Library');?>:</label>
			<input id="post-search-input" name="term" value="<? echo $term;?>" type="text" />
			<input name="page" value="newhaze-game-library" type="hidden" />
			<input value="<? _e('Search Library');?>" class="button" type="submit" />
		</p>
		<div class="tablenav">
		<div class="alignleft actions">
		<select name="cat_id" id="cat" class="postform">
			<option value=""<? if(!$cat_id) {echo' selected';}?>><? _e('View all categories');?></option>
			<? foreach($cats as $id=>$cat) {?>
				<option class="level-0" value="<? echo $id;?>"<? if($cat_id==$id) {echo' selected';}?>><? _e($cat);?></option>
			<? }?>
		</select>
		<input id="post-query-submit" value="<? _e('Filter');?>" class="button-secondary" type="submit" />
		</div>
		<div class="tablenav-pages"><span class="displaying-num"><? _e('Displaying');?> <? echo (($n*100)-99).'-'.((($n*100)-100)+count($games))." ".__('of').' '.$total_games;?></span>
		<? newhaze_pagination($n,$f,$l,'?page=newhaze-game-library&term='.$term.'&cat_id='.$cat_id.'&pager=');?></div>
		</div>
	</form><form action="?page=newhaze-game-library&term=<? echo $term;?>&cat_id=<? echo $cat_id;?>&pager=<? echo $page;?>" method="post">
		<table class="widefat" cellspacing="0">
		      <thead>
		        <tr> 
		          <th scope="col" class="manage-column column-title"></th>
		          <th scope="col" class="manage-column column-title"><? _e('Game');?></th>
				  <th scope="col" class="manage-column column-title"><? _e('Category');?></th>
				  <th scope="col" class="manage-column column-title"><? _e('Plays');?></th>
				  <th scope="col" class="manage-column column-title" style="min-width:100px;"><? _e('Add to category...');?></th>
		        </tr>
		      </thead>
		<? foreach($games as $game) {?>
		      <tr>
		        <td scope="col"><img src="//api.newhaze.com/<? echo $game['id'];?>/picture/50x50" width="50" height="50" /></td>
		        <td scope="col">
					<strong><a href="<? echo $game['url'];?>" target="_blank"><? echo $game['name'];?></a></strong>
					<p><? echo $game['description']; ?></p>
				</td>
				<td scope="col"><a href="?page=newhaze-game-library&cat_id=<?php echo $game['category']['id'];?>"><?php echo __($game['category']['name']); ?></a></td>
				<td scope="col"><?php echo $game['plays']; ?></td>
		        <td scope="col"><? if(!in_array($game['id'],$already_added)) {?><select name="<? echo $game['id'];?>" class="postform"><? echo $output;?></select><? }else{_e("Already added");}?></td>
		    </tr>
		<? }?>
		<tfoot>
	        <tr> 
	          <th scope="col" class="manage-column column-title"></th>
		      <th scope="col" class="manage-column column-title"><? _e('Game');?></th>
			  <th scope="col" class="manage-column column-title"><? _e('Category');?></th>
		 	  <th scope="col" class="manage-column column-title"><? _e('Plays');?></th>
			  <th scope="col" class="manage-column column-title"><? _e('Add to category...');?></th>
	        </tr>
	      </tfoot>
		    </table>
			<div class="tablenav">
				<div class="alignleft actions">
				<input name="addtosite" value="true" type="hidden" />
				<input id="post-query-submit" value="<? _e('Add to Site');?>" class="button" type="submit">
				</div>
		<div class="tablenav-pages"><span class="displaying-num">		<? _e('Displaying');?> <? echo (($n*100)-99).'-'.((($n*100)-100)+count($games))." ".__('of').' '.$total_games;?></span>
		<? newhaze_pagination($n,$f,$l,'?page=newhaze-game-library&term='.$term.'&cat_id='.$cat_id.'&pager=');?></div></div>
		</div>
		<?php
	}else{?>
		<div class="nh_dialog_outer">
			<div class="nh_dialog">
				<div class="nh_dialog_inner">
						<div class="nh_dialog_title">
							<? _e('You need to add game categories before you can add games');?>
						</div>
						<div class="nh_dialog_note">
							Click "Categories" in the "Games" menu on the left hand side to get started.
						</div>
					</form>
				</div>
			</div>
		</div>
	<? }
	}
}
function newhaze_manage_categories() {
	if(newhaze_auth_needed()) {
		echo '<div class="wrap">';
		$newhaze=new newhaze(array('app_id'=>get_option('newhaze_consumer_key'),'app_secret'=>get_option('newhaze_consumer_secret')));
		?>
		<div class="icon32" style="background:transparent url(<? echo WP_PLUGIN_URL;?>/newhaze/game_32.png) no-repeat;"><br></div>
		<h2><? _e('Game Categories');?></h2>
		<?
		$action = $_POST['action'];
		if ($action == 'add') {
	    	$name=trim($_POST['name']);
			$result = $newhaze->api('this/categories','post',array('name'=>$name));
			if(isset($result['success']) and $result['success']) {
				$my_cat = array('cat_name' => $name, 'category_description' => "$name games");
				$my_cat_id = wp_insert_category($my_cat);
				if($my_cat_id) {
					echo '<div class="nh_success"><div class="nh_inner">'.__('A new category called').' <b>'.$name.'</b> '.__('has been added.').'</div></div>';
				}else{
					echo '<div class="nh_error"><div class="nh_inner">'.__('There was an error adding this category.').'</div></div>';
				}
			}else{
				echo '<div class="nh_error"><div class="nh_inner">'.__('There was an error adding this category. Please check there are no other categories with the same name.').'</div></div>';
			}
		}
		if($action=='delete' and isset($_POST['submit'])) {
			$id=$_POST['id'];
			$result=$newhaze->api($id);
			if(isset($result['data'])) {
				$oldinfo=$result['data'];
				$result = $newhaze->api($id,'delete');
				if(isset($result['success']) and $result['success']) {
					$wp_cat_id=get_cat_ID($oldinfo['name']);
					if($wp_cat_id) {
						$result=wp_delete_category($wp_cat_id);
					}
					echo '<div class="nh_success"><div class="nh_inner">'.__('This category has been successfully removed.').'</div></div>';
				}else{
					echo '<div class="nh_error"><div class="nh_inner">'.__('There was an error removing this category. Please check that the category exists.').'</div></div>';
				}
			}
		}
		$action = $_GET['action'];
		if($action=='delete') {
			$id=$_GET['id'];
			$result=$newhaze->api($id);
			if(isset($result['data'])) {?>
				<div class="nh_dialog_outer">
					<div class="nh_dialog">
						<div class="nh_dialog_inner">
							<form method="post" name="renamecat" action="?page=newhaze-manage-categories">
								<input type="hidden" name="action" value="delete" />
								<input type="hidden" name="id" value="<? echo $result['data']['id'];?>" />
								<div class="nh_dialog_title">
									<? _e('Are you sure you want to remove the game category');?> <? echo $result['data']['name'];?>?
								</div>
								<div class="nh_dialog_note">
									Please note, this will also remove all games within this category.
								</div>
								<div class="nh_dialog_buttons">
									<input class="button-primary" type="submit" name="submit" value="<? _e('Yes');?>" />
									<input class="button-secondary" type="submit" name="cancel" value="<? _e('No');?>" />
								</div>
							</form>
						</div>
					</div>
				</div>
			<? }
			?>
		<? }else{
			try {
				$cats=$newhaze->api('this/categories');
			}catch(newhazeException $e) {}
		?>
		<div id="col-container">
			<div id="col-right">
				<div class="col-wrap">
					<table class="widefat fixed" cellspacing="0">
						<thead>
							<tr>
								<th scope="col" class="manage-column column-title"><? _e('Name');?></th>
								<th scope="col" class="manage-column column-title"><? _e('Category ID');?></th>
							</tr>
						</thead>
						<? if(isset($cats['data'])) {
							foreach($cats['data'] as $cat) {?>
								<tr>
									<td scope="col">
										<strong><? echo $cat['name'];?></strong>
										<div class="row-actions">
											<span class="edit"><a href="?page=newhaze-manage-games&cat_id=<? echo $cat['id'];?>" title="View games in this category"><? _e('View Games');?></a> | </span>
											<span class="trash"><a href="?page=newhaze-manage-categories&action=delete&id=<?php echo $cat['id'];?>" class="submitdelete" title="Permanently delete this category" href="">Delete Permanently</a></span>
										</div>
									</td>
									<td scope="col"><?php echo $cat['id'];?></td>
								</tr>
						<?	}
						}?>
						<tfoot>
							<tr>
								<th scope="col" class="manage-column column-title"><? _e('Name');?></th>
								<th scope="col" class="manage-column column-title"><? _e('Category ID');?></th>
							</tr>
						</tfoot>
					</table>
				</div>
			</div>
			<div id="col-left">
				<div class="col-wrap">
					<div class="form-wrap">
						<h3><? _e('Add New Category');?></h3>
						<form method="post" name="addcat">
							<input type="hidden" name="action" value="add" />
							<div class="form-field">
								<label for="cat_name"><? _e('Name');?></label>
								<input type="text" name="name" id="cat_name" />
								<p><? _e('We recommend not including the word "Games" in the name');?></p>
							</div>
							<p class="submit">
								<input class="button" type="submit" name="submit" value="<? _e('Add New Category');?>">
							</p>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php }?>
	</div>
	<? }
}
function newhaze_install() {
	add_option("newhaze_consumer_key", '', '', 'yes');
	add_option("newhaze_consumer_secret", '', '', 'yes');
	global $wpdb;
	$game_table=$wpdb->prefix . "newhaze_gamemap";
	if($wpdb->get_var("show tables like '$game_table'")!=$game_table) {
		$sql = "CREATE TABLE `$game_table` (`post_id` int(11) NOT NULL, `newhaze_id` varchar(50) NOT NULL, PRIMARY KEY  (`post_id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
	    dbDelta($sql);
	}else{
		$sql = "ALTER TABLE `$game_table` CHANGE `newhaze_id` `newhaze_id` VARCHAR(50) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL";
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}
	add_option('nh_default_code',"<p>\n<img src=\"{picture-50x50}\" width=\"50\" height=\"50\" style=\"float:left;margin-right:5px;\" />\n{description}\n</p>\n<!--more-->\n<p>{swf}</p>\n<p align=\"right\">{fav-button}</p>\n<p>{comments}</p>");
}
function nh_outputbool($val) {
	if($val) {
		return "true";
	}else{
		return "false";
	}
}

//template tags

function nh_advert($width=728, $height=90, $echo=true) {
	$consumer_key=get_option('newhaze_consumer_key');
	$output="<script type=\"text/javascript\">
	var nh_ad_width=$width;
	var nh_ad_height=$height;
	var nh_app_key='$consumer_key';
	</script>
	<script type=\"text/javascript\" src=\"//ads.newhaze.com/d.js\"></script>";
	if($echo) {
		echo $output;
	}else{
		return $output;
	}
}
function nh_comments($a=0, $b=0, $echo=true) {
	$game_id=get_post_meta(get_the_ID(),'nh_local_id',true);
	$output="<div class=\"nh-comments\" data-game-id=\"".$game_id."\"></div>";
	if($echo) {
		echo $output;
	}else{
		return $output;
	}
}
function nh_favorite($a='',$echo=true) {
	$game_id=get_post_meta(get_the_ID(),'nh_local_id',true);
	if($width>0 and $height>0) {
		$output="<div class=\"nh-fav\" data-game-id=\"".$game_id."\"></div>";
		if($echo) {
			echo $output;
		}else{
			return $output;
		}
	}
}
function nh_game_pic_url($width=100, $height=100, $echo=true) {
	$game_id=get_post_meta(get_the_ID(),'nh_local_id',true);
	$output="//api.newhaze.com/$game_id/picture/{$width}x{$height}";
	if($echo) {
		echo $output;
	}else{
		return $output;
	}
}
//widgets
class widget_nh_advert extends WP_Widget {
	function widget_nh_advert() {
		parent::WP_Widget(false, $name = 'newhaze Advert');
    }
    function widget($args, $instance) {
		extract($args, EXTR_SKIP);
		echo $before_widget;
		$title = empty($instance['title']) ? ' ' : apply_filters('widget_title', $instance['title']);
		if(!empty($title)) {echo $before_title . $title . $after_title; };
		$consumer_key=get_option('newhaze_consumer_key');
		echo"<div align='center'>".nh_advert($instance['width'], $instance['height'], false)."</div>";
		echo $after_widget;
	}
	function update($new_instance, $old_instance) {
		$instance = $old_instance;
		$instance['title'] = strip_tags($new_instance['title']);
		$instance['width'] = (int) strip_tags($new_instance['width']);
		$instance['height'] = (int) strip_tags($new_instance['height']);
		return $instance;
	}
	function form($instance) {
		$instance = wp_parse_args( (array) $instance, array( 'title' => '', 'width' => '', 'height' => '' ) );
		$title = strip_tags($instance['title']);
		$width = (int) strip_tags($instance['width']);
		$height = (int) strip_tags($instance['height']);?><p><label for="<?php echo $this->get_field_id('title'); ?>">Title: <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo attribute_escape($title); ?>" /></label></p>
<p><label for="<?php echo $this->get_field_id('width'); ?>">Width: <input class="widefat" id="<?php echo $this->get_field_id('width'); ?>" name="<?php echo $this->get_field_name('width'); ?>" type="text" value="<?php echo attribute_escape($width); ?>" /></label></p>
<p><label for="<?php echo $this->get_field_id('height'); ?>">Height: <input class="widefat" id="<?php echo $this->get_field_id('height'); ?>" name="<?php echo $this->get_field_name('height'); ?>" type="text" value="<?php echo attribute_escape($height); ?>" /></label></p><?php
	}
}
?>