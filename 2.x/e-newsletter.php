<?php
/*
Plugin Name: E-Newsletter
Plugin URI: http://premium.wpmudev.org/project/e-newsletter
Description: E-Newsletter
Version: 2.0
Author: Cole / Andrey (Incsub), Maniu (Incsub)
Author URI: http://premium.wpmudev.org
WDP ID: 233

Copyright 2009-2011 Incsub (http://incsub.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License (Version 2 - GPLv2) as published by
the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/
if( !defined('EMAIL_NEWSLETTER_VERSION') )
	define('EMAIL_NEWSLETTER_VERSION', '2.0');

include_once( 'email-newsletter-files/class.functions.php' );
require_once( 'email-newsletter-files/builder/class.builder.php' );

/**
* Plugin main class
**/

class Email_Newsletter extends Email_Newsletter_functions {

    var $plugin_dir;
    var $plugin_url;
    var $settings;
    var $tb_prefix;
    var $cron_send_name;
	var $plugin_templates = array();
	var $capabilities = array();

    function Email_Newsletter() {
        __construct();
    }

    /**
     * PHP 5 constructor
     **/
    function __construct() {
        global $wpdb;

        //checking for MultiSite
        if ( 1 < $wpdb->blogid )
            $this->tb_prefix = $wpdb->base_prefix . $wpdb->blogid . '_';
        else
            $this->tb_prefix = $wpdb->base_prefix;

        //set cron name for send emails
        $this->cron_send_name = "e_newsletter_cron_send_" . $wpdb->blogid;

        //setup proper directories
		$this->plugin_dir = plugin_dir_path( __FILE__ );
		$this->plugin_url = plugins_url( '/', __FILE__ );
		if(!isset($this->plugin_dir) || !isset($this->plugin_url))
            wp_die( __('There was an issue determining plugin path or url', 'email-newsletter' ) );
		
		include_once($this->plugin_dir . '/email-newsletter-files/class.wpmudev_dash_notification.php');
		
		// Setup all plugin capabilities
		$this->capabilities['create_newsletter'] = __('Create Newsletters','email-newsletter');
		$this->capabilities['save_newsletter'] = __('Edit Newsletters','email-newsletter');
		$this->capabilities['send_newsletter'] = __('Send Newsletters','email-newsletter');
		$this->capabilities['delete_newsletter'] = __('Delete Newsletters','email-newsletter');
		$this->capabilities['create_newsletter_group'] = __('Create Newsletter Groups','email-newsletter');
		$this->capabilities['edit_newsletter_group'] = __('Edit Newsletter Groups','email-newsletter');
		$this->capabilities['delete_newsletter_group'] = __('Delete Newsletter Groups','email-newsletter');
		$this->capabilities['change_newsletter_group'] = __('Change Newsletter Groups','email-newsletter');
		$this->capabilities['view_newsletter_members'] = __('View Newsletter Members','email-newsletter');
		$this->capabilities['add_newsletter_member'] = __('Add Newsletter Members','email-newsletter');
		$this->capabilities['delete_newsletter_member'] = __('Delete Newsletter Members','email-newsletter');
		$this->capabilities['add_members_group'] = __('Add Members To Group','email-newsletter');
		$this->capabilities['delete_members_group'] = __('Delete Members From Group','email-newsletter');
		$this->capabilities['save_newsletter_settings'] = __('Save Blog Settings','email-newsletter');
		$this->capabilities['view_newsletter_dashboard'] = __('View Dashboard Page','email-newsletter');
		$this->capabilities['import_newsletter_members'] = __('Import Members','email-newsletter');
		$this->capabilities['install_newsletter'] = __('First Time Install','email-newsletter');
		$this->capabilities['uninstall_newsletter'] = __('Un-Install Blog Data','email-newsletter');
		
		$admin_role = get_role('administrator');
		foreach($this->capabilities as $key => $cap) {
			if(!isset($admin_role->capabilities[$key]) || $admin_role->capabilities[$key] == false ) {
				$admin_role->add_cap($key,true);
			}
		}
		
        //add new rewrite rules
        register_activation_hook( $this->plugin_dir . 'e-newsletter.php', array( &$this, 'do_activation' ) );
        add_filter( 'rewrite_rules_array', array( &$this, 'insert_rewrite_rules' ) );
        add_filter( 'query_vars', array( &$this, 'insert_query_vars' ) );

        //get all setting of plugin
        $this->settings = $this->get_settings();

        //Set value for CRON (transition from old version)
        if ( ! isset( $this->settings['cron_enable'] ) && isset( $this->settings['cron_time'] ) ) {
            if ( 1 < $this->settings['cron_time'] ) {
                $result = $wpdb->query( "INSERT INTO {$this->tb_prefix}enewsletter_settings SET `key` = 'cron_enable', `value` = '1'" );
                if ( 7 >  $this->settings['cron_time'] )
                    $result = $wpdb->query( "UPDATE {$this->tb_prefix}enewsletter_settings SET `key` = 'cron_time', `value` = '1' WHERE `key` = 'cron_time'" );
                else
                    $result = $wpdb->query( "UPDATE {$this->tb_prefix}enewsletter_settings SET `key` = 'cron_time', `value` = '2' WHERE `key` = 'cron_time'" );
            } else {
                $result = $wpdb->query( "INSERT INTO {$this->tb_prefix}enewsletter_settings SET `key` = 'cron_enable', `value` = '2'" );
                if ( wp_next_scheduled( $this->cron_send_name ) )
                    wp_clear_scheduled_hook( $this->cron_send_name );
            }
            $this->settings = $this->get_settings();
        }
		
		add_action('plugins_loaded',array(&$this,'import_wpmu_plugins'));
		add_action('plugins_loaded',array(&$this,'set_current_user'));

        add_action( 'admin_init', array( &$this, 'admin_init' ) );
		add_action( 'admin_enqueue_scripts', array(&$this,'admin_enqueue_scripts'));

        // filter schedules
        add_filter( 'cron_schedules', array( &$this, 'add_new_cron_time' ) );

        add_action( 'init', array( &$this, 'init' ) );	

        //some actions for MultiSite
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			add_action( 'wpmu_activate_user', array( &$this, 'user_create' ) );
            add_action( 'added_existing_user', array( &$this, 'user_create' ) );
            add_action( 'remove_user_from_blog', array( &$this, 'user_remove_from_site' ) );
            add_action( 'wpmu_delete_user', array( &$this, 'user_delete' ) );
            add_action( 'delete_blog', array( &$this, 'uninstall' ) );
			//Coming Soon
			add_action( 'network_admin_menu', array( &$this, 'admin_page' ) );
			add_action( 'network_admin_menu', array( &$this, 'network_admin_menu' ) );
        }
		else {
			//changing list of members when we create or delete user of the standard site
			add_action( 'user_register', array( &$this, 'user_create' ) );
			add_action( 'delete_user', array( &$this, 'user_delete' ) );			
		}

        //creating menu of the plugin
        add_action( 'admin_menu', array( &$this, 'admin_page' ) );

        //send email by WP-CRON
        add_action( $this->cron_send_name, array( &$this, 'send_by_wpcron' ) );

        //check bounces email by WP-CRON
        add_action( 'e_newsletter_cron_check_bounces_' . $wpdb->blogid .'_1', array( &$this, 'check_bounces' ) );
        add_action( 'e_newsletter_cron_check_bounces_' . $wpdb->blogid .'_2', array( &$this, 'check_bounces' ) );


        //ajax action for sent preview (test) email
        //add_action( 'wp_ajax_nopriv_send_preview', array( &$this, 'send_preview_ajax' ) );
        add_action( 'wp_ajax_send_email_preview', array( &$this, 'send_preview_ajax' ) );

        //ajax action for change member's group on members page
        add_action( 'wp_ajax_nopriv_change_groups', array( &$this, 'change_groups_ajax' ) );
        add_action( 'wp_ajax_change_groups', array( &$this, 'change_groups_ajax' ) );

        //ajax action for show transparent image 1x1 for check that email was opened
        add_action( 'wp_ajax_nopriv_check_email_opened', array( &$this, 'check_email_opened_ajax' ) );
        add_action( 'wp_ajax_check_email_opened', array( &$this, 'check_email_opened_ajax' ) );

        //ajax action for unsubscribe from email
        add_action( 'wp_ajax_nopriv_newsletter_unsubscibe', array( &$this, 'unsubscibe_ajax' ) );
        add_action( 'wp_ajax_newsletter_unsubscibe', array( &$this, 'unsubscibe_ajax' ) );

        //ajax action for subscribe
        add_action( 'wp_ajax_nopriv_confirm_subscibe', array( &$this, 'confirm_subscibe_ajax' ) );
        add_action( 'wp_ajax_confirm_subscibe', array( &$this, 'confirm_subscibe_ajax' ) );

        //ajax action for test connection to bounces email
        add_action( 'wp_ajax_nopriv_test_bounces', array( &$this, 'test_bounces_ajax' ) );
        add_action( 'wp_ajax_test_bounces', array( &$this, 'test_bounces_ajax' ) );

        //ajax action for sand email to member
        add_action( 'wp_ajax_nopriv_send_email_to_member', array( &$this, 'send_email_to_member' ) );
        add_action( 'wp_ajax_send_email_to_member', array( &$this, 'send_email_to_member' ) );

        add_action( 'template_redirect', array( &$this, 'template_redirect' ), 12 );
		
		
		//sets up current version for future! ...also upgrades:)
		$prev = get_option('email_newsletter_version', false);

