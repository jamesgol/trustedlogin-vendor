<?php

namespace TrustedLogin\Vendor;

use \WP_REST_Request;
use \WP_REST_Response;
use \WP_Error;

/**
 * Class: TrustedLogin API Handler
 *
 * @version 0.1.0
 */
class Endpoint {

	use Debug_Logging;
	use Licensing;

	/**
	 * @var String - the endpoint used to redirect Support Agents to Client WP admin panels
	 * @since 0.3.0
	 */
	const REDIRECT_ENDPOINT = 'trustedlogin';

	/**
	 * @var string
	 * @since 0.7.0
	 */
	const REST_ENDPOINT = 'trustedlogin/v1';

	const HEALTH_CHECK_SUCCESS_STATUS = 204;

	const HEALTH_CHECK_ERROR_STATUS = 424;

	const PUBLIC_KEY_SUCCESS_STATUS = 200;

	const PUBLIC_KEY_ERROR_STATUS = 501;

	const REDIRECT_SUCCESS_STATUS = 302;

	const REDIRECT_ERROR_STATUS = 303;

	/**
	 * @var Settings
	 * @since 0.9.0
	 */
	private $settings;

	/**
	 * @var TrustedLogin_Audit_Log
	 * @since 0.9.0
	 */
	private $audit_log;

	/**
	 * Endpoint constructor.
	 */
	public function __construct( Settings $settings_instance ) {

		$this->settings = $settings_instance;

		$this->audit_log = new TrustedLogin_Audit_Log( $this->settings );

		add_action( 'template_redirect', array( $this, 'maybe_handle_redirect' ), 99 );
		add_action( 'rest_api_init', array( $this, 'register_endpoints' ) );
	}

	/**
	 * @return TrustedLogin_Audit_Log
	 */
	public function get_audit_log() {
		return $this->audit_log;
	}

	public function register_endpoints() {

		register_rest_route( self::REST_ENDPOINT, '/healthcheck', array(
			'methods'  => \WP_REST_Server::READABLE,
			'callback' => array( $this, 'healthcheck_callback' ),
		) );

		register_rest_route( self::REST_ENDPOINT, '/public_key', array(
			'methods'  => \WP_REST_Server::READABLE,
			'callback' => array( $this, 'public_key_callback' ),
		) );

		register_rest_route( self::REST_ENDPOINT, '/signature_key', array(
			'methods'  => \WP_REST_Server::READABLE,
			'callback' => array( $this, 'sign_public_key_callback' ),
		) );

	}

	/**
	 * Returns the Public Key for this specific vendor/plugin.
	 *
	 * @since 0.8.0
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	public function public_key_callback( \WP_REST_Request $request ) {

		$trustedlogin_encryption = new Encryption();
		$public_key              = $trustedlogin_encryption->get_public_key();

		$response = new \WP_REST_Response();

		if ( ! is_wp_error( $public_key ) ) {
			$data = array(
				'publicKey' => $public_key,
			);
			$response->set_data( $data );
			$response->set_status( self::PUBLIC_KEY_SUCCESS_STATUS );
		} else {
			$response->set_status( self::PUBLIC_KEY_ERROR_STATUS );
		}

		return $response;
	}

	/**
	 * Returns the results of our healthcheck
	 *
	 * @since 0.8.0
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	public function healthcheck_callback( \WP_REST_Request $request ) {

		$response    = new \WP_REST_Response();
		$healthcheck = new HealthCheck();
		$checks      = $healthcheck->run_all_checks();

		if ( ! is_wp_error( $checks ) ) {
			$response->set_status( self::HEALTH_CHECK_SUCCESS_STATUS );
		} else {
			$response->set_status( self::HEALTH_CHECK_ERROR_STATUS );
		}

		return $response;

	}


	/**
	 * Returns the Signature Public Key for this specific vendor/plugin.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_REST_Response
	 */
	public function sign_public_key_callback( \WP_REST_Request $request ) {

		$trustedlogin_encryption = new Encryption();
		$sign_public_key         = $trustedlogin_encryption->get_public_key( 'sign_public_key' );

		$response = new \WP_REST_Response();

		if ( ! is_wp_error( $sign_public_key ) ) {
			$data = array( 'signatureKey' => $sign_public_key );
			$response->set_data( $data );
			$response->set_status( self::PUBLIC_KEY_SUCCESS_STATUS );
		} else {
			$response->set_status( self::PUBLIC_KEY_ERROR_STATUS );
		}

		return $response;

	}

