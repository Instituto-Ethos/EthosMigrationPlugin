<?php

namespace ethos\migration;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const LOG_DIR = WP_CONTENT_DIR . '/.ethos-logs';

/*
 * Chunked-cycle machinery.
 *
 * A migration cycle iterates the CRM accounts page by page (one page per
 * chunk — see crm.php). The recurring `ethos_migration\run_chunk` tick
 * advances the cycle one page at a time; the daily 02:00 event only starts
 * a new cycle (and processes its first page). Because the tick is a plain
 * recurring event, a crashed chunk needs no recovery logic: the next tick
 * resumes from the persisted cursor.
 *
 * Both events are SCHEDULED by the theme (`library/cron.php`, following the
 * project convention); this plugin only EXECUTES them.
 */
const TICK_INTERVAL = 5 * MINUTE_IN_SECONDS;
const MAX_CYCLE_AGE = 20 * HOUR_IN_SECONDS;

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

    $limit = time() - ( 30 * DAY_IN_SECONDS );

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

/**
 * Acquires the chunk lock, valid for one chunk. The short TTL (instead of
 * the old 23-hour lock) lets a crashed run self-heal: the next tick simply
 * takes over from the persisted cursor.
 */
function acquire_lock(): bool {
    if ( ! empty( get_transient( 'ethos_migration_chunk_lock' ) ) ) {
        return false;
    }

    set_transient( 'ethos_migration_chunk_lock', 1, 3 * TICK_INTERVAL );
    return true;
}

/**
 * Extends the chunk lock TTL, keeping it alive across the chunks of a
 * long-running CLI cycle.
 *
 * Re-asserts the lock unconditionally: a conditional refresh would let the
 * lock lapse if a single chunk ever outlives the TTL, allowing a cron tick
 * to process a chunk concurrently with this run.
 */
function refresh_lock(): void {
    set_transient( 'ethos_migration_chunk_lock', 1, 3 * TICK_INTERVAL );
}

/**
 * Acquires the chunk lock, waiting for an in-flight chunk (or CLI run) to
 * finish. CLI-only: cron ticks must never block and keep using
 * acquire_lock() instead.
 *
 * @param int $retry_every Seconds between attempts.
 * @param int $max_wait    Maximum seconds to wait before giving up.
 *
 * @return bool True when the lock was acquired, false on timeout.
 */
function acquire_lock_with_wait( int $retry_every = 30, int $max_wait = 3 * TICK_INTERVAL ): bool {
    $attempts = max( 1, intdiv( $max_wait, $retry_every ) );

    for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
        if ( acquire_lock() ) {
            return true;
        }

        if ( $attempt === $attempts ) {
            break;
        }

        log_message( "Migration lock held by a cron tick or another CLI run; waiting (attempt {$attempt}/{$attempts})...", 'warning' );
        sleep( $retry_every );
    }

    return false;
}

function release_lock(): void {
    delete_transient( 'ethos_migration_chunk_lock' );
}

function get_cycle_state(): array|null {
    $state = get_option( '_ethos_migration_cycle', null );

    return is_array( $state ) ? $state : null;
}

function update_cycle_state( array $partial ): void {
    $state = array_merge( get_cycle_state() ?? [], $partial );
    $state['last_chunk_at'] = time();

    if ( get_option( '_ethos_migration_cycle', null ) === null ) {
        add_option( '_ethos_migration_cycle', $state, '', false );
    } else {
        update_option( '_ethos_migration_cycle', $state );
    }
}

function clear_cycle_state(): void {
    delete_option( '_ethos_migration_cycle' );
}

function start_cycle( bool $force ): array {
    $state = [
        'phase'                => 'accounts',
        'page'                 => 1,
        'per_page'             => ACCOUNTS_PER_PAGE,
        'force'                => $force,
        'started'              => current_datetime()->format( 'Y-m-d H:i:s P' ),
        'started_ts'           => microtime( true ),
        'query_signature'      => cycle_query_signature( ACCOUNTS_PER_PAGE ),
        'active_account_ids'   => [],
        'processed_accounts'   => 0,
        'total_count'          => 0,
        'total_errors'         => 0,
        'consecutive_failures' => 0,
    ];

    update_cycle_state( $state );

    return $state;
}

/**
 * A cycle that runs for too long (e.g. limping along on repeated retries)
 * is abandoned so the next daily start can begin fresh.
 */
function cycle_is_stale( array $state ): bool {
    $started_ts = (float) ( $state['started_ts'] ?? 0 );

    return ( $started_ts > 0 ) && ( ( microtime( true ) - $started_ts ) > MAX_CYCLE_AGE );
}

/**
 * The tick: advances an in-progress cycle by one chunk (one page of
 * accounts). No-ops when no cycle is running or when the previous chunk is
 * still in flight (lock held).
 */
function run_chunk_migration(): void {
    $state = get_cycle_state();

    if ( empty( $state ) ) {
        return;
    }

    global $ethos_migration_log_file;

    $opened_log = false;
    if ( empty( $ethos_migration_log_file ) ) {
        $ethos_migration_log_file = open_migration_log();
        $opened_log = true;
    }

    $locked = acquire_lock();

    try {
        if ( ! $locked ) {
            log_message( 'Chunk skipped: lock held (previous chunk still running).', 'warning' );
            return;
        }

        set_hacklab_as_current_user();

        if ( cycle_is_stale( $state ) ) {
            abort_cycle( $state, 'cycle is older than ' . round( MAX_CYCLE_AGE / HOUR_IN_SECONDS ) . ' hours' );
            return;
        }

        run_migration_chunk();
    } finally {
        if ( $locked ) {
            release_lock();
        }

        if ( $opened_log ) {
            $ethos_migration_log_file = null;
        }
    }
}
add_action( 'ethos_migration\run_chunk', 'ethos\\migration\\run_chunk_migration' );

/**
 * Daily cycle starter (02:00): creates the cycle state and processes page 1
 * right away; the recurring tick continues from page 2.
 */
function run_daily_migration(): void {
    global $ethos_migration_log_file;
    $ethos_migration_log_file = open_migration_log();

    try {
        prune_old_logs();
        set_hacklab_as_current_user();

        $state = get_cycle_state();

        if ( ! empty( $state ) ) {
            log_message( "Daily starter skipped: a cycle is already in progress (page {$state['page']}, started at {$state['started']}).", 'warning' );
            return;
        }

        start_cycle( false );
        log_message( 'Daily migration cycle started.' );

        run_chunk_migration();
    } finally {
        $ethos_migration_log_file = null;
    }
}
add_action( 'ethos_migration\run_daily', 'ethos\\migration\\run_daily_migration' );
