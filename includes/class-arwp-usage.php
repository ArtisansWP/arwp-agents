<?php
/**
 * Daily token and dollar ceilings.
 *
 * @package ARWP_Agents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The daily usage row is written on every reply. A cache would block or alert from stale totals.

/**
 * Records site-wide daily usage and decides when to stop or email.
 */
class ARWP_Usage {

	/**
	 * Table name, including the site prefix.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'arwp_usage_daily';
	}

	/**
	 * Convert a dollar amount to integer micro-dollars (USD × 1,000,000).
	 *
	 * @param mixed $dollars Dollar amount.
	 * @return int
	 */
	public static function dollars_to_micro( $dollars ) {
		if ( ! is_numeric( $dollars ) ) {
			return 0;
		}

		$negative   = ( (float) $dollars < 0 );
		$normalized = number_format( abs( (float) $dollars ), 6, '.', '' );
		$parts      = explode( '.', $normalized );
		$whole      = (int) $parts[0];
		$fraction   = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$micro      = ( $whole * 1000000 ) + $fraction;

		return $negative ? ( 0 - $micro ) : $micro;
	}

	/**
	 * Why today's usage is already over a ceiling.
	 *
	 * An empty string means the next message may be sent. A token ceiling of 0,
	 * or a dollar ceiling of 0, is off. When both are met, the token reason wins.
	 *
	 * @param int   $tokens      Tokens already stored for the day.
	 * @param int   $cost_micro  Reported cost already stored, in micro-dollars.
	 * @param int   $token_cap   Daily token ceiling. 0 is off.
	 * @param mixed $dollar_cap  Daily dollar ceiling. 0 is off.
	 * @return string tokens, dollars, or empty.
	 */
	public static function block_reason( $tokens, $cost_micro, $token_cap, $dollar_cap ) {
		$token_cap    = (int) $token_cap;
		$dollar_micro = self::dollars_to_micro( $dollar_cap );

		if ( $token_cap > 0 && (int) $tokens >= $token_cap ) {
			return 'tokens';
		}

		if ( $dollar_micro > 0 && (int) $cost_micro >= $dollar_micro ) {
			return 'dollars';
		}

		return '';
	}

	/**
	 * Which one-time email to send for this daily row.
	 *
	 * @param int   $tokens       Tokens stored for the day.
	 * @param int   $cost_micro   Reported cost stored, in micro-dollars.
	 * @param int   $token_cap    Daily token ceiling.
	 * @param mixed $dollar_cap   Daily dollar ceiling.
	 * @param bool  $alerted_near Whether the 80% email already went out.
	 * @param bool  $alerted_stop Whether the stop email already went out.
	 * @return string near, stop, or empty.
	 */
	public static function alert_kind( $tokens, $cost_micro, $token_cap, $dollar_cap, $alerted_near, $alerted_stop ) {
		if ( '' !== self::block_reason( $tokens, $cost_micro, $token_cap, $dollar_cap ) ) {
			return $alerted_stop ? '' : 'stop';
		}

		if ( $alerted_near ) {
			return '';
		}

		$token_cap    = (int) $token_cap;
		$dollar_micro = self::dollars_to_micro( $dollar_cap );
		$near         = false;

		if ( $token_cap > 0 && ( (int) $tokens * 5 ) >= ( $token_cap * 4 ) ) {
			$near = true;
		}

		if ( $dollar_micro > 0 && ( (int) $cost_micro * 5 ) >= ( $dollar_micro * 4 ) ) {
			$near = true;
		}

		return $near ? 'near' : '';
	}

	/**
	 * Today's usage row, or zeros when the day has no row yet.
	 *
	 * @return array
	 */
	public static function today_row() {
		global $wpdb;

		$date = current_time( 'Y-m-d' );
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT usage_date, tokens, cost_micro, alerted_near, alerted_stop FROM %i WHERE usage_date = %s',
				self::table_name(),
				$date
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array(
				'usage_date'   => $date,
				'tokens'       => 0,
				'cost_micro'   => 0,
				'alerted_near' => 0,
				'alerted_stop' => 0,
			);
		}

