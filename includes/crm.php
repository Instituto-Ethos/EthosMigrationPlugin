<?php

namespace ethos\migration;

use \AlexaCRM\Xrm\Entity;
use \ethos\crm;

/**
 * If inside `wp` CLI, prints the message
 *
 * @param string $message The message to be printed
 * @param string $level One of 'debug', 'error', 'log', 'success' or 'warning'
 */
function cli_log( string $message, string $level = 'log' ) {
    if ( class_exists( '\WP_CLI' ) ) {
        if ( $level === 'error' ) {
            call_user_func( [ \WP_CLI::class, $level ], $message, false );
        } else {
            call_user_func( [ \WP_CLI::class, $level ], $message );
        }
    }
}

function csv_init() {
    global $ethos_crm_csv;

    if ( ! empty( $ethos_crm_csv ) ) {
        csv_finish();
    }

    $date = substr( date_format( date_create( 'now' ), 'c' ), 0, 16 );

    $ethos_crm_csv = fopen( wp_upload_dir()['basedir'] . '/imported-contacts-' . $date . '.csv', 'w' );

    fputcsv( $ethos_crm_csv, [
        'Contato - ID',
        'Usuário - ID',
        'Usuário - Nome',
        'Usuário - Login',
        'Usuário - E-mail',
        'Conta - ID',
        'Conta - Nome',
        'Usuário - Link de recuperação de senha',
    ] );
}

function csv_add_line( int $user_id, Entity $account ) {
    global $ethos_crm_csv;

    $user = get_user_by( 'id', $user_id );

    $account_id = get_user_meta( $user_id, '_ethos_crm_contact_id', true );

    $recovery_link = sprintf( get_home_url() . '/wp-login.php?action=rp&key=%s&login=%s&lang=pt_BR', get_password_reset_key( $user ), $user->user_login  );

    fputcsv( $ethos_crm_csv, [
        $account_id,
        $user->ID,
        $user->display_name,
        $user->user_login,
        $user->user_email,
        $account->Id,
        $account->Attributes['name'] ?? '',
        $recovery_link,
    ] );
}

function csv_finish() {
    global $ethos_crm_csv;

    fclose( $ethos_crm_csv );
}