		if ($prev == false) {
			update_option('email_newsletter_version', (defined(EMAIL_NEWSLETTER_VERSION) ? EMAIL_NEWSLETTER_VERSION : false));
			// First time upgrade.  1.3.1 -> 2.0
			$this->upgrade();
		}

    }
	
    /**
     * Sets current user
     *
     * @return void
     */
    function set_current_user() {
		global $current_user;
		
		get_currentuserinfo();
    }

    /**
     * Do the stuff on activation
     *
     */
    function do_activation() {
		global $email_builder;
		
		//Update rewrite_rules
        flush_rewrite_rules( false );
		
		//create folder for custom themes
		$custom_theme_dir = $this->get_custom_theme_dir();
		
		if (!is_dir($custom_theme_dir)) {
			mkdir($custom_theme_dir);
		}
    }

    /**
     * Adding a new rule
     **/
    function insert_rewrite_rules( $rules ) {
        $newrules = array();
        $newrules['e-newsletter/unsubscribe/([\w\d]{15})(\d*)/?$'] = 'index.php?unsubscribe_page=1&unsubscribe_code=$matches[1]&unsubscribe_member_id=$matches[2]';
        return $newrules + $rules;
    }
    /**
     * Adding the var for unsubscribe page
     **/
    function insert_query_vars( $vars ) {
        array_push( $vars, 'unsubscribe_page' );
        array_push( $vars, 'unsubscribe_code' );
        array_push( $vars, 'unsubscribe_member_id' );
        return $vars;
    }
	function admin_enqueue_scripts($hook) {
		 //including JS scripts for Newsletter pages
        if ( isset( $_REQUEST['page'] ) && 1 == $this->is_enewsletter_page( $_REQUEST['page'] ) ) {
            wp_enqueue_script( 'jquery' );

            //including JS scripts
            wp_enqueue_script( 'jquery-ui-tabs' );
            wp_enqueue_script( 'jquery-ui-core' );

            //including JS scripts for tooltips
            wp_register_script( 'jquery_tooltips', $this->plugin_url . 'email-newsletter-files/js/jquery.tools.min.js' );
            wp_enqueue_script( 'jquery_tooltips' );

            //including JS scripts for progressbar
            wp_register_script( 'jquery_ui_widget', $this->plugin_url . 'email-newsletter-files/js/ui.widget.js' );
            wp_enqueue_script( 'jquery_ui_widget' );

            //including JS scripts for progressbar
            wp_register_script( 'jquery_progressbar', $this->plugin_url . 'email-newsletter-files/js/jquery.ui.progressbar.js' );
            wp_enqueue_script( 'jquery_progressbar' );
        }
		
		// Including CSS file
        if ( isset( $_REQUEST['page'] ) && 1 == $this->is_enewsletter_page( $_REQUEST['page'] ) ) {
            wp_register_style( 'emailNewsletterStyle', $this->plugin_url . 'email-newsletter-files/email-newsletter.css' );
            wp_enqueue_style( 'emailNewsletterStyle' );
        }
	}

    /**
     * init for admin
     **/
    function admin_init() {
        
		$mu_cap = (function_exists('is_multisite' && is_multisite()) ? 'manage_network_options' : 'manage_options');
		
        //private actions of the plugin
        if ( isset( $_REQUEST['newsletter_action'] ) ) {
            switch( $_REQUEST[ 'newsletter_action' ] ) {

                //action for save Newsletter
                case "save_newsletter":
					if(! (current_user_can('save_newsletter') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
						
                    $this->save_newsletter( $_REQUEST['newsletter_id'], $_REQUEST['page'] );
                break;

                //action for delete Newsletter
                case "delete_newsletter":
					
					if(! (current_user_can('delete_newsletter') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $this->delete_newsletter( $_REQUEST['newsletter_id'], $_REQUEST['page'] );

                break;

                //action for create new group
                case "create_group":
					
					if(! (current_user_can('create_newsletter_group') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $edit_public = ( isset( $_REQUEST['public'] ) ) ? '1' : '0';
                    $this->create_group( $_REQUEST['group_name'], $edit_public );

                break;

                //action for edit group
                case "edit_group":
					
					if(! (current_user_can('edit_newsletter_group') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $edit_public = ( isset( $_REQUEST['edit_public'] ) ) ? '1' : '0';
                    $this->create_group( $_REQUEST['edit_group_name'], $edit_public, $_REQUEST['group_id'] );
                break;

                //action for delete group
                case "delete_group":
					
					if(! (current_user_can('delete_newsletter_group') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $this->delete_group( $_REQUEST['group_id'] );
                break;

                //action for change group
                case "change_group":
					
					if(! (current_user_can('change_newsletter_group') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $groups_id = ( isset( $_REQUEST['groups_id'] ) ) ? $_REQUEST['groups_id'] : NULL;
                    $this->change_group( $_REQUEST['member_id'], $groups_id );
                break;

                //action add new member
                case "add_member":
					
					if(! (current_user_can('add_newsletter_member') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $this->add_member( $_REQUEST['member'] );
                break;

                //Bulk action delete members
                case "delete_members":
					
					if(! (current_user_can('delete_newsletter_member') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $this->delete_members( $_REQUEST['members_id'] );
                break;

                //Bulk action add members to group
                case "add_members_group":
					
					if(! (current_user_can('add_members_group') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $this->add_members_group( $_REQUEST['members_id'], $_REQUEST['list_group_id'] );
                break;

                //Bulk action add members to group
                case "delete_members_group":
					
					if(! (current_user_can('delete_members_group') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $this->delete_members_group( $_REQUEST['members_id'], $_REQUEST['list_group_id'] );
                break;

                //action save settings
                case "save_settings":
					
					if(! (current_user_can('save_newsletter_settings') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    if( ! isset( $_REQUEST['settings']['double_opt_in'] ) ) {
                        $_REQUEST['settings']['double_opt_in'] = 0;
                    }
                    $this->save_settings( $_REQUEST['settings'] );
                break;

                //action send newsletter
                case "send_newsletter":
					
					if(! (current_user_can('send_newsletter') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    if ( isset( $_REQUEST['cron'] ) && 'add_to_cron' == $_REQUEST['cron'] )
                        $this->add_to_cron( $_REQUEST['newsletter_id'], $_REQUEST['send_id'] );
                    else if ( isset( $_REQUEST['action'] ) && 'send' == $_REQUEST["action"] )
                        $this->send_newsletter( $_REQUEST['newsletter_id'] );
                break;

                //action import members
                case "import_members":
					
					if(! (current_user_can('import_newsletter_members') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $this->import_members();
                break;

                //action install data in DB
                case "install":
					
					if(! (current_user_can('install_newsletter') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $this->install();
                    if( ! isset( $_REQUEST['settings']['double_opt_in'] ) ) {
                        $_REQUEST['settings']['double_opt_in'] = 0;
                    }
                    $this->save_settings( $_REQUEST['settings'] );
                break;

                //action uninstall data from DB
                case "uninstall":
					
					if(! (current_user_can('uninstall_newsletter') || current_user_can($mu_cap)) )
						wp_die('You do not have permission to do that');
					
                    $this->uninstall();
                    wp_redirect( add_query_arg( array( 'page' => 'newsletters-settings', 'updated' => 'true', 'dmsg' => urlencode( __( "eNewsletter data are deleted.", 'email-newsletter' ) ) ), 'admin.php' ) );
                    exit;
                break;

            }
        }
    }
	
    /**
     * init for all users
     **/
    function init() {

        load_plugin_textdomain( 'email-newsletter', false, dirname( plugin_basename( __FILE__ ) ) . '/email-newsletter-files/languages/' );

        //public actions of the plugin
        if ( isset( $_REQUEST['newsletter_action'] ) )
            switch( $_REQUEST['newsletter_action'] ) {
                //action for save selected groups of subscribe
                case "save_subscribes":
                    $redirect_to = $_SERVER['HTTP_REFERER'];
                    $this->save_subscribes( $_REQUEST['e_newsletter_groups_id'] );
                break;

                //action for subscribe
                case "subscribe":
                    $redirect_to = $_SERVER['HTTP_REFERER'];
                    $this->subscribe( "" );
                break;

                //action for Unsubscribe
                case "unsubscribe":
                    $redirect_to = $_SERVER['HTTP_REFERER'];
                    $this->unsubscribe( $_REQUEST['unsubscribe_code'] );
                break;

                //action for Subscribe of public member (not user of site)
                case "new_subscribe":
                    $redirect_to = $_SERVER['HTTP_REFERER'];
                    if( isset( $this->settings['double_opt_in'] ) && $this->settings['double_opt_in'] ) {
                        $member_data['double_opt_in'] = 1;
                        $member_data['future_groups_id'] = $_REQUEST['e_newsletter_groups_id'];
                    }
                    $member_data['email']       =  ( isset( $_REQUEST['e_newsletter_email'] ) ) ? $_REQUEST['e_newsletter_email'] : '';
                    $member_data['fname']       =  ( isset( $_REQUEST['e_newsletter_name'] ) ) ? $_REQUEST['e_newsletter_name'] : '';
                    $member_data['lname']       =  '';
                    $member_data['groups_id']   =  ( isset( $_REQUEST['e_newsletter_groups_id'] ) ) ? $_REQUEST['e_newsletter_groups_id'] : '';
                    $this->add_member( $member_data, $redirect_to );
                break;

            }
    }

    /**
     * Save Subscribes
     **/
    function save_subscribes( $groups_id, $redirect_to = ""  ) {
        global $wpdb, $current_user;

        $member_id = $this->get_members_by_wp_user_id( $current_user->data->ID );
		
		if(!empty($member_id)) {
			//deleting old list of groups for user
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tb_prefix}enewsletter_member_group WHERE member_id = %d", $member_id ) );
	
			//creating new list of groups for user
			if ( $groups_id )
				foreach( $groups_id as $group_id )
					$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$this->tb_prefix}enewsletter_member_group SET member_id = %d, group_id =  %d", $member_id, $group_id ) );
		}	
	
		if ( "" == $redirect_to ) {
			wp_redirect( add_query_arg( array( 'page' => 'newsletters-subscribes', 'updated' => 'true', 'dmsg' => urlencode( __( 'Subscriptions are saved!', 'email-newsletter' ) ) ), 'admin.php' ) );
			exit;
		} else {
			$_SESSION['newsletter_widget_status'] = __( 'Subscribes were saved!', 'email-newsletter' );
			wp_redirect( $redirect_to );
			exit;
		}
    }

    /**
     *  Subscribe on Newsletters
     **/
    function subscribe( $member_id = "", $redirect_to = "" ) {
        global $wpdb, $current_user;

        if ( "" == $member_id )
            $member_id = $this->get_members_by_wp_user_id( $current_user->data->ID );

        $unsubscribe_code = $this->gen_unsubscribe_code();
		
		if(isset($member_id) && isset($unsubscribe_code))
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->tb_prefix}enewsletter_members SET unsubscribe_code = '%s' WHERE member_id = %d", $unsubscribe_code, $member_id ) );

        if ( "false" != $redirect_to )
            if ( "" == $redirect_to ) {
                wp_redirect( add_query_arg( array( 'page' => 'newsletters-subscribes', 'updated' => 'true', 'dmsg' => urlencode( __( 'You are subscribed successfully!', 'email-newsletter' ) ) ), 'admin.php' ) );
                exit;
            } else {
                $_SESSION['newsletter_widget_status'] = __( 'You are subscribed successfully!', 'email-newsletter' );
                wp_redirect( $redirect_to );
                exit;
            }
    }

    /**
     * Unsubscribe on Newsletters
     **/
    function unsubscribe( $unsubscribe_code, $redirect_to = "" ) {
        global $wpdb;
        if ( "" != $unsubscribe_code ) {
            $member =  $wpdb->get_row( $wpdb->prepare( "SELECT member_id FROM {$this->tb_prefix}enewsletter_members WHERE unsubscribe_code = '%s'", $unsubscribe_code ), "ARRAY_A" );
            if ( 0 < $member['member_id'] ) {
                //delete all groups of member
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tb_prefix}enewsletter_member_group WHERE member_id = %d", $member['member_id'] ) );

                //delete unsubscribe_code of member
                $result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->tb_prefix}enewsletter_members SET unsubscribe_code = '' WHERE unsubscribe_code = '%s'", $unsubscribe_code ) );

                if ( "false" == $redirect_to ) {
                    return true;
                } elseif ( "" == $redirect_to ) {
                    wp_redirect( add_query_arg( array( 'page' => 'newsletters-subscribes', 'updated' => 'true', 'dmsg' => urlencode( __( 'You are unsubscribed!', 'email-newsletter' ) ) ), 'admin.php' ) );
                    exit;
                } else {
                    $_SESSION['newsletter_widget_status'] = __( 'You are unsubscribed!', 'email-newsletter' );
                    wp_redirect( $redirect_to );
                    exit;
                }
            }
            return false;
        }
    }

    /**
     * Add new member
     **/
    function add_member( $member_data, $redirect_to = "" ) {
        global $wpdb;

        $dmsg = "";

        if ( email_exists( $member_data['email'] ) !== false ) {
            //if email of new member == email of site user

            $wp_user_id = email_exists( $member_data['email'] );
            $member_id = $this->get_members_by_wp_user_id( $wp_user_id );

            //check that this site's user there is on list of members
            if ( 0 < $member_id )
                $dmsg =  __( 'This email is already used!', 'email-newsletter' );

        } else {
            //check email of new member there isn't on list of members
            $member =  $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tb_prefix}enewsletter_members WHERE member_email = '%s'", $member_data['email'] ), "ARRAY_A" );
            if ( $member )
                if ( "" != $member['unsubscribe_code'] ) {
                    $dmsg =   __( 'This email is already subscribed!', 'email-newsletter' );
                } else {
                    $this->subscribe( $member['member_id'], $redirect_to );
                    exit;
                }
        }

        if ( "" == $dmsg ) {
            if ( isset( $member_data['double_opt_in'] ) && 1 == $member_data['double_opt_in'] )
                $unsubscribe_code = "";
            else
                $unsubscribe_code = $this->gen_unsubscribe_code();

                $member_info = '';
            if ( isset( $member_data['future_groups_id'] ) && $member_data['future_groups_id'] ) {
                $member_info = array(
                    "future_groups_id" => $member_data['future_groups_id']
                );

                $member_info = serialize( $member_info );
            }

            $result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$this->tb_prefix}enewsletter_members SET
                member_fname = '%s',
                member_lname = '%s',
                member_email = '%s',
                join_date = '%s',
                member_info = '%s',
                unsubscribe_code = '%s'",
                $member_data['fname'], $member_data['lname'], $member_data['email'], time(), $member_info, $unsubscribe_code ) );

            $member_id = $wpdb->insert_id;

            if ( isset( $member_data['double_opt_in'] ) && 1 == $member_data['double_opt_in'] ) {
                $this->do_double_opt_in( $member_id );
            } else {
                //creating new list of groups for user
                if ( isset( $member_data['groups_id'] ) && is_array( $member_data['groups_id'] ) )
                    foreach( $member_data['groups_id'] as $group_id )
                        $result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$this->tb_prefix}enewsletter_member_group SET member_id = %d, group_id =  %d", $member_id, $group_id ) );
            }

            if ( "" == $redirect_to )
                $dmsg =  __( 'The new member is added!', 'email-newsletter' );
            else
                $dmsg =  __( 'You are subscribed successfully!', 'email-newsletter' );


        }

        if ( "" == $redirect_to ) {
            wp_redirect( add_query_arg( array( 'page' => 'newsletters-members', 'updated' => 'true', 'dmsg' => urlencode( $dmsg ) ), 'admin.php' ) );
            exit;
        } else {
            $_SESSION['newsletter_widget_status'] = $dmsg;
            wp_redirect( $redirect_to );
            exit;
        }
    }

    /**
     * Delete members
     **/
    function delete_members( $members_id ) {
        global $wpdb;
        if ( $members_id ) {
            foreach( ( array ) $members_id as $member_id ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tb_prefix}enewsletter_member_group WHERE member_id = %d", $member_id ) );
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tb_prefix}enewsletter_members WHERE member_id = %d", $member_id ) );
            }

            wp_redirect( add_query_arg( array( 'page' => 'newsletters-members', 'updated' => 'true', 'dmsg' => urlencode( __( 'Members are deleted!', 'email-newsletter' ) ) ), 'admin.php' ) );
            exit;
        }
    }

    /**
     * Adding new member when create new user
     **/
    function user_create( $userID ) {
        global $wpdb;
        $unsubscribe_code = $this->gen_unsubscribe_code();

        $data = get_userdata( $userID );

        if ( !empty( $data->data ) )
            $user = (array) $data->data;
        else
            $user = (array) $data;

        $result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$this->tb_prefix}enewsletter_members SET
            wp_user_id = %d,
            member_fname = %s,
            member_email = %s,
            join_date = %d,
            unsubscribe_code = '%s'
         ", $user['ID'], $user['user_nicename'], $user['user_email'], time(), $unsubscribe_code ) );
    }

    /**
     * Deleting member's groups and member when delete site user
     **/
    function user_delete( $userID ) {
        global $wpdb;

        if ( function_exists('is_multisite' ) && is_multisite() ) {
                $blogids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
        } else {
                $blogids[] = 1;
        }

        foreach ( $blogids as $blog_id ) {
            //Checking DB prefix
            if ( 1 < $blog_id )
                $tb_prefix = $wpdb->base_prefix . $blog_id . '_';
            else
                $tb_prefix = $wpdb->base_prefix;

            $member_id = $this->get_members_by_wp_user_id( $userID, $blog_id );

            if ( 0 < $member_id ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$tb_prefix}enewsletter_member_group WHERE member_id = %d", $member_id ) );
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$tb_prefix}enewsletter_members WHERE member_id = %d", $member_id ) );
            }
        }
    }

    /**
     * Deleting member's groups and member when remove user fron site
     **/
    function user_remove_from_site( $userID ) {
        global $wpdb;

        $member_id = $this->get_members_by_wp_user_id( $userID );

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tb_prefix}enewsletter_member_group WHERE member_id = %d", $member_id ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tb_prefix}enewsletter_members WHERE member_id = %d", $member_id ) );
    }

    /**
     * Delete Newsletter
     **/
    function delete_newsletter( $newsletter_id, $page_redirect ) {
        global $wpdb;
        if ( ! $page_redirect )
            $page_redirect = "newsletters-dashboard";

        if ( "newsletters-create" == $page_redirect )
            $page_redirect = "newsletters";

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tb_prefix}enewsletter_newsletters WHERE newsletter_id = %d", $newsletter_id ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->tb_prefix}enewsletter_send WHERE newsletter_id = %d", $newsletter_id ) );
		
		$this->delete_newsletter_meta($newsletter_id);

        wp_redirect( add_query_arg( array( 'page' => $page_redirect, 'updated' => 'true', 'dmsg' => urlencode( __( 'The Newsletter is deleted!', 'email-newsletter' ) ) ), 'admin.php' ) );
        exit;
    }

    /**
     * Save Newsletter
     **/
    function save_newsletter( $newsletter_id = NULL, $page_redirect, $data = NULL ) {
        global $wpdb, $email_builder;
		
		if(empty($data))
			$data = $_REQUEST;
		
		if(isset($data['newsletter_id']) && empty($newsletter_id)) {
			$newsletter_id = $data['newsletter_id'];
		}
		
		$current_theme = $email_builder->get_builder_theme($newsletter_id);

        $content        = base64_decode( str_replace( "-", "+", (isset($data['content_encoded']) ? $data['content_encoded'] : '' ) ) );
        $contact_info   = base64_decode( str_replace( "-", "+", (isset($data['contact_info']) ? $data['contact_info'] : '' ) ) );

        $fields = array(
            "template"      => $data['newsletter_template'],
            "subject"       => $data['subject'],
            "from_name"     => $data['from_name'],
            "from_email"    => $data['from_email'],
            "bounce_email"  => ( isset( $data['bounce_email'] ) ) ? $data['bounce_email'] : '',
            "content"       => $content,
            "contact_info"  => $contact_info,
        );
		
		$meta = $data['meta'];
		
		if($data['newsletter_template'] != $current_theme)
			$this->delete_newsletter_meta($newsletter_id, 'email_title', 1 );
		else		
			foreach($meta as $meta_key => $meta_value) {
				$this->update_newsletter_meta($newsletter_id, $meta_key, $meta_value);
			}

        if( ! $newsletter_id ) {
            $sql    = "INSERT INTO {$this->tb_prefix}enewsletter_newsletters SET create_date = " . time() . " ";
            $where  = '';
        }else{
            $sql    = "UPDATE {$this->tb_prefix}enewsletter_newsletters SET newsletter_id = '".mysql_real_escape_string( $newsletter_id )."' ";
            $where  = " WHERE newsletter_id = '".mysql_real_escape_string( $newsletter_id )."' LIMIT 1";
        }

        foreach( $fields as $key=>$val ) {
            $val = trim( $val );
            if( $val == '' )continue;

            $sql .= ", `".$key."` = '".mysql_real_escape_string( $val )."'";
        }
        $sql .= $where;

        $result = $wpdb->query( $sql );

        if( ! $newsletter_id )
            $newsletter_id = $wpdb->insert_id;
			
		if($page_redirect != false) {
			//Save nad redirect on Send page
			if ( "send" == $_REQUEST['send'] ) {
				wp_redirect( add_query_arg( array( 'page' => 'newsletters-dashboard', 'newsletter_action' => 'send_newsletter', 'newsletter_id' => $newsletter_id, 'updated' => 'true', 'dmsg' => urlencode( __( 'The Newsletter is saved!', 'email-newsletter' ) ) ), 'admin.php' ) );
				exit;
			}
	
			wp_redirect( add_query_arg( array( 'page' => 'newsletters-create', 'newsletter_id' => $newsletter_id, 'updated' => 'true', 'dmsg' => urlencode( __( 'The Newsletter is saved!', 'email-newsletter' ) ) ), 'admin.php' ) );
			exit;
		} else {
			return $newsletter_id;
		}
    }

    /**
     * Check that email was opened
     **/
    function check_email_opened_ajax() {
        global $wpdb;
        //write opened time to table
        $result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->tb_prefix}enewsletter_send_members SET opened_time = %d WHERE send_id = %d AND member_id = %d AND opened_time = 0" , time(), $_REQUEST['send_id'], $_REQUEST['member_id'] ) );

        //show blank image 1x1
        $filename = $this->plugin_dir . "email-newsletter-files/images/spacer.gif";
        $handle = fopen( $filename, "r" );
        $content = fread( $handle, filesize( $filename ) );
        fclose( $handle );
        die($content);
    }

    /**
     * Confirm subscibe from Email
     **/
    function confirm_subscibe_ajax() {
        global $wpdb;

        $member_id = $_REQUEST['member_id'];

        if ( $_REQUEST['hash'] != md5( "sometext123" . $member_id ) )
            die( __( 'Error: Wrong subscription data!', 'email-newsletter' ) );

        $member_data = $this->get_member( $member_id );

        $unsubscribe_code = $this->gen_unsubscribe_code();

        $result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->tb_prefix}enewsletter_members SET member_info = '', unsubscribe_code = '%s' WHERE member_id = %d", $unsubscribe_code, $member_id ) );

        //creating new list of groups for user
        if ( is_array( $member_data['future_groups_id'] ) )
            foreach( ( array ) $member_data['future_groups_id'] as $group_id )
                $result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$this->tb_prefix}enewsletter_member_group SET member_id = %d, group_id =  %d", $member_id, $group_id ) );


        die( __( 'Successful subscription!', 'email-newsletter' ) );
    }

    /**
     * Change group
     **/
    function change_groups_ajax() {
        $users_group = $this->get_memeber_groups( $_REQUEST['member_id'] );
        if ( ! is_array( $users_group ) )
            $users_group = array();

        $groups = $this->get_groups();
         if ( 0 < count( $groups ) ) {
            $content = __( 'Select groups for this user:', 'email-newsletter' ) . "<br />";

            foreach( $groups as $group ){
                if ( false === array_search ( $group['group_id'], $users_group ) )
                    $checked = '';
                else
                    $checked = 'checked="checked"';
                $content .= '<label><input type="checkbox" name="groups_id[]" value="' . $group['group_id'] . '" ' . $checked . ' />' . $group['group_name'] . '</label><br />';
            }
            $content .= "<br />";

        } else {
            $content = __( 'Please create some groups.', 'email-newsletter' ) . "<br />";
        }

        die($content);
    }

    /**
     * Unsubscibe from email
     **/
    function unsubscibe_ajax() {
        $this->unsubscribe( $_REQUEST['unsubscribe_code'] );
        die('');
    }

    /**
     * Write inforamtion of Send newsletter to DB
     **/
    function send_newsletter( $newsletter_id ) {
        global $wpdb;

        $members_id = array();
        if ( isset( $_REQUEST["all_members"] ) && "1" == $_REQUEST["all_members"] ) {
            $args = array (
                'where' => "unsubscribe_code != ''"
            );

            $members = $this->get_members( $args );
            foreach ( $members as $member ) {
                $members_id[] = $member['member_id'];
            }
        } else {
            if ( isset( $_REQUEST["group_name"] ) && $_REQUEST["group_name"] )
                foreach ( $_REQUEST["group_name"] as $group_name ) {
                    $users_id = get_users( array( 'role' => $group_name ) );
                    foreach ( $users_id as $user_id ) {
                        $member_id = $this->get_members_by_wp_user_id( $user_id->ID );
                        if ( 0 < $member_id )
                            $members_id[] = $member_id;
                    }
                }
             if ( isset( $_REQUEST["group_id"] ) && $_REQUEST["group_id"] )
                foreach ( $_REQUEST["group_id"] as $group_id ) {
                    $members_id = array_merge ( $members_id,  $this->get_members_of_group( $group_id ) );
                }

            $members_id = array_unique( $members_id );
        }

        $email_body = $this->make_email_body( $newsletter_id );

        $wpdb->query( $wpdb->prepare( "INSERT INTO {$this->tb_prefix}enewsletter_send SET newsletter_id = %d, start_time = %d, end_time = '', email_body = '%s'", $newsletter_id, time(), $email_body ) );
        $send_id = $wpdb->insert_id;

        if ( 'cron' == $_REQUEST["cron"] )
            $status = 'by_cron';
        else
            $status = 'waiting_send';

        if ( 0 < count( $members_id ) )
            foreach ( $members_id as $member_id ) {

                if ( ! ( isset( $_REQUEST['dont_send_duplicate'] ) && "1" == $_REQUEST['dont_send_duplicate'] && $this->check_duplicate_send( $newsletter_id, $member_id ) ) )
                    $wpdb->query( $wpdb->prepare( "INSERT INTO {$this->tb_prefix}enewsletter_send_members SET send_id = %d, member_id = %d, status = '%s' ", $send_id, $member_id, $status ) );
            }

        $count_send_members = $this->get_count_send_members( $send_id, $status );

        if ( 0 == $count_send_members )
            wp_redirect( add_query_arg( array( 'page' => $_REQUEST['page'], 'newsletter_action' => 'send_newsletter', 'newsletter_id' => $newsletter_id, 'updated' => 'true', 'dmsg' => urlencode( __( 'All members have already received it or no user is subscribed!', 'email-newsletter' ) ) ), 'admin.php' ) );
        else
            if ( 'cron' == $_REQUEST["cron"] )
                wp_redirect( add_query_arg( array( 'page' => $_REQUEST['page'], 'newsletter_action' => 'send_newsletter', 'newsletter_id' => $newsletter_id, 'updated' => 'true', 'dmsg' => urlencode( $count_send_members . ' ' . __( 'Members are added to CRON list', 'email-newsletter' ) ) ), 'admin.php' ) );
            else
                wp_redirect( add_query_arg( array( 'page' => $_REQUEST['page'], 'newsletter_action' => 'send_newsletter', 'newsletter_id' => $newsletter_id, 'send_id' => $send_id, 'check_key' => $_SESSION['check_key'] ), 'admin.php' ) );

        exit;
    }

    /**
     * Add email or send to CRON list
     **/
    function add_to_cron( $newsletter_id, $send_id ) {
        global $wpdb;

        $result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->tb_prefix}enewsletter_send_members SET status = 'by_cron' WHERE send_id = %d AND status = 'waiting_send'", $send_id ) );

        $count_send_members = $this->get_count_send_members( $send_id, 'by_cron' );

        wp_redirect( add_query_arg( array( 'page' => $_REQUEST['page'], 'newsletter_action' => 'send_newsletter', 'newsletter_id' => $newsletter_id, 'updated' => 'true', 'dmsg' => urlencode( $count_send_members . ' ' . __( 'Members are added to CRON list', 'email-newsletter' ) ) ), 'admin.php' ) );

        exit;
    }

    /**
     * Send email to member
     **/
    function send_email_to_member() {
        global $wpdb;

        if ( $_REQUEST['check_key'] != $_SESSION['check_key'] )
            die('Key Security Error');

        $send_id = $_REQUEST['send_id'];
        //get data of newsletter
        $send_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tb_prefix}enewsletter_send WHERE send_id = %d",  $send_id ), "ARRAY_A");

        $send_member = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tb_prefix}enewsletter_send_members WHERE send_id = %d AND status = 'waiting_send' LIMIT 0, 1",  $send_id ), "ARRAY_A");

        if ( ! $send_member ) {
            if ( ! wp_next_scheduled( 'e_newsletter_cron_check_bounces_' . $wpdb->blogid .'_1' ) )
                wp_schedule_single_event( time() + 60*2, 'e_newsletter_cron_check_bounces_' . $wpdb->blogid .'_1' );
			else {
				wp_clear_scheduled_hook( 'e_newsletter_cron_check_bounces_' . $wpdb->blogid .'_1' );
				wp_schedule_single_event( time() + 60*2, 'e_newsletter_cron_check_bounces_' . $wpdb->blogid .'_1' );
			}

            die('end');
        }

        $member_data = $this->get_member( $send_member['member_id'] );

        if ( !class_exists( 'PHPMailer' ) )
            require_once( $this->plugin_dir . 'email-newsletter-files/phpmailer/class.phpmailer.php' );

        $newsletter_data = $this->get_newsletter_data( $send_data['newsletter_id'] );

        $unsubscribe_code = $member_data['unsubscribe_code'];

		$mail =  new PHPMailer();
		
        $mail->CharSet = 'UTF-8';

        //Set Sending Method
        switch( $this->settings['outbound_type'] ) {
            case 'smtp':
                $mail->IsSMTP();
                $mail->Host = $this->settings['smtp_host'];
                
                if($this->settings['smtp_secure_method'] == 'tls' || $this->settings['smtp_secure_method'] == 'ssl')
					$mail->SMTPSecure = $this->settings['smtp_secure_method'];
				if(!empty($this->settings['smtp_port']))
					$mail->Port = $this->settings['smtp_port'];
                
                $mail->SMTPAuth = ( strlen( $this->settings['smtp_user'] ) > 0 );
                
                if( $mail->SMTPAuth ){
                    $mail->Username = esc_attr($this->settings['smtp_user']);
                    $mail->Password = $this->_decrypt( $this->settings['smtp_pass'] );
                }
                break;
            case 'mail':
                $mail->IsMail();
                break;

            case 'sendmail':
                $mail->IsSendmail();
                break;
        }

        $contents = $send_data['email_body'];
		
		
        //Replace some content inside the email body
        $wp_user = ($member_data['wp_user_id'] == 0 ? false : get_user_by('id', $member_data['wp_user_id']));
		$contents = str_replace( "{USER_NAME}", (is_a($wp_user,'WP_User') ? $wp_user->user_login : ''), $contents );
        $contents = str_replace( "{OPENED_TRACKER}", '<img src="' . admin_url('admin-ajax.php?action=check_email_opened&send_id=' . $send_id . '&member_id=' . $member_data['member_id']) . '" width="1" height="1" style="display:none;" />', $contents );
        $contents = str_replace( "%7BUNSUBSCRIBE_URL%7D", site_url('/e-newsletter/unsubscribe/' . $unsubscribe_code . $member_data['member_id'] . '/'), $contents );


		$mail->SetFrom($newsletter_data['from_email'],$newsletter_data['from_name']);
        $mail->Subject = $newsletter_data["subject"];

        $mail->MsgHTML( $contents );

        $mail->AddAddress( $member_data["member_email"] );

        $mail->XMailer = 'Newsletters-' . $send_member['member_id'] . '-' . $send_id . '-'. md5( 'Hash of bounce member_id='. $send_member['member_id'] . ', send_id='. $send_id );

        if( ! $mail->Send() ) {
        	$mail_error = $mail->ErrorInfo;
            die('Mail Send Error: '.$mail_error);
        } else {
            //write info of Sent in DB
            $result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->tb_prefix}enewsletter_send_members SET status = 'sent' WHERE send_id = %d AND member_id = %d", $send_id, $send_member['member_id'] ) );
            if ( $result )
                die('ok');
            else
                die('DB Update Error');
        }
    }

    /**
     * Send email to member
     **/
    function send_by_wpcron() {
        global $wpdb;

        @set_time_limit( 0 );

        if ( 1 > $wpdb->get_var( "SELECT Count(send_id) FROM {$this->tb_prefix}enewsletter_send_members WHERE status = 'by_cron'") )
            return ;

        //$process_id = time();
        //writing some information in the plugin log file
        //$this->write_log( $process_id . " 01 - start" );

        if ( ! get_option( 'enewsletter_cron_send_run' ) ) {

            //writing some information in the plugin log file
            //$this->write_log( $process_id . " 02 - before enewsletter_cron_send_run 1" );

            //add new column for check limit
            if ( 1 != $wpdb->query( "DESCRIBE {$this->tb_prefix}enewsletter_send_members sent_time" ) ) {
                $wpdb->query( "ALTER TABLE {$this->tb_prefix}enewsletter_send_members ADD sent_time INT" );
            }

            update_option( 'enewsletter_cron_send_run', time() );

            //writing some information in the plugin log file
            //$this->write_log( $process_id . " 03 - set enewsletter_cron_send_run 1" );


            if ( 0 < $this->settings['send_limit'] ) {

                $month  = date( 'n', time() );
                $year   = date( 'Y', time() );
                $day    = date( 'j', time() );
                $hour   = date( 'H', time() );
                $min    = date( 'i', time() );

                switch ( $this->settings['cron_time'] ) {
                case '1':
                    $limit_time_start   = mktime( $hour , 0, 0, $month, $day, $year ) ;
                    $limit_time_end     = mktime( $hour + 1, 0, -1, $month, $day, $year );
                    break;
                case '2':
                    $limit_time_start   = mktime( 0, 0, 0, $month, $day, $year );
                    $limit_time_end     = mktime( 0, 0, -1, $month, $day + 1, $year );
                    break;
                case '3':
                    $limit_time_start   =  mktime( 0, 0, 0, $month, 1, $year);
                    $limit_time_end     =  mktime( 0, 0, -1, $month + 1, 1, $year);
                    break;
                }

                //for test (every 2 min )
             $limit_time_start   = mktime( $hour , $min - 2, 0, $month, $day, $year ) ;
              $limit_time_end     = mktime( $hour, $min + 1, -1, $month, $day, $year );


                //writing some information in the plugin log file
                //$this->write_log( $process_id . " 04 - cron_time: " . $this->settings['cron_time'] . "  limit_time_start:" . $limit_time_start . "  limit_time_end:" . $limit_time_end );

                $current_count_sent = $wpdb->get_var( $wpdb->prepare( "SELECT Count(send_id) FROM {$this->tb_prefix}enewsletter_send_members WHERE sent_time  BETWEEN %d AND %d", $limit_time_start, $limit_time_end ) );
            }


            //writing some information in the plugin log file
            //$this->write_log( $process_id . " 05 - current_count_sent: " . $current_count_sent  . "  send_limit:" . $this->settings['send_limit'] );


            if ( ! isset( $current_count_sent ) || $current_count_sent < $this->settings['send_limit'] ) {

                $send_limit = 'LIMIT 0, 500';

                //writing some information in the plugin log file
                //$this->write_log( $process_id . " 06 - NOT LIMIT YET" );

                $send_members = $wpdb->get_results( "SELECT * FROM {$this->tb_prefix}enewsletter_send_members WHERE status = 'by_cron' " . $send_limit , "ARRAY_A");

                //writing some information in the plugin log file
                //$this->write_log( $process_id . " 07 - send_members:" . $send_members );

                if ( ! $send_members ) {
                    delete_option( 'enewsletter_cron_send_run' );
                    die(1);
                }

                if ( !class_exists( 'PHPMailer' ) )
                    require_once( $this->plugin_dir . 'email-newsletter-files/phpmailer/class.phpmailer.php' );

                foreach ( $send_members as $send_member ) {

                    update_option( 'enewsletter_cron_send_run', time() );

                    $member_data = $this->get_member( $send_member['member_id'] );

                    $send_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tb_prefix}enewsletter_send WHERE send_id = %d",  $send_member['send_id'] ), "ARRAY_A");

                    $newsletter_data = $this->get_newsletter_data( $send_data['newsletter_id'] );

                    $unsubscribe_code = $member_data['unsubscribe_code'];

                    $siteurl = get_option( 'siteurl' );

                    $mail = new PHPMailer();

                    $mail->CharSet = 'UTF-8';

                    //Set Sending Method
                    switch( $this->settings['outbound_type'] ) {
                        case 'smtp':
			                $mail->IsSMTP();
			                $mail->Host = $this->settings['smtp_host'];
			                
			                if($this->settings['smtp_secure_method'] == 'tls' || $this->settings['smtp_secure_method'] == 'ssl')
								$mail->SMTPSecure = $this->settings['smtp_secure_method'];
							if(!empty($this->settings['smtp_port']))
								$mail->Port = $this->settings['smtp_port'];
			                
			                $mail->SMTPAuth = ( strlen( $this->settings['smtp_user'] ) > 0 );
			                
			                if( $mail->SMTPAuth ){
			                    $mail->Username = esc_attr($this->settings['smtp_user']);
			                    $mail->Password = $this->_decrypt( $this->settings['smtp_pass'] );
			                }
			                break;

                        case 'mail':
                            $mail->IsMail();
                            break;

                        case 'sendmail':
                            $mail->IsSendmail();
                            break;
                    }

                    $contents = $send_data['email_body'];
					
					
					//Replace some content inside the email body
					$wp_user = ($member_data['wp_user_id'] == 0 ? false : get_user_by('id', $member_data['wp_user_id']));
					$contents = str_replace( "{USER_NAME}", (is_a($wp_user,'WP_User') ? $wp_user->user_login : ''), $contents );
					$contents = str_replace( "{OPENED_TRACKER}", '<img src="' . admin_url('admin-ajax.php?action=check_email_opened&send_id=' . $send_id . '&member_id=' . $member_data['member_id']) . '" width="1" height="1" style="display:none;" />', $contents );
					$contents = str_replace( "%7BUNSUBSCRIBE_URL%7D", site_url('/e-newsletter/unsubscribe/' . $unsubscribe_code . $member_data['member_id'] . '/'), $contents );


                    $mail->From         = $newsletter_data['from_email'];
                    $mail->FromName     = $newsletter_data['from_name'];
                    $mail->Subject      = $newsletter_data["subject"];

                    $mail->MsgHTML( $contents );

                    $mail->AddAddress( $member_data["member_email"] );

                    $mail->XMailer = 'Newsletters-' . $send_member['member_id'] . '-' . $send_member['send_id'] . '-'. md5( 'Hash of bounce member_id='. $send_member['member_id'] . ', send_id='. $send_member['send_id'] );

                    if( ! $mail->Send() ) {
                        //return "Mailer Error: " . $mail->ErrorInfo;
                        //return 'error';

                        //writing some information in the plugin log file
                        //$this->write_log( $process_id . " 08 - send_errors:" . $mail->ErrorInfo );

                    } else {
                        //write info of Sent in DB
                        $result = $wpdb->query( $wpdb->prepare( "UPDATE {$this->tb_prefix}enewsletter_send_members SET status = 'sent', sent_time = %d WHERE send_id = %d AND member_id = %d", time(), $send_member['send_id'], $send_member['member_id'] ) );

                        //writing some information in the plugin log file
                        //$this->write_log( $process_id . " 09 - send OK" );

                        if ( ++$current_count_sent == $this->settings['send_limit'] ) {
                            //writing some information in the plugin log file
                            //$this->write_log( $process_id . " 10 - STOP - LIMIT" );
                            delete_option( 'enewsletter_cron_send_run' );
                            die(2);
                        }
                    }
                }

                if ( ! wp_next_scheduled( 'e_newsletter_cron_check_bounces_' . $wpdb->blogid .'_2' ) )
                    wp_schedule_single_event( time() + 60*5, 'e_newsletter_cron_check_bounces_' . $wpdb->blogid .'_2' );
				else {
					wp_clear_scheduled_hook( 'e_newsletter_cron_check_bounces_' . $wpdb->blogid .'_2' );
					wp_schedule_single_event( time() + 60*5, 'e_newsletter_cron_check_bounces_' . $wpdb->blogid .'_2' );
				}

            } else {
                delete_option( 'enewsletter_cron_send_run' );
            }
        } elseif ( get_option( 'enewsletter_cron_send_run' ) < time() - 3*60 ) {
            //writing some information in the plugin log file
            //$this->write_log( $process_id . " 11 - CRON works more 3 min - restart CRON" );

            delete_option( 'enewsletter_cron_send_run' );
            die(3);
        }
        //writing some information in the plugin log file
        //$this->write_log( $process_id . " 12 - END" );

        die(4);
    }

    /**
     * Send Preview (Test) newsletter email
     **/
    function send_preview_ajax() {
		
		$newsletter_id = (isset($_REQUEST['newsletter_id']) ? $_REQUEST['newsletter_id'] : false);
		
		if(!$newsletter_id)
			die('No valid newsletter ID supplied');
		
        if ( !class_exists( 'PHPMailer' ) )
            require_once( $this->plugin_dir . 'email-newsletter-files/phpmailer/class.phpmailer.php' );
		
        $mail = new PHPMailer();

        $mail->CharSet = 'UTF-8';

        //Set Sending Method
        switch( $this->settings['outbound_type'] ) {
            case 'smtp':
                $mail->IsSMTP();
                $mail->Host = $this->settings['smtp_host'];
                
                if($this->settings['smtp_secure_method'] == 'tls' || $this->settings['smtp_secure_method'] == 'ssl')
					$mail->SMTPSecure = $this->settings['smtp_secure_method'];
				if(!empty($this->settings['smtp_port']))
					$mail->Port = $this->settings['smtp_port'];
                
                $mail->SMTPAuth = ( strlen( $this->settings['smtp_user'] ) > 0 );
                
                if( $mail->SMTPAuth ){
                    $mail->Username = esc_attr($this->settings['smtp_user']);
                    $mail->Password = $this->_decrypt( $this->settings['smtp_pass'] );
                }
                break;
            case 'mail':
                $mail->IsMail();
                break;

            case 'sendmail':
                $mail->IsSendmail();
                break;
        }
        				
        $newsletter_data = $this->get_newsletter_data( $newsletter_id );
		$content = $this->make_email_body($newsletter_id);
		$content = str_replace( "{UNSUBSCRIBE_URL}", '#', $content );
		$content = str_replace( "{OPENED_TRACKER}", '', $content );
        if($newsletter_data && $content) {
	        $mail->SetFrom($newsletter_data['from_email'],$newsletter_data['from_name']);
	        $mail->Subject = '(PREVIEW) '.$newsletter_data['subject'];
	        $mail->MsgHTML( $content );
	        $mail->AddAddress( $_REQUEST['preview_email'] );
	
	        if( $this->settings['bounce_email'] ) {
	            $mail->Sender = $this->settings['bounce_email'];
	        }
	
	        if( ! $mail->Send() )
	            die( "Mailer Error: " . $mail->ErrorInfo );
	        else
	            die( "Your test email has been sent." );
		} else {
			die( "Failed to generate email body." );
		}
    }


    /**
     * Make email body
     **/
    function make_email_body( $newsletter_id ) {
        //get data of newsletter
        $newsletter_data = $this->get_newsletter_data( $newsletter_id );
		
		if(!$newsletter_data || empty($newsletter_data))
			return false;
		
        //open template file
		$template_path  = $this->plugin_dir . "email-newsletter-files/templates/" . $newsletter_data['template'].'/';
		$template_url  = $this->plugin_url . "email-newsletter-files/templates/" . $newsletter_data['template'].'/';
        $filename   = $template_path . "template.html";
        $handle     = fopen( $filename, "r" );
        $contents   = fread( $handle, filesize( $filename ) );
        fclose( $handle );		

        $newsletter_data['content'] = '{OPENED_TRACKER}' . $newsletter_data['content'];
		
		// Extra Meta Replacements
		// Use the "email_newsletter_make_email_body" filter below to filter your own custom data
		
		// We check for meta-data then look for a defined default coming from
		// the newsletter template then we pick something anyways using the
		// get_default_builder_var() located in class.functions.php
		
		
		//Take care of all text, replace body, title, subject... stuff like this:)
		$contents = str_replace( "{EMAIL_BODY}", $newsletter_data['content'], $contents );

		$email_title = $this->get_newsletter_meta($newsletter_id,'email_title', $this->get_default_builder_var('email_title') );
		$email_title = apply_filters('email_newsletter_make_email_title',$email_title,$newsletter_id);
		$contents = str_replace( "{EMAIL_TITLE}", $email_title, $contents);
		
        $contents = str_replace( "{EMAIL_SUBJECT}", $newsletter_data['subject'], $contents );
        $contents = str_replace( "{FROM_NAME}", (isset($newsletter_data['from_name']) ? $newsletter_data['from_name'] : $this->settings['from_name']), $contents );
        $contents = str_replace( "{FROM_EMAIL}", (isset($newsletter_data['from_email']) ? $newsletter_data['from_email'] : $this->settings['from_email']), $contents );
        $contents = str_replace( "{CONTACT_INFO}", (isset($newsletter_data['contact_info']) ? $newsletter_data['contact_info'] : $this->settings['contact_info']), $contents );
		
		$date_format = (isset($this->settings['date_format']) ? $this->settings['date_format'] : "F j, Y");
		$contents = str_replace( "{DATE}", date($date_format), $contents );
		
		//wp magic
		$contents = apply_filters('the_content',$contents);
		
		//do the inline styling
		global $email_builder;
		$themedata = $email_builder->find_builder_theme();
		
		$contents = $this->do_inline_styles($themedata, $contents);
		
		// REPLACE THE image LINKS AT THE START
		$contents = str_replace( "images/", $this->plugin_url . "email-newsletter-files/templates/" . $newsletter_data['template'] . "/images/", $contents );
		
		// BG COLOR
		$bg_color = $this->get_newsletter_meta($newsletter_id,'bg_color', $this->get_default_builder_var('bg_color'));
		$bg_color = apply_filters('email_newsletter_make_email_bgcolor',$bg_color,$newsletter_id);
		$contents = str_replace( "{BG_COLOR}", $bg_color, $contents);
		
		// BG IMAGE			
		$default_bg = $this->get_default_builder_var('bg_image');
		if(!empty($default_bg))
			$default_bg = $template_url.$default_bg;
		else 
			$default_bg = '';
		$bg_image = $this->get_newsletter_meta($newsletter_id,'bg_image', $default_bg);
		$bg_image = apply_filters('email_newsletter_make_email_bg_image',$bg_image,$newsletter_id);
		$contents = str_replace( "{BG_IMAGE}", $bg_image, $contents);
		
		// LINK COLOR
		$link_color = $this->get_newsletter_meta($newsletter_id,'link_color', $this->get_default_builder_var('link_color'));
		$link_color = apply_filters('email_newsletter_make_email_link_color',$link_color,$newsletter_id);
		$contents = str_replace( "{LINK_COLOR}", $link_color, $contents);
		$contents = str_replace( "#linkcolor", $link_color, $contents);
		
		// BODY COLOR
		$body_color = $this->get_newsletter_meta($newsletter_id,'body_color', $this->get_default_builder_var('body_color'));
		$body_color = apply_filters('email_newsletter_make_email_body_color',$body_color,$newsletter_id);
		$contents = str_replace( "{BODY_COLOR}", $body_color, $contents);
		
		return apply_filters('email_newsletter_make_email_body', $contents, $newsletter_id);
    }

    /**
     * Test bounces settings
     **/
    function test_bounces_ajax(){
        @set_time_limit( 0 );

        //Send test email on bounces address
        $email_id           = time();
        $email_to           = $_REQUEST['bounce_email'];
        $email_from         = ( $this->settings['from_email'] ) ? $this->settings['from_email'] : $_REQUEST['bounce_email'];
        $email_from_name    = ( $this->settings['from_name'] ) ? $this->settings['from_name'] : $_REQUEST['bounce_email'];
        $email_subject      = "Test-Connection-Bounce-". $email_id;
        $email_contents     = 'Test';
        $options            = array ();

        if( ! $this->send_email( $email_from_name, $email_from, $email_to, $email_subject, $email_contents, $options ) ) {
            die( "Failed to send test email!" );
        }

        //Set value for connect to email server
        $email_address  = $_REQUEST['bounce_email'];
        $email_username = $_REQUEST['bounce_username'];
        $email_password = $_REQUEST['bounce_password'];
        $email_host     = trim( $_REQUEST['bounce_host'] );
        $email_port     = ( $_REQUEST['bounce_port'] ) ? $_REQUEST['bounce_port'] : 110;
		
		if($email_password == '********') {
			$settings = $this->get_settings();
			$email_password = $this->_decrypt($settings['bounce_password']);
		}
		
        if( ! $email_host )
            return true;

        sleep( 3 );
		
		$email_security = isset($_REQUEST['bounce_security']) ? $_REQUEST['bounce_security'] : '/norsh';

        $mbox = imap_open( '{'.$email_host.':'.$email_port.'/pop3/notls'.$email_security.'}INBOX', $email_username, $email_password ) or die( imap_last_error() );

        if( ! $mbox ) {
            die( __( 'Failed to connect while checking bounces.', 'email-newsletter' ) );
        } else {
            $MC = imap_check( $mbox );

            //get all emails
            $mails = imap_fetch_overview( $mbox, "1:{$MC->Nmsgs}", 0 );

            foreach ( $mails as $mail ) {
                //Search test email on server
                if( $mail->subject == 'Test-Connection-Bounce-'. $email_id ) {
					imap_delete( $mbox, $mail->uid, FT_UID );
					imap_expunge( $mbox );
					imap_close( $mbox );
					die(  __( 'Successfully connected!', 'email-newsletter' ) );
				}
            }
            imap_expunge( $mbox );
            imap_close( $mbox );
            die(  __( 'Connection failed!', 'email-newsletter' ) );
        }
		
		die();
    }


    /**
     * Send Confirm email for subscribe
     **/
     function do_double_opt_in( $member_id ){
        $message = '';
        if( isset( $this->settings['double_opt_in'] ) && $this->settings['double_opt_in'] ) {

            $siteurl = get_option( 'siteurl' );

            $member_data = $this->get_member( $member_id );

            $email_to           = $member_data['member_email'];
            $email_from         = $this->settings['from_email'];
            $email_from_name    = $this->settings['from_name'];
            $email_subject      = ( isset( $this->settings['double_opt_in_subject'] ) ) ? $this->settings['double_opt_in_subject'] : __('Confirm newsletter subscription','email-newsletter');
			
			// Determine our locale to check for a specific template
			$locale = get_locale();
			if( file_exists($this->plugin_dir . 'email-newsletter-files/emails/double_optin-'.$locale.'.html') ) {
				$email_contents     = file_get_contents( $this->plugin_dir . 'email-newsletter-files/emails/double_optin-'.$locale.'.html' );
			} else {
	            $email_contents     = file_get_contents( $this->plugin_dir . "email-newsletter-files/emails/double_optin.html" );
			}

            $replace = array(
                "from_name"=>$email_from_name,
                "CONFIRM_SUBSCRIPTION"=> $siteurl . '/wp-admin/admin-ajax.php?action=confirm_subscibe&member_id=' . $member_id .'&hash='.md5( "sometext123" . $member_id ) . '',
                "first_name"=>$member_data['member_fname'],
                "last_name"=>$member_data['member_lname'],
                "email"=>$member_data['member_email'],
            );

            foreach( $replace as $key=>$val ) {
                if( is_array( $val ) )continue;
                $email_contents = preg_replace( '/\{'.strtoupper( preg_quote( $key,'/' ) ).'\}/', $val, $email_contents );
            }
            if( !$this->send_email( $email_from_name, $email_from, $email_to, $email_subject, $email_contents ) ) {
                $message .= "Failed to send opt-in email, please contact us to inform us of this error. ";
            }else{
            }
        }

    }
	
	function network_admin_menu() {
		add_submenu_page( 'settings.php', __('Email Newsletter Network Settings','email-newsletter'), __('eNewsletter','email-newsletter'), 'manage_options', 'network-settings', null,  $this->plugin_url . 'email-newsletter-files/images/icon.png' );
	}
	
    /**
     * Creating admin menu
     **/
    function admin_page() {
			
		$mu_cap = (function_exists('is_multisite' && is_multisite()) ? 'manage_network_options' : 'manage_options');
		
        if ( $this->settings ) {
        	global $email_builder, $submenu;
                add_menu_page( __( 'eNewsletter', 'email-newsletter' ), __( 'eNewsletter', 'email-newsletter' ), 'read', 'newsletters-dashboard', null, $this->plugin_url . 'email-newsletter-files/images/icon.png');
                add_submenu_page( 'newsletters-dashboard', __( 'Reports', 'email-newsletter' ), __( 'Reports', 'email-newsletter' ), 'view_newsletter_dashboard', 'newsletters-dashboard', array( &$this, 'newsletters_dashboard_page' ) );
                add_submenu_page( 'newsletters-dashboard', __( 'Newsletters', 'email-newsletter' ), __( 'Newsletters', 'email-newsletter' ), 'save_newsletter', 'newsletters', array( &$this, 'newsletters_page' ) );
                add_submenu_page( 'newsletters-dashboard', __( 'Create Newsletter', 'email-newsletter' ), __( 'Create Newsletter', 'email-newsletter' ), 'create_newsletter', 'newsletters-create', array( &$this, 'create_newsletter_page' ) );
				add_submenu_page( 'newsletters-dashboard', __( 'Member Groups', 'email-newsletter' ), __( 'Member Groups', 'email-newsletter' ), 'edit_newsletter_group', 'newsletters-groups', array( &$this, 'member_groups_page' ) );
                add_submenu_page( 'newsletters-dashboard', __( 'Members', 'email-newsletter' ), __( 'Members', 'email-newsletter' ), 'view_newsletter_members', 'newsletters-members',  array( &$this, 'members_page' ) );
                add_submenu_page( 'newsletters-dashboard', __( 'Settings', 'email-newsletter' ), __( 'Settings', 'email-newsletter' ), 'save_newsletter_settings', 'newsletters-settings', array( &$this, 'settings_page' ) );

                //menu for lowest level users
                add_submenu_page( 'newsletters-dashboard', __( 'My Subscriptions', 'email-newsletter' ), __( 'My Subscriptions', 'email-newsletter' ), 'read', 'newsletters-subscribes', array( &$this, 'newsletters_subscribe_page' ) );

				if(isset($submenu['newsletters-dashboard'])) {
					foreach($submenu['newsletters-dashboard'] as $k => $v) {
						if(isset($v[2]) && $v[2] == 'newsletters-create') {
							$submenu['newsletters-dashboard'][$k][2] = $email_builder->generate_builder_link('new');
						}
					}
				}
				
				
        } else {
            //first start of plugin
            add_menu_page( __( 'eNewsletter', 'email-newsletter' ), __( 'eNewsletter', 'email-newsletter' ), $mu_cap, 'newsletters-settings' );
            add_submenu_page( 'newsletters-settings', __( 'Install Settings', 'email-newsletter' ), __( 'Install Settings', 'email-newsletter' ), $mu_cap, 'newsletters-settings', array( &$this, 'settings_page' ) );
        }
    }

    /**
     *  Tempalate of the Newsletters Dashboard page
     **/
    function newsletters_dashboard_page() {
        //including file for send newsletter
        if ( isset( $_REQUEST['newsletter_action'] ) && "send_newsletter" == $_REQUEST['newsletter_action'] && ( $_REQUEST['newsletter_id'] ||  $_REQUEST['send_id'] ) ) {
            require_once( $this->plugin_dir . "email-newsletter-files/page-send-newsletter.php" );
            return;
        }
        require_once( $this->plugin_dir . "email-newsletter-files/page-newsletters-dashboard.php" );
    }

    /**
     *  Tempalate of the Newsletters page
     **/
    function newsletters_page() {
        //including file for send newsletter
        if ( isset( $_REQUEST['newsletter_action'] ) && "send_newsletter" == $_REQUEST['newsletter_action'] && ( $_REQUEST['newsletter_id'] ||  $_REQUEST['send_id'] ) ) {
            require_once( $this->plugin_dir . "email-newsletter-files/page-send-newsletter.php" );
            return;
        }

        require_once( $this->plugin_dir . "email-newsletter-files/page-newsletters.php" );
    }

    /**
     *  Tempalate of the Create/Edit Newsletter page
     **/
    function create_newsletter_page() {
        require_once( $this->plugin_dir . "email-newsletter-files/page-create-newsletter.php" );
    }

    /**
     *  Tempalate of the Groups list
     **/
    function member_groups_page() {
        require_once( $this->plugin_dir . "email-newsletter-files/page-groups.php" );
    }

    /**
     *  Tempalate of the Memebers page
     **/
    function members_page() {
        require_once( $this->plugin_dir . "email-newsletter-files/page-members.php" );
    }

    /**
     *  Tempalate of the Settings page
     **/
    function settings_page() {
        require_once( $this->plugin_dir . "email-newsletter-files/page-settings.php" );
    }

    /**
     *  Tempalate of the Settings page
     **/
    function newsletters_subscribe_page() {
        require_once( $this->plugin_dir . "email-newsletter-files/page-subscribe.php" );
    }

}
global $email_newsletter, $email_builder;
$email_newsletter =& new Email_Newsletter();
$email_builder =& new Email_Newsletter_Builder();

