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
		wp_register_style( 'rsnc-ev-fonts', 'https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&family=Bebas+Neue&family=Big+Shoulders+Display:wght@800;900&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_register_style( 'rsnc-ev', RSNC_EV_URL . 'assets/rsnc-events.css', array( 'rsnc-ev-fonts' ), RSNC_EV_VERSION );
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
		$events = RSNC_EV_Feed::events();
		if ( null === $events ) {
			return self::unavailable();
		}
		$today    = self::today();
		$upcoming = array_filter(
			$events,
			static function ( $e ) use ( $today ) {
				return self::ymd( $e['endDate'] ?? '' ) >= $today;
			}
		);
		usort(
			$upcoming,
			static function ( $a, $b ) {
				return strcmp( (string) $a['startDate'], (string) $b['startDate'] ) ?: ( (int) $a['eventUID'] <=> (int) $b['eventUID'] );
			}
		);

		ob_start();
		echo '<div class="rsnc-ev rsnc-ev-calendar">';
		if ( ! $upcoming ) {
			echo '<p class="rsnc-ev-empty">' . esc_html__( 'No upcoming events are scheduled yet. Please check back soon.', 'rsnc-events' ) . '</p>';
		}
		$month = '';
		foreach ( $upcoming as $event ) {
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
		echo '</div>';
		return ob_get_clean();
	}

	private static function event_card( array $event ) {
		$cancelled = ! empty( $event['cancelled'] );
		$days      = isset( $event['days'] ) && is_array( $event['days'] ) ? count( $event['days'] ) : 1;
		$flyers    = isset( $event['flyers'] ) && is_array( $event['flyers'] ) ? $event['flyers'] : array();
		$name_id   = 'rsnc-ev-name-' . (int) $event['eventUID'];

		echo '<article class="rsnc-ev-card' . ( $cancelled ? ' rsnc-ev-is-cancelled' : '' ) . ( $flyers ? ' rsnc-ev-has-flyers' : '' ) . '" aria-labelledby="' . esc_attr( $name_id ) . '">';
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
		if ( ! $cancelled && ! empty( $event['bookingUrl'] ) ) {
			echo '<p class="rsnc-ev-actions"><a class="rsnc-ev-btn" href="' . esc_url( $event['bookingUrl'] ) . '">' . esc_html__( 'Book Stalls & RV', 'rsnc-events' ) . '</a></p>';
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
		$events = RSNC_EV_Feed::events( array( 'includeResults' => 1 ) );
		if ( null === $events ) {
			return self::unavailable();
		}
		$today = self::today();
		// Past events this season, plus any event already showing published results.
		$past = array_filter(
			$events,
			static function ( $e ) use ( $today ) {
				if ( ! empty( $e['cancelled'] ) ) {
					return false;
				}
				return self::ymd( $e['endDate'] ?? '' ) < $today || ! empty( $e['resultsPublished'] );
			}
		);
		usort(
			$past,
			static function ( $a, $b ) {
				return strcmp( (string) $b['startDate'], (string) $a['startDate'] ) ?: ( (int) $b['eventUID'] <=> (int) $a['eventUID'] );
			}
		);

		ob_start();
		echo '<div class="rsnc-ev rsnc-ev-results">';
		if ( ! $past ) {
			echo '<p class="rsnc-ev-empty">' . esc_html__( 'No results yet this season.', 'rsnc-events' ) . '</p>';
		}
		echo '<div class="rsnc-ev-list">';
		foreach ( $past as $event ) {
			$name_id = 'rsnc-ev-rname-' . (int) $event['eventUID'];
			echo '<article class="rsnc-ev-card rsnc-ev-result-card" aria-labelledby="' . esc_attr( $name_id ) . '"><div class="rsnc-ev-body">';
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
		echo '</div></div>';
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
			echo '<td class="rsnc-ev-c-money">' . esc_html( self::money( $r['amountWon'] ?? 0 ) ) . '</td>';
			if ( ! $rr ) {
				$pts = isset( $r['points'] ) ? (float) $r['points'] : 0;
				echo '<td class="rsnc-ev-c-num" data-label="' . esc_attr__( 'Points', 'rsnc-events' ) . '">' . esc_html( $pts > 0 ? rtrim( rtrim( number_format( $pts, 2, '.', '' ), '0' ), '.' ) : '—' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