function generate_unique_user_login( string $user_name ) {
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

function get_pmpro_level_id( int $post_id, string $level_name ) {
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

    return \hacklabr\get_pmpro_level_options( $post_id )[ $level_slug ] ?? null;
}

function set_hacklab_as_current_user() {
    $user = get_user_by( 'login', 'hacklab' );
    if ( ! empty( $user ) ) {
        wp_set_current_user( $user->ID, $user->user_login );
    }
}

function import_account( Entity $account, bool $force_update = false ) {
    $account_id = $account->Id;
    $attributes = $account->Attributes;
    $formatted = $account->FormattedValues;

    $post_meta = crm\parse_account_into_post_meta( $account );

    if ( crm\is_active_account( $account ) ) {
        cli_log( "Importing account {$post_meta['nome_fantasia']} — {$account->Id}", 'debug' );
    } else {
        cli_log( "Skipping account {$post_meta['nome_fantasia']} — {$account->Id}", 'debug' );
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

        if ( crm\is_subsidiary_company( $account ) ) {
            $post_parent = crm\get_post_id_by_account( $attributes['parentaccountid']->Id ) ?? 0;
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
            set_primary_contact( $post_id, $attributes['primarycontactid']->Id, $formatted['fut_pl_tipo_associacao'] );
        }

        if ( ! empty( $attributes['fut_lk_contato_alternativo'] ) ) {
            set_secondary_contact( $post_id, $attributes['fut_lk_contato_alternativo']->Id );
        }

        if ( ! empty( $attributes['fut_lk_contato_alternativo2'] ) ) {
            set_secondary_contact( $post_id, $attributes['fut_lk_contato_alternativo2']->Id );
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

function get_contact( string $contact_id, string $account_id ) {
    $existing_users = get_users( [
        'meta_query' => [
            [ 'key' => '_ethos_crm_account_id', 'value' => $account_id ],
            [ 'key' => '_ethos_crm_contact_id', 'value' => $contact_id ],
        ],
    ] );

    if ( empty( $existing_users ) ) {
        $account = \hacklabr\get_crm_entity_by_id( 'account', $account_id );
        $contact = \hacklabr\get_crm_entity_by_id( 'contact', $contact_id );

        if ( ! empty( $contact ) ) {
            return import_contact( $contact, $account, false );
        }
    } else {
        return $existing_users[0]->ID;
    }

    return null;
}

function import_contact( Entity $contact, Entity|null $account = null, bool $force_update = false ) {
    $contact_id = $contact->Id;

    $user_meta = crm\parse_contact_into_user_meta( $contact, $account );

    // Don't import users without organization
    if ( empty( $account ) ) {
        if ( crm\is_active_contact( $contact, null ) ) {
            $account = crm\get_account_by_contact( $contact );
        } else {
            cli_log( "Skipping contact {$user_meta['nome_completo']} — {$contact->Id}", 'debug' );
            return null;
        }
    }

    cli_log( "Importing contact {$user_meta['nome_completo']} — {$contact->Id}", 'debug' );

    $existing_users = get_users( [
        'meta_query' => [
            [ 'key' => '_ethos_crm_account_id', 'value' => $account->Id ],
            [ 'key' => '_ethos_crm_contact_id', 'value' => $contact_id ],
        ],
    ] );

    if ( empty( $existing_users ) ) {
        $password = wp_generate_password( 16 );

        $existing_user_by_email = get_user_by( 'email', $user_meta['email'] );

        if ( $existing_user_by_email ) {
            $user_id = wp_update_user([
                'ID' => $existing_user_by_email->ID,
                'meta_input' => $user_meta,
            ]);
        } else {
            $user_id = wp_insert_user( [
                'display_name' => $user_meta['nome_completo'],
                'user_email' => $user_meta['email'],
                'user_login' => generate_unique_user_login( $user_meta['nome_completo'] ),
                'user_pass' => $password,
                'role' => 'subscriber',
                'meta_input' => $user_meta,
            ] );

            if ( empty( $user_id ) ) {
                return null;
            } else if ( ! is_wp_error( $user_id ) ) {
                csv_add_line( $user_id, $account );
            }
        }

        if ( is_wp_error( $user_id ) ) {
            cli_log( $user_id->get_error_message(), 'error' );
            return null;
        }

        $post_id = crm\get_post_id_by_account( $account->Id );
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

function approve_contact( int $user_id, int $level_id ) {
    return \PMPro_Approvals::approveMember( $user_id, $level_id, true );
}

function add_contact_to_organization( int $user_id, int $post_id ) {
    $existing_group_id = get_user_meta( $user_id, '_pmpro_group', true );
    if ( ! empty( $existing_group_id ) ) {
        return (int) $existing_group_id;
    }

    $group_id = (int) ( get_post_meta( $post_id, '_pmpro_group', true ) ?? 0 );

    if ( ! empty( $group_id ) ) {
        $membership = \hacklabr\add_user_to_pmpro_group( $user_id, $group_id );

        update_user_meta( $user_id, '_pmpro_group', $group_id );

        approve_contact( $user_id, $membership->group_child_level_id );
    }

    return $group_id ?: null;
}

function set_primary_contact( int $post_id, string $contact_id, string $level_name ) {
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

function set_secondary_contact( int $post_id, string $contact_id ) {
    $account_id = get_post_meta( $post_id, '_ethos_crm_account_id', true );

    $user_id = get_contact( $contact_id, $account_id ) ?? 0;

    if ( empty( get_user_meta( $user_id, '_pmpro_group', true ) ) ) {
        add_contact_to_organization( $user_id, $post_id );
    }

    update_user_meta( $user_id, '_ethos_admin', '1' );
}

function set_approver( int $post_id, string $contact_id ) {
    $account_id = get_post_meta( $post_id, '_ethos_crm_account_id', true );

    $user_id = get_contact( $contact_id, $account_id ) ?? 0;

    if ( empty( get_user_meta( $user_id, '_pmpro_group', true ) ) ) {
        add_contact_to_organization( $user_id, $post_id );
    }

    update_user_meta( $user_id, '_ethos_approver', '1' );
}

function import_accounts_command( array $args, array $assoc_args ) {
    set_hacklab_as_current_user();
    csv_init();

    $parsed_args = wp_parse_args( $assoc_args, [
        'type' => 'all',
        'update' => false,
    ] );

    $import_type = $parsed_args['type'];
    $force_update = $parsed_args['update'];

    if ( $import_type === 'account' || $import_type === 'all' ) {
        $accounts = \hacklabr\iterate_crm_entities( 'account', [
            'orderby' => 'name',
            'order' => 'ASC',
        ] );

        $count = 0;

        foreach( $accounts as $account ) {
            try {
                \hacklabr\cache_crm_entity( $account );
                import_account( $account, $force_update );
                $count++;
            } catch ( \Throwable $err ) {
                cli_log( $err->getMessage(), 'error' );
            }
        }

        cli_log( "Finished importing {$count} accounts.", 'success' );
    }

    if ( $import_type === 'contact' || $import_type === 'all' ) {
        $contacts = \hacklabr\iterate_crm_entities( 'contact', [
            'orderby' => 'fullname',
            'order' => 'ASC',
        ] );

        $count = 0;

        foreach( $contacts as $contact ) {
            try {
                \hacklabr\cache_crm_entity( $contact );
                import_contact( $contact, null, $force_update );
                $count++;
            } catch ( \Throwable $err ) {
                cli_log( $err->getMessage(), 'error' );
            }
        }

        cli_log( "Finished importing {$count} contacts.", 'success' );
    }

    csv_finish();
}

function disable_pmpro_emails( $pre, $option ) {
    if ( class_exists( '\WP_CLI' ) ) {
        if ( str_starts_with( $option, 'pmpro_email_' ) && str_ends_with( $option, '_disabled' ) ) {
            return true;
        }
    }
    return $pre;
}
add_filter( 'pre_option', 'ethos\\migration\\disable_pmpro_emails', 10, 2 );

function disable_wp_emails( $send, $user ) {
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
add_filter( 'pmpro_approvals_after_approve_member_send_emails', 'ethos\\migration\\disable_wp_emails', 20, 2 );
add_filter( 'pmpro_wp_new_user_notification', 'ethos\\migration\\disable_wp_emails', 20, 2 );
add_filter( 'wp_send_new_user_notification_to_admin', 'ethos\\migration\\disable_wp_emails', 20, 2 );
add_filter( 'wp_send_new_user_notification_to_user', 'ethos\\migration\\disable_wp_emails', 20, 2 );

function change_password_expiry_time( $expiration ) {
    $diff = strtotime( '2024-10-01' ) - time();
    return max( $diff, $expiration );
}
add_filter( 'password_reset_expiration', 'ethos\\migration\\change_password_expiry_time' );

function register_import_accounts_command() {
    if ( class_exists( '\WP_CLI' ) ) {
        \WP_CLI::add_command( 'import-accounts', 'ethos\\migration\\import_accounts_command' );
    }
}
add_action( 'init', 'ethos\\migration\\register_import_accounts_command' );
