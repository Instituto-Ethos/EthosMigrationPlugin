<?php

namespace ethos;

function parse_account_into_registration( $entity ) {
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
        $logradouro_prefix = trim( $attributes['fut_lk_tipologradouro']?->Name );
        if ( ! str_starts_with( strtolower( $logradouro ), strtolower( $logradouro_prefix ) ) ) {
            $logradouro = $logradouro_prefix . ' ' . $logradouro;
        }
    }

    $post_meta = [
        '_ethos_from_crm' => 1,
        '_ethos_crm_id' => $entity_id,

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

    return $post_meta;
}

function import_account( $entity ) {
    $entity_id = $entity->Id;
    $post_meta = parse_account_into_registration( $entity );

    $existing_posts = get_posts( [
        'post_type' => 'organizacao',
        'meta_query' => [
            [ '_ethos_crm_id' => $entity_id ],
        ],
    ] );

    if ( empty( $existing_posts ) ) {
        wp_insert_post( [
            'post_type' => 'organizacao',
            'post_title' => $post_meta['nome_fantasia'],
            'post_content' => '',
            'post_status' => 'publish',
            'meta_input' => $post_meta,
        ] );

        // @TODO Set featured image
    } else {
        wp_update_post( [
            'ID' => $existing_posts[0]->ID,
            'post_title' => $post_meta['nome_fantasia'],
            'meta_input' => $post_meta,
        ] );
    }
}

function import_accounts_command() {
    $iterator = \hacklabr\iterate_crm_entities( 'account', [
        'orderby' => 'name',
        'order' => 'ASC',
    ] );

    $i = 0;

    foreach( $iterator as $account ) {
        $meta = parse_account_into_registration( $account );

        \WP_CLI::success( ( ++$i ) . "\t\t" . ( $meta['nome_fantasia'] ) );

        foreach ( $meta as $key => $value ) {
            \WP_CLI::log( str_pad( $key, 40, ' ' ) . $value );
        }
    }

    \WP_CLI::success( 'Finished importing accounts from CRM' );
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
