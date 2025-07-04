<?php

$field_settings = array(
	'xoo_aff_text' => array(
		'active',
		'required',
		'show_label',
		'label',
		'cols',
		'default',
		'icon',
		'placeholder',
		'minlength',
		'maxlength',
		'unique_id',
		'class',
		'autocomplete_field_id',
		'autocomplete_field_type',
	),

	'xoo_aff_textarea' => array(
		'active',
		'required',
		'show_label',
		'label',
		'ta_rows',
		'cols',
		'default',
		'placeholder',
		'minlength',
		'maxlength',
		'unique_id',
		'class',
		'autocomplete_field_id',
		'autocomplete_field_type'
	),

	'xoo_aff_number' => array(
		'active',
		'required',
		'show_label',
		'label',
		'cols',
		'default',
		'icon',
		'placeholder',
		'step',
		'min',
		'max',
		'unique_id',
		'class'
	),
	'xoo_aff_date' 	=> array(
		'active',
		'required',
		'show_label',
		'label',
		'cols',
		'default' => array(
			'type' => 'date'
		), 
		'icon',
		'placeholder', 
		'date_format',
		'unique_id',
		'class'
	),
	'xoo_aff_checkbox_single' => array(
		'active',
		'required',
		'show_label',
		'cols',
		'label',
		'placeholder',
		'checkbox_single',
		'unique_id',
		'class'
	),
	'xoo_aff_checkbox_list' => array(
		'active',
		'show_label',
		'cols',
		'label',
		'checkbox_list' ,
		'unique_id',
		'class'
	),

	'xoo_aff_radio' => array(
		'active',
		'show_label',
		'cols',
		'one_line' => array(
			'title' => 'Radio items in one line'
		),
		'label',
		'radio',
		'unique_id',
		'class'
	),

	'xoo_aff_select_list' => array(
		'active',
		'required',
		'show_label', 
		'label',
		'cols',
		'icon',
		'use_select2',
		'placeholder',
		'select_list',
		'unique_id',
		'class'
	),

	'xoo_aff_email' => array( 
		'active', 
		'required', 
		'show_label', 
		'label', 
		'cols', 
		'icon', 
		'placeholder',
		'unique_id', 
		'class'
	),
	'xoo_aff_country' => array(
		'active',
		'required',
		'show_label', 
		'label',
		'cols',
		'icon',
		'use_select2',
		'placeholder',
		'country_list',
		'country_choose',
		'countries',
		'default' => array(
			'type' 			=> 'select',
			'options' 		=> array_merge( array( 'xoo_ff_placeholder' => 'Placeholder' ), (array) include XOO_AFF_DIR.'/countries/countries.php' ),
		),
		'unique_id',
		'class',
		'autocomplete_field_id',
	),
	'xoo_aff_states' => array(
		'active',
		'required',
		'show_label', 
		'label',
		'cols',
		'icon',
		'use_select2' => array(
			'value' => 'yes'
		),
		'placeholder',
		'for_country_id',
		'unique_id',
		'class',
		'autocomplete_field_id',
	),

	'xoo_aff_phone_code' => array(
		'active',
		'required',
		'phone_code_display_type',
		'show_label', 
		'label',
		'cols',
		'icon',
		'use_select2' => array(
			'value' => 'yes'
		),
		'placeholder',
		'country_list',
		'country_choose',
		'default' => array(
			'type' 			=> 'select',
			'options' 		=> (array) include XOO_AFF_DIR.'/countries/countries.php',
		),
		'for_country_id' => array(
			'required' => 'no',
		),
		'unique_id',
		'class'
	),

	'xoo_aff_phone' => array(
		'active',
		'required',
		'show_label',
		'label',
		'cols',
		'default',
		'icon',
		'placeholder',
		'minlength',
		'maxlength',
		'unique_id',
		'class'
	),

	'xoo_aff_password' => array(
		'active',
		'required',
		'show_label',
		'label',
		'cols',
		'icon',
		'placeholder',
		'minlength',
		'maxlength',
		'strength_meter',
		'strength_meter_pass',
		'password_visibility',
		'unique_id',
		'class'
	),

	'xoo_aff_upload' => array(
		'active',
		'required',
		'show_label',
		'label',
		'cols',
		'icon' => array(
			'value' => 'fas fa-cloud-upload-alt'
		),
		'max_filesize',
		'file_type',
		'file_multiple',
		'file_multiple_max',
		'unique_id',
		'class'
	),


	'xoo_aff_profile_photo' => array(
		'active',
		'required',
		'show_label',
		'label' => array(
			'value' => 'Upload your Photo'
		),
		'cols',
		'icon' => array(
			'value' => 'fas fa-user-circle'
		),
		'upload_layout',
		'profile_icon_size',
		'max_filesize',
		'file_type' => array(
			'value' => '.png, .jpg'
		),
		'file_multiple' => array(
			'value' => 'no',
			'visible' => 'no',
		),
		'file_multiple_max' => array(
			'value' => 1,
			'visible' => 'no'
		),
		'unique_id',
		'class'
	),


	'xoo_aff_autocomplete_address' => array(
		'active',
		'required',
		'show_label',
		'autocomplete_auto_fetch',
		'label',
		'cols',
		'default',
		'icon' => array(
			'value' => 'fas fa-location-arrow'
		),
		'placeholder' => array(
			'value' => 'Deliver to'
		),
		'minlength',
		'maxlength',
		'unique_id',
		'class'
	),
	
);

return apply_filters( 'xoo_aff_'.$this->plugin_slug.'_default_field_settings', $field_settings );