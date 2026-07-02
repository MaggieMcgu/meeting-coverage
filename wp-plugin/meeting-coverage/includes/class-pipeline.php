<?php
/**
 * The MaaG pipeline: transcript → agenda → Claude → WP draft.
 *
 * All methods are static. Call MAAG_Pipeline::run( $body_id, $video ) where
 * $video = ['video_id'=>'...', 'title'=>'...', 'published'=>timestamp].
 */
class MAAG_Pipeline {

    /**
     * Run the full pipeline for one video.
     *
     * @param int   $body_id  maag_body post ID
     * @param array $video    ['video_id', 'title', 'published'] from MAAG_YouTube::get_latest_video()
     * @return int|WP_Error   Created draft post ID or error
     */
    public static function run( $body_id, $video ) {
        $entity      = get_the_title( $body_id );
        $api_key     = get_option( 'maag_anthropic_key', '' );
        $relay_url   = get_option( 'maag_relay_url', MAAG_DEFAULT_RELAY );
        $relay_key   = get_option( 'maag_relay_key', '' );
        $agenda_url  = get_post_meta( $body_id, '_maag_agenda_url', true );
        $roster      = get_post_meta( $body_id, '_maag_roster', true );
        $corrections = get_post_meta( $body_id, '_maag_corrections', true );

        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', 'Anthropic API key not configured in Meeting Coverage Settings.' );
        }

        $video_id   = $video['video_id'];
        $video_date = date( 'F j, Y', $video['published'] );

        self::log( "Starting pipeline for body #{$body_id} ({$entity}), video {$video_id} ({$video_date})" );

        // 1. Fetch transcript
        $transcript = self::fetch_transcript( $relay_url, $relay_key, $video_id );
        if ( is_wp_error( $transcript ) ) {
            self::log( "Transcript failed: " . $transcript->get_error_message() );
            return $transcript;
        }
        self::log( "Transcript: " . number_format( strlen( $transcript ) ) . " chars" );

        // 2. Fetch agenda
        $agenda_text = self::fetch_agenda( $agenda_url );
        if ( empty( $agenda_text ) ) {
            $agenda_text = "(Agenda could not be fetched from {$agenda_url} — [VERIFY: all agenda items and outcomes])";
            self::log( "Agenda fetch failed, using placeholder" );
        } else {
            self::log( "Agenda: " . number_format( strlen( $agenda_text ) ) . " chars" );
        }

        // 3. Build prompt and call Claude
        $prompt  = self::build_prompt( $entity, $video_date, $agenda_text, $transcript, $roster, $corrections );
        $content = self::call_claude( $api_key, $prompt );
        if ( is_wp_error( $content ) ) {
            self::log( "Claude failed: " . $content->get_error_message() );
            return $content;
        }

        // 4. Create WP draft
        $post_title = "{$entity} Meeting — {$video_date}";
        $post_id    = wp_insert_post( [
            'post_title'   => $post_title,
            'post_content' => $content,
            'post_status'  => 'draft',
            'post_type'    => 'post',
            'post_excerpt' => 'AI-generated meeting summary — contains [VERIFY: ...] flags. Do not publish without human review.',
            'meta_input'   => [
                '_maag_source_body'    => $body_id,
                '_maag_source_video'   => $video_id,
                '_maag_source_entity'  => $entity,
                '_maag_generated_date' => current_time( 'mysql' ),
            ],
        ] );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // 5. Mark video as processed on the body
        update_post_meta( $body_id, '_maag_last_video_id', $video_id );
        update_post_meta( $body_id, '_maag_last_run', current_time( 'mysql' ) );

