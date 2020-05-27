<?php
/**
 * Class: TrustedLogin Settings
 *
 * @package trustedlogin-vendor
 * @version 0.2.0
 **/

namespace TrustedLogin\Vendor;

use \WP_Error;
use \Exception;
use const TRUSTEDLOGIN_PLUGIN_VERSION;
use function selected;

class Settings {

	const SETTING_NAME = 'trustedlogin_vendor';

	/**
	 * @var boolean $debug_mode Whether or not to save a local text log
	 * @since 0.1.0
	 */
	protected $debug_mode;

	/**
	 * @var array $default_options The default settings for our plugin
	 * @since 0.1.0
	 */
	private $default_options = array(
		'account_id'       => '',
		'private_key'      => '',
		'public_key'       => '',
		'helpdesk'         => array( 'helpscout' ),
		'approved_roles'   => array( 'administrator' ),
		'debug_enabled'    => 'on',
		'output_audit_log' => 'on',
	);

	/**
	 * @var string $menu_location Where the TrustedLogin settings should sit in menu. Options: 'main', or 'submenu' to add under Setting tab
	 * @see Filter: trustedlogin_menu_location
	 */
	private $menu_location = 'main';

	/**
	 * @var array Current site's TrustedLogin settings
	 * @since 0.1.0
	 **/
	private $options;

	/**
	 * @var string $plugin_version Used for versioning of settings page assets.
	 * @since 0.1.0
	 */
	private $plugin_version;

	public function __construct() {

		$this->set_defaults();

		$this->plugin_version = TRUSTEDLOGIN_PLUGIN_VERSION;

		$this->add_hooks();
	}

