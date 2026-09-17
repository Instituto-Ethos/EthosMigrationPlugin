<?php

namespace ethos;

function remove_inactive_accounts( array $active_account_ids ): void {
    $accounts_map = [];
    foreach ( $active_account_ids as $account_id ) {
        $accounts_map[ $account_id ] = true;
    }

    $synced_posts = get_posts( [
        'post_type' => 'organizacao',
        'post_status' => 'publish',
        'meta_query' => [
            [ 'key' => '_ethos_crm_account_id', 'compare' => 'EXISTS' ],
        ],
        'posts_per_page' => -1,
    ] );

    $total_count = 0;
    $total_errors = 0;

    foreach ( $synced_posts as $post ) {
        $post_account = get_post_meta( $post->ID, '_ethos_crm_account_id', true );

        if ( empty( $accounts_map[ $post_account ] ) ) {
            migration\cli_log( "Removing {$post->post_title} ({$account_id})..." );

            try {
                crm\delete_from_account( $post );
                $total_count++;
            } catch ( \Exception $e ) {
                $total_errors++;
            }
        }
    }

    migration\cli_log( "Finished removing {$total_count} contacts, with {$total_errors} errors.", 'success' );
}
