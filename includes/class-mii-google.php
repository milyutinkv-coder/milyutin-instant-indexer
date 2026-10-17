<?php
/**
 * Модуль 2. Google Indexing API.
 *
 * Авторизация — через собственный сервисный аккаунт Google Cloud (JSON-ключ).
 * JSON шифруется (AES-256-CBC + HMAC, см. MII_Crypto) и хранится в отдельной
 * опции с autoload = false, то есть в открытом виде в базе и в админке его нет.
 *
 * Эндпоинты:
 *  POST https://indexing.googleapis.com/v3/urlNotifications:publish   — отправка URL
 *  GET  https://indexing.googleapis.com/v3/urlNotifications/metadata  — статус URL (квота не тратится)
 *
 * Лимит: 200 публикаций в сутки на проект. При исчерпании URL ставится в очередь
 * и отправляется позже через WP-Cron (когда квота обнулится).
 *
 * @package Milyutin_Instant_Indexer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс MII_Google.
 */
class MII_Google {

	/**
	 * Точка получения access_token.
	 */
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/**
	 * Базовый URL Indexing API.
	 */
	const API_BASE = 'https://indexing.googleapis.com/v3';

	/**
	 * Область доступа (scope).
	 */
	const SCOPE = 'https://www.googleapis.com/auth/indexing';

	/**
	 * Кэш access_token (транзиент).
	 */
	const TOKEN_TRANSIENT = 'mii_google_token';

	/**
	 * Опция с зашифрованным JSON сервисного аккаунта.
	 */
	const CREDENTIALS_OPTION = 'mii_google_credentials';

	/**
	 * Опция с неконфиденциальными данными аккаунта (email, project_id).
	 */
	const META_OPTION = 'mii_google_meta';

	/**
	 * Опция со счётчиком дневной квоты.
	 */
	const QUOTA_OPTION = 'mii_google_quota';

	/**
	 * Очередь URL, не отправленных из-за лимита.
	 */
	const QUEUE_OPTION = 'mii_google_queue';

	/**
	 * Официальный дневной лимит Google по умолчанию.
	 */
	const DEFAULT_DAILY_LIMIT = 200;

	/**
	 * Экземпляр класса.
	 *
	 * @var MII_Google|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return MII_Google
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор (хуки не нужны — класс вызывается напрямую).
	 */
	private function __construct() {}

