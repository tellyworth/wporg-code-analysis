#!/usr/bin/php
<?php
/**
 * A quick and dirty script for testing the PHPCS class against any plugin in the directory.
 * This script does not use or require WordPress.
 *
 * Usage:
 * php check-plugin-by-slug.php --slug akismet
 * Or:
 * php check-plugin-by-slug.php
 */

use WordPressDotOrg\Code_Analysis\PHPCS;

// This script should only be called in a CLI environment.
if ( 'cli' != php_sapi_name() ) {
	die();
}

$opts = getopt( '', array( 'slug:', 'tag:', 'report:', 'page:', 'number:', 'errors', 'jsonfile:' ) );
if ( empty( $opts['report'] ) ) {
	$opts['report'] = 'summary';
}
if ( intval( $opts['page'] ?? 0 ) < 1 ) {
	$opts['page'] = 1;
}
if ( intval( $opts['number'] ?? 0 ) < 1 ) {
	$opts['number'] = 25;
}
if ( empty( $opts['tag'] ) || empty( $opts['slug'] ) ) {
	$opts['tag'] = null;
}

// Fetch the slugs of the top plugins in the directory
function get_top_slugs( $plugins_to_retrieve, $starting_page = 1, $browse = 'popular' ) {
	$payload = array(
		'action' => 'query_plugins',
		'request' => serialize( (object) array( 'browse' => $browse, 'per_page' => $plugins_to_retrieve, 'page' => $starting_page, 'fields' => [ 'active_installs' => true ] ) ) );

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,"https://api.wordpress.org/plugins/info/1.0/");
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $payload );

	$response = curl_exec( $ch );

	$data = unserialize( $response );

	curl_close( $ch );

	var_dump( $data ); die;

	$out = [];

	foreach ( $data->plugins as $plugin ) {
		$out[ $plugin->slug ] = [
			'slug' => $plugin->slug,
			'installs' => $plugin->active_installs,
			'updated' => $plugin->last_updated,
		];
	}

	return $out;
}

// Return two zip file URLs: $version_to_check, and the next available version after that one.
function get_zip_versions( $plugin_slug, $version_to_check ) {
	$payload = array(
		'action' => 'plugin_information',
		'request' => serialize( (object) array( 'slugs' => $plugin_slug, 'fields' => [ 'active_installs' => true ] ) ) );

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,"https://api.wordpress.org/plugins/info/1.0/");
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $payload );

	$response = curl_exec( $ch );

	$data = (array)unserialize( $response );

	curl_close( $ch );

	if ( isset( $data[$plugin_slug]['versions'] ) && count( $data[$plugin_slug]['versions'] ) > 1 ) {
		$version_keys = array_keys( $data[$plugin_slug]['versions'] );

		if ( $i = array_search( $version_to_check, $version_keys ) ) {
			if ( isset( $version_keys[ $i + 1 ] ) ) {
				return [
					$version_keys[ $i ] => $data[$plugin_slug]['versions'][ $version_keys[ $i ] ],
					$version_keys[ $i+1 ] => $data[$plugin_slug]['versions'][ $version_keys[ $i+1 ] ],

				];
			}
		}
	}

	return false;

}

function get_svn_tag_versions( $plugin_slug, $version_to_check ) {
	$cmd = "svn ls " . escapeshellarg( "https://plugins.svn.wordpress.org/" . urlencode($plugin_slug) . '/tags/' );
	$ls = shell_exec( $cmd );
	$tags = array_map( function($str) { return trim( trim( $str ), '/' ); }, explode( "\n", $ls ) );
	$tags = array_filter( $tags );
	usort( $tags, 'version_compare' );

	if ( $index = array_search( $version_to_check, $tags ) ) {
		if ( isset( $tags[ $index + 1 ] ) ) {
			return [
				$tags[ $index ],
				$tags[ $index + 1 ],
			];
		}
	}

	return false;
}

function get_dir_for_tag( $tag ) {
	if ( !empty( $tag ) ) {
		$dir = '/tags/' . basename( $tag );
	} else {
		$dir = '/trunk';
	}

	return $dir;
}

// Export a plugin to ./plugins/SLUG and return the full path to that directory
function export_plugin( $slug, $tag = null ) {

	$tmpnam = tempnam( '/tmp', 'plugin-' . $slug );

	$dir = get_dir_for_tag( $tag );

	if ( $tmpnam ) {
		$tmpnam = realpath( $tmpnam );
		unlink( $tmpnam );
		mkdir( $tmpnam ) || die( "Failed creating temp directory $tmpnam" );
		$cmd = "svn export --force https://plugins.svn.wordpress.org/" . $slug . $dir . ' ' . $tmpnam;
		shell_exec( $cmd );

		return $tmpnam;
	}
}

// Export a plugin ZIP to ./themes/SLUG and return the full path to that directory
function export_plugin_zip( $slug, $url ) {

	$zipfile = tempnam( '/tmp', $slug . '.zip' );
	copy( $url, $zipfile );

	$tmpnam = tempnam( '/tmp', 'plugin-' . $slug );

	if ( $tmpnam ) {
		$tmpnam = realpath( $tmpnam );
		unlink( $tmpnam );
		mkdir( $tmpnam ) || die( "Failed creating temp directory $tmpnam" );

		$zip = new ZipArchive();
		if ( $zip->open( $zipfile ) ) {
			$zip->extractTo( $tmpnam . '/' );
			$zip->close();
			unlink( $zipfile );
			return $tmpnam;
		}
	}

	return false;
}


// Fake WP_Error class so the PHPCS class works
class WP_Error {
	var $code;
	var $message;
	var $data;

	function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}

	public function __toString() {
		return var_export( $this, true );
	}
}

// Again so PHPCS class works
define( 'WPINC', 'yeahnah' );

