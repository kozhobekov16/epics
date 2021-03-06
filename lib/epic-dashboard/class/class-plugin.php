<?php
/**
 * Plugin Class
 *
 * @author Jegstudio
 * @license https://opensource.org/licenses/MIT
 * @package epic-dashboard
 */

namespace EPIC\Dashboard;

/**
 * Class Plugin
 *
 * @package EPIC\Dashboard
 */
class Plugin {

	/**
	 * Plugin ID
	 *
	 * @var integer
	 */
	private $id;

	/**
	 * Plugin Name
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Plugin Slug
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Plugin File Path
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Plugin Version
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Plugin Option Name
	 *
	 * @var string
	 */
	private $option;

	/**
	 * Plugin constructor.
	 *
	 * @param array $data Plugin Data.
	 */
	public function __construct( $data ) {
		$this->id      = $data['id'];
		$this->name    = $data['name'];
		$this->slug    = $data['slug'];
		$this->version = $data['version'];
		$this->option  = $data['option'];
		$this->file    = $data['file'];

		$this->setup_hook();
	}

	/**
	 * Setup Plugin Hook
	 */
	public function setup_hook() {
		add_action( 'admin_init', array( $this, 'do_validate_license' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_plugin' ) );
		add_action( 'upgrader_package_options', array( $this, 'maybe_deferred_download' ), 99 );
		add_action( 'epic_retrieve_purchase_list', array( $this, 'force_purchase_list' ), null, 3 );
	}

	/**
	 * Check if deferred download by this plugin
	 *
	 * @param array $options Deferred download options.
	 *
	 * @return mixed
	 */
	public function maybe_deferred_download( $options ) {
		$package = $options['package'];

		if ( false !== strrpos( $package, 'deferred_download' ) && false !== strrpos( $package, 'item_id' ) ) {
			parse_str( wp_parse_url( $package, PHP_URL_QUERY ), $vars );
			if ( (int) $vars['item_id'] === $this->id ) {
				$options['package'] = $this->get_download_url();
			}
		}

		return $options;
	}

	/**
	 * Get Download URL
	 *
	 * @param string $token Envato user API token.
	 *
	 * @return bool
	 */
	public function get_download_url( $token = null ) {
		$token = $this->get_token( $token );
		$code  = $this->get_purchase_code();

		if ( $token ) {
			$url      = 'https://api.envato.com/v2/market/buyer/download?item_id=' . $this->id . '&purchase_code=' . $code . '&shorten_url=true';
			$response = $this->request( $url, $token, array() );

			if ( ! is_wp_error( $response ) ) {
				return $response['wordpress_plugin'];
			} else {
				return false;
			}
		}

		return false;
	}

	/**
	 * Runs on pre_set_site_transient_update_plugin. Provide update status on Plugin
	 *
	 * @param object $transient The update_themes transient object.
	 *
	 * @return object The same or a modified version of the transient.
	 */
	public function update_plugin( $transient ) {
		$slug = "{$this->slug}/{$this->slug}.php";
		if ( ! isset( $transient->response[ $slug ] ) ) {
			if ( $this->check_save_token_validity() ) {
				$option  = get_option( $this->option );
				$plugin  = get_plugin_data( $this->file );
				$premium = $option['item'];

				if ( version_compare( $plugin['Version'], $premium['version'], '<' ) ) {
					$obj                          = new \stdClass();
					$obj->slug                    = $this->slug;
					$obj->new_version             = $premium['version'];
					$obj->url                     = $premium['url'];
					$obj->package                 = $this->deferred_download_url( $premium['id'] );
					$transient->response[ $slug ] = $obj;
				}
			}
		}
		return $transient;
	}

	/**
	 * Get Plugin ID
	 *
	 * @return int|mixed
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get Plugin Name
	 *
	 * @return int|mixed
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Get Plugin Name
	 *
	 * @return int|mixed
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Generate deferred download URL
	 *
	 * @param integer $id Item ID.
	 *
	 * @return string
	 */
	public function deferred_download_url( $id ) {
		$args = array(
			'deferred_download' => true,
			'item_id'           => $id,
		);

		return add_query_arg( $args, esc_url( $this->license_url() ) );
	}

	/**
	 * Get License URL
	 *
	 * @return string
	 */
	public function license_url() {
		static $url;

		if ( ! isset( $url ) ) {
			$parent = 'epic';
			if ( false === strpos( $parent, '.php' ) ) {
				$parent = 'admin.php';
			}
			$url = add_query_arg(
				array(
					'page' => rawurlencode( 'epic' ),
				),
				self_admin_url( $parent )
			);
		}

		return $url;
	}

	/**
	 * Assign license validate
	 *
	 * @return bool
	 */
	public function is_license_valid() {
		return true;
	}

	/**
	 * Validate License
	 */
	public function do_validate_license() {
		if ( isset( $_POST['envato_token'], $_POST['nonce'], $_POST['product-id'], $_POST['action'] ) && wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'epic' ) ) {
			$action     = sanitize_text_field( wp_unslash( $_POST['action'] ) );
			$product_id = (int) sanitize_text_field( wp_unslash( $_POST['product-id'] ) );

			if ( 'validate-license' === $action && $product_id === $this->id ) {
				$token  = sanitize_text_field( wp_unslash( $_POST['envato_token'] ) );
				$result = $this->check_save_token_validity( $token );

				if ( ! $result ) {
					add_action( 'admin_notices', array( $this, 'print_validate_failed' ) );
				}
			}
		}
	}

	/**
	 * Validate Failed
	 */
	public function print_validate_failed() {
		?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Failed to validate license, please check if required access is granted when token created, also please check to make sure if your account already bought the item', 'epic-ne' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Check if token valid
	 *
	 * @param string|null $token Envato user API token.
	 *
	 * @return bool
	 */
	public function check_save_token_validity( $token = null ) {
		$token = $this->get_token( $token );

		if ( $token ) {
			$option = get_option( $this->option, null );

			if ( is_null( $option ) || ! $option['validated'] ) {
				$result = $this->get_token_purchase_list( $token );

				if ( $result ) {
					update_option( $this->option, array(
						'validated' => true,
						'token'     => $token,
						'item'      => $result,
					) );

					return true;
				} else {
					update_option( $this->option, array(
						'validated' => false,
						'token'     => null,
						'item'      => null,
					) );

					return false;
				}
			} else {
				return true;
			}
		}

		return false;
	}

	/**
	 * Force to update purchase list
	 *
	 * @param array  $responses Array of response.
	 * @param string $token Token purchase list.
	 * @param int    $id ID of brodcaster.
	 */
	public function force_purchase_list( $responses, $token, $id ) {
		if ( $id !== $this->id ) {
			foreach ( $responses as $key => $item ) {
				unset( $item['item']['description'] );
				if ( $item['item']['id'] === $this->id ) {
					$plugin = $this->normalize_plugin( $item['item'], $item['code'] );

					update_option( $this->option, array(
						'validated' => true,
						'token'     => $token,
						'item'      => $plugin,
					) );
				}
			}
		}
	}

	/**
	 * Get plugin purchase list from token
	 *
	 * @param string $token Token purchase list.
	 *
	 * @return bool|array
	 */
	public function get_token_purchase_list( $token ) {
		$url      = 'https://api.envato.com/v2/market/buyer/list-purchases?filter_by=wordpress-plugins';
		$response = $this->request( $url, $token, array() );
		$plugin   = false;

		if ( ! is_wp_error( $response ) ) {
			if ( $response && isset( $response['results'] ) ) {

				// Force other plugin instance to check if its plugin available in retrieved purchase list.
				do_action( 'epic_retrieve_purchase_list', $response['results'], $token, $this->id );

				foreach ( $response['results'] as $key => $item ) {
					unset( $item['item']['description'] );
					if ( $item['item']['id'] === $this->id ) {
						$plugin = $this->normalize_plugin( $item['item'], $item['code'] );
					}
				}
			}
		}

		return $plugin;
	}

	/**
	 * Normalize plugin data
	 *
	 * @param array  $plugin Plugin data.
	 * @param string $code User purchase code.
	 *
	 * @return array
	 */
	public function normalize_plugin( $plugin, $code = '' ) {
		$item                = array();
		$item['id']          = $plugin['id'];
		$item['name']        = ! empty( $plugin['wordpress_plugin_metadata']['plugin_name'] ) ? $plugin['wordpress_plugin_metadata']['plugin_name'] : '';
		$item['author']      = ! empty( $plugin['wordpress_plugin_metadata']['author'] ) ? $plugin['wordpress_plugin_metadata']['author'] : '';
		$item['version']     = ! empty( $plugin['wordpress_plugin_metadata']['version'] ) ? $plugin['wordpress_plugin_metadata']['version'] : '';
		$item['url']         = ! empty( $plugin['url'] ) ? $plugin['url'] : '';
		$item['author_url']  = ! empty( $plugin['author_url'] ) ? $plugin['author_url'] : '';
		$item['description'] = $this->remove_non_unicode( $plugin['wordpress_plugin_metadata']['description'] );
		$item['code']        = $code;
		$item['slug']        = $this->slug;
		return $item;
	}

	/**
	 * Remove Non unicode string
	 *
	 * @param string $retval String to be filtered.
	 *
	 * @return mixed
	 */
	private function remove_non_unicode( $retval ) {
		return preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', $retval );
	}

	/**
	 * Request call to Plugin API
	 *
	 * @param string $url API URL.
	 * @param string $token User API Token Generated from Envato.
	 * @param array  $args Additional Parameter for request.
	 *
	 * @return array|mixed|object|\WP_Error
	 */
	public function request( $url, $token, $args ) {
		$defaults = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'User-Agent'    => 'Epic Plugin',
			),
			'timeout' => 20,
		);
		$args     = wp_parse_args( $args, $defaults );

		$token = trim( str_replace( 'Bearer', '', $args['headers']['Authorization'] ) );
		if ( empty( $token ) ) {
			return new \WP_Error( 'api_token_error', esc_html__( 'An API token is required.', 'epic-ne' ) );
		}

		// Make an API request.
		$response = wp_remote_get( esc_url_raw( $url ), $args );

		// Check the response code.
		$response_code    = wp_remote_retrieve_response_code( $response );
		$response_message = wp_remote_retrieve_response_message( $response );

		if ( 200 !== $response_code && ! empty( $response_message ) ) {
			return new \WP_Error( $response_code, $response_message );
		} elseif ( 200 !== $response_code ) {
			return new \WP_Error( $response_code, esc_html__( 'An unknown API error occurred.', 'epic-ne' ) );
		} else {
			$return = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( null === $return ) {
				return new \WP_Error( 'api_error', esc_html__( 'An unknown API error occurred.', 'epic-ne' ) );
			}

			return $return;
		}
	}

	/**
	 * Retrieve token data
	 *
	 * @param string|null $token Envato user API token.
	 *
	 * @return bool|string
	 */
	public function get_token( $token = null ) {
		if ( null === $token || empty( $token ) ) {
			if ( $this->is_license_valid() ) {
				$option = get_option( $this->option );
				$token  = $option['token'];
			} else {
				return false;
			}
		}

		return $token;
	}

	/**
	 * Retrieve purchase code data
	 *
	 * @param string|null $code Envato user API token.
	 *
	 * @return bool|string
	 */
	public function get_purchase_code( $code = null ) {
		if ( null === $code || empty( $code ) ) {
			if ( $this->is_license_valid() ) {
				$option = get_option( $this->option );
				$code   = $option['item']['code'];
			} else {
				return false;
			}
		}

		return $code;
	}
}
