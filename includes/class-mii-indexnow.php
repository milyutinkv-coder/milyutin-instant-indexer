<?php
/**
 * Модуль 1. IndexNow — мгновенное уведомление Яндекса (и Bing, Naver, Seznam).
 *
 * Что делает класс:
 *  - генерирует ключ (8–128 символов: латиница + цифры + дефис);
 *  - отдаёт виртуальный файл /{ключ}.txt через add_rewrite_rule (без записи на диск);
 *  - умеет (опционально) создать физический файл /{ключ}.txt в корне сайта;
 *  - отправляет одиночные и пакетные (до 10 000 URL за запрос) уведомления;
 *  - разбирает коды ответа: 200 — успех, 202 — принято, ключ ещё проверяется (повтор);
 *  - повторяет отправку через WP-Cron при 202 / 429 / 5xx.
 *
 * Документация протокола: https://www.indexnow.org/documentation
 *
 * @package Milyutin_Instant_Indexer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс MII_IndexNow.
 */
class MII_IndexNow {

	/**
	 * Максимум URL в одном запросе (ограничение протокола IndexNow).
	 */
	const MAX_URLS_PER_REQUEST = 10000;

	/**
	 * Максимум попыток повторной отправки.
	 */
	const MAX_RETRIES = 3;

	/**
	 * Поддерживаемые точки приёма IndexNow.
	 *
	 * @return array<string,string>
	 */
	public static function endpoints() {
		return array(
			'yandex'  => 'https://yandex.com/indexnow',
			'indexnow' => 'https://api.indexnow.org/indexnow',
			'bing'    => 'https://www.bing.com/indexnow',
			'naver'   => 'https://searchadvisor.naver.com/indexnow',
			'seznam'  => 'https://search.seznam.cz/indexnow',
		);
	}

	/**
	 * Экземпляр класса.
	 *
	 * @var MII_IndexNow|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return MII_IndexNow
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор: регистрируем хуки виртуального файла ключа.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'serve_key_file' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Ключ
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Сгенерировать новый ключ IndexNow.
	 *
	 * wp_generate_password с $special_char = false даёт только [a-zA-Z0-9] —
	 * именно то, что требует протокол.
	 *
	 * @return string
	 */
	public static function generate_key() {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * Текущий ключ из настроек.
	 *
	 * @return string
	 */
	public static function get_key() {
		return (string) MII_Settings::get( 'indexnow_key', '' );
	}

	/**
	 * Ключ в замаскированном виде (для логов и вывода на экран).
	 *
	 * @return string
	 */
	public static function masked_key() {
		$key = self::get_key();
		if ( '' === $key ) {
			return '(не задан)';
		}
		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '*', strlen( $key ) );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', 6 ) . substr( $key, -4 );
	}

