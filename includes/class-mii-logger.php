<?php
/**
 * Логирование запросов и ошибок.
 *
 * Логи пишутся в двух местах:
 *  1) файл wp-content/uploads/milyutin-instant-indexer/mii-indexer.log (защищён .htaccess);
 *  2) кольцевой буфер в опции mii_log — последние 50 записей для таблицы в админке.
 *
 * В логи НИКОГДА не попадают: приватный ключ, JWT, access_token и полный JSON
 * сервисного аккаунта. Всё подозрительное автоматически маскируется методом mask().
 *
 * @package Milyutin_Instant_Indexer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс MII_Logger.
 */
class MII_Logger {

	/**
	 * Опция с последними записями лога.
	 */
	const OPTION = 'mii_log';

	/**
	 * Сколько записей хранить в БД.
	 */
	const MAX_ENTRIES = 50;

	/**
	 * Максимальный размер файла лога до ротации (байт).
	 */
	const MAX_FILE_SIZE = 1048576; // 1 МБ.

	/**
	 * Добавить запись в лог.
	 *
	 * @param string $level   info|success|warning|error.
	 * @param string $context indexnow|google|system|ajax|skip.
	 * @param string $message Человекочитаемое сообщение.
	 * @param array  $data    Дополнительные данные: url, code, response и т.п.
	 * @return void
	 */
	public static function log( $level, $context, $message, $data = array() ) {
		$level   = in_array( $level, array( 'info', 'success', 'warning', 'error' ), true ) ? $level : 'info';
		$context = sanitize_key( $context );
		if ( '' === $context ) {
			$context = 'system';
		}

		$entry = array(
			'time'    => current_time( 'mysql' ),
			'level'   => $level,
			'context' => $context,
			'message' => self::clean_text( $message ),
			'url'     => isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '',
			'code'    => isset( $data['code'] ) ? (int) $data['code'] : 0,
			'extra'   => isset( $data['extra'] ) ? self::clean_text( (string) $data['extra'] ) : '',
		);

		// 1. Кольцевой буфер в опции.
		if ( MII_Settings::get( 'log_enabled', 1 ) ) {
			$buffer = get_option( self::OPTION, array() );
			if ( ! is_array( $buffer ) ) {
				$buffer = array();
			}
			array_unshift( $buffer, $entry );
			$buffer = array_slice( $buffer, 0, self::MAX_ENTRIES );
			update_option( self::OPTION, $buffer, false );
		}

		// 2. Файл.
		self::write_to_file( $entry );
	}

	/**
	 * Последние записи лога (для таблицы в админке).
	 *
	 * @param int $limit Сколько записей вернуть (по умолчанию 20).
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_entries( $limit = 20 ) {
		$limit  = max( 1, (int) $limit );
		$buffer = get_option( self::OPTION, array() );

		if ( ! is_array( $buffer ) ) {
			return array();
		}

		return array_slice( $buffer, 0, $limit );
	}

	/**
	 * Очистить логи (и буфер, и файл).
	 *
	 * @return void
	 */
	public static function clear() {
		update_option( self::OPTION, array(), false );

		$file = self::file_path();
		if ( $file && file_exists( $file ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $file, '' );
		}

		self::log( 'info', 'system', 'Логи очищены вручную.' );
	}

	/**
	 * Путь к файлу лога. Папка создаётся при первом обращении и защищается
	 * от прямого доступа по HTTP.
	 *
	 * @return string Путь или пустая строка, если запись невозможна.
	 */
	public static function file_path() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'milyutin-instant-indexer';

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		self::protect_dir( $dir );

		return trailingslashit( $dir ) . 'mii-indexer.log';
	}

	/**
	 * Положить в папку логов .htaccess и index.php (защита от чтения извне).
	 *
	 * @param string $dir Абсолютный путь к папке.
	 * @return void
	 */
	private static function protect_dir( $dir ) {
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Записать строку в файл лога.
	 *
	 * @param array $entry Запись лога.
	 * @return void
	 */
	private static function write_to_file( $entry ) {
		if ( ! MII_Settings::get( 'log_enabled', 1 ) ) {
			return;
		}

		$file = self::file_path();
		if ( '' === $file ) {
			return;
		}

		$line = sprintf(
			'[%s] %s/%s %s %s%s',
			$entry['time'],
			strtoupper( $entry['level'] ),
			$entry['context'],
			$entry['message'],
			$entry['url'] ? '| url=' . $entry['url'] : '',
			$entry['extra'] ? '| ' . $entry['extra'] : ''
		);

		// Если папка логов недоступна для записи — уходим в стандартный error_log хостинга.
		if ( function_exists( 'error_log' ) && ! wp_is_writable( dirname( $file ) ) ) {
			error_log( 'MII: ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $file, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Ротация: если файл больше лимита — оставляем последние ~256 КБ.
	 *
	 * @return void
	 */
	public static function rotate_file() {
		$file = self::file_path();
		if ( '' === $file || ! file_exists( $file ) ) {
			return;
		}

		if ( filesize( $file ) < self::MAX_FILE_SIZE ) {
			return;
		}

		$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return;
		}

		$tail = substr( $content, -262144 );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $file, $tail );
	}

	/**
	 * Маскировка секретов: ключи, токены, JWT, приватные ключи.
	 *
	 * @param string $value Исходная строка.
	 * @return string Безопасная строка.
	 */
	public static function mask( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		// JWT-подобные строки (header.payload.signature).
		$value = preg_replace( '/\b[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/', '***JWT***', $value );

		// Приватные ключи и токены доступа в JSON.
		$value = preg_replace( '/"private_key"\s*:\s*"[^"]*"/i', '"private_key":"***"', $value );
		$value = preg_replace( '/"client_secret"\s*:\s*"[^"]*"/i', '"client_secret":"***"', $value );
		$value = preg_replace( '/(access_token|refresh_token|assertion|id_token)"?\s*[:=]\s*"?[A-Za-z0-9._\-]{8,}/i', '$1=***', $value );

		// Bearer-заголовки.
		$value = preg_replace( '/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ***', $value );

		return $value;
	}

	/**
	 * Убрать переводы строк и обрезать слишком длинный текст.
	 *
	 * @param string $text Текст.
	 * @return string
	 */
	private static function clean_text( $text ) {
		$text = self::mask( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, 500 );
		}

		return substr( $text, 0, 500 );
	}
}
