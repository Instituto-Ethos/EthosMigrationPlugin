<?php

namespace ethos\migration;

use \AlexaCRM\Xrm\Entity;
use \ethos\crm;

/**
 * Accounts fetched (and imported) per migration chunk.
 */
const ACCOUNTS_PER_PAGE = 100;

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

/**
 * CRM filters that define which accounts take part in a migration cycle.
 *
 * Prefilters on the server side what is_active_account() checks in PHP:
 * active accounts with an association status of Associado or Grupo Econômico.
 * Keep in sync with AccountAssociation::activeValues() (theme) and
 * is_active_account() (importer).
 */
function cycle_account_filters(): array {
    return [
        'statecode' => 0,
        'fut_pl_associacao' => \ethos\crm\AccountAssociation::activeValues(),
    ];
}

/**
 * Fingerprint of the accounts page query for a given page size.
 *
 * Persisted in the cycle state: when a deploy changes the query shape
 * (filters, order, page size), the running cycle restarts from page 1
 * instead of continuing over inconsistent offsets.
 */
function cycle_query_signature( int $per_page ): string {
    return md5( serialize( [
        'entity'   => 'account',
        'filters'  => cycle_account_filters(),
        'orderby'  => [ 'name', 'accountid' ],
        'order'    => 'ASC',
        'per_page' => $per_page,
    ] ) );
}

/**
 * Processes a single page of accounts — and, for each imported account, all
 * of its active contacts.
 *
 * @param array $state Current cycle state.
 *
 * @return array|null The next cycle state (page advanced, counters merged,
 *                    transient 'finished' key telling whether the cycle is
 *                    complete), or null when the CRM page could not be fetched.
 */
function process_accounts_page( array $state ): array|null {
    $page         = (int) $state['page'];
    $per_page     = (int) $state['per_page'];
    $force_update = ! empty( $state['force'] );

    $result = \hacklabr\get_crm_entities_page( 'account', [
        'page'     => $page,
        'per_page' => $per_page,
        'orderby'  => 'name',
        'order'    => 'ASC',
        'filters'  => cycle_account_filters(),
    ] );

    if ( $result === null ) {
        return null;
    }

    log_message( "Running page $page." );

    $accounts   = $result->Entities ?? [];

    foreach ( $accounts as $account ) {
        if ( ! crm\is_active_account( $account ) ) {
            continue;
        }

        $state['processed_accounts']++;
        if ( 0 === $state['processed_accounts'] % 50 ) {
            crm\free_runtime_memory();
        }

        $attributes   = $account->Attributes;
        $account_id   = $account->Id;
        $account_name = $attributes['name'] ?? '';
        $cnpj         = $attributes['fut_st_cnpjsemmascara'] ?? '';

        $state['active_account_ids'][] = $account_id;

        log_message( "Processing account {$account_name} ({$account_id})..." );

        if ( empty( $cnpj ) ) {
            log_message( "Skipped account {$account_name} ({$account_id}), because of blank CNPJ." );
            continue;
        }

        $post_id = null;

        try {
            \hacklabr\cache_crm_entity( $account );
            $post_id = crm\import_account( $account, $force_update );
        } catch ( \Throwable $err ) {
            log_message( $err->getMessage(), 'error' );
        }

        if ( empty( $post_id ) ) {
            log_message( "\tAccount import failed for {$account_name} ({$account_id}).", 'error' );
            continue;
        }

        if ( empty( get_post_meta( $post_id, '_pmpro_group', true ) ) ) {
            log_message( "\tCould not find primary contact." );
        }

        $contacts = \hacklabr\iterate_crm_entities( 'contact', [
            'filters' => [
                'accountid' => $account_id,
                'statecode' => 0 /* Active */,
            ],
        ] );

        $current_count  = 0;
        $current_errors = 0;

        foreach ( $contacts as $contact ) {
            if ( ! crm\is_active_contact( $contact, $account ) ) {
                continue;
            }

            try {
                \hacklabr\forget_cached_crm_entity( 'contact', $contact->Id );
                \hacklabr\cache_crm_entity( $contact );
                $user_id = crm\import_contact( $contact, $account, $force_update );

                if ( $user_id ) {
                    $current_count++;
                    $state['total_count']++;
                }
            } catch ( \Throwable $err ) {
                $contact_name = $contact->Attributes['fullname'] ?? '';
                log_message( "\tErro ao importar {$contact_name} ({$contact->Id}): {$err->getMessage()}", 'error' );
                $current_errors++;
                $state['total_errors']++;
            }
        }

        log_message( "\tImported {$current_count} contacts ({$state['total_count']} total so far)." );
        if ( $current_errors > 0 ) {
            log_message( "\tFound {$current_errors} errors ({$state['total_errors']} total so far)." );
        }
    }

    $state['page'] = $page + 1;
    $state['finished'] = ! $result->MoreRecords;

    return $state;
}

