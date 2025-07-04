<?php
if ( ! function_exists( 'get_editable_roles' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}
$editable_roles = array_reverse( get_editable_roles() );
foreach ( $editable_roles as $role_id => $role_data) {
	$user_roles[$role_id] = translate_user_role( $role_data['name'] );
}
$user_roles = apply_filters( 'xoo_el_admin_user_roles', $user_roles );

$localizeTexts = version_compare( get_option( 'xoo-el-version' ) , '2.5', '<' );

$settings = array(

	/** MAIN **/
	array(
		'callback' 		=> 'links',
		'title' 		=> 'Links',
		'id' 			=> 'fake',
		'section_id' 	=> 'gl_main',
		'args' 			=> array(
			'options' 	=> array(
				admin_url('admin.php?page=xoo-el-fields') => 'Manage Fields',
				admin_url( 'nav-menus.php?xoo_el_nav=true' ) => 'Add Links to Menu',
			)
		)
	),


	array(
		'callback' 		=> 'asset_selector',
		'title' 		=> 'Form Pattern',
		'id' 			=> 'm-form-pattern',
		'section_id' 	=> 'gl_main',
		'default' 		=> 'separate',
		'args' 			=> array(
			'options' => array(
				'separate' 	=> array(
					'title' => 'Separate',
					'asset' => XOO_EL_URL.'/admin/assets/images/pattern-separate.jpg',
					'info' 	=> 'Displays separate login and registration forms side by side'
				),
				'single' 	=> array(
					'title' => 'Single',
					'asset' => XOO_EL_URL.'/admin/assets/images/pattern-single.jpg',
					'info' 	=> 'A single field form where users enter email or username and are auto-directed to login or registration based on input.'
				)
			),
			'custom_attributes' => array(
				'data-multiple' => 'no',
				'data-required' => 'yes'
			)
		),

	),



	array(
		'callback' 		=> 'checkbox',
		'title' 		=> 'Enable Registration',
		'id' 			=> 'm-en-reg',
		'section_id' 	=> 'gl_main',
		'default' 		=> 'yes',
	),

	array(
		'callback' 		=> 'select',
		'title' 		=> 'User Role',
		'id' 			=> 'm-user-role',
		'section_id' 	=> 'gl_main',
		'args'			=> array(
			'options' => $user_roles
		),
		'default' 		=> class_exists( 'woocommerce' ) ? 'customer' : 'subscriber',
		'desc' 			=> 'Register users with role.<br> You can also enable "User Role" field from the "Fields" page and allow users to select their role while signing up.'
	),

	array(
		'callback' 		=> 'checkbox',
		'title' 		=> 'Auto Login User on Sign up',
		'id' 			=> 'm-auto-login',
		'section_id' 	=> 'gl_main',
		'default' 		=> 'yes',
	),


	array(
		'callback' 		=> 'checkbox',
		'title' 		=> 'Handle Reset Password',
		'id' 			=> 'm-reset-pw',
		'section_id' 	=> 'gl_main',
		'default' 		=> 'yes',
		'desc' 			=> 'If checked, allow users to set a new password in form.'
	),




	array(
		'callback' 		=> 'select',
		'title' 		=> 'Navigation Pattern',
		'id' 			=> 'm-nav-pattern',
		'section_id' 	=> 'gl_main',
		'args'			=> array(
			'options' => array(
				'tabs' 		=> 'Header Tabs',
				'links' 	=> 'Footer Links',
				'disable' 	=> 'Disable'
			)
		),
		'default' 		=> 'tabs',
		'desc' 			=> 'Choose a way to switch between login and registration form.'
	),


);


if( class_exists( 'woocommerce' ) ){
	$settings[] = array(
		'callback' 		=> 'checkbox',
		'title' 		=> 'Replace myaccount form',
		'id' 			=> 'm-en-myaccount',
		'section_id' 	=> 'gl_wc',
		'default' 		=> 'yes',
		'desc' 			=> 'If checked , this will replace woocommerce myaccount page form.'
	);

	$settings[] = array(
		'callback' 		=> 'textarea',
		'title' 		=> 'My account page form shortcode',
		'id' 			=> 'm-myacc-sc',
		'section_id' 	=> 'gl_wc',
		'default' 		=> '[xoo_el_inline_form active="login"]',
		'desc' 			=> 'My account page form shortcode. See info tab for shortcode details',
		'args' 			=> array(
			'rows' => 2,
			'cols' => 60,
			'custom_attributes' => array(
				'spellcheck' => 'false',
			)
		)
	);

	$settings[] = array(
		'callback' 		=> 'textarea',
		'title' 		=> 'Lost Password page form shortcode',
		'id' 			=> 'm-myacclpw-sc',
		'section_id' 	=> 'gl_wc',
		'default' 		=> '[xoo_el_inline_form active="lostpw"]',
		'desc' 			=> 'Lost Password page form shortcode. See info tab for shortcode details',
		'args' 			=> array(
			'rows' => 2,
			'cols' => 60,
			'custom_attributes' => array(
				'spellcheck' => 'false',
			)
		)
	);

	$settings[] = array(
		'callback' 		=> 'checkbox',
		'title' 		=> 'Replace Checkout page login',
		'id' 			=> 'm-en-chkout',
		'section_id' 	=> 'gl_wc',
		'default' 		=> 'yes',
		'desc' 			=> 'This will replace checkout page login form, make sure to enable "Login during checkout" from woocommerce settings'
	);

	$settings[] = array(
		'callback' 		=> 'textarea',
		'title' 		=> 'Checkout page form shortcode',
		'id' 			=> 'm-chkout-sc',
		'section_id' 	=> 'gl_wc',
		'default' 		=> '[xoo_el_inline_form active="login" login_redirect="same" register_redirect="same"]',
		'desc' 			=> 'Checkout page form shortcode. See info tab for shortcode details',
		'args' 			=> array(
			'rows' => 2,
			'cols' => 60,
			'custom_attributes' => array(
				'spellcheck' => 'false',
			)
		)
	);
}


$popup = array(


	array(
		'callback' 		=> 'text',
		'title' 		=> 'Login Redirect',
		'id' 			=> 'm-red-login',
		'section_id' 	=> 'gl_red',
		'default' 		=> '',
		'desc' 			=> 'Leave empty to redirect on the same page.'
	),

	array(
		'callback' 		=> 'text',
		'title' 		=> 'Register Redirect',
		'id' 			=> 'm-red-register',
		'section_id' 	=> 'gl_red',
		'default' 		=> '',
		'desc' 			=> 'Leave empty to redirect on the same page.'
	),

	array(
		'callback' 		=> 'text',
		'title' 		=> 'Logout Redirect',
		'id' 			=> 'm-red-logout',
		'section_id' 	=> 'gl_red',
		'default' 		=> '',
		'desc' 			=> 'Leave empty to redirect on the same page.'
	),


	array(
		'callback' 		=> 'checkbox',
		'title' 		=> 'Success Endpoint',
		'id' 			=> 'm-ep-success',
		'section_id' 	=> 'gl_red',
		'default' 		=> 'yes',
		'desc' 			=> 'Adds (login="success" & register="success") in URL bar on login & register. Clears cache on login/register if you have cache plugin enabled'
	),

	array(
		'callback' 		=> 'checkbox_list',
		'title' 		=> 'Forms',
		'id' 			=> 'popup-forms',
		'section_id' 	=> 'gl_popup',
		'args' 			=> array(
			'options' 	=> array(
				'login' 		=> 'Login',
				'register' 		=> 'Register',
			),
		),
		'default' 	=> array(
			'login', 'register',
		)
	),


	array(
		'callback' 		=> 'checkbox',
		'title' 		=> 'Prevent closing',
		'id' 			=> 'popup-force',
		'section_id' 	=> 'gl_popup',
		'default' 		=> 'no',
		'desc' 			=> 'Once popup is opened, this option will prevent user from closing it. Useful when you want to hide your website page content for guest users. You can also set "overlay opacity to 1" from style tab to completely blackout the background.'
	),



	array(
		'callback' 		=> 'checkbox',
		'title' 		=> 'Auto open Popup',
		'id' 			=> 'ao-enable',
		'section_id' 	=> 'gl_ao',
		'default' 		=> 'yes',
	),


	array(
		'callback' 		=> 'select',
		'title' 		=> 'Default Tab',
		'id' 			=> 'ao-default-form',
		'section_id' 	=> 'gl_ao',
		'args' 			=> array(
			'options' 		=> array(
				'login' 	=> 'Login',
				'register' 	=> 'Register',
			),
		),
		'default' 		=> 'login',
	),


	array(
		'callback' 		=> 'checkbox',
		'title' 		=> 'Open once',
		'id' 			=> 'ao-once',
		'section_id' 	=> 'gl_ao',
		'default' 		=> 'no',
	),


	array(
		'callback' 		=> 'textarea',
		'title' 		=> 'On Pages',
		'id' 			=> 'ao-pages',
		'section_id' 	=> 'gl_ao',
		'default' 		=> '',
		'desc' 			=> 'Use post type/page id/slug separated by comma. For eg: 19,contact-us,shop .Leave empty for every page.'
	),

	array(
		'callback' 		=> 'number',
		'title' 		=> 'Delay',
		'id' 			=> 'ao-delay',
		'section_id' 	=> 'gl_ao',
		'default' 		=> 500,
		'desc' 			=> 'Trigger popup after seconds. 1000 = 1 second'
	),


	array(
		'callback' 		=> 'text',
		'title' 		=> 'Login Tab text',
		'id' 			=> 'txt-tab-login',
		'section_id' 	=> 'gl_texts',
		'default' 		=> $localizeTexts ? __( 'Login', 'easy-login-woocommerce' ) : 'Login',
	),

	array(
		'callback' 		=> 'text',
		'title' 		=> 'Register Tab text',
		'id' 			=> 'txt-tab-reg',
		'section_id' 	=> 'gl_texts',
		'default' 		=> $localizeTexts ? __( 'Sign Up', 'easy-login-woocommerce' ) : 'Sign Up',
	),


	array(
		'callback' 		=> 'text',
		'title' 		=> 'Login Button text',
		'id' 			=> 'txt-btn-login',
		'section_id' 	=> 'gl_texts',
		'default' 		=> $localizeTexts ? __( 'Sign in', 'easy-login-woocommerce' ) : 'Sign in',
	),

	array(
		'callback' 		=> 'text',
		'title' 		=> 'Register Button text',
		'id' 			=> 'txt-btn-reg',
		'section_id' 	=> 'gl_texts',
		'default' 		=> $localizeTexts ? __( 'Sign Up', 'easy-login-woocommerce' ) : 'Sign Up',
	),

	array(
		'callback' 		=> 'text',
		'title' 		=> 'Reset password Button text',
		'id' 			=> 'txt-btn-respw',
		'section_id' 	=> 'gl_texts',
		'default' 		=> $localizeTexts ? __( 'Email Reset Link', 'easy-login-woocommerce' ) : 'Email Reset Link',
	),


	array(
		'callback' 		=> 'text',
		'title' 		=> 'Single Field Form Heading',
		'id' 			=> 'txt-sing-head',
		'section_id' 	=> 'gl_texts',
		'default' 		=> 'Welcome to '.esc_attr( get_bloginfo( 'name' ) ),
	),


	array(
		'callback' 		=> 'text',
		'title' 		=> 'Single Field Form Subtext',
		'id' 			=> 'txt-sing-subtxt',
		'section_id' 	=> 'gl_texts',
		'default' 		=> 'Log in or sign up with your email.',
	),

	array(
		'callback' 		=> 'text',
		'title' 		=> 'Single Field Form Button text',
		'id' 			=> 'txt-btn-single',
		'section_id' 	=> 'gl_texts',
		'default' 		=> 'Continue',
	),




);

$settings = array_merge( $settings, $popup );

return apply_filters( 'xoo_el_admin_settings', $settings, 'general' );

?>