	public function add_hooks() {

		if( did_action( 'trustedlogin/vendor/add_hooks/after' ) ) {
			return;
		}

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_scripts' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_accesskey' ) );

		do_action( 'trustedlogin/vendor/add_hooks/after' );
	}

	public function debug_mode_enabled() {

		return (bool) $this->debug_mode;

	}

	public function set_defaults() {


		/**
		 * Filter: Manipulate default options
		 *
		 * @since 1.0.0
		 *
		 * @see   `default_options` private variable.
		 *
		 * @param array
		 **/
		$this->default_options = apply_filters( 'trustedlogin/vendor/settings/default', $this->default_options );

		$this->options = get_option( 'trustedlogin_vendor', $this->default_options );

		$this->debug_mode = $this->setting_is_toggled( 'debug_enabled' );

		/**
		 * Filter: Where in the menu the TrustedLogin Options should go.
		 * Added to allow devs to move options item under 'Settings' menu item in wp-admin to keep things neat.
		 *
		 * @since 1.0.0
		 *
		 * @param String either 'main' or 'submenu'
		 **/
		$this->menu_location = apply_filters( 'trustedlogin/vendor/settings/menu-location', 'main' );
	}

	public function add_admin_menu() {

		$args = array(
			'submenu_page' => 'options-general.php',
			'menu_title'   => __( 'Settings', 'trustedlogin-vendor' ),
			'page_title'   => __( 'TrustedLogin', 'trustedlogin-vendor' ),
			'capabilities' => 'manage_options',
			'slug'         => 'trustedlogin_vendor',
			'callback'     => array( $this, 'settings_options_page' ),
			'icon'         => 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz48c3ZnIGVuYWJsZS1iYWNrZ3JvdW5kPSJuZXcgMCAwIDEzOS4zIDIyMC43IiB2ZXJzaW9uPSIxLjEiIHZpZXdCb3g9IjAgMCAxMzkuMyAyMjAuNyIgeG1sOnNwYWNlPSJwcmVzZXJ2ZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48c3R5bGUgdHlwZT0idGV4dC9jc3MiPi5zdDB7ZmlsbDojMDEwMTAxO308L3N0eWxlPjxwYXRoIGNsYXNzPSJzdDAiIGQ9Im00Mi4yIDY5Ljd2LTIxLjZjMC0xNS4yIDEyLjMtMjcuNSAyNy41LTI3LjUgMTUuMSAwIDI3LjUgMTIuMyAyNy41IDI3LjV2MjEuNmM3LjUgMC41IDE0LjUgMS4yIDIwLjYgMi4xdi0yMy43YzAtMjYuNS0yMS42LTQ4LjEtNDguMS00OC4xLTI2LjYgMC00OC4yIDIxLjYtNDguMiA0OC4xdjIzLjdjNi4yLTAuOSAxMy4yLTEuNiAyMC43LTIuMXoiLz48cmVjdCBjbGFzcz0ic3QwIiB4PSIyMS41IiB5PSI2Mi40IiB3aWR0aD0iMjAuNiIgaGVpZ2h0PSIyNS41Ii8+PHJlY3QgY2xhc3M9InN0MCIgeD0iOTcuMSIgeT0iNjIuNCIgd2lkdGg9IjIwLjYiIGhlaWdodD0iMjUuNSIvPjxwYXRoIGNsYXNzPSJzdDAiIGQ9Im02OS43IDc1LjNjLTM4LjUgMC02OS43IDQuOS02OS43IDEwLjh2NTRoNTYuOXYtOS44YzAtMi41IDEuOC0zLjYgNC0yLjNsMjguMyAxNi40YzIuMiAxLjMgMi4yIDMuMyAwIDQuNmwtMjguMyAxNi40Yy0yLjIgMS4zLTQgMC4yLTQtMi4zdi05LjhoLTU2Ljl2MTIuN2MwIDM4LjUgNDcuNSA1NC44IDY5LjcgNTQuOHM2OS43LTE2LjMgNjkuNy01NC44di03OS45Yy0wLjEtNS45LTMxLjMtMTAuOC02OS43LTEwLjh6bTAgMTIyLjRjLTIzIDAtNDIuNS0xNS4zLTQ4LjktMzYuMmgxNC44YzUuOCAxMy4xIDE4LjkgMjIuMyAzNC4xIDIyLjMgMjAuNSAwIDM3LjItMTYuNyAzNy4yLTM3LjJzLTE2LjctMzcuMi0zNy4yLTM3LjJjLTE1LjIgMC0yOC4zIDkuMi0zNC4xIDIyLjNoLTE0LjhjNi40LTIwLjkgMjUuOS0zNi4yIDQ4LjktMzYuMiAyOC4yIDAgNTEuMSAyMi45IDUxLjEgNTEuMS0wLjEgMjguMi0yMyA1MS4xLTUxLjEgNTEuMXoiLz48L3N2Zz4=',
		);

		if ( 'submenu' === $this->menu_location ) {
			add_submenu_page( $args['submenu_page'], $args['menu_title'], $args['page_title'], $args['capabilities'], $args['slug'], $args['callback'] );
		} else {
			add_menu_page(
                $args['menu_title'],
                $args['page_title'],
                $args['capabilities'],
                $args['slug'],
                $args['callback'],
                $args['icon']
            );

             add_submenu_page(
                $args['slug'],
                $args['page_title'],
                $args['menu_title'],
                $args['capabilities'],
                $args['slug'],
                $args['callback']
            );

		}

		add_submenu_page(
			'trustedlogin_vendor',
			__( 'TrustedLogin with Site Key', 'trustedlogin-vendor' ),
			__( 'Log In with Site Key', 'trustedlogin-vendor' ),
			'manage_options', // TODO: Custom capabilities!
			'trustedlogin_log',
			array( $this, 'accesskey_page' )
		);

	}

	public function admin_init() {

		register_setting( 'trustedlogin_vendor_options', 'trustedlogin_vendor', array( 'sanitize_callback' => array( $this, 'verify_api_details' ) ) );

		add_settings_section(
			'trustedlogin_vendor_options_section',
			__( 'Settings for how your site and support agents are connected to TrustedLogin', 'trustedlogin-vendor' ),
			array( $this, 'section_callback' ),
			'trustedlogin_vendor_options'
		);

		add_settings_field(
			'account_id',
			__( 'TrustedLogin Account ID ', 'trustedlogin-vendor' ),
			array( $this, 'account_id_field_render' ),
			'trustedlogin_vendor_options',
			'trustedlogin_vendor_options_section'
		);

		add_settings_field(
			'private_key',
			__( 'TrustedLogin Private Key ', 'trustedlogin-vendor' ),
			array( $this, 'private_key_field_render' ),
			'trustedlogin_vendor_options',
			'trustedlogin_vendor_options_section'
		);

		add_settings_field(
			'public_key',
			__( 'TrustedLogin Public Key ', 'trustedlogin-vendor' ),
			array( $this, 'public_key_field_render' ),
			'trustedlogin_vendor_options',
			'trustedlogin_vendor_options_section'
		);

		add_settings_field(
			'approved_roles',
			__( 'Which WP roles can automatically be logged into customer sites?', 'trustedlogin-vendor' ),
			array( $this, 'approved_roles_field_render' ),
			'trustedlogin_vendor_options',
			'trustedlogin_vendor_options_section'
		);

		add_settings_field(
			'trustedlogin_vendor_helpdesk',
			__( 'Which helpdesk software are you using?', 'trustedlogin-vendor' ),
			array( $this, 'helpdesks_field_render' ),
			'trustedlogin_vendor_options',
			'trustedlogin_vendor_options_section'
		);

		add_settings_field(
			'trustedlogin_vendor_debug_enabled',
			__( 'Enable debug logging?', 'trustedlogin-vendor' ),
			array( $this, 'debug_enabled_field_render' ),
			'trustedlogin_vendor_options',
			'trustedlogin_vendor_options_section'
		);

		add_settings_field(
			'trustedlogin_vendor_output_audit_log',
			__( 'Enable Activity Log?', 'trustedlogin-vendor' ),
			array( $this, 'output_audit_log_field_render' ),
			'trustedlogin_vendor_options',
			'trustedlogin_vendor_options_section'
		);

	}

	/**
	 * Hooks into settings sanitization to verify API details
	 *
	 * Note: Although hooked up to `sanitize_callback`, this function does NOT sanitize data provided.
	 *
	 * @since 0.9.1
	 *
	 * @uses `add_settings_error()` to set an alert for verification failures/errors and success message when API creds verified.
	 *
	 * @param array $input Data saved on Settings page.
	 *
	 * @return array Output of sanitized data.
	 */
	public function verify_api_details( $input ) {

		if ( ! isset( $input['account_id'] ) ) {
			return $input;
		}

		static $api_creds_verified = false;

		if( $api_creds_verified ) {
			return $input;
		}

		try {
			$account_id = intval( $input['account_id'] );
			$saas_auth  = sanitize_text_field( $input['private_key'] );
			$public_key = sanitize_text_field( $input['public_key'] );
			$debug_mode = isset( $input['debug_enabled'] );

			$saas_attr = array(
				'auth' => $saas_auth,
				'debug_mode' => $debug_mode
			);

			$saas_api = new API_Handler( $saas_attr );

			/**
			 * @var string $saas_token Additional SaaS Token for authenticating API queries.
			 * @see https://github.com/trustedlogin/trustedlogin-ecommerce/blob/master/docs/user-remote-authentication.md
			 **/
			$saas_token  = hash( 'sha256', $public_key . $saas_auth );
			$token_added = $saas_api->set_additional_header( 'X-TL-TOKEN', $saas_token );

			if ( ! $token_added ) {
				$error = __( 'Error setting X-TL-TOKEN header', 'tl-support-side' );
				$this->dlog( $error, __METHOD__ );
				throw new Exception( $error );
			}

			$verified = $saas_api->verify( $account_id );

			if ( is_wp_error( $verified ) ) {
				throw new Exception( $verified->get_error_message() );
			}

			$api_creds_verified = true;

		} catch ( Exception $e ) {

			$error = sprintf(
				esc_html__( 'Could not verify TrustedLogin credentials: %s', 'trustedlogin-vendor' ),
				esc_html__( $e->getMessage() )
			);

			add_settings_error(
				'trustedlogin_vendor_options',
				'trustedlogin_auth',
				$error,
				'error'
			);
		}

		if ( $api_creds_verified ) {
			add_settings_error(
				'trustedlogin_vendor_options',
				'trustedlogin_auth',
				__( 'TrustedLogin API credentials verified.', 'trustedlogin-vendor' ),
				'updated'
			);
		}

		return $input;
	}

	public function private_key_field_render() {

		$this->render_input_field( 'private_key', 'password', true );

	}

	public function public_key_field_render() {

		$this->render_input_field( 'public_key', 'text', true );

	}

	public function account_id_field_render() {
		$this->render_input_field( 'account_id', 'text', true );
	}

	public function render_input_field( $setting, $type = 'text', $required = false ) {
		if ( ! in_array( $type, array( 'password', 'text' ) ) ) {
			$type = 'text';
		}

		$value = ( array_key_exists( $setting, $this->options ) ) ? $this->options[ $setting ] : '';

		$set_required = ( $required ) ? 'required' : '';

		$output = '<input id="' . esc_attr( $setting ) . '" name="' . self::SETTING_NAME . '[' . esc_attr( $setting ) . ']" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '" class="regular-text ltr" ' . esc_attr( $set_required ) . '>';

		echo $output;
	}

	public function approved_roles_field_render() {

		$roles          = get_editable_roles();
		$selected_roles = $this->get_approved_roles();

		// I mean, really. No one wants this.
		unset( $roles['subscriber'] );

		$select = '<select name="' . self::SETTING_NAME . '[approved_roles][]" size="5" id="trustedlogin_vendor_approved_roles" class="postform regular-text ltr" multiple="multiple" regular-text>';

		foreach ( $roles as $role_slug => $role_info ) {

			$selected = selected( true, in_array( $role_slug, $selected_roles, true ), false );

			$select .= "<option value='" . $role_slug . "' " . $selected . ">" . $role_info['name'] . "</option>";
		}

		$select .= "</select>";

		echo $select;

	}

	public function helpdesks_field_render() {

		/**
		 * Filter: The array of TrustLogin supported HelpDesks
		 *
		 * @since 0.1.0
		 *
		 * @param array [
		 * 		$slug => [					Slug is the identifier of the Helpdesk software, and is the value of the dropdown option.
		 *			@var string $title,  	Translated title of the Helpdesk software, and title of dropdown option.
		 *			@var bool   $active,	If false, the Helpdesks Solution is not shown in the dropdown options for selection.
		 * 		],
		 * ]
		 **/
		$helpdesks = apply_filters( 'trustedlogin/vendor/settings/helpdesks', array(
			''          => array(
				'title'  => __( 'Select Your Helpdesk Software', 'trustedlogin-vendor' ),
				'active' => false
			),
			'helpscout' => array( 'title' => __( 'HelpScout', 'trustedlogin-vendor' ), 'active' => true ),
			'intercom'  => array( 'title' => __( 'Intercom', 'trustedlogin-vendor' ), 'active' => false ),
			'helpspot'  => array( 'title' => __( 'HelpSpot', 'trustedlogin-vendor' ), 'active' => false ),
			'drift'     => array( 'title' => __( 'Drift', 'trustedlogin-vendor' ), 'active' => false ),
			'gosquared' => array( 'title' => __( 'GoSquared', 'trustedlogin-vendor' ), 'active' => false ),
		) );

		$selected_helpdesk = $this->get_setting( 'helpdesk' );

		$select = '<select name="' . self::SETTING_NAME . '[helpdesk][]" id="helpdesk" class="postform regular-text ltr">';

		foreach ( $helpdesks as $key => $helpdesk ) {

			$selected = selected( $selected_helpdesk, $key, false );

			$title = $helpdesk['title'];

			if ( ! $helpdesk['active'] && ! empty( $key ) ) {
				$title    .= ' (' . __( 'Coming Soon', 'trustedlogin-vendor' ) . ')';
				$disabled = ' disabled="disabled"';
			} else {
				$disabled = '';
			}

			$select .= sprintf( '<option value="%s"%s%s>%s</option>', esc_attr( $key ), esc_attr( $selected ), esc_attr( $disabled ), esc_html( $title ) );

		}

		$select .= "</select>";

		echo $select;

	}

	public function debug_enabled_field_render() {

		$this->settings_output_toggle( 'debug_enabled' );

	}

	public function output_audit_log_field_render() {

		$this->settings_output_toggle( 'output_audit_log' );

	}

	public function settings_output_toggle( $setting ) {

		$value = ( array_key_exists( $setting, $this->options ) ) ? $this->options[ $setting ] : 'off';

		$select = '<label class="switch">
                    <input class="switch-input" name="' . self::SETTING_NAME . '[' . $setting . ']" id="' . $setting . '" type="checkbox" ' . checked( $value, 'on', false ) . '/>
                    <span class="switch-label" data-on="On" data-off="Off"></span>
                    <span class="switch-handle"></span>
                </label>';
		echo $select;
	}

	public function section_callback() {
		do_action( 'trustedlogin/vendor/settings/section-callback' );
	}

	public function settings_options_page() {

		wp_enqueue_script( 'chosen' );
		wp_enqueue_style( 'chosen' );
		wp_enqueue_script( 'trustedlogin-settings' );
		wp_enqueue_style( 'trustedlogin-settings' );

		echo '<form method="post" action="options.php">';

		echo sprintf( '<h1>%1$s</h1>', __( 'TrustedLogin Settings', 'trustedlogin-vendor' ) );

		settings_errors( 'trustedlogin_vendor_options' );

		do_action( 'trustedlogin/vendor/settings/sections/before' );

		settings_fields( 'trustedlogin_vendor_options' );

		do_settings_sections( 'trustedlogin_vendor_options' );

		do_action( 'trustedlogin/vendor/settings/sections/after' );

		submit_button();

		echo '</form>';

		do_action( 'trustedlogin/vendor/settings/form/after' );

	}

	/**
	 * Settings page output for logging into a customer's site via an AccessKey
	 *
	 * @since 1.0.0
	 */
	public function accesskey_page(){

		wp_enqueue_script( 'chosen' );
		wp_enqueue_style( 'chosen' );
		wp_enqueue_script( 'trustedlogin-settings' );
		wp_enqueue_style( 'trustedlogin-settings' );

		$endpoint = new Endpoint( $this );

		if ( $endpoint->auth_verify_user() ){
			$output = sprintf(
				'<div class="trustedlogin-dialog accesskey">
				  <form method="GET">
					  <input type="text" name="ak" id="trustedlogin-access-key" placeholder="%1$s" />
					  <button type="submit" id="trustedlogin-go" class="button button-large trustedlogin-proceed">%2$s</button>
					  <input type="hidden" name="action" value="ak-redirect" />
					  <input type="hidden" name="page" value="%3$s" />
				  </form>
				</div>',
				/* %1$s */ esc_html__('Paste key received from customer', 'trustedlogin-vendor'),
				/* %2$s */ esc_html__('Login to Site', 'trustedlogin-vendor'),
				/* $3$s */ esc_attr( \sanitize_title( $_GET['page'] ) )
			);
		} else {
			$output = sprintf(
				'<div class="trustedlogin-dialog error">%1$s</div>',
				__('You do not have permissions to use TrustedLogin AccessKeys.', 'trustedlogin-vendor')
			);
		}
		echo $output;
	}

	public function maybe_handle_accesskey() {

		if ( ! isset( $_REQUEST['page'] ) || $_REQUEST['page'] !== 'trustedlogin_accesskey' ){
			return;
		}

		if ( ! isset( $_REQUEST['ak'] ) ){
			return;
		}

		$access_key = sanitize_text_field( $_REQUEST['ak'] );

		if ( empty( $access_key ) ){
			return;
		}

		$endpoint = new namespace\Endpoint( $this );
		$endpoint->maybe_redirect_support( $access_key );

	}

	public function register_scripts() {

		wp_register_style(
			'chosen',
			plugins_url( '/assets/chosen/chosen.min.css', dirname( __FILE__ ) )
		);
		wp_register_script(
			'chosen',
			plugins_url( '/assets/chosen/chosen.jquery.min.js', dirname( __FILE__ ) ),
			array( 'jquery' ),
			false,
			true
		);

		wp_register_style( 'trustedlogin-settings',
			plugins_url( '/assets/trustedlogin-settings.css', dirname( __FILE__ ) ),
			array(),
			$this->plugin_version
		);

		wp_register_script( 'trustedlogin-settings',
			plugins_url( '/assets/trustedlogin-settings.js', dirname( __FILE__ ) ),
			array( 'jquery' ),
			$this->plugin_version,
			true
		);
	}

	/**
	 * Returns the value of a setting
	 *
	 * @since 0.2.0
	 *
	 * @param String $setting_name The name of the setting to get the value for
	 *
	 * @return mixed     The value of the setting, or false if it's not found.
	 **/
	public function get_setting( $setting_name ) {

		if ( empty( $setting_name ) ) {
			return new WP_Error( 'input-error', __( 'Cannot fetch empty setting name', 'trustedlogin-vendor' ) );
		}

		switch ( $setting_name ) {
			case 'approved_roles':
				return $this->get_selected_values( 'approved_roles' );
				break;
			case 'helpdesk':
				$helpdesk = $this->get_selected_values( 'helpdesk' );
				return empty( $helpdesk ) ? null : $helpdesk[0];
				break;
			case 'debug_enabled':
				return $this->setting_is_toggled( 'debug_enabled' );
				break;
			default:
				return $value = ( array_key_exists( $setting_name, $this->options ) ) ? $this->options[ $setting_name ] : false;
		}

	}

	public function get_approved_roles() {
		return $this->get_selected_values( 'approved_roles' );
	}

	public function get_selected_values( $setting ) {
		$value = ( array_key_exists( $setting, $this->options ) ) ? $this->options[ $setting ] : array();

		return maybe_unserialize( $value );
	}

	public function setting_is_toggled( $setting ) {
		return array_key_exists( $setting, $this->options ) ? true : false;
	}

	public function settings_get_value( $setting ) {
		return $value = ( array_key_exists( $setting, $this->options ) ) ? $this->options[ $setting ] : false;
	}

}
