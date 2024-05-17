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

    if ( has_term( 'noticias', 'categoria', $post->ID ) ) {
        $get_tipo_post = '';
        $get_tipo_post = get_term_by( 'slug', 'noticias', 'tipo_post' );
        wp_set_post_terms( $post->ID, $get_tipo_post->term_id, 'tipo_post', true );
    }

    if ( has_term( 'releases', 'categoria', $post->ID ) ) {
        $get_tipo_post = '';
        $get_tipo_post = get_term_by( 'slug', 'releases', 'tipo_post' );
        wp_set_post_terms( $post->ID, $get_tipo_post->term_id, 'tipo_post', true );
    }

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
        wp_set_post_tags( $post->ID, 'parcerias', true );
    }

    clean_post_cache( $post->ID );
}

/**
 * Altera tags específicas para category no post
 */
function change_tag_to_category( $post ) {
    $tags = [
        'ethos-meio-ambiente',
        'ethos-integridade',
        'ethos-direitos-humanos',
        'ethos-institucional',
        'ethos-gestao-sustentavel'
    ];

    foreach ( $tags as $tag ) {
        if ( has_term( $tag, 'post_tag', $post->ID ) ) {
            $get_category = get_term_by( 'slug', $tag, 'category' );

            if ( $get_category ) {
                wp_set_post_categories( $post->ID, $get_category->term_id, true );

                $post_tags = wp_get_post_terms( $post->ID, 'post_tag', ['fields'=>'names'] );
                $pos = array_search( $tag, $post_tags );

                if ( false !== $pos ) {
                    unset( $post_tags[$pos] );
                    wp_set_post_terms( $post->ID, $post_tags, 'post_tag', true );
                }
            }
        }
    }
}

/**
 * Altera tag 'posicionamento-institucional' para tipo_post no post
 */
function set_posicionamento_institucional( $post ) {
    $tag = 'posicionamento-institucional';
    if ( has_term( $tag, 'post_tag', $post->ID ) ) {
        $get_tipo_post = get_term_by( 'slug', $tag, 'tipo_post' );

        if ( $get_tipo_post ) {
            wp_set_post_terms( $post->ID, $get_tipo_post->term_id, 'tipo_post', true );

            $post_tags = wp_get_post_terms( $post->ID, 'post_tag', ['fields'=>'names'] );
            $pos = array_search( $tag, $post_tags );

            if ( false !== $pos ) {
                unset( $post_tags[$pos] );
                wp_set_post_terms( $post->ID, $post_tags, 'post_tag', true );
            }
        }
    }
}

/**
 * Altera tag 'opinioes-e-analises' para tipo_post no post
 */
function set_opinioes_e_analises( $post ) {
    $tag = 'opinioes-e-analises';
    if ( has_term( $tag, 'post_tag', $post->ID ) ) {
        $get_tipo_post = get_term_by( 'slug', $tag, 'tipo_post' );

        if ( $get_tipo_post ) {
            wp_set_post_terms( $post->ID, $get_tipo_post->term_id, 'tipo_post', true );

            $post_tags = wp_get_post_terms( $post->ID, 'post_tag', ['fields'=>'names'] );
            $pos = array_search( $tag, $post_tags );

            if ( false !== $pos ) {
                unset( $post_tags[$pos] );
                wp_set_post_terms( $post->ID, $post_tags, 'post_tag', true );
            }
        }
    }
}

/**
 * Define tipo_post 'noticias' nos posts com a categoria 'noticias'
 */
function set_noticias( $post ) {
    $categoria = 'noticias';
    if ( has_term( $categoria, 'categoria', $post->ID ) ) {
        $get_tipo_post = get_term_by( 'slug', $categoria, 'tipo_post' );

        if ( $get_tipo_post ) {
            wp_set_post_terms( $post->ID, $get_tipo_post->term_id, 'tipo_post', true );
        }
    }
}