	/**
	 * Helper: Determines if eCommerce platform is acceptable
	 *
	 * @since 0.8.0
	 *
	 * @param string $param - The parameter value being validated
	 * @param \WP_REST_Request $request
	 * @param int $key
	 *
	 * @return bool
	 */
	public function validate_callback( $param, $request = null, $key = null ) {

		$types = apply_filters( 'trustedlogin/vendor/endpoint/ecommerce-types', array( 'EDD', 'WooCommerce' ) );

		return in_array( $param, $types, true );
	}


	/**
	 * Hooked Action: Checks if the specified attributes are set has a valid access_key before checking if we can redirect support agent.
	 *
	 * @since 1.0.0
	 */
	public function maybe_handle_redirect() {

		if ( ! isset( $_REQUEST[ self::REDIRECT_ENDPOINT ] ) ) {
			return;
		}

		if ( 1 !== intval( $_REQUEST[ self::REDIRECT_ENDPOINT ] ) ) {
			$this->dlog(
				'Incorrect parameter for trustedlogin provided: ' . sanitize_text_field( $_REQUEST[ self::REDIRECT_ENDPOINT ] ),
				__METHOD__ );

			return;
		}

		$required_args = array(
			'action',
			'provider',
			'ak'
		);

		foreach ( $required_args as $required_arg ) {
			if ( ! isset( $_REQUEST[ $required_arg ] ) ) {
				$this->dlog( 'Required arg ' . $required_arg . ' missing.', __METHOD__ );
				return;
			}
		}


		if ( isset( $_REQUEST[ 'provider' ] ) ) {
			$active_helpdesk = $this->settings->get_setting( 'helpdesk' );

			if( $active_helpdesk !== $_REQUEST['provider'] ) {
				$this->dlog( 'Active helpdesk doesn\'t match passed provider. Helpdesk: ' . esc_attr( $active_helpdesk ) . ', Provider: ' . esc_attr( $_REQUEST['provider'] ), __METHOD__ );

				return;
			}
		}


		switch ( $_REQUEST['action'] ) {
			case 'accesskey_login':

				if ( ! isset( $_REQUEST['ak'] ) ){
					$this->dlog( 'Required arg ak missing.', __METHOD__ );
					return;
				}

				$access_key = sanitize_text_field( $_REQUEST['ak'] );
				$secret_ids = $this->api_get_secret_ids( $access_key );

				if ( is_wp_error( $secret_ids ) ){
					$this->dlog( 
						'Could not get secret ids. ' .$secret_ids->get_error_message(),
						__METHOD__ 
					);
					return;
				}

				if ( empty( $secret_ids ) ){
					$this->dlog( 
						sprintf( 'No secret ids returned for access_key (%s).', $access_key ),
						__METHOD__ 
						);
					return;
				}

				if ( 1  == count( $secret_ids ) ){

					$this->maybe_redirect_support( $secret_ids[0] );
					
				}
				
				$this->handle_multiple_secret_ids( $secret_ids );

				break;

			case 'support_redirect':

				if ( ! isset( $_REQUEST['ak'] ) ){
					$this->dlog( 'Required arg ak missing.', __METHOD__ );
					return;
				}

				$secret_id = sanitize_text_field( $_REQUEST['ak'] );
				
				$this->maybe_redirect_support( $secret_id );

				break;
			default:

		}

		return;
	}

