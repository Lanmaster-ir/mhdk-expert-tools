<?php
/**
 * Plugin Name: Expert Tools
 * Version: 1.0
 * Description: Expert Tools
 * Author: Mohammad Hosein Darvishzadeh
 * Author URI: 
 * Text Domain: mhdk-expert-tools
 *
 */
 
define( "MHDKET_SLUG" , "mhdk-expert-tools" );
define( "MHDKET_TITLE" , "IranExperTools" );
define( "MHDKET_ICON" , "dashicons-privacy" );



class MHDKET_PLUGIN {
	
	private $table = 'mhdk_table_requests';
	static $instance = false;
	
    public function __construct() {
		
        
			
        register_activation_hook( __FILE__ , array( $this, 'activate'));
			
        register_deactivation_hook( __FILE__ , array( $this, 'deactivate' ) );

		add_action('admin_menu', array( $this , 'mhdk_menu' ) );

		add_action('admin_enqueue_scripts', array( $this , 'mhdk_load_datepicker' ) );
			
		add_action( 'init', array ( $this , 'mhdk_init' ) );
		add_action( 'init', array ( $this , 'disable_action_scheduler' ) , 40 );
		
		add_action( 'before_delete_post',  array ( $this , 'mhdk_before_delete' ) , 110, 2 );
		add_action( 'delete_post',  array ( $this , 'mhdk_before_delete' ) , 110, 2 );
		add_action( 'trash_post',  array ( $this , 'mhdk_before_delete' ) , 110, 2 );

		add_action( 'before_delete_product',  array ( $this , 'mhdk_before_delete' ) , 120, 2 );
		add_action( 'delete_product',  array ( $this , 'mhdk_before_delete' ) , 120, 2 );
		add_action( 'trash_product',  array ( $this , 'mhdk_before_delete' ) , 120, 2 );
		
		add_filter( 'rest_authentication_errors', array ( $this , 'disable_rest_api' ) );
		
		add_filter('xmlrpc_enabled', '__return_false');
		add_action('wp_loaded', array ( $this , 'disable_xmlrpc_access' ) );
		
    }

	public static function getInstance() {
		
		if ( !self::$instance )
			self::$instance = new self;
		
		return self::$instance;
		
	}

	
	public function mhdk_init(){
		
		remove_action( 'xmlrpc_rsd_apis', 'rest_output_rsd' );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		
		remove_action( 'wp_scheduled_delete', 'wp_scheduled_delete' );
		
		$mhdk_delete_role = ( ( get_option('mhdk_delete_access') ) ? get_option('mhdk_delete_access') : '' );
		
		$prevent_delete =  ( ( is_super_admin() ) ? false : true );
		
		if ( $mhdk_delete_role != '' && $prevent_delete )
			$prevent_delete = current_user_can( $mhdk_delete_role ) ? false : true;

		if ( $prevent_delete )
			add_filter ('user_has_cap', array ( $this , 'prevent_deletion_filter' ), 10, 3);
		
		
	}
	
	public function disable_rest_api( $access ) {
		
		if ( ! is_user_logged_in() )
			return new WP_Error(	'rest_disabled',
									__('The WordPress REST API has been disabled.') , 
									array( 'status' => rest_authorization_required_code() ) );
								
	}
		
	
	
	public function disable_action_scheduler() {
		
		if ( class_exists( 'ActionScheduler' ) ) {
			remove_action( 'action_scheduler_run_queue', array( ActionScheduler::runner(), 'run' ) );
		}
		
	}	
	
	public function disable_xmlrpc_access(){

		$page = $this->get_current_page();

		if ($page === 'xmlrpc.php') {
			$header_one = apply_filters('dsxmlrpc_header_1', 'HTTP/1.0 404 Not Found');
			$header_two = apply_filters('dsxmlrpc_header_2', 'Status: 404 Not Found');

			header($header_one);
			header($header_two);

			exit();
		}
		
		/*
		//else if from custom
		@define('NO_CACHE', true);
		@define('WTC_IN_MINIFY', true);
		@define('WP_CACHE', false);

		// Prevent errors from defining constants again
		error_reporting(E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR);

		include ABSPATH.'/xmlrpc.php';

		exit();
		*/

	}
	
	
	public function get_current_page(){

		$blog_url = trailingslashit( get_bloginfo('url') );

		// Build the Current URL
		$url = (is_ssl() ? 'https://' : 'http://').$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];

		if(is_ssl() && preg_match('/^http\:/is', $blog_url)){
			$blog_url = substr_replace($blog_url, 's', 4, 0);
		}

		// The relative URL to the Blog URL
		$req = str_replace($blog_url, '', $url);
		$req = str_replace('index.php/', '', $req);

		// We dont need the args
		$parts = explode('?', $req, 2);
		$relative = basename($parts[0]);

		// Remove trailing slash
		$relative = rtrim($relative, '/');
		$tmp = explode('/', $relative, 2);
		$page = end($tmp);

