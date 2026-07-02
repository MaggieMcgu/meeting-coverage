<?php
/**
 * Plugin settings page: Anthropic API key, relay URL, relay key.
 * WP admin → Meeting Coverage → Settings
 */
class MAAG_Settings {

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register' ] );
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
    }

    public function register() {
        register_setting( 'maag_options', 'maag_anthropic_key', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'maag_options', 'maag_relay_url',     [ 'sanitize_callback' => 'esc_url_raw' ] );
        register_setting( 'maag_options', 'maag_relay_key',     [ 'sanitize_callback' => 'sanitize_text_field' ] );

        add_settings_section( 'maag_api', 'API Configuration', null, 'maag-settings' );

        add_settings_field( 'maag_anthropic_key', 'Anthropic API Key',      [ $this, 'field_api_key'   ], 'maag-settings', 'maag_api' );
        add_settings_field( 'maag_relay_url',     'Transcript Relay URL',   [ $this, 'field_relay_url' ], 'maag-settings', 'maag_api' );
        add_settings_field( 'maag_relay_key',     'Relay API Key',          [ $this, 'field_relay_key' ], 'maag-settings', 'maag_api' );
    }

    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=maag_body',
            'Meeting Coverage Settings',
            'Settings',
            'manage_options',
            'maag-settings',
            [ $this, 'render_page' ]
        );
    }

    public function field_api_key() {
        $val = get_option( 'maag_anthropic_key', '' );
        ?>
        <input type="password" name="maag_anthropic_key" value="<?= esc_attr( $val ) ?>" class="regular-text" autocomplete="new-password">
        <p class="description">
            Your Anthropic API key (<code>sk-ant-...</code>). Get one at
            <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a>.
            Each meeting costs ~$0.16, billed directly to your Anthropic account.
        </p>
        <?php
    }

    public function field_relay_url() {
        $val = get_option( 'maag_relay_url', '' );
        ?>
        <input type="url" name="maag_relay_url" value="<?= esc_attr( $val ) ?>" class="regular-text" placeholder="<?= esc_attr( MAAG_DEFAULT_RELAY ) ?>">
        <p class="description">
            URL of your transcript relay. Leave blank to use the default hosted relay
            (<code><?= esc_html( MAAG_DEFAULT_RELAY ) ?></code>).
            To self-host: see the
            <a href="https://github.com/maggiemcgu/meeting-coverage/tree/main/relay" target="_blank">relay README</a>.
        </p>
        <?php
    }

    public function field_relay_key() {
        $val = get_option( 'maag_relay_key', '' );
        ?>
        <input type="password" name="maag_relay_key" value="<?= esc_attr( $val ) ?>" class="regular-text" autocomplete="new-password">
        <p class="description">
            API key for the relay (the <code>RELAY_API_KEY</code> you set in Vercel). Leave blank for an open relay.
        </p>
        <?php
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Meeting Coverage Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'maag_options' );
                do_settings_sections( 'maag-settings' );
                submit_button( 'Save Settings' );
                ?>
            </form>
        </div>
        <?php
    }
}
