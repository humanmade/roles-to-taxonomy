<?php

namespace Altis\Roles_To_Taxonomy;

use WP_CLI;
use WP_Tax_Query;
use WP_User_Query;

const ROLES_TAXONOMY = 'user_roles';
const USER_LEVELS_TAXONOMY = 'user_levels';

function bootstrap() {
	add_action( 'pre_get_users', __NAMESPACE__ . '\\add_tax_query_to_wp_user_query' );
	add_action( 'pre_user_query', __NAMESPACE__ . '\\add_tax_query_clauses_to_wp_user_query' );
	add_action( 'users_pre_query', __NAMESPACE__ . '\\set_wp_user_query_count_total', 10, 2 );
	add_action( 'set_user_role', __NAMESPACE__ . '\\set_user_role', 10, 3 );
	add_action( 'init', __NAMESPACE__ . '\\register_roles_taxonomies' );
	add_filter( 'pre_count_users', __NAMESPACE__ . '\\get_count_users', 10, 3 );

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once __DIR__ . '/class-cli-command.php';
		WP_CLI::add_command( 'roles-to-taxonomy', __NAMESPACE__ . '\\CLI_Command' );
	}
}

/**
 * Register the taxonomy.
 */
function register_roles_taxonomies() {
	register_taxonomy(
		ROLES_TAXONOMY, 'user', [
			'public' => false,
		]
	);

	register_taxonomy(
		USER_LEVELS_TAXONOMY, 'user', [
			'public' => false,
		]
	);
}

/**
 * Add the tax_query to the WP_User_Query object on parsing args.
 *
 * @todo Handle who=autors
 *
 * @param WP_User_Query $query
 */
function add_tax_query_to_wp_user_query( WP_User_Query $query ) {
	if ( ! $query->get( 'blog_id' ) ) {
		return;
	}

	$tax_query = [];

	if ( $query->get( 'role' ) ) {
		$tax_query[] = [
			'taxonomy' => ROLES_TAXONOMY,
			'terms' => [ $query->get( 'role' ) ],
			'field' => 'slug',
		];
	}

	if ( $query->get( 'role__in' ) ) {
		$tax_query[] = [
			'taxonomy'   => ROLES_TAXONOMY,
			'terms'      => $query->get( 'role__in' ),
			'field'      => 'slug',
			'operator'   => 'IN',
		];
	}

	if ( $query->get( 'role__not_in' ) ) {
		$tax_query[] = [
			'taxonomy'   => ROLES_TAXONOMY,
			'terms'      => $query->get( 'role__not_in' ),
			'field'      => 'slug',
			'operator'   => 'NOT IN',
		];
	}

	// If the query is for users on a different blog to the current on,
	// switch to that blog before we run get_terms etc, as we'll want the
	// terms from that other blog.
	$restore_blog = false;
	if ( $query->get( 'blog_id' ) != get_current_blog_id() ) {
		switch_to_blog( $query->get( 'blog_id' ) );
		$restore_blog = true;
	}

	// If there is no role specified, and the blog_id is set, use an exists query. We only do this
	// in the case that no role is set because if a role is being queried for, it already doesn't need
	// to check for EXISTS.
	if ( ! $tax_query ) {
		$tax_query[] = [
			'taxonomy'   => ROLES_TAXONOMY,
			'operator'   => 'EXISTS',
		];
	}

	if ( $query->get( 'who' ) && $query->get( 'who' ) === 'authors' ) {
		$tax_query[] = [
			'taxonomy'   => USER_LEVELS_TAXONOMY,
			'terms'      => [ 'level_0' ],
			'field'      => 'slug',
			'operator'   => 'NOT IN',
		];
	}

	// If there are no query vars except for roles or blog_id, override the handling of found_rows
	// for performance. We can use the term's count field to populate the found rows which is much
	// fast than an SQL_CALC_FOUND_ROWS
	$query_vars = [
		'meta_key'            => '',
		'meta_value'          => '',
		'include'             => [],
		'exclude'             => [],
		'search'              => '',
		'has_published_posts' => null,
		'nicename'            => '',
		'nicename__in'        => [],
		'nicename__in'        => [],
		'nicename__not_in'    => [],
		'login'               => '',
		'login__in'           => [],
		'login__not_in'       => [],
		'role__not_in'        => [],
	];
	$has_extra_search = false;
	foreach ( $query_vars as $query_var => $value ) {
		if ( $query->get( $query_var ) != $value ) {
			$has_extra_search = true;
			break;
		}
	}

	$query->set( 'original_who', $query->get( 'who' ) );
	$query->set( 'original_role', $query->get( 'role' ) );
	$query->set( 'original_role__in', $query->get( 'role__in' ) );
	$query->set( 'original_role__not_in', $query->get( 'role__not_in' ) );
	$query->set( 'original_blog_id', $query->get( 'blog_id' ) );
	$query->set( 'role', '' );
	$query->set( 'role__in', [] );
	$query->set( 'role__not_in', [] );
	$query->set( 'blog_id', 0 );
	$query->set( 'who', '' );

	// If there is no extra seach params, we can use the term counts to calculte the total
	// counts, which is going to be a lot faster. This is done in the set_wp_user_query_count_total
	// function which is called via the users_pre_query filter.
	if ( ! $has_extra_search ) {
		$query->set( 'original_count_total', $query->get( 'count_total' ) );
		$query->set( 'count_total', false );
	}

	$query->set( 'tax_query', $tax_query );

	if ( $restore_blog ) {
		restore_current_blog();
	}
}

