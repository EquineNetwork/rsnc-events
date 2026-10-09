<?php
/**
 * Updates from GitHub: WordPress shows "Update available" for this plugin when a newer
 * release is published at github.com/EquineNetwork/rsnc-events (the release's
 * rsnc-events.zip is installed), and the plugin can be set to update automatically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSNC_EV_Updater {

	const REPO      = 'EquineNetwork/rsnc-events';
	const TRANSIENT = 'rsnc_ev_latest_release';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ), 10, 0 );
	}

	private static function basename() {
		return plugin_basename( RSNC_EV_FILE );
	}

	/** The latest release on GitHub, cached for 6 hours (Dashboard → Updates → "Check again" refreshes it). */
	private static function latest() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only "Check again" link.
		$force  = is_admin() && isset( $_GET['force-check'] );
		$cached = get_site_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
				'user-agent' => 'RSNC Events WordPress plugin/' . RSNC_EV_VERSION,
			)
		);
		$release = array();
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $body ) && ! empty( $body['tag_name'] ) ) {
				$package = '';
				foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
					if ( 'rsnc-events.zip' === ( $asset['name'] ?? '' ) ) {
						$package = (string) $asset['browser_download_url'];
					}
				}
				$release = array(
					'version' => ltrim( (string) $body['tag_name'], 'vV' ),
					'package' => $package,
					'url'     => (string) ( $body['html_url'] ?? '' ),
					'notes'   => (string) ( $body['body'] ?? '' ),
					'date'    => (string) ( $body['published_at'] ?? '' ),
				);
			}
		}
		// Remember a failure briefly too, so a GitHub outage doesn't slow every admin page.
		set_site_transient( self::TRANSIENT, $release, $release ? 6 * HOUR_IN_SECONDS : 30 * MINUTE_IN_SECONDS );
		return $release;
	}

	/** Adds this plugin to WordPress's list of available updates (or of plugins that are up to date). */
	public static function check( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::latest();
		if ( empty( $release['version'] ) ) {
			return $transient;
		}
		$item = (object) array(
			'id'          => 'github.com/' . self::REPO,
			'slug'        => 'rsnc-events',
			'plugin'      => self::basename(),
			'new_version' => $release['version'],
			'url'         => 'https://github.com/' . self::REPO,
			'package'     => $release['package'],
			'tested'      => get_bloginfo( 'version' ),
		);
		if ( '' !== $release['package'] && version_compare( $release['version'], RSNC_EV_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
		} else {
			$item->new_version = RSNC_EV_VERSION;
			$transient->no_update[ self::basename() ] = $item;
		}
		return $transient;
	}

	/** The "View details" window on the Plugins page. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'rsnc-events' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::latest();
		return (object) array(
			'name'          => 'RSNC Events',
			'slug'          => 'rsnc-events',
			'version'       => $release['version'] ?? RSNC_EV_VERSION,
			'author'        => 'Equine Network',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'] ?? '',
			'last_updated'  => $release['date'] ?? '',
			'sections'      => array(
				'description' => esc_html__( 'Shows RSNC\'s calendar and published results on rsnc.us from the RSNC admin\'s events feed.', 'rsnc-events' ),
				'changelog'   => nl2br( esc_html( $release['notes'] ?? '' ) ),
			),
		);
	}

	public static function forget() {
		delete_site_transient( self::TRANSIENT );
	}
}
