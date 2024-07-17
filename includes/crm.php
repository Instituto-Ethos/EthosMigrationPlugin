<?php

namespace ethos;

/**
 * Better than calling `array_filter` than `array_unique` because the latter
 * preserve keys
 */
function array_unique_values( $array ) {
    $return = [];

    foreach ( $array as $el ) {
        if ( ! empty( $el ) && ! in_array( $el, $return ) ) {
            $return[] = $el;
        }
    }

    return $return;
}

function get_pmpro_level_id ( $post_id, $level_name ) {
    $level_slugs = [
        'Conexão' => 'conexao',
        'Essencial' => 'essencial',
        'Institucional' => 'institucional',
        'Vivência' => 'vivencia',
    ];

    $level_slug = $level_slugs[ $level_name ] ?? null;

    if ( empty( $level_slug ) ) {
        return null;
    }

    return \hacklabr\Fields\get_pmpro_level_options( $post_id )[ $level_slug ] ?? null;
}

function sanitize_number( $string ) {
    if ( empty( $string ) ) {
        return '';
    }
    return str_replace( [ '+', '-' ], '', filter_var( $string, FILTER_SANITIZE_NUMBER_INT ) );
}

function parse_account_into_post_meta( $entity ) {
    $entity_id = $entity->Id;
    $attributes = $entity->Attributes;
    $formatted = $entity->FormattedValues;

    $revenue_base = $attributes['revenue_base'] ?? 0;
    if ( $revenue_base < 10_000_000 ) {
        $revenue = 'small';
    } elseif ( $revenue_base < 300_000_000 ) {
        $revenue = 'medium';
    } else {
        $revenue = 'large';
    }

    $size_base = strtolower( $formatted['fut_pl_porte'] ?? 'Microempresa');
    if ( str_contains( $size_base, 'micro' ) ) {
        $size = 'micro';
    } elseif ( str_contains( $size_base, 'pequena' ) ) {
        $size = 'small';
    } elseif ( str_contains( $size_base, 'média' ) ) {
        $size = 'medium';
    } else {
        $size = 'large';
    }

    if ( ! empty( $attributes['entityimage_url'] ) ) {
        $logo = \hacklabr\get_crm_server_url() . $attributes['entityimage_url'];
    } else {
        $logo = '';
    }

    $logradouro = trim( $attributes['fut_address1_logradouro'] ?? '' );
    if ( ! empty( $attributes['fut_lk_tipologradouro']?->Name ) ) {
        $logradouro_prefix = trim( $attributes['fut_lk_tipologradouro']->Name );

        if ( ! str_starts_with( strtolower( $logradouro ), strtolower( $logradouro_prefix ) ) ) {
            $logradouro = $logradouro_prefix . ' ' . $logradouro;
        }
    }

    if ( ! empty( $attributes['originatingleadid'] ) ) {
        $lead_id = $attributes['originatingleadid']->Id;
    } else {
        $lead_id = null;
    }

    $post_meta = [
        '_ethos_from_crm' => 1,
        '_ethos_crm_account_id' => $entity_id,
        '_ethos_crm_lead_id' => $lead_id,

        'cnpj' => trim( $attributes['fut_st_cnpjsemmascara'] ?? '' ),
        'razao_social' => trim( $attributes['fut_st_razaosocial'] ?? '' ),
        'nome_fantasia' => trim( $attributes['name'] ?? '' ),
        'segmento' => trim( $attributes['fut_lk_setor']?->Name ?? '' ),
        'cnae' => sanitize_number( $formatted['fut_lk_cnae'] ?? '' ),
        'faturamento_anual' => $revenue,
        'inscricao_estadual' => trim( $attributes['fut_st_inscricaoestadual'] ?? '' ),
        'inscricao_municipal' => trim( $attributes['fut_st_inscricaomunicipal'] ?? '' ),
        'logomarca' => $logo,
        'website' => trim( $attributes['websiteurl'] ?? '' ),
        'num_funcionarios' => $attributes['numberofemployees'] ?? 0,
        'porte' => $size,
        'end_logradouro' => $logradouro,
        'end_numero' => trim( $attributes['fut_address1_nro'] ?? '' ),
        'end_complemento' => trim( $attributes['address1_line2'] ?? '' ),
        'end_bairro' => trim( $attributes['address1_line3'] ?? '' ),
        'end_cidade' => trim( $attributes['address1_city'] ?? '' ),
        'end_estado' => $formatted['fut_pl_estado'] ?? '',
        'end_cep' => sanitize_number( $attributes['address1_postalcode'] ?? '' ),
    ];

    foreach ( $attributes as $key => $value ) {
        if ( is_object( $value ) ) {
            $post_meta['_ethos_crm:' . $key ] = json_encode( $value, JSON_FORCE_OBJECT );
        } elseif ( ! empty( $value ) ) {
            $post_meta['_ethos_crm:' . $key ] = $value;
        }
    }

    return $post_meta;
}