	/**
	 * Валиден ли ключ по требованиям протокола (8–128 символов, [A-Za-z0-9-]).
	 *
	 * @param string $key Ключ.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return (bool) preg_match( '/^[A-Za-z0-9\-]{8,128}$/', (string) $key );
	}

	/**
	 * Публичный URL файла ключа.
	 *
	 * @return string
	 */
	public static function key_file_url() {
		$key = self::get_key();
		if ( '' === $key ) {
			return '';
		}
		return home_url( '/' . $key . '.txt' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Виртуальный файл /{ключ}.txt
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Правило ЧПУ: любой корневой *.txt длиной 8+ символов отдаём нашему обработчику.
	 *
	 * robots.txt (6 симв.), ads.txt, security.txt и пр. НЕ перехватываются,
	 * так как в них меньше 8 символов до расширения.
	 *
	 * @return void
	 */
	public function add_rewrite_rule() {
		add_rewrite_rule( '^([A-Za-z0-9\-]{8,128})\.txt$', 'index.php?mii_indexnow_key=$matches[1]', 'top' );
	}

	/**
	 * Разрешить наш query var.
	 *
	 * @param string[] $vars Список переменных.
	 * @return string[]
	 */
	public function add_query_var( $vars ) {
		$vars[] = 'mii_indexnow_key';
		return $vars;
	}

	/**
	 * Отдать содержимое файла ключа, если запрошенный ключ совпадает с сохранённым.
	 *
	 * @return void
	 */
	public function serve_key_file() {
		$requested = get_query_var( 'mii_indexnow_key' );

		if ( empty( $requested ) ) {
			return;
		}

		$requested = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $requested );
		$key       = self::get_key();

		if ( '' !== $key && '' !== $requested && hash_equals( $key, $requested ) ) {
			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
			echo esc_html( $key );
			exit;
		}

		// Ключ не совпал — отдаём честный 404, чтобы не подменять чужие .txt файлы.
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not found';
		exit;
	}

	/**
	 * Создать физический файл /{ключ}.txt в корне сайта.
	 *
	 * Нужен как резервный вариант, если на хостинге не работают rewrite rules.
	 *
	 * @param string $key Ключ (по умолчанию — ключ из настроек). Параметр нужен,
	 *                    когда настройки ещё не сохранены (например, внутри sanitize).
	 * @return true|WP_Error
	 */
	public static function create_physical_key_file( $key = '' ) {
		if ( '' === $key ) {
			$key = self::get_key();
		}

		// Ключ приходит из формы — оставляем только разрешённые символы.
		$key = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $key );

		if ( '' === $key ) {
			return new WP_Error( 'mii_no_key', 'Сначала сгенерируйте ключ IndexNow.' );
		}

		$path = trailingslashit( ABSPATH ) . $key . '.txt';

		// Уже существует и содержит тот же ключ — ничего не делаем.
		if ( file_exists( $path ) && trim( (string) file_get_contents( $path ) ) === $key ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return true;
		}

		if ( ! wp_is_writable( ABSPATH ) ) {
			return new WP_Error(
				'mii_root_not_writable',
				'Корневая папка сайта недоступна для записи. Используйте виртуальный файл (rewrite rules) — он работает автоматически.'
			);
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = @file_put_contents( $path, $key );

		if ( false === $written ) {
			return new WP_Error( 'mii_write_failed', 'Не удалось записать файл ключа: ' . $path );
		}

		update_option( 'mii_physical_key_file', $key, false );
		MII_Logger::log( 'success', 'indexnow', 'Создан физический файл ключа: /' . $key . '.txt' );

		return true;
	}

	/**
	 * Удалить физический файл ключа (при деактивации или смене ключа).
	 *
	 * @param string $key Ключ (по умолчанию — из опции mii_physical_key_file,
	 *                    затем — ключ из настроек).
	 * @return void
	 */
	public static function delete_physical_key_file( $key = '' ) {
		if ( '' === $key ) {
			$key = (string) get_option( 'mii_physical_key_file', '' );
		}

		if ( '' === $key ) {
			$key = self::get_key();
		}

		if ( '' === $key ) {
			return;
		}

		// Проверяем, что имя файла — это ровно наш ключ (защита от path traversal).
		if ( ! self::is_valid_key( $key ) ) {
			return;
		}

		$path = trailingslashit( ABSPATH ) . $key . '.txt';

		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $path );
		}