// Widget for Subscribe
class e_newsletter_subscribe extends WP_Widget {
    //constructor
    function e_newsletter_subscribe() {
        if( isset( $_REQUEST['wp3_newsletter_subscribe'] ) ) {
        }
		if (session_id() == "" || !isset($_SESSION))
      		session_start();

        $widget_ops = array( 'description' => __( 'Allow people to subscribe to your newsletter database.') );
        parent::WP_Widget( false, __( 'eNewsletter: Subscribe' ), $widget_ops );
    }

    /** @see WP_Widget::widget */
    function widget( $args, $instance ) {
        global $email_newsletter, $current_user;

        $groups = $email_newsletter->get_groups();

        extract( $args );

        if ( $current_user->data && 0 < $current_user->data->ID ) {
            $member_id      = $email_newsletter->get_members_by_wp_user_id( $current_user->data->ID );
            $member_data    = $email_newsletter->get_member( $member_id );

            if ( "" != $member_data['unsubscribe_code'] ) {
                $member_groups = $email_newsletter->get_memeber_groups( $member_id );
                if ( ! is_array( $member_groups ) )
                    $member_groups = array();

            }

            $show_groups = true;
        } else {

            $show_name      = apply_filters( 'widget_title', $instance['name'] );
            $show_groups    = apply_filters( 'widget_title', $instance['groups'] );
        }

        $title = apply_filters( 'widget_title', $instance['title'] );

        ?>
        <?php
        echo $before_widget;
        if ( $title )
            echo $before_title . $title . $after_title;
        ?>

    <div class="e-newsletter-widget">
        <?php
        if ( isset ( $_SESSION['newsletter_widget_status'] ) ) {
        ?>
            <div id="message" style="background-color: #FFFFE0;border-color: #E6DB55;margin: 5px 0 15px;-moz-border-radius: 3px 3px 3px 3px;border-style: solid;border-width: 1px;padding: 5px;">
            <?php
            echo $_SESSION['newsletter_widget_status'];
            unset($_SESSION['newsletter_widget_status']);
            ?>

            </div>
    	<?php 
		} else { ?>
            <div id="message" style="display:none; background-color: #FFFFE0;border-color: #E6DB55;margin: 5px 0 15px;-moz-border-radius: 3px 3px 3px 3px;border-style: solid;border-width: 1px;padding: 5px;"></div>
    	<?php
		} ?>

        <form action="" method="post" name="subscribes_form" id="subscribes_form">
            <input type="hidden" name="newsletter_action" id="newsletter_action" value="" />
            <?php
            if ( ! $current_user->data || 0 == $current_user->data->ID ) {
            ?>
                <div>
                    <label for="e_newsletter_email"><?php _e( 'Your Email:', 'email-newsletter' ) ?></label>
                    <input type="text" name="e_newsletter_email" id="e_newsletter_email" />
                </div>


                <?php
                if( $show_name ) {
                ?>
                <div>
                    <label for="e_newsletter_name"><?php _e( 'Your Name:', 'email-newsletter' ) ?></label>
                    <input type="text" name="e_newsletter_name" id="e_newsletter_name" />
                </div>
                <?php
                }

                if( $show_groups && $groups ) {
                ?>
                <div>
                    <h3><?php _e( 'Subscribe to:', 'email-newsletter' ) ?></h3>
                    <ul style="list-style: none outside none;">
                        <?php
                        foreach( ( array ) $groups as $group ) {
                            if( ! $group['public'] ) continue;
                        ?>
                            <li>

                                <input type="checkbox" name="e_newsletter_groups_id[]" value="<?php echo $group['group_id'];?>" id="e_newsletter_groups_id_<?php echo $group['group_id'];?>" />
                                <label for="e_newsletter_groups_id_<?php echo $group['group_id'];?>"><?php echo $group['group_name'];?></label>

                            </li>
                        <?php
                        }
                        ?>
                    </ul>
                </div>
                <?php
                }
                ?>
                <div>
                    <input type="button" id="new_subscribe" value="<?php _e( 'Subscribe', 'email-newsletter' ) ?>" />
                </div>

            <?php
            } else if ( isset( $member_data['unsubscribe_code'] ) && "" != $member_data['unsubscribe_code'] && 0 < $current_user->data->ID ) {
            ?>
                <input type="hidden" name="unsubscribe_code" value="<?php echo $member_data['unsubscribe_code']; ?>" />
                <?php
                if( $groups ) {
                ?>
                    <div>
                        <h3><?php _e( 'Subscribe to:', 'email-newsletter' ) ?></h3>
                        <ul style="list-style: none outside none;">
                            <?php
                            foreach( (array) $groups as $group ){
                                if ( false === array_search ( $group['group_id'], ( array ) $member_groups ) )
                                    $checked = '';
                                else
                                    $checked = 'checked="checked"';
                            ?>
                                <li>

                                    <input type="checkbox" name="e_newsletter_groups_id[]" value="<?php echo $group['group_id'];?>" <?php echo $checked;?> id="e_newsletter_groups_id_<?php echo $group['group_id'];?>" />
                                    <label for="e_newsletter_groups_id_<?php echo $group['group_id'];?>"><?php echo $group['group_name'];?></label>
                                </li>
                            <?php
                            }
                            ?>
                        </ul>
                    </div>
                <?php
                }
                ?>
                <div>
                    <input type="button" id="save_subscribes" value="<?php _e( 'Save Subscriptions', 'email-newsletter' ) ?>" />
                </div>
                <div>
                <a href="javascript:;" id="unsubscribe" ><?php _e( 'Unsubscribe', 'email-newsletter' ) ?></a>
                </div>

            <?php
            } else if ( $current_user->data && 0 < $current_user->data->ID ) {
            ?>
                <input type="hidden" name="newsletter_action" value="subscribe" />
                <div>
                    <input type="submit" id="subscribe"  value="<?php _e( 'Subscribe to Newsletters', 'email-newsletter' ) ?>" />
                </div>
            <?php
            }
            ?>
        </form>
    </div><!--//e-newsletter-widget  -->

        <?php echo $after_widget; ?>

    <?php

    }

