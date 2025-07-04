<?php

class Xoo_Aff{

	public $plugin_slug, $admin_page_slug, $fields, $admin, $en_autocompadr, $hasUpdated;

	public function __construct( $plugin_slug, $admin_page_slug ){

		$this->plugin_slug = $plugin_slug;
		$this->admin_page_slug = $admin_page_slug;

		$this->includes();
		$this->hooks();
		$this->init();
		
	}

	public function hooks(){
		add_action( 'init', array( $this, 'on_install' ), 1 );
	}

	public function includes(){

		include_once XOO_AFF_DIR.'/includes/xoo-aff-functions.php';
		include_once XOO_AFF_DIR.'/admin/class-xoo-aff-fields.php';
		include_once XOO_AFF_DIR.'/admin/class-xoo-aff-admin.php';

	}

	public function init(){

		$this->fields 		= new Xoo_Aff_Fields( $this );
		$this->admin 		= new Xoo_Aff_Admin( $this );
		
	}


	public function is_fields_page(){
		return is_admin() && isset( $_GET['page'] ) && $_GET['page'] === $this->admin_page_slug;
	}


	public function is_fields_page_ajax_request(){
		return isset( $_POST['xoo_aff_plugin_action'] ) && $_POST['xoo_aff_plugin_action'] === $this->plugin_slug;
	}


	//Enqueue scripts from the main plugin
	public function enqueue_scripts(){

		$strategy 		= array( 'strategy' => 'defer' );

		$sy_options 	= get_option( $this->admin->settings->get_option_key( 'general' ) );

		wp_enqueue_style( 'xoo-aff-style', XOO_AFF_URL.'/assets/css/xoo-aff-style.css', array(), XOO_AFF_VERSION) ;

		if( $sy_options['s-show-icons'] === "yes" ){
			wp_enqueue_style( 'xoo-aff-font-awesome5', XOO_AFF_URL.'/lib/fontawesome5/css/all.min.css' );
		}


		$fields = $this->fields->get_fields_data();

		$has_date = $has_meter = $has_phonecode = $has_select2 = $has_autocompadr = $has_states = $has_countries_locale = $has_country = false;

		$inline_style = '';

		if( !empty( $fields ) ){

			foreach ( $fields as $field_id => $field_data) {

				$settings = $field_data['settings'];

				if( isset( $settings['upload_layout'] ) && $settings['upload_layout'] === 'profile' && isset( $settings['profile_icon_size'] ) ){
					$profileSize 	= $settings['profile_icon_size'] ? sanitize_text_field( $settings['profile_icon_size'] ) : 50;
					$fieldCont 		= '.'.$field_id.'_cont';
					$inline_style 	.= "{$fieldCont} .xoo-aff-input-icon{
						font-size: {$profileSize}px;
					}
					{$fieldCont} .xoo-ff-file-preview{
						width: {$profileSize}px;
						height: {$profileSize}px;
					}
					";
				}

				if( isset( $settings['use_select2'] ) && $settings['use_select2'] === 'yes' ){
					$has_select2 = true;
				}

				if( !isset( $field_data['input_type'] ) ) continue;

				switch ( $field_data['input_type'] ) {
					case 'date':
						$has_date = true;
						break;

					case 'password':
						if( isset( $settings['strength_meter'] ) && $settings['strength_meter'] === "yes" ){
							$has_meter = true;
						}
						break;

					case 'phone_code':
						$has_phonecode = true;
						break;

					case 'autocomplete_address':
						$has_autocompadr = true;
						break;

					case 'states':
						$has_states = true;
						if( isset( $settings['for_country_id'] ) && $settings['for_country_id'] ){
							$has_countries_locale = true;
						}
						break;

					case 'country':
						$has_country = true;
						break;
				}

			}

		}

		if( $has_phonecode ){
			wp_enqueue_style( 'xoo-aff-flags', XOO_AFF_URL.'/countries/flags.css', array(), XOO_AFF_VERSION );
		}

		if( $has_meter ){
			wp_enqueue_script( 'password-strength-meter' );
		}

		if( $has_date ){
			wp_enqueue_style( 'jquery-ui-css', XOO_AFF_URL.'/lib/jqueryui/uicss.css' );
			wp_enqueue_script('jquery-ui-datepicker');
		}


