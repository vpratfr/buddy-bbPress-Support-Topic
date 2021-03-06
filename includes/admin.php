<?php

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

if ( !class_exists( 'BP_bbP_ST_Admin' ) ) :
/**
 * Loads Buddy-bbPress Support Topic plugin admin area
 *
 * @package    Buddy-bbPress Support Topic
 * @subpackage Administration
 * 
 * @since      2.0
 */
class BP_bbP_ST_Admin {

	/**
	 * The admin loader
	 *
	 * @since 2.0
	 *
	 * @uses  BP_bbP_ST_Admin::setup_actions() to add some key hooks
	 * @uses  BP_bbP_ST_Admin::maybe_activate() to eventually load the welcome screen
	 */
	public function __construct() {
		$this->setup_actions();
		$this->maybe_activate();
	}

	/**
	 * Setup the admin hooks, actions and filters
	 *
	 * @since  2.0
	 * @access private
	 *
	 * @uses   bbp_is_deactivation() to prevent interfering with bbPress deactivation process
	 * @uses   add_action() To add various actions
	 * @uses   add_filter() To add various filters
	 */
	private function setup_actions() {

		if ( bbp_is_deactivation() )
			return;

		// forums metabox
		add_action( 'bbp_forum_attributes_metabox',             array( $this, 'forum_meta_box_register' ),      10    );
		add_action( 'bbp_forum_attributes_metabox_save',        array( $this, 'forum_meta_box_save' ),          10, 1 );
		// filters a users query to only get forum moderators ( Keymasters+moderators )
		add_action( 'pre_user_query',                           array( $this, 'filter_user_query' ),            10, 1 );
		// enqueues a js script to hide show recipients
		add_action( 'load-post.php',                            array( $this, 'enqueue_forum_js'  )                   );
		add_action( 'load-post-new.php',                        array( $this, 'enqueue_forum_js'  )                   );

		// topics metabox
		add_action( 'bbp_topic_metabox',                        array( $this, 'topic_meta_box' ),               10, 1 );
		add_action( 'bbp_topic_attributes_metabox_save',        array( $this, 'topic_meta_box_save' ),          10, 2 );

		// moving a topic from the admin
		add_action( 'save_post',                                array( $this, 'topic_moved' ),                   9, 2 );

		// topics list columns
		add_filter( 'bbp_admin_topics_column_headers',          array( $this, 'topics_admin_column' ),          10, 1 );
		add_action( 'bbp_admin_topics_column_data',             array( $this, 'topics_column_data' ),           10, 2 );

		// topics list filter by support status
		add_action( 'restrict_manage_posts',                    array( $this, 'topics_admin_support_filter' ),  11    );
		add_filter( 'bbp_request',                              array( $this, 'topics_admin_support_request' ), 11, 1 );

		// topics bulk edit
		add_action( 'bulk_edit_custom_box',                     array( $this, 'bulk_topics_support' ),          10, 2 );
		add_action( 'load-edit.php',                            array( $this, 'bulk_update_support' )                 );

		// Dashboard right now bbPress widget
		add_action( 'bbp_dashboard_widget_right_now_table_end', array( $this, 'dashboard_widget' )                    );

		// Welcome Screen
		add_action( 'bbp_admin_menu',                           array( $this, 'welcome_screen_register' )             );
		add_action( 'bbp_admin_head',                           array( $this, 'welcome_screen_css' )                  );
		add_filter( 'plugin_action_links',                      array( $this, 'modify_plugin_action_links' ),   10, 2 );

	}

	/**
	 * Registers a new metabox in Forum's edit form (admin)
	 *
	 * @since 2.0
	 * 
	 * @uses  add_meta_box() to add the metabox to forum edit screen
	 * @uses  bbp_get_forum_post_type() to get forum post type
	 */
	public function forum_meta_box_register() {

		add_meta_box (
			'bpbbpst_forum_settings',
			__( 'Support settings', 'buddy-bbpress-support-topic' ),
			array( &$this, 'forum_meta_box_display' ),
			bbp_get_forum_post_type(),
			'normal',
			'low'
		);

	}

