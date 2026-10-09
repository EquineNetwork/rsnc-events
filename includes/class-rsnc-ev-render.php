<?php
/**
 * The [rsnc_calendar] and [rsnc_results] shortcodes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RSNC_EV_Render {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( 'rsnc_calendar', array( __CLASS__, 'calendar' ) );
		add_shortcode( 'rsnc_results', array( __CLASS__, 'results' ) );
	}

	public static function register_assets() {
		// Uses the theme's own fonts, so it looks like the rest of the site.
		wp_register_style( 'rsnc-ev', RSNC_EV_URL . 'assets/rsnc-events.css', array(), RSNC_EV_VERSION );
		wp_register_script( 'rsnc-ev', RSNC_EV_URL . 'assets/rsnc-events.js', array(), RSNC_EV_VERSION, true );
	}

	private static function enqueue() {
		if ( ! wp_style_is( 'rsnc-ev', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'rsnc-ev' );
		wp_enqueue_script( 'rsnc-ev' );
	}

	/** Today's date on this site (Y-m-d). */
	private static function today() {
		return current_time( 'Y-m-d' );
	}

	private static function ymd( $iso ) {
		return substr( (string) $iso, 0, 10 );
	}

	/** "Sat, Nov 14, 2026" / "Nov 14–15, 2026" / "Nov 30 – Dec 1, 2026". */
	private static function date_range( $start, $end ) {
		$s = strtotime( self::ymd( $start ) . ' 12:00:00' );
		$e = strtotime( self::ymd( $end ? $end : $start ) . ' 12:00:00' );
		if ( ! $s ) {
			return '';
		}
		if ( ! $e || $e <= $s ) {
			return date_i18n( 'D, M j, Y', $s );
		}
		if ( gmdate( 'Y-m', $s ) === gmdate( 'Y-m', $e ) ) {
			return date_i18n( 'M j', $s ) . '–' . date_i18n( 'j, Y', $e );
		}
		if ( gmdate( 'Y', $s ) === gmdate( 'Y', $e ) ) {
			return date_i18n( 'M j', $s ) . ' – ' . date_i18n( 'M j, Y', $e );
		}
		return date_i18n( 'M j, Y', $s ) . ' – ' . date_i18n( 'M j, Y', $e );
	}

	/** "FRI / 9 / OCT" block, like the site's event list. */
	private static function date_block( $start ) {
		$t = strtotime( self::ymd( $start ) . ' 12:00:00' );
		if ( ! $t ) {
			return;
		}
		echo '<div class="rsnc-ev-dateblock" aria-hidden="true"><span>' . esc_html( date_i18n( 'D', $t ) ) . '</span><b>' . esc_html( date_i18n( 'j', $t ) ) . '</b><span>' . esc_html( date_i18n( 'M', $t ) ) . '</span></div>';
	}

	private static function day_label( $ymd ) {
		$t = strtotime( $ymd . ' 12:00:00' );
		return $t ? date_i18n( 'D, M j', $t ) : $ymd;
	}

	private static function unavailable() {
		return '<div class="rsnc-ev"><p class="rsnc-ev-empty">' . esc_html__( 'Events are not available right now. Please check back soon.', 'rsnc-events' ) . '</p></div>';
	}

	/* ----------------------------------------------------------------- calendar */

	public static function calendar( $atts ) {
		self::enqueue();
		$atts   = shortcode_atts( array( 'per_page' => 10 ), $atts, 'rsnc_calendar' );
		$events = RSNC_EV_Feed::events();
		if ( null === $events ) {
			return self::unavailable();
		}
		$today    = self::today();
		$upcoming = array_values(
			array_filter(
				$events,
				static function ( $e ) use ( $today ) {
					return self::ymd( $e['endDate'] ?? '' ) >= $today;
				}
			)
		);
		usort(
			$upcoming,
			static function ( $a, $b ) {
				return strcmp( (string) $a['startDate'], (string) $b['startDate'] ) ?: ( (int) $a['eventUID'] <=> (int) $b['eventUID'] );
			}
		);
		$f     = self::request_filters( false );
		$shown = self::apply_filters( $upcoming, $f );
		list( $page_events, $page, $pages ) = self::paginate( $shown, $f['page'], (int) $atts['per_page'] );

		ob_start();
		echo '<div class="rsnc-ev rsnc-ev-calendar" id="rsnc-ev-top">';
		self::filter_form( $upcoming, $f, false, count( $shown ) );
		if ( ! $upcoming ) {
			echo '<p class="rsnc-ev-empty">' . esc_html__( 'No upcoming events are scheduled yet. Please check back soon.', 'rsnc-events' ) . '</p>';
		} elseif ( ! $shown ) {
			echo '<p class="rsnc-ev-empty">' . esc_html__( 'No events match your search.', 'rsnc-events' ) . '</p>';
		}
		$month = '';
		foreach ( $page_events as $event ) {
			$m = substr( self::ymd( $event['startDate'] ), 0, 7 );
			if ( $m !== $month ) {
				if ( '' !== $month ) {
					echo '</div></section>';
				}
				$month = $m;
				echo '<section class="rsnc-ev-month"><h2 class="rsnc-ev-month-title">' . esc_html( date_i18n( 'F Y', strtotime( $m . '-15' ) ) ) . '</h2><div class="rsnc-ev-list">';
			}
			self::event_card( $event );
		}
		if ( '' !== $month ) {
			echo '</div></section>';
		}
		self::pager( $page, $pages, $f );
		echo '</div>';
		return ob_get_clean();
	}

	/* ------------------------------------------------------- filters and pages */

	/** The visitor's choices from the address (all prefixed rsnc_ so WordPress's own words are left alone). */
	private static function request_filters( $with_season ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters on a public page.
		$get = static function ( $key ) {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		$f = array(
			'q'        => trim( $get( 'rsnc_q' ) ),
			'state'    => strtoupper( $get( 'rsnc_state' ) ),
			'producer' => $get( 'rsnc_producer' ),
			'month'    => preg_match( '/^\d{4}-\d{2}$/', $get( 'rsnc_month' ) ) ? $get( 'rsnc_month' ) : '',
			'venue'    => $get( 'rsnc_venue' ),
			'page'     => max( 1, absint( $get( 'rsnc_page' ) ) ),
			'season'   => '',
		);
		if ( $with_season ) {
			$season = $get( 'rsnc_season' );
			$f['season'] = in_array( $season, wp_list_pluck( self::seasons(), 'key' ), true ) ? $season : '';
		}
		// phpcs:enable
		return $f;
	}

	/** RSNC seasons run Jun 15 to Jun 14: this one and the four before it. Key '' = this season. */
	private static function seasons() {
		$today = self::today();
		$year  = (int) substr( $today, 0, 4 );
		$start = substr( $today, 5 ) >= '06-15' ? $year : $year - 1;
		$list  = array();
		for ( $y = $start; $y > $start - 5; $y-- ) {
			$list[] = array(
				'key'   => $y === $start ? '' : (string) $y,
				'label' => $y === $start ? __( 'This season', 'rsnc-events' ) : $y . '–' . substr( (string) ( $y + 1 ), 2 ) . ' ' . __( 'season', 'rsnc-events' ),
				'from'  => $y . '-06-15',
				'to'    => ( $y + 1 ) . '-06-14',
			);
		}
		return $list;
	}

	private static function apply_filters( array $events, array $f ) {
		return array_values(
			array_filter(
				$events,
				static function ( $e ) use ( $f ) {
					if ( '' !== $f['state'] && strtoupper( (string) ( $e['arenaState'] ?? '' ) ) !== $f['state'] ) {
						return false;
					}
					if ( '' !== $f['producer'] && 0 !== strcasecmp( trim( (string) ( $e['producerName'] ?? '' ) ), $f['producer'] ) ) {
						return false;
					}
					if ( '' !== $f['venue'] && 0 !== strcasecmp( trim( (string) ( $e['arenaName'] ?? '' ) ), $f['venue'] ) ) {
						return false;
					}
					if ( '' !== $f['month'] ) {
						$start = substr( self::ymd( $e['startDate'] ?? '' ), 0, 7 );
						$end   = substr( self::ymd( $e['endDate'] ?? '' ), 0, 7 );
						if ( $f['month'] < $start || $f['month'] > ( $end ? $end : $start ) ) {
							return false;
						}
					}
					if ( '' !== $f['q'] ) {
						$hay = implode( ' ', array( $e['eventName'] ?? '', $e['arenaName'] ?? '', $e['arenaCity'] ?? '', $e['arenaState'] ?? '', $e['producerName'] ?? '' ) );
						if ( false === stripos( $hay, $f['q'] ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/** Returns array( this page's events, page number, number of pages ). */
	private static function paginate( array $events, $page, $per_page ) {
		$per_page = max( 1, $per_page );
		$pages    = max( 1, (int) ceil( count( $events ) / $per_page ) );
		$page     = min( max( 1, (int) $page ), $pages );
		return array( array_slice( $events, ( $page - 1 ) * $per_page, $per_page ), $page, $pages );
	}

	/** The current page's address with these filters (empty ones left out). */
	private static function link( array $f, $page ) {
		$args = array(
			'rsnc_q'        => $f['q'],
			'rsnc_state'    => $f['state'],
			'rsnc_producer' => $f['producer'],
			'rsnc_month'    => $f['month'],
			'rsnc_venue'    => $f['venue'],
			'rsnc_season'   => $f['season'],
			'rsnc_page'     => $page > 1 ? $page : '',
		);
		$base = remove_query_arg( array_keys( $args ) );
		return add_query_arg( array_map( 'rawurlencode', array_filter( $args, 'strlen' ) ), $base ) . '#rsnc-ev-top';
	}

	/** Search box and drop-downs. Options come from the events being listed. */
	private static function filter_form( array $events, array $f, $with_season, $count ) {
		$states = array();
		$producers = array();
		$months = array();
		$venues = array();
		foreach ( $events as $e ) {
			if ( ! empty( $e['arenaName'] ) ) {
				$venues[ trim( $e['arenaName'] ) ] = true;
			}
			if ( ! empty( $e['arenaState'] ) ) {
				$states[ strtoupper( $e['arenaState'] ) ] = true;
			}
			if ( ! empty( $e['producerName'] ) ) {
				$producers[ trim( $e['producerName'] ) ] = true;
			}
			$m = substr( self::ymd( $e['startDate'] ?? '' ), 0, 7 );
			if ( $m ) {
				$months[ $m ] = true;
			}
		}
		ksort( $states );
		uksort( $producers, 'strcasecmp' );
		uksort( $venues, 'strcasecmp' );
		ksort( $months );
		if ( $with_season ) {
			krsort( $months );
		}
		$filtered = '' !== $f['q'] || '' !== $f['state'] || '' !== $f['producer'] || '' !== $f['month'] || '' !== $f['venue'] || '' !== $f['season'];
		$uid      = $with_season ? 'r' : 'c';

		echo '<form class="rsnc-ev-filters" method="get" action="' . esc_url( remove_query_arg( array( 'rsnc_q', 'rsnc_state', 'rsnc_producer', 'rsnc_month', 'rsnc_venue', 'rsnc_season', 'rsnc_page' ) ) ) . '#rsnc-ev-top" role="search">';
		// Keep any other address parameters (e.g. page_id on sites without pretty links).
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 0 !== strpos( (string) $key, 'rsnc_' ) && is_string( $value ) ) {
				echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( wp_unslash( $value ) ) . '">';
			}
		}
		echo '<div class="rsnc-ev-field rsnc-ev-field-q"><label class="rsnc-ev-sr" for="rsnc-ev-q-' . $uid . '">' . esc_html__( 'Search', 'rsnc-events' ) . '</label>';
		echo '<input type="search" id="rsnc-ev-q-' . $uid . '" name="rsnc_q" value="' . esc_attr( $f['q'] ) . '" placeholder="' . esc_attr__( 'Search events', 'rsnc-events' ) . '"></div>';
		if ( $with_season ) {
			echo '<div class="rsnc-ev-field"><label class="rsnc-ev-sr" for="rsnc-ev-season">' . esc_html__( 'Season', 'rsnc-events' ) . '</label><select id="rsnc-ev-season" name="rsnc_season" data-rsnc-ev-auto>';
			foreach ( self::seasons() as $season ) {
				echo '<option value="' . esc_attr( $season['key'] ) . '"' . selected( $f['season'], $season['key'], false ) . '>' . esc_html( $season['label'] ) . '</option>';
			}
			echo '</select></div>';
		}
		echo '<div class="rsnc-ev-field"><label class="rsnc-ev-sr" for="rsnc-ev-month-' . $uid . '">' . esc_html__( 'Month', 'rsnc-events' ) . '</label><select id="rsnc-ev-month-' . $uid . '" name="rsnc_month" data-rsnc-ev-auto><option value="">' . esc_html__( 'All months', 'rsnc-events' ) . '</option>';
		foreach ( array_keys( $months ) as $m ) {
			echo '<option value="' . esc_attr( $m ) . '"' . selected( $f['month'], $m, false ) . '>' . esc_html( date_i18n( 'F Y', strtotime( $m . '-15' ) ) ) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="rsnc-ev-field"><label class="rsnc-ev-sr" for="rsnc-ev-state-' . $uid . '">' . esc_html__( 'State', 'rsnc-events' ) . '</label><select id="rsnc-ev-state-' . $uid . '" name="rsnc_state" data-rsnc-ev-auto><option value="">' . esc_html__( 'All states', 'rsnc-events' ) . '</option>';
		foreach ( array_keys( $states ) as $st ) {
			echo '<option value="' . esc_attr( $st ) . '"' . selected( $f['state'], $st, false ) . '>' . esc_html( $st ) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="rsnc-ev-field"><label class="rsnc-ev-sr" for="rsnc-ev-producer-' . $uid . '">' . esc_html__( 'Producer', 'rsnc-events' ) . '</label><select id="rsnc-ev-producer-' . $uid . '" name="rsnc_producer" data-rsnc-ev-auto><option value="">' . esc_html__( 'All producers', 'rsnc-events' ) . '</option>';
		foreach ( array_keys( $producers ) as $pr ) {
			echo '<option value="' . esc_attr( $pr ) . '"' . selected( 0 === strcasecmp( $f['producer'], $pr ), true, false ) . '>' . esc_html( $pr ) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="rsnc-ev-field"><label class="rsnc-ev-sr" for="rsnc-ev-venue-' . $uid . '">' . esc_html__( 'Venue', 'rsnc-events' ) . '</label><select id="rsnc-ev-venue-' . $uid . '" name="rsnc_venue" data-rsnc-ev-auto><option value="">' . esc_html__( 'All venues', 'rsnc-events' ) . '</option>';
		foreach ( array_keys( $venues ) as $vn ) {
			echo '<option value="' . esc_attr( $vn ) . '"' . selected( 0 === strcasecmp( $f['venue'], $vn ), true, false ) . '>' . esc_html( $vn ) . '</option>';
		}
		echo '</select></div>';
		echo '<div class="rsnc-ev-filter-actions"><button type="submit" class="rsnc-ev-find">' . esc_html__( 'Find Events', 'rsnc-events' ) . '</button>';
		if ( $filtered ) {
			echo '<a class="rsnc-ev-clear" href="' . esc_url( remove_query_arg( array( 'rsnc_q', 'rsnc_state', 'rsnc_producer', 'rsnc_month', 'rsnc_venue', 'rsnc_season', 'rsnc_page' ) ) ) . '#rsnc-ev-top">' . esc_html__( 'Clear', 'rsnc-events' ) . '</a>';
		}
		echo '</div></form>';
		/* translators: %d: number of events */
		echo '<p class="rsnc-ev-count-line" aria-live="polite">' . esc_html( sprintf( _n( '%d event', '%d events', $count, 'rsnc-events' ), $count ) ) . '</p>';
	}

	/** Previous / page numbers / Next. */
	private static function pager( $page, $pages, array $f ) {
		if ( $pages < 2 ) {
			return;
		}
		echo '<nav class="rsnc-ev-pager" aria-label="' . esc_attr__( 'Pages', 'rsnc-events' ) . '">';
		if ( $page > 1 ) {
			echo '<a class="rsnc-ev-page rsnc-ev-page-step" href="' . esc_url( self::link( $f, $page - 1 ) ) . '" rel="prev">' . esc_html__( 'Previous', 'rsnc-events' ) . '</a>';
		}
		$last_shown = 0;
		for ( $i = 1; $i <= $pages; $i++ ) {
			// First, last and the two pages either side of this one; gaps become "…".
			if ( 1 !== $i && $pages !== $i && abs( $i - $page ) > 2 ) {
				continue;
			}
			if ( $last_shown && $i - $last_shown > 1 ) {
				echo '<span class="rsnc-ev-page-gap" aria-hidden="true">&hellip;</span>';
			}
			if ( $i === $page ) {
				echo '<span class="rsnc-ev-page is-current" aria-current="page">' . (int) $i . '</span>';
			} else {
				/* translators: %d: page number */
				echo '<a class="rsnc-ev-page" href="' . esc_url( self::link( $f, $i ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Page %d', 'rsnc-events' ), $i ) ) . '">' . (int) $i . '</a>';
			}
			$last_shown = $i;
		}
		if ( $page < $pages ) {
			echo '<a class="rsnc-ev-page rsnc-ev-page-step" href="' . esc_url( self::link( $f, $page + 1 ) ) . '" rel="next">' . esc_html__( 'Next', 'rsnc-events' ) . '</a>';
		}
		echo '</nav>';
	}

	private static function event_card( array $event ) {
		$cancelled = ! empty( $event['cancelled'] );
		$days      = isset( $event['days'] ) && is_array( $event['days'] ) ? count( $event['days'] ) : 1;
		$flyers    = isset( $event['flyers'] ) && is_array( $event['flyers'] ) ? $event['flyers'] : array();
		$name_id   = 'rsnc-ev-name-' . (int) $event['eventUID'];

		echo '<article class="rsnc-ev-card' . ( $cancelled ? ' rsnc-ev-is-cancelled' : '' ) . ( $flyers ? ' rsnc-ev-has-flyers' : '' ) . '" aria-labelledby="' . esc_attr( $name_id ) . '">';
		self::date_block( $event['startDate'] );
		echo '<div class="rsnc-ev-body">';
		echo '<p class="rsnc-ev-when">' . esc_html( self::date_range( $event['startDate'], $event['endDate'] ) );
		if ( $days > 1 ) {
			/* translators: %d: number of days */
			echo ' <span class="rsnc-ev-days">' . esc_html( sprintf( _n( '%d day', '%d days', $days, 'rsnc-events' ), $days ) ) . '</span>';
		}
		echo '</p>';
		echo '<h3 class="rsnc-ev-name" id="' . esc_attr( $name_id ) . '">' . esc_html( $event['eventName'] ) . '</h3>';
		if ( $cancelled ) {
			echo '<p class="rsnc-ev-cancelled">' . esc_html__( 'Cancelled', 'rsnc-events' ) . '</p>';
		}
		self::place( $event );

		if ( ! empty( $event['producerName'] ) ) {
			echo '<p class="rsnc-ev-producer"><span class="rsnc-ev-label">' . esc_html__( 'Producer', 'rsnc-events' ) . '</span> ' . esc_html( $event['producerName'] );
			echo '<span class="rsnc-ev-contacts">';
			if ( ! empty( $event['producerPhone'] ) ) {
				$tel = preg_replace( '/[^0-9+]/', '', (string) $event['producerPhone'] );
				echo ' <a class="rsnc-ev-contact" href="' . esc_url( 'tel:' . $tel, array( 'tel' ) ) . '">' . esc_html( $event['producerPhone'] ) . '</a>';
			}
			if ( ! empty( $event['producerEmail'] ) && is_email( $event['producerEmail'] ) ) {
				echo ' <a class="rsnc-ev-contact" href="' . esc_url( 'mailto:' . $event['producerEmail'], array( 'mailto' ) ) . '">' . esc_html( $event['producerEmail'] ) . '</a>';
			}
			echo '</span></p>';
		}
		echo '</div>';
		if ( $flyers ) {
			self::gallery( $flyers, $event['eventName'] );
		}
		echo '</article>';
	}

	private static function place( array $event ) {
		$where = trim( (string) $event['arenaCity'] );
		if ( ! empty( $event['arenaState'] ) ) {
			$where .= ( '' !== $where ? ', ' : '' ) . $event['arenaState'];
		}
		$bits = array_filter( array( (string) $event['arenaName'], $where ) );
		if ( $bits ) {
			echo '<p class="rsnc-ev-place">' . esc_html( implode( ' · ', $bits ) ) . '</p>';
		}
	}

	/** Flyers as a swipeable strip (native touch scrolling), with arrows when there are several. */
	private static function gallery( array $flyers, $event_name ) {
		$count = count( $flyers );
		echo '<div class="rsnc-ev-gallery" data-rsnc-ev-gallery role="region" aria-roledescription="carousel" aria-label="' . esc_attr( sprintf( /* translators: %s: event name */ __( 'Flyers for %s', 'rsnc-events' ), $event_name ) ) . '">';
		echo '<div class="rsnc-ev-track" tabindex="0">';
		foreach ( $flyers as $i => $flyer ) {
			$url = isset( $flyer['url'] ) ? $flyer['url'] : '';
			if ( '' === $url ) {
				continue;
			}
			/* translators: 1: flyer number, 2: number of flyers */
			$label = sprintf( __( 'Flyer %1$d of %2$d', 'rsnc-events' ), $i + 1, $count );
			echo '<div class="rsnc-ev-slide" aria-label="' . esc_attr( $label ) . '">';
			if ( 'pdf' === ( $flyer['type'] ?? '' ) ) {
				echo '<a class="rsnc-ev-pdf" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">';
				echo '<span class="rsnc-ev-pdf-icon" aria-hidden="true">PDF</span>';
				echo '<span class="rsnc-ev-pdf-text">' . esc_html__( 'View flyer', 'rsnc-events' ) . '</span>';
				if ( ! empty( $flyer['name'] ) ) {
					echo '<span class="rsnc-ev-pdf-name">' . esc_html( $flyer['name'] ) . '</span>';
				}
				echo '</a>';
			} else {
				echo '<a class="rsnc-ev-img" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $label . ' — ' . $event_name ) . '" loading="lazy" decoding="async"></a>';
			}
			echo '</div>';
		}
		echo '</div>';
		if ( $count > 1 ) {
			echo '<button type="button" class="rsnc-ev-arrow rsnc-ev-prev" aria-label="' . esc_attr__( 'Previous flyer', 'rsnc-events' ) . '"><span aria-hidden="true">&#8249;</span></button>';
			echo '<button type="button" class="rsnc-ev-arrow rsnc-ev-next" aria-label="' . esc_attr__( 'Next flyer', 'rsnc-events' ) . '"><span aria-hidden="true">&#8250;</span></button>';
			echo '<p class="rsnc-ev-count" aria-live="polite"><span class="rsnc-ev-count-now">1</span> / ' . (int) $count . '</p>';
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------ results */

	public static function results( $atts ) {
		self::enqueue();
		$atts  = shortcode_atts( array( 'per_page' => 10 ), $atts, 'rsnc_results' );
		$f     = self::request_filters( true );
		$query = array( 'includeResults' => 1 );
		if ( '' !== $f['season'] ) {
			foreach ( self::seasons() as $season ) {
				if ( $season['key'] === $f['season'] ) {
					$query['from'] = $season['from'];
					$query['to']   = $season['to'];
				}
			}
		}
		$events = RSNC_EV_Feed::events( $query );
		if ( null === $events ) {
			return self::unavailable();
		}
		$today = self::today();
		// Past events in the season, plus any event already showing published results.
		$past = array_values(
			array_filter(
				$events,
				static function ( $e ) use ( $today ) {
					if ( ! empty( $e['cancelled'] ) ) {
						return false;
					}
					return self::ymd( $e['endDate'] ?? '' ) < $today || ! empty( $e['resultsPublished'] );
				}
			)
		);
		usort(
			$past,
			static function ( $a, $b ) {
				return strcmp( (string) $b['startDate'], (string) $a['startDate'] ) ?: ( (int) $b['eventUID'] <=> (int) $a['eventUID'] );
			}
		);
		$shown = self::apply_filters( $past, $f );
		list( $page_events, $page, $pages ) = self::paginate( $shown, $f['page'], (int) $atts['per_page'] );

		ob_start();
		echo '<div class="rsnc-ev rsnc-ev-results" id="rsnc-ev-top">';
		self::filter_form( $past, $f, true, count( $shown ) );
		if ( ! $past ) {
			echo '<p class="rsnc-ev-empty">' . esc_html( '' === $f['season'] ? __( 'No results yet this season.', 'rsnc-events' ) : __( 'No results for this season.', 'rsnc-events' ) ) . '</p>';
		} elseif ( ! $shown ) {
			echo '<p class="rsnc-ev-empty">' . esc_html__( 'No events match your search.', 'rsnc-events' ) . '</p>';
		}
		echo '<div class="rsnc-ev-list">';
		foreach ( $page_events as $event ) {
			$name_id = 'rsnc-ev-rname-' . (int) $event['eventUID'];
			echo '<article class="rsnc-ev-card rsnc-ev-result-card" aria-labelledby="' . esc_attr( $name_id ) . '">';
			self::date_block( $event['startDate'] );
			echo '<div class="rsnc-ev-body">';
			echo '<p class="rsnc-ev-when">' . esc_html( self::date_range( $event['startDate'], $event['endDate'] ) ) . '</p>';
			echo '<h3 class="rsnc-ev-name" id="' . esc_attr( $name_id ) . '">' . esc_html( $event['eventName'] ) . '</h3>';
			self::place( $event );
			echo '<div class="rsnc-ev-daylist">';
			$days = isset( $event['days'] ) && is_array( $event['days'] ) ? $event['days'] : array();
			foreach ( $days as $day ) {
				self::day_results( $day, count( $days ) > 1 );
			}
			echo '</div></div></article>';
		}
		echo '</div>';
		self::pager( $page, $pages, $f );
		echo '</div>';
		return ob_get_clean();
	}

	private static function day_results( array $day, $multi ) {
		$label = $multi ? self::day_label( $day['date'] ) : __( 'Results', 'rsnc-events' );
		if ( ! empty( $day['cancelled'] ) ) {
			echo '<div class="rsnc-ev-day rsnc-ev-day-static"><span class="rsnc-ev-day-name">' . esc_html( $label ) . '</span> <span class="rsnc-ev-soon">' . esc_html__( 'Cancelled', 'rsnc-events' ) . '</span></div>';
			return;
		}
		$classes = isset( $day['results'] ) && is_array( $day['results'] ) ? $day['results'] : array();
		if ( empty( $day['resultsPublished'] ) || ! $classes ) {
			echo '<div class="rsnc-ev-day rsnc-ev-day-static"><span class="rsnc-ev-day-name">' . esc_html( $label ) . '</span> <span class="rsnc-ev-soon">' . esc_html__( 'Results coming soon', 'rsnc-events' ) . '</span></div>';
			return;
		}
		echo '<details class="rsnc-ev-day"><summary><span class="rsnc-ev-day-name">' . esc_html( $label ) . '</span> <span class="rsnc-ev-view">' . esc_html__( 'View results', 'rsnc-events' ) . '</span></summary><div class="rsnc-ev-day-body">';
		foreach ( $classes as $class ) {
			echo '<section class="rsnc-ev-class">';
			echo '<h4 class="rsnc-ev-class-name">' . esc_html( $class['className'] );
			if ( ! empty( $class['teamCount'] ) ) {
				/* translators: %d: number of teams */
				echo ' <span class="rsnc-ev-teams">' . esc_html( sprintf( _n( '%d team', '%d teams', (int) $class['teamCount'], 'rsnc-events' ), (int) $class['teamCount'] ) ) . '</span>';
			}
			echo '</h4>';
			if ( ! empty( $class['placings'] ) ) {
				self::placings_table( $class['placings'] );
			}
			if ( ! empty( $class['roundRobinWinners'] ) ) {
				echo '<h5 class="rsnc-ev-rr-title">' . esc_html__( 'Round Robin individual winners', 'rsnc-events' ) . '</h5>';
				self::placings_table( $class['roundRobinWinners'], true );
			}
			echo '</section>';
		}
		echo '</div></details>';
	}

	private static function money( $amount ) {
		return (float) $amount > 0 ? '$' . number_format_i18n( (float) $amount, 2 ) : '—';
	}

	private static function placings_table( array $rows, $rr = false ) {
		echo '<table class="rsnc-ev-table' . ( $rr ? ' rsnc-ev-table-rr' : '' ) . '"><thead><tr>';
		echo '<th scope="col" class="rsnc-ev-c-place">' . esc_html__( 'Place', 'rsnc-events' ) . '</th>';
		echo '<th scope="col" class="rsnc-ev-c-name">' . esc_html__( 'Name', 'rsnc-events' ) . '</th>';
		if ( ! $rr ) {
			echo '<th scope="col" class="rsnc-ev-c-num">' . esc_html__( 'Time', 'rsnc-events' ) . '</th>';
			echo '<th scope="col" class="rsnc-ev-c-num">' . esc_html__( 'Cattle', 'rsnc-events' ) . '</th>';
		}
		echo '<th scope="col" class="rsnc-ev-c-money">' . esc_html__( 'Money', 'rsnc-events' ) . '</th>';
		if ( ! $rr ) {
			echo '<th scope="col" class="rsnc-ev-c-num">' . esc_html__( 'Points', 'rsnc-events' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$name = trim( ( $r['firstName'] ?? '' ) . ' ' . ( $r['lastName'] ?? '' ) );
			echo '<tr>';
			echo '<td class="rsnc-ev-c-place">' . esc_html( (string) ( $r['placing'] ?? '' ) ) . '</td>';
			echo '<td class="rsnc-ev-c-name">' . esc_html( $name ) . '</td>';
			if ( ! $rr ) {
				echo '<td class="rsnc-ev-c-num" data-label="' . esc_attr__( 'Time', 'rsnc-events' ) . '">' . esc_html( (string) ( $r['totalTime'] ?? '' ) ) . '</td>';
				echo '<td class="rsnc-ev-c-num" data-label="' . esc_attr__( 'Cattle', 'rsnc-events' ) . '">' . esc_html( (string) ( $r['totalCattle'] ?? '' ) ) . '</td>';
			}
			$won = (float) ( $r['amountWon'] ?? 0 ) > 0;
			echo '<td class="rsnc-ev-c-money' . ( $won ? '' : ' rsnc-ev-none' ) . '">' . esc_html( self::money( $r['amountWon'] ?? 0 ) ) . '</td>';
			if ( ! $rr ) {
				$pts = isset( $r['points'] ) ? (float) $r['points'] : 0;
				echo '<td class="rsnc-ev-c-num" data-label="' . esc_attr__( 'Points', 'rsnc-events' ) . '">' . esc_html( $pts > 0 ? rtrim( rtrim( number_format( $pts, 2, '.', '' ), '0' ), '.' ) : '—' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