    /** @see WP_Widget::update */
    function update( $new_instance, $old_instance ) {
        $instance           = $old_instance;
        $instance['title']  = strip_tags($new_instance['title']);
        $instance['name']   = strip_tags($new_instance['name']);
        $instance['groups'] = strip_tags($new_instance['groups']);
        return $instance;
    }

    /** @see WP_Widget::form */
    function form( $instance ) {
        if ( isset( $instance['title'] ) )
            $title = esc_attr( $instance['title'] );
        else
            $title = __( 'Subscribe to our Newsletters', 'email-newsletter' );

        if ( isset( $instance['name'] ) )
            $name = esc_attr( $instance['name'] );
        else
            $name = 0;

        if ( isset( $instance['groups'] ) )
            $groups = esc_attr( $instance['groups'] );
        else
            $groups = 0;

        ?>
            <p>
                <label for="<?php echo $this->get_field_name( 'title' ); ?>"><?php _e( 'Title', 'email-newsletter' ) ?></label>
                <input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo $title; ?>" />

            </p>
            <p>
                <label for="<?php echo $this->get_field_name( 'name' ); ?>"><?php _e( 'Ask the name?', 'email-newsletter' ) ?></label>
                <input id="<?php echo $this->get_field_id( 'name' ); ?>" name="<?php echo $this->get_field_name( 'name' ); ?>" type="checkbox" value="1" <?php echo $name ? ' checked' : '';?> />

            </p>
            <p>
                <label for="<?php echo $this->get_field_name( 'groups' ); ?>"><?php _e( 'Show Groups?', 'email-newsletter' ) ?></label>
                <input id="<?php echo $this->get_field_id( 'groups' ); ?>" name="<?php echo $this->get_field_name( 'groups' ); ?>" type="checkbox" value="1" <?php echo $groups ? ' checked' : '';?> />

            </p>
        <?php
    }

} // class e_newsletter_subscribe


add_action( 'widgets_init', create_function( '', 'return register_widget("e_newsletter_subscribe");' ) );
add_action( 'init', 'email_newsletter_widgets_scripts' );

function register_enewsletter_plugin_template( $template_id, $plugin_name, $email_type) {
	global $email_newsletter;
	$return = array(
		'name' => $plugin_name,
		'type' => $email_type,
	);
	$email_newsletter->plugin_templates[$template_id] = $return;
}
function is_active_enewsletter_plugin_template( $template_id ) {
	// To-Do Make additional is_active check
	if(isset($email_newsletter->plugin_templates[$template_id]) && is_array($email_newsletter->plugin_templates[$template_id]))
		return true;
	else
		return false;
}

function email_newsletter_widgets_scripts() {
    wp_register_script( 'email-newsletter-widget-scripts', plugins_url( '/email-newsletter-files/js/widget_script.js', __FILE__ ), array( 'jquery', 'jquery-form' ) );
    wp_enqueue_script( 'email-newsletter-widget-scripts' );
}

?>