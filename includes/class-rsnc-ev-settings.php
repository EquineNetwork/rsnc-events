<?php
/**
 * Settings → RSNC Events: feed address, cache time, optional consumer name, Clear cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSNC_EV_Settings {

	const OPTION = 'rsnc_ev_settings';
	const PAGE   = 'rsnc-events';

	public static function defaults() {
		return array(
			'feed_url'      => 'https://endev.rsnc.us/api/events.php',
			'cache_minutes' => 15,
			'consumer'      => '',
		);
	}

	public static function get( $name ) {
		$saved = get_option( self::OPTION, array() );
		$all   = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		return isset( $all[ $name ] ) ? $all[ $name ] : null;
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_rsnc_ev_clear_cache', array( __CLASS__, 'handle_clear_cache' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( RSNC_EV_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_options_page( 'RSNC Events', 'RSNC Events', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'rsnc-events' ) . '</a>' );
		return $links;
	}

	public static function register() {
		register_setting(
			'rsnc_ev',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();
		$url   = isset( $input['feed_url'] ) ? esc_url_raw( trim( (string) $input['feed_url'] ), array( 'https', 'http' ) ) : '';
		if ( '' !== $url ) {
			$out['feed_url'] = $url;
		} else {
			add_settings_error( self::OPTION, 'rsnc_ev_url', __( 'Please enter the feed address (https://…/api/events.php).', 'rsnc-events' ) );
		}
		$out['cache_minutes'] = isset( $input['cache_minutes'] ) ? min( 1440, max( 1, absint( $input['cache_minutes'] ) ) ) : 15;
		$out['consumer']      = isset( $input['consumer'] ) ? substr( sanitize_text_field( (string) $input['consumer'] ), 0, 100 ) : '';
		// New settings take effect on the next page view.
		RSNC_EV_Feed::clear_cache();
		return $out;
	}

	public static function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'rsnc-events' ) );
		}
		check_admin_referer( 'rsnc_ev_clear_cache' );
		RSNC_EV_Feed::clear_cache();
		wp_safe_redirect( add_query_arg( 'rsnc_ev_cleared', '1', admin_url( 'options-general.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$error   = get_option( 'rsnc_ev_last_error' );
		$success = (int) get_option( 'rsnc_ev_last_success', 0 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RSNC Events', 'rsnc-events' ); ?></h1>
			<?php if ( isset( $_GET['rsnc_ev_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Cache cleared. Pages will show the latest events on their next view.', 'rsnc-events' ); ?></p></div>
			<?php endif; ?>
			<?php settings_errors( self::OPTION ); ?>

			<p><?php esc_html_e( 'Events, flyers and results come from the RSNC admin. Add these shortcodes to pages:', 'rsnc-events' ); ?></p>
			<ul style="list-style:disc;margin-left:20px">
				<li><code>[rsnc_calendar]</code> — <?php esc_html_e( 'upcoming events by month, with flyers', 'rsnc-events' ); ?></li>
				<li><code>[rsnc_results]</code> — <?php esc_html_e( 'this season’s past events and their published results', 'rsnc-events' ); ?></li>
			</ul>

			<form method="post" action="options.php">
				<?php settings_fields( 'rsnc_ev' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rsnc_ev_feed_url"><?php esc_html_e( 'Feed address', 'rsnc-events' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="rsnc_ev_feed_url" name="<?php echo esc_attr( self::OPTION ); ?>[feed_url]" value="<?php echo esc_attr( self::get( 'feed_url' ) ); ?>" required>
							<p class="description"><?php esc_html_e( 'Testing: https://endev.rsnc.us/api/events.php — switch to the live admin address once Craig has deployed the feed.', 'rsnc-events' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rsnc_ev_cache"><?php esc_html_e( 'Check for changes every', 'rsnc-events' ); ?></label></th>
						<td><input type="number" min="1" max="1440" class="small-text" id="rsnc_ev_cache" name="<?php echo esc_attr( self::OPTION ); ?>[cache_minutes]" value="<?php echo esc_attr( self::get( 'cache_minutes' ) ); ?>"> <?php esc_html_e( 'minutes', 'rsnc-events' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="rsnc_ev_consumer"><?php esc_html_e( 'Site name sent to the feed (optional)', 'rsnc-events' ); ?></label></th>
						<td><input type="text" class="regular-text" id="rsnc_ev_consumer" name="<?php echo esc_attr( self::OPTION ); ?>[consumer]" value="<?php echo esc_attr( self::get( 'consumer' ) ); ?>" placeholder="rsnc.us"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Cache', 'rsnc-events' ); ?></h2>
			<p>
				<?php
				if ( $success ) {
					/* translators: %s: time ago */
					echo esc_html( sprintf( __( 'Last read from the feed %s ago.', 'rsnc-events' ), human_time_diff( $success ) ) );
				} else {
					esc_html_e( 'The feed has not been read yet.', 'rsnc-events' );
				}
				if ( is_array( $error ) && ! empty( $error['time'] ) && $error['time'] > $success ) {
					echo ' <strong>' . esc_html( sprintf( /* translators: 1: error, 2: time ago */ __( 'Last attempt failed (%1$s, %2$s ago) — the last good copy is being shown.', 'rsnc-events' ), $error['message'], human_time_diff( (int) $error['time'] ) ) ) . '</strong>';
				}
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rsnc_ev_clear_cache">
				<?php wp_nonce_field( 'rsnc_ev_clear_cache' ); ?>
				<?php submit_button( __( 'Clear cache', 'rsnc-events' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Version', 'rsnc-events' ); ?></h2>
			<p>
				<?php
				/* translators: %s: version number */
				echo esc_html( sprintf( __( 'Installed: %s.', 'rsnc-events' ), RSNC_EV_VERSION ) ) . ' ';
				esc_html_e( 'New versions are published on GitHub and show on the Plugins page as "Update available".', 'rsnc-events' );
				?>
				<a href="<?php echo esc_url( admin_url( 'update-core.php?force-check=1' ) ); ?>"><?php esc_html_e( 'Check for updates now', 'rsnc-events' ); ?></a>
			</p>
		</div>
		<?php
	}
}