/**
 * Add the SQL clauses for the tax query to the WP_User_Query SQL query.
 *
 * @param WP_User_Query $query
 */
function add_tax_query_clauses_to_wp_user_query( WP_User_Query $query ) {
	$tax_query = $query->get( 'tax_query' );

	if ( ! $tax_query ) {
		return;
	}

	global $wpdb;

	$restore_blog = false;
	if ( $query->get( 'original_blog_id' ) != get_current_blog_id() ) {
		switch_to_blog( $query->get( 'original_blog_id' ) );
		$restore_blog = true;
	}
	$wp_tax_query = new WP_Tax_Query( $tax_query );

	$clauses = $wp_tax_query->get_sql( $wpdb->users, 'ID' );

	$query->query_from .= ' ' . $clauses['join'];
	$query->query_where .= ' ' . $clauses['where'];

	if ( $restore_blog ) {
		restore_current_blog();
	}
}

/**
 * Set the user's role.
 *
 * @param integer $user_id
 * @param string $role
 * @return void
 */
function set_user_role( int $user_id, ?string $role ) {
	$user = get_userdata( $user_id );

	wp_set_object_terms( $user_id, $role ?: [], ROLES_TAXONOMY, false );
	wp_set_object_terms( $user_id, $user->user_level ? 'level_' . $user->user_level: [], USER_LEVELS_TAXONOMY, false );
}

/**
 * Custom implementation of count_users.
 *
 * @param null|array $users
 * @param string $strategy
 * @param integer $blog_id
 * @return array
 */
function get_count_users( $users = null, string $strategy, int $blog_id ) {
	if ( $users ) {
		return $users;
	}

	$restore_blog = false;
	if ( $blog_id != get_current_blog_id() ) {
		switch_to_blog( $blog_id );
		$restore_blog = true;
	}
	$roles = get_terms(
		[
			'taxonomy' => ROLES_TAXONOMY,
			'hide_empty' => true,
		]
	);
	if ( $restore_blog ) {
		restore_current_blog();
	}

	$users['avail_roles'] = [];

	foreach ( $roles as $role ) {
		$users['avail_roles'][ $role->slug ] = $role->count;
	}

	$users['total_users'] = array_sum( $users['avail_roles'] );
	return $users;
}

/**
 * Provide a custom implementation for the user_count value on WP_User_Query
 *
 * We hide this value so the SQL_CALC_FOUND_ROWS is not added to the mysql query.
 *
 * @todo support blog_id
 * @param WP_User_Query $query
 */
function set_wp_user_query_count_total( $null, WP_User_Query $query ) {
	if ( $null || ! $query->get( 'original_count_total' ) ) {
		return $null;
	}

	$restore_blog = false;
	if ( $query->get( 'original_blog_id' ) != get_current_blog_id() ) {
		switch_to_blog( $query->get( 'original_blog_id' ) );
		$restore_blog = true;
	}

	if ( $query->get( 'original_role' ) ) {
		$term = get_term_by( 'slug', $query->get( 'original_role' ), ROLES_TAXONOMY );
		if ( $term ) {
			$terms = [ $term ];
		} else {
			$terms = [];
		}
	} elseif ( $query->get( 'original_role__in' ) ) {
		$terms = [];
		foreach ( $query->get( 'original_role__in' ) as $role ) {
			$term = get_term_by( 'slug', $query->get( 'role' ), ROLES_TAXONOMY );
			if ( $term ) {
				$terms[] = $term;
			}
		}
	} else {
		// If there is no role specified, and the blog_id is set, use an exists query. We only do this
		// in the case that no role is set because if a role is being queried for, it already doesn't need
		// to check for EXISTS.
		$terms = get_terms( [
			'taxonomy' => ROLES_TAXONOMY,
			'hide_empty' => true,
		] );
	}

	if ( $restore_blog ) {
		restore_current_blog();
	}

	$count = 0;
	foreach ( $terms as $term ) {
		$count += $term->count;
	}

	$query->total_users = $count;

	return $null;
}
