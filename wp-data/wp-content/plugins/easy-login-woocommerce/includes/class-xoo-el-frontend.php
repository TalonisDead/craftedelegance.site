<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}


class Xoo_El_Frontend{

	protected static $_instance = null;

	public $glSettings;

	public static function get_instance(){
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct(){
		$this->glSettings = xoo_el_helper()->get_general_option();
		$this->hooks();
	}

	public function hooks(){
		add_action( 'wp_enqueue_scripts', array($this,'enqueue_styles') );
		add_action( 'wp_enqueue_scripts', array($this,'enqueue_scripts'), 5 );
		add_action( 'wp_footer', array($this,'popup_markup') );
		add_shortcode( 'xoo_el_action', array($this,'markup_shortcode') );

		add_shortcode( 'xoo_el_pop', array( $this, 'pop_shortcode' ) );
		
		add_filter( 'xoo_easy-login-woocommerce_get_template', array( $this, 'force_plugin_templates_over_outdated' ), 10, 4 );
	}


	//Enqueue stylesheets
	public function enqueue_styles(){

		wp_enqueue_style( 'xoo-el-style', XOO_EL_URL.'/assets/css/xoo-el-style.css', array(), XOO_EL_VERSION );
		wp_enqueue_style( 'xoo-el-fonts', XOO_EL_URL.'/assets/css/xoo-el-fonts.css', array(), XOO_EL_VERSION );

		ob_start();
		xoo_el_helper()->get_template( '/global/inline-style.php' );
		wp_add_inline_style( 'xoo-el-style',  ob_get_clean() . stripslashes( xoo_el_helper()->get_advanced_option('m-custom-css') )  );

	}

	//Enqueue javascript
	public function enqueue_scripts(){

		//Enqueue Form field framework scripts
		xoo_el()->aff->enqueue_scripts();

		wp_enqueue_script( 'xoo-el-js', XOO_EL_URL.'/assets/js/xoo-el-js.js', array('jquery'), XOO_EL_VERSION, true ); // Main JS

		$localizeData = array(
			'adminurl'  		=> admin_url().'admin-ajax.php',
			'redirectDelay' 	=> apply_filters( 'xoo_el_redirect_delay', 300 ),
			'html' 				=> array(
				'spinner' 	=> '<i class="xoo-el-icon-spinner8 xoo-el-spinner"></i>',
				'editField' => '<span class="xoo-el-edit-em">' . __( 'Change?', 'mobile-login-woocommerce' ) . '</span>',
				'notice' 	=> array(
					'error' 	=> xoo_el_add_notice( 'error', "%s" ),
					'success' 	=> xoo_el_add_notice( 'success', "%s" )
				)
			),
			'autoOpenPopup' 	=> $this->is_auto_open_page() ? 'yes' : 'no',
			'autoOpenPopupOnce' => $this->glSettings['ao-once'],
			'aoDelay' 			=> $this->glSettings['ao-delay'],
			'loginClass' 		=> xoo_el_helper()->get_advanced_option('m-login-class'),
			'registerClass' 	=> xoo_el_helper()->get_advanced_option('m-register-class'),
			'errorLog' 			=> xoo_el_helper()->get_advanced_option('m-error-log'),
		);

		if( class_exists('woocommerce') ){
			$localizeData['checkout'] =  array(
				'loginEnabled' 		=> $this->glSettings['m-en-chkout'],
				'loginRedirect' 	=> esc_url( $_SERVER['REQUEST_URI'] )
			);
		}

		$localizeData = apply_filters( 'xoo_el_localize_data', $localizeData );

		wp_localize_script( 'xoo-el-js', 'xoo_el_localize', $localizeData );

	}


	public function is_auto_open_page(){

		if( !trim( $this->glSettings['ao-pages'] ) ){
			$pages = array();
		}
		else{
			$pages = array_map( 'trim', explode( ',', $this->glSettings['ao-pages'] ) );
		}

		$isPage = $this->glSettings['ao-enable'] === "yes" && ( empty( $pages ) || is_page( $pages ) || ( class_exists('woocommerce') && is_product() && in_array( get_the_id() , $pages ) ) );


		foreach ( $pages as $page_id ) {
			if( is_single( $page_id ) ){
				$isPage = true;
				break;
			}
		}

		return apply_filters( 'xoo_el_is_auto_open_page', $isPage, $pages );
	}


	//Add popup to footer
	public function popup_markup(){
		if( is_user_logged_in() ) return;
		xoo_el_helper()->get_template( 'xoo-el-popup.php' );
		xoo_el_helper()->get_template( '/global/xoo-el-notice-popup.php' );
	}


	public function pop_shortcode( $atts ){

		$atts = shortcode_atts( array(
			'type'				=> 'login',
			'text' 				=> '',
			'change_to_text' 	=> '',
			'redirect_to' 		=> ''
		), $atts, 'xoo_el_pop');



		$change_to_text = wp_kses_post( html_entity_decode( $atts['change_to_text'] ) );
		$text 			= wp_kses_post( html_entity_decode( $atts['text'] ) );

		if( is_user_logged_in() && $change_to_text ){

			$user = wp_get_current_user();

			$changeToTextsHolders = array(
				'{firstname}' 	=> $user->first_name,
				'{lastname}' 	=> $user->last_name,
				'{username}' 	=> $user->user_login,
			);

			foreach ($changeToTextsHolders as $holderKey => $holderValue) {
				$change_to_text = str_replace( $holderKey , $holderValue, $change_to_text );
			}

			preg_match_all('/\{logout\}(.*?)\{\/logout\}/s', $change_to_text, $logout_match);

		    if( isset( $logout_match[1] ) ){

		    	$logout_link 	= !empty( $this->glSettings['m-red-logout'] ) ? $this->glSettings['m-red-logout'] : $_SERVER['REQUEST_URI'];
				$change_to_link = wp_logout_url( $logout_link );

		    	foreach ($logout_match[1] as $index => $content ) {
		    		$logoutHTML 	= '<a href="'.esc_url( $change_to_link ).'">'.$content.'</a>';
			    	$change_to_text = str_replace( $logout_match[0][$index] , $logoutHTML, $change_to_text );
			    }

		    }

			$html = $change_to_text;
		}
		else{

			$action_type = isset( $user_atts['action'] ) ? $user_atts['action'] : $atts['type'];

			switch ( $action_type ) {
				case 'login':
					$popclass = 'xoo-el-login-tgr';
					break;

				case 'register':
					$popclass = 'xoo-el-reg-tgr';
					break;

				case 'lost-password':
					$popclass = 'xoo-el-lostpw-tgr';
					break;
				
				default:
					$popclass = 'xoo-el-login-tgr';
					break;
			}

			$popclass .= ' xoo-el-pop-sc';

			if( $atts['redirect_to'] === 'same' ){
				$redirect = $_SERVER['REQUEST_URI'];
			}
			elseif( $atts['redirect_to'] ){
				$redirect = $atts['redirect_to'];
			}
			else{
				$redirect = false;
			}

			$redirect = $redirect ? 'data-redirect="'.esc_url( $redirect ).'"' : '';

			// Extract content inside {pop} and {/pop}
		    preg_match_all('/\{pop\}(.*?)\{\/pop\}/s', $text, $pop_match);

		    if( isset( $pop_match[1] ) ){
		    	foreach ($pop_match[1] as $index => $content ) {
		    		$popHTML = sprintf( '<div class="%1$s" %2$s>%3$s</div>', $popclass, $redirect, $content );
			    	$text = str_replace( $pop_match[0][$index] , $popHTML, $text );
			    }

		    }

			$html = $text;

		}

		$contHTML = '<div class="xoo-el-action-sc">'.$html.'</div>';

		return $contHTML;
	}


	//Shortcode
	public function markup_shortcode($user_atts){

		$atts = shortcode_atts( array(
			'action' 			=> 'login', // For version < 1.3
			'type'				=> 'login',
			'text' 				=> '',
			'change_to' 		=> 'logout',
			'change_to_text' 	=> '',
			'display' 			=> 'link',
			'redirect_to' 		=> ''
		), $user_atts, 'xoo_el_action');


		$class = 'xoo-el-action-sc ';

		if( $atts['display'] === 'button' ){
			$class .= 'button btn ';
		}

		if( is_user_logged_in() ){

			$user = wp_get_current_user();

			$change_to_text = esc_html( $atts['change_to_text'] );

			$changeToTextsHolders = array(
				'{firstname}' 	=> $user->first_name,
				'{lastname}' 	=> $user->last_name,
				'{username}' 	=> $user->user_login,
			);

			foreach ($changeToTextsHolders as $holderKey => $holderValue) {
				$change_to_text = str_replace( $holderKey , $holderValue, $change_to_text );
			}

			if( $atts['change_to'] === 'myaccount' ) {
				$change_to_link = wc_get_page_permalink( 'myaccount' );
				$change_to_text =  !empty( $change_to_text ) ? $change_to_text : __('My account','easy-login-woocommerce');
			}
			else if( $atts['change_to'] === 'logout' ){
				$logout_link 	= !empty( $this->glSettings['m-red-logout'] ) ? $this->glSettings['m-red-logout'] : $_SERVER['REQUEST_URI'];
				$change_to_link = wp_logout_url( $logout_link );
				$change_to_text =  !empty( $change_to_text ) ? $change_to_text : __('Logout','easy-login-woocommerce');
			}
			else if( $atts['change_to'] === 'hide' ){
				return '';
			}
			else{
				$change_to_link = $atts['change_to'];
				$change_to_text =  !empty( $change_to_text ) ? $change_to_text : __('Logout','easy-login-woocommerce');
			}

			$html =  '<a href="'.esc_url( $change_to_link ).'" class="'.$class.'">'.$change_to_text.'</a>';
		}
		else{
			$action_type = isset( $user_atts['action'] ) ? $user_atts['action'] : $atts['type'];
			switch ( $action_type ) {
				case 'login':
					$class .= 'xoo-el-login-tgr';
					$text  	= __('Login','easy-login-woocommerce');
					break;

				case 'register':
					$class .= 'xoo-el-reg-tgr';
					$text  	= __('Signup','easy-login-woocommerce');
					break;

				case 'lost-password':
					$class .= 'xoo-el-lostpw-tgr';
					$text 	= __('Lost Password','easy-login-woocommerce');
					break;
				
				default:
					$class .= 'xoo-el-login-tgr';
					$text 	= __('Login','easy-login-woocommerce');
					break;
			}

			if( $atts['text'] ){
				$text = esc_html( $atts['text'] );
			}

			if( $atts['redirect_to'] === 'same' ){
				$redirect = $_SERVER['REQUEST_URI'];
			}
			elseif( $atts['redirect_to'] ){
				$redirect = $atts['redirect_to'];
			}
			else{
				$redirect = false;
			}

			$redirect = $redirect ? 'data-redirect="'.esc_url( $redirect ).'"' : '';

			$html = sprintf( '<a class="%1$s" %2$s>%3$s</a>', $class, $redirect, $text );

		}
		return $html;
	}

	public function force_plugin_templates_over_outdated( $template, $template_name, $args, $template_path ){

		$templates_data = xoo_el_helper()->get_theme_templates_data();

		if( empty( $templates_data ) || $templates_data['has_outdated'] !== 'yes' ) return $template;

		$templates = $templates_data['templates'];		

		foreach ( $templates as $template_data ) {
			if( $template_data['is_outdated'] === "yes" && version_compare( $template_data['theme_version'] , '2.0', '<' )  && basename( $template_name ) === $template_data['basename'] && @md5_file( $template ) === @md5_file( $template_data['file'] ) ){
				return XOO_EL_PATH.'/templates/'.$template_name;
			}
		}

		return $template;
	}
}


function xoo_el_frontend(){
	return Xoo_El_Frontend::get_instance();
}

xoo_el_frontend();

?>
