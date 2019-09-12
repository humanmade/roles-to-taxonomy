<?php

namespace Altis\Roles_To_Taxonomy;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;

class CLI_Command extends WP_CLI_Command {

	/**
	 * Synchronise taxonomy term counts with user roles from user_meta.
	 *
	 * @synopsis [--verbose] [--batch-size=<number>] [--progress] [--offset=<number>] [--fast-populate] [--limit=<number>]
	 *
	 * @param array $args
	 * @param array $args_assoc
	 */
	public function sync( array $args, array $args_assoc ) {
		global $wpdb;

		$args_assoc = wp_parse_args(
			$args_assoc, [
				'batch-size'    => 100,
				'progress'      => false,
				'verbose'       => false,
				'offset'        => 0,
				'fast-populate' => false,
				'limit'         => 0,
			]
		);

		$users_args = [
			'blog_id'     => null,
			'number'      => $args_assoc['batch-size'],
			'count_total' => false,
			'fields'      => 'ID',
			'paged'       => 1,
			'offset'      => $args_assoc['offset'],

		];
		$synced = 0;

		wp_defer_term_counting( true );

		if ( $args_assoc['progress'] ) {
			if ( $args_assoc['verbose'] ) {
				WP_CLI::Line( 'Counting users...' );
			}
			$total_users = $wpdb->get_var( "SELECT count(ID) FROM $wpdb->users" ) - $args_assoc['offset'];
			$total_users = $args_assoc['limit'] ? min( $total_users, $args_assoc['limit'] ) : $total_users;
			$progress_bar = Utils\make_progress_bar( sprintf( 'Syncing %d Users', $total_users ), $total_users );
		}

		$roles_terms_map = [];
		$user_level_terms_map = [];

		while ( $users = get_users( $users_args ) ) {
			$insert_values = [];

			if ( $args_assoc['fast-populate'] ) {
				$caps = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT user_id, meta_value FROM $wpdb->usermeta WHERE user_id IN ( " . implode( ', ', $users ) . ' ) AND meta_key = %s',
						$wpdb->prefix . 'capabilities'
					)
				);
				foreach ( $caps as $cap_user ) {
					if ( $cap_user->meta_value ) {
						$keys = array_keys( unserialize( $cap_user->meta_value ) );
						$role = array_pop( $keys );
					} else {
						$role = null;
					}
					if ( $role ) {
						if ( isset( $roles_terms_map[ $role ] ) ) {
							$term_id = $roles_terms_map[ $role ];
						} else {
							$term = term_exists( $role, ROLES_TAXONOMY );
							if ( $term ) {
								$term_id = $term['term_id'];
							} else {
								$term_id = wp_insert_term( $role, ROLES_TAXONOMY )['term_id'];
							}
							$roles_terms_map[ $role ] = $term_id;
						}
						$insert_values[] = $wpdb->prepare( '(%d, %d, 0)', $cap_user->user_id, $term_id );
					}
					$synced++;
					if ( isset( $progress_bar ) ) {
						$progress_bar->tick();
					}
					if ( $args_assoc['verbose'] ) {
						WP_CLI::line( sprintf( 'Synced user %d with role %s', $cap_user->user_id, $role ) );
					}
				}

				$user_levels = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT user_id, meta_value FROM $wpdb->usermeta WHERE user_id IN ( " . implode( ', ', $users ) . ' ) AND meta_key = %s',
						$wpdb->prefix . 'user_level'
					)
				);
				foreach ( $user_levels as $level_user_row ) {
					$user_level = $level_user_row->meta_value;
					if ( isset( $user_level_terms_map[ $user_level ] ) ) {
						$term_id = $user_level_terms_map[ $user_level ];
					} else {
						$term = term_exists( $user_level, USER_LEVELS_TAXONOMY );
						if ( $term ) {
							$term_id = $term['term_id'];
						} else {
							$term_id = wp_insert_term( $user_level, USER_LEVELS_TAXONOMY )['term_id'];
						}
						$user_level_terms_map[ $user_level ] = $term_id;
					}

					$insert_values[] = $wpdb->prepare( '(%d, %d, 0)', $level_user_row->user_id, $term_id );
				}

				$insert = sprintf(
					"INSERT into $wpdb->term_relationships ( object_id, term_taxonomy_id, term_order ) VALUES %s",
					implode( ', ', $insert_values )
				);

				$result = $wpdb->query( $insert );
				if ( ! $result ) {
					WP_CLI::line( 'Could not run insert query. ' . $wpdb->last_error );
				}
			} else {
				foreach ( $users as $user_id ) {
					$user = get_userdata( $user_id );
					$role = array_pop( $user->roles );
					set_user_role( $user->ID, $role );

					$synced++;
					if ( isset( $progress_bar ) ) {
						$progress_bar->tick();
					}
					if ( $args_assoc['verbose'] ) {
						WP_CLI::line( sprintf( 'Synced user %d with role %s', $user_id, $role ) );
					}

					if ( $args_assoc['limit'] && $synced >= $args_assoc['limit'] ) {
						break;
					}
				}
			}

			if ( count( $users ) < $args_assoc['batch-size'] ) {
				break;
			}

			if ( $args_assoc['limit'] && $synced >= $args_assoc['limit'] ) {
				break;
			}

			$users_args['paged']++;
			// Clear the local object cache, as a huge amount of users will cause massive memory
			// usage.
			global $wp_object_cache;
			$wp_object_cache->cache = [];
		}

		if ( $args_assoc['fast-populate'] ) {
			wp_cache_flush();
			wp_update_term_count_now(
				get_terms(
					[
						'fields' => 'ids',
						'taxonomy' => ROLES_TAXONOMY,
					]
				), ROLES_TAXONOMY
			);
			wp_update_term_count_now(
				get_terms(
					[
						'fields' => 'ids',
						'taxonomy' => USER_LEVELS_TAXONOMY,
					]
				), USER_LEVELS_TAXONOMY
			);
		} else {
			wp_defer_term_counting( false );
		}

		if ( isset( $progress_bar ) ) {
			$progress_bar->finish();
		}
		WP_CLI::success( sprintf( 'Synced %d users.', $synced ) );
	}
}
