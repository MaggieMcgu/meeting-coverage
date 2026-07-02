<?php
/**
 * YouTube utility methods: channel ID resolution and RSS feed parsing.
 */
class MAAG_YouTube {

    /**
     * Resolve a YouTube channel URL to a channel ID (UCxxxx format).
     *
     * Handles:
     *   /channel/UCxxxx  — extract directly
     *   /@handle          — fetch page, extract from RSS link or page source
     *   /c/name           — same page-fetch approach
     *   /user/name        — same
     *
     * Returns channel ID string or null on failure.
     */
    public static function resolve_channel_id( $channel_url ) {
        $channel_url = esc_url_raw( trim( $channel_url ) );

        // Direct channel ID already in URL
        if ( preg_match( '#/channel/(UC[a-zA-Z0-9_-]{22})#', $channel_url, $m ) ) {
            return $m[1];
        }

        // Handle/custom/user URL — fetch the page to extract channel ID
        $response = wp_remote_get( $channel_url, [
            'timeout'    => 15,
            'user-agent' => 'Mozilla/5.0 (compatible; MeetingCoverage/' . MAAG_VERSION . ')',
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $html = wp_remote_retrieve_body( $response );

        // Best: extract from the RSS alternate link tag
        if ( preg_match( '#feeds/videos\.xml\?channel_id=(UC[a-zA-Z0-9_-]{22})#', $html, $m ) ) {
            return $m[1];
        }

        // Fallback: extract from page JSON-LD / embedded data
        if ( preg_match( '#"channelId"\s*:\s*"(UC[a-zA-Z0-9_-]{22})"#', $html, $m ) ) {
            return $m[1];
        }

        return null;
    }

    /**
     * Fetch the latest video from a channel's YouTube RSS feed.
     *
     * Returns array with keys: video_id, title, published (timestamp)
     * or WP_Error on failure.
     */
    public static function get_latest_video( $channel_id ) {
        $rss_url  = "https://www.youtube.com/feeds/videos.xml?channel_id={$channel_id}";
        $response = wp_remote_get( $rss_url, [
            'timeout'    => 15,
            'user-agent' => 'Mozilla/5.0 (compatible; MeetingCoverage/' . MAAG_VERSION . ')',
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'rss_error', "YouTube RSS returned HTTP {$code}" );
        }

        $xml_string = wp_remote_retrieve_body( $response );
        if ( empty( $xml_string ) ) {
            return new WP_Error( 'rss_empty', 'Empty RSS response from YouTube' );
        }

        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $xml_string );

        if ( ! $xml || ! isset( $xml->entry[0] ) ) {
            return new WP_Error( 'no_videos', 'No videos found in RSS feed' );
        }

        $entry = $xml->entry[0];
        $ns    = $entry->getNamespaces( true );
        $yt    = $entry->children( $ns['yt'] ?? 'http://www.youtube.com/xml/schemas/2015' );

        // Extract video ID — prefer yt:videoId, fall back to id text
        $video_id = (string) ( $yt->videoId ?? '' );
        if ( ! $video_id ) {
            preg_match( '#yt:video:(.+)$#', (string) $entry->id, $m );
            $video_id = $m[1] ?? '';
        }

        if ( ! $video_id ) {
            return new WP_Error( 'parse_error', 'Could not extract video ID from RSS entry' );
        }

        return [
            'video_id'  => $video_id,
            'title'     => (string) $entry->title,
            'published' => strtotime( (string) $entry->published ),
        ];
    }
}