	/**
	 * Displays the content for the metabox
	 *
	 * @since 2.0
	 * 
	 * @param object $forum the forum object
	 * @uses  bpbbpst_get_forum_support_setting() to get forum support setting
	 * @uses  bpbbpst_display_forum_setting_options() to list the available support settings
	 * @uses  bpbbpst_checklist_moderators() to list the bbPress keymasters and moderators
	 * @uses  do_action_ref_array() to let plugins or themes add some actions
	 */
	public function forum_meta_box_display( $forum = false ) {
		if( empty( $forum->ID ) )
			return;

		$support_feature = bpbbpst_get_forum_support_setting( $forum->ID );

		$style = ( $support_feature == 3 ) ? 'style="display:none"' : false;

		bpbbpst_display_forum_setting_options( $support_feature );
		?>
		<div class="bpbbpst-mailing-list" <?php echo $style;?>>
			<h4><?php _e( 'Who should receive an email notification when a new support topic is posted ?', 'buddy-bbpress-support-topic' );?></h4>

			<?php bpbbpst_checklist_moderators( $forum->ID );?>
		</div>
		<?php

		do_action_ref_array( 'bpbbpst_forum_support_options', array( $forum->ID, $style ) );
	}

	/**
	 * Saves the forum metabox datas
	 *
	 * @since  2.0
	 * 
	 * @param  integer $forum_id the forum id
	 * @uses   update_post_meta() to save the forum support setting
	 * @uses   delete_post_meta() to eventually delete a setting if needed
	 * @uses   do_action() to let plugins or themes do stuff from this point
	 * @return integer           the forum id
	 */
	public function forum_meta_box_save( $forum_id = 0 ) {
		
		$support_feature = intval( $_POST['_bpbbpst_forum_settings'] );
	
		if( !empty( $support_feature ) ) {
			update_post_meta( $forum_id, '_bpbbpst_forum_settings', $support_feature );

			if( $support_feature == 3 )
				delete_post_meta( $forum_id, '_bpbbpst_support_recipients' );
			else {
				$recipients = !empty( $_POST['_bpbbpst_support_recipients'] ) ? array_map( 'intval', $_POST['_bpbbpst_support_recipients'] ) : false ;

				if( !empty( $recipients ) && is_array( $recipients ) && count( $recipients ) > 0 )
					update_post_meta( $forum_id, '_bpbbpst_support_recipients', $recipients );
				else
					delete_post_meta( $forum_id, '_bpbbpst_support_recipients' );
					
			}

			do_action( 'bpbbpst_forum_settings_updated', $forum_id, $support_feature );
		}
		
		return $forum_id;
	}

	/**
	 * Adds a js to WordPress scripts queue
	 *
	 * @since 2.0
	 * 
	 * @uses  get_current_screen() to be sure we are in forum post screen
	 * @uses  bbp_get_forum_post_type() to get the forum post type
	 * @uses  wp_enqueue_script() to add the js to WordPress queue
	 * @uses  bpbbpst_get_plugin_url() to build the path to plugin's js folder
	 * @uses  bpbbpst_get_plugin_version() to get plugin's version
	 */
	public function enqueue_forum_js() {

		if ( !isset( get_current_screen()->post_type ) || ( bbp_get_forum_post_type() != get_current_screen()->post_type ) )
			return;

		wp_enqueue_script( 'bpbbpst-forum-js', bpbbpst_get_plugin_url( 'js' ) . 'bpbbpst-forum.js', array( 'jquery' ), bpbbpst_get_plugin_version() );
	}

	/**
	 * Hooks pre_user_query to build a cutom meta_query to list forum moderators
	 * 
	 * First checks for who arguments to be sure we're requesting forum moderators
	 * 
	 * @since  2.0
	 * 
	 * @global object $wpdb (the database class)
	 * @global integer the current blog id
	 * @param  object $query the user query arguments
	 * @uses   bbp_get_keymaster_role() to get keymaster role
	 * @uses   bbp_get_moderator_role() to get moderator role
	 * @uses   WP_Meta_Query::get_sql() to rebuild the user meta query
	 */
	public function filter_user_query( $query = false ) {
		global $wpdb, $blog_id;

		if( empty( $query->query_vars['who'] ) || 'bpbbpst_moderators' != $query->query_vars['who'] )
			return;

		$meta_key = $wpdb->get_blog_prefix( $blog_id ) . 'capabilities';

		$meta_query = array(
			'relation' => 'OR',
			array(
				'key' => $meta_key,
				'value' => bbp_get_keymaster_role(),
				'compare' => 'LIKE'
			),
			array(
				'key' => $meta_key,
				'value' => bbp_get_moderator_role(),
				'compare' => 'LIKE'
			),
		);

		$role_meta_query = new WP_Meta_Query( $meta_query );
		$meta_sql = $role_meta_query->get_sql( 'user', $wpdb->users, 'ID' );

 		$query->query_fields = "DISTINCT {$wpdb->users}.ID, ". $query->query_fields;

 		if( is_multisite() ) {
 			/**
 			 this is an ugly fix but i need more time to investigate
 			 */
 			$fixdoubleusermeta = str_replace( "FROM {$wpdb->users} ", '', $query->query_from );
 			if( strpos( $meta_sql['join'], $fixdoubleusermeta ) !== false && !empty( $fixdoubleusermeta ) )
 				$meta_sql['join'] = str_replace( $fixdoubleusermeta, '', trim( $meta_sql['join'] ) );
 		}
 		
		$query->query_from .= $meta_sql['join'];
		$query->query_where .= $meta_sql['where'];
	}

