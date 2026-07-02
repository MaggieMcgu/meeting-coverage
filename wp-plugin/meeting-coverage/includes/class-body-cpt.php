<?php
/**
 * Meeting Body custom post type.
 *
 * Stores per-body config (channel URL, entity name, agenda URL, roster, corrections).
 * Handles WP Cron RSS polling and the admin "Run Now" action.
 */
class MAAG_Body_CPT {

    public function __construct() {
        add_action( 'init',                                [ __CLASS__, 'register_cpt' ] );
        add_filter( 'cron_schedules',                      [ $this, 'add_cron_schedule' ] );
        add_action( 'add_meta_boxes',                      [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_maag_body',                 [ $this, 'save_meta' ], 10, 2 );
        add_action( 'maag_check_feeds',                    [ $this, 'check_all_feeds' ] );
        add_action( 'admin_post_maag_run_now',             [ $this, 'handle_run_now' ] );
        add_action( 'admin_notices',                       [ $this, 'admin_notices' ] );
        add_filter( 'manage_maag_body_posts_columns',      [ $this, 'add_columns' ] );
        add_action( 'manage_maag_body_posts_custom_column',[ $this, 'render_column' ], 10, 2 );
    }

    // -------------------------------------------------------------------------
    // CPT + cron registration

    public static function register_cpt() {
        register_post_type( 'maag_body', [
            'labels' => [
                'name'          => 'Meeting Bodies',
                'singular_name' => 'Meeting Body',
                'add_new'       => 'Add Body',
                'add_new_item'  => 'Add Meeting Body',
                'edit_item'     => 'Edit Meeting Body',
                'all_items'     => 'All Bodies',
                'search_items'  => 'Search Bodies',
                'menu_name'     => 'Meeting Coverage',
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'menu_icon'       => 'dashicons-microphone',
            'supports'        => [ 'title' ], // Title = entity name
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ] );
    }

    public function add_cron_schedule( $schedules ) {
        $schedules['maag_30min'] = [
            'interval' => 1800,
            'display'  => 'Every 30 Minutes (Meeting Coverage)',
        ];
        return $schedules;
    }

    // -------------------------------------------------------------------------
    // Meta boxes

    public function add_meta_boxes() {
        add_meta_box(
            'maag_body_config',
            'Body Configuration',
            [ $this, 'render_config_box' ],
            'maag_body',
            'normal',
            'high'
        );
        add_meta_box(
            'maag_body_status',
            'Status & Actions',
            [ $this, 'render_status_box' ],
            'maag_body',
            'side'
        );
    }

    public function render_config_box( $post ) {
        wp_nonce_field( 'maag_save_body_' . $post->ID, 'maag_body_nonce' );

        $channel_url = get_post_meta( $post->ID, '_maag_channel_url', true );
        $agenda_url  = get_post_meta( $post->ID, '_maag_agenda_url', true );
        $roster      = get_post_meta( $post->ID, '_maag_roster', true );
        $corrections = get_post_meta( $post->ID, '_maag_corrections', true );
        ?>
        <p style="color:#666;margin-top:0">The post title above is the <strong>entity name</strong> (e.g. "Grand County Commission").</p>
        <table class="form-table">
            <tr>
                <th style="width:200px"><label for="maag_channel_url">YouTube Channel URL <span style="color:red">*</span></label></th>
                <td>
                    <input type="url" id="maag_channel_url" name="maag_channel_url" value="<?= esc_attr( $channel_url ) ?>" class="widefat">
                    <p class="description">e.g. <code>https://www.youtube.com/@TownOfEstesPark</code> or <code>https://www.youtube.com/channel/UCxxxx</code></p>
                </td>
            </tr>
            <tr>
                <th><label for="maag_agenda_url">Agenda URL <span style="color:red">*</span></label></th>
                <td>
                    <input type="url" id="maag_agenda_url" name="maag_agenda_url" value="<?= esc_attr( $agenda_url ) ?>" class="widefat">
                    <p class="description">Public URL of the meeting agenda (PDF or HTML). Must be accessible without login. Note: JavaScript-rendered portals (CivicClerk, Granicus) may not work — test by opening in a private browser window and checking if the page loads without JS.</p>
                </td>
            </tr>
            <tr>
                <th><label for="maag_roster">Member Roster <em style="font-weight:normal">(optional)</em></label></th>
                <td>
                    <textarea id="maag_roster" name="maag_roster" rows="5" class="widefat"><?= esc_textarea( $roster ) ?></textarea>
                    <p class="description">One name per line. Auto-captions garble member names consistently — a roster eliminates most [VERIFY] flags on votes. Include phonetic variations if known (e.g. "Trustee Igel (sounds like Eagle)").</p>
                </td>
            </tr>
            <tr>
                <th><label for="maag_corrections">Local Name Corrections <em style="font-weight:normal">(optional)</em></label></th>
                <td>
                    <textarea id="maag_corrections" name="maag_corrections" rows="5" class="widefat"><?= esc_textarea( $corrections ) ?></textarea>
                    <p class="description">One correction per line: <code>garbled: correct</code><br>
                    Example: <code>Survey Construction: Cervi Construction</code><br>
                    <code>O-Tech: Otak</code></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function render_status_box( $post ) {
        $last_video  = get_post_meta( $post->ID, '_maag_last_video_id', true );
        $last_run    = get_post_meta( $post->ID, '_maag_last_run', true );
        $channel_id  = get_post_meta( $post->ID, '_maag_resolved_channel_id', true );
        $is_saved    = $post->post_status !== 'auto-draft';
        ?>
        <p>
            <strong>Last video processed:</strong><br>
            <?php if ( $last_video ): ?>
                <a href="https://youtu.be/<?= esc_attr( $last_video ) ?>" target="_blank"><?= esc_html( $last_video ) ?></a>
            <?php else: ?>
                <em>None yet</em>
            <?php endif; ?>
        </p>
        <p>
            <strong>Last run:</strong><br>
            <?= $last_run ? esc_html( $last_run ) : '<em>Never</em>' ?>
        </p>
        <?php if ( $channel_id ): ?>
        <p>
            <strong>Channel ID:</strong><br>
            <code style="font-size:11px"><?= esc_html( $channel_id ) ?></code>
        </p>
        <?php endif; ?>
        <hr>
        <?php if ( $post->post_status === 'publish' && $is_saved ): ?>
        <form method="post" action="<?= admin_url( 'admin-post.php' ) ?>">
            <?php wp_nonce_field( 'maag_run_' . $post->ID, 'maag_run_nonce' ); ?>
            <input type="hidden" name="action" value="maag_run_now">
            <input type="hidden" name="body_id" value="<?= intval( $post->ID ) ?>">
            <p><button type="submit" class="button button-primary" style="width:100%">&#9654; Run Now</button></p>
            <p class="description">Fetches the latest video from this channel, runs the pipeline, and creates a draft post.</p>
            <p class="description"><strong>Note:</strong> This runs the latest video regardless of the 48-hour window used by the cron job.</p>
        </form>
        <?php else: ?>
        <p class="description">Save and <strong>Publish</strong> this body to enable "Run Now".</p>
        <?php endif; ?>
        <?php
    }

    public function save_meta( $post_id, $post ) {
        if ( ! isset( $_POST['maag_body_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['maag_body_nonce'], 'maag_save_body_' . $post_id ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $url_fields  = [ 'maag_channel_url', 'maag_agenda_url' ];
        $text_fields = [ 'maag_roster', 'maag_corrections' ];

        foreach ( $url_fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, "_{$field}", esc_url_raw( $_POST[ $field ] ) );
            }
        }

        foreach ( $text_fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_post_meta( $post_id, "_{$field}", sanitize_textarea_field( $_POST[ $field ] ) );
            }
        }

        // Clear cached channel ID when channel URL changes
        if ( isset( $_POST['maag_channel_url'] ) ) {
            delete_post_meta( $post_id, '_maag_resolved_channel_id' );
        }
    }

    // -------------------------------------------------------------------------
    // Cron feed check

    public function check_all_feeds() {
        $body_ids = get_posts( [
            'post_type'      => 'maag_body',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );

        foreach ( $body_ids as $body_id ) {
            $this->check_body_feed( $body_id );
        }
    }

    private function check_body_feed( $body_id ) {
        $channel_url = get_post_meta( $body_id, '_maag_channel_url', true );
        if ( ! $channel_url ) return;

        // Resolve channel ID (cached in meta)
        $channel_id = $this->ensure_channel_id( $body_id, $channel_url );
        if ( ! $channel_id ) return;

        $video = MAAG_YouTube::get_latest_video( $channel_id );
        if ( is_wp_error( $video ) ) {
            error_log( '[MAAG] Body #' . $body_id . ': ' . $video->get_error_message() );
            return;
        }

        $last_video_id = get_post_meta( $body_id, '_maag_last_video_id', true );

        // Skip if already processed
        if ( $video['video_id'] === $last_video_id ) return;

        // Skip if older than 48 hours (safety valve for cron backfill)
        if ( $video['published'] < strtotime( '-48 hours' ) ) return;

        error_log( '[MAAG] Body #' . $body_id . ': new video ' . $video['video_id'] . ' (' . $video['title'] . ')' );
        MAAG_Pipeline::run( $body_id, $video );
    }

    // -------------------------------------------------------------------------
    // "Run Now" admin-post handler

    public function handle_run_now() {
        $body_id = intval( $_POST['body_id'] ?? 0 );
        $back    = admin_url( "post.php?post={$body_id}&action=edit" );

        if ( ! $body_id || ! current_user_can( 'edit_posts' ) ) {
            wp_die( 'Permission denied.' );
        }

        if ( ! wp_verify_nonce( $_POST['maag_run_nonce'] ?? '', 'maag_run_' . $body_id ) ) {
            wp_die( 'Security check failed.' );
        }

        $channel_url = get_post_meta( $body_id, '_maag_channel_url', true );
        if ( ! $channel_url ) {
            wp_redirect( add_query_arg( 'maag_msg', 'no_channel', $back ) );
            exit;
        }

        $channel_id = $this->ensure_channel_id( $body_id, $channel_url );
        if ( ! $channel_id ) {
            wp_redirect( add_query_arg( 'maag_msg', 'channel_error', $back ) );
            exit;
        }

        $video = MAAG_YouTube::get_latest_video( $channel_id );
        if ( is_wp_error( $video ) ) {
            wp_redirect( add_query_arg( [ 'maag_msg' => 'rss_error', 'maag_err' => urlencode( $video->get_error_message() ) ], $back ) );
            exit;
        }

        $result = MAAG_Pipeline::run( $body_id, $video );

        if ( is_wp_error( $result ) ) {
            wp_redirect( add_query_arg( [ 'maag_msg' => 'pipeline_error', 'maag_err' => urlencode( $result->get_error_message() ) ], $back ) );
        } else {
            wp_redirect( add_query_arg( [ 'maag_msg' => 'success', 'maag_post' => $result ], $back ) );
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // Admin notices (shown on body edit screen after "Run Now")

    public function admin_notices() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'maag_body' ) return;

        $msg = $_GET['maag_msg'] ?? '';
        if ( ! $msg ) return;

        switch ( $msg ) {
            case 'success':
                $post_id   = intval( $_GET['maag_post'] ?? 0 );
                $edit_link = $post_id ? ' <a href="' . get_edit_post_link( $post_id ) . '">View draft &rarr;</a>' : '';
                echo '<div class="notice notice-success is-dismissible"><p><strong>Meeting Coverage:</strong> Draft created.' . $edit_link . '</p></div>';
                break;

            case 'pipeline_error':
                $err = esc_html( urldecode( $_GET['maag_err'] ?? 'Unknown error' ) );
                echo '<div class="notice notice-error is-dismissible"><p><strong>Meeting Coverage pipeline failed:</strong> ' . $err . '</p></div>';
                break;

            case 'channel_error':
                echo '<div class="notice notice-error is-dismissible"><p><strong>Meeting Coverage:</strong> Could not resolve YouTube channel ID. Check the channel URL and save the body first.</p></div>';
                break;

            case 'rss_error':
                $err = esc_html( urldecode( $_GET['maag_err'] ?? '' ) );
                echo '<div class="notice notice-error is-dismissible"><p><strong>Meeting Coverage:</strong> Could not fetch YouTube RSS feed. ' . $err . '</p></div>';
                break;

            case 'no_channel':
                echo '<div class="notice notice-error is-dismissible"><p><strong>Meeting Coverage:</strong> No channel URL set. Add a YouTube channel URL and save first.</p></div>';
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Admin list columns

    public function add_columns( $columns ) {
        $new = [];
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'title' ) {
                $new['maag_channel']    = 'Channel';
                $new['maag_last_video'] = 'Last Video';
                $new['maag_last_run']   = 'Last Run';
            }
        }
        return $new;
    }

    public function render_column( $column, $post_id ) {
        switch ( $column ) {
            case 'maag_channel':
                $url = get_post_meta( $post_id, '_maag_channel_url', true );
                echo $url ? '<a href="' . esc_url( $url ) . '" target="_blank">View</a>' : '—';
                break;

            case 'maag_last_video':
                $vid = get_post_meta( $post_id, '_maag_last_video_id', true );
                echo $vid
                    ? '<a href="https://youtu.be/' . esc_attr( $vid ) . '" target="_blank">' . esc_html( $vid ) . '</a>'
                    : '—';
                break;

            case 'maag_last_run':
                $run = get_post_meta( $post_id, '_maag_last_run', true );
                echo $run ? esc_html( $run ) : '—';
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers

    private function ensure_channel_id( $body_id, $channel_url ) {
        $channel_id = get_post_meta( $body_id, '_maag_resolved_channel_id', true );
        if ( $channel_id ) return $channel_id;

        $channel_id = MAAG_YouTube::resolve_channel_id( $channel_url );
        if ( $channel_id ) {
            update_post_meta( $body_id, '_maag_resolved_channel_id', $channel_id );
        }
        return $channel_id;
    }
}