function parse_contact_into_user_meta( $entity ) {
    $entity_id = $entity->Id;
    $attributes = $entity->Attributes;
    $formatted = $entity->FormattedValues;

    /**
     * _ethos_admin
     * _pmpro_group
     */

    $phones = [
        sanitize_number( $attributes['mobilephone'] ?? '' ),
        sanitize_number( $attributes['telephone1'] ?? '' ),
        sanitize_number( $attributes['telephone2'] ?? '' ),
    ];
    $phones = array_unique_values( $phones );

    $user_meta = [
        '_ethos_from_crm' => 1,
        '_ethos_crm_contact_id' => $entity_id,
        '_pmpro_role' => $attributes['fut_bt_financeiro'] ? 'financial' : 'primary',

        'nome_completo' => trim( $attributes['fullname'] ?? '' ),
        'cpf' => sanitize_number( $attributes['fut_st_cpf'] ?? '' ),
        'cargo' => trim( $attributes['jobtitle'] ?? '' ),
        'area' => trim( $formatted['fut_pl_area'] ?? '' ),
        'email' => trim( $attributes['emailaddress1'] ?? '' ),
        'celular' => $phones[ 0 ] ?? '',
        'celular_is_whatsapp' => '',
        'telefone' => $phones[ 1 ] ?? '',
    ];

    foreach ( $attributes as $key => $value ) {
        if ( is_object( $value ) ) {
            $user_meta['_ethos_crm:' . $key ] = json_encode( $value, JSON_FORCE_OBJECT );
        } elseif ( ! empty( $value ) ) {
            $user_meta['_ethos_crm:' . $key ] = $value;
        }
    }

    return $user_meta;
}

function get_account( $entity_id ) {
    $existing_posts = get_posts( [
        'post_type' => 'organizacao',
        'meta_query' => [
            [ '_ethos_crm_account_id' => $entity_id ],
        ],
    ] );

    if ( empty( $existing_posts ) ) {
        $entity = \hacklabr\get_crm_entity_by_id( 'account', $entity_id );

        if ( ! empty( $entity ) ) {
            return import_account( $entity, false );
        }
    } else {
        return $existing_posts[0]->ID;
    }

    return null;
}

function import_account( $entity, $force_update = false ) {
    $entity_id = $entity->Id;
    $attributes = $entity->Attributes;
    $formatted = $entity->FormattedValues;

    $post_meta = parse_account_into_post_meta( $entity );

    if ( class_exists( '\WP_CLI' ) ) {
        \WP_CLI::debug( "Importing account {$post_meta['nome_fantasia']} — {$post_meta['cnpj']}" );
    }

    $existing_posts = get_posts( [
        'post_type' => 'organizacao',
        'meta_query' => [
            [ 'key' => '_ethos_crm_account_id', 'value' => $entity_id ],
        ],
    ] );

    if ( empty( $existing_posts ) ) {
        $post_id = wp_insert_post( [
            'post_type' => 'organizacao',
            'post_title' => $post_meta['nome_fantasia'],
            'post_content' => '',
            'post_status' => 'publish',
            'meta_input' => $post_meta,
        ] );

        // Update author after post creation to avoid infinite loop
        if ( ! empty( $attributes['primarycontactid'] ) && ! empty( $formatted['fut_pl_tipo_associacao'] ) ) {
            set_main_contact( $post_id, $attributes['primarycontactid']->Id, $formatted['fut_pl_tipo_associacao'] );
        }

        // @TODO Set featured image

        if ( class_exists( '\WP_CLI' ) ) {
            \WP_CLI::debug( "Created post with ID = {$post_id}" );
        }

        return $post_id;
    }

    if ( $force_update ) {
        wp_update_post( [
            'ID' => $existing_posts[0]->ID,
            'post_title' => $post_meta['nome_fantasia'],
            'meta_input' => $post_meta,
        ] );
    }

    return $existing_posts[0]->ID;
}

function get_contact( $entity_id ) {
    $existing_users = get_users( [
        'meta_query' => [
            [ 'key' => '_ethos_crm_contact_id', 'value' => $entity_id ],
        ],
    ] );

    if ( empty( $existing_users ) ) {
        $entity = \hacklabr\get_crm_entity_by_id( 'contact', $entity_id );

        if ( ! empty( $entity ) ) {
            return import_contact( $entity, false );
        }
    } else {
        return $existing_users[0]->ID;
    }

    return null;
}