	/**
	 * Helper: Handles the case where a single accessKey returns more than 1 secretId.
	 * 
	 * @param  array $secret_ids [
	 *   @type string $siteurl  The url of the site the secretId is for.
	 *   @type string $loginurl The vendor-side redirect link to login via secretId.
 	 * ]
 	 * 
	 * @return void.
	 */
	private function handle_multiple_secret_ids( $secret_ids ){

		if ( !is_array( $secret_ids ) || empty( $secret_ids ) ){
			return;
		}

		$urls_output  = '';
		$url_template = '<li><a href="%1$s" class="%2$s">%3$s</a></li>';

		foreach ( $secret_ids as $secret_id ){

			$envelope = $this->api_get_envelope( $secret_id );
			
			if ( is_wp_error( $envelope ) ) {
				$this->dlog( 'Error: ' . $envelope->get_error_message(), __METHOD__ );
				continue;
			}

			$url_parts = ( $envelope ) ? $this->envelope_to_url( $envelope, true ) : false;

			if ( is_wp_error( $url_parts ) ) {
				$this->dlog( 'Error: ' . $url_parts->get_error_message(), __METHOD__ );
				continue;
			}

			if ( $url_parts ) {
				$urls_output .= sprintf( 
					$url_template, 
					/* %1$s */ esc_url( $url_parts['loginurl'] ), 
					/* %2$s */ esc_attr( 'trustedlogin-authlink' ), 
					/* %3$s */ sprintf( 
						__( 'Login to %s', 'trustedlogin-vendor' ), 
						/* %s */ esc_html( $url_parts['siteurl'] )
						)
				);
			}

		}

		if ( empty( $urls_output ) ){
			return;
		}

		add_action( 'admin_notices', function () use ( $urls_output ) {
			echo '<div class="notice notice-warning"><h3>' . esc_html__( 'Please pick a site to log into.', 'trustedlogin-vendor' ) . '</h3><ul>' . $urls_output . '</ul></div>';
		} );

	}


	/**
	 * Helper: If all checks pass, redirect support agent to client site's admin panel
	 *
	 * @since 0.4.0
	 * @since 0.8.0 Added `Encryption->decrypt()` to decrypt envelope from Vault.
	 *
	 * @see endpoint_maybe_redirect()
	 *
	 * @param string $secret_id collected via endpoint
	 * @param array|WP_Error Envelope, if already fetched. Optional.
	 *
	 * @return null
	 */
	public function maybe_redirect_support( $secret_id, $envelope = null ) {

		$this->dlog( "Got here. ID: $secret_id", __METHOD__ );

		if ( ! is_admin() ) {
			$redirect_url = get_site_url();
		} else {
			$redirect_url = add_query_arg( 'page', sanitize_text_field( $_GET['page'] ), admin_url( 'admin.php' ) );
		}

		// first check if user can be redirected.
		if ( ! $this->auth_verify_user() ) {
			$this->dlog( "User cannot be redirected.", __METHOD__ );

			return;
		}

		if ( is_null( $envelope ) ) {
			// Get the envelope
			$envelope = $this->api_get_envelope( $secret_id );
		}

		if ( is_wp_error( $envelope ) ) {
			$this->dlog( 'Error: ' . $envelope->get_error_message(), __METHOD__ );
			$this->audit_log->insert( $secret_id, 'failed', $envelope->get_error_message() );
			wp_safe_redirect( $redirect_url, self::REDIRECT_ERROR_STATUS, 'TrustedLogin' );
			exit;
		}

		$url = ( $envelope ) ? $this->envelope_to_url( $envelope ) : false;

		if ( is_wp_error( $url ) ) {
			$this->audit_log->insert( $secret_id, 'failed', $url->get_error_message() );
			wp_safe_redirect( $redirect_url, self::REDIRECT_ERROR_STATUS, 'TrustedLogin' );
			exit;
		}

		if ( $url ) {
			// then redirect
			$this->audit_log->insert( $secret_id, 'redirected', __( 'Successful', 'trustedlogin-vendor' ) );
			wp_safe_redirect( $url, self::REDIRECT_SUCCESS_STATUS, 'TrustedLogin' );
			exit;
		}

		$this->dlog( "Got to end of function, with no action.", __METHOD__ );
	}