	/**
	 * Adds a selectbox to update the support status to topic attributes metabox
	 *
	 * @since 2.0
	 * 
	 * @param integer $topic_id the topic id
	 * @uses  bbp_get_topic_forum_id() to get the parent forum id
	 * @uses  bpbbpst_get_forum_support_setting() to get the support setting of the parent forum
	 * @uses  get_post_meta() to get the previuosly stored topic support status
	 * @uses  bpbbpst_get_selectbox() to build the support status selectbox
	 */
	public function topic_meta_box( $topic_id = 0 ) {
		// Since 2.0, we first need to check parent forum has support for support :)
		$forum_id = bbp_get_topic_forum_id( $topic_id );

		if( empty( $forum_id ) )
			return false;

		if( 3 == bpbbpst_get_forum_support_setting( $forum_id ) )
			return false;
		
		$support_status = get_post_meta( $topic_id, '_bpbbpst_support_topic', true );

		if( empty( $support_status ) )
			$support_status = 0;
		?>
		<p>
			<strong class="label"><?php _e( 'Support:', 'buddy-bbpress-support-topic' ); ?></strong>
			<label class="screen-reader-text" for="parent_id"><?php _e( 'Support', 'buddy-bbpress-support-topic' ); ?></label>
			<?php echo bpbbpst_get_selectbox( $support_status, $topic_id);?>
		</p>
		<?php
	}

	/**
	 * Saves support status for the topic (admin)
	 *
	 * @since  2.0
	 * 
	 * @param  integer $topic_id the topic id
	 * @param  integer $forum_id the parent forum id
	 * @uses   wp_verify_nonce() for security reason
	 * @uses   delete_post_meta() to eventually delete the support status
	 * @uses   update_post_meta() to save the support status
	 * @uses   do_action() to let plugins or themes add action from this point
	 * @return integer           the topic id
	 */
	public function topic_meta_box_save( $topic_id = 0, $forum_id = 0 ) {

		if( !isset( $_POST['_support_status'] ) || $_POST['_support_status'] === false )
			return $topic_id; 

		$new_status = intval( $_POST['_support_status'] );
		
		if( $new_status !== false && !empty( $_POST['_wpnonce_bpbbpst_support_status'] ) && wp_verify_nonce( $_POST['_wpnonce_bpbbpst_support_status'], 'bpbbpst_support_status') ) {
			
			if( empty( $new_status ) ) {
				delete_post_meta( $topic_id, '_bpbbpst_support_topic' );
			} else {
				update_post_meta( $topic_id, '_bpbbpst_support_topic', $new_status );
			}
			
			do_action( 'bpbbpst_topic_meta_box_save', $new_status );
			
		}
		
		return $topic_id;
	}

	/**
	 * Handles the support status in case a topic moved to another forum (admin)
	 *
	 * In case a topic moves to another forum, we need to check the new parent forum
	 * support setting to eventually delete the support status or create it.
	 *
	 * @since 2.0
	 * 
	 * @param integer $topic_id the topic id
	 * @param object $topic     the topic object
	 * @uses  get_current_screen() to make sure we're editing a topic from admin
	 * @uses  bbp_get_topic_post_type() to get topic post type
	 * @uses  bbp_is_post_request() to make sure we're playing with a post request
	 * @uses  wp_verify_nonce() for security reasons
	 * @uses  current_user_can() to check for current user's capability
	 * @uses  bpbbpst_handle_moving_topic() to handle topic move
	 */
	public function topic_moved( $topic_id = 0, $topic = false ) {
		if ( !isset( get_current_screen()->post_type ) || ( bbp_get_topic_post_type() != get_current_screen()->post_type ) )
			return $topic_id;

		// Bail if doing an autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return $topic_id;

		// Bail if not a post request
		if ( ! bbp_is_post_request() )
			return $topic_id;

		// Nonce check
		if ( empty( $_POST['bbp_topic_metabox'] ) || !wp_verify_nonce( $_POST['bbp_topic_metabox'], 'bbp_topic_metabox_save' ) )
			return $topic_id;

		// Bail if current user cannot edit this topic
		if ( !current_user_can( 'edit_topic', $topic_id ) )
			return $topic_id;

		// Get the forum ID
		$forum_id = !empty( $_POST['parent_id'] ) ? (int) $_POST['parent_id'] : 0;

		if( empty( $forum_id ) )
			return $topic_id;

		if( $the_topic = wp_is_post_revision( $topic_id ) )
			$topic_id = $the_topic;

		bpbbpst_handle_moving_topic( $topic_id, $forum_id );
	}

