<?php
/**
 * The common functionality of the plugin.
 *
 * @link       https://wpswings.com/
 * @since      1.0.0
 *
 * @package    Track_Orders_For_Woocommerce
 * @subpackage Track_Orders_For_Woocommerce/common
 */

use Automattic\WooCommerce\Utilities\OrderUtil;
/**
 * The common functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the common stylesheet and JavaScript.
 * namespace track_orders_for_woocommerce_common.
 *
 * @package    Track_Orders_For_Woocommerce
 * @subpackage Track_Orders_For_Woocommerce/common
 */
class Track_Orders_For_Woocommerce_Common {
	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string $plugin_name       The name of the plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Register the stylesheets for the common side of the site.
	 *
	 * @since    1.0.0
	 */
	public function tofw_common_enqueue_styles() {
		wp_enqueue_style( $this->plugin_name . 'common', TRACK_ORDERS_FOR_WOOCOMMERCE_DIR_URL . 'common/css/track-orders-for-woocommerce-common.css', array(), $this->version, 'all' );
	}

	/**
	 * Register the JavaScript for the common side of the site.
	 *
	 * @since    1.0.0
	 */
	public function tofw_common_enqueue_scripts() {
		wp_register_script( $this->plugin_name . 'common', TRACK_ORDERS_FOR_WOOCOMMERCE_DIR_URL . 'common/js/track-orders-for-woocommerce-common.js', array( 'jquery' ), $this->version, false );
		wp_localize_script(
			$this->plugin_name . 'common',
			'tofw_common_param',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'tofw_common_param_nonce' ),
			)
		);
		wp_enqueue_script( $this->plugin_name . 'common' );
	}

	/**
	 * Validating wpswings license
	 *
	 * @since    1.0.0
	 */
	public function wps_tofw_validate_license_key() {
		check_ajax_referer( 'ajax-nonce', 'nonce' );
		$wps_tofw_purchase_code = ( ! empty( $_POST['purchase_code'] ) ) ? sanitize_text_field( wp_unslash( $_POST['purchase_code'] ) ) : '';
		$wps_tofw_response = self::track_orders_for_woocommerce_license_code_update( $wps_tofw_purchase_code );
		if ( is_wp_error( $wps_tofw_response ) ) {
			echo wp_json_encode(
				array(
					'status' => false,
					'msg' => __(
						'An unexpected error occurred. Please try again.',
						'track-orders-for-woocommerce'
					),
				)
			);

		} else {
			$wps_tofw_license_data = json_decode( wp_remote_retrieve_body( $wps_tofw_response ) );

			if ( isset( $wps_tofw_license_data->result ) && 'success' === $wps_tofw_license_data->result ) {
				update_option( 'wps_tofw_license_key', $wps_tofw_purchase_code );
				update_option( 'wps_tofw_license_check', true );

				echo wp_json_encode(
					array(
						'status' => true,
						'msg' => __(
							'Successfully Verified. Please Wait.',
							'track-orders-for-woocommerce'
						),
					)
				);

			} else {
				echo wp_json_encode(
					array(
						'status' => false,
						'msg' => $wps_tofw_license_data->message,
					)
				);

			}
		}
		wp_die();
	}

	/**
	 * Function is used for the sending the track data.
	 *
	 * @param boolean $override is a boolean.
	 * @return void
	 */
	public function tofw_wpswings_tracker_send_event( $override = false ) {
		require_once WC()->plugin_path() . '/includes/class-wc-tracker.php';

		$last_send = get_option( 'wpswings_tracker_last_send' );
		if ( ! apply_filters( 'wpswings_tracker_send_override', $override ) ) {
			// Send a maximum of once per week by default.
			$last_send = $this->wps_tofw_last_send_time();
			if ( $last_send && $last_send > apply_filters( 'wpswings_tracker_last_send_interval', strtotime( '-1 week' ) ) ) {
				return;
			}
		} else {
			// Make sure there is at least a 1 hour delay between override sends, we don't want duplicate calls due to double clicking links.
			$last_send = $this->wps_tofw_last_send_time();
			if ( $last_send && $last_send > strtotime( '-1 hours' ) ) {
				return;
			}
		}
		$api_route = '';
		$api_route = 'mp';
		$api_route .= 's';
		// Update time first before sending to ensure it is set.
		update_option( 'wpswings_tracker_last_send', time() );
		$params = WC_Tracker::get_tracking_data();
		$params = apply_filters( 'wpswings_tracker_params', $params );
		$api_url = 'https://tracking.wpswings.com/wp-json/' . $api_route . '-route/v1/' . $api_route . '-testing-data/';
		$sucess = wp_safe_remote_post(
			$api_url,
			array(
				'method'      => 'POST',
				'body'        => wp_json_encode( $params ),
			)
		);
	}

	/**
	 * Get the updated time.
	 *
	 * @name wps_tofw_last_send_time
	 *
	 * @since 1.0.0
	 */
	public function wps_tofw_last_send_time() {
		return apply_filters( 'wpswings_tracker_last_send_time', get_option( 'wpswings_tracker_last_send', false ) );
	}

	/**
	 * Update the option for settings from the multistep form.
	 *
	 * @name tofw_wps_standard_save_settings_filter
	 * @since 1.0.0
	 */
	public function tofw_wps_standard_save_settings_filter() {
		check_ajax_referer( 'ajax-nonce', 'nonce' );

		$term_accpted = ! empty( $_POST['consetCheck'] ) ? sanitize_text_field( wp_unslash( $_POST['consetCheck'] ) ) : ' ';
		if ( ! empty( $term_accpted ) && 'yes' == $term_accpted ) {
			update_option( 'tofw_enable_tracking', 'on' );
		}
		// settings fields.
		$first_name = ! empty( $_POST['firstName'] ) ? sanitize_text_field( wp_unslash( $_POST['firstName'] ) ) : '';
		update_option( 'firstname', $first_name );

		$email = ! empty( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
		update_option( 'email', $email );

		$desc = ! empty( $_POST['desc'] ) ? sanitize_text_field( wp_unslash( $_POST['desc'] ) ) : '';
		update_option( 'desc', $desc );

		$age = ! empty( $_POST['age'] ) ? sanitize_text_field( wp_unslash( $_POST['age'] ) ) : '';
		update_option( 'age', $age );

		$first_checkbox = ! empty( $_POST['FirstCheckbox'] ) ? sanitize_text_field( wp_unslash( $_POST['FirstCheckbox'] ) ) : '';
		update_option( 'first_checkbox', $first_checkbox );

		$checked_first_switch = ! empty( $_POST['checkedA'] ) ? sanitize_text_field( wp_unslash( $_POST['checkedA'] ) ) : '';
		if ( ! empty( $checked_first_switch ) && $checked_first_switch ) {
			update_option( 'tofw_radio_switch_demo', 'on' );
		}

		$checked_second_switch = ! empty( $_POST['checkedB'] ) ? sanitize_text_field( wp_unslash( $_POST['checkedB'] ) ) : '';
		if ( ! empty( $checked_second_switch ) && $checked_second_switch ) {
			update_option( 'tofw_radio_reset_license', 'on' );
		}
		update_option( 'wps_track_orders_for_woocommerce_multistep_done', 'yes' );

		wp_send_json( 'yes' );
	}

	/**
	 * Function to return template.
	 *
	 * @param string $template is a path.
	 * @return string
	 */
	public function wps_tofw_include_track_order_page( $template ) {
		$selected_template = get_option( 'wps_tofw_activated_template' );
		$wps_tofw_google_map_setting = get_option( 'wps_tofw_trackorder_with_google_map', false );
		$wps_tofw_enable_track_order_feature = get_option( 'tofw_enable_track_order', 'no' );
		$status_name = '';
		if ( 'on' != $wps_tofw_enable_track_order_feature ) {
			return $template;
		}
		if ( 'on' == $wps_tofw_enable_track_order_feature && 'on' == $wps_tofw_google_map_setting ) {
			$wps_tofw_pages = get_option( 'wps_tofw_tracking_page' );
			$page_id = $wps_tofw_pages['pages']['wps_track_order_page'];
			if ( is_page( $page_id ) ) {
				$new_template = TRACK_ORDERS_FOR_WOOCOMMERCE_DIR_PATH . 'template/wps-map-new-template.php';
				$template = $new_template;
			}
		} else {

			$wps_tofw_pages = get_option( 'wps_tofw_tracking_page', false );
			$page_id = $wps_tofw_pages['pages']['wps_track_order_page'];
			if ( is_page( $page_id ) ) {
				if ( ' ' != $selected_template && null != $selected_template ) {

					$path = '';
					$link_array = explode( '?', isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
					if ( empty( $link_array[ count( $link_array ) - 1 ] ) ) {
						$order_id = $link_array[ count( $link_array ) - 2 ];
					} else {
						$order_id = $link_array[ count( $link_array ) - 1 ];
					}
					$order = wc_get_order( $order_id );

					if ( ! $order ) {
						return $template;
					}
					// Retrieve the order status.
					$status_slug = $order->get_status();
					$order_statuses = wc_get_order_statuses();
					if ( isset( $order_statuses[ 'wc-' . $status_slug ] ) ) {
						$status_name = $order_statuses[ 'wc-' . $status_slug ];
					}

					// Retrieve the mapping from the options table.
					$status_template_mapping = get_option( 'wps_tofw_new_custom_template', array() );

					// Check if the retrieved data is valid.
					if ( is_array( $status_template_mapping ) ) {
						$current_order_status = $status_name; // Replace this with your dynamic order status.
						$template1 = false; // Initialize the template variable.

						// Loop through the mapping to find the matching template.
						foreach ( $status_template_mapping as $mapping ) {
							if ( isset( $mapping[ $current_order_status ] ) ) {
								$template1 = $mapping[ $current_order_status ]; // Assign the matched template.
								break; // Exit the loop after finding the match.
							}
						}
					}

					$found = false;
					foreach ( $status_template_mapping as $sub_array ) {
						if ( array_key_exists( $status_name, $sub_array ) ) {
							$found = true;
							break; // Exit loop once the key is found.
						}
					}

					if ( $found ) {
						// Determine the path based on the selected template.
						if ( ( 'template4' === $template1 || 'new-template1' === $template1 || 'new-template2' === $template1 || 'new-template3' === $template1 || 'template8' === $template1 ) && is_plugin_active( 'track-orders-for-woocommerce-pro/track-orders-for-woocommerce-pro.php' ) ) {
							$path = TRACK_ORDERS_FOR_WOOCOMMERCE_PRO_DIR_PATH;
						} else {
							$path = TRACK_ORDERS_FOR_WOOCOMMERCE_DIR_PATH;
						}
						// Construct the template path.
						$new_template = $path . 'template/wps-track-order-myaccount-page-' . $template1 . '.php';
						$template = $new_template;

					} else {
						if ( 'template4' === $selected_template || 'new-template1' === $selected_template || 'new-template2' === $selected_template || 'new-template3' === $selected_template || 'template8' === $selected_template ) {
							$path = TRACK_ORDERS_FOR_WOOCOMMERCE_PRO_DIR_PATH;
						} else {
							$path = TRACK_ORDERS_FOR_WOOCOMMERCE_DIR_PATH;
						}
						$new_template = $path . 'template/wps-track-order-myaccount-page-' . $selected_template . '.php';
						$template = $new_template;
					}
				} else {
					$new_template = TRACK_ORDERS_FOR_WOOCOMMERCE_DIR_PATH . 'template/wps-track-order-myaccount-page-template1.php';
					$template = $new_template;
				}
			}
		}

		return $template;
	}


	/**
	 * Function to set timing if order status.
	 *
	 * @param int    $order_id is id of order.
	 * @param string $old_status is old status.
	 * @param string $new_status is  current status.
	 * @return void
	 */
	public function wps_tofw_track_order_status( $order_id, $old_status, $new_status ) {

		require_once TRACK_ORDERS_FOR_WOOCOMMERCE_DIR_PATH . 'package/lib/phpqrcode/phpqrcode.php';

		$wps_tofw_pages = get_option( 'wps_tofw_tracking_page' );
		$page_id = $wps_tofw_pages['pages']['wps_track_order_page'];
		$track_order_url = get_permalink( $page_id );

			// Parse the URL.
			$url_parts = wp_parse_url( $track_order_url );
			$path = $url_parts['path'];
			$path = trim( $path, '/' );
			$path_parts = explode( '/', $path );
			$last_part = end( $path_parts );

		$order = wc_get_order( $order_id );
		$site_url = get_site_url() . '/' . $last_part . '/?' . esc_html( $order_id ) . '';
		$uploads = wp_upload_dir();
		$path = $uploads['basedir'] . '/tracking_images/';
		$file  = $path . $order_id . 'tracking_checkin.png';  // address of the image od barcode in which  url is saved.
		if ( file_exists( $file ) ) {

			wp_delete_file( $file );
		}

		$path = $uploads['basedir'] . '/tracking_images/';
		$file = $path . $order_id . 'tracking_checkin.png'; // path  of the image.
		$ecc = 'M';
		$pixel_size = 20;
		$frame_size = 20;
		// Generate the PNG QR code.
		QRcode::png( $site_url, $file, $ecc, $pixel_size, $frame_size );

		$old_status = 'wc-' . $old_status;
		$new_status = 'wc-' . $new_status;
		$wps_tofw_email_notifier = get_option( 'wps_tofw_email_notifier', 'no' );
		$order = new WC_Order( $order_id );
		if ( '3.0.0' > WC()->version ) {
			$wps_date_on_order_change = $order->modified_date;

		} else {
			$change_order_status = $order->get_data()['status'];

			$date_on_order_change = $order->get_data();

			$wps_date_on_order_change = $date_on_order_change['date_modified']->format( 'd F, Y H:i' );

		}
		$wps_modified_date = $wps_date_on_order_change;

		$wps_status_change_time = array();
		$wps_status_change_time_temp = array();

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// HPOS usage is enabled.
			$wps_status_change_time = $order->get_meta( 'wps_track_order_onchange_time', true );
			$wps_status_change_time_temp = $order->get_meta( 'wps_track_order_onchange_time_temp', true );
			$wps_status_change_time_template2 = $order->get_meta( 'wps_track_order_onchange_time_template', true );
		} else {
			$wps_status_change_time = get_post_meta( $order_id, 'wps_track_order_onchange_time', true );
			$wps_status_change_time_temp = get_post_meta( $order_id, 'wps_track_order_onchange_time_temp', true );
			$wps_status_change_time_template2 = get_post_meta( $order_id, 'wps_track_order_onchange_time_template', true );
		}

		$order_index = 'wc-' . $change_order_status;
		if ( is_array( $wps_status_change_time_temp ) && ! empty( $wps_status_change_time_temp ) ) {
			if ( is_array( $wps_status_change_time_temp ) ) {

				$wps_status_change_time[ $order_index ] = $wps_modified_date;
			}
		} else {
			$wps_status_change_time = array();
			if ( is_array( $wps_status_change_time ) ) {

				$wps_status_change_time[ $order_index ] = $wps_modified_date;
			}
		}
		if ( is_array( $wps_status_change_time_temp ) && ! empty( $wps_status_change_time_temp ) ) {

			$wps_status_change_time_temp[ $order_index ] = $wps_modified_date;
		} else {
			$wps_status_change_time_temp = array();
			if ( is_array( $wps_status_change_time_temp ) ) {

				$wps_status_change_time_temp[ $order_index ] = $wps_modified_date;
			}
		}
		if ( is_array( $wps_status_change_time_template2 ) && ! empty( $wps_status_change_time_template2 ) ) {

			$wps_status_change_time_template2[][ $order_index ] = $wps_modified_date;
		} else {
			$wps_status_change_time_template2 = array();
			$wps_status_change_time_template2[][ $order_index ] = $wps_modified_date;
		}
		$statuses = wc_get_order_statuses();

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// HPOS usage is enabled.
			$wps_track_order_status = $order->get_meta( 'wps_track_order_status', true );
		} else {
			$wps_track_order_status = get_post_meta( $order_id, 'wps_track_order_status', true );
		}

		if ( is_array( $wps_track_order_status ) && ! empty( $wps_track_order_status ) ) {
			$c = count( $wps_track_order_status );
			if ( $wps_track_order_status[ $c - 1 ] === $old_status ) {

				if ( in_array( $new_status, $wps_track_order_status ) ) {

					$key = array_search( $new_status, $wps_track_order_status );
					unset( $wps_track_order_status[ $key ] );
					$wps_track_order_status = array_values( $wps_track_order_status );
				}

				$wps_track_order_status[] = $new_status;

				if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
					// HPOS usage is enabled.
					$order->update_meta_data( 'wps_track_order_status', $wps_track_order_status );
					$order->update_meta_data( 'wps_track_order_onchange_time', $wps_status_change_time );
					$order->update_meta_data( 'wps_track_order_onchange_time_temp', $wps_status_change_time_temp );
					$order->update_meta_data( 'wps_track_order_onchange_time_template', $wps_status_change_time_template2 );
					$order->save();

				} else {
					update_post_meta( $order_id, 'wps_track_order_status', $wps_track_order_status );
					update_post_meta( $order_id, 'wps_track_order_onchange_time', $wps_status_change_time );
					update_post_meta( $order_id, 'wps_track_order_onchange_time_temp', $wps_status_change_time_temp );
					update_post_meta( $order_id, 'wps_track_order_onchange_time_template', $wps_status_change_time_template2 );
				}
			} else {

				$wps_track_order_status[] = $old_status;
				$wps_track_order_status[] = $new_status;

				if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
					// HPOS usage is enabled.
					$order->update_meta_data( 'wps_track_order_status', $wps_track_order_status );
					$order->update_meta_data( 'wps_track_order_onchange_time', $wps_status_change_time );
					$order->update_meta_data( 'wps_track_order_onchange_time_temp', $wps_status_change_time_temp );
					$order->update_meta_data( 'wps_track_order_onchange_time_template', $wps_status_change_time_template2 );
					$order->save();

				} else {
					update_post_meta( $order_id, 'wps_track_order_status', $wps_track_order_status );
					update_post_meta( $order_id, 'wps_track_order_onchange_time', $wps_status_change_time );
					update_post_meta( $order_id, 'wps_track_order_onchange_time_temp', $wps_status_change_time_temp );
					update_post_meta( $order_id, 'wps_track_order_onchange_time_template', $wps_status_change_time_template2 );
				}
			}
		} else {

			$wps_status_change_time = array();
			$wps_status_change_time_temp = array();
			$wps_status_change_time_template2 = array();
			$wps_track_order_status = array();
			$wps_track_order_status[] = $old_status;
			$wps_track_order_status[] = $new_status;

			if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
				// HPOS usage is enabled.
				$order->update_meta_data( 'wps_track_order_status', $wps_track_order_status );
				$order->update_meta_data( 'wps_track_order_onchange_time', $wps_status_change_time );
				$order->update_meta_data( 'wps_track_order_onchange_time_temp', $wps_status_change_time_temp );
				$order->update_meta_data( 'wps_track_order_onchange_time_template', $wps_status_change_time_template2 );
				$order->save();

			} else {
				update_post_meta( $order_id, 'wps_track_order_status', $wps_track_order_status );
				update_post_meta( $order_id, 'wps_track_order_onchange_time', $wps_status_change_time );
				update_post_meta( $order_id, 'wps_track_order_onchange_time_temp', $wps_status_change_time_temp );
				update_post_meta( $order_id, 'wps_track_order_onchange_time_template', $wps_status_change_time_template2 );
			}
		}

		$plugin_path = 'track-orders-for-woocommerce-pro/track-orders-for-woocommerce-pro.php';
		$wps_pro_is_active = false;
		// Check if the plugin is active.
		if ( is_plugin_active( $plugin_path ) ) {
			$wps_pro_is_active = true;
		}

		if ( 'on' == $wps_tofw_email_notifier && 'wc-completed' != $new_status ) {
			if ( '3.0.0' > WC()->version ) {
				$order_id = $order->id;
				$headers = array();
				$headers[] = 'Content-Type: text/html; charset=UTF-8';
				if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
					// HPOS usage is enabled.
					$fname = $order->get_meta( '_billing_first_name', true );
					$lname = $order->get_meta( '_billing_last_name', true );
					$to = $order->get_meta( '_billing_email', true );
				} else {
					$fname = get_post_meta( $order_id, '_billing_first_name', true );
					$lname = get_post_meta( $order_id, '_billing_last_name', true );
					$to = get_post_meta( $order_id, '_billing_email', true );
				}

				$subject = __( 'Your Order Status for Order #', 'track-orders-for-woocommerce' ) . $order_id;
				$message = __( 'Your Order Status is ', 'track-orders-for-woocommerce' ) . $statuses[ $new_status ];
				$mail_header = __( 'Current Order Status is ', 'track-orders-for-woocommerce' ) . $statuses[ $new_status ];
				$mail_footer = '';

			} else {
				$headers = array();
				$headers[] = 'Content-Type: text/html; charset=UTF-8';
				$wps_all_data = $order->get_data();
				$billing_address = $wps_all_data['billing'];
				$shipping_address = $wps_all_data['shipping'];
				$to = $billing_address['email'];
				$subject = __( 'Your Order Status for Order #', 'track-orders-for-woocommerce' ) . $order_id;
				$message = __( 'Your Order Status is ', 'track-orders-for-woocommerce' ) . $statuses[ $new_status ];
				$mail_header = __( 'Current Order Status is ', 'track-orders-for-woocommerce' ) . $statuses[ $new_status ];
				$mail_footer = '';

			}
			if ( $wps_pro_is_active ) {
				$wps_mail_template = get_option( 'tofw_invoice_template' );
				if ( 'template_1' == $wps_mail_template ) {
					$message = '<html>
					<body>
					<style>
						body {
							box-shadow: 2px 2px 10px #ccc;
							color: #333;
							font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
							margin: 40px auto;
							max-width: 700px;
							padding: 30px;
							width: 100%;
							background-color: #f8f9fa;
						}
					
						h2 {
							font-size: 28px;
							margin-top: 0;
							color: #fff;
							padding: 20px;
							background-color: #343a40;
							text-align: center;
						}
					
						h4 {
							color: #343a40;
							font-size: 18px;
							margin-bottom: 10px;
						}
					
						.content {
							padding: 0 20px;
						}
					
						.Customer-detail ul li p {
							margin: 0;
						}
					
						.details .Shipping-detail,
						.details .Billing-detail {
							display: inline-block;
							width: 48%;
							vertical-align: top;
						}
					
						.details .Shipping-detail ul li,
						.details .Billing-detail ul li {
							list-style-type: none;
							margin: 0;
							padding: 5px 0;
						}
					
						table, td, th {
							border: 1px solid #ccc;
							padding: 10px;
							text-align: left;
						}
					
						table {
							border-collapse: collapse;
							width: 100%;
							margin-bottom: 20px;
						}
					
						.info {
							display: inline-block;
						}
					
						.bold {
							font-weight: bold;
						}
					
						.footer {
							margin-top: 30px;
							text-align: center;
							color: #6c757d;
							font-size: 12px;
						}
					
						dl.variation dd {
							font-size: 12px;
							margin: 0;
						}
					</style>
					
					<div style="padding: 20px; background-color:#343a40;color: #fff; font-size: 24px; font-weight: 300; text-align: center;" class="header">
						' . $mail_header . '
					</div>       
					
					<div class="content">
						<h4>Order #' . $order_id . '</h4>
						<table>
							<thead>
								<tr>
									<th>' . __( 'Product', 'track-orders-for-woocommerce' ) . '</th>
									<th>' . __( 'Quantity', 'track-orders-for-woocommerce' ) . '</th>
									<th>' . __( 'Price', 'track-orders-for-woocommerce' ) . '</th>
								</tr>
							</thead>
							<tbody>';

					$order = new WC_Order( $order_id );
					$total = 0;
					foreach ( $order->get_items() as $item_id => $item ) {
						$product = apply_filters( 'woocommerce_order_item_product', $item->get_product(), $item );
						$item_meta = new WC_Order_Item_Meta( $item, $product );
						$item_meta_html = $item_meta->display( true, true );
						$wps_billing_phone = OrderUtil::custom_orders_table_usage_is_enabled() ? $order->get_meta( '_billing_phone', true ) : get_post_meta( $order->id, '_billing_phone', true );

						$taxes = $item->get_taxes();

						// Loop through each tax class.
						foreach ( $taxes as $tax_class => $tax ) {
							// Add tax amount to total tax.
							$total_tax += array_sum( $tax );
						}

						$message .= '<tr>
							<td>' . $item['name'] . '<br><small>' . $item_meta_html . '</small></td>
							<td>' . $item['qty'] . '</td>
							<td>' . wc_price( $product->get_price() ) . '</td>
						</tr>';

						$total += $product->get_price() * $item['qty'];
					}

					foreach ( $order->get_order_item_totals() as $key => $total ) {

						$message .= '<tr>
						<th colspan = "2">' . esc_html( $total['label'] ) . '</th><td>' . wp_kses_post( $total['value'] ) . '</td></tr>';
					}

					$message .= '
					</tbody>
					</table>
					</div>';

					if ( 'on' == get_option( 'wps_tofw_qr_redirect' ) ) {
						$message .= '<div style="text-align: center; margin-top: 20px;">
							<img src="' . get_site_url() . '/' . str_replace( ABSPATH, '', $file ) . '" alt="QR" style="width: 200px; height: 200px;" />
						</div>';
					}

					$message .= '<div class="footer">
						&copy; ' . gmdate( 'Y' ) . ' ' . $site_name . ' Your Company. All rights reserved.
					</div>
					</body>
					</html>';

				} elseif ( 'template_2' == $wps_mail_template ) {
					// 2nd Email notification template.
					$message = '<html>
						<body>
						<style>
							body {
								font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
								background-color: #f4f4f4;
								margin: 0;
								padding: 0;
								width: 100%;
							}

							.container {
								max-width: 700px;
								margin: 40px auto;
								background-color: #fff;
								box-shadow: 0 4px 8px rgba(0,0,0,0.1);
								overflow: hidden;
								border-radius: 8px;
							}

							.header {
								background-color: #4a90e2;
								color: #fff;
								text-align: center;
								padding: 20px 0;
								font-size: 24px;
								font-weight: bold;
							}

							.content {
								padding: 20px;
							}

							.content h4 {
								color: #333;
								font-size: 20px;
								margin-bottom: 20px;
							}

							.order-details {
								background-color: #f9f9f9;
								padding: 20px;
								border-radius: 8px;
							}

							.order-details table {
								width: 100%;
								border-collapse: collapse;
								margin-bottom: 20px;
							}

							.order-details th, .order-details td {
								padding: 12px;
								text-align: left;
								border-bottom: 1px solid #ddd;
							}

							.order-details th {
								background-color: #e9ecef;
								font-weight: bold;
							}

							.order-details .total {
								font-size: 18px;
								font-weight: bold;
								text-align: right;
							}

							.qr-code {
								text-align: center;
								margin: 20px 0;
							}

							.qr-code img {
								width: 150px;
								height: 150px;
							}

							.footer {
								background-color: #4a90e2;
								color: #fff;
								text-align: center;
								padding: 10px 0;
								font-size: 12px;
							}
						</style>

						<div class="container">
							<div class="header">
								' . $mail_header . '
							</div>
							<div class="content">
								<h4>Order #' . $order_id . '</h4>
								<div class="order-details">
									<table>
										<thead>
											<tr>
												<th>' . __( 'Product', 'track-orders-for-woocommerce' ) . '</th>
												<th>' . __( 'Quantity', 'track-orders-for-woocommerce' ) . '</th>
												<th>' . __( 'Price', 'track-orders-for-woocommerce' ) . '</th>
											</tr>
										</thead>
										<tbody>';

						$order = new WC_Order( $order_id );
						$total = 0;
					foreach ( $order->get_items() as $item_id => $item ) {
						$product = apply_filters( 'woocommerce_order_item_product', $item->get_product(), $item );
						$item_meta = new WC_Order_Item_Meta( $item, $product );
						$item_meta_html = $item_meta->display( true, true );

						$taxes = $item->get_taxes();

						// Loop through each tax class.
						foreach ( $taxes as $tax_class => $tax ) {
							// Add tax amount to total tax.
							$total_tax += array_sum( $tax );
						}

						$message .= '<tr>
								<td>' . $item['name'] . '<br><small>' . $item_meta_html . '</small></td>
								<td>' . $item['qty'] . '</td>
								<td>' . wc_price( $product->get_price() ) . '</td>
							</tr>';

						$total += $product->get_price() * $item['qty'];
					}

					foreach ( $order->get_order_item_totals() as $key => $total ) {

						$message .= '<tr>
								<th colspan = "2">' . esc_html( $total['label'] ) . '</th><td>' . wp_kses_post( $total['value'] ) . '</td></tr>';
					}

						$message .= '
						</tbody>
						</table>
						</div>';

					if ( 'on' == get_option( 'wps_tofw_qr_redirect' ) ) {
						$message .= '<div class="qr-code">
								<img src="' . get_site_url() . '/' . str_replace( ABSPATH, '', $file ) . '" alt="QR" />
							</div>';
					}
						$site_name = get_bloginfo( 'name' );
						$message .= '<div class="footer">
							&copy; ' . gmdate( 'Y' ) . ' ' . $site_name . ' Your Company. All rights reserved.
						</div>
						</div>
						</body>
						</html>';

				} elseif ( 'template_3' == $wps_mail_template ) {
					// template 3.
					$message = '<html>
				<body>
				<style>
				body {
					font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
					background-color: #f4f4f4;
					margin: 0;
					padding: 0;
					width: 100%;
				}
				
				.container {
					max-width: 700px;
					margin: 40px auto;
					background-color: #fff;
					box-shadow: 0 4px 8px rgba(0,0,0,0.1);
					overflow: hidden;
					border-radius: 10px;
				}
				
				.header {
					background-color: #ff6f61; /* New color */
					color: #fff;
					text-align: center;
					padding: 30px 0;
					font-size: 26px;
					font-weight: bold;
					border-bottom: 5px solid #ff6347; /* New color */
				}
				
				.content {
					padding: 30px 40px;
				}
				
				.content h4 {
					color: #333;
					font-size: 22px;
					margin-bottom: 20px;
					border-bottom: 2px solid #eee;
					padding-bottom: 10px;
				}
				
				.order-details {
					background-color: #f9f9f9;
					padding: 20px;
					border-radius: 8px;
					margin-bottom: 20px;
					border: 1px solid #ddd;
				}
				
				.order-details table {
					width: 100%;
					border-collapse: collapse;
					margin-bottom: 20px;
				}
				
				.order-details th, .order-details td {
					padding: 15px;
					text-align: left;
					border-bottom: 1px solid #ddd;
				}
				
				.order-details th {
					background-color: #e9ecef; /* Existing color */
					font-weight: bold;
					text-transform: uppercase;
				}
				
				.order-details .total {
					font-size: 18px;
					font-weight: bold;
					text-align: right;
					color: #ff6f61; /* New color */
				}
				
				.qr-code {
					text-align: center;
					margin: 20px 0;
				}
				
				.qr-code img {
					width: 150px;
					height: 150px;
					border: 1px solid #ddd;
					border-radius: 10px;
				}
				
				.footer {
					background-color: #ff6f61; /* New color */
					color: #fff;
					text-align: center;
					padding: 15px 0;
					font-size: 12px;
					border-top: 5px solid #ff6347; /* New color */
				}
				
				.footer a {
					color: #fff;
					text-decoration: underline;
				}
				
				.footer a:hover {
					text-decoration: none;
					color: #ff4500; /* New color for hover */
				}
				
				/* New Elements with Different Colors */
				
				.header.alt {
					background-color: #50c878; /* New color */
					border-bottom: 5px solid #32cd32; /* New color */
				}
				
				.header.alt2 {
					background-color: #ffa500; /* New color */
					border-bottom: 5px solid #ff8c00; /* New color */
				}
				
				.header.alt3 {
					background-color: #d2691e; /* New color */
					border-bottom: 5px solid #8b4513; /* New color */
				}
				
				.order-details.alt {
					background-color: #d3f8e2; /* New color */
					border: 1px solid #adebad; /* New color */
				}
				
				.order-details.alt2 {
					background-color: #fff7e6; /* New color */
					border: 1px solid #ffcc99; /* New color */
				}
				
				.order-details.alt3 {
					background-color: #e6e6fa; /* New color */
					border: 1px solid #dcdcdc; /* New color */
				}
				
				.footer.alt {
					background-color: #50c878; /* New color */
					border-top: 5px solid #32cd32; /* New color */
				}
				
				.footer.alt2 {
					background-color: #ffa500; /* New color */
					border-top: 5px solid #ff8c00; /* New color */
				}
				
				.footer.alt3 {
					background-color: #d2691e; /* New color */
					border-top: 5px solid #8b4513; /* New color */
				}
				
				</style>

				<div class="container">
					<div class="header">
						' . $mail_header . '
					</div>
					<div class="content">
						<h4>Order #' . $order_id . '</h4>
						<div class="order-details">
							<table>
								<thead>
									<tr>
										<th>' . __( 'Product', 'track-orders-for-woocommerce' ) . '</th>
										<th>' . __( 'Quantity', 'track-orders-for-woocommerce' ) . '</th>
										<th>' . __( 'Price', 'track-orders-for-woocommerce' ) . '</th>
									</tr>
								</thead>
								<tbody>';

					$order = new WC_Order( $order_id );
					$total = 0;
					foreach ( $order->get_items() as $item_id => $item ) {
						$product = apply_filters( 'woocommerce_order_item_product', $item->get_product(), $item );
						$item_meta = new WC_Order_Item_Meta( $item, $product );
						$item_meta_html = $item_meta->display( true, true );

						$taxes = $item->get_taxes();

							// Loop through each tax class.
						foreach ( $taxes as $tax_class => $tax ) {
							// Add tax amount to total tax.
							$total_tax += array_sum( $tax );
						}

							$message .= '<tr>
								<td>' . $item['name'] . '<br><small>' . $item_meta_html . '</small></td>
								<td>' . $item['qty'] . '</td>
								<td>' . wc_price( $product->get_price() ) . '</td>
							</tr>';

							$total += $product->get_price() * $item['qty'];
					}

					foreach ( $order->get_order_item_totals() as $key => $total ) {

						$message .= '<tr>
								<th colspan = "2">' . esc_html( $total['label'] ) . '</th><td>' . wp_kses_post( $total['value'] ) . '</td></tr>';
					}

					$message .= '
				</tbody>
				</table>
				</div>';

					if ( 'on' == get_option( 'wps_tofw_qr_redirect' ) ) {
						$message .= '<div class="qr-code">
						<img src="' . get_site_url() . '/' . str_replace( ABSPATH, '', $file ) . '" alt="QR" />
					</div>';
					}
					$site_name = get_bloginfo( 'name' );
					$message .= '<div class="footer">
					&copy; ' . gmdate( 'Y' ) . ' ' . $site_name . ' All rights reserved. <a href="#">Privacy Policy</a> | <a href="#">Terms of Service</a>
				</div>
				</div>
				</body>
				</html>';
				} elseif ( 'template_4' == $wps_mail_template ) {

					$message = '<html>
					<body>
					<style>
						body {
							background-color: #eef2f6;
							font-family: "Arial", sans-serif;
							margin: 0;
							padding: 0;
							color: #333333;
						}
						.container {
							max-width: 600px;
							margin: 40px auto;
							padding: 20px;
							background: #ffffff;
							border-radius: 10px;
							box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
						}
						.header {
							background: linear-gradient(45deg, #4e73df, #1cc88a);
							color: #ffffff;
							text-align: center;
							padding: 20px;
							border-radius: 10px 10px 0 0;
							font-size: 22px;
							font-weight: bold;
							letter-spacing: 1px;
						}
						.content {
							padding: 20px;
						}
						.content h4 {
							color: #4e73df;
							font-size: 20px;
							font-weight: bold;
							margin-bottom: 15px;
							text-align: center;
						}
						.product-card {
							display: flex;
							justify-content: space-between;
							align-items: center;
							padding: 15px;
							margin-bottom: 10px;
							background-color: #f8f9fc;
							border: 1px solid #d1d3e2;
							border-radius: 8px;
						}
						.product-card img {
							max-width: 60px;
							border-radius: 5px;
						}
						.product-card .details {
							flex-grow: 1;
							margin-left: 15px;
						}
						.product-card .details p {
							margin: 0;
							font-size: 14px;
							color: #5a5c69;
						}
						.product-card .price {
							font-size: 16px;
							font-weight: bold;
							color: #1cc88a;
						}
						.summary {
							background-color: #f8f9fc;
							padding: 15px;
							border: 1px solid #d1d3e2;
							border-radius: 8px;
							margin-top: 20px;
						}
						.summary .row {
							display: flex;
							justify-content: space-between;
							margin-bottom: 10px;
						}
						.summary .row:last-child {
							margin-bottom: 0;
						}
						.summary .row .label {
							color: #5a5c69;
							font-size: 14px;
						}
						.summary .row .value {
							font-size: 14px;
							font-weight: bold;
						}
						.qr-code {
							text-align: center;
							margin-top: 20px;
						}
						.qr-code img {
							width: 120px;
							height: 120px;
						}
						.footer {
							text-align: center;
							padding: 20px;
							color: #858796;
							font-size: 12px;
							border-top: 1px solid #e3e6f0;
							margin-top: 20px;
						}
					</style>
					
					<div class="container">
						<div class="header">
							' . $mail_header . '
						</div>
						
						<div class="content">
							<h4>Order Confirmation: #' . $order_id . '</h4>';

					foreach ( $order->get_items() as $item_id => $item ) {
						$product = $item->get_product();
						$item_meta_html = wc_display_item_meta( $item );
						$product_image = wp_get_attachment_image_src( $product->get_image_id(), 'thumbnail' )[0];

						$message .= '<div class="product-card">
									<img src="' . esc_url( $product_image ) . '" alt="' . esc_attr( $item->get_name() ) . '">
									<div class="details">
										<p><strong>' . esc_html( $item->get_name() ) . '</strong></p>
										<p>' . $item_meta_html . '</p>
									</div>
									<div class="price">  ' . wc_price( $item->get_total() ) . '</div>
								</div>';
					}

					$message .= '<div class="summary">
								<div class="row">
									<span class="label">Subtotal:</span>
									<span class="value"> ' . wc_price( $order->get_subtotal() ) . '</span>
								</div>
								<div class="row">
									<span class="label">Tax:</span>
									<span class="value"> ' . wc_price( $order->get_total_tax() ) . '</span>
								</div>
								<div class="row">
									<span class="label">Total:</span>
									<span class="value"> ' . wc_price( $order->get_total() ) . '</span>
								</div>
							</div>';

					if ( 'on' === get_option( 'wps_tofw_qr_redirect' ) ) {
						$message .= '<div class="qr-code">
									<img src="' . esc_url( $file_url ) . '" alt="QR Code">
								</div>';
					}
					$site_name = get_bloginfo( 'name' );
					$message .= '</div>
						<div class="footer">
							&copy; ' . gmdate( 'Y' ) . ' ' . esc_html( $site_name ) . '. All rights reserved.
						</div>
					</div>
					</body>
				</html>';

				}
			}

			if ( ! $wps_pro_is_active ) {
				$message = '<html>
		<body>
			<style>
				body {
					box-shadow: 2px 2px 10px #ccc;
					color: #767676;
					font-family: Arial,sans-serif;
					margin: 80px auto;
					max-width: 700px;
					padding-bottom: 30px;
					width: 100%;
				}

				h2 {
					font-size: 30px;
					margin-top: 0;
					color: #fff;
					padding: 40px;
					background-color: #557da1;
				}

				h4 {
					color: #557da1;
					font-size: 20px;
					margin-bottom: 10px;
				}

				.content {
					padding: 0 40px;
				}

				.Customer-detail ul li p {
					margin: 0;
				}

				.details .Shipping-detail {
					width: 40%;
					float: right;
				}

				.details .Billing-detail {
					width: 60%;
					float: left;
				}

				.details .Shipping-detail ul li,.details .Billing-detail ul li {
					list-style-type: none;
					margin: 0;
				}

				.details .Billing-detail ul,.details .Shipping-detail ul {
					margin: 0;
					padding: 0;
				}

				.clear {
					clear: both;
				}

				table,td,th {
					border: 2px solid #ccc;
					padding: 15px;
					text-align: left;
				}

				table {
					border-collapse: collapse;
					width: 100%;
				}

				.info {
					display: inline-block;
				}

				.bold {
					font-weight: bold;
				}

				.footer {
					margin-top: 30px;
					text-align: center;
					color: #99B1D8;
					font-size: 12px;
				}
				dl.variation dd {
					font-size: 12px;
					margin: 0;
				}
			</style>

			<div style="padding: 36px 48px; background-color:#557DA1;color: #fff; font-size: 30px; font-weight: 300; font-family:helvetica;" class="header">
				' . $mail_header . '
			</div>		

			<div class="content">

				<div class="Order">
					<h4>Order #' . $order_id . '</h4>
					<table>
						<tbody>
							<tr>
								<th>' . __( 'Product', 'track-orders-for-woocommerce' ) . '</th>
								<th>' . __( 'Quantity', 'track-orders-for-woocommerce' ) . '</th>
								<th>' . __( 'Price', 'track-orders-for-woocommerce' ) . '</th>
							</tr>';

							$order = new WC_Order( $order_id );
							$total = 0;
				foreach ( $order->get_items() as $item_id => $item ) {
					/**
					 * Woocommerce order items.
					 *
					 * @since 1.0.0
					 */
					$product = apply_filters( 'woocommerce_order_item_product', $item->get_product(), $item );
					$item_meta      = new WC_Order_Item_Meta( $item, $product );
					$item_meta_html = $item_meta->display( true, true );
					$wps_billing_phone = OrderUtil::custom_orders_table_usage_is_enabled() ? $order->get_meta( '_billing_phone', true ) : get_post_meta( $order->id, '_billing_phone', true );

					$taxes = $item->get_taxes();

					// Loop through each tax class.
					foreach ( $taxes as $tax_class => $tax ) {
						// Add tax amount to total tax.
						$total_tax += array_sum( $tax );
					}

					$message .= '<tr>
								<td>' . $item['name'] . '<br>';
					$message .= '<small>' . $item_meta_html . '</small>
									<td>' . $item['qty'] . '</td>
									<td>' . wc_price( $product->get_price() ) . '</td>
								</tr>';
					$total = $total + ( $product->get_price() * $item['qty'] );
				}

				foreach ( $order->get_order_item_totals() as $key => $total ) {

					$message .= '<tr>
						<th colspan = "2">' . esc_html( $total['label'] ) . '</th><td>' . wp_kses_post( $total['value'] ) . '</td></tr>';
				}

					$message .= '</tbody>
				</table>
			</div>';
				if ( 'on' == get_option( 'wps_tofw_qr_redirect' ) ) {
					$message .= '<div><img src="' . get_site_url() . '/' . str_replace( ABSPATH, '', $file ) . '" alt= "QR" style="display: block; margin: 0 auto; width: 300px; height: 300px;" /></div>';
				}
				$message .= '</body>
		</html>';

			}
				wc_mail( $to, $subject, $message, $headers );
		}
	}


	/**
	 * Function for ajax callback.
	 *
	 * @return void
	 */
	public function wps_tofw_export_my_orders_callback() {
		$_orders = wc_get_orders(
			array(
				'status'      => array_keys( wc_get_order_statuses() ),
				'customer'    => get_current_user_id(),
				'numberposts' => -1,
				'return'      => 'ids', // Specify 'ids' to get only the order IDs.
			)
		);

		if ( ! empty( $_orders ) ) {
			$order_details = $this->wps_tofw_get_csv_order_details( $_orders );
			$main_arr = array(
				'status' => 'success',
				'file_name' => 'wps_order_details',
				'order_data' => $order_details,
			);
		} else {

			$main_arr = array(
				'status' => 'failed',
			);
		}
		echo wp_json_encode( $main_arr );
		wp_die();
	}


		/**
		 * Function to return order details.
		 *
		 * @param array $_orders contains array of order ids.
		 * @return array
		 */
	public function wps_tofw_get_csv_order_details( $_orders ) {
		$order_details = array();
		$order_details[] = array(
			__( 'Order Id', 'track-orders-for-woocommerce' ),
			__( 'Order Status', 'track-orders-for-woocommerce' ),
			__( 'Order Total', 'track-orders-for-woocommerce' ),
			__( 'Order Items', 'track-orders-for-woocommerce' ),
			__( 'Payment Method', 'track-orders-for-woocommerce' ),
			__( 'Billing Name', 'track-orders-for-woocommerce' ),
			__( 'Billing Email', 'track-orders-for-woocommerce' ),
			__( 'Billing Address', 'track-orders-for-woocommerce' ),
			__( 'Billing Contact', 'track-orders-for-woocommerce' ),
			__( 'Order date', 'track-orders-for-woocommerce' ),

		);

		foreach ( $_orders as $index => $_order_id ) {
			$order = wc_get_order( $_order_id );
			$order_total = $order->get_total();
			$payment_method = $order->get_payment_method_title();
			$billing_name = $order->get_billing_first_name();
			$billing_name .= ' ';
			$billing_name .= $order->get_billing_last_name();
			$billing_email  = $order->get_billing_email();
			$billing_address = $order->get_billing_company();
			$billing_address .= ' ';
			$billing_address .= $order->get_billing_address_1();
			$billing_address .= ' ';
			$billing_address .= $order->get_billing_address_2();
			$billing_address .= ' ';
			$billing_address .= $order->get_billing_city();
			$billing_address .= ' ';
			$billing_address .= $order->get_billing_state();
			$billing_address .= ' ';
			$billing_address .= $order->get_billing_country();
			$billing_address .= ' ';
			$billing_address .= $order->get_billing_postcode();

			$billing_contact = $order->get_billing_phone();
			$order_date = $order->get_date_created()->date( 'F d Y H:i ' );
			$order_items = '';
			$_order_status = $order->get_status();
			foreach ( $order->get_items() as $item_id => $item ) {
				$order_items .= $item->get_name() . ' ';
			}
			$order_details[] = array(
				$_order_id,
				$_order_status,
				$order_total,
				$order_items,
				$payment_method,
				$billing_name,
				$billing_email,
				$billing_address,
				$billing_contact,
				$order_date,
			);
		}
		return $order_details;
	}


	/**
	 * Function for ajax callback for guest user export
	 *
	 * @return void
	 */
	public function wps_tofw_export_my_orders_guest_user_callback() {
		check_ajax_referer( 'tofw_common_param_nonce', 'nonce' );
		$email = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
		$_orders = array();
		if ( ! empty( $email ) ) {
			$_orders_temp = wc_get_orders(
				array(
					'status'      => array_keys( wc_get_order_statuses() ),
					'numberposts' => -1,
					'return'      => 'ids', // Specify 'ids' to get only the order IDs.
				)
			);
			$wps_check = 'failed';
			if ( ! empty( $_orders_temp ) && is_array( $_orders_temp ) ) {
				foreach ( $_orders_temp as $key => $id ) {

					$_order = new WC_Order( $id );
					if ( $_order->get_billing_email() == $email ) {
						$_orders[] = $id;
						$main_arr = array(
							'status' => 'successs',
							'file_name' => 'wps_order_details',
						);
						$wps_check = 'success';
					} else {
						$main_arr = array(
							'status' => 'failed',
						);
					}
				}
				$order_details = $this->wps_tofw_get_csv_order_details( $_orders );
				$main_arr = array(
					'status' => $wps_check,
					'order_data' => $order_details,
				);
			}
		} else {
			$main_arr = array(
				'status' => 'failed',
			);
		}

		echo wp_json_encode( $main_arr );
		wp_die();

	}

	/**
	 * Function to register custom statuses.
	 *
	 * @return void
	 */
	public function wps_tofw_register_custom_order_status() {

		$wps_tofw_enable_track_order_feature = get_option( 'tofw_enable_track_order', 'no' );
		$wps_tofw_enable_custom_order_feature = get_option( 'tofw_enable_use_custom_status', 'no' );
		if ( 'on' !== $wps_tofw_enable_track_order_feature || 'on' !== $wps_tofw_enable_custom_order_feature ) {
			return;
		}

		register_post_status(
			'wc-packed',
			array(
				'label'                     => __( 'Order Packed', 'track-orders-for-woocommerce' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count */
				'label_count'               => false,
			)
		);

		register_post_status(
			'wc-dispatched',
			array(
				'label'                     => __( 'Order Dispatched', 'track-orders-for-woocommerce' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count */
				'label_count' => _n_noop(
					'Order Dispatched <span class="count">(%s)</span>',
					'Order Dispatched <span class="count">(%s)</span>',
					'track-orders-for-woocommerce'
				),
			)
		);

		register_post_status(
			'wc-shipped',
			array(
				'label'                     => __( 'Order Shipped', 'track-orders-for-woocommerce' ),
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count */
				'label_count'               => false,
			)
		);

		$custom_statuses = get_option( 'wps_tofw_new_custom_order_status', array() );

		if ( is_array( $custom_statuses ) && ! empty( $custom_statuses ) ) {
			foreach ( $custom_statuses as $key => $value ) {
				foreach ( $value as $custom_status_key => $custom_status_value ) {
					register_post_status(
						'wc-' . $custom_status_key,
						array(
							'label'                     => $custom_status_value,
							'public'                    => true,
							'exclude_from_search'       => false,
							'show_in_admin_all_list'    => true,
							'show_in_admin_status_list' => true,
							/* translators: %s: count */
							'label_count'               => false,
						)
					);
				}
			}
		}
	}

	/**
	 * Function to add custom statuses.
	 *
	 * @param array $order_statuses is an array.
	 * @return array
	 */
	public function wps_tofw_add_custom_order_status( $order_statuses ) {
		$wps_tofw_enable_track_order_feature = get_option( 'tofw_enable_track_order', 'no' );
		$wps_tofw_enable_custom_order_feature = get_option( 'tofw_enable_use_custom_status', 'no' );
		if ( 'on' != $wps_tofw_enable_track_order_feature || 'on' != $wps_tofw_enable_custom_order_feature ) {
			return $order_statuses;
		}
			$custom_order = get_option( 'wps_tofw_new_custom_order_status', array() );
			$statuses = get_option( 'tofw_selected_custom_order_status', array() );
			$wps_tofw_statuses = get_option( 'wps_tofw_new_settings_custom_statuses_for_order_tracking', array() );

			// Ensure it's an array.
		if ( ! is_array( $custom_order ) ) {
			$custom_order = array();
		}
			$custom_order[] = array( 'dispatched' => __( 'Order Dispatched', 'track-orders-for-woocommerce' ) );
			$custom_order[] = array( 'shipped' => __( 'Order Shipped', 'track-orders-for-woocommerce' ) );
			$custom_order[] = array( 'packed' => __( 'Order Packed', 'track-orders-for-woocommerce' ) );

		if ( is_array( $custom_order ) && ! empty( $custom_order ) && ! empty( $statuses ) && is_array( $statuses ) ) {
			foreach ( $custom_order as $key1 => $value1 ) {
				foreach ( $value1 as $custom_key => $custom_value ) {
					if ( in_array( 'wc-' . $custom_key, $statuses ) ) {
						$order_statuses[ 'wc-' . $custom_key ] = $custom_value;
					}
				}
			}
		}

			return $order_statuses;
	}

}
