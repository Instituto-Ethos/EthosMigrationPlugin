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

function set_hacklab_as_current_user() {
    $user = get_user_by( 'login', 'hacklab' );
    if ( ! empty( $user ) ) {
        wp_set_current_user( $user->ID, $user->user_login );
    }
}

function import_accounts_command( array $args, array $assoc_args ) {
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

function change_password_expiry_time( $expiration ) {
    $diff = strtotime( '2024-10-01' ) - time();
    return max( $diff, $expiration );
}
add_filter( 'password_reset_expiration', 'ethos\\migration\\change_password_expiry_time' );

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
        csv_add_line( $user_id, $account );
    }
}
add_action( 'ethos_crm:create_user', 'ethos\\migration\\csv_add_contact', 10, 2 );