        self::log( "Created draft #{$post_id}: {$post_title}" );
        return $post_id;
    }

    // -------------------------------------------------------------------------

    private static function fetch_transcript( $relay_url, $relay_key, $video_id ) {
        $url    = trailingslashit( $relay_url ) . 'transcript';
        $params = [ 'v' => $video_id ];
        if ( $relay_key ) {
            $params['key'] = $relay_key;
        }

        $response = wp_remote_get( add_query_arg( $params, $url ), [ 'timeout' => 45 ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $detail = $body['detail'] ?? $body['error'] ?? "relay returned HTTP {$code}";
            return new WP_Error( 'relay_error', "Transcript relay: {$detail}" );
        }

        $text = $body['transcript'] ?? '';
        if ( empty( $text ) ) {
            return new WP_Error( 'empty_transcript', 'Relay returned empty transcript' );
        }

        return $text;
    }

    private static function fetch_agenda( $url ) {
        if ( empty( $url ) ) {
            return '';
        }

        $response = wp_remote_get( $url, [
            'timeout'    => 20,
            'user-agent' => 'Mozilla/5.0 (compatible; MeetingCoverage/' . MAAG_VERSION . ')',
        ] );

        if ( is_wp_error( $response ) ) {
            return '';
        }

        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        $body         = wp_remote_retrieve_body( $response );

        // Detect PDF by content-type or magic bytes
        $is_pdf = strpos( $content_type, 'pdf' ) !== false || substr( $body, 0, 4 ) === '%PDF';

        if ( $is_pdf ) {
            return self::extract_pdf_text( $body );
        }

        if ( strpos( $content_type, 'html' ) !== false ) {
            return wp_strip_all_tags( $body );
        }

        return $body;
    }

    private static function extract_pdf_text( $pdf_content ) {
        $tmpfile = tempnam( sys_get_temp_dir(), 'maag_' );
        file_put_contents( $tmpfile, $pdf_content );
        $text = '';

        if ( self::command_available( 'pdftotext' ) ) {
            $text = shell_exec( 'pdftotext ' . escapeshellarg( $tmpfile ) . ' - 2>/dev/null' );
        }

        if ( empty( $text ) && self::command_available( 'gs' ) ) {
            $text = shell_exec(
                'gs -dBATCH -dNOPAUSE -sDEVICE=txtwrite -sOutputFile=- '
                . escapeshellarg( $tmpfile ) . ' 2>/dev/null'
            );
        }

        if ( empty( $text ) ) {
            $text = self::extract_pdf_text_php( $pdf_content );
        }

        @unlink( $tmpfile );
        return trim( $text );
    }

    /**
     * Crude PHP-native PDF text extraction (works for uncompressed, simple PDFs).
     * Not a substitute for pdftotext but catches most plain-text government agendas.
     */
    private static function extract_pdf_text_php( $content ) {
        $text = '';

        // PDF string literals in parentheses
        preg_match_all( '/\(((?:[^()\\\\]|\\\\.)*)\)/', $content, $matches );
        foreach ( $matches[1] as $s ) {
            $s = stripslashes( $s );
            if ( preg_match( '/[a-zA-Z]{2,}/', $s ) ) {
                $text .= $s . ' ';
            }
        }

        return preg_replace( '/\s+/', ' ', trim( $text ) );
    }

    private static function command_available( $cmd ) {
        $output = shell_exec( 'which ' . escapeshellarg( $cmd ) . ' 2>/dev/null' );
        return ! empty( $output );
    }

    private static function build_prompt( $entity, $date, $agenda, $transcript, $roster, $corrections ) {
        $prompt = "You are a local news editor creating a structured meeting summary.\n\n";
        $prompt .= "ENTITY: {$entity}\n";
        $prompt .= "MEETING DATE: {$date}\n";

        if ( ! empty( $roster ) ) {
            $prompt .= "\nKNOWN MEMBER ROSTER (auto-captions garble names — use this list to match):\n{$roster}\n";
        }

        if ( ! empty( $corrections ) ) {
            $prompt .= "\nLOCAL NAME CORRECTIONS (garbled name: correct name):\n{$corrections}\n";
        }

        $prompt .= "\nAGENDA:\n{$agenda}\n";
        $prompt .= "\nTRANSCRIPT (auto-generated captions — names may be garbled):\n{$transcript}\n";

        $prompt .= <<<'PROMPT'

Generate a "Meeting at a Glance" with these sections:

## Quick Takes
3–5 bullets, most newsworthy items first.

## Agenda Items
For each item: outcome (passed / failed / tabled / discussed / no action) + vote tally if audible. Format: **Item Name** — Outcome (X–Y). If individual votes are unclear: "Individual votes unclear — [VERIFY: ▶ H:MM:SS]"

## Key Quotes
Direct quotes with speaker attribution. Add [VERIFY: name uncertain] if speaker identity is not clear from agenda or roster.

## What's Next
Upcoming deadlines, return dates, next meeting items mentioned.

---

Rules:
- Use [VERIFY: reason] whenever a name, tally, dollar amount, or quote is uncertain
- For votes: state outcome + tally if audible; give a timecode [VERIFY: ▶ H:MM:SS] when tally is unclear so an editor can jump to the moment
- Match names in transcript to agenda and roster; prefer agenda/roster spelling over auto-caption spelling
- Never invent names not in the agenda, roster, or clearly audible in transcript
- For legal descriptions, ordinance numbers, dollar amounts: always add [VERIFY: confirm from official record]
- Format for a local newspaper audience — clear, direct, no jargon
PROMPT;

        return $prompt;
    }

    private static function call_claude( $api_key, $prompt ) {
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'      => 'claude-sonnet-4-6',
                'max_tokens' => 4096,
                'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
            ] ),
            'timeout' => 90,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $body['error']['message'] ?? "Claude API returned HTTP {$code}";
            return new WP_Error( 'claude_error', $msg );
        }

        $text = $body['content'][0]['text'] ?? '';
        if ( empty( $text ) ) {
            return new WP_Error( 'empty_response', 'Claude returned an empty response' );
        }

        return $text;
    }

    private static function log( $msg ) {
        error_log( '[MAAG] ' . $msg );
    }
}
