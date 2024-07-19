<?php

namespace ethos;

/**
 * Better than calling `array_filter` than `array_unique` because the latter
 * preserve keys
 */
function array_unique_values( array $array ) {
    $return = [];

    foreach ( $array as $el ) {
        if ( ! empty( $el ) && ! in_array( $el, $return ) ) {
            $return[] = $el;
        }
    }

    return $return;
}

/**
 * If inside `wp` CLI, prints the message
 *
 * @param string $message The message to be printed
 * @param string $level One of 'debug', 'error', 'log', 'success' or 'warning'
 */
function cli_log( string $message, string $level = 'log' ) {
    if ( class_exists( '\WP_CLI' ) ) {
        call_user_func( [ \WP_CLI::class, $level ], $message );
    }
}

function generate_unique_email( $email, $account ) {
    $email_parts = explode( '@', $email );
    $folder = sanitize_title( $account->Attributes['name'] );
    return $email_parts[0] . '+' . $folder . '@' . $email_parts[1];
}

function generate_unique_user_login( $user_name ) {
	$login_base = substr( sanitize_title( $user_name ), 0, 60 );

    if ( empty( get_user_by( 'login', $login_base ) ) ) {
        return $login_base;
    }

    $i = 2;

    while ( true ) {
        $login = $login_base . '-' . $i;

        if ( empty( get_user_by( 'login', $login ) ) ) {
            return $login;
        }

        $i++;
    }
}

