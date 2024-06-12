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

function set_events_only_date( $post ) {
    $date = get_post_meta( $post->ID, 'data-do-evento', true );

    if ( is_valid_date_format( $date ) ) {
        create_events_from_cedoc( $post );
    }
}

function create_events_from_cedoc( $post ) {

    if ( ! is_event_created( $post->ID ) ) {
        $dates      = factory_dates( $post );
        $start_date = \DateTime::createFromFormat( 'Y-m-d H:i:s', $dates['EventStartDate'] );
        $end_date   = \DateTime::createFromFormat( 'Y-m-d H:i:s', $dates['EventEndDate'] );

        $args = [
            'EventAllDay'             => 'yes',
            'EventCurrencyCode'       => 'BRL',
            'EventCurrencyPosition'   => 'prefix',
            'EventCurrencySymbol'     => 'R$',
            'EventDateTimeSeparator'  => ' @ ',
            'EventEndDate'            => $end_date->format( 'Y-m-d' ),
            'EventEndHour'            => $end_date->format( 'h' ),
            'EventEndMeridian'        => $end_date->format( 'a' ),
            'EventEndMinute'          => $end_date->format( 'i' ),
            'EventStartDate'          => $start_date->format( 'Y-m-d' ),
            'EventStartHour'          => $start_date->format( 'h' ),
            'EventStartMeridian'      => $start_date->format( 'a' ),
            'EventStartMinute'        => $start_date->format( 'i' ),
            'EventTimeRangeSeparator' => ' - ',
            'EventTimezone'           => 'UTC-3',
            'EventURL'                => get_post_meta( $post->ID, 'inscricoes', true ),
            'menu_order'              => '-1',
            'post_author'             => $post->post_author,
            'post_category'           => $post->post_category,
            'post_content_filtered'   => $post->post_content_filtered,
            'post_content'            => $post->post_content,
            'post_date_gmt'           => $post->post_date_gmt,
            'post_date'               => $post->post_date,
            'post_excerpt'            => $post->post_excerpt,
            'post_modified_gmt'       => $post->post_modified_gmt,
            'post_modified'           => $post->post_modified,
            'post_name'               => $post->post_name,
            'post_parent'             => $post->post_parent,
            'post_status'             => $post->post_status,
            'post_title'              => $post->post_title,
            'post_type'               => \Tribe__Events__Main::POSTTYPE
        ];

        $event_id = \Tribe__Events__API::createEvent( $args );

        if ( $event_id ) {
            add_post_meta( $post->ID, 'ethos_migration_tribe_events_id', $event_id );
            add_post_meta( $event_id, 'ethos_migration_cedoc_id', $post->ID );
            add_post_meta( $event_id, 'ethos_migration_postmeta_raw', get_post_meta( $post->ID ) );

            $meta_keys = [
                'curso',
                'data_mysql',
                'data-do-evento',
                'horario',
                'informacoes_complementares',
                'inscricoes',
                'local',
                'post_views_count',
                'projetos_relacionados',
                'projetos',
                'publicacoes_relacionadas',
                'saiba_mais',
                'temas'
            ];

            foreach ( $meta_keys as $meta_key ) {
                $meta_value = get_post_meta( $post->ID, $meta_key, true );
                add_post_meta( $event_id, $meta_key, $meta_value );
            }
        } else {
            echo "\n";
            \WP_CLI::error( "Erro ao tentar criar evento a partir do post ID $post->ID\n", false );
        }

    } else {
        echo "\n";
        \WP_CLI::warning( "Já existe um evento criado para o Cedoc ID $post->ID\n" );
    }
}

function factory_dates( $post ) {
    $date = get_post_meta( $post->ID, 'data-do-evento', true );

    $start_date = convert_date_to_allday_tec( $date, '00:00:00' );
    $start_date_utc = convert_to_utc( $start_date );

    $end_date = convert_date_to_allday_tec( $date, '23:59:59' );
    $end_date_utc = convert_to_utc( $end_date );

    return [
        'EventStartDate'    => $start_date,
        'EventStartDateUTC' => $start_date_utc,
        'EventEndDate'      => $end_date,
        'EventEndDateUTC'   => $end_date_utc
    ];
}

/**
 * @todo: Make function to convert dates and times
 */
function factory_dates_and_times( $post ) {
    return false;
}

function convert_date_to_allday_tec( $date, $time ) {
    $date_time = \DateTime::createFromFormat( 'd/m/Y H:i:s', $date . ' ' . $time );

    if ( $date_time ) {
        return $date_time->format( 'Y-m-d H:i:s' );
    }

    return false;
}

function convert_to_utc( $local_datetime ) {
    $timezone_string = get_option( 'timezone_string' ) ?: 'America/Sao_Paulo';
    $timezone = new \DateTimeZone( $timezone_string );

    $date = new \DateTime( $local_datetime, $timezone );
    $date->setTimezone( new \DateTimeZone( 'UTC' ) );

    return $date->format( 'Y-m-d H:i:s' );
}

function is_valid_date_format( $date_string ) {
    // "dd/mm/yyyy"
    $regex = '/^([0-2][0-9]|(3)[0-1])\/(0[1-9]|1[0-2])\/\d{4}$/';
    return preg_match($regex, $date_string) === 1;
}

function is_event_created( $post_id ) {

    if ( ! function_exists( 'tribe_get_events' ) ) {
        return false;
    }

    $args = [
        'post_type'      => 'tribe_events',
        'posts_per_page' => -1,
        'eventDisplay'   => 'all',
        'orderby'        => 'event_date',
        'order'          => 'ASC',
        'post_status'    => ['publish', 'pending', 'draft', 'future', 'private', 'inherit'],
        'meta_query'     => [
            [
                'key'     => 'ethos_migration_cedoc_id',
                'value'   => $post_id,
                'compare' => '='
            ]
        ],
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ];

    $get_posts = tribe_get_events( $args );

    return ! empty( $get_posts );

}