		return $page;

	}
	
	
	public function prevent_deletion_filter($allcaps, $caps, $args) {

		$delete_cap_array = array( "delete_post" , "delete_product" , "delete_page" );
		
		if ( isset( $args[0] ) && in_array( $args[0] , $delete_cap_array ) ) {
			$allcaps[ $caps[0] ] = false;
		}
		
		return $allcaps;
	}
	
	
	public function mhdk_before_delete( $post_id, $post ) {
		  
		$this->save_delete_request( $post_id , $post->post_type , get_permalink( $post_id ) );
	
	}
	
	private function save_delete_request( $post_id , $post_type , $post_link ){
		
		global $wpdb;
		
		$ip = $_SERVER['REMOTE_ADDR'];
		$agent = $_SERVER['HTTP_USER_AGENT'];
		$time = date("Y-m-d H:i:s");
		
		
		$wpdb->insert( $this->getTableName() , array(
			'request_ip' => $ip,
			'request_agent' => $agent,
			'request_time' => $time, 
			'request_user' => get_current_user_id(), 
			'request_type' => $post_type, 
			'request_content_id' => $post_id, 
			'request_link' => $post_link, 
		));
		
	}
	
	
	public function mhdk_load_datepicker(){
		
		wp_enqueue_style( 'mhdk_reports_styles', plugins_url( 'vendors/dp/persianDP.css', __FILE__), array() );
		
		wp_enqueue_script( 'mhdk_reports_scripts',  plugins_url( 'vendors/dp/persianDP.min.js', __FILE__) , array( 'jquery' ) );
		
		wp_enqueue_script( 'mhdk_reports_dp_script',  plugins_url( 'vendors/dp/dp.js', __FILE__)  , array( 'jquery' ) );

	}

	public function mhdk_menu() { 
		
		if ( is_super_admin() || current_user_can( 'administrator' ) )
			add_menu_page( IET_TITLE , IET_TITLE , 'manage_options', 'mhdk_plugin', '', IET_ICON);
		
		if ( is_super_admin() || current_user_can( 'administrator' ) )
			add_submenu_page( 'mhdk_plugin', 'تنظیمات افزونه' , 'تنظیمات افزونه', 'manage_options', 'mhdk_plugin', array ( $this , "mhdk_settings" ) );
		
		if ( is_super_admin() || current_user_can( 'administrator' ) )
			add_submenu_page( 'mhdk_plugin', 'گزارشات' , 'گزارشات', 'manage_options', 'mhdk_report', array( $this , "mhdk_reports" ) );
		
	}
	
	public function mhdk_settings(){
		$error = $success = '';
			
		if ( isset( $_POST['mhdk_setting_submit'] ) && isset( $_POST['mhdk_setting_nonce'] ) && wp_verify_nonce( $_POST['mhdk_setting_nonce'], 'mhdk_setting_act' ) ) {
			
			update_option( "mhdk_delete_access" , $_POST['mhdk_delete_access'] );
			
			echo '
				<div class="notice notice-success is-dismissible">
					<p>' . __('تنظیمات افزونه با موفقیت ذخیره شد') . '</p>
				</div>
				';

		}
		

		echo '		
				<div class="wrap wpp-settings-wrap">
					<h2><i class="dashicons dashicons-admin-generic"></i> تنظیمات افزونه</h2>
					
					<hr>
					
					
					<form method="post" action="">
						<input type="hidden"  naame="page"  value="mhdk_plugin">
			';
			
		wp_nonce_field( 'mhdk_setting_act', 'mhdk_setting_nonce' );	
		
		echo '
						<table class="form-table">
							
							<tbody>
							
								<tr>
									<th scope="row">مجوز دسترسی به حذف</th>
									<td>
										<select name="mhdk_delete_access" id="mhdk_delete_access">';
											
											$mhdk_delete_role = '';
											if ( get_option('mhdk_delete_access') )
												$mhdk_delete_role = get_option('mhdk_delete_access');
											wp_dropdown_roles( $mhdk_delete_role );
		
					echo '
										</select>
										<p class="description"><i class="dashicons dashicons-info"></i> فقط مدیر کل و کاربران دارای این نقش قادر به حذف محتوا خواهند بود.</p>
									</td>
								</tr>
								
							</tbody>
						</table>
						
						<p class="submit"><input type="submit" name="mhdk_setting_submit" id="mhdk_setting_submit" class="button button-primary" value="ذخیرهٔ تغییرات"></p>
					</form>
					
				</div>
			';
		
	}



	public function mhdk_reports(){
		
		$items_per_page = 20;
		$total_items = 0;
		
		echo '		
				<div class="wrap wpp-settings-wrap">
					<h2><i class="dashicons dashicons-filter"></i> ' . __('گزارشات ')  .  '</h2>
					
					<hr>
					
					<div class="manage-menus">
						<form class="form-horizontal" method="get" action="">
							<input type="hidden" name="page" value="mhdk_report">
							<div>
								بازه زمانی از 
								<input type="text" name="mhdk_report_from_date" id="mhdk_report_from_date" class="input ltr" placeholder="' . date("yy-m-d")  . '" autocomplete="off" value="'. ( (isset($_GET['mhdk_report_from_date'])) ? $_GET['mhdk_report_from_date'] : '' ) .'" required>
								تا 
								<input type="text" name="mhdk_report_to_date" id="mhdk_report_to_date" class="input ltr" placeholder="' . date("yy-m-d")  . '" autocomplete="off" value="'. ( (isset($_GET['mhdk_report_to_date'])) ? $_GET['mhdk_report_to_date'] : '' ) .'" required>
								<input type="submit" name="mhdk_submit_report" style="" class="button" value="' . __('نمایش گزارش') . '"/>
							</div>
						</form>
					</div>
					
					<hr>
					
					<br class="clear">
				';
		
		
		
		if ( isset($_GET['mhdk_submit_report']) &&  isset($_GET['mhdk_report_from_date']) && isset($_GET['mhdk_report_to_date']) ){
			
			$from_date = $_GET['mhdk_report_from_date'] . ' 00:00:00';
			$to_date = $_GET['mhdk_report_to_date'] . ' 23:59:59';
			
			global $wpdb;
			
			$sql = "SELECT * 
					FROM " . $this->getTableName()  . " 
					WHERE ( request_time BETWEEN  '" . $from_date . "' and '" . $to_date . "' ) 
					";

			$total_items = $wpdb->get_var( "SELECT COUNT(1) FROM (" . $sql . ") AS DataTable" );
			
			
			$paged = ( isset( $_GET['paged'] ) && is_numeric( $_GET['paged'] ) ) ? $_GET['paged'] : 1;
			$offset = ( $paged * $items_per_page ) - $items_per_page;
			$pages = ceil ( $total_items / $items_per_page);
			
			
			$records = $wpdb->get_results( $sql . " ORDER BY request_time DESC LIMIT " . $offset . "," . $items_per_page );
			
		}
		
		

		echo '
					<div class="tablenav-pages alignright">
						<span class="displaying-num">' . $this->mhdk_persian_nums( $total_items ) . ' مورد</span>
			';
		
		if ( $total_items > $items_per_page ){
				echo '
						<span class="pagination-links">';
				if ( $pages > 1 && $paged > 1  ) {
					echo '
							<a class="next-page button" href="?page=mhdk_report&paged=1&mhdk_report_from_date=' . $_GET['mhdk_report_from_date'] . '&mhdk_report_to_date=' . $_GET['mhdk_report_to_date'] . '&mhdk_submit_report=1" class=" button disabled">
								<span class="screen-reader-text">اولین برگه</span>
								<span aria-hidden="true">«</span>
							</a>
							<a class="next-page button" href="?page=mhdk_report&paged=' . ( $paged - 1 ) . '&mhdk_report_from_date=' . $_GET['mhdk_report_from_date'] . '&mhdk_report_to_date=' . $_GET['mhdk_report_to_date'] . '&mhdk_submit_report=1" class=" button disabled">
								<span class="screen-reader-text">برگه قبلی</span>
								<span aria-hidden="true">‹</span>
							</a>
					
						';
				}else{
					echo '
							<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
							<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
						';
				}
				
				echo '
							<span class="paging-input">
								<label for="current-page-selector" class="screen-reader-text">برگهٔ فعلی</label>
								<span class="current-page" id="current-page-selector" >' . $this->mhdk_persian_nums( $paged ) . '</span>
								<span class="tablenav-paging-text"> از <span class="total-pages">' . $this->mhdk_persian_nums( $pages ) . '</span> </span>
							</span>
						';
						
				if ( $pages > 1 && $paged < $pages ) {
					echo '
							<a class="next-page button" href="?page=mhdk_report&paged=' . ( $paged + 1 ) . '&mhdk_report_from_date=' . $_GET['mhdk_report_from_date'] . '&mhdk_report_to_date=' . $_GET['mhdk_report_to_date'] . '&mhdk_submit_report=1" class=" button disabled">
								<span class="screen-reader-text">برگهٔ بعدی</span>
								<span aria-hidden="true">›</span>
							</a>
							<a class="next-page button" href="?page=mhdk_report&paged=' . $pages . '&mhdk_report_from_date=' . $_GET['mhdk_report_from_date'] . '&mhdk_report_to_date=' . $_GET['mhdk_report_to_date'] . '&mhdk_submit_report=1" class=" button disabled">
								<span class="screen-reader-text">آخرین برگه</span>
								<span aria-hidden="true">»</span>
							</a>
						';
				}else{
					echo '
							<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
							<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>
						';
				}
				echo '
						</span>
					';
		}
				
		echo '
					</div>
			';
			

		echo '
					<br class="clear">
					<br class="clear">
					
					<form action="">
						<table class="wp-list-table widefat striped responsive table-view-list posts">
								<thead>
									<tr>
										<td scope="col" id="user" class="manage-column column-user column-primary">
											<span>نام کاربر</span>
										</td>
										<td scope="col" id="content" class="manage-column column-contents">
											<span>محتوا</span>
										</td>									
										<td scope="col" id="ip" class="manage-column column-ip column-primary">
											<span>آی  پی</span>
										</td>
										<td scope="col" id="date" class="manage-column column-last-date column-primary">
											<span>تاریخ درخواست</span>
										</td>
									</tr>
								</thead>
								
								<tbody id="the-list">
			';
		
		if ( $total_items == 0 || !isset($_GET['mhdk_submit_report']) ){
			echo '
					<tr class="iedit author-self type-product status-publish ">
						<td colspan="4">
							<span class="inline aligncenter"><i class="dashicons dashicons-info"></i> ' . __('هیچ اطلاعاتی جهت نمایش وجود ندارد.') . '</span>
						</td>
					</tr>
				';
		}else{
			
			if( $total_items  > 0 ) {
				
				foreach ( $records as $record ) {
					$wp_user = get_user_by(  'id' , $record->request_user );
					echo '
							<tr class="iedit author-self type-product status-publish ">
								<td class="column-user" data-colname="کاربر">
									<span>' . $wp_user->user_login . ' (' . $wp_user->display_name . ')</span>
								</td>
								<td class="column-content" data-colname="محتوا">
									<a href= "' . $record->request_link . '" target="_blank"> 
										<i class="dashicons dashicons-admin-links"></i>  
										<span>' . $record->request_type . '</span>
									</a> 
								</td>
								<td class="column-ip" data-colname="آی پی">
									<span>' . $record->request_ip . '</span>
								</td>
								<td class="column-date" data-colname="تاریخ درخواست">
									<span> <i class="dashicons dashicons-clock"></i> 
										' . $this->mhdk_persian_nums( wp_date( 'Y/m/d H:i:s' , strtotime($record->request_time) ) ) . '
									</span>
								</td>							
							</tr>
					';
			
				}
				
				wp_reset_postdata();
				
			}
				
		}

		echo '
								</tbody>
								<tfoot>
									<tr>
										<td scope="col" id="user" class="manage-column column-user column-primary">
											<span>نام کاربر</span>
										</td>
										<td scope="col" id="content" class="manage-column column-contents">
											<span>محتوا</span>
										</td>									
										<td scope="col" id="ip" class="manage-column column-ip column-primary">
											<span>آی  پی</span>
										</td>
										<td scope="col" id="date" class="manage-column column-last-date column-primary">
											<span>تاریخ درخواست</span>
										</td>
									</tr>
								</tfoot>
						</table>				
					</form>
					
				</div>
			';
		
		
	}	
	
	private function mhdk_persian_nums($number) {
		
		$en_numbrers = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
		$fa_numbrers = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
		
		return str_replace($en_numbrers, $fa_numbrers, $number);
		
	}	
	
	private function getTableName(){
		global $wpdb;
		return $wpdb->prefix . $this->table;
		
	}
	
    public function activate() {
		
        $this->create_table( true );
		
    }   

    private function create_table( $check_exists = false ) {
		
        global $wpdb;
		
        $table = $this->getTableName();
		
        $charset = $wpdb->get_charset_collate();
        $charset_collate = $wpdb->get_charset_collate();
		
		$check_exists_statement = '';
		if ( $check_exists )
			$check_exists_statement = ' IF NOT EXISTS ' ;
		
		
        $sql = "CREATE TABLE $check_exists_statement $table (
				request_id int(11) NOT NULL AUTO_INCREMENT,
				request_time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
				request_user int(11) NOT NULL,
				request_ip  varchar(15) DEFAULT '' NOT NULL,
				request_agent  varchar(200) DEFAULT '' NOT NULL,
				request_type  varchar(10) DEFAULT '' NOT NULL,
				request_content_id int(11) NOT NULL,
				request_link varchar(1000) DEFAULT '' NOT NULL,
				PRIMARY KEY  (request_id)
				) $charset_collate;";
				
		if ( !function_exists('dbDelta') )
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
				
        dbDelta( $sql );
		
    }   

    private function drop_table() {
		
        global $wpdb;
		
        $table = $this->getTableName();
		
        $sql = "DROP TABLE IF EXISTS $table";
		
        $wpdb->query($sql);
		
    } 


    public function deactivate() {

    } 	

}

$IET_PLUGIN = IET_PLUGIN::getInstance();