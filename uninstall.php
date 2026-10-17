<?php
/**
 * Полная очистка данных при УДАЛЕНИИ плагина (не при деактивации).
 *
 * Удаляются: настройки, зашифрованный JSON сервисного аккаунта, логи, счётчики
 * квоты, очередь, физический файл ключа IndexNow и папка с логами.
 *
 * @package Milyutin_Instant_Indexer
 */

// Плагин удаляют только через админку WordPress — иначе молча выходим.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Настройки читаем ДО удаления опций — они нужны, чтобы найти файл ключа.
$mii_settings = get_option( 'mii_settings', array() );
$mii_key      = isset( $mii_settings['indexnow_key'] ) ? (string) $mii_settings['indexnow_key'] : '';

// ── Опции ────────────────────────────────────────────────────────────────────
$mii_options = array(
	'mii_settings',
	'mii_google_credentials',
	'mii_google_meta',
	'mii_google_quota',
	'mii_google_quota_blocked',
	'mii_google_queue',
	'mii_log',
	'mii_physical_key_file',
	'mii_flush_rewrite_needed',
);

foreach ( $mii_options as $mii_option ) {
	delete_option( $mii_option );
}

// Для мультисайта — чистим настройки каждого сайта сети.
if ( is_multisite() ) {
	$mii_sites = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $mii_sites as $mii_site_id ) {
		switch_to_blog( (int) $mii_site_id );
		foreach ( $mii_options as $mii_option ) {
			delete_option( $mii_option );
		}
		restore_current_blog();
	}
}

// ── Физический файл ключа в корне сайта ──────────────────────────────────────
// Удаляем файл, только если имя строго соответствует формату ключа.
if ( '' !== $mii_key && preg_match( '/^[A-Za-z0-9\-]{8,128}$/', $mii_key ) ) {
	$mii_path = trailingslashit( ABSPATH ) . $mii_key . '.txt';

	if ( file_exists( $mii_path ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $mii_path );
	}
}

// ── Папка с логами ───────────────────────────────────────────────────────────
$mii_uploads = wp_upload_dir();
if ( empty( $mii_uploads['error'] ) ) {
	$mii_dir = trailingslashit( $mii_uploads['basedir'] ) . 'milyutin-instant-indexer';

	$mii_files = array( 'mii-indexer.log', '.htaccess', 'index.php' );
	foreach ( $mii_files as $mii_file ) {
		$mii_file_path = trailingslashit( $mii_dir ) . $mii_file;
		if ( file_exists( $mii_file_path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $mii_file_path );
		}
	}

	// Удаляем папку, только если она пуста.
	if ( is_dir( $mii_dir ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $mii_dir );
	}
}

// ── Запланированные задачи ───────────────────────────────────────────────────
wp_clear_scheduled_hook( 'mii_indexnow_retry' );
wp_clear_scheduled_hook( 'mii_google_drain_queue' );
wp_clear_scheduled_hook( 'mii_daily_maintenance' );

flush_rewrite_rules();