	/**
	 * Gets the secretId's associated with an access or license key.
	 *
	 * @since  1.0.0
	 * 
	 * @param  string $access_key The key we're checking for connected sites
	 * 
	 * @return array|WP_Error Array of siteIds or WP_Error on issue.
	 */
	public function api_get_secret_ids( $access_key ){

		if ( empty( $access_key ) ) {
			$this->dlog( 'Error: access_key cannot be empty.', __METHOD__ );

			return new WP_Error( 'data-error', __( 'Access Key cannot be empty', 'trustedlogin-vendor' ) );
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'auth-error', __( 'User not logged in.', 'trustedlogin-vendor' ) );
		}

		$saas_auth  = $this->settings->get_setting( 'private_key' );
		$account_id = $this->settings->get_setting( 'account_id' );
		$public_key = $this->settings->get_setting( 'public_key' );

		if ( empty( $saas_auth ) || empty( $account_id ) || empty( $public_key ) ) {
			$this->dlog( "no api_key, public_key or account_id provided", __METHOD__ );

			return new WP_Error( 'setup-error', __( 'No auth, public key or account_id data found', 'trustedlogin-vendor' ) );
		}

		$saas_attr = array(
			'type'       => 'saas',
			'auth'       => $saas_auth,
			'debug_mode' => $this->settings->debug_mode_enabled(),
		);

		$saas_api = new API_Handler( $saas_attr );
		$endpoint = 'accounts/' . $account_id . '/sites/';
		$method   = 'POST';
		$data     = array( 'accessKeys' => array( $access_key ) );

		$response = $saas_api->call( $endpoint, $data, $method );

		if ( is_wp_error( $response ) ) {
			return $response;
		} 

		$access_keys = array();

		$this->dlog( 'Response: ' . print_r( $response, true ), __METHOD__ );

		if ( ! empty( $response ) ) {
			foreach ( $response as $key => $secrets ) {
				foreach ( $secrets as $secret ) {
					$access_keys[] = $secret;
				}
			}
		}

