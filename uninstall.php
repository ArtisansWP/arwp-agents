<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package ARWP_Agents
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

call_user_func(
	static function () {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'arwp_sessions',
			$wpdb->prefix . 'arwp_messages',
			$wpdb->prefix . 'arwp_documents',
			$wpdb->prefix . 'arwp_embeddings',
			$wpdb->prefix . 'arwp_promos',
			$wpdb->prefix . 'arwp_promo_events',
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall removes this plugin's tables. Names are prefixed constants.
		}

		$options = array(
			'arwp_openrouter_api_key',
			'arwp_openrouter_default_model',
			'arwp_openai_api_key',
			'arwp_rag_chunk_size',
			'arwp_api_status',
			'arwp_db_version',
			'arwp_widget_enabled',
			'arwp_widget_agent_id',
			'arwp_widget_position',
			'arwp_widget_label',
			'arwp_rate_limit_per_hour',
			'arwp_trust_proxy',
			'arwp_promos_enabled',
			'arwp_promos_cooldown',
			'arwp_promos_blocked_topics',
			'arwp_promos_disclosure',
			'arwp_promos_utm_enabled',
			'arwp_promos_utm_source',
			'arwp_promos_utm_medium',
			'arwp_promos_semantic',
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		delete_transient( 'arwp_openrouter_models' );
		delete_transient( 'arwp_db_upgraded_threads_v1' );

		$agents = get_posts(
			array(
				'post_type'      => 'arwp_agent',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);

		foreach ( $agents as $agent_id ) {
			wp_delete_post( $agent_id, true );
		}
	}
);
