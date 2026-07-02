<?php
// Runs when the plugin is deleted (not just deactivated).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove plugin options
delete_option( 'maag_anthropic_key' );
delete_option( 'maag_relay_url' );
delete_option( 'maag_relay_key' );

// Cancel scheduled cron
$ts = wp_next_scheduled( 'maag_check_feeds' );
if ( $ts ) {
    wp_unschedule_event( $ts, 'maag_check_feeds' );
}

// maag_body posts and their meta are left in place intentionally —
// they represent the outlet's configuration and generated drafts.
// If you want to purge them, run:
//   $posts = get_posts(['post_type'=>'maag_body','posts_per_page'=>-1,'fields'=>'ids']);
//   foreach ($posts as $id) { wp_delete_post($id, true); }
