<?php
/**
 * Transcript retention, deletion, and export text.
 *
 * @package ARWP_Agents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention deletes custom chat rows. A cache would keep transcripts that should be gone.

/**
 * Retention cutoff, transcript formatting, and the required notice sentence.
 */
class ARWP_Privacy {

	/**
	 * Register the daily cleanup hook.
	 */
	public static function init() {
		add_action( 'arwp_daily_maintenance', array( __CLASS__, 'purge_expired' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedule cleanup once per day.
	 */
	public static function schedule() {
		if ( wp_next_scheduled( 'arwp_daily_maintenance' ) ) {
			return;
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'arwp_daily_maintenance' );
	}

	/**
	 * Default sentence shown before the first send.
	 *
	 * @return string
	 */
	public static function default_notice() {
		return 'Your message is sent to OpenRouter to generate a reply. It leaves this site. Do not include passwords or anything you do not want shared.';
	}

	/**
	 * Notice text. A blank setting restores the default sentence.
	 *
	 * @param mixed $saved Saved setting.
	 * @return string
	 */
	public static function notice_text( $saved ) {
		$saved = trim( (string) $saved );

		return '' === $saved ? self::default_notice() : $saved;
	}

	/**
	 * Local timestamp cutoff for retention. An empty string means keep everything.
	 *
	 * The timestamp uses the same clock as session created_at values.
	 *
	 * @param int $days          Days to keep. 0 keeps transcripts.
	 * @param int $now_timestamp Current timestamp on that clock.
	 * @return string MySQL datetime, or empty when retention is off.
	 */
	public static function cutoff_mysql( $days, $now_timestamp ) {
		$days          = (int) $days;
		$now_timestamp = (int) $now_timestamp;

		if ( $days < 1 || $now_timestamp < 1 ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', $now_timestamp - ( $days * 86400 ) );
	}

	/**
	 * Plain-text transcript. System rows are skipped.
	 *
	 * @param string $title    Session title.
	 * @param array  $messages Rows with role and content.
	 * @return string
	 */
	public static function format_transcript( $title, $messages ) {
		$lines = array( (string) $title, '' );

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role = isset( $message['role'] ) ? (string) $message['role'] : '';
			if ( 'user' !== $role && 'assistant' !== $role ) {
				continue;
			}

			$content = isset( $message['content'] ) ? (string) $message['content'] : '';
			$lines[] = $role . ': ' . $content;
			$lines[] = '';
		}

		return rtrim( implode( "\n", $lines ) ) . "\n";
	}

	/**
	 * Delete one session and its messages.
	 *
	 * @param int $session_id Session ID.
	 */
	public static function delete_session( $session_id ) {
		global $wpdb;

		$session_id = (int) $session_id;
		if ( $session_id < 1 ) {
			return;
		}

		$wpdb->delete(
			$wpdb->prefix . 'arwp_messages',
			array( 'session_id' => $session_id ),
			array( '%d' )
		);
		$wpdb->delete(
			$wpdb->prefix . 'arwp_sessions',
			array( 'id' => $session_id ),
			array( '%d' )
		);
	}

	/**
	 * Delete sessions older than the retention setting.
	 *
	 * @return int Number of sessions removed.
	 */
	public static function purge_expired() {
		global $wpdb;

		$days = (int) get_option( 'arwp_retention_days', 0 );
		// current_time( 'timestamp' ) matches created_at from current_time( 'mysql' ).
		$cutoff = self::cutoff_mysql( $days, (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		if ( '' === $cutoff ) {
			return 0;
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE created_at < %s',
				$wpdb->prefix . 'arwp_sessions',
				$cutoff
			)
		);

		if ( empty( $ids ) || ! is_array( $ids ) ) {
			return 0;
		}

		foreach ( $ids as $id ) {
			self::delete_session( $id );
		}

		return count( $ids );
	}
}