function import_contact( $entity, $force_update = false ) {
    $entity_id = $entity->Id;
    $user_meta = parse_contact_into_user_meta( $entity );

    if ( class_exists( '\WP_CLI' ) ) {
        \WP_CLI::debug( "Importing contact {$user_meta['nome_completo']} — {$user_meta['cpf']}" );
    }

    $existing_users = get_users( [
        'meta_query' => [
            [ 'key' => '_ethos_crm_contact_id', 'value' => $entity_id ],
        ],
    ] );

    if ( empty( $existing_users ) ) {
        $password = wp_generate_password( 16 );

        $user_id = wp_insert_user( [
            'display_name' => $user_meta['nome_completo'],
            'user_email' => $user_meta['email'],
            'user_login' => sanitize_title( $user_meta['nome_completo'] ),
            'user_pass' => $password,
            'role' => 'subscriber',
            'meta_input' => $user_meta,
        ] );

        if ( ! empty( $entity->Attributes['parentcustomerid'] ) ) {
            add_contact_to_account( $user_id, $entity->Attributes['parentcustomerid']->Id );
        }

        if ( class_exists( '\WP_CLI' ) ) {
            \WP_CLI::debug( "Created user with ID = {$user_id}" );
        }

        return $user_id;
    }

    if ( $force_update ) {
        wp_update_user( [
            'ID' => $existing_users[0]->ID,
            'display_name' => $user_meta['nome_completo'],
            'user_email' => $user_meta['email'],
            'meta_input' => $user_meta,
        ] );
    }

    return $existing_users[0]->ID;
}

function approve_contact( $user_id, $level_id ) {
    return \PMPro_Approvals::approveMember( $user_id, $level_id, true );
}

function add_contact_to_account( $user_id, $account_id ) {
    $existing_group_id = get_user_meta( $user_id, '_pmpro_group', true );
    if ( ! empty( $existing_group_id ) ) {
        return (int) $existing_group_id;
    }

    $post_id = get_account( $account_id );

    $group_id = (int) ( get_post_meta( $post_id, '_pmpro_group', true ) ?? 0 );

    if ( ! empty( $group_id ) ) {
        $membership = \hacklabr\add_user_to_pmpro_group( $user_id, $group_id );

        wp_update_user([
            'ID' => $user_id,
            'meta_input' => [
                '_pmpro_group' => $group_id,
            ],
        ]);

        approve_contact( $group_id, $membership->group_child_level_id );
    }

    return $group_id ?: null;
}

function set_main_contact( $post_id, $contact_id, $level_name ) {
    $existing_group_id = get_post_meta( $post_id, '_pmpro_group', true );
    if ( ! empty( $existing_group_id ) ) {
        return (int) $existing_group_id;
    }

    $user_id = get_contact( $contact_id ) ?? 0;

    $level_id = get_pmpro_level_id( $post_id, $level_name );

    $group = \hacklabr\create_pmpro_group( $user_id, $level_id );

    wp_update_user([
        'ID' => $user_id,
        'meta_input' => [
            '_ethos_admin' => '1',
            '_pmpro_group' => $group->id,
            '_pmpro_role' => 'primary',
        ],
    ]);

    wp_update_post([
        'ID' => $post_id,
        'post_author' => $user_id,
        'meta_input' => [
            '_pmpro_group' => $group->id,
        ],
    ]);

    approve_contact( $user_id, $level_id );

    return $group->id;
}

function import_accounts_command( $args, $assoc_args ) {
    $parsed_args = wp_parse_args( $assoc_args, [
        'update' => false,
    ] );

    $force_update = $parsed_args['update'];

    $accounts = \hacklabr\iterate_crm_entities( 'account', [
        'orderby' => 'name',
        'order' => 'ASC',
    ] );

    $count = 0;

    foreach( $accounts as $account ) {
        import_account( $account, $force_update );
        $count++;
    }

    \WP_CLI::success( "Finished importing {$count} accounts." );

    $contacts = \hacklabr\iterate_crm_entities( 'contact', [
        'orderby' => 'fullname',
        'order' => 'ASC',
    ] );

    $count = 0;

    foreach( $contacts as $contact ) {
        import_contact( $contact, $force_update );
        $count++;
    }

    \WP_CLI::success( "Finished importing {$count} contacts." );
}

function dont_notify_imported_users ( $send, $user ) {
    $is_imported = get_user_meta( $user->ID, '_ethos_from_crm', true );

    if ( ! empty( $is_imported ) && $is_imported == '1' ) {
        return false;
    }

    return $send;
}
add_filter( 'pmpro_approvals_after_approve_member_send_emails', 'ethos\\dont_notify_imported_users', 20, 2 );
add_filter( 'pmpro_wp_new_user_notification', 'ethos\\dont_notify_imported_users', 20, 2 );
add_filter( 'wp_send_new_user_notification_to_admin', 'ethos\\dont_notify_imported_users', 20, 2 );
add_filter( 'wp_send_new_user_notification_to_user', 'ethos\\dont_notify_imported_users', 20, 2 );

function register_import_accounts_command() {
    if ( class_exists( '\WP_CLI' ) ) {
        \WP_CLI::add_command( 'import-accounts', 'ethos\\import_accounts_command' );
    }
}
add_action( 'init', 'ethos\\register_import_accounts_command' );
