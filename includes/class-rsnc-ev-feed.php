<?php
/**
 * Reads the RSNC admin's events feed, caches it, and keeps the last good copy so the
 * pages still show events if the feed is ever unreachable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSNC_EV_Feed {

	/** Option listing every cache key in use (so "Clear cache" can find them). */
	const KEYS_OPTION = 'rsnc_ev_cache_keys';

	/** Prefix of the long-lived "last good copy" options. */
	const LAST_GOOD_PREFIX = 'rsnc_ev_last_';

	/**
	 * Events from the feed.
	 *
	 * @param array $query Feed parameters, e.g. array( 'includeResults' => 1 ).
	 * @return array|null List of event records, or null when nothing has ever been fetched.
	 */
	public static function events( array $query = array() ) {
		$url = add_query_arg( array_map( 'rawurlencode', $query ), RSNC_EV_Settings::get( 'feed_url' ) );
		$key = 'rsnc_ev_' . md5( $url );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$fresh = self::fetch( $url );
		if ( is_array( $fresh ) ) {
			$minutes = max( 1, (int) RSNC_EV_Settings::get( 'cache_minutes' ) );
			set_transient( $key, $fresh, $minutes * MINUTE_IN_SECONDS );
			update_option( self::LAST_GOOD_PREFIX . md5( $url ), array( 'data' => $fresh, 'time' => time() ), false );
			self::remember_key( $key, md5( $url ) );
			return $fresh;
		}

		// The feed failed: serve the last good copy, and don't retry on every page view.
		$last = get_option( self::LAST_GOOD_PREFIX . md5( $url ) );
		if ( is_array( $last ) && isset( $last['data'] ) && is_array( $last['data'] ) ) {
			set_transient( $key, $last['data'], 2 * MINUTE_IN_SECONDS );
			self::remember_key( $key, md5( $url ) );
			return $last['data'];
		}
		return null;
	}

	/** Fetches and decodes the feed; returns the data list, or null on any failure. */
	private static function fetch( $url ) {
		$headers = array( 'Accept' => 'application/json' );
		$consumer = trim( (string) RSNC_EV_Settings::get( 'consumer' ) );
		if ( '' !== $consumer ) {
			$headers['X-RSNC-Consumer'] = $consumer;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'headers'    => $headers,
				'user-agent' => 'RSNC Events WordPress plugin/' . RSNC_EV_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::log_error( $response->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			self::log_error( 'HTTP ' . $code );
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			self::log_error( 'The feed did not return events JSON.' );
			return null;
		}
		update_option( 'rsnc_ev_last_success', time(), false );
		return $body['data'];
	}

	private static function log_error( $message ) {
		update_option( 'rsnc_ev_last_error', array( 'message' => (string) $message, 'time' => time() ), false );
	}

	private static function remember_key( $transient, $hash ) {
		$keys = get_option( self::KEYS_OPTION, array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		if ( ! isset( $keys[ $transient ] ) ) {
			$keys[ $transient ] = $hash;
			update_option( self::KEYS_OPTION, $keys, false );
		}
	}

	/**
	 * Empties the cache so the next page view reads the feed again.
	 *
	 * @param bool $everything Also forget the last good copies (only on uninstall).
	 */
	public static function clear_cache( $everything = false ) {
		$keys = get_option( self::KEYS_OPTION, array() );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $transient => $hash ) {
				delete_transient( $transient );
				if ( $everything ) {
					delete_option( self::LAST_GOOD_PREFIX . $hash );
				}
			}
		}
		if ( $everything ) {
			delete_option( self::KEYS_OPTION );
			delete_option( 'rsnc_ev_last_error' );
			delete_option( 'rsnc_ev_last_success' );
		}
	}
}
