<?php
/**
 * Hub invoke handlers: cache, DB maintenance, comments, reading settings.
 *
 * @package WPHubPro
 */

namespace WPHubPro\Api;

use WPHubPro\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Callable handlers for POST wphubpro/v1/hub/invoke (registered in HubInvoke).
 */
class HubMaintenance {

	/**
	 * Flush object cache and expired transients (best-effort).
	 *
	 * @param array            $args    Unused.
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function handler_flush_caches( array $args, $request ): array {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( function_exists( 'delete_expired_transients' ) ) {
			delete_expired_transients();
		}
		/**
		 * Fires after WPHub Pro attempts to flush caches (object cache + expired transients).
		 */
		do_action( 'wphubpro_after_cache_flush' );

		return array(
			'ok'        => true,
			'flushed'   => true,
			'note'      => 'Object cache flush and expired transients cleanup (hosting and page-cache plugins may need their own purge).',
		);
	}

	/**
	 * OPTIMIZE TABLE for all tables using the site DB prefix.
	 *
	 * @param array            $args    Unused.
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function handler_optimize_db( array $args, $request ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! property_exists( $wpdb, 'prefix' ) ) {
			return new \WP_Error( 'db_unavailable', __( 'Database is not available.', 'wphubpro-bridge' ), array( 'status' => 500 ) );
		}

		$prefix = $wpdb->get_blog_prefix();
		$like   = $wpdb->esc_like( $prefix ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- esc_like used for pattern.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		if ( ! is_array( $tables ) ) {
			$tables = array();
		}

		$optimized = array();
		$errors    = array();
		foreach ( $tables as $table ) {
			$table = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table );
			if ( $table === '' || strpos( $table, $prefix ) !== 0 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name validated to prefix + alnum.
			$ok = $wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $table ) . '`' );
			if ( false === $ok ) {
				$errors[] = $table;
			} else {
				$optimized[] = $table;
			}
		}

		Logger::log_action(
			'invoke',
			'hub/maintenance_optimize_db',
			array(),
			array(
				'count'   => count( $optimized ),
				'errors'  => $errors,
			)
		);

		return array(
			'ok'          => empty( $errors ),
			'optimized'   => count( $optimized ),
			'tables'      => array_slice( $optimized, 0, 50 ),
			'failed'      => $errors,
			'message'     => empty( $errors )
				/* translators: %d: number of tables */
				? sprintf( __( 'Optimized %d tables.', 'wphubpro-bridge' ), count( $optimized ) )
				: __( 'Some tables could not be optimized; see failed list.', 'wphubpro-bridge' ),
		);
	}

	/**
	 * Permanently delete spam comments (capped).
	 *
	 * @param array            $args    May contain limit (int).
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function handler_purge_spam_comments( array $args, $request ): array {
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 200;
		if ( $limit < 1 ) {
			$limit = 1;
		}
		if ( $limit > 2000 ) {
			$limit = 2000;
		}

		$ids = get_comments(
			array(
				'status' => 'spam',
				'number' => $limit,
				'fields' => 'ids',
			)
		);
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id < 1 ) {
				continue;
			}
			if ( wp_delete_comment( $id, true ) ) {
				$deleted++;
			}
		}

		return array(
			'ok'       => true,
			'deleted'  => $deleted,
			'queried'  => count( $ids ),
			'limit'    => $limit,
		);
	}

	/**
	 * Control Search engine visibility (Settings → Reading).
	 *
	 * @param array            $args    Expect discourage (bool): true = discourage indexing (blog_public 0).
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function handler_reading_search_visibility( array $args, $request ): array {
		$discourage = ! empty( $args['discourage'] );
		update_option( 'blog_public', $discourage ? 0 : 1 );

		return array(
			'ok'           => true,
			'blog_public'  => (int) get_option( 'blog_public' ),
			'discourage'   => $discourage,
			'message'      => $discourage
				? __( 'Search engines are discouraged from indexing this site.', 'wphubpro-bridge' )
				: __( 'Search engines may index this site.', 'wphubpro-bridge' ),
		);
	}
}
