<?php
/**
 * Шифрование секретов плагина (JSON-ключ сервисного аккаунта Google).
 *
 * Схема: AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC), ключ выводится из
 * «солей» WordPress (AUTH_KEY и т.п.). Секреты никогда не попадают в БД в
 * открытом виде и не выводятся на страницу настроек.
 *
 * ВАЖНО: если вы смените соли в wp-config.php (или перенесёте сайт без переноса
 * солей), сохранённый JSON перестанет расшифровываться — плагин сообщит об этом
 * и попросит загрузить ключ заново.
 *
 * @package Milyutin_Instant_Indexer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс MII_Crypto.
 */
class MII_Crypto {

	/**
	 * Префикс версии формата зашифрованного значения.
	 */
	const PREFIX = 'mii1:';

	/**
	 * Доступно ли шифрование на сервере.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& function_exists( 'openssl_random_pseudo_bytes' )
			&& function_exists( 'hash_hmac' )
			&& in_array( 'aes-256-cbc', openssl_get_cipher_methods(), true );
	}

	/**
	 * Ключ шифрования (32 байта), производный от солей WordPress.
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|mii|' . wp_salt( 'secure_auth' ), true );
	}

	/**
	 * Зашифровать строку.
	 *
	 * @param string $plain Открытый текст.
	 * @return string Пустая строка при ошибке или недоступности OpenSSL.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || ! self::is_available() ) {
			return '';
		}

		$method  = 'aes-256-cbc';
		$key     = self::key();
		$iv_len  = openssl_cipher_iv_length( $method );
		$iv      = openssl_random_pseudo_bytes( $iv_len );
		$cipher  = openssl_encrypt( $plain, $method, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher || false === $iv ) {
			return '';
		}

		// HMAC считаем по IV + шифротексту (encrypt-then-MAC).
		$hmac = hash_hmac( 'sha256', $iv . $cipher, $key, true );

		return self::PREFIX . base64_encode( $iv . $hmac . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Расшифровать строку.
	 *
	 * @param string $payload Значение из опции.
	 * @return string|false Открытый текст или false, если расшифровать нельзя.
	 */
	public static function decrypt( $payload ) {
		$payload = (string) $payload;

		if ( '' === $payload || ! self::is_available() ) {
			return false;
		}
		if ( 0 !== strpos( $payload, self::PREFIX ) ) {
			return false;
		}

		$raw = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return false;
		}

		$key    = self::key();
		$method = 'aes-256-cbc';
		$iv_len = openssl_cipher_iv_length( $method );

		// Минимальная длина: IV + 32 байта HMAC + хотя бы 1 блок шифротекста.
		if ( strlen( $raw ) < ( $iv_len + 32 + 16 ) ) {
			return false;
		}

		$iv     = substr( $raw, 0, $iv_len );
		$hmac   = substr( $raw, $iv_len, 32 );
		$cipher = substr( $raw, $iv_len + 32 );

		$calc = hash_hmac( 'sha256', $iv . $cipher, $key, true );
		if ( ! hash_equals( $calc, $hmac ) ) {
			// Данные повреждены либо сменились соли WordPress.
			return false;
		}

		$plain = openssl_decrypt( $cipher, $method, $key, OPENSSL_RAW_DATA, $iv );

		return ( false === $plain ) ? false : $plain;
	}
}