/**
 * Ends a completed cycle: runs the inactive-accounts cleanup (with the
 * usual safety guards, computed over the whole cycle) and records the last
 * run statistics.
 */
function finalize_cycle( array $state ): void {
    $current_active = count( $state['active_account_ids'] );
    $last_active    = (int) get_option( '_ethos_migration_last_active_accounts', 0 );
    $cleanup        = [ 'status' => 'skipped' ];

    if ( $current_active === 0 ) {
        $cleanup['reason'] = 'no-active-accounts';
        log_message( 'Cleanup skipped: no active accounts found this cycle (CRM connection or data problem?).', 'error' );
    } elseif ( $last_active > 0 && $current_active < $last_active * 0.5 ) {
        $cleanup['reason'] = 'active-accounts-dropped';
        log_message( "Cleanup skipped: active accounts dropped from {$last_active} to {$current_active} (possible truncated CRM iteration).", 'error' );
    } else {
        $cleanup_result = \ethos\remove_inactive_accounts( $state['active_account_ids'] );
        $cleanup = [
            'status'  => 'done',
            'removed' => $cleanup_result['removed'],
            'errors'  => $cleanup_result['errors'],
        ];
        update_option( '_ethos_migration_last_active_accounts', $current_active );
    }

    log_message( "Finished importing {$state['total_count']} contacts, with {$state['total_errors']} errors.", 'success' );

    update_option( '_ethos_migration_last_run', [
        'started'         => $state['started'],
        'finished'        => current_datetime()->format( 'Y-m-d H:i:s P' ),
        'duration_s'      => round( microtime( true ) - (float) $state['started_ts'], 1 ),
        'active_accounts' => $current_active,
        'contacts'        => (int) $state['total_count'],
        'errors'          => (int) $state['total_errors'],
        'cleanup'         => $cleanup,
        'status'          => 'completed',
        'pages'           => max( 0, (int) $state['page'] - 1 ),
        'per_page'        => (int) $state['per_page'],
        'force'           => ! empty( $state['force'] ),
    ] );

    clear_cycle_state();
}

/**
 * Discards an in-progress cycle without ever running the cleanup.
 */
function abort_cycle( array $state, string $reason ): void {
    log_message( "Aborting migration cycle: {$reason}", 'error' );

    update_option( '_ethos_migration_last_run', [
        'started'         => $state['started'],
        'finished'        => current_datetime()->format( 'Y-m-d H:i:s P' ),
        'duration_s'      => round( microtime( true ) - (float) $state['started_ts'], 1 ),
        'active_accounts' => count( $state['active_account_ids'] ),
        'contacts'        => (int) $state['total_count'],
        'errors'          => (int) $state['total_errors'],
        'cleanup'         => [ 'status' => 'skipped', 'reason' => 'cycle-aborted' ],
        'status'          => 'aborted',
        'abort_reason'    => $reason,
        'pages'           => max( 0, (int) $state['page'] - 1 ),
        'per_page'        => (int) $state['per_page'],
        'force'           => ! empty( $state['force'] ),
    ] );

    clear_cycle_state();
}

/**
 * Runs a single migration chunk: fetches and imports one page of accounts,
 * then persists the advanced cursor. Safe to call repeatedly.
 *
 * @return string One of 'idle' (no cycle in progress), 'running', 'finished'
 *                or 'aborted'.
 */