	/**
	 * Registers a new column to topics admin list to show support status
	 *
	 * @since  2.0
	 * 
	 * @param  array  $columns the registered columns
	 * @return array           the columns with the support one
	 */
	public function topics_admin_column( $columns = array() ) {
		$columns['buddy_bbp_st_support'] = __( 'Support', 'buddy-bbpress-support-topic' );
	
		return $columns;
	}

	/**
	 * Displays the support status of each topic row
	 *
	 * @since 2.0
	 * 
	 * @param string  $column   the column id
	 * @param integer $topic_id the topic id
	 * @uses  bpbbpst_add_support_mention() to output the topic support status
	 */
	public function topics_column_data( $column = '', $topic_id = 0 ) {
		if( $column == 'buddy_bbp_st_support' && !empty( $topic_id ) ) {
			bpbbpst_add_support_mention( $topic_id );
		}
	}

	/**
	 * Adds a selectbox to allow filtering topics by status (admin)
	 *
	 * @since 2.0
	 * 
	 * @uses  get_current_screen() to be sure we're on topic admin list
	 * @uses  bbp_get_topic_post_type() to get topic post type
	 * @uses  bpbbpst_get_selectbox() to output the support status selectbox
	 */
	public function topics_admin_support_filter() {
		if( get_current_screen()->post_type == bbp_get_topic_post_type() ){
			
			$selected = empty( $_GET['_support_status'] ) ? -1 : intval( $_GET['_support_status'] );
			//displays the selectbox to filter by support status
			echo bpbbpst_get_selectbox( $selected , 'adminlist' );
		}
	}

	/**
	 * Filters bbPress query to include a support status meta query
	 *
	 * @since  2.0
	 * 
	 * @param  array  $query_vars the bbPress query vars
	 * @uses   is_admin() to check we're in WordPress backend
	 * @return array the query vars with a support meta query
	 */
	public function topics_admin_support_request( $query_vars = array() ) {
		if( !is_admin() )
			return $query_vars;
		
		if( empty( $_GET['_support_status']  ) )
			return $query_vars;
		
		$support_status = intval( $_GET['_support_status'] );

		if( !empty( $query_vars['meta_key'] ) ) {
			
			if( $support_status == -1 )
				return $query_vars;

			unset( $query_vars['meta_value'], $query_vars['meta_key'] );

			$query_vars['meta_query'] = array( array(
														'key' => '_bpbbpst_support_topic',
														'value' => $support_status,
														'compare' => '='
													),
												array(
														'key' => '_bbp_forum_id',
														'value' =>  intval( $_GET['bbp_forum_id'] ),
														'compare' => '='
													) 
											);

		} else {
			
			if( $support_status == -1 )
				return $query_vars;
			
			$query_vars['meta_key']   = '_bpbbpst_support_topic';
			$query_vars['meta_value'] = $support_status;
			
		}

		return $query_vars;
	}

