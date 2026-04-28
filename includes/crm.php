<?php

namespace ethos\migration;

use \AlexaCRM\Xrm\Entity;
use \ethos\crm;

function inside_wp_cli () {
    return class_exists( '\WP_CLI' );
}

/**
 * If inside `wp` CLI, prints the message
 *
 * @param string $message The message to be printed
 * @param string $level One of 'debug', 'error', 'log', 'success' or 'warning'
 */
function cli_log( string $message, string $level = 'log' ) {
    if ( $level === 'error' ) {
        call_user_func( [ \WP_CLI::class, $level ], $message, false );
    } else {
        call_user_func( [ \WP_CLI::class, $level ], $message );
    }
}

function csv_init( string|null $filename = null ) {
    global $ethos_crm_csv;

    if ( ! empty( $ethos_crm_csv ) ) {
        csv_finish();
    }

    if (empty($filename)) {
        $date = substr( date_format( date_create( 'now' ), 'c' ), 0, 16 );
        $filename = '/imported-contacts-' . $date . '.csv';
    }

    $ethos_crm_csv = fopen( wp_upload_dir()['basedir'] . $filename, 'w' );

    fputcsv( $ethos_crm_csv, [
        'Contato - ID',
        'Usuário - ID',
        'Usuário - Nome',
        'Usuário - Login',
        'Usuário - E-mail',
        'Conta - ID',
        'Conta - Nome',
        'Usuário - Link de recuperação de senha',
        'Usário - É admin',
    ] );
}

function csv_add_line( int $user_id, Entity $account ) {
    global $ethos_crm_csv;

    $user = get_user_by( 'id', $user_id );

    $account_id = get_user_meta( $user_id, '_ethos_crm_contact_id', true );

    $recovery_link = sprintf( get_home_url() . '/wp-login.php?action=rp&key=%s&login=%s&lang=pt_BR', get_password_reset_key( $user ), $user->user_login  );

    $ethos_is_admin = ! empty( get_user_meta( $user_id, '_ethos_admin', true ) );

    fputcsv( $ethos_crm_csv, [
        $account_id,
        $user->ID,
        $user->display_name,
        $user->user_login,
        $user->user_email,
        $account->Id,
        $account->Attributes['name'] ?? '',
        $recovery_link,
        $ethos_is_admin ? 'Sim' : 'Não',
    ] );
}

function csv_finish() {
    global $ethos_crm_csv;

    fclose( $ethos_crm_csv );
}

function set_hacklab_as_current_user() {
    $user = get_user_by( 'login', 'hacklab' );
    if ( ! empty( $user ) ) {
        wp_set_current_user( $user->ID, $user->user_login );
    }
}

function import_accounts_command( array $args, array $assoc_args ) {
    global $ethos_crm_command;
    $ethos_crm_command = 'import-accounts';

    set_hacklab_as_current_user();
    csv_init();

    $sync_start = date_format( date_create( 'now' ), 'Y-m-d\TH:i:sp' );

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
            if ( ! crm\is_active_account( $account ) ) {
                continue;
            }

            try {
                \hacklabr\cache_crm_entity( $account );
                crm\import_account( $account, $force_update );
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
            if ( ! crm\is_active_contact( $contact ) ) {
                continue;
            }

            try {
                \hacklabr\cache_crm_entity( $contact );
                crm\import_contact( $contact, null, $force_update );
                $count++;
            } catch ( \Throwable $err ) {
                cli_log( $err->getMessage(), 'error' );
            }
        }

        cli_log( "Finished importing {$count} contacts.", 'success' );
    }

    $last_sync = \ethos\crm\get_last_crm_sync();

    if ( $import_type === 'all' && ( empty( $last_sync ) || $sync_start > $last_sync ) ) {
        \ethos\crm\update_last_crm_sync( $sync_start );
    }

    csv_finish();
}