	// ─────────────────────────────────────────────────────────────────────────
	// Хранилище сервисного аккаунта
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Есть ли сохранённые и расшифровываемые учётные данные.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return ! is_wp_error( $this->get_credentials() );
	}

	/**
	 * Получить данные сервисного аккаунта (расшифрованные).
	 *
	 * @return array|WP_Error
	 */
	public function get_credentials() {
		$stored = (string) get_option( self::CREDENTIALS_OPTION, '' );

		if ( '' === $stored ) {
			return new WP_Error( 'mii_google_no_credentials', 'JSON-ключ сервисного аккаунта не загружен.' );
		}

		if ( ! MII_Crypto::is_available() ) {
			return new WP_Error(
				'mii_google_no_openssl',
				'На сервере недоступно расшифрование (нет OpenSSL). Обратитесь к хостингу.'
			);
		}

		$json = MII_Crypto::decrypt( $stored );

		if ( false === $json ) {
			return new WP_Error(
				'mii_google_decrypt_failed',
				'Не удалось расшифровать сохранённый JSON (вероятно, сменились соли в wp-config.php). Загрузите ключ заново.'
			);
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			return new WP_Error( 'mii_google_bad_credentials', 'JSON сервисного аккаунта повреждён или неполон.' );
		}

		return $data;
	}

	/**
	 * Сохранить JSON сервисного аккаунта (шифруется перед записью в БД).
	 *
	 * @param string $json_string Содержимое JSON-файла.
	 * @return true|WP_Error
	 */
	public function save_credentials( $json_string ) {
		$json_string = trim( (string) $json_string );

		if ( '' === $json_string ) {
			return new WP_Error( 'mii_google_empty_json', 'Пустой JSON.' );
		}

		$data = json_decode( $json_string, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'mii_google_invalid_json', 'Это не валидный JSON. Скопируйте содержимое файла целиком.' );
		}

		// Обязательные поля сервисного аккаунта.
		foreach ( array( 'client_email', 'private_key', 'type' ) as $field ) {
			if ( empty( $data[ $field ] ) ) {
				return new WP_Error( 'mii_google_missing_field', 'В JSON нет обязательного поля: ' . $field );
			}
		}

		if ( 'service_account' !== $data['type'] ) {
			return new WP_Error( 'mii_google_wrong_type', 'Это не ключ сервисного аккаунта (type должен быть service_account).' );
		}

		if ( false === strpos( (string) $data['private_key'], 'PRIVATE KEY' ) ) {
			return new WP_Error( 'mii_google_bad_key', 'Поле private_key не похоже на приватный ключ.' );
		}

		if ( ! MII_Crypto::is_available() ) {
			return new WP_Error( 'mii_google_no_openssl', 'На сервере нет OpenSSL — безопасно сохранить ключ невозможно.' );
		}

		// Приводим к «чистому» виду и шифруем.
		$clean = array(
			'type'                        => 'service_account',
			'project_id'                  => isset( $data['project_id'] ) ? sanitize_text_field( $data['project_id'] ) : '',
			'private_key_id'              => isset( $data['private_key_id'] ) ? sanitize_text_field( $data['private_key_id'] ) : '',
			'private_key'                 => (string) $data['private_key'],
			'client_email'                => sanitize_email( $data['client_email'] ),
			'client_id'                   => isset( $data['client_id'] ) ? sanitize_text_field( $data['client_id'] ) : '',
			'auth_uri'                    => isset( $data['auth_uri'] ) ? esc_url_raw( $data['auth_uri'] ) : 'https://accounts.google.com/o/oauth2/auth',
			'token_uri'                   => isset( $data['token_uri'] ) ? esc_url_raw( $data['token_uri'] ) : self::TOKEN_URL,
			'auth_provider_x509_cert_url' => isset( $data['auth_provider_x509_cert_url'] ) ? esc_url_raw( $data['auth_provider_x509_cert_url'] ) : '',
			'client_x509_cert_url'        => isset( $data['client_x509_cert_url'] ) ? esc_url_raw( $data['client_x509_cert_url'] ) : '',
		);

		$encrypted = MII_Crypto::encrypt( wp_json_encode( $clean ) );

		if ( '' === $encrypted ) {
			return new WP_Error( 'mii_google_encrypt_failed', 'Не удалось зашифровать JSON.' );
		}

		update_option( self::CREDENTIALS_OPTION, $encrypted, false );

		// В мета-опции храним только неконфиденциальные данные для интерфейса.
		update_option(
			self::META_OPTION,
			array(
				'client_email' => $clean['client_email'],
				'project_id'   => $clean['project_id'],
				'saved_at'     => current_time( 'mysql' ),
			),
			false
		);

		// Старый токен больше не нужен.
		delete_transient( self::TOKEN_TRANSIENT );

		MII_Logger::log( 'success', 'google', 'JSON сервисного аккаунта сохранён и зашифрован.' );

		return true;
	}

	/**
	 * Удалить сохранённые учётные данные.
	 *
	 * @return void
	 */
	public function delete_credentials() {
		delete_option( self::CREDENTIALS_OPTION );
		delete_option( self::META_OPTION );
		delete_transient( self::TOKEN_TRANSIENT );
		MII_Logger::log( 'info', 'google', 'JSON сервисного аккаунта удалён из настроек.' );
	}

	/**
	 * Метаданные аккаунта для вывода в админке (без секретов).
	 *
	 * @return array
	 */
	public function get_meta() {
		$meta = get_option( self::META_OPTION, array() );

		return wp_parse_args(
			is_array( $meta ) ? $meta : array(),
			array(
				'client_email' => '',
				'project_id'   => '',
				'saved_at'     => '',
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Авторизация (JWT → access_token)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Получить access_token (из кэша или новый).
	 *
	 * @param bool $force Игнорировать кэш.
	 * @return string|WP_Error
	 */
	public function get_access_token( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_TRANSIENT );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$credentials = $this->get_credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$jwt = $this->build_jwt( $credentials );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$response = wp_remote_post(
			! empty( $credentials['token_uri'] ) ? $credentials['token_uri'] : self::TOKEN_URL,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			MII_Logger::log( 'error', 'google', 'Не удалось получить токен: ' . $response->get_error_message() );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$message = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : 'неизвестная ошибка' );
			MII_Logger::log(
				'error',
				'google',
				'Google OAuth вернул ошибку: ' . $message,
				array( 'code' => $code )
			);

			return new WP_Error( 'mii_google_token_error', 'Ошибка авторизации Google: ' . $message );
		}

		$expires = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], max( 60, $expires - 120 ) );

		return $body['access_token'];
	}

	/**
	 * Собрать и подписать JWT (RS256).
	 *
	 * @param array $credentials Данные сервисного аккаунта.
	 * @return string|WP_Error
	 */
	private function build_jwt( $credentials ) {
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'mii_google_no_openssl', 'На сервере нет функции openssl_sign — подпись JWT невозможна.' );
		}

		$now    = time();
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		$claims = array(
			'iss'   => $credentials['client_email'],
			'scope' => self::SCOPE,
			'aud'   => ! empty( $credentials['token_uri'] ) ? $credentials['token_uri'] : self::TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);

		$signing_input = self::base64url_encode( wp_json_encode( $header ) ) . '.' . self::base64url_encode( wp_json_encode( $claims ) );

		$signature = '';
		$ok        = openssl_sign( $signing_input, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256 );

		if ( ! $ok ) {
			return new WP_Error( 'mii_google_sign_failed', 'Не удалось подписать JWT приватным ключом сервисного аккаунта.' );
		}

		return $signing_input . '.' . self::base64url_encode( $signature );
	}

	/**
	 * Base64url без padding.
	 *
	 * @param string $data Данные.
	 * @return string
	 */
	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Отправка URL
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Отправить URL в Google Indexing API.
	 *
	 * @param string $url        URL страницы.
	 * @param string $type       URL_UPDATED (обновление) или URL_DELETED (удаление).
	 * @param bool   $from_queue true — вызов из обработчика очереди (не ставим в очередь повторно).
	 * @return array|WP_Error
	 */
	public function publish( $url, $type = 'URL_UPDATED', $from_queue = false ) {
		$url  = esc_url_raw( $url );
		$type = in_array( $type, array( 'URL_UPDATED', 'URL_DELETED' ), true ) ? $type : 'URL_UPDATED';

		if ( '' === $url ) {
			return new WP_Error( 'mii_empty_url', 'Пустой URL.' );
		}

		// 1. Проверка дневного лимита.
		if ( $this->is_quota_exceeded() ) {
			MII_Logger::log(
				'warning',
				'google',
				'Дневной лимит Google (' . $this->daily_limit() . ' запросов) исчерпан — URL поставлен в очередь.',
				array( 'url' => $url )
			);

			if ( ! $from_queue ) {
				$this->add_to_queue( $url );
			}

			return new WP_Error( 'mii_google_quota_exceeded', 'Дневной лимит Google исчерпан. URL добавлен в очередь на завтра.' );
		}

		// 2. Токен.
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// 3. Запрос на публикацию.
		$response = wp_remote_post(
			self::API_BASE . '/urlNotifications:publish',
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode(
					array(
						'url'  => $url,
						'type' => $type,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			MII_Logger::log(
				'error',
				'google',
				'Ошибка соединения с Google: ' . $response->get_error_message(),
				array( 'url' => $url )
			);
			return $response;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$body    = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$message = $this->extract_error( $body );

		// 4. Разбор ответа.
		if ( 200 === $code ) {
			$this->increment_quota();

			MII_Logger::log(
				'success',
				'google',
				'Google Indexing API: URL отправлен (URL_UPDATED), использовано за сутки: ' . $this->get_quota()['count'] . '/' . $this->daily_limit() . '.',
				array(
					'url'  => $url,
					'code' => $code,
				)
			);

			return array(
				'code'    => $code,
				'status'  => 'success',
				'message' => 'Google принял URL (URL_UPDATED).',
				'quota'   => $this->get_quota(),
			);
		}

		// 429 — серверная квота Google исчерпана.
		if ( 429 === $code ) {
			$this->force_quota_exceeded();
			if ( ! $from_queue ) {
				$this->add_to_queue( $url );
			}
			MII_Logger::log( 'warning', 'google', 'Google: 429 RESOURCE_EXHAUSTED — квота исчерпана, URL в очереди.', array( 'url' => $url, 'code' => $code ) );

			return new WP_Error( 'mii_google_quota_exceeded', 'Google сообщил об исчерпании квоты (429). URL добавлен в очередь.' );
		}

		// 401 — токен просрочен/неверен: сбрасываем кэш, чтобы следующий вызов получил новый.
		if ( 401 === $code ) {
			delete_transient( self::TOKEN_TRANSIENT );
		}

		$hint = '';
		if ( 403 === $code ) {
			$hint = ' Проверьте, что email сервисного аккаунта добавлен в Google Search Console с правами «Владелец», а Web Search Indexing API включён в проекте.';
		}

		MII_Logger::log(
			'error',
			'google',
			'Google вернул ошибку: HTTP ' . $code . ( $message ? ' — ' . $message : '' ),
			array(
				'url'  => $url,
				'code' => $code,
			)
		);

		return new WP_Error( 'mii_google_api_error', 'Google вернул HTTP ' . $code . ( $message ? ': ' . $message : '' ) . $hint );
	}

	/**
	 * Получить метаданные URL (последняя отправка/уведомление). Квота публикаций не расходуется.
	 *
	 * @param string $url URL.
	 * @return array|WP_Error
	 */
	public function get_metadata( $url ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get(
			add_query_arg( 'url', rawurlencode( $url ), self::API_BASE . '/urlNotifications/metadata' ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = $this->extract_error( $body );
			return new WP_Error( 'mii_google_metadata_error', 'HTTP ' . $code . ( $message ? ': ' . $message : '' ) );
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Проверка подключения: токен + метаданные главной страницы.
	 *
	 * @param string $url URL для проверки (по умолчанию главная сайта).
	 * @return array|WP_Error
	 */
	public function check_connection( $url = '' ) {
		$url = $url ? esc_url_raw( $url ) : home_url( '/' );

		$token = $this->get_access_token( true );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$meta = $this->get_metadata( $url );

		$result = array(
			'token_ok'  => true,
			'url'       => $url,
			'meta_ok'   => ! is_wp_error( $meta ),
			'meta'      => is_wp_error( $meta ) ? $meta->get_error_message() : $meta,
			'quota'     => $this->get_quota(),
			'limit'     => $this->daily_limit(),
			'remaining' => $this->remaining_quota(),
		);

		MII_Logger::log(
			$result['meta_ok'] ? 'success' : 'warning',
			'google',
			'Проверка подключения: токен получен, метаданные ' . ( $result['meta_ok'] ? 'доступны' : 'недоступны' ) . '.',
			array( 'url' => $url )
		);

		return $result;
	}

	/**
	 * Достать текст ошибки из ответа Google.
	 *
	 * @param mixed $body Разобранный JSON.
	 * @return string
	 */
	private function extract_error( $body ) {
		if ( ! is_array( $body ) ) {
			return '';
		}
		if ( isset( $body['error']['message'] ) ) {
			return MII_Logger::mask( (string) $body['error']['message'] );
		}
		if ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
			return MII_Logger::mask( $body['error'] );
		}
		return '';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Дневная квота и очередь
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Дневной лимит из настроек (не больше официальных 200).
	 *
	 * @return int
	 */
	public function daily_limit() {
		$limit = (int) MII_Settings::get( 'google_daily_limit', self::DEFAULT_DAILY_LIMIT );
		$limit = max( 1, min( self::DEFAULT_DAILY_LIMIT, $limit ) );

		return $limit;
	}

	/**
	 * Текущее состояние счётчика.
	 *
	 * @return array{date:string,count:int}
	 */
	public function get_quota() {
		$quota = get_option( self::QUOTA_OPTION, array() );
		$today = current_time( 'Ymd' );

		if ( ! is_array( $quota ) || empty( $quota['date'] ) || $quota['date'] !== $today ) {
			$quota = array(
				'date'  => $today,
				'count' => 0,
			);
			update_option( self::QUOTA_OPTION, $quota, false );
		}

		return array(
			'date'  => $quota['date'],
			'count' => (int) $quota['count'],
		);
	}

	/**
	 * Сколько запросов ещё можно сделать сегодня.
	 *
	 * @return int
	 */
	public function remaining_quota() {
		return max( 0, $this->daily_limit() - $this->get_quota()['count'] );
	}

	/**
	 * Лимит исчерпан?
	 *
	 * @return bool
	 */
	public function is_quota_exceeded() {
		if ( get_option( 'mii_google_quota_blocked', '' ) === current_time( 'Ymd' ) ) {
			return true;
		}
		return $this->get_quota()['count'] >= $this->daily_limit();
	}

	/**
	 * Увеличить счётчик на 1.
	 *
	 * @return void
	 */
	private function increment_quota() {
		$quota = $this->get_quota();
		$quota['count']++;
		update_option( self::QUOTA_OPTION, $quota, false );
	}

	/**
	 * Пометить квоту исчерпанной по ответу Google (429).
	 *
	 * @return void
	 */
	private function force_quota_exceeded() {
		update_option( 'mii_google_quota_blocked', current_time( 'Ymd' ), false );
	}

	/**
	 * Сбросить счётчик, если наступил новый день (вызывается из WP-Cron).
	 *
	 * @return void
	 */
	public function reset_quota_if_new_day() {
		$quota = get_option( self::QUOTA_OPTION, array() );
		$today = current_time( 'Ymd' );

		if ( ! is_array( $quota ) || empty( $quota['date'] ) || $quota['date'] !== $today ) {
			update_option(
				self::QUOTA_OPTION,
				array(
					'date'  => $today,
					'count' => 0,
				),
				false
			);
			delete_option( 'mii_google_quota_blocked' );
		}
	}

	/**
	 * Добавить URL в очередь на отправку после обнуления квоты.
	 *
	 * @param string $url URL.
	 * @return void
	 */
	public function add_to_queue( $url ) {
		$url   = esc_url_raw( $url );
		$queue = (array) get_option( self::QUEUE_OPTION, array() );

		if ( in_array( $url, $queue, true ) ) {
			return;
		}

		// Не раздуваем очередь бесконечно.
		if ( count( $queue ) >= 500 ) {
			array_shift( $queue );
		}

		$queue[] = $url;
		update_option( self::QUEUE_OPTION, array_values( array_unique( $queue ) ), false );
	}

	/**
	 * Размер очереди.
	 *
	 * @return int
	 */
	public function queue_size() {
		return count( (array) get_option( self::QUEUE_OPTION, array() ) );
	}
}