	/**
	 * Adds an inline edit part to topics row to allow support bulk edit
	 *
	 * @since  2.0
	 * 
	 * @param  string $column_name the colum name id
	 * @param  string $post_type   the post type id
	 * @uses   bpbbpst_get_support_status() to get available support statuses
	 * @return string              html output
	 */
	public function bulk_topics_support( $column_name = '', $post_type = '' ) {
		if( $column_name != 'buddy_bbp_st_support' )
			return;

		$all_status = bpbbpst_get_support_status();

		if( empty( $all_status ) || !is_array( $all_status ) )
			return;
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<div class="inline-edit-group">
					<label class="alignleft">
						<span class="title"><?php _e( 'Support' ); ?></span>
						<select name="_support_status">
							<?php foreach( $all_status as $status ):?>
								<option value="<?php echo $status['value'];?>"><?php echo $status['sb-caption']; ?></option>
							<?php endforeach;?>
						</select>
					</label>
				</div>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Bulk update support statuses for selected topics
	 *
	 * @since  2.0
	 *
	 * @uses   wp_parse_id_list() to sanitize list of topic ids
	 * @uses   bbp_get_topic_forum_id() to get the forum parent id
	 * @uses   bpbbpst_get_forum_support_setting() to get forum parent support setting
	 * @uses   update_post_meta() to update the support statuses for selected topic ids
	 * @return boolean true
	 */
	public function bulk_update_support() {

		if( !isset( $_GET['bulk_edit'] ) )
			return;

		if( !isset( $_GET['post_type'] ) || $_GET['post_type'] != bbp_get_topic_post_type() )
			return;

		if( !isset( $_GET['_support_status'] ) )
			return;
		
		if( !isset( $_GET['post'] ) )
			return;

		$topic_ids = wp_parse_id_list( $_GET['post'] );

		$support_status = intval( $_GET['_support_status'] );

		foreach( $topic_ids as $topic_id ) {
			// we need to check the topic belongs to a support featured forum
			$forum_id = bbp_get_topic_forum_id( $topic_id );

			if( empty( $forum_id ) || ( 3 == bpbbpst_get_forum_support_setting( $forum_id ) && 0 != $support_status ) )
				continue;

			if( 2 == bpbbpst_get_forum_support_setting( $forum_id ) && 0 == $support_status )
				continue;
			
			update_post_meta( $topic_id, '_bpbbpst_support_topic', $support_status );
		}

		return true;
	}

	/**
	 * Extends bbPress right now Dashboard widget to display support statistics
	 *
	 * @since  2.0
	 *
	 * @uses   bpbbpst_support_statistics() to build the support statistics
	 * @uses   current_user_can() to check for current user's capability
	 * @uses   add_query_arg() to build links to topic admin list filtered by support status
	 * @uses   bbp_get_topic_post_type() to get the topic post type
	 * @uses   get_admin_url() to get the admin url
	 * @return string html output
	 */
	public function dashboard_widget() {
		$support_statistics = bpbbpst_support_statistics();

		if( empty( $support_statistics['total_support'] ) )
			return false;

		$status_stats = $support_statistics['allstatus'];

		if( !is_array( $status_stats ) || count( $status_stats ) < 1 )
			return false;

		?>
		<div class="table table_content" style="margin-top:40px">
			<p class="sub"><?php _e( 'Support topics', 'bbpress' ); ?></p>
			<table>
				<tr class="first">

					<td class="first b b-topics"><span class="total-count"><?php echo $support_statistics['percent']; ?></span></td>
					<td class="t topics"><?php _e( 'Resolved so far', 'buddy-bbpress-support-topic' ); ?></td>

				</tr>

				<?php foreach( $status_stats as $key => $stat ) :?>

					<tr class="first">

					<?php
					$num  = $stat['stat'];
					$text = $stat['label'];
					$class = $stat['admin_class'];
					
					if ( current_user_can( 'publish_topics' ) ) {
						$link = add_query_arg( array( 'post_type' => bbp_get_topic_post_type(), '_support_status' => $key ), get_admin_url( null, 'edit.php' ) );
						$num  = '<a href="' . $link . '" class="' . $class . '">' . $num  . '</a>';
						$text = '<a href="' . $link . '" class="' . $class . '">' . $text . '</a>';
					}
					?>

					<td class="first b b-topic_tags"><?php echo $num; ?></td>
					<td class="t topic_tags"><?php  echo $text; ?></td>

				</tr>

				<?php endforeach;?>

			</table>

		</div>
		<?php
	}

	/**
	 * Check for welcome screen transient to eventually redirect admin to welcome screen
	 *
	 * @since 2.0
	 *
	 * @uses  get_transient() to get the transient created on plugin's activation
	 * @uses  delete_transient() to remove it
	 * @uses  is_network_admin() to check for network admin area
	 * @uses  wp_safe_redirect() to redirect admin to welcome screen
	 * @uses  add_query_arg() to build the link to the welcome screen
	 * @uses  admin_url() to get admin url
	 */
	public function maybe_activate() {
		
		if ( ! get_transient( '_bpbbst_welcome_screen' ) )
			return;

		// Delete the redirect transient
		delete_transient( '_bpbbst_welcome_screen' );

		// Bail if activating from network, or bulk
		if ( is_network_admin() || isset( $_GET['activate-multi'] ) )
			return;

	   wp_safe_redirect( add_query_arg( array( 'page' => 'bpbbst-about' ), admin_url( 'index.php' ) ) );
	}

	/**
	 * Adds a submenu to dashboard page to register the welcome screen
	 *
	 * @since  2.0
	 *
	 * @global string $bpbbpst_about_page the about page identifier
	 * @uses   add_dashboard_page() to build the dashboard submenu
	 * @uses   get_option() to get db version
	 * @uses   bpbbpst_get_plugin_version() to get plugin's version
	 * @uses   update_option() to eventually update db version
	 * @uses   do_action() to allow plugins or themes add action from this point
	 */
	public function welcome_screen_register() {
		global $bpbbpst_about_page;
	
		$bpbbpst_about_page = add_dashboard_page(
			__( 'Welcome to Buddy-bbPress Support Topic',  'buddy-bbpress-support-topic' ),
			__( 'Welcome to Buddy-bbPress Support Topic',  'buddy-bbpress-support-topic' ),
			'manage_options',
			'bpbbst-about',
			array( &$this, 'welcome_screen_display' )
		);

		$db_version = get_option( 'bp-bbp-st-version' );
		$plugin_version = bpbbpst_get_plugin_version();

		if( empty( $db_version ) || $plugin_version != $db_version ) {
			update_option( 'bp-bbp-st-version', $plugin_version );

			do_action( 'bpbbpst_upgrade', $plugin_version, $db_version );
		}
		
	}

	/**
	 * Displays the welcome screen of the plugin
	 *
	 * @since  2.0
	 *
	 * @uses   bpbbpst_get_plugin_version() to get plugin's version
	 * @uses   bpbbpst_get_plugin_url() to get plugin's url
	 * @uses   esc_url() to sanitize urls
	 * @uses   admin_url() to build the admin url of welcome screen
	 * @uses   add_query_arg() to add arguments to the admin url
	 * @return string html
	 */
	public function welcome_screen_display() {
		$display_version = bpbbpst_get_plugin_version();
		$plugin_url = bpbbpst_get_plugin_url();
		?>
		<div class="wrap about-wrap">
				<h1><?php printf( __( 'Buddy-bbPress Support Topic %s', 'buddy-bbpress-support-topic' ), $display_version ); ?></h1>
				<div class="about-text"><?php printf( __( 'Thank you for updating to the latest version! Buddy-bbPress Support Topic %s is ready to manage your support requests!', 'buddy-bbpress-support-topic' ), $display_version ); ?></div>
				<div class="bpbbpst-badge"><?php printf( __( 'Version %s', 'buddy-bbpress-support-topic' ), $display_version ); ?></div>

				<h2 class="nav-tab-wrapper">
					<a class="nav-tab nav-tab-active" href="<?php echo esc_url( admin_url( add_query_arg( array( 'page' => 'bpbbst-about' ), 'index.php' ) ) ); ?>">
						<?php _e( 'What&#8217;s New', 'buddy-bbpress-support-topic' ); ?>
					</a>
				</h2>

				<div class="changelog">
					<h3><?php _e( 'New features for the bbPress (2.3.2 and up) powered forums !', 'buddy-bbpress-support-topic' ); ?></h3>

					<div class="feature-section">
						<p><?php printf( __( 'Discover below the new features introduced in version %s.', 'buddy-bbpress-support-topic' ), $display_version ); ?></p>
					</div>
				</div>



				<div class="changelog">
					<h3><?php _e( 'More control on Support feature', 'buddy-bbpress-support-topic' ); ?></h3>

					<div class="feature-section images-stagger-right">
						<img alt="" src="<?php echo $plugin_url;?>screenshot-1.png" class="image-30" />
						<h4><?php _e( 'Control the support feature by Forum', 'buddy-bbpress-support-topic' ); ?></h4>
						<p><?php _e( 'Before, the support feature was set by default and it was not possible to control it by forum.', 'buddy-bbpress-support-topic' ); ?></p>
						<p><?php _e( 'Now, from the forum administration, you can manage the support feature by forum, by choosing to leave default behavior, disallow the support feature or dedicate a forum to support.', 'buddy-bbpress-support-topic' ); ?></p>
						<p><?php _e( 'This new feature made possible another one, so jump to next chapter !!', 'buddy-bbpress-support-topic' ); ?></p>
					</div>
				</div>

				<div class="changelog">
					<h3><?php _e( 'Email notices to moderators', 'buddy-bbpress-support-topic' ); ?></h3>

					<div class="feature-section images-stagger-right">
						<img alt="" src="<?php echo $plugin_url;?>screenshot-2.png" class="image-30" />
						<h4><?php _e( 'Moderators can be notified of new support topics', 'buddy-bbpress-support-topic' ); ?></h4>
						<p><?php _e( 'If you choosed to enable the support feature for your forum, the Keymaster can activate email notices for moderators when a new support topic is posted.', 'buddy-bbpress-support-topic' ); ?></p>
						<p><?php _e( 'In Forum administration, you will find a checkbox list of bbPress moderators.', 'buddy-bbpress-support-topic' ); ?></p>
						<p><?php _e( 'Simply select the ones to receive notification and you will be able ro reply faster to your support requests !', 'buddy-bbpress-support-topic' ); ?></p>
					</div>
				</div>

				<div class="changelog">
					<h3><?php _e( 'BuddyPress : a new Group admin tab', 'buddy-bbpress-support-topic' ); ?></h3>

					<div class="feature-section images-stagger-right">
						<img alt="" src="<?php echo $plugin_url;?>screenshot-3.png" class="image-30" />
						<h4><?php _e( 'Group Admins can customize their support settings', 'buddy-bbpress-support-topic' ); ?></h4>
						<p><?php _e( 'If BuddyPress 1.8+ is activated, a new admin tab will show to allow group Admins to set the support behavior for their forum.', 'buddy-bbpress-support-topic' ); ?></p>
						<p><?php _e( 'They can choose to disallow the support feature or use it just as explained in first feature description', 'buddy-bbpress-support-topic' ); ?></p>
						<p><?php _e( 'If they allowed support for their forum, they can subscribe to an email notification when a new support topic is posted, they can also add group mods to the subscribe list to help them', 'buddy-bbpress-support-topic' ); ?></p>
					</div>
				</div>

				<div class="changelog">
					<h3><?php _e( 'Bulk edit support topics !', 'buddy-bbpress-support-topic' ); ?></h3>

					<div class="feature-section images-stagger-right">
						<img alt="" src="<?php echo $plugin_url;?>screenshot-4.png" class="image-50" />
						<h4><?php _e( 'Keymaster can change the support status of several topics at once', 'buddy-bbpress-support-topic' ); ?></h4>
						<p><?php _e( 'From the Topics list in WordPress Administration, Keymaster can use the bulk actions to edit the support status of several topics.', 'buddy-bbpress-support-topic' ); ?></p>
						<p><?php _e( 'After activating the topics checkboxes, choose Edit option in the bulk action, then click on the apply button.', 'buddy-bbpress-support-topic' ); ?></p>
						<p><?php _e( 'You can use the support selectbox to update these topics support status', 'buddy-bbpress-support-topic' ); ?></p>
					</div>
				</div>

				<div class="changelog">
					<h3><?php _e( 'A new widget', 'buddy-bbpress-support-topic' ); ?></h3>

					<div class="feature-section images-stagger-right">
						<img alt="" src="<?php echo $plugin_url;?>screenshot-6.png" class="image-50" />
						<h4><?php _e( 'A widget to help users ask for support.', 'buddy-bbpress-support-topic' ); ?></h4>
						<p><?php _e( 'Administrator can use this new widget to create a button that will activate the new topic form in the support only forum', 'buddy-bbpress-support-topic' ); ?></p>
						<p><?php _e( 'If your WordPress is an application, it can be interesting to get the referer url the user went from before hitting the new support topic widget link', 'buddy-bbpress-support-topic' ); ?></p>
						<p><?php _e( 'Once posted, Keymasters and moderators will be able to see the referer above the content of the topic.', 'buddy-bbpress-support-topic' ); ?></p>
					</div>
				</div>

				<div class="changelog">
					<h3><?php _e( 'Advanced users : need new support status ?', 'buddy-bbpress-support-topic' ); ?></h3>

					<div class="feature-section images-stagger-right">
						<img alt="" src="<?php echo $plugin_url;?>screenshot-5.png" class="image-30" />
						<h4><?php _e( 'A filter to add custom support statuses', 'buddy-bbpress-support-topic' ); ?></h4>
						<p><?php _e( 'The new status will be fully integrated in the different features of the plugin (Dashboard stats, Stats Widget...)', 'buddy-bbpress-support-topic' ); ?></p>
						<p><?php _e( 'An example of use is displayed below, use it in your plugin or in the functions.php file of your theme.', 'buddy-bbpress-support-topic' ); ?></p>
						<div class="bpbbpst-code">
							function functionprefix_custom_status( $allstatus = array() ) {<br/>
							&nbsp;&nbsp;$allstatus&#91;&#39;topic-working-on-it&#39;&#93; = array(<br/> 
							&nbsp;&nbsp;&nbsp;&nbsp;&#39;sb-caption&#39;   =&gt; __( &#39;Working on it&#39;, &#39;buddy-bbpress-support-topic&#39; ),<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;&#39;value&#39;        =&gt; 3,<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;&#39;prefix-title&#39; =&gt; __( &#39;&#91;Working on it&#93; &#39;, &#39;buddy-bbpress-support-topic&#39; ),<br/>
							&nbsp;&nbsp;&nbsp;&nbsp;&#39;admin_class&#39;  =&gt; &#39;waiting&#39;<br/>
							&nbsp;&nbsp;);<br/>
							<br/>
							&nbsp;&nbsp;return $allstatus;<br/>
							}<br/>
							<br/>
							add_filter( &#39;bpbbpst_get_support_status&#39;, &#39;functionprefix_custom_status&#39;, 10, 1 );
						</div>
					</div>
				</div>

				<div class="changelog">
					<h3><?php _e( 'Many thanks !', 'buddy-bbpress-support-topic' ); ?></h3>

					<div class="feature-section">
						<h4 class="wp-people-group"><?php _e( 'I thank these &quot;buddies&quot; for their help and support', 'buddy-bbpress-support-topic' ); ?></h4>
						<ul class="wp-people-group">
							<li class="wp-person" id="wp-person-chouf1" style="list-style:none">
								<a href="http://profiles.wordpress.org/chouf1"><img src="http://0.gravatar.com/avatar/124800ff8edcebba80fd043e088e30b6?s=60" class="gravatar" alt="Chouf1" /></a>
								<a class="web" href="http://profiles.wordpress.org/chouf1">Chouf1</a>
								<span class="title"></span>
							</li>
							<li class="wp-person" id="wp-person-djpaul" style="list-style:none">
								<a href="http://profiles.wordpress.org/djpaul"><img src="http://0.gravatar.com/avatar/3bc9ab796299d67ce83dceb9554f75df?s=60" class="gravatar" alt="Paul Gibbs" /></a>
								<a class="web" href="http://profiles.wordpress.org/djpaul">Paul Gibbs</a>
								<span class="title"></span>
							</li>
							<li class="wp-person" id="wp-person-mercime" style="list-style:none">
								<a href="http://profiles.wordpress.org/mercime"><img src="http://0.gravatar.com/avatar/fae451be6708241627983570a1a1817a?s=60" class="gravatar" alt="Mercime" /></a>
								<a class="web" href="http://profiles.wordpress.org/mercime">Mercime</a>
								<span class="title"></span>
							</li>
						</ul>
					</div>
				</div>

			</div>
		<?php
	}

	/**
	 * Outputs some css rules if on welcome screen
	 *
	 * @since  2.0
	 *
	 * @global string the welcome screen page identifier
	 * @uses   remove_submenu_page() to remove the page from dashoboard menu
	 * @uses   bpbbpst_get_plugin_url() to get the plugin url
	 * @uses   get_current_screen() to check current page is the welcome screen
	 * @return string css rules
	 */
	public function welcome_screen_css() {
		global $bpbbpst_about_page;
	
		remove_submenu_page( 'index.php', 'bpbbst-about');
		
		$badge_url = bpbbpst_get_plugin_url( 'images' ) .'bpbbst-badge.png';
		
		if( get_current_screen()->id == $bpbbpst_about_page ) {
			?>
			<style type="text/css" media="screen">
				/*<![CDATA[*/
				
				.bpbbpst-code {
					font-family:Monaco,"Lucida Console";
					font-size:80%;
					background:#f1f1f1;
					width:60%;
				}

				.bpbbpst-badge {
					padding-top: 142px;
					height: 50px;
					width: 173px;
					color: #555;
					font-weight: bold;
					font-size: 14px;
					text-align: center;
					margin: 0 -5px;
					background: url('<?php echo $badge_url; ?>') no-repeat;
				}

				.about-wrap .bpbbpst-badge {
					position: absolute;
					top: 0;
					right: 0;
				}
					body.rtl .about-wrap .bpbbpst-badge {
						right: auto;
						left: 0;
					}
					
				.wp-person{
					list-style:none;
				}
			</style>
			<?php
		}
	}

	/**
	 * Adds a custom link to the welcome screen in plugin's list for our row
	 *
	 * @since  2.0
	 * @param  array  $links the plugin links
	 * @param  string $file  the plugin in row
	 * @uses   plugin_basename() to get plugin's basename
	 * @uses   bbpress() to get bbPress main instance
	 * @uses   add_query_arg() to build the url to our welcome screen
	 * @uses   admin_url() to get admin url to dashoboard
	 * @uses   esc_html__() to sanitize translated string
	 * @return array         the plugin links
	 */
	public function modify_plugin_action_links( $links = array(), $file = '' ) {

		// Return normal links if not BuddyPress
		if ( plugin_basename( bbpress()->extend->bpbbpst->globals->file ) != $file )
			return $links;

		// Add a few links to the existing links array
		return array_merge( $links, array(
			'about'    => '<a href="' . add_query_arg( array( 'page' => 'bpbbst-about' ), admin_url( 'index.php' ) ) . '">' . esc_html__( 'About', 'buddy-bbpress-support-topic' ) . '</a>'
		) );

	}

}

/**
 * Setup Buddy-bbPress Support Topic Admin
 *
 * @since 2.0
 *
 * @uses  bbpress() to get main bbPress instance
 * @uses  BP_bbP_ST_Admin() to start the admin part of the plugin
 */
function bpbbpst_admin() {
	bbpress()->extend->bpbbpst->admin = new BP_bbP_ST_Admin();
}

endif; // class_exists check