function first_access_command( array $args ) {
    global $ethos_crm_command;
    $ethos_crm_command = 'first-access';

    $cnpj = $args[0] ?? '';
    $cnpj = preg_replace( '/\D/', '', $cnpj );
    if ( strlen( $cnpj ) !== 14 ) {
        cli_log( 'Invalid CNPJ number', 'error' );
        return;
    }

    set_hacklab_as_current_user();
    csv_init( '/first-access-' . $cnpj . '.csv' );

    $accounts = \hacklabr\iterate_crm_entities( 'account', [
        'filters' => [
            'fut_st_cnpjsemmascara' => $cnpj,
        ],
    ] );

    $count = 0;

    foreach ( $accounts as $account ) {
        try {
            \hacklabr\cache_crm_entity( $account );
            $post_id = crm\import_account( $account, true );

            if ( empty( $post_id ) || empty( get_post_meta( $post_id, '_pmpro_group', true ) ) ) {
                cli_log( "\tCould not find primary contact." );
            }
        } catch ( \Throwable $err ) {
            cli_log( $err->getMessage(), 'error' );
        }

        $contacts = \hacklabr\iterate_crm_entities( 'contact', [
            'filters' => [
                'accountid' => $account->Id,
            ],
        ] );

        foreach ( $contacts as $contact ) {
            if ( ! crm\is_active_contact( $contact, $account ) ) {
                continue;
            }

            try {
                \hacklabr\cache_crm_entity( $contact );
                crm\import_contact( $contact, $account, true );

                $user_id = crm\get_contact( $contact->Id, $account->Id );
                if ( $user_id ) {
                    csv_add_line( $user_id, $account );
                    $count++;

                    if ( ( $count % 10 ) == 0 ) {
                        cli_log( "Imported {$count} contacts..." );
                    }
                }
            } catch ( \Throwable $err ) {
                cli_log( $err->getMessage(), 'error' );
            }
        }
    }

    cli_log( "Finished importing {$count} contacts.", 'success' );

    csv_finish();
}

function first_access_v2_command() {
    global $ethos_crm_command;
    $ethos_crm_command = 'first-access';

    set_hacklab_as_current_user();
    csv_init();

    $skipped_cnpjs = [
        // ALLIA HIGIENE
        '25983227000125',
        // ALSTOM BRASIL ENERGIA E TRANSPORTE LTDA
        '88309620000158',
        '88309620000662',
        // ASSAÍ ATACADISTA
        '06057223000171',
        // BANCO DO BRASIL S.A.
        '00000000000191',
        // DANIEL ADVOGADOS
        '33073800000434',
        // FEDERAÇÃO DAS INDÚSTRIAS DO ESTADO DO RIO DE JANEIRO - FIRJAN
        '42422212000107',
        // RIO ÔNIBUS - SINDICATO DAS EMPRESAS DE ÔNIBUS DA CIDADE DO RIO DE JANEIRO
        '33927872000159',
        // TECHINT ENGENHARIA E CONSTRUÇÃO S/A
        '61575775000180',
        // INTEX BANK BANCO DE CAMBIO S.A.
        '02992317000187',
        // ISA ENERGIA BRASIL S.A
        '02998611000104',
        // OPERADOR NACIONAL DO SISTEMA ELÉTRICO
        '02831210000238',
        // BOCA ROSA COMPANY LTDA
        '22694602000129',
        // LIGA INDEPENDENTE DO GRUPO A - RIO DE JANEIRO
        '28326598000122',
        // EQUATORIAL ENERGIA S/A
        '03220438000173',
    ];

    $accounts = \hacklabr\iterate_crm_entities( 'account', [
        'orderby' => 'name',
        'order' => 'ASC',
    ] );

    $total_count = 0;
    $total_errors = 0;

    foreach ( $accounts as $account ) {
        if ( ! crm\is_active_account( $account ) ) {
            continue;
        }

        $attributes = $account->Attributes;
        $account_name = $attributes['name'] ?? '';
        $cnpj = $attributes['fut_st_cnpjsemmascara'] ?? '';

        if ( empty( $cnpj ) || in_array( $cnpj, $skipped_cnpjs ) ) {
            cli_log( "Skipped {$account_name} ({$account->Id})...");
            continue;
        }

        $post_id = null;

        try {
            cli_log( "Updating {$account_name} ({$account->Id})...");
            \hacklabr\cache_crm_entity( $account );
            $post_id = crm\import_account( $account, true );
        } catch ( \Throwable $err ) {
            cli_log( $err->getMessage(), 'error' );
        }

        if ( empty( $post_id ) || empty( get_post_meta( $post_id, '_pmpro_group', true ) ) ) {
            cli_log( "\tCould not find primary contact." );
        }

        $contacts = \hacklabr\iterate_crm_entities( 'contact', [
            'filters' => [
                'accountid' => $account->Id,
            ],
        ] );

        $current_count = 0;
        $current_errors = 0;

        foreach ( $contacts as $contact ) {
            if ( ! crm\is_active_contact( $contact, $account ) ) {
                continue;
            }

            try {
                \hacklabr\cache_crm_entity( $contact );
                crm\import_contact( $contact, $account, true );

                $user_id = crm\get_contact( $contact->Id, $account->Id );
                if ( $user_id ) {
                    csv_add_line( $user_id, $account );
                    $current_count++;
                    $total_count++;
                }
            } catch ( \Throwable $err ) {
                $contact_name = $contact->Attributes['fullname'] ?? '';
                cli_log( "\tErro ao importar {$contact_name} ({$contact->Id}): {$err->getMessage()}", 'error' );
                $current_errors++;
                $total_errors++;
            }
        }

        cli_log( "\tImported more {$current_count} contacts (of {$total_count} total)." );
        if ( $current_errors > 0 ) {
            cli_log( "\tFound more {$current_errors} errors (of {$total_errors} total)." );
        }
    }

    cli_log( "Finished importing {$total_count} contacts, with {$total_errors} errors.", 'success' );

    csv_finish();
}

