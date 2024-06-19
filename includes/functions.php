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

/**
 * Remove o termo "sem-categoria" dos posts que possuem outro termo
 */
function remove_sem_categoria( $post ) {
    $terms = get_the_terms( $post->ID, 'category' );

    if ( $terms && ! is_wp_error( $terms ) ) {
        if ( count( $terms ) > 1 ) {
            foreach ( $terms as $term ) {
                if ( $term->slug == 'sem-categoria' ) {
                    echo "\n";
                    echo "Removendo o termo '$term->slug' do post: '$post->ID' - ";
                    wp_remove_object_terms( $post->ID, $term->term_id, 'category' );
                }
            }
        }
    }
}

function set_events_only_date( $post ) {
    $date = get_post_meta( $post->ID, 'data-do-evento', true );

    if ( is_valid_date_format( $date ) ) {
        create_events_from_cedoc( $post, 'ethos\\factory_date' );
    } else {
        $error_message = $date ? "Data inválida: $date" : 'Data inválida';
        update_post_meta( $post->ID, 'ethos_migration_format_error', $error_message );
    }
}

function set_events_date_time( $post ) {
    create_events_from_cedoc( $post, 'ethos\\factory_date_time' );
}

function create_events_from_cedoc( $post, $fn ) {

    if ( ! is_event_created( $post->ID ) ) {
        $dates = $fn( $post );

        if ( is_array( $dates ) ) {
            $start_date = \DateTime::createFromFormat( 'Y-m-d H:i:s', $dates['EventStartDate'] );
            $end_date   = \DateTime::createFromFormat( 'Y-m-d H:i:s', $dates['EventEndDate'] );

            if ( $start_date && $end_date ) {
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

                $venue_id = set_venue_by_post( $post );

                if ( $venue_id ) {
                    $args['EventVenueID'] = $venue_id;
                }

                $event_id = \Tribe__Events__API::createEvent( $args );

                if ( $event_id ) {
                    update_post_meta( $post->ID, 'ethos_migration_tribe_events_id', $event_id );
                    update_post_meta( $event_id, 'ethos_migration_cedoc_id', $post->ID );
                    update_post_meta( $event_id, 'ethos_migration_postmeta_raw', get_post_meta( $post->ID ) );

                    $meta_keys = [
                        'arquivo_documento',
                        'carga_horaria_total',
                        'conteudos_relacionados',
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
                        'slideshare',
                        'temas'
                    ];

                    foreach ( $meta_keys as $meta_key ) {
                        $meta_value = get_post_meta( $post->ID, $meta_key, true );
                        update_post_meta( $event_id, $meta_key, $meta_value );
                    }

                    assign_terms_to_event( $post->ID, $event_id, 'categoria' );
                    assign_terms_to_event( $post->ID, $event_id, 'category' );
                    assign_terms_to_event( $post->ID, $event_id, 'post_tag' );

                } else {
                    echo "\n";
                    \WP_CLI::error( "Erro ao tentar criar evento a partir do post ID $post->ID\n", false );
                }
            }
        } else {
            update_post_meta( $post->ID, 'ethos_migration_format_error', 'Post sem data' );
        }
    } else {
        echo "\n";
        \WP_CLI::warning( "Já existe um evento criado para o Cedoc ID $post->ID\n" );
    }
}

function factory_date( $post ) {
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

function assign_terms_to_event( $post_id, $event_id, $taxonomy ) {
    $terms = wp_get_post_terms( $post_id, $taxonomy );

    if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
        $term_ids = wp_list_pluck( $terms, 'term_id' );
        wp_set_post_terms( $event_id, $term_ids, $taxonomy );
    }
}

function set_venue_by_post( $post ) {

    if ( ! function_exists( 'tribe_get_events' ) ) {
        return false;
    }

    $venue_name = get_post_meta( $post->ID, 'local', true );

    if ( $venue_name ) {
        $venue_id = check_if_venue_exists( $venue_name );

        if ( ! $venue_id ) {
            $venue_args = [
                'post_status' => 'publish',
                'post_title'  => $venue_name,
                'post_type'   => 'tribe_venue'
            ];

            $venue_id = \wp_insert_post( $venue_args );

            if ( ! $venue_id || is_wp_error( $venue_id ) ) {
                echo 'Erro ao criar o local: ' . $venue_name;
                return false;
            } else {
                echo "\nLocal $venue_name criado\n";
                return $venue_id;
            }
        } else {
            echo "\nLocal $venue_name já existe\n";
            return $venue_id;
        }
    }

    return false;

}

function check_if_venue_exists( $venue_name ) {
    $args = [
        'post_type'   => 'tribe_venue',
        'post_status' => 'publish',
        'name'        => sanitize_title( $venue_name ),
    ];

    $existing_venues = get_posts( $args );

    if ( ! empty( $existing_venues ) ) {
        return $existing_venues[0]->ID;
    }

    return false;
}