		return $access_keys;

	}

	/**
	 * API Wrapper: Get the envelope for a specified site ID
	 *
	 * @since 0.2.0
	 *
	 * @param string $site_id - unique secret_id of a site
	 *
	 * @return array|false|WP_Error
	 */
	public function api_get_envelope( $secret_id ) {

		if ( empty( $secret_id ) ) {
			$this->dlog( 'Error: secret_id cannot be empty.', __METHOD__ );

			return new WP_Error( 'data-error', __( 'Site ID cannot be empty', 'trustedlogin-vendor' ) );
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'auth-error', __( 'User not logged in.', 'trustedlogin-vendor' ) );
		}

		// The data array that will be sent to TrustedLogin to request a site's envelope
		$data = array();

		// Let's grab the user details. Logged in status already confirmed in maybe_redirect_support();
		$current_user = wp_get_current_user();

		$data['user'] = array( 'id' => $current_user->ID, 'name' => $current_user->display_name );

		// make sure we have the auth details from the settings page before continuing.
		$saas_auth  = $this->settings->get_setting( 'private_key' );
		$account_id = $this->settings->get_setting( 'account_id' );
		$public_key = $this->settings->get_setting( 'public_key' );

		if ( empty( $saas_auth ) || empty( $account_id ) || empty( $public_key ) ) {
			$this->dlog( "no api_key, public_key or account_id provided", __METHOD__ );

			return new WP_Error( 'setup-error', __( 'No auth, public key or account_id data found', 'trustedlogin-vendor' ) );
		}

		// Then let's get the identity verification pair to confirm the site is the one sending the request.
		$trustedlogin_encryption = new Encryption();
		$auth_nonce              = $trustedlogin_encryption->create_identity_nonce();

		if ( is_wp_error( $auth_nonce ) ) {
			return $auth_nonce;
		}

		$data['nonce']       = $auth_nonce['nonce'];
		$data['signedNonce'] = $auth_nonce['signed'];

		$this->audit_log->insert( $secret_id, 'requested' );

		$endpoint = 'sites/' . $secret_id . '/get-envelope';

		$saas_attr = array(
			'type'       => 'saas',
			'auth'       => $saas_auth,
			'debug_mode' => $this->settings->debug_mode_enabled()
		);
		$saas_api  = new API_Handler( $saas_attr );

		/**
		 * @see https://github.com/trustedlogin/trustedlogin-ecommerce/blob/master/docs/user-remote-authentication.md
		 * @var string $saas_token Additional SaaS Token for authenticating API queries.
		 */
		$saas_token  = hash( 'sha256', $public_key . $saas_auth );
		$token_added = $saas_api->set_additional_header( 'X-TL-TOKEN', $saas_token );

		if ( ! $token_added ) {
			$error = __( 'Error setting X-TL-TOKEN header', 'trustedlogin-vendor' );
			$this->dlog( $error, __METHOD__ );

			return new WP_Error( 'x-tl-token-error', $error );
		}

		/**
		 * @var array $envelope (
		 * @type string $siteurl The site url. Double encrypted.
		 * @type string $identifier The support-agent unique ID. Double encrypted.
		 * @type string $endpoint The unique endpoint for auto-login. Double encrypted.
		 * @type int $expiry The time() of when this Support User will decay
		 * )
		 */
		$envelope = $saas_api->call( $endpoint, $data, 'POST' );

		$success = ( $envelope && ! is_wp_error( $envelope ) ) ? __( 'Successful', 'trustedlogin-vendor' ) : sprintf( __( 'Failed: %s', 'trustedlogin-vendor' ), $envelope->get_error_message() );

		$this->audit_log->insert( $secret_id, 'received', $success );

		return $envelope;

	}

	/**
	 * Helper function: Extract redirect url from encrypted envelope.
	 *
	 * @since 0.1.0
	 *
	 * @param array $envelope Received from encrypted TrustedLogin storage {
	 *   @type string $siteUrl Encrypted site URL
	 *   @type string $identifier Encrypted site identifier, used to generate endpoint
	 *   @type string $publicKey @TODO
	 *   @type string $nonce Nonce from Client {@see \TrustedLogin\Envelope::generate_nonce()} converted to string using \sodium_bin2hex().
	 * }
	 * @param boolen $return_parts Optional. Whether to return an array of parts. Default: false.
	 *
	 * @return string|array|WP_Error
	 */
	public function envelope_to_url( $envelope, $return_parts = false ) {

		if ( is_object( $envelope ) ) {
			$envelope = (array) $envelope;
		}

		if ( ! is_array( $envelope ) ) {
			$this->dlog( 'Error: envelope not an array. e:' . print_r( $envelope, true ), __METHOD__ );

			return new WP_Error( 'malformed_envelope', 'The data received is not formatted correctly' );
		}

		$required_keys = array( 'identifier', 'siteUrl', 'publicKey', 'nonce' );

		foreach ( $required_keys as $required_key ) {
			if ( ! array_key_exists( $required_key, $envelope ) ) {
				$this->dlog( 'Error: malformed envelope. e:' . print_r( $envelope, true ), __METHOD__ );

				return new WP_Error( 'malformed_envelope', 'The data received is not formatted correctly' );
			}
		}

		$trustedlogin_encryption = new Encryption();

		try {

			$nonce = \sodium_hex2bin( $envelope['nonce'] );

			$parts = array(
				'siteurl'    => $envelope['siteUrl'],
				'identifier' => $trustedlogin_encryption->decrypt( $envelope['identifier'], $nonce, $envelope['publicKey'] ),
			);

		} catch ( \Exception $e ) {

			return new WP_Error( $e->getCode(), $e->getMessage() );

		}

		$parts['endpoint'] = md5( $parts['siteurl'] . $parts['identifier'] );

		$loginurl = $parts['siteurl'] . '/' . $parts['endpoint'] . '/' . $parts['identifier'];

		if ( $return_parts ){

			return array( 
				'siteurl' => $parts['siteurl'],
				'loginurl'=> $loginurl,
			);
		}

		return $loginurl;

	}

	/**
	 * Helper: Check if the current user can be redirected to the client site
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	public function auth_verify_user() {

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$_usr       = get_userdata( get_current_user_id() );
		$user_roles = $_usr->roles;

		if ( ! is_array( $user_roles ) ) {
			return false;
		}

		$required_roles = $this->settings->get_approved_roles();

		$intersect = array_intersect( $required_roles, $user_roles );

		if ( 0 < count( $intersect ) ) {
			return true;
		}

		return false;
	}

}