function first_access_v3_command() {
    // Required for using `wp_delete_user` function
    require_once( ABSPATH . 'wp-admin/includes/user.php' );

    global $ethos_crm_command;
    $ethos_crm_command = 'first-access';

    set_hacklab_as_current_user();
    csv_init();

    $skipped_cnpjs = [
        // ALLIA HIGIENE
        '25983227000125',
        // ALSTOM BRASIL ENERGIA E TRANSPORTE LTDA
        '88309620000158',
        '88309620000662',
        // ASSAÍ ATACADISTA
        '06057223000171',
        // BANCO DO BRASIL S.A.
        '00000000000191',
        // DANIEL ADVOGADOS
        '33073800000434',
        // FEDERAÇÃO DAS INDÚSTRIAS DO ESTADO DO RIO DE JANEIRO - FIRJAN
        '42422212000107',
        // RIO ÔNIBUS - SINDICATO DAS EMPRESAS DE ÔNIBUS DA CIDADE DO RIO DE JANEIRO
        '33927872000159',
        // TECHINT ENGENHARIA E CONSTRUÇÃO S/A
        '61575775000180',
        // INTEX BANK BANCO DE CAMBIO S.A.
        '02992317000187',
        // ISA ENERGIA BRASIL S.A
        '02998611000104',
        // OPERADOR NACIONAL DO SISTEMA ELÉTRICO
        '02831210000238',
        // BOCA ROSA COMPANY LTDA
        '22694602000129',
        // LIGA INDEPENDENTE DO GRUPO A - RIO DE JANEIRO
        '28326598000122',
        // EQUATORIAL ENERGIA S/A
        '03220438000173',
    ];

    $accounts = \hacklabr\iterate_crm_entities( 'account', [
        'orderby' => 'name',
        'order' => 'ASC',
    ] );

    $total_count = 0;
    $total_errors = 0;

    foreach ( $accounts as $account ) {
        $attributes = $account->Attributes;
        $account_name = $attributes['name'] ?? '';
        $cnpj = $attributes['fut_st_cnpjsemmascara'] ?? '';

        if ( ! crm\is_active_account( $account ) ) {
            $account_status = $account->FormattedValues['fut_pl_associacao'] ?? '';

            if ( in_array( $account_status, ['Associado', 'Grupo Econômico'] ) ) {
                $post_ids = get_posts( [
                    'post_type' => 'organizacao',
                    'meta_query' => [
                        [ 'key' => '_ethos_crm_account_id', 'value' => $account->Id ],
                    ],
                    'fields' => 'ids',
                ] );

                foreach ( $post_ids as $post_id ) {
                    wp_delete_post( $post_id, true );
                    cli_log( "Deleted account {$account_name} ({$account->Id})...");
                }

                $users = get_users( [
                    'meta_query' => [
                        [ 'key' => '_ethos_crm_account_id', 'value' => $account->Id ],
                    ],
                ] );

                foreach ( $users as $user ) {
                    wp_delete_user( $user->ID, null );
                }
            }

            continue;
        }

        if ( in_array( $cnpj, $skipped_cnpjs ) ) {
            cli_log( "Skipping {$account_name} ({$account->Id})...");

            $contacts = \hacklabr\iterate_crm_entities( 'contact', [
                'filters' => [
                    'accountid' => $account->Id,
                ],
            ] );

            foreach ( $contacts as $contact ) {
                if ( ! crm\is_active_contact( $contact, $account ) ) {
                    $users = get_users( [
                        'meta_query' => [
                            [ 'key' => '_ethos_crm_contact_id', 'value' => $contact->Id ],
                        ],
                    ] );

                    foreach ( $users as $user ) {
                        wp_delete_user( $user->ID, null );
                    }
                }
            }

            continue;
        }

        if ( empty( $cnpj ) ) {
            cli_log( "Skipped {$account_name} ({$account->Id})...");
            continue;
        }

        $post_id = null;

        try {
            cli_log( "Updating {$account_name} ({$account->Id})...");
            \hacklabr\forget_cached_crm_entity( 'account', $account->Id );
            \hacklabr\cache_crm_entity( $account );
            $post_id = crm\import_account( $account, true );
        } catch ( \Throwable $err ) {
            cli_log( $err->getMessage(), 'error' );
        }

        if ( empty( $post_id ) || empty( get_post_meta( $post_id, '_pmpro_group', true ) ) ) {
            cli_log( "\tCould not find primary contact." );
        }

        $contacts = \hacklabr\iterate_crm_entities( 'contact', [
            'filters' => [
                'accountid' => $account->Id,
            ],
        ] );

        $current_count = 0;
        $current_errors = 0;

        foreach ( $contacts as $contact ) {
            if ( ! crm\is_active_contact( $contact, $account ) ) {
                $users = get_users( [
                    'meta_query' => [
                        [ 'key' => '_ethos_crm_contact_id', 'value' => $contact->Id ],
                    ],
                ] );

                foreach ( $users as $user ) {
                    wp_delete_user( $user->ID, null );
                }

                continue;
            }

            try {
                \hacklabr\forget_cached_crm_entity( 'contact', $contact->Id );
                \hacklabr\cache_crm_entity( $contact );
                crm\import_contact( $contact, $account, true );

                $user_id = crm\get_contact( $contact->Id, $account->Id );
                if ( $user_id ) {
                    csv_add_line( $user_id, $account );
                    $current_count++;
                    $total_count++;
                }
            } catch ( \Throwable $err ) {
                $contact_name = $contact->Attributes['fullname'] ?? '';
                cli_log( "\tErro ao importar {$contact_name} ({$contact->Id}): {$err->getMessage()}", 'error' );
                $current_errors++;
                $total_errors++;
            }
        }

        cli_log( "\tImported more {$current_count} contacts (of {$total_count} total)." );
        if ( $current_errors > 0 ) {
            cli_log( "\tFound more {$current_errors} errors (of {$total_errors} total)." );
        }
    }

    cli_log( "Finished importing {$total_count} contacts, with {$total_errors} errors.", 'success' );

    csv_finish();
}