		delete_option( 'mii_physical_key_file' );
	}

	/**
	 * Есть ли физический файл ключа в корне сайта.
	 *
	 * @param string $key Ключ (по умолчанию — из настроек).
	 * @return bool
	 */
	public static function has_physical_key_file( $key = '' ) {
		if ( '' === $key ) {
			$key = self::get_key();
		}

		$key = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $key );

		if ( '' === $key ) {
			return false;
		}
		return file_exists( trailingslashit( ABSPATH ) . $key . '.txt' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Отправка
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Точка приёма из настроек (с валидацией по белому списку).
	 *
	 * @return string
	 */
	public static function endpoint() {
		$key       = (string) MII_Settings::get( 'indexnow_endpoint', 'yandex' );
		$endpoints = self::endpoints();

		if ( isset( $endpoints[ $key ] ) ) {
			return $endpoints[ $key ];
		}

		return $endpoints['yandex'];
	}

	/**
	 * Отправить один URL (POST с параметрами в query string).
	 *
	 * @param string $url     URL страницы.
	 * @param string $context Источник вызова для лога.
	 * @return array|WP_Error
	 */
	public function submit_url( $url, $context = 'manual' ) {
		$url = esc_url_raw( $url );
		$key = self::get_key();

		if ( '' === $key ) {
			MII_Logger::log( 'error', 'indexnow', 'Отправка отменена: не задан ключ IndexNow.', array( 'url' => $url ) );
			return new WP_Error( 'mii_no_key', 'Не задан ключ IndexNow.' );
		}

		$args = array(
			'url'         => $url,
			'key'         => $key,
			'keyLocation' => self::key_file_url(),
		);

		$request_url = add_query_arg( array_map( 'rawurlencode', $args ), self::endpoint() );

		return $this->request( $request_url, null, array( $url ), $context );
	}

	/**
	 * Отправить пачку URL (POST с JSON-телом, до 10 000 URL за запрос).
	 *
	 * @param string[] $urls    Массив URL.
	 * @param string   $context Источник вызова для лога.
	 * @return array|WP_Error Итоговый результат (по последнему чанку — сводка по всем).
	 */
	public function submit_urls( $urls, $context = 'manual' ) {
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) ) );

		if ( empty( $urls ) ) {
			return new WP_Error( 'mii_empty_urls', 'Не передан ни один URL.' );
		}

		$key = self::get_key();
		if ( '' === $key ) {
			MII_Logger::log( 'error', 'indexnow', 'Отправка отменена: не задан ключ IndexNow.' );
			return new WP_Error( 'mii_no_key', 'Не задан ключ IndexNow.' );
		}

		$host         = wp_parse_url( home_url(), PHP_URL_HOST );
		$key_location = self::key_file_url();
		$chunks       = array_chunk( $urls, self::MAX_URLS_PER_REQUEST );
		$summary      = null;

		foreach ( $chunks as $index => $chunk ) {
			$body = array(
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => $key_location,
				'urlList'     => array_values( $chunk ),
			);

			$summary = $this->request( self::endpoint(), $body, $chunk, $context );

			// Если транспорт упал или сервер ответил ошибкой — дальше слать нет смысла.
			if ( is_wp_error( $summary ) ) {
				return $summary;
			}
			if ( ! empty( $summary['retry'] ) ) {
				// Планируем повтор для этого чанка.
				$this->schedule_retry( $chunk, 1, $context, (int) $summary['retry_delay'] );
			}
			if ( $index < count( $chunks ) - 1 ) {
				// Небольшая пауза между чанками при массовой отправке.
				usleep( 300000 ); // 0.3 сек.
			}
		}

		return $summary;
	}

	/**
	 * Выполнить HTTP-запрос к IndexNow и разобрать ответ.
	 *
	 * @param string     $request_url URL точки приёма.
	 * @param array|null $body        JSON-тело или null (тогда параметры уже в query).
	 * @param string[]   $urls        URL, которые отправляем (для лога и повторов).
	 * @param string     $context     Источник вызова.
	 * @return array|WP_Error
	 */
	private function request( $request_url, $body, $urls, $context ) {
		$args = array(
			'timeout'     => 20,
			'redirection' => 2,
			'user-agent'  => 'Milyutin-Instant-Indexer/' . MII_VERSION . '; ' . home_url(),
			'headers'     => array(
				'Accept' => 'application/json',
			),
		);

		if ( is_array( $body ) ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_post( $request_url, $args );

		if ( is_wp_error( $response ) ) {
			MII_Logger::log(
				'error',
				'indexnow',
				'Ошибка соединения: ' . $response->get_error_message(),
				array(
					'url'   => reset( $urls ),
					'extra' => 'URL в запросе: ' . count( $urls ),
				)
			);

			// Таймаут/сеть — повторяем позже.
			$this->schedule_retry( $urls, 1, $context, 15 * MINUTE_IN_SECONDS );

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$info = $this->interpret( $code, $raw );

		// Записываем в лог один раз на запрос (первый URL + количество).
		MII_Logger::log(
			$info['level'],
			'indexnow',
			$info['message'],
			array(
				'url'   => reset( $urls ),
				'code'  => $code,
				'extra' => count( $urls ) > 1 ? 'URL в запросе: ' . count( $urls ) : '',
			)
		);

		return array(
			'code'        => $code,
			'status'      => $info['status'],
			'message'     => $info['message'],
			'retry'       => $info['retry'],
			'retry_delay' => $info['retry_delay'],
			'count'       => count( $urls ),
		);
	}

	/**
	 * Расшифровка кодов ответа IndexNow.
	 *
	 * 200 — URL приняты; 202 — приняты, но ключ ещё проверяется (нужен повтор);
	 * 400 — неверный формат; 403 — ключ недействителен; 404 — файл ключа не найден;
	 * 422 — URL не принадлежат хосту или ключ не совпал; 429 — слишком много запросов.
	 *
	 * @param int    $code Код ответа.
	 * @param string $body Тело ответа.
	 * @return array{status:string,level:string,message:string,retry:bool,retry_delay:int}
	 */
	private function interpret( $code, $body ) {
		$body = trim( wp_strip_all_tags( $body ) );
		$tail = $body ? ' Ответ сервера: ' . $body : '';

		switch ( $code ) {
			case 200:
				return array(
					'status'      => 'success',
					'level'       => 'success',
					'message'     => 'IndexNow: 200 OK — URL успешно приняты.',
					'retry'       => false,
					'retry_delay' => 0,
				);

			case 202:
				// Ключ ещё не подтверждён (файл не найден на момент проверки) — ждём и повторяем.
				return array(
					'status'      => 'pending',
					'level'       => 'warning',
					'message'     => 'IndexNow: 202 Accepted — ключ ещё не подтверждён, отправка будет повторена.' . $tail,
					'retry'       => true,
					'retry_delay' => 2 * HOUR_IN_SECONDS,
				);

			case 400:
				return array(
					'status'      => 'error',
					'level'       => 'error',
					'message'     => 'IndexNow: 400 — неверный формат запроса (проверьте URL и ключ).' . $tail,
					'retry'       => false,
					'retry_delay' => 0,
				);

			case 403:
				return array(
					'status'      => 'error',
					'level'       => 'error',
					'message'     => 'IndexNow: 403 — ключ недействителен. Проверьте файл {ключ}.txt.' . $tail,
					'retry'       => false,
					'retry_delay' => 0,
				);

			case 404:
				return array(
					'status'      => 'error',
					'level'       => 'error',
					'message'     => 'IndexNow: 404 — файл ключа не найден по адресу keyLocation.' . $tail,
					'retry'       => false,
					'retry_delay' => 0,
				);

			case 422:
				return array(
					'status'      => 'error',
					'level'       => 'error',
					'message'     => 'IndexNow: 422 — URL не принадлежат указанному хосту или ключ не совпал.' . $tail,
					'retry'       => false,
					'retry_delay' => 0,
				);

			case 429:
				return array(
					'status'      => 'error',
					'level'       => 'warning',
					'message'     => 'IndexNow: 429 — слишком много запросов, повтор через час.' . $tail,
					'retry'       => true,
					'retry_delay' => HOUR_IN_SECONDS,
				);

			default:
				if ( $code >= 500 ) {
					return array(
						'status'      => 'error',
						'level'       => 'warning',
						'message'     => 'IndexNow: ' . $code . ' — ошибка на стороне поисковика, повтор позже.' . $tail,
						'retry'       => true,
						'retry_delay' => 15 * MINUTE_IN_SECONDS,
					);
				}

				return array(
					'status'      => 'error',
					'level'       => 'error',
					'message'     => 'IndexNow: неожиданный код ответа ' . $code . '.' . $tail,
					'retry'       => false,
					'retry_delay' => 0,
				);
		}
	}

	/**
	 * Запланировать повторную отправку через WP-Cron.
	 *
	 * @param string[] $urls    URL.
	 * @param int      $attempt Номер попытки.
	 * @param string   $context Источник.
	 * @param int      $delay   Задержка в секундах.
	 * @return void
	 */
	private function schedule_retry( $urls, $attempt, $context, $delay = 0 ) {
		if ( $attempt > self::MAX_RETRIES ) {
			MII_Logger::log( 'error', 'indexnow', 'Исчерпаны попытки повторной отправки (' . self::MAX_RETRIES . ').' );
			return;
		}

		$delay = $delay > 0 ? (int) $delay : 15 * MINUTE_IN_SECONDS;

		wp_schedule_single_event(
			time() + $delay,
			'mii_indexnow_retry',
			array( array_values( (array) $urls ), (int) $attempt, (string) $context )
		);
	}

	/**
	 * Обработчик WP-Cron: повторная отправка.
	 *
	 * @param string[] $urls    URL.
	 * @param int      $attempt Номер попытки.
	 * @param string   $context Источник.
	 * @return void
	 */
	public static function process_retry( $urls, $attempt = 1, $context = 'retry' ) {
		$urls = array_values( array_filter( (array) $urls ) );

		if ( empty( $urls ) || ! MII_Settings::get( 'indexnow_enabled' ) ) {
			return;
		}

		$instance = self::instance();
		$key      = self::get_key();
		$body     = array(
			'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
			'key'         => $key,
			'keyLocation' => self::key_file_url(),
			'urlList'     => $urls,
		);

		MII_Logger::log(
			'info',
			'indexnow',
			sprintf( 'Повторная отправка (попытка %d), URL: %d.', (int) $attempt, count( $urls ) ),
			array( 'url' => reset( $urls ) )
		);

		$result = $instance->request( self::endpoint(), $body, $urls, 'retry' );

		if ( ! is_wp_error( $result ) && ! empty( $result['retry'] ) ) {
			$instance->schedule_retry( $urls, (int) $attempt + 1, $context, (int) $result['retry_delay'] );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Проверка ключа
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Проверка ключа: локальная доступность файла + тестовый запрос в IndexNow.
	 *
	 * @return array Результат с ключами local_ok, local_message, remote_ok, remote_message, code.
	 */
	public function check_key() {
		$key = self::get_key();

		$result = array(
			'key'            => $key,
			'key_file_url'   => self::key_file_url(),
			'local_ok'       => false,
			'local_message'  => '',
			'remote_ok'      => false,
			'remote_message' => '',
			'code'           => 0,
		);

		if ( '' === $key || ! self::is_valid_key( $key ) ) {
			$result['local_message']  = 'Ключ не задан или не соответствует требованиям (8–128 символов, латиница/цифры/дефис).';
			$result['remote_message'] = 'Тест не выполнялся.';
			return $result;
		}

		// 1. Локальная проверка: доступен ли https://site.ru/{ключ}.txt.
		$response = wp_remote_get(
			self::key_file_url(),
			array(
				'timeout'    => 15,
				'user-agent' => 'Milyutin-Instant-Indexer/' . MII_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['local_message'] = 'Не удалось открыть файл ключа: ' . $response->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = trim( (string) wp_remote_retrieve_body( $response ) );

			if ( 200 === $code && $body === $key ) {
				$result['local_ok']      = true;
				$result['local_message'] = 'Файл ключа доступен и содержит верное значение.';
			} elseif ( 200 === $code ) {
				$result['local_message'] = 'Файл открывается, но содержимое не совпадает с ключом. Возможно, мешает кэш страницы.';
			} else {
				$result['local_message'] = 'Файл ключа недоступен (HTTP ' . $code . '). Включите «красивые ссылки» или создайте физический файл.';
			}
		}

		// 2. Тестовый запрос в IndexNow с главной страницей сайта.
		$test_url = home_url( '/' );
		$response = $this->submit_url( $test_url, 'check_key' );

		if ( is_wp_error( $response ) ) {
			$result['remote_message'] = 'Ошибка соединения: ' . $response->get_error_message();
			return $result;
		}

		$result['code']           = (int) $response['code'];
		$result['remote_message'] = $response['message'];
		$result['remote_ok']      = ( 200 === (int) $response['code'] );

		return $result;
	}
}
