<?php

namespace ethos\migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const LOCK_KEY = 'ethos_migration_lock';
const LOG_DIR = WP_CONTENT_DIR . '/.ethos-logs';
const LOG_RETENTION_DAYS = 30;
const ACTIVE_ACCOUNTS_BASELINE = '_ethos_migration_last_active_accounts';
const LAST_RUN_OPTION = '_ethos_migration_last_run';

function next_daily_run_timestamp(): int {
    $now = current_datetime();
    $next = $now->modify( 'today 02:00:00' );

    if ( $next <= $now ) {
        $next = $next->modify( '+1 day' );
    }

    return $next->getTimestamp();
}

function get_log_file_path(): string {
    return LOG_DIR . '/migration-' . current_datetime()->format( 'Y-m-d' ) . '.log';
}

function open_migration_log(): string {
    if ( ! is_dir( LOG_DIR ) ) {
        wp_mkdir_p( LOG_DIR );

        @file_put_contents( LOG_DIR . '/.htaccess', "Require all denied\n" );
        @file_put_contents( LOG_DIR . '/index.php', "<?php\n// Silence is golden.\n" );
    }

    return get_log_file_path();
}

function prune_old_logs(): void {
    $files = glob( LOG_DIR . '/migration-*.log' );

    if ( empty( $files ) ) {
        return;
    }

    $limit = time() - ( LOG_RETENTION_DAYS * DAY_IN_SECONDS );

    foreach ( $files as $file ) {
        if ( is_file( $file ) && filemtime( $file ) < $limit ) {
            @unlink( $file );
        }
    }
}

function write_migration_log_line( string $message, string $level ): void {
    global $ethos_migration_log_file;

    if ( empty( $ethos_migration_log_file ) ) {
        return;
    }

    $timestamp = current_datetime()->format( 'Y-m-d H:i:s P' );
    @error_log( "[{$timestamp}] [{$level}] {$message}\n", 3, $ethos_migration_log_file );
}

function acquire_lock(): bool {
    if ( ! empty( get_transient( LOCK_KEY ) ) ) {
        return false;
    }

    set_transient( LOCK_KEY, 1, 23 * HOUR_IN_SECONDS );
    return true;
}

function release_lock(): void {
    delete_transient( LOCK_KEY );
}

function run_daily_migration(): void {
    if ( ! acquire_lock() ) {
        log_message( 'Daily migration skipped: lock held (previous run still active or wedged).', 'warning' );
        return;
    }

    global $ethos_migration_log_file;
    $ethos_migration_log_file = open_migration_log();

    try {
        prune_old_logs();
        set_hacklab_as_current_user();
        incremental_migration_command();
    } finally {
        release_lock();

        $ethos_migration_log_file = null;
    }
}
add_action( 'ethos_migration\run_daily', 'ethos\\migration\\run_daily_migration' );