function disable_pmpro_emails( $pre, $option ) {
    if ( inside_wp_cli() ) {
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

function disable_user_update_email( bool $send ): bool {
    if (inside_wp_cli()) {
        return false;
    }

    return $send;
}
add_filter( 'send_email_change_email', 'ethos\\migration\\disable_user_update_email', 20, 1 );
add_filter( 'send_password_change_email', 'ethos\\migration\\disable_user_update_email', 20, 1 );

function change_password_expiry_time( int $expiration ): int {
    return 60 * \DAY_IN_SECONDS;
}
add_filter( 'password_reset_expiration', 'ethos\\migration\\change_password_expiry_time' );

function register_first_access_command() {
    if ( inside_wp_cli() ) {
        \WP_CLI::add_command( 'first-access', 'ethos\\migration\\first_access_command' );
        \WP_CLI::add_command( 'first-access-v2', 'ethos\\migration\\first_access_v2_command' );
        \WP_CLI::add_command( 'first-access-v3', 'ethos\\migration\\first_access_v3_command' );
    }
}
add_action( 'init', 'ethos\\migration\\register_first_access_command' );

function register_import_accounts_command() {
    if ( inside_wp_cli() ) {
        \WP_CLI::add_command( 'import-accounts', 'ethos\\migration\\import_accounts_command' );
    }
}
add_action( 'init', 'ethos\\migration\\register_import_accounts_command' );

function log_message( string $message, string $level = 'debug' ) {
    if ( inside_wp_cli() ) {
        cli_log( $message, $level );
    } else {
        error_log( '[' . $level . '] ' . $message, 0 );
    }

    switch ( $level ) {
        case 'warning':
            $logger_status = 'warning';
            break;
        case 'error':
            $logger_status = 'error';
            break;
        default:
            $logger_status = 'info';
            break;
    }

    do_action( 'logger', $message, $logger_status );
}
add_action( 'ethos_crm:log', 'ethos\\migration\\log_message', 10, 2 );

function csv_add_contact( int $user_id, Entity $account ) {
    if ( inside_wp_cli() ) {
        global $ethos_crm_command;
        if ( ! empty( $ethos_crm_command ) && $ethos_crm_command === 'import-accounts' ) {
            csv_add_line( $user_id, $account );
        }
    }
}
add_action( 'ethos_crm:create_user', 'ethos\\migration\\csv_add_contact', 10, 2 );
