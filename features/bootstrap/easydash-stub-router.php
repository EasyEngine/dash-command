<?php
/**
 * Local EasyDash API stub for the dash-command Behat suite, served by `php -S 127.0.0.1:<port>`.
 *
 * Every request is appended to requests.jsonl. Answers come from overrides.json (per endpoint) or,
 * by default, from state.json, which add_server/add_site update so repeated runs see earlier additions.
 *
 * @package ee-cli
 */

$dir = getenv( 'EASYDASH_STUB_DIR' );
if ( ! $dir || ! is_dir( $dir ) ) {
	http_response_code( 500 );
	echo '{"exc":"EASYDASH_STUB_DIR is not set"}';
	return true;
}

$path     = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$method   = basename( $path );
$endpoint = substr( (string) strrchr( '.' . $method, '.' ), 1 );
$raw_body = (string) file_get_contents( 'php://input' );
$json     = json_decode( $raw_body, true );
$headers  = [];
foreach ( ( function_exists( 'getallheaders' ) ? getallheaders() : [] ) as $name => $value ) {
	$headers[ strtolower( $name ) ] = $value;
}

file_put_contents(
	$dir . '/requests.jsonl',
	json_encode(
		[
			'method'   => $_SERVER['REQUEST_METHOD'],
			'path'     => $path,
			'endpoint' => $endpoint,
			'headers'  => $headers,
			'body'     => $raw_body,
			'json'     => $json,
		]
	) . "\n",
	FILE_APPEND | LOCK_EX
);

$respond = function ( $status, $body ) {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo is_string( $body ) ? $body : json_encode( $body );
	return true;
};

$overrides = json_decode( (string) @file_get_contents( $dir . '/overrides.json' ), true );
if ( is_array( $overrides ) && isset( $overrides[ $endpoint ] ) ) {
	$override = $overrides[ $endpoint ];
	if ( ! empty( $override['delay'] ) ) {
		sleep( (int) $override['delay'] );
	}
	return $respond( isset( $override['status'] ) ? (int) $override['status'] : 200, isset( $override['body'] ) ? $override['body'] : '{"message":"ok"}' );
}

$state_file = $dir . '/state.json';
$state      = json_decode( (string) @file_get_contents( $state_file ), true );
if ( ! is_array( $state ) ) {
	$state = [ 'servers' => [], 'sites' => [] ];
}

switch ( $endpoint ) {
	case 'get_server_list':
		return $respond( 200, [ 'message' => $state['servers'] ] );

	case 'get_site_list':
		return $respond( 200, [ 'message' => $state['sites'] ] );

	case 'add_server':
		$state['servers'][] = [
			'public_ipv4' => isset( $json['public_ipv4'] ) ? $json['public_ipv4'] : null,
			'hostname'    => isset( $json['hostname'] ) ? $json['hostname'] : null,
		];
		file_put_contents( $state_file, json_encode( $state ), LOCK_EX );
		return $respond( 200, [ 'message' => 'Server added' ] );

	case 'add_site':
		$state['sites'][] = [ 'domain' => isset( $json['domain'] ) ? $json['domain'] : null ];
		file_put_contents( $state_file, json_encode( $state ), LOCK_EX );
		return $respond( 200, [ 'message' => 'Site added' ] );

	default:
		return $respond( 404, [ 'exc_type' => 'DoesNotExistError', 'exception' => 'Unknown method ' . $method ] );
}