function run_migration_chunk(): string {
    $state = get_cycle_state();

    if ( empty( $state ) ) {
        return 'idle';
    }

    $per_page = (int) ( $state['per_page'] ?? ACCOUNTS_PER_PAGE );

    if ( ( $state['query_signature'] ?? '' ) !== cycle_query_signature( $per_page ) ) {
        log_message( 'Cycle query signature changed (deploy?); restarting the cycle from page 1.', 'warning' );
        $state = start_cycle( ! empty( $state['force'] ), $per_page );
    }

    $new_state = process_accounts_page( $state );

    if ( $new_state === null ) {
        $failures = (int) ( $state['consecutive_failures'] ?? 0 ) + 1;

        if ( $failures >= 3 ) {
            abort_cycle( $state, "CRM page fetch failed {$failures} consecutive times" );
            return 'aborted';
        }

        update_cycle_state( [ 'consecutive_failures' => $failures ] );
        log_message( "[cycle page {$state['page']}] Fetch failed; will retry (attempt {$failures}/3).", 'error' );

        return 'running';
    }

    $finished = ! empty( $new_state['finished'] );
    unset( $new_state['finished'] );

    $new_state['consecutive_failures'] = 0;
    update_cycle_state( $new_state );

    if ( $finished ) {
        finalize_cycle( $new_state );
        return 'finished';
    }

    return 'running';
}

/**
 * Usage: wp incremental-migration [--force] [--per-page=N]
 *
 * Runs a whole migration cycle in a single process, chunk by chunk (one CRM
 * page of accounts per chunk). When driven by cron, each chunk runs in its
 * own request through the `ethos_migration\run_chunk` tick (see
 * incremental-cron.php).
 *
 * --force ignores the _ethos_crm:modifiedon markers and re-syncs everything,
 * discarding any cycle in progress.
 * --per-page sets the accounts page size for a NEW cycle (default 100).
 *
 * Waits up to 15 minutes for the chunk lock (a running cron tick or CLI run)
 * before aborting.
 */
function incremental_migration_command( array $args = [], array $assoc_args = [] ) {
    global $ethos_crm_command, $ethos_migration_log_file;
    $ethos_crm_command = 'incremental-migration';

    $parsed_args = wp_parse_args( $assoc_args, [
        'force'    => false,
        'per-page' => ACCOUNTS_PER_PAGE,
    ] );

    $force_update = (bool) $parsed_args['force'];
    $per_page     = max( 1, (int) $parsed_args['per-page'] );

    if ( empty( $ethos_migration_log_file ) ) {
        $ethos_migration_log_file = open_migration_log();
    }

    if ( ! acquire_lock_with_wait() ) {
        log_message( 'Could not acquire the migration lock after waiting 15 minutes (a cron tick or another CLI run holds it). Aborting.', 'error' );
        return;
    }

    try {
        set_hacklab_as_current_user();

        $state = get_cycle_state();

        if ( empty( $state ) ) {
            $state = start_cycle( $force_update, $per_page );
            log_message( "Started a new migration cycle" . ( $force_update ? ' with --force' : '' ) . " ({$per_page} accounts per page)." );
        } elseif ( $force_update ) {
            log_message( "Discarding the in-progress cycle (was at page {$state['page']}) to start a forced one.", 'warning' );
            $state = start_cycle( true, $per_page );
        } else {
            log_message( "Resuming the existing cycle from page {$state['page']}." );
        }

        while ( true ) {
            refresh_lock();

            $result = run_migration_chunk();

            if ( $result === 'finished' ) {
                cli_log( 'Migration cycle finished.', 'success' );
                break;
            }

            if ( $result === 'aborted' ) {
                cli_log( 'Migration cycle aborted; see the migration log for details.', 'error' );
                break;
            }

            if ( $result === 'idle' ) {
                break;
            }
        }
    } finally {
        release_lock();
    }
}

function disable_pmpro_emails( $pre, $option ) {
    if ( inside_wp_cli() || wp_doing_cron() ) {
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
    if ( inside_wp_cli() || wp_doing_cron() ) {
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
        \WP_CLI::add_command( 'incremental-migration', 'ethos\\migration\\incremental_migration_command' );
    }
}
add_action( 'init', 'ethos\\migration\\register_import_accounts_command' );

function log_message( string $message, string $level = 'debug' ) {
    if ( inside_wp_cli() ) {
        cli_log( $message, $level );
    } else {
        error_log( '[' . $level . '] ' . $message, 0 );
    }

    write_migration_log_line( $message, $level );

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
