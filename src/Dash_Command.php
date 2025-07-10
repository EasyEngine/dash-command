<?php

use EE\Model\Site;
use EE\Model\Auth;
use function EE\Utils\get_config_value;
use function EE\Site\Utils\get_site_info;
use function EE\Utils\http_request;

/**
 * Manages EasyDash integration.
 *
 * @package ee-cli
 */
class Dash_Command extends EE_Command {

	/**
	 * The base URL for the EasyDash API.
	 *
	 * @var string
	 */
	private $ed_api_url = '';

	private $ed_base_url = '';

	/**
	 * Integrates server on EasyDash dashboard.
	 *
	 * ## OPTIONS
	 *
	 * [--api=<api-key>]
	 * : ED API Key.
	 *
	 * [--org=<org-name>]
	 * : ED Organization name in which server needs to be added.
	 *
	 * [--ip=<ip-address>]
	 * : Public IP address of the server.
	 *
	 * [--hostname=<hostname>]
	 * : FQDN hostname of the server.
	 *
	 * ## EXAMPLES
	 *
	 *     # Integrate server on EasyDash dashboard
	 *     $ ee dash init --api=xxx
	 *
	 */
	public function init( $args, $assoc_args ) {

		EE\Utils\delem_log( 'dash ' . __FUNCTION__ . ' start' );

		$api_key = EE\Utils\get_flag_value( $assoc_args, 'api', '' );
		$public_ipv4 = EE\Utils\get_flag_value( $assoc_args, 'ip', '' );
		$hostname = EE\Utils\get_flag_value( $assoc_args, 'hostname', '' );
		$organization = EE\Utils\get_flag_value( $assoc_args, 'org', '' );
		$hostname = trim( $hostname );
		$public_ipv4 = trim( $public_ipv4 );
		$api_key = trim( $api_key );
		$organization = trim( $organization );

		if ( empty( $api_key ) ) {
			EE::error( 'Please provide an EasyDash API key using the --api=<token:key> flag.' );
		}

		if ( empty( $organization ) ) {
			EE::error( 'Please provide an EasyDash organization name using the --org=<org-name> flag.' );
		}

		$this->pre_checks();

		$this->ed_api_url = get_config_value( 'ed-api-url', 'https://dash.easyengine.io/api/method/' );
		$this->ed_api_url = rtrim( $this->ed_api_url, '/' ) . '/';

		$this->ed_base_url = preg_replace( '/\/api\/method\/$/', '', $this->ed_api_url );

		$headers    = [
			'Token' => $api_key,
			'Content-Type'  => 'application/json',
		];

		if ( empty( $public_ipv4 ) ) {
			$public_ipv4 = $this->get_external_ip();
		}

		if ( false === $public_ipv4 ) {
			$public_ipv4 = EE::input( 'Please enter the public IP address of the server: ' );
		}

		$host_confirm = false;

		if ( ! empty( $hostname ) && filter_var( $hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
			$host_confirm = true;
		}

		$hostname = gethostname();
		$valid_hostname = false;
		// Check if $hostname is a valid FQDN
		if ( filter_var( $hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
			$valid_hostname = true;
		} else {
			$valid_hostname = false;
		}

		if ( $valid_hostname && ! $host_confirm ) {
			$host_confirm = EE::confirm( 'Is the hostname ' . $hostname . ' correct?', [], false );
		}

		if ( ! $valid_hostname || ! $host_confirm ) {
			while ( true ) {
				$hostname = EE::input( 'Please enter the FQDN hostname of the server: ' );
				if ( filter_var( $hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
					break;
				}
				EE::warning( 'Invalid hostname. Please enter a valid FQDN hostname.' );
			}
			// Set the hostname to the server
			EE::launch( 'hostnamectl set-hostname ' . $hostname );
		}

		// Check if the server already exists on EasyDash
		if ( $this->server_exists_on_easydash( $api_key, $public_ipv4, $organization ) ) {
			EE::log( 'Server with IP ' . $public_ipv4 . ' already exists on EasyDash. Skipping server addition.' );
		} else {

			// @todo: Check if the server is already integrated with EasyDash.

			$ssh_key_exists = EE::launch( 'grep -q "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILbESQqRcGdwnn/u1BkDCD9rDiFqgDhTHBHIIasaDpWV EasyEngine" /root/.ssh/authorized_keys' );
			if ( 0 !== $ssh_key_exists->return_code ) {
				EE::launch( 'echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILbESQqRcGdwnn/u1BkDCD9rDiFqgDhTHBHIIasaDpWV EasyEngine" >> /root/.ssh/authorized_keys' );
			}

			$server_data  = [
				"hostname"      => $hostname,
				"public_ipv4"   => $public_ipv4,
				"organization"  => $organization,
			];

			$server_data_json = json_encode( $server_data );

			// Make the API call to add server to EasyDash
			try {
				$server_response = http_request(
					'POST',
					$this->ed_api_url . 'easydash.easydash.doctype.server.server.add_server',
					$server_data_json,
					$headers
				);

				if ( $server_response->success ) {
					EE::success( 'Server integrated with EasyDash successfully. Please wait for sometime for site data to populate on EasyDash.' );
					exit( 0 );
				} else {
					EE::warning( 'Failed to integrate server with EasyDash. Response: ' . $server_response->body );
					EE::warning( 'Status code: ' . $server_response->status_code );
				}
			} catch ( \RuntimeException $e ) {
				EE::error( 'Error connecting to EasyDash API: ' . $e->getMessage() );
				return;
			}
		}

		$sites = Site::all();
		foreach ( $sites as $site ) {

			if ( ! $site->site_enabled ) {
				EE::launch( "ee site enable $site->site_url" );
			}

			$site_data = get_site_info( [ $site->site_url ] );

			if ( empty( $site_data ) ) {
				EE::warning( "Could not retrieve site information for: {$site->site_url}." );
				if ( ! $site->site_enabled ) {
					EE::launch( "ee site disable $site->site_url" );
				}
				continue;
			}

			if ( ! file_exists( $site->site_fs_path . '/docker-compose.yml' ) ) {
				EE::warning( "docker-compose.yml file not found for site: {$site->site_url}. Skipping." );
				continue;
			}

			$public_dir = str_replace( '/var/www/htdocs', '', $site->site_container_fs_path );
			$public_dir = ltrim( $public_dir, '/' );

			// Check if the site already exists on EasyDash
			if ( $this->site_exists_on_easydash( $api_key, $site->site_url, $organization ) ) {
				EE::log( 'Site ' . $site->site_url . ' already exists on EasyDash. Skipping site addition.' );
				if ( ! $site->site_enabled ) {
					EE::launch( "ee site disable $site->site_url" );
				}
				continue;
			}

			// @todo: Add site ssl type to details (le/self/custom).
			// @todo: FQDN hostname
			// @todo: mailhog

			// If site type is wp then only then get the table prefix.
			if ( 'wp' === $site->site_type ) {
				$table_prefix = '';

				$table_config = "ee shell $site->site_url --command='wp config get table_prefix' --skip-tty";
				$table_prefix = trim( EE::launch( $table_config, false, true )->stdout );
			} else {
				$table_prefix = '';
			}

			if ( 'latest' === $site->php_version ) {
				$php_version = trim ( EE::launch( "ee shell $site->site_url --skip-tty --command=\"php -v\"" )->stdout );
				if ( preg_match( '/PHP (\d+\.\d+)/', $php_version, $matches ) ) {
					$site->php_version = $matches[1];
				} else {
					EE::debug( "Could not determine PHP version for site {$site->site_url}. Using default version 8.1." );
					$site->php_version = '8.2';
				}
			}

			if ( ! $site->site_enabled ) {
				EE::launch( "ee site disable $site->site_url" );
			}

			$alias_domains = $site->alias_domains;
			$alias_domains = explode( ',', $alias_domains );
			$alias_domains = array_map( 'trim', $alias_domains );
			$alias_domains = array_filter( $alias_domains, function ( $domain ) use ( $site ) {
				return $domain !== $site->site_url;
			} );
			// implode the alias domains into a comma-separated string
			$alias_domains = implode( ',', $alias_domains );

			$query_conditions = [
				'site_url' => $site->site_url,
			];
			$existing_auths = Auth::where( $query_conditions );

			EE::log( 'Checking for existing auths for site: ' . $site->site_url );
			EE::log( 'Existing auths: ' . json_encode( $existing_auths, JSON_PRETTY_PRINT ) );

			if ( empty( $existing_auths ) ) {
				$http_auth = false;
			} else {
				$http_auth = true;
			}

			if ( 'wp' === $site->app_sub_type || 'php' === $site->app_sub_type || 'html' === $site->app_sub_type || empty( $site->app_sub_type ) ) {
				$app_sub_type = 0;
			} else {
				$app_sub_type = $site->app_sub_type;
			}

			$site_info = [
				"domain"                   => $site->site_url,
				"server"                   => $hostname,
				"site_type"                => $site->site_type,
				"organization"             => $organization,
				"enabled"                  => $site->site_enabled ? true : false,
				"alias_domains"            => $alias_domains,
				"ssl"                      => $site->site_ssl ? true : false,
				"http_basic_auth"          => $http_auth,
				"admin_tools"              => $site->admin_tools,
				"mailhog"                  => $site->mailhog_enabled,
				"php_version"              => $site->php_version,
				"public_directory"         => $public_dir,
				"enable_database"          => ! empty( $site->db_name ),
				"redis_cache"              => $site->cache_nginx_fullpage,
				"multisite"                => $app_sub_type,
				"table_prefix"             => $table_prefix,
			];

			// Log the site information for debugging
			EE::Log( 'Site Data: ' . json_encode( $site_info, JSON_PRETTY_PRINT ) );

			// If php version is < 8.1, then skip adding the site.
			if ( $site->site_type !== 'html' && version_compare( $site->php_version, '8.1', '<' ) ) {
				EE::warning( "Skipping site {$site->site_url} integration with EasyDash as PHP version is less than 8.1." );
				continue;
			}

			$site_info_json = json_encode( $site_info );

			try {
				$site_response = http_request(
					'POST',
					$this->ed_api_url . 'easydash.easydash.doctype.site.site.add_site',
					$site_info_json,
					$headers
				);

				if ( $site_response->success ) {
					EE::success( "Site {$site->site_url} integrated with EasyDash successfully." );
				} else {
					EE::error( "Failed to integrate site {$site->site_url} with EasyDash. Response: " . $site_response->body );
				}
			} catch ( \RuntimeException $e ) {
				EE::error( "Error connecting to EasyDash API for site {$site->site_url}: " . $e->getMessage() );
			}
		}

		EE\Utils\delem_log( 'dash ' . __FUNCTION__ . ' end' );
	}

	/**
	 * Checks if a server already exists on EasyDash based on its IP address.
	 *
	 * @param string $api_key The EasyDash API key.
	 * @param string $public_ipv4 The public IPv4 address of the server.
	 *
	 * @return bool True if the server exists, false otherwise.
	 */
	private function server_exists_on_easydash( $api_key, $public_ipv4, $organization ) {
		$server_api_url = $this->ed_base_url . '/api/method/easydash.easydash.doctype.server.server.get_server_list';
		$headers        = [
			'Token' => $api_key,
			'Content-Type'  => 'application/json',
		];
		$data = [
			'organization' => $organization,
		];

		try {
			$server_response = http_request( 'POST', $server_api_url, json_encode($data), $headers );

			EE::debug( 'Request URL: ' . $server_api_url );
			EE::debug( 'Request Headers: ' . json_encode( $headers ) );
			EE::debug( 'Request data: ' . json_encode( $data ) );
			EE::debug( 'Checking if server with IP ' . $public_ipv4 . ' exists on EasyDash...' );
			EE::debug( 'Response from EasyDash: ' . $server_response->body );

			if ( $server_response->success ) {
				$server_data = json_decode( $server_response->body, true );

				if ( isset( $server_data['message'] ) && is_array( $server_data['message'] ) ) {
					foreach ( $server_data['message'] as $server ) {
						if ( isset( $server['public_ipv4'] ) && $server['public_ipv4'] === $public_ipv4 ) {
							return true;
						}
					}
				}
				return false;
			} else {
				EE::log( 'There are no servers in the org right now. Adding new one...' );
				EE::debug( 'Response: ' . $server_response->body );
				EE::debug( 'Status code: ' . $server_response->status_code );
				return false; // Assume server doesn't exist to avoid potential issues
			}
		} catch ( \RuntimeException $e ) {
			EE::warning( 'Error connecting to EasyDash API to get server list.' );
			EE::debug( 'Error message: ' . $e->getMessage() );
			EE::debug( 'Error code: ' . $e->getCode() );
			return false; // Assume server doesn't exist to avoid potential issues
		}
	}


	/**
	 * Checks if a site already exists on EasyDash.
	 *
	 * @param string $api_key The EasyDash API key.
	 * @param string $site_url The URL of the site.
	 * @param string $hostname The hostname of the server.
	 *
	 * @return bool True if the site exists, false otherwise.
	 */
	private function site_exists_on_easydash( $api_key, $site_url, $organization ) {
		$site_api_url = $this->ed_base_url . '/api/method/easydash.easydash.doctype.site.site.get_site_list';
		$headers      = [
			'Token' => $api_key,
			'Content-Type'  => 'application/json',
		];

		$data = [
			'organization' => $organization,
		];

		try {
			$site_response = http_request( 'POST', $site_api_url, json_encode( $data ), $headers );

			if ( $site_response->success ) {
				$site_data = json_decode( $site_response->body, true );
				if ( isset( $site_data['message'] ) && is_array( $site_data['message'] ) ) {
					foreach ( $site_data['message'] as $site ) {
						if ( isset( $site['domain'] ) && $site['domain'] === $site_url ) {
							return true;
						}
					}
				}
				return false;
			} else {
				EE::log( 'Failed to retrieve site list.' );
				EE::debug( 'Response: ' . $site_response->body );
				EE::debug( 'Status code: ' . $site_response->status_code );
				return false; // Assume site doesn't exist to avoid potential issues
			}
		} catch ( \RuntimeException $e ) {
			EE::warning( 'Error connecting to EasyDash API to get site list: ' . $e->getMessage() );
			return false; // Assume site doesn't exist to avoid potential issues
		}
	}

	/**
	 * Do initial checks if server is eligible for EasyDash integration.
	 */
	private function pre_checks() {

		// If the OS is not Ubuntu or Debian, exit with error that unsupported OS.
		$os = trim( EE::launch( 'lsb_release -i' )->stdout );
		// Check if the server is running Ubuntu or Debian.
		if ( false === strpos( $os, 'Ubuntu' ) && false === strpos( $os, 'Debian' ) ) {
			EE::error( 'EasyDash integration is only supported on Ubuntu or Debian.' );
		}

		// Check if the server is running Ubuntu 22.04 or later.
		$ubuntu_version = trim( EE::launch( 'lsb_release -r' )->stdout );
		$version_string = str_replace('Release:', '', $ubuntu_version);
		$version = trim($version_string);
		if (version_compare($version, '22.04', '<')) {
			EE::error( 'EasyDash integration is only supported on Ubuntu 22.04 or later.' );
		}

		// Check if rclone config is present. If there, show confirm msg to continue.
		// if ( file_exists( '~/.config/rclone/rclone.conf' ) ) {
		// 	$confirm = EE::confirm( 'Rclone config file found. Do you want to continue with EasyDash integration?', [], false );
		// 	if ( ! $confirm ) {
		// 		EE::error( 'EasyDash integration aborted by user.' );
		// 	}
		// }
	}

	/**
	 * Gets the external IP address of the server.
	 *
	 * @return string|false The external IP address, or false on failure.
	 */
	private function get_external_ip() {
		$ip_service_url = 'https://api.ipify.org';

		try {
			$response = http_request( 'GET', $ip_service_url, null, [], [ 'timeout' => 15 ] );

			if ( $response->success ) {
				$ip = trim( $response->body );
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
					return $ip;
				} else {
					EE::warning( "Invalid IP address received from $ip_service_url: " . $ip );
					return false;
				}
			} else {
				EE::warning( "Failed to retrieve external IP address from $ip_service_url. Status code: " . $response->status_code . " Body: " . $response->body );
				return false;
			}

		} catch ( \RuntimeException $e ) {
			EE::warning( "Error retrieving external IP address: " . $e->getMessage() );
			return false;
		}
	}
}