		if( $has_select2 ){

			if( !wp_style_is( 'select2' ) ){
				wp_enqueue_style( 'select2', XOO_AFF_URL.'/lib/select2/select2.css');
			}

			if( !wp_script_is( 'select2' ) ){
				wp_enqueue_script( 'select2', XOO_AFF_URL.'/lib/select2/select2.js', array('jquery'), XOO_AFF_VERSION, $strategy ); // Main JS
			}

		}

		if( $this->en_autocompadr && $has_autocompadr ){
			$autocompadr_key = isset( $sy_options['aca-apikey'] ) && $sy_options['aca-apikey'] ? esc_html( $sy_options['aca-apikey'] ) : '';
			if( $autocompadr_key ){
				wp_enqueue_script( 'xoo-google-autocomplete', 'https://maps.googleapis.com/maps/api/js?key='.$autocompadr_key.'&libraries=places&language=en' );
			}
		}

		wp_enqueue_script( 'xoo-aff-js', XOO_AFF_URL.'/assets/js/xoo-aff-js.js', array( 'jquery' ), XOO_AFF_VERSION, $strategy );


		$localize_args = array(
			'adminurl'  			=> admin_url().'admin-ajax.php',
			'password_strength' 	=> array(
				'min_password_strength' => apply_filters( 'xoo_aff_min_password_strength', 3 ),
				'i18n_password_error'   => esc_attr__( 'Please enter a stronger password.', $this->plugin_slug ),
				'i18n_password_hint'    => esc_attr( wp_get_password_hint() ),
			)
		);

		if( $has_states ){
			$localize_args['states'] = json_encode( include XOO_AFF_DIR.'/countries/states.php' );
			if( $has_country && $has_countries_locale ){
				$localize_args['countries_locale'] = json_encode( include XOO_AFF_DIR.'/countries/country-locale.php' );
			}
		}

		if( isset( $autocompadr_key ) ){

			$restrictCountries = isset( $sy_options['aca-countries'] ) &&  $sy_options['aca-countries'] ? esc_html( $sy_options['aca-countries'] ) : array();	

			if( !empty( $sy_options['aca-countries'] ) ){
				$localize_args['geolocate_countries'] = explode(',', $restrictCountries);
			}

			$localize_args['geolocate_apikey'] = $autocompadr_key;

		}
		
		wp_localize_script('xoo-aff-js','xoo_aff_localize', $localize_args );

		$inline_style = xoo_aff_get_template( 'xoo-aff-inline-style.php',  XOO_AFF_DIR.'/includes/templates/', array( 'sy_options' => $sy_options ), true ) . $inline_style ;

		wp_add_inline_style( 'xoo-aff-style', $inline_style );

	}


	/**
	 * What type of request is this?
	 *
	 * @param  string $type admin, ajax, cron or frontend.
	 * @return bool
	 */
	private function is_request( $type ) {
		switch ( $type ) {
			case 'admin':
				return is_admin();
			case 'ajax':
				return defined( 'DOING_AJAX' );
			case 'cron':
				return defined( 'DOING_CRON' );
			case 'frontend':
				return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
		}
	}


	public function on_install(){

		$this->en_autocompadr = apply_filters( 'xoo_aff_enable_autocompadr', true, $this );

		$db_version = get_option( 'xoo_aff_'.$this->plugin_slug.'_version' );

		if( $db_version && version_compare( $db_version, '1.7' , '<' ) ){

			$fields = $this->fields->get_fields_data();

			if( !empty( $fields ) ){

				$inputTypeWithSelect = array( 'phone_code', 'country' ,'states' );

				foreach ( $fields as $field_id => $field_data) {

					if( in_array( $field_data['input_type'] , $inputTypeWithSelect ) ||  ( isset( $field_data['settings']['select_list'] ) && count($field_data['settings']['select_list']) > 5 ) ){
						$fields[$field_id]['settings']['use_select2'] = 'yes';
					}
				}

				$this->fields->update_db_fields( $fields );

			}
		}
		
		if( version_compare( $db_version, XOO_AFF_VERSION , '<' ) ){
			$this->hasUpdated = true;
			update_option( 'xoo_aff_'.$this->plugin_slug.'_version', XOO_AFF_VERSION );
		}
	}

}


?>