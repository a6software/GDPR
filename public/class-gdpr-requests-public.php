<?php

/**
 * The public facing requests functionality of the plugin.
 *
 * @link       https://trewknowledge.com
 * @since      1.0.0
 *
 * @package    GDPR
 * @subpackage GDPR/admin
 */

/**
 * The public facing requests functionality of the plugin.
 *
 * @package    GDPR
 * @subpackage GDPR/admin
 * @author     Fernando Claussen <fernandoclaussen@gmail.com>
 */
class GDPR_Requests_Public extends GDPR_Requests {

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		parent::__construct( $plugin_name, $version );
	}


	function delete_user( $user, $index ) {
		if ( ! $user instanceof WP_User ) {
			return false;
		}

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/user.php' );
		}

		if ( parent::remove_from_requests( $index ) ) {
			GDPR_Email::send( $user->user_email, 'deleted', array( 'token' => 123456 ) );
			wp_delete_user( $user->ID );
			wp_logout();
		}
	}

	static function request_form( $type ) {
		if ( ! in_array( $type, parent::$allowed_types ) ) {
			return;
		}

		include plugin_dir_path( dirname( __FILE__ ) ) . 'public/partials/' . $type . '-form.php';
	}

	function send_request_email() {
		if ( ! isset( $_POST['type'] ) || ! in_array( $_POST['type'], parent::$allowed_types ) ) {
				wp_die( esc_html__( 'Invalid type of request. Please try again.', 'gdpr' ) );
		}

		if ( ! isset( $_POST['gdpr_request_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['gdpr_request_nonce'] ), 'add-to-requests' ) ) {
			wp_die( esc_html__( 'We could not verify the security token. Please try again.', 'gdpr' ) );
		}

		$type = sanitize_text_field( wp_unslash( $_POST['type'] ) );
		$data = isset( $_POST['data'] ) ? sanitize_textarea_field( $_POST['data'] ) : '';

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
		} else {
			$user = isset( $_POST['user_email'] ) ? get_user_by( 'email', sanitize_email( $_POST['user_email'] ) ) : null;
		}

		switch ( $type ) {
			case 'delete':
				if ( ! $user instanceof WP_User ) {
					wp_safe_redirect(
						esc_url_raw(
							add_query_arg(
								array(
									'notify' => 1,
									'user-not-found' => 1,
								),
								wp_get_referer()
							)
						)
					);
					exit;
				}

				if ( in_array( 'administrator', $user->roles ) ) {
					$admins_query = new WP_User_Query( array(
							'role' => 'Administrator'
					)	);
					if ( 1 === $admins_query->get_total() ) {
						wp_safe_redirect(
						esc_url_raw(
							add_query_arg(
								array(
									'notify' => 1,
									'cannot-delete' => 1,
								),
								wp_get_referer()
							)
						)
					);
					exit;
					}
				}
				break;

			case 'rectify':
			case 'complaint':
				if ( ! $data ) {
					wp_safe_redirect(
						esc_url_raw(
							add_query_arg(
								array(
									'notify' => 1,
									'required-information-missing' => 1,
								),
								wp_get_referer()
							)
						)
					);
				}
				break;
		}

		$key = parent::add_to_requests( $user->user_email, $type, $data );
		if ( GDPR_Email::send( $user, "{$type}-request", array( 'user' => $user, 'key' => $key, 'data' => $data ) ) ) {
			wp_safe_redirect(
				esc_url_raw(
					add_query_arg(
						array(
							'notify' => 1,
							'email-sent' => 1,
						),
						wp_get_referer()
					)
				)
			);
			exit;
		} else {
			wp_safe_redirect(
				esc_url_raw(
					add_query_arg(
						array(
							'notify' => 1,
							'error' => 1,
						),
						wp_get_referer()
					)
				)
			);
			exit;
		}
	}

	function request_confirmed() {
		if ( ! is_front_page() || ! isset( $_GET['type'], $_GET['key'], $_GET['email'] ) ) {
			return;
		}

		$type = sanitize_text_field( wp_unslash( $_GET['type'] ) );
		$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		$email = sanitize_email( $_GET['email'] );


		$user = get_user_by( 'email', $email );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$meta_key = get_user_meta( $user->ID, self::$plugin_name . "_{$type}_key", true );
		if ( empty( $meta_key ) ) {
			return;
		}

		if ( $key === $meta_key ) {
			switch ( $type ) {
				case 'delete':
					$found_posts = parent::user_has_content( $user );
					if ( $found_posts ) {
						parent::confirm_request( $key );
						wp_safe_redirect(
							esc_url_raw(
								add_query_arg(
									array(
										'user-deleted' => 0,
										'notify' => 1
									),
									home_url()
								)
							)
						);
						exit;
					} else {
						if ( $this->delete_user( $user, $key ) ) {
							wp_safe_redirect(
								esc_url_raw(
									add_query_arg(
										array(
											'user-deleted' => 1,
											'notify' => 1
										),
										home_url()
									)
								)
							);
							exit;
						}
					}
					break;
				case 'rectify':
				case 'complaint':
					parent::confirm_request( $key );
					wp_safe_redirect(
						esc_url_raw(
							add_query_arg(
								array(
									'request-confirmed' => 1,
									'notify' => 1
								),
								home_url()
							)
						)
					);
					exit;
					break;
			}
		}
	}
}
