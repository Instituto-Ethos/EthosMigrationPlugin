<?php

namespace ethos;

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

    if ( ! empty( $attributes['originatingleadid']?->Id ) ) {
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
        'cnae' => str_replace( [ '-', '/' ], '', $formatted['fut_lk_cnae'] ?? '' ),
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
        'end_cep' => str_replace( '-', '', trim( $attributes['address1_postalcode'] ?? '' ) ),
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

    $user_meta = [
        '_ethos_from_crm' => 1,
        '_ethos_crm_contact_id' => $entity_id,
        '_pmpro_role' => $attributes['fut_bt_financeiro'] ? 'financial' : 'primary',

        'nome_completo' => trim( $attributes['fullname'] ?? '' ),
        'cpf' => str_replace( [ '.', '-' ], '', trim( $attributes['fut_st_cpf'] ?? '' ) ),
        'cargo' => trim( $attributes['jobtitle'] ?? '' ),
        'area' => trim( $formatted['fut_pl_area'] ?? '' ),
        'email' => trim( $attributes['emailaddress1'] ?? '' ),
        'celular' => str_replace( [ '(', ')', ' ', '-' ], '', $attributes['telephone1'] ?? '' ),
        'celular_is_whatsapp' => '',
        'telefone' => '',
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

function import_account( $entity, $force_update = false ) {
    $entity_id = $entity->Id;
    $post_meta = parse_account_into_post_meta( $entity );

    $existing_posts = get_posts( [
        'post_type' => 'organizacao',
        'meta_query' => [
            [ '_ethos_crm_account_id' => $entity_id ],
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

        // @TODO Set featured image

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

function import_contact( $entity, $force_update = false ) {
    $entity_id = $entity->Id;
    $user_meta = parse_contact_into_user_meta( $entity );

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

function import_accounts_command( $args, $assoc_args ) {
    $parsed_args = wp_parse_args( $assoc_args, [
        'update' => false,
    ] );

    $should_update = $parsed_args['update'];

    $iterator = \hacklabr\iterate_crm_entities( 'account', [
        'orderby' => 'name',
        'order' => 'ASC',
    ] );

    $i = 0;

    foreach( $iterator as $account ) {
        $meta = parse_account_into_post_meta( $account );

        \WP_CLI::success( ( ++$i ) . ' — ' . ( $meta['nome_fantasia'] ) );

        foreach ( $meta as $key => $value ) {
            \WP_CLI::log( str_pad( $key, 60, ' ' ) . $value );
        }
    }

    \WP_CLI::success( 'Finished importing accounts from CRM' );

    $iterator = \hacklabr\iterate_crm_entities( 'contact', [
        'orderby' => 'fullname',
        'order' => 'ASC',
    ] );

    $i = 0;

    foreach( $iterator as $contact ) {
        $meta = parse_contact_into_user_meta( $contact );

        \WP_CLI::success( ( ++$i ) . ' — ' . ( $meta['nome_completo'] ) );

        foreach ( $meta as $key => $value ) {
            \WP_CLI::log( str_pad( $key, 60, ' ' ) . $value );
        }
    }

    \WP_CLI::success( 'Finished importing contacts from CRM' );
}

function dont_notify_imported_users ( $send, $user ) {
    $is_imported = get_user_meta( $user->ID, '_ethos_from_crm', true );

    if ( ! empty( $is_imported ) && $is_imported == '1' ) {
        return false;
    }

    return $send;
}
add_filter( 'wp_send_new_user_notification_to_user', 'ethos\\dont_notify_imported_users', 10, 2 );

function register_import_accounts_command() {
    if ( class_exists( '\WP_CLI' ) ) {
        \WP_CLI::add_command( 'import-accounts', 'ethos\\import_accounts_command' );
    }
}
add_action( 'init', 'ethos\\register_import_accounts_command' );