		return $row;
	}

	/**
	 * Refuse a new message when a daily ceiling is already met.
	 *
	 * Called before the user row is inserted.
	 *
	 * @return WP_Error|null
	 */
	public static function preflight_error() {
		$row    = self::today_row();
		$reason = self::block_reason(
			$row['tokens'],
			$row['cost_micro'],
			(int) get_option( 'arwp_daily_token_cap', 0 ),
			get_option( 'arwp_daily_dollar_cap', '0' )
		);

		if ( '' === $reason ) {
			return null;
		}

		self::maybe_alert( $row );

		return new WP_Error(
			'arwp_daily_cap',
			__( 'This site has reached its daily usage limit. Please try again tomorrow.', 'arwp-agents' ),
			array( 'status' => 429 )
		);
	}

	/**
	 * Add one completed reply to today's totals, then send any due email.
	 *
	 * @param int   $tokens Tokens reported for the reply.
	 * @param mixed $cost   Dollar cost reported for the reply. 0 when absent.
	 */
	public static function record( $tokens, $cost ) {
		global $wpdb;

		$tokens = max( 0, (int) $tokens );
		$micro  = max( 0, self::dollars_to_micro( $cost ) );

		if ( 0 === $tokens && 0 === $micro ) {
			return;
		}

		$date = current_time( 'Y-m-d' );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (usage_date, tokens, cost_micro, alerted_near, alerted_stop) VALUES (%s, %d, %d, 0, 0) ON DUPLICATE KEY UPDATE tokens = tokens + %d, cost_micro = cost_micro + %d',
				self::table_name(),
				$date,
				$tokens,
				$micro,
				$tokens,
				$micro
			)
		);

		self::maybe_alert( self::today_row() );
	}

	/**
	 * Email the alert address once at 80% and once when a ceiling is reached.
	 *
	 * @param array $row Daily usage row.
	 */
	public static function maybe_alert( $row ) {
		global $wpdb;

		$kind = self::alert_kind(
			isset( $row['tokens'] ) ? $row['tokens'] : 0,
			isset( $row['cost_micro'] ) ? $row['cost_micro'] : 0,
			(int) get_option( 'arwp_daily_token_cap', 0 ),
			get_option( 'arwp_daily_dollar_cap', '0' ),
			! empty( $row['alerted_near'] ),
			! empty( $row['alerted_stop'] )
		);

		if ( '' === $kind || empty( $row['usage_date'] ) ) {
			return;
		}

		$email = sanitize_email( (string) get_option( 'arwp_usage_alert_email', '' ) );
		if ( '' === $email || ! is_email( $email ) ) {
			$email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		}

		if ( '' !== $email && is_email( $email ) ) {
			$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
			if ( 'stop' === $kind ) {
				$subject = sprintf(
					/* translators: %s: site name. */
					__( '[%s] Daily chat usage is paused', 'arwp-agents' ),
					$site
				);
				$body = __( 'ARWP Agents stopped new messages because a daily usage ceiling was reached. Raise the ceiling in Settings, or wait until tomorrow.', 'arwp-agents' );
			} else {
				$subject = sprintf(
					/* translators: %s: site name. */
					__( '[%s] Daily chat usage is near its ceiling', 'arwp-agents' ),
					$site
				);
				$body = __( 'ARWP Agents has used about 80% of a daily usage ceiling. New messages still go through until the ceiling is reached.', 'arwp-agents' );
			}
			wp_mail( $email, $subject, $body );
		}

		if ( 'stop' === $kind ) {
			$wpdb->update(
				self::table_name(),
				array( 'alerted_stop' => 1 ),
				array( 'usage_date' => $row['usage_date'] ),
				array( '%d' ),
				array( '%s' )
			);
			return;
		}

		$wpdb->update(
			self::table_name(),
			array( 'alerted_near' => 1 ),
			array( 'usage_date' => $row['usage_date'] ),
			array( '%d' ),
			array( '%s' )
		);
	}
}
