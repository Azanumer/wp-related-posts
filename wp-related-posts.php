<?php
/**
 * Plugin Name:       Related Posts
 * Plugin URI:        https://github.com/Azanumer/wp-related-posts
 * Description:       Shows related posts under single posts, matched by shared categories and tags, with a transient cache and a shortcode.
 * Version:           1.0.0
 * Author:            Azan Umer
 * License:           MIT
 * Text Domain:       wrp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WRP_VERSION', '1.0.0' );

/**
 * Get related post IDs for a post, scored by shared terms (categories + tags).
 *
 * @param int $post_id Post to find relatives for.
 * @param int $limit   How many related posts to return.
 * @return int[] Post IDs, best match first.
 */
function wrp_get_related_ids( $post_id, $limit = 4 ) {
    $post_id = (int) $post_id;
    $limit   = max( 1, (int) $limit );

    $cache_key = 'wrp_related_' . $post_id . '_' . $limit;
    $cached    = get_transient( $cache_key );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        return array();
    }

    // Gather the post's terms in both taxonomies.
    $term_ids = array();
    foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
        $terms = get_the_terms( $post_id, $taxonomy );
        if ( is_array( $terms ) ) {
            foreach ( $terms as $term ) {
                $term_ids[] = (int) $term->term_id;
            }
        }
    }
    $term_ids = array_unique( $term_ids );
    if ( empty( $term_ids ) ) {
        return array();
    }

    // Candidates: posts sharing at least one term, excluding the post itself.
    $candidates = get_posts( array(
        'post_type'      => $post->post_type,
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'post__not_in'   => array( $post_id ),
        'tax_query'      => array(
            array(
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => $term_ids,
                'operator' => 'IN',
            ),
            array(
                'taxonomy' => 'post_tag',
                'field'    => 'term_id',
                'terms'    => $term_ids,
                'operator' => 'IN',
            ),
            'relation' => 'OR',
        ),
        'fields'         => 'ids',
    ) );

    // Score each candidate by the number of shared terms (highest first).
    $scores = array();
    foreach ( $candidates as $candidate_id ) {
        $shared = 0;
        foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
            $candidate_terms = get_the_terms( $candidate_id, $taxonomy );
            if ( is_array( $candidate_terms ) ) {
                foreach ( $candidate_terms as $term ) {
                    if ( in_array( (int) $term->term_id, $term_ids, true ) ) {
                        $shared++;
                    }
                }
            }
        }
        $scores[ $candidate_id ] = $shared;
    }
    arsort( $scores );

    $related = array_slice( array_keys( $scores ), 0, $limit );
    set_transient( $cache_key, $related, DAY_IN_SECONDS );
    return $related;
}

/**
 * Render the related-posts box HTML.
 *
 * @param int $post_id Post to find relatives for.
 * @return string HTML (empty string when nothing related).
 */
function wrp_render( $post_id ) {
    $count = (int) apply_filters( 'wrp_count', 4 );
    $ids   = wrp_get_related_ids( $post_id, $count );
    if ( empty( $ids ) ) {
        return '';
    }

    $posts = get_posts( array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'post__in'       => $ids,
        'orderby'        => 'post__in',
        'posts_per_page' => count( $ids ),
    ) );

    $html  = '<aside class="wrp-related"><h3>' . esc_html__( 'Related Posts', 'wrp' ) . '</h3><ul>';
    foreach ( $posts as $related_post ) {
        $html .= '<li><a href="' . esc_url( get_permalink( $related_post ) ) . '">'
            . esc_html( get_the_title( $related_post ) ) . '</a></li>';
    }
    $html .= '</ul></aside>';
    return $html;
}

/** Auto-append the box to single posts (filterable, off via `wrp_auto_append`). */
function wrp_auto_append( $content ) {
    if ( is_singular( 'post' ) && in_the_loop() && is_main_query()
        && apply_filters( 'wrp_auto_append', true ) ) {
        $content .= wrp_render( get_the_ID() );
    }
    return $content;
}
add_filter( 'the_content', 'wrp_auto_append' );

/** Shortcode: [related_posts count="4"]. */
function wrp_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'count' => 4 ), $atts, 'related_posts' );
    $ids  = wrp_get_related_ids( get_the_ID(), (int) $atts['count'] );
    if ( empty( $ids ) ) {
        return '';
    }
    // Reuse the renderer but honour the shortcode count.
    $count_filter = function () use ( $atts ) {
        return (int) $atts['count'];
    };
    add_filter( 'wrp_count', $count_filter );
    $html = wrp_render( get_the_ID() );
    remove_filter( 'wrp_count', $count_filter );
    return $html;
}
add_shortcode( 'related_posts', 'wrp_shortcode' );

/** Invalidate cached relations when a post is saved or deleted. */
function wrp_invalidate_cache( $post_id ) {
    global $wpdb;
    // Transients are stored as options; delete every wrp_related_* entry for this post.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_wrp_related_' . (int) $post_id . '_' ) . '%'
    ) );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_timeout_wrp_related_' . (int) $post_id . '_' ) . '%'
    ) );
}
add_action( 'save_post', 'wrp_invalidate_cache' );
add_action( 'delete_post', 'wrp_invalidate_cache' );
