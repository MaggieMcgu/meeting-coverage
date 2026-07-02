<?php
/**
 * REST API endpoints.
 *
 * POST /wp-json/maag/v1/run/{body_id}   — trigger pipeline for a body (latest video)
 * POST /wp-json/maag/v1/run/{body_id}?force=1 — re-run even if video already processed
 * GET  /wp-json/maag/v1/bodies          — list configured bodies
 * GET  /wp-json/maag/v1/drafts          — list recent AI-generated drafts
 *
 * Auth: WP application passwords or logged-in cookie (manage_options capability).
 */
class MAAG_Rest_API {

    const NS = 'maag/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( self::NS, '/run/(?P<body_id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_run' ],
            'permission_callback' => [ $this, 'require_manage_options' ],
            'args'                => [
                'body_id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
                'force'   => [ 'type' => 'boolean', 'default' => false ],
            ],
        ] );

        register_rest_route( self::NS, '/bodies', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_bodies' ],
            'permission_callback' => [ $this, 'require_manage_options' ],
        ] );

        register_rest_route( self::NS, '/drafts', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'list_drafts' ],
            'permission_callback' => [ $this, 'require_manage_options' ],
            'args'                => [
                'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
            ],
        ] );
    }

    public function require_manage_options() {
        return current_user_can( 'manage_options' );
    }

    // POST /maag/v1/run/{body_id}
    public function handle_run( WP_REST_Request $request ) {
        $body_id = intval( $request->get_param( 'body_id' ) );
        $force   = (bool) $request->get_param( 'force' );

        if ( get_post_type( $body_id ) !== 'maag_body' ) {
            return new WP_Error( 'not_found', 'Meeting body not found.', [ 'status' => 404 ] );
        }

        if ( get_post_status( $body_id ) !== 'publish' ) {
            return new WP_Error( 'not_published', 'Meeting body must be published before running.', [ 'status' => 400 ] );
        }

        $channel_url = get_post_meta( $body_id, '_maag_channel_url', true );
        if ( ! $channel_url ) {
            return new WP_Error( 'no_channel', 'No YouTube channel URL configured for this body.', [ 'status' => 400 ] );
        }

        // Resolve channel ID
        $channel_id = get_post_meta( $body_id, '_maag_resolved_channel_id', true );
        if ( ! $channel_id ) {
            $channel_id = MAAG_YouTube::resolve_channel_id( $channel_url );
            if ( ! $channel_id ) {
                return new WP_Error( 'channel_error', 'Could not resolve YouTube channel ID from the channel URL.', [ 'status' => 502 ] );
            }
            update_post_meta( $body_id, '_maag_resolved_channel_id', $channel_id );
        }

        $video = MAAG_YouTube::get_latest_video( $channel_id );
        if ( is_wp_error( $video ) ) {
            return new WP_Error( $video->get_error_code(), $video->get_error_message(), [ 'status' => 502 ] );
        }

        // Skip if already processed (unless ?force=1)
        $last_video_id = get_post_meta( $body_id, '_maag_last_video_id', true );
        if ( ! $force && $video['video_id'] === $last_video_id ) {
            return rest_ensure_response( [
                'status'   => 'skipped',
                'reason'   => 'Video already processed. Pass ?force=1 to re-run.',
                'video_id' => $video['video_id'],
                'title'    => $video['title'],
            ] );
        }

        $result = MAAG_Pipeline::run( $body_id, $video );

        if ( is_wp_error( $result ) ) {
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 500 ] );
        }

        return rest_ensure_response( [
            'status'    => 'success',
            'draft_id'  => $result,
            'edit_url'  => get_edit_post_link( $result, 'raw' ),
            'video_id'  => $video['video_id'],
            'title'     => $video['title'],
        ] );
    }

    // GET /maag/v1/bodies
    public function list_bodies( WP_REST_Request $request ) {
        $posts = get_posts( [
            'post_type'      => 'maag_body',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
        ] );

        $bodies = array_map( function ( $p ) {
            return [
                'id'            => $p->ID,
                'entity_name'   => get_the_title( $p ),
                'channel_url'   => get_post_meta( $p->ID, '_maag_channel_url', true ),
                'agenda_url'    => get_post_meta( $p->ID, '_maag_agenda_url', true ),
                'last_video_id' => get_post_meta( $p->ID, '_maag_last_video_id', true ),
                'last_run'      => get_post_meta( $p->ID, '_maag_last_run', true ),
                'channel_id'    => get_post_meta( $p->ID, '_maag_resolved_channel_id', true ),
            ];
        }, $posts );

        return rest_ensure_response( $bodies );
    }

    // GET /maag/v1/drafts
    public function list_drafts( WP_REST_Request $request ) {
        $per_page = intval( $request->get_param( 'per_page' ) );

        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'draft',
            'posts_per_page' => $per_page,
            'meta_key'       => '_maag_source_body',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $drafts = array_map( function ( $p ) {
            return [
                'id'             => $p->ID,
                'title'          => $p->post_title,
                'date'           => $p->post_date,
                'entity'         => get_post_meta( $p->ID, '_maag_source_entity', true ),
                'video_id'       => get_post_meta( $p->ID, '_maag_source_video', true ),
                'body_id'        => get_post_meta( $p->ID, '_maag_source_body', true ),
                'generated_date' => get_post_meta( $p->ID, '_maag_generated_date', true ),
                'edit_url'       => get_edit_post_link( $p->ID, 'raw' ),
            ];
        }, $posts );

        return rest_ensure_response( $drafts );
    }
}
