<?php
/**
 * Plugin Name: Web para pruebas
 * Description: Identifica sitios de pruebas, controla el correo saliente y activa las actualizaciones automáticas.
 * Version: 1.0.0
 * Author: Taisa - Raquel Garcia Arevalo
 * Author URI: https://www.taisadigital.com
 * Text Domain: web-para-pruebas
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Taisa_Web_Para_Pruebas {
    const VERSION = '1.0.0';
    const OPTION = 'taisawpp_settings';
    const NOTICE = 'taisawpp_blocked_mail_notice';
    const DISMISS_META = '_taisawpp_banner_hidden_until';
    const GITHUB_REPO = 'TaisaDigital/web-para-pruebas';

    public static function init() {
        add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'github_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'github_plugin_info' ), 20, 3 );
        add_action( 'admin_menu', array( __CLASS__, 'settings_page' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_banner' ) );
        add_action( 'admin_notices', array( __CLASS__, 'mail_notice' ) );
        add_action( 'wp_body_open', array( __CLASS__, 'frontend_banner' ) );
        add_action( 'wp_footer', array( __CLASS__, 'frontend_banner_fallback' ), 1 );
        add_action( 'admin_post_taisawpp_dismiss_banner', array( __CLASS__, 'dismiss_banner' ) );
        add_filter( 'wp_mail', array( __CLASS__, 'filter_mail' ), 1 );
        add_filter( 'auto_update_core', '__return_true', 999 );
        add_filter( 'auto_update_plugin', '__return_true', 999 );
        add_filter( 'auto_update_theme', '__return_true', 999 );
        add_filter( 'auto_update_translation', '__return_true', 999 );
        add_filter( 'allow_major_auto_core_updates', '__return_true', 999 );
        add_filter( 'allow_minor_auto_core_updates', '__return_true', 999 );
        add_filter( 'auto_core_update_send_email', '__return_false', 999 );
        add_filter( 'auto_plugin_update_send_email', '__return_false', 999 );
        add_filter( 'auto_theme_update_send_email', '__return_false', 999 );
        add_filter( 'xmlrpc_enabled', '__return_false', 999 );
    }

    public static function load_textdomain() { load_plugin_textdomain( 'web-para-pruebas', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); }
    private static function defaults() { return array( 'banner' => 1, 'frontend' => 1, 'backend' => 1, 'mail_mode' => 'block', 'allowlist' => '' ); }
    private static function settings() { return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() ); }
    private static function is_german() { return 0 === strpos( determine_locale(), 'de_' ) || 'de' === determine_locale(); }
    private static function t( $es, $de ) { return self::is_german() ? $de : $es; }
    private static function label() { return self::t( 'Web para pruebas', 'Testwebsite' ); }
    private static function banner_visible() {
        $s = self::settings();
        if ( empty( $s['banner'] ) ) return false;
        $until = (int) get_user_meta( get_current_user_id(), self::DISMISS_META, true );
        return $until < time();
    }

    public static function settings_page() { add_options_page( self::t( 'Web para pruebas', 'Testwebsite' ), self::t( 'Web para pruebas', 'Testwebsite' ), 'manage_options', 'web-para-pruebas', array( __CLASS__, 'render_settings' ) ); }
    public static function register_settings() { register_setting( 'taisawpp', self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) ); }
    public static function sanitize( $input ) {
        $input = is_array( $input ) ? $input : array();
        $emails = isset( $input['allowlist'] ) ? preg_split( '/[\r\n,;]+/', sanitize_textarea_field( $input['allowlist'] ) ) : array();
        $emails = array_filter( array_map( 'sanitize_email', $emails ), 'is_email' );
        return array( 'banner' => empty( $input['banner'] ) ? 0 : 1, 'frontend' => empty( $input['frontend'] ) ? 0 : 1, 'backend' => empty( $input['backend'] ) ? 0 : 1, 'mail_mode' => ( isset( $input['mail_mode'] ) && 'allowlist' === $input['mail_mode'] ) ? 'allowlist' : ( ( isset( $input['mail_mode'] ) && 'on' === $input['mail_mode'] ) ? 'on' : 'block' ), 'allowlist' => implode( "\n", array_unique( $emails ) ) );
    }
    private static function checkbox( $key, $label, $s ) { printf( '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label><br>', esc_attr( self::OPTION ), esc_attr( $key ), checked( ! empty( $s[$key] ), true, false ), esc_html( $label ) ); }
    public static function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $s = self::settings();
        echo '<div class="wrap"><h1>' . esc_html( self::t( 'Web para pruebas', 'Testwebsite' ) ) . '</h1><form method="post" action="options.php">';
        settings_fields( 'taisawpp' );
        echo '<h2>' . esc_html( self::t( 'Aviso del sitio', 'Website-Hinweis' ) ) . '</h2><p>';
        self::checkbox( 'banner', self::t( 'Mostrar el aviso', 'Hinweis anzeigen' ), $s );
        self::checkbox( 'frontend', self::t( 'En la web pública', 'Auf der Website' ), $s );
        self::checkbox( 'backend', self::t( 'En el escritorio de WordPress', 'Im WordPress-Backend' ), $s );
        echo '</p><h2>' . esc_html( self::t( 'Correo saliente', 'Ausgehende E-Mails' ) ) . '</h2><fieldset>';
        foreach ( array( 'block' => self::t( 'Bloquear todos los correos (recomendado)', 'Alle E-Mails blockieren (empfohlen)' ), 'allowlist' => self::t( 'Permitir solo las direcciones indicadas', 'Nur angegebene Adressen zulassen' ), 'on' => self::t( 'Activar todos los correos', 'Alle E-Mails aktivieren' ) ) as $value => $label ) printf( '<label><input type="radio" name="%1$s[mail_mode]" value="%2$s" %3$s> %4$s</label><br>', esc_attr( self::OPTION ), esc_attr( $value ), checked( $s['mail_mode'], $value, false ), esc_html( $label ) );
        echo '</fieldset><p><label for="taisawpp-allowlist">' . esc_html( self::t( 'Direcciones permitidas (una por línea):', 'Erlaubte Adressen (eine pro Zeile):' ) ) . '</label><br><textarea class="large-text" rows="5" id="taisawpp-allowlist" name="' . esc_attr( self::OPTION ) . '[allowlist]">' . esc_textarea( $s['allowlist'] ) . '</textarea></p>';
        echo '<h2>' . esc_html( self::t( 'Actualizaciones y tareas', 'Updates und Aufgaben' ) ) . '</h2><p>' . esc_html( self::t( 'Las actualizaciones automáticas de WordPress, plugins, temas y traducciones están activadas. WP-Cron se mantiene activo porque WordPress lo necesita para ejecutar las actualizaciones automáticas y las tareas programadas de plugins.', 'Automatische Updates für WordPress, Plugins, Themes und Übersetzungen sind aktiviert. WP-Cron bleibt aktiv, da WordPress ihn für automatische Updates und geplante Plugin-Aufgaben benötigt.' ) ) . '</p><p>' . esc_html( self::t( 'Se desactiva XML-RPC. No se bloquea WP-Cron de forma general para no interrumpir actualizaciones, pedidos, copias de seguridad ni otras tareas del sitio.', 'XML-RPC ist deaktiviert. WP-Cron wird nicht pauschal blockiert, damit Updates, Bestellungen, Backups und andere Website-Aufgaben weiterlaufen.' ) ) . '</p>';
        submit_button(); echo '</form></div>';
    }

    private static function banner_markup() {
        $label = self::label();
        $dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=taisawpp_dismiss_banner' ), 'taisawpp_dismiss' );
        return '<div class="taisawpp-banner" style="background:#7a2f00;color:#fff;text-align:center;padding:9px 42px;position:relative;z-index:99999;font:600 15px/1.4 sans-serif">' . esc_html( $label ) . ( is_user_logged_in() ? '<a href="' . esc_url( $dismiss ) . '" style="color:#fff;position:absolute;right:12px;text-decoration:underline;font-weight:400">' . esc_html( self::t( 'Ocultar 30 min', '30 Minuten ausblenden' ) ) . '</a>' : '' ) . '</div>';
    }
    public static function admin_banner() { $s = self::settings(); if ( is_admin() && self::banner_visible() && ! empty( $s['backend'] ) ) echo '<div class="notice" style="padding:0;border:0">' . self::banner_markup() . '</div>'; }
    public static function frontend_banner() { $s = self::settings(); if ( ! is_admin() && self::banner_visible() && ! empty( $s['frontend'] ) ) { echo self::banner_markup(); } }
    public static function frontend_banner_fallback() { if ( ! did_action( 'wp_body_open' ) ) self::frontend_banner(); }
    public static function dismiss_banner() { if ( ! current_user_can( 'read' ) ) wp_die( esc_html( self::t( 'No tienes permiso para hacerlo.', 'Du hast keine Berechtigung für diese Aktion.' ) ) ); check_admin_referer( 'taisawpp_dismiss' ); update_user_meta( get_current_user_id(), self::DISMISS_META, time() + 30 * MINUTE_IN_SECONDS ); wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() ); exit; }

    public static function filter_mail( $mail ) {
        $s = self::settings();
        if ( 'on' === $s['mail_mode'] ) return $mail;
        $recipients = preg_split( '/[,;]+/', is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : (string) $mail['to'] );
        $allowed = 'allowlist' === $s['mail_mode'] ? array_filter( array_map( 'trim', explode( "\n", $s['allowlist'] ) ) ) : array();
        $blocked = array();
        foreach ( $recipients as $recipient ) {
            if ( preg_match( '/<([^>]+)>/', $recipient, $m ) ) $recipient = $m[1];
            $recipient = sanitize_email( trim( $recipient ) );
            if ( ! $recipient || ! in_array( strtolower( $recipient ), array_map( 'strtolower', $allowed ), true ) ) $blocked[] = $recipient ?: self::t( 'destinatario desconocido', 'unbekannter Empfänger' );
        }
        if ( empty( $blocked ) ) return $mail;
        $entry = array( 'time' => time(), 'to' => array_values( array_unique( $blocked ) ), 'subject' => sanitize_text_field( $mail['subject'] ) );
        $log = get_transient( self::NOTICE ); $log = is_array( $log ) ? $log : array(); array_unshift( $log, $entry ); set_transient( self::NOTICE, array_slice( $log, 0, 10 ), DAY_IN_SECONDS );
        return false;
    }
    public static function mail_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $log = get_transient( self::NOTICE ); if ( ! is_array( $log ) || ! $log ) return;
        echo '<div class="notice notice-warning"><p><strong>' . esc_html( self::t( 'Web para pruebas: se han bloqueado correos salientes.', 'Testwebsite: Ausgehende E-Mails wurden blockiert.' ) ) . '</strong></p><ul>';
        foreach ( array_slice( $log, 0, 5 ) as $entry ) printf( '<li>%1$s — %2$s — %3$s</li>', esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ) ), esc_html( implode( ', ', $entry['to'] ) ), esc_html( $entry['subject'] ) );
        echo '</ul><p>' . esc_html( self::t( 'Los avisos se conservan durante 24 horas.', 'Hinweise bleiben 24 Stunden gespeichert.' ) ) . '</p></div>';
    }

    private static function latest_release() {
        $cached = get_transient( 'taisawpp_github_release' );
        if ( false !== $cached ) return $cached;
        $response = wp_remote_get( 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest', array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'Web-para-pruebas/' . self::VERSION ) ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) return false;
        $release = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $release['tag_name'] ) || empty( $release['assets'] ) ) return false;
        set_transient( 'taisawpp_github_release', $release, 6 * HOUR_IN_SECONDS );
        return $release;
    }
    private static function release_package( $release ) {
        foreach ( $release['assets'] as $asset ) if ( 'web-para-pruebas.zip' === $asset['name'] ) return $asset['browser_download_url'];
        return '';
    }
    public static function github_update( $transient ) {
        if ( ! is_object( $transient ) || empty( $transient->checked ) ) return $transient;
        $release = self::latest_release();
        if ( ! $release ) return $transient;
        $version = ltrim( $release['tag_name'], 'v' );
        $plugin = plugin_basename( __FILE__ );
        $package = self::release_package( $release );
        if ( $package && version_compare( $version, self::VERSION, '>' ) ) $transient->response[$plugin] = (object) array( 'slug' => 'web-para-pruebas', 'plugin' => $plugin, 'new_version' => $version, 'url' => $release['html_url'], 'package' => $package );
        return $transient;
    }
    public static function github_plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'web-para-pruebas' !== $args->slug ) return $result;
        $release = self::latest_release();
        if ( ! $release ) return $result;
        return (object) array( 'name' => 'Web para pruebas', 'slug' => 'web-para-pruebas', 'version' => ltrim( $release['tag_name'], 'v' ), 'author' => 'Taisa - Raquel Garcia Arevalo', 'homepage' => $release['html_url'], 'download_link' => self::release_package( $release ), 'sections' => array( 'description' => 'Plugin ligero para sitios de pruebas.' ) );
    }
}
Taisa_Web_Para_Pruebas::init();