function factory_date_time( $post ) {
    $date = get_post_meta( $post->ID, 'data-do-evento', true );
    $time = get_post_meta( $post->ID, 'horario', true );

    if ( $time ) {
        $format_time = format_time( $time );
        $start_time  = false;
        $end_time    = false;

        if ( isset( $format_time['error'] ) ) {
            $format_error = $format_time['time'];

            $all_day = [
                'Dia inteiro',
                'Dia todo',
                'Programação diária'
            ];

            if ( in_array( $format_error, $all_day ) ) {
                $start_time = '00:00:00';
                $end_time = '23:59:59';
            } else {
                echo "\nErro ao tentar formatar o horário: $format_error\n";
                update_post_meta( $post->ID, 'ethos_migration_format_error', $format_error );
            }
        } else {
            if ( is_valid_time_format( $format_time['start_date'] ) ) {
                $start_time = $format_time['start_date'];
            } else {
                echo "\nErro ao tentar formatar o horário `start_time`\n";
                update_post_meta( $post->ID, 'ethos_migration_format_error', 'start_time não existe ou é inválido' );
            }

            if ( is_valid_time_format( $format_time['end_date'] ) ) {
                $end_time = $format_time['end_date'];
            } else if ( $start_time ) {
                $start_time_format = new \DateTime( $start_time );
                $start_time_format->modify( '+1 hour' );
                $end_time = $start_time_format->format( 'H:i:s' );
            }
        }

        $start_date = convert_date_to_allday_tec( $date, $start_time );
        $start_date_utc = convert_to_utc( $start_date );

        $end_date = convert_date_to_allday_tec( $date, $end_time );
        $end_date_utc = convert_to_utc( $end_date );

        return [
            'EventStartDate'    => $start_date,
            'EventStartDateUTC' => $start_date_utc,
            'EventEndDate'      => $end_date,
            'EventEndDateUTC'   => $end_date_utc
        ];
    } else {
        echo "\nEsse post não possui o metadado `horario` - ";
        update_post_meta( $post->ID, 'ethos_migration_format_error', 'Post sem horário' );
    }

    return false;
}

function log_not_migrated_events( $post ) {
    $error = get_post_meta( $post->ID, 'ethos_migration_format_error', true );

    if ( $error ) {
        echo "\nPost não migrado: $post->ID, motivo: $error\n";
    } else {
        echo "\nPost não migrado: $post->ID\n";
    }
}

function format_time( $time ) {
    $original_time = $time;
    $time = trim( $time );
    $time = rtrim( $time, '.' );

    // Helper function to format single time
    $format_single_time = function( $time ) {
        if ( preg_match('/^\d{1,2}h$/', $time ) ) {
            return str_pad( str_replace( 'h', '', $time ), 2, '0', STR_PAD_LEFT ) . ":00:00";
        }

        if ( preg_match( '/^\d{1,2}[:h]\d{2}h?$/', $time ) ) {
            $time = str_replace( 'h', ':', $time );
            return str_pad( $time, 5, '0', STR_PAD_LEFT ) . ":00";
        }

        return false;
    };

    // Hora única
    if ( $formatted_time = $format_single_time( $time ) ) {
        $formatted_time = str_replace( '::', ':', $formatted_time );
        return ['start_date' => $formatted_time, 'end_date' => ''];
    }

    // Intervalo de horas
    if ( preg_match( '/^\d{1,2}[:h]?\d{0,2}h? (às|as|a|-) \d{1,2}[:h]?\d{0,2}h?$/i', $time ) ) {
        $times = preg_split( '/ (às|as|a|-) /i', $time );
        if ( count( $times ) == 2 ) {
            $start_date = $format_single_time( trim( $times[0] ) );
            $end_date = $format_single_time( trim( $times[1] ) );

            $start_date = str_replace( '::', ':', $start_date );
            $end_date = str_replace( '::', ':', $end_date );

            if ( $start_date && $end_date ) {
                return ['start_date' => $start_date, 'end_date' => $end_date];
            }
        }
    }

    // Intervalo de horas com prefixo 'das'.
    if ( preg_match( '/^das \d{1,2}[:h]?\d{0,2}h? (às|as|a) \d{1,2}[:h]?\d{0,2}h?$/i', $time ) ) {
        $time = preg_replace( '/(das | às| as| a)/i', '', $time );
        $times = preg_split( '/ /', $time );
        if ( count( $times ) == 2 ) {
            $start_date = $format_single_time( trim( $times[0] ) );
            $end_date   = $format_single_time( trim( $times[1] ) );

            $start_date = str_replace( '::', ':', $start_date );
            $end_date = str_replace( '::', ':', $end_date );

            if ( $start_date && $end_date ) {
                return array(
                    'start_date' => $start_date,
                    'end_date'   => $end_date,
                );
            }
        }
    }

    // Intervalo de horas com prefixo 'das' e descrição adicional.
    if ( preg_match( '/^das \d{1,2}[:h]?\d{0,2}h? (às|as|a) \d{1,2}[:h]?\d{0,2}h?:.*$/i', $time ) ) {
        $parts       = explode( ':', $time, 2 );
        $time        = $parts[0];
        $description = $parts[1];
        $time        = preg_replace( '/(das | às| as| a)/i', '', $time );
        $times       = preg_split( '/ /', $time );

        if ( count( $times ) === 2 ) {
            $start_date = $format_single_time( trim( $times[0] ) );
            $end_date   = $format_single_time( trim( $times[1] ) );

            $start_date = str_replace( '::', ':', $start_date );
            $end_date = str_replace( '::', ':', $end_date );

            if ( $start_date && $end_date ) {
                return array(
                    'start_date'  => $start_date,
                    'end_date'    => $end_date
                );
            }
        }
    }

    return [
        'error' => "Unknown pattern",
        'time' => $original_time
    ];
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

function is_valid_time_format( $time_string ) {
    // "H:i:s"
    $regex = '/^([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/';
    return preg_match( $regex, $time_string ) === 1;
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