function get_pmpro_level_id( $post_id, $level_name ) {
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

function cache_crm_entity( $entity ) {
    global $ethos_crm_cache;

    if ( empty( $ethos_crm_cache ) ) {
        $ethos_crm_cache = [];
    } else if ( empty( $ethos_crm_cache[ $entity->LogicalName ] ) ) {
        $ethos_crm_cache[ $entity->LogicalName ] = [];
    }

    $ethos_crm_cache[ $entity->LogicalName ][ $entity->Id ] = $entity;
    return $entity;
}

function fetch_crm_entity( $entity_type, $entity_id ) {
    global $ethos_crm_cache;

    $cached_value = $ethos_crm_cache[ $entity_type ][ $entity_id ] ?? null;

    if ( ! empty( $cached_value ) ) {
        return $cached_value;
    }

    $entity = \hacklabr\get_crm_entity_by_id( $entity_type, $entity_id );
    return cache_crm_entity( $entity );
}

function get_account_by_contact( $contact ) {
    $account_id = $contact->Attributes['parentcustomerid']?->Id ?? null;
    if ( empty( $account_id ) ) {
        return null;
    }
    return fetch_crm_entity( 'account', $account_id ) ?? null;
}

function is_active_account( $account ) {
    $account_status = $account->FormattedValues['fut_pl_associacao'] ?? '';
    return in_array( $account_status, ['Associado', 'Grupo Econômico'] );
}

function is_active_contact( $contact ) {
    $account = get_account_by_contact( $contact );
    if ( empty( $account ) ) {
        return false;
    }
    return is_active_account( $account );
}

function is_parent_company( $account ) {
    return strlen( $account->Attributes['fut_txt_childnode'] ?? '' ) > 169;
}

function is_subsidiary_company( $account ) {
    return $account->Attributes['fut_bt_pertencegrupo'] ?? false;
}

function parse_account_into_post_meta( $account ) {
    $account_id = $account->Id;
    $attributes = $account->Attributes;
    $formatted = $account->FormattedValues;

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
        '_ethos_crm_account_id' => $account_id,
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

function parse_contact_into_user_meta( $contact ) {
    $contact_id = $contact->Id;
    $attributes = $contact->Attributes;
    $formatted = $contact->FormattedValues;

    $account = get_account_by_contact( $contact );

    $phones = [
        sanitize_number( $attributes['mobilephone'] ?? '' ),
        sanitize_number( $attributes['telephone1'] ?? '' ),
        sanitize_number( $attributes['telephone2'] ?? '' ),
    ];
    $phones = array_unique_values( $phones );

    $email = trim( $attributes['emailaddress1'] ?? '@' );
    $email = is_subsidiary_company( $account ) ? generate_unique_email( $email, $account ) : $email;

    $user_meta = [
        '_ethos_from_crm' => 1,
        '_ethos_crm_account_id' => $attributes['parentcustomerid']?->Id ?? '',
        '_ethos_crm_contact_id' => $contact_id,
        '_pmpro_role' => $attributes['fut_bt_financeiro'] ? 'financial' : 'primary',

        'nome_completo' => trim( $attributes['fullname'] ?? '' ),
        'cpf' => sanitize_number( $attributes['fut_st_cpf'] ?? '' ),
        'cargo' => trim( $attributes['jobtitle'] ?? '' ),
        'area' => trim( $formatted['fut_pl_area'] ?? '' ),
        'email' => $email,
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

function get_account( $account_id ) {
    $existing_posts = get_posts( [
        'post_type' => 'organizacao',
        'meta_query' => [
            [ 'key' => '_ethos_crm_account_id', 'value' => $account_id ],
        ],
    ] );

    if ( empty( $existing_posts ) ) {
        $account = fetch_crm_entity( 'account', $account_id );

        if ( ! empty( $account ) ) {
            return import_account( $account, false );
        }
    } else {
        return $existing_posts[0]->ID;
    }

    return null;
}

function import_account( $account, $force_update = false ) {
    $account_id = $account->Id;
    $attributes = $account->Attributes;
    $formatted = $account->FormattedValues;

    $post_meta = parse_account_into_post_meta( $account );

    if ( is_active_account( $account ) ) {
        cli_log( "Importing account {$post_meta['nome_fantasia']} — {$post_meta['cnpj']}", 'debug' );
    } else {
        cli_log( "Skipping account {$post_meta['nome_fantasia']} — {$post_meta['cnpj']}", 'debug' );
        return null;
    }

    $existing_posts = get_posts( [
        'post_type' => 'organizacao',
        'meta_query' => [
            [ 'key' => '_ethos_crm_account_id', 'value' => $account_id ],
        ],
    ] );

    if ( empty( $existing_posts ) ) {
        $post_parent = 0;

        if ( is_subsidiary_company( $account ) ) {
            $post_parent = get_account( $attributes['parentaccountid']->Id ) ?? 0;
        }

        $post_id = wp_insert_post( [
            'post_type' => 'organizacao',
            'post_title' => $post_meta['nome_fantasia'],
            'post_content' => '',
            'post_status' => 'publish',
            'post_parent' => $post_parent,
            'meta_input' => $post_meta,
        ] );

        if ( ! empty( $attributes['primarycontactid'] ) && ! empty( $formatted['fut_pl_tipo_associacao'] ) ) {
            // Update author after post creation to avoid infinite loop
            set_main_contact( $post_id, $attributes['primarycontactid']->Id, $formatted['fut_pl_tipo_associacao'] );
        }

        if ( ! empty( $attributes['i4d_aprovador_cortesia'] ) ) {
            set_approver( $post_id, $attributes['i4d_aprovador_cortesia']->Id );
        }

        // @TODO Set featured image

        if ( class_exists( '\WP_CLI' ) ) {
            cli_log( "Created post with ID = {$post_id}", 'debug' );
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

function get_contact( $contact_id, $account_id ) {
    $existing_users = get_users( [
        'meta_query' => [
            [ 'key' => '_ethos_crm_account_id', 'value' => $account_id ],
            [ 'key' => '_ethos_crm_contact_id', 'value' => $contact_id ],
        ],
    ] );

    if ( empty( $existing_users ) ) {
        $contact = fetch_crm_entity( 'contact', $contact_id );

        if ( ! empty( $contact ) ) {
            return import_contact( $contact, false, false );
        }
    } else {
        return $existing_users[0]->ID;
    }

    return null;
}

function import_contact( $contact, $force_import = false, $force_update = false ) {
    $contact_id = $contact->Id;
    $attributes = $contact->Attributes;

    $user_meta = parse_contact_into_user_meta( $contact );

    cli_log( "Importing contact {$user_meta['nome_completo']} — {$user_meta['cpf']}", 'debug' );

    // Don't import users without organization
    if ( ! is_active_contact( $contact ) && ! $force_import ) {
        return null;
    }

    $account = get_account_by_contact( $attributes['parentcustomerid']->Id );

    $existing_users = get_users( [
        'meta_query' => [
            [ 'key' => '_ethos_crm_account_id', 'value' => $account->Id ],
            [ 'key' => '_ethos_crm_contact_id', 'value' => $contact_id ],
        ],
    ] );

    if ( empty( $existing_users ) ) {
        $password = wp_generate_password( 16 );

        $user_id = wp_insert_user( [
            'display_name' => $user_meta['nome_completo'],
            'user_email' => $user_meta['email'],
            'user_login' => generate_unique_user_login( $user_meta['nome_completo'] ),
            'user_pass' => $password,
            'role' => 'subscriber',
            'meta_input' => $user_meta,
        ] );

        if ( is_wp_error( $user_id ) ) {
            cli_log( $user_id->get_error_message(), 'warning' );
            return null;
        }

        $post_id = get_account( $attributes['parentcustomerid']->Id );
        add_contact_to_organization( $user_id, $post_id );

        cli_log( "Created user with ID = {$user_id}", 'debug' );

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

function add_contact_to_organization( $user_id, $post_id ) {
    $existing_group_id = get_user_meta( $user_id, '_pmpro_group', true );
    if ( ! empty( $existing_group_id ) ) {
        return (int) $existing_group_id;
    }

    $group_id = (int) ( get_post_meta( $post_id, '_pmpro_group', true ) ?? 0 );

    if ( ! empty( $group_id ) ) {
        $membership = \hacklabr\add_user_to_pmpro_group( $user_id, $group_id );

        update_user_meta( $user_id, '_pmpro_group', $group_id );

        approve_contact( $group_id, $membership->group_child_level_id );
    }

    return $group_id ?: null;
}

function set_main_contact( $post_id, $contact_id, $level_name ) {
    $existing_group_id = get_post_meta( $post_id, '_pmpro_group', true );
    if ( ! empty( $existing_group_id ) ) {
        return (int) $existing_group_id;
    }

    $account_id = get_post_meta( $post_id, '_ethos_crm_account_id', true );

    $user_id = get_contact( $contact_id, $account_id ) ?? 0;

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

function set_approver( $post_id, $contact_id ) {
    $account_id = get_post_meta( $post_id, '_ethos_crm_account_id', true );

    $user_id = get_contact( $contact_id, $account_id ) ?? 0;

    if ( empty( get_user_meta( $user_id, '_pmpro_group', true ) ) ) {
        add_contact_to_organization( $user_id, $post_id );
    }

    update_user_meta( $user_id, '_ethos_approver', '1' );
}

function count_economic_groups_command() {
    $accounts = \hacklabr\iterate_crm_entities( 'account', [
        'orderby' => 'name',
        'order' => 'ASC',
    ] );

    $count = 0;

    foreach( $accounts as $account ) {
        cache_crm_entity( $account );
        if ( is_parent_company( $account ) ) {
            cli_log( "Found group {$account->Attributes['name']} ({$account->Id}).", 'success' );
            $count++;
        }
    }

    cli_log( "Found {$count} groups.", 'success' );
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
        try {
            cache_crm_entity( $account );
            import_account( $account, $force_update );
            $count++;
        } catch ( \Exception $err ) {
            cli_log( $err->getMessage(), 'warning' );
        }
    }

    cli_log( "Finished importing {$count} accounts.", 'success' );

    $contacts = \hacklabr\iterate_crm_entities( 'contact', [
        'orderby' => 'fullname',
        'order' => 'ASC',
    ] );

    $count = 0;

    foreach( $contacts as $contact ) {
        try {
            cache_crm_entity( $contact );
            import_contact( $contact, true, $force_update );
            $count++;
        } catch ( \Exception $err ) {
            cli_log( $err->getMessage(), 'warning' );
        }
    }

    cli_log( "Finished importing {$count} contacts.", 'success' );
}

function dont_notify_imported_users ( $send, $user ) {
    if ( $user instanceof \WP_User ) {
        $user_id = $user->ID;
    } else {
        $user_id = (int) ( $user ?? 0 );
    }
    $is_imported = get_user_meta( $user_id, '_ethos_from_crm', true );

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
        \WP_CLI::add_command( 'count-economic-groups', 'ethos\\count_economic_groups_command' );
        \WP_CLI::add_command( 'import-accounts', 'ethos\\import_accounts_command' );
    }
}
add_action( 'init', 'ethos\\register_import_accounts_command' );
