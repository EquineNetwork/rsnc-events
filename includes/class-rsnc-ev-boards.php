<?php
/**
 * Leaderboards: [rsnc_leaderboard type="state|national|international|northeast|hall-of-fame|challenge"].
 *
 * Reads the RSNC admin server's own points lists (the pages rsnc.us used to show in iframes),
 * so the numbers, divisions, frozen and lapsed-member rules are exactly the admin's, and shows
 * them in the site's look. Read only: nothing is ever sent to the admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSNC_EV_Boards {

	/** Where the points lists live: the RSNC admin server. */
	private static function base() {
		return untrailingslashit( (string) apply_filters( 'rsnc_ev_leaderboard_base', 'https://legacy.rsnc.us' ) );
	}

	/**
	 * Each leaderboard: the picker page (for its list of states or countries), the list page,
	 * and the query field the list page takes.
	 */
	private static function types() {
		return array(
			'state'         => array( 'picker' => '/Points/StateRatingsPage_2025.php', 'list' => '/ratingslist/StatePointsList.php', 'field' => 'state', 'label' => __( 'Select a state', 'rsnc-events' ) ),
			'national'      => array( 'picker' => '/Points/NationalRatingsPage_2025.php', 'list' => '/ratingslist/NationalPointsList.php', 'field' => 'state', 'label' => __( 'Select a state', 'rsnc-events' ) ),
			'international' => array( 'picker' => '/Points/InternationalRatingsPage_2025.php', 'list' => '/ratingslist/InternationalPointsList_2025.php', 'field' => 'country', 'label' => __( 'Select a country', 'rsnc-events' ) ),
			'northeast'     => array( 'picker' => '/Points/RegionalRatingsPage.php', 'list' => '/ratingslist/RegionalPointsList.php', 'field' => 'state', 'label' => __( 'Select a state', 'rsnc-events' ) ),
			'hall-of-fame'  => array( 'page' => '/wp-content/themes/RSNC/halloffame.php' ),
			'challenge'     => array( 'page' => '/wp-content/themes/RSNC/challengeofchampions.php' ),
		);
	}

	public static function init() {
		add_shortcode( 'rsnc_leaderboard', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'rsnc_awarded', array( __CLASS__, 'awarded' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
	}

	/** GET a page from the admin server, cached (15 minutes; a failure is remembered for 1 minute). */
	private static function fetch( $path, array $query = array() ) {
		$url = self::base() . $path . ( $query ? '?' . http_build_query( $query ) : '' );
		$key = 'rsnc_ev_lb_' . md5( $url );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return is_array( $hit ) ? null : (string) $hit;
		}
		$res = wp_safe_remote_get( $url, array( 'timeout' => 20, 'redirection' => 2 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			set_transient( $key, array( 'error' ), MINUTE_IN_SECONDS );
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $res );
		set_transient( $key, $body, 15 * MINUTE_IN_SECONDS );
		return $body;
	}

	private static function text( $html ) {
		$t = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $t ) );
	}

	/** The picker page's <option>s (value => name), without the empty "Select..." one. */
	private static function options( $type ) {
		$cfg  = self::types()[ $type ];
		$html = self::fetch( $cfg['picker'] );
		if ( null === $html || ! preg_match( '~<select[^>]*name="' . preg_quote( $cfg['field'], '~' ) . '"[^>]*>(.*?)</select>~is', $html, $m ) ) {
			return array();
		}
		$out = array();
		if ( preg_match_all( '~<option[^>]*value="([^"]*)"[^>]*>(.*?)(?=<option|$)~is', $m[1], $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $o ) {
				$value = trim( html_entity_decode( $o[1], ENT_QUOTES, 'UTF-8' ) );
				if ( '' !== $value ) {
					$out[ $value ] = self::text( $o[2] );
				}
			}
		}
		return $out;
	}

	/**
	 * A points list → sections of rows. Each section: title, columns, rows; each row: cells,
	 * lapsed (points shown red in the admin), frozen, and the "See Status" address.
	 */
	private static function parse_table( $html ) {
		$sections = array();
		$columns  = array();
		$current  = null;
		if ( ! preg_match_all( '~<tr\b([^>]*)>(.*?)</tr>~is', $html, $rows, PREG_SET_ORDER ) ) {
			return $sections;
		}
		foreach ( $rows as $tr ) {
			$inner = $tr[2];
			if ( preg_match( '~<h3[^>]*>(.*?)</h3>~is', $inner, $h ) ) {
				$sections[] = array( 'title' => self::text( $h[1] ), 'columns' => $columns, 'rows' => array() );
				$current    = count( $sections ) - 1;
				continue;
			}
			preg_match_all( '~<td\b[^>]*>(.*?)</td>~is', $inner, $tds );
			$cells = array_map( array( __CLASS__, 'text' ), $tds[1] );
			if ( ! $cells ) {
				continue;
			}
			if ( 'Last Name' === $cells[0] ) {
				$columns = $cells;
				if ( null !== $current && ! $sections[ $current ]['rows'] ) {
					$sections[ $current ]['columns'] = $cells;
				}
				continue;
			}
			if ( null === $current ) {
				$sections[] = array( 'title' => '', 'columns' => $columns, 'rows' => array() );
				$current    = count( $sections ) - 1;
			}
			$status = '';
			if ( preg_match( '~data-src="([^"]+)"~i', $inner, $s ) ) {
				$status = html_entity_decode( $s[1], ENT_QUOTES, 'UTF-8' );
			}
			$sections[ $current ]['rows'][] = array(
				'cells'  => $cells,
				'lapsed' => (bool) preg_match( '~color:\s*red~i', $inner ),
				'frozen' => (bool) preg_match( '~#0000AA~i', $tr[1] ) || in_array( 'Frozen', $cells, true ),
				'status' => $status,
			);
		}
		return array_values(
			array_filter(
				$sections,
				static function ( $s ) {
					return ! empty( $s['rows'] );
				}
			)
		);
	}

	/** Hall of Fame / Challenge of the Champions: headings, then rows of floated <div> cells. */
	private static function parse_divs( $html, $per_row ) {
		$sections = array();
		$cells    = array();
		$current  = null;
		$top      = false;
		preg_match_all( '~<(h3|legend)\b[^>]*>(.*?)</\1>|<div style="width:\d+%;([^"]*)">((?:(?!<div|<h3|<legend).)*?)</div>|(FACE OFF ZONE)~is', $html, $tokens, PREG_SET_ORDER );
		foreach ( $tokens as $t ) {
			if ( ! empty( $t[1] ) ) {
				$sections[] = array( 'title' => self::text( $t[2] ), 'rows' => array() );
				$current    = count( $sections ) - 1;
				$cells      = array();
				continue;
			}
			if ( ! empty( $t[5] ) ) {
				if ( null !== $current ) {
					$sections[ $current ]['rows'][] = array( 'divider' => true );
				}
				continue;
			}
			if ( null === $current ) {
				continue;
			}
			if ( ! $cells ) {
				$top = (bool) preg_match( '~font-weight:\s*bold~i', $t[3] );
			}
			$cells[] = self::text( $t[4] );
			if ( count( $cells ) === $per_row ) {
				$sections[ $current ]['rows'][] = array( 'cells' => $cells, 'top' => $top );
				$cells = array();
			}
		}
		return $sections;
	}

	public static function shortcode( $atts ) {
		RSNC_EV_Render::enqueue_assets();
		$atts  = shortcode_atts( array( 'type' => 'state' ), $atts, 'rsnc_leaderboard' );
		$type  = sanitize_key( str_replace( ' ', '-', (string) $atts['type'] ) );
		$types = self::types();
		if ( ! isset( $types[ $type ] ) ) {
			return '';
		}
		$cfg = $types[ $type ];
		if ( isset( $cfg['page'] ) ) {
			return self::render_static( $type, $cfg );
		}
		return self::render_list( $type, $cfg );
	}

	private static function unavailable() {
		return '<p class="rsnc-ev-empty">' . esc_html__( 'The leaderboard is not available right now. Please check back soon.', 'rsnc-events' ) . '</p>';
	}

	/** State, National, International, Northeast Circuit: a picker, then the chosen list. */
	private static function render_list( $type, array $cfg ) {
		$options = self::options( $type );
		$param   = 'rsnc_lb';
		$chosen  = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter
		if ( '' !== $chosen && ! isset( $options[ $chosen ] ) ) {
			$chosen = '';
		}
		ob_start();
		echo '<div class="rsnc-ev rsnc-ev-lb rsnc-ev-lb-' . esc_attr( $type ) . '" id="rsnc-ev-lb">';
		echo '<form class="rsnc-ev-lb-pick" method="get" action="#rsnc-ev-lb">';
		echo '<label class="rsnc-ev-sr" for="rsnc-ev-lb-select">' . esc_html( $cfg['label'] ) . '</label>';
		echo '<div class="rsnc-ev-field"><select id="rsnc-ev-lb-select" name="' . esc_attr( $param ) . '" data-rsnc-ev-auto>';
		echo '<option value="">' . esc_html( $cfg['label'] ) . '</option>';
		foreach ( $options as $value => $name ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $chosen, $value, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select></div><noscript><button type="submit" class="rsnc-ev-find">' . esc_html__( 'Show', 'rsnc-events' ) . '</button></noscript></form>';

		if ( ! $options ) {
			echo self::unavailable(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( '' !== $chosen ) {
			$html = self::fetch( $cfg['list'], array( $cfg['field'] => $chosen ) );
			if ( null === $html ) {
				echo self::unavailable(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				$sections = 'northeast' === $type ? self::parse_divs( $html, 4 ) : self::parse_table( $html );
				$sections = array_values(
					array_filter(
						$sections,
						static function ( $s ) {
							return ! empty( $s['rows'] );
						}
					)
				);
				if ( ! $sections ) {
					echo '<p class="rsnc-ev-empty">' . esc_html( sprintf( __( 'No points posted for %s yet.', 'rsnc-events' ), $options[ $chosen ] ) ) . '</p>';
				} else {
					echo '<h2 class="rsnc-ev-lb-title">' . esc_html( $options[ $chosen ] ) . '</h2>';
					if ( 'northeast' === $type ) {
						echo '<p class="rsnc-ev-lb-note">' . esc_html__( 'Northeast Circuit shows ridden this season. Five or more shows qualifies for the Northeast Circuit Finals.', 'rsnc-events' ) . '</p>';
						foreach ( $sections as $section ) {
							self::render_northeast( $section );
						}
					} else {
						echo '<p class="rsnc-ev-lb-note">' . esc_html__( 'Points in red: we are missing information or the membership has lapsed. Call the RSNC office at 970-897-2901 to bring it current.', 'rsnc-events' ) . '</p>';
						foreach ( $sections as $section ) {
							self::render_section( $section );
						}
					}
					self::status_dialog();
				}
			}
		}
		echo '</div>';
		return ob_get_clean();
	}

	/** One division table. Columns come from the admin's own header row. */
	private static function render_section( array $section ) {
		$columns = $section['columns'];
		$name_at = array_search( 'Last Name', $columns, true );
		$first   = array_search( 'First Name', $columns, true );
		$id_at   = array_search( 'Member #', $columns, true );
		$pts_at  = null;
		foreach ( $columns as $i => $c ) {
			if ( false !== stripos( $c, 'Points' ) ) {
				$pts_at = $i;
			}
		}
		$extra = array();
		foreach ( $columns as $i => $c ) {
			if ( $i !== $name_at && $i !== $first && $i !== $id_at && $i !== $pts_at && '' !== $c && false === stripos( $c, 'Status' ) ) {
				$extra[ $i ] = $c;
			}
		}
		echo '<section class="rsnc-ev-lb-section">';
		if ( '' !== $section['title'] ) {
			echo '<h3 class="rsnc-ev-lb-division">' . esc_html( $section['title'] ) . '</h3>';
		}
		echo '<table class="rsnc-ev-lb-table"><thead><tr><th class="rsnc-ev-lb-rank">#</th><th>' . esc_html__( 'Name', 'rsnc-events' ) . '</th><th class="rsnc-ev-lb-id">' . esc_html__( 'Member #', 'rsnc-events' ) . '</th>';
		foreach ( $extra as $c ) {
			echo '<th class="rsnc-ev-lb-extra">' . esc_html( $c ) . '</th>';
		}
		echo '<th class="rsnc-ev-lb-pts">' . esc_html__( 'Points', 'rsnc-events' ) . '</th><th class="rsnc-ev-lb-st"><span class="rsnc-ev-sr">' . esc_html__( 'Status', 'rsnc-events' ) . '</span></th></tr></thead><tbody>';
		$rank = 0;
		foreach ( $section['rows'] as $row ) {
			++$rank;
			$c    = $row['cells'];
			$name = trim( ( false !== $first ? ( $c[ $first ] ?? '' ) : '' ) . ' ' . ( false !== $name_at ? ( $c[ $name_at ] ?? '' ) : '' ) );
			$cls  = ( $row['lapsed'] ? ' rsnc-ev-lb-lapsed' : '' ) . ( $row['frozen'] ? ' rsnc-ev-lb-frozen' : '' );
			echo '<tr class="' . esc_attr( trim( $cls ) ) . '"><td class="rsnc-ev-lb-rank">' . (int) $rank . '</td>';
			echo '<td class="rsnc-ev-lb-name">' . esc_html( $name ) . ( $row['frozen'] ? ' <span class="rsnc-ev-lb-tag">' . esc_html__( 'Frozen', 'rsnc-events' ) . '</span>' : '' ) . '</td>';
			echo '<td class="rsnc-ev-lb-id">' . esc_html( false !== $id_at ? ( $c[ $id_at ] ?? '' ) : '' ) . '</td>';
			foreach ( $extra as $i => $label ) {
				echo '<td class="rsnc-ev-lb-extra">' . esc_html( $c[ $i ] ?? '' ) . '</td>';
			}
			echo '<td class="rsnc-ev-lb-pts">' . esc_html( null !== $pts_at ? ( $c[ $pts_at ] ?? '' ) : '' ) . '</td><td class="rsnc-ev-lb-st">';
			if ( '' !== $row['status'] ) {
				$url = self::base() . '/ratingslist/' . ltrim( $row['status'], '/' );
				echo '<a class="rsnc-ev-lb-status" href="' . esc_url( $url ) . '" target="_blank" rel="noopener" data-rsnc-status="' . esc_attr( ltrim( $row['status'], '/' ) ) . '">' . esc_html__( 'See status', 'rsnc-events' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/** Northeast Circuit: name, shows ridden, Qualified. */
	private static function render_northeast( array $section ) {
		echo '<section class="rsnc-ev-lb-section"><h3 class="rsnc-ev-lb-division">' . esc_html( $section['title'] ) . '</h3>';
		echo '<table class="rsnc-ev-lb-table"><thead><tr><th class="rsnc-ev-lb-rank">#</th><th>' . esc_html__( 'Name', 'rsnc-events' ) . '</th><th class="rsnc-ev-lb-pts">' . esc_html__( 'Shows', 'rsnc-events' ) . '</th><th class="rsnc-ev-lb-st">' . esc_html__( 'Status', 'rsnc-events' ) . '</th></tr></thead><tbody>';
		$rank = 0;
		foreach ( $section['rows'] as $row ) {
			if ( empty( $row['cells'] ) ) {
				continue;
			}
			$c = $row['cells'];
			echo '<tr><td class="rsnc-ev-lb-rank">' . (int) ++$rank . '</td><td class="rsnc-ev-lb-name">' . esc_html( trim( $c[1] . ' ' . $c[0] ) ) . '</td><td class="rsnc-ev-lb-pts">' . esc_html( $c[2] ) . '</td><td class="rsnc-ev-lb-st">' . ( '' !== $c[3] ? '<span class="rsnc-ev-lb-tag rsnc-ev-lb-qual">' . esc_html( $c[3] ) . '</span>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	/** The qualification status opens in a pop-up on the page (a new tab without JavaScript). */
	private static function status_dialog() {
		echo '<dialog class="rsnc-ev-lb-dialog" id="rsnc-ev-lb-dialog" data-rest="' . esc_url( rest_url( 'rsnc-events/v1/status' ) ) . '"><form method="dialog"><button class="rsnc-ev-lb-close" aria-label="' . esc_attr__( 'Close', 'rsnc-events' ) . '">&times;</button></form><div class="rsnc-ev-lb-status-body" aria-live="polite"></div></dialog>';
	}

	/** GET /wp-json/rsnc-events/v1/status?src=qualification.php?... : a rider's status as data. */
	public static function rest() {
		register_rest_route(
			'rsnc-events/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'status' ),
			)
		);
	}

	public static function status( $request ) {
		$src = (string) $request->get_param( 'src' );
		// Only the admin's own status pages, with their usual query.
		if ( ! preg_match( '~^(qualification(?:_state)?\.php)\?([A-Za-z0-9_=&%.+-]{1,300})$~', $src, $m ) ) {
			return new WP_Error( 'rsnc_bad', 'Not found', array( 'status' => 404 ) );
		}
		parse_str( $m[2], $q );
		$html = self::fetch( '/ratingslist/' . $m[1], array_map( 'strval', $q ) );
		if ( null === $html ) {
			return new WP_Error( 'rsnc_down', 'Unavailable', array( 'status' => 503 ) );
		}
		$name  = preg_match( '~<h2[^>]*>(.*?)</h2>~is', $html, $h ) ? self::text( $h[1] ) : '';
		$items = array();
		if ( preg_match_all( '~<strong>(.*?)</strong>.*?<span[^>]*>(.*?)</span>~is', $html, $all, PREG_SET_ORDER ) ) {
			foreach ( $all as $it ) {
				$value   = self::text( $it[2] );
				$items[] = array(
					'label'     => ltrim( self::text( $it[1] ), '# ' ),
					'value'     => '' === $value ? '0' : $value,
					'qualified' => 0 === strcasecmp( $value, 'Qualified' ),
				);
			}
		}
		return rest_ensure_response( array( 'name' => $name, 'items' => $items ) );
	}

	/** Hall of Fame and Challenge of the Champions: no picker, ranked earnings lists. */
	private static function render_static( $type, array $cfg ) {
		$html = self::fetch( $cfg['page'] );
		ob_start();
		echo '<div class="rsnc-ev rsnc-ev-lb rsnc-ev-lb-' . esc_attr( $type ) . '">';
		$sections = null === $html ? array() : self::parse_divs( $html, 'challenge' === $type ? 4 : 3 );
		if ( ! $sections ) {
			echo self::unavailable(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		foreach ( $sections as $section ) {
			if ( ! $section['rows'] ) {
				continue;
			}
			echo '<section class="rsnc-ev-lb-section">';
			echo '<h3 class="rsnc-ev-lb-division">' . esc_html( $section['title'] ) . '</h3>';
			echo '<table class="rsnc-ev-lb-table"><thead><tr><th class="rsnc-ev-lb-rank">#</th><th>' . esc_html__( 'Name', 'rsnc-events' ) . '</th><th class="rsnc-ev-lb-pts">' . esc_html__( 'Earnings', 'rsnc-events' ) . '</th></tr></thead><tbody>';
			$rank = 0;
			foreach ( $section['rows'] as $row ) {
				if ( ! empty( $row['divider'] ) ) {
					echo '<tr class="rsnc-ev-lb-faceoff"><td colspan="3">' . esc_html__( 'Face Off Zone', 'rsnc-events' ) . '</td></tr>';
					continue;
				}
				$c = $row['cells'];
				if ( 4 === count( $c ) ) {
					$place = $c[0];
					$name  = trim( $c[1] . ' ' . $c[2] );
					$money = $c[3];
				} else {
					$place = (string) ++$rank;
					$name  = trim( $c[0] . ' ' . $c[1] );
					$money = $c[2];
				}
				echo '<tr' . ( ! empty( $row['top'] ) && 'challenge' === $type ? ' class="rsnc-ev-lb-top"' : '' ) . '><td class="rsnc-ev-lb-rank">' . esc_html( $place ) . '</td><td class="rsnc-ev-lb-name">' . esc_html( $name ) . '</td><td class="rsnc-ev-lb-pts">' . esc_html( $money ) . '</td></tr>';
			}
			echo '</tbody></table></section>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * [rsnc_awarded]: total paid out this season ("$1,699,394"), from the admin's api/wins (beside
	 * the events feed; a plain number). Checked every 5 minutes; if the admin can't be reached,
	 * the last total it gave is shown.
	 */
	public static function awarded( $atts ) {
		$atts  = shortcode_atts( array( 'dollar' => 'yes' ), $atts, 'rsnc_awarded' );
		$total = get_transient( 'rsnc_ev_awarded' );
		if ( false === $total ) {
			$url  = preg_replace( '~/events\.php(\?.*)?$~', '/wins', (string) RSNC_EV_Settings::get( 'feed_url' ) );
			$res  = $url ? wp_safe_remote_get( $url, array( 'timeout' => 8, 'headers' => array( 'Accept' => 'text/plain' ) ) ) : null;
			$body = ( $res && ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) ? trim( (string) wp_remote_retrieve_body( $res ) ) : '';
			if ( preg_match( '/^\d+(\.\d+)?$/', $body ) ) {
				$total = (float) $body;
				update_option( 'rsnc_ev_awarded_last', $total, false );
				set_transient( 'rsnc_ev_awarded', $total, 5 * MINUTE_IN_SECONDS );
			} else {
				$total = get_option( 'rsnc_ev_awarded_last', '' );
				set_transient( 'rsnc_ev_awarded', $total, 2 * MINUTE_IN_SECONDS );
			}
		}
		if ( '' === $total || null === $total ) {
			return '';
		}
		return ( 'no' === strtolower( (string) $atts['dollar'] ) ? '' : '$' ) . number_format( round( (float) $total ) );
	}
}
