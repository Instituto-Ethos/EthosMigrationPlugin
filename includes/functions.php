<?php

namespace ethos;

// ethos\example
function example( $post ) {
    // example
}

/**
 * Altera post type para 'publicacao'
 */
function set_post_type_publicacao( $post ) {
    $post->post_type = 'publicacao';
    clean_post_cache( $post->ID );
}

/**
 * Altera post type para 'post'
 */
function set_post_type_post( $post ) {
    $post->post_type = 'post';
    clean_post_cache( $post->ID );
}

/**
 * Altera post type para 'page'
 */
function set_post_type_page( $post ) {
    $post->post_type = 'page';
    clean_post_cache( $post->ID );
}

/**
 * Altera post type para 'iniciativa'
 */
function set_post_type_iniciativa( $post ) {
    $post->post_type = 'iniciativa';

    if ( has_term( 'parcerias', 'category', $post->ID ) ) {
        wp_set_post_tags( $post->ID, 'parcerias' );
    }

    clean_post_cache( $post->ID );
}