// Load phpcs class
require dirname( __DIR__ ) . '/includes/class-phpcs.php';



function differential_scan( $slug, $tag, $errors_only = false ) {

	$svn_tags = get_svn_tag_versions( $slug, $tag );
	if ( $svn_tags ) {
		echo str_repeat( '=', 80 ) . "\n";
		echo "Checking svn " . $slug . " version $svn_tags[0] vs $svn_tags[1]...\n";
		echo str_repeat( '=', 80 ) . "\n";
		$version_strings = $svn_tags;

		$path_old = export_plugin( $slug, $svn_tags[0] );
		$path_new = export_plugin( $slug, $svn_tags[1] );
	} else {
		$versions = get_zip_versions( $slug, $tag );
		if ( !$versions || count( $versions ) !== 2 ) {
			echo "Unable to find plugin " . $slug . " version " . $tag . "\n";
			#var_dump( $versions );
			return false;
		}
		$version_strings = array_keys( $versions );
		$zip_urls = array_values( $versions );

		echo str_repeat( '=', 80 ) . "\n";
		echo "Checking zip " . $slug . " version $version_strings[0] vs $version_strings[1]...\n";
		echo str_repeat( '=', 80 ) . "\n";

		$path_old = export_plugin_zip( $slug, $zip_urls[0] );
		$path_new = export_plugin_zip( $slug, $zip_urls[1] );
	}


	$phpcs = new PHPCS();
	$phpcs->set_standard( dirname( __DIR__ ) . '/MinimalPluginStandard' );

	$args = array(
		'extensions' => 'php', // Only check php files.
		's' => true, // Show the name of the sniff triggering a violation.
	);



	$result_1 = $phpcs->run_json_report( $path_old, $args, 'array' );
	if ( !$result_1 ) {
		return false;
	}
	$result_2 = $phpcs->run_json_report( $path_new, $args, 'array' );
	if ( !$result_2 ) {
		return false;
	}

	$files = array_unique( array_merge( array_keys( $result_1[ 'files' ] ), array_keys( $result_2[ 'files' ] ) ) );
	foreach ( $files as $filename ) {
		if ( empty( $result_1[ 'files' ][ $filename ] ) ) {
			echo "New in $version_strings[1]: $filename\n";
			echo "Errors " . $result_2[ 'files' ][ $filename ][ 'errors' ] . " and Warnings " . $result_2[ 'files' ][ $filename ][ 'warnings' ] . "\n";
		} elseif ( empty( $result_2[ 'files' ][ $filename ] ) ) {
			echo "Removed in $version_strings[1]: $filename\n";
			echo "Errors " . $result_1[ 'files' ][ $filename ][ 'errors' ] . " and Warnings " . $result_1[ 'files' ][ $filename ][ 'warnings' ] . "\n";
		} else {
			if ( array_diff_key( $result_1[ 'files' ][ $filename ]['messages'], $result_2[ 'files' ][ $filename ]['messages'] ) || array_diff_key( $result_2[ 'files' ][ $filename ]['messages'], $result_1[ 'files' ][ $filename ]['messages'] ) ) {
				echo "Changed in $version_strings[1]: $filename\n";
				echo "Was Errors " . $result_1[ 'files' ][ $filename ][ 'errors' ] . " and Warnings " . $result_1[ 'files' ][ $filename ][ 'warnings' ] . "\n";
				echo "Now Errors " . $result_2[ 'files' ][ $filename ][ 'errors' ] . " and Warnings " . $result_2[ 'files' ][ $filename ][ 'warnings' ] . "\n";

				foreach ( array_diff_key( $result_1[ 'files' ][ $filename ]['messages'], $result_2[ 'files' ][ $filename ]['messages'] ) as $fixed ) {
					if ( !$errors_only || 'ERROR' === $fixed['type'] ) {
						echo "Fixed: \n" . $fixed['line'] . "\t " . $fixed['type'] . "\t";
						echo $fixed[ 'source' ] . "\n";
						echo $fixed[ 'message' ] . "\n\n";
					}
				}

				foreach ( array_diff_key( $result_2[ 'files' ][ $filename ]['messages'], $result_1[ 'files' ][ $filename ]['messages'] ) as $added ) {
					if ( !$errors_only || 'ERROR' === $added['type'] ) {
						echo "Introduced: \n" . $added['line'] . "\t " . $added['type'] . "\t";
						echo $added[ 'source' ] . "\n";
						echo $added[ 'message' ] . "\n\n";
					}
				}
			} else {
				#echo "No change in $version_strings[1]: $filename\n";
				#echo "Errors " . $result_2[ 'files' ][ $filename ][ 'errors' ] . " and Warnings " . $result_2[ 'files' ][ $filename ][ 'warnings' ] . "\n";
			}
		}

	}

	#echo `diff -r -x '*.js' -x '*.txt' -x '*.mo' -x '*.po' -x '*.css' $path_old $path_new`;
}

if ( !empty( $opts['slug' ] ) && !empty( $opts['tag'] ) ) {
	differential_scan( $opts['slug'], $opts['tag'], isset( $opts['errors'] ) );
} elseif ( !empty( $opts[ 'jsonfile' ] ) ) {
	$jsondb = json_decode( file_get_contents( $opts['jsonfile' ] ) );
	if ( !$jsondb ) {
		die("Unable to read data from " . $opts['jsonfile'] . "\n");
	}

	foreach ( array_reverse( $jsondb ) as $item ) {
		if ( isset( $item->slug ) && isset( $item->max_version_vulnerable ) && false !== strpos( $item->url, 'wordpress.org' ) ) {
			differential_scan( $item->slug, $item->max_version_vulnerable, isset( $opts[ 'errors' ] ) );
		}
	}
}