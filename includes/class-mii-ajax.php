<?php
/**
 * AJAX-обработчики админки: ручная отправка, проверки, логи, ключи.
 *
 * Все обработчики проходят две проверки:
 *  1) check_ajax_referer() — nonce формы (защита от CSRF);
 *  2) current_user_can( 'manage_options' ) — права администратора.
 *
 * @package Milyutin_Instant_Indexer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс MII_Ajax.
 */
class MII_Ajax {

	/**
	 * Имя nonce-действия (должно совпадать с admin.js).
	 */
	const NONCE_ACTION = 'mii_ajax';

	/**
	 * Экземпляр класса.
	 *
	 * @var MII_Ajax|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return MII_Ajax
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор: регистрируем все AJAX-хуки.
	 */
	private function __construct() {
		$actions = array(
			'generate_key',
			'create_key_file',
			'check_key',
			'save_google_json',
			'delete_google_json',
			'check_google',
			'send_single',
			'count_posts',
			'send_batch',
			'clear_logs',
			'get_logs',
			'get_status',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_mii_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Общие проверки
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Проверка nonce и прав. При провале завершает запрос с ошибкой.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Проверка безопасности не пройдена. Обновите страницу и попробуйте снова.' ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав.' ), 403 );
		}
	}

	/**
	 * Собрать человекочитаемый результат работы модулей.
	 *
	 * @param array $results Результаты MII_IndexNow / MII_Google.
	 * @return array{ok:bool,messages:string[]}
	 */
	private function describe_results( $results ) {
		$messages = array();
		$ok       = false;

		foreach ( $results as $module => $result ) {
			$label = ( 'indexnow' === $module ) ? 'IndexNow' : 'Google';

			if ( is_wp_error( $result ) ) {
				$messages[] = array(
					'ok'      => false,
					'module'  => $label,
					'message' => $result->get_error_message(),
				);
				continue;
			}

			if ( is_array( $result ) && ! empty( $result['message'] ) ) {
				$is_ok      = ! empty( $result['status'] ) && 'success' === $result['status'];
				$ok         = $ok || $is_ok;
				$messages[] = array(
					'ok'      => $is_ok,
					'module'  => $label,
					'message' => $result['message'],
				);
				continue;
			}

			$messages[] = array(
				'ok'      => true,
				'module'  => $label,
				'message' => 'Готово.',
			);
			$ok         = true;
		}

		return array(
			'ok'       => $ok,
			'messages' => $messages,
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// IndexNow
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Сгенерировать новый ключ IndexNow.
	 *
	 * @return void
	 */
	public function handle_generate_key() {
		$this->guard();

		$settings = MII_Settings::all();

		// Старый физический файл больше не нужен.
		MII_IndexNow::delete_physical_key_file();

		$new_key                  = MII_IndexNow::generate_key();
		$settings['indexnow_key'] = $new_key;
		MII_Settings::update( $settings );

		// Правило ЧПУ зависит от ключа — просим пересобрать правила.
		update_option( 'mii_flush_rewrite_needed', 1, false );
		MII_IndexNow::instance()->add_rewrite_rule();
		flush_rewrite_rules();

		// Если физический файл был включён — пересоздаём под новый ключ.
		$physical = false;
		if ( MII_Settings::get( 'indexnow_physical_file' ) ) {
			$created  = MII_IndexNow::create_physical_key_file();
			$physical = ! is_wp_error( $created );
		}

		MII_Logger::log( 'info', 'indexnow', 'Сгенерирован новый ключ IndexNow: ' . MII_IndexNow::masked_key() );

		wp_send_json_success(
			array(
				'key'          => $new_key,
				'key_file_url' => MII_IndexNow::key_file_url(),
				'physical'     => $physical,
				'message'      => 'Новый ключ создан. Проверьте доступность файла ключа.',
			)
		);
	}

	/**
	 * Создать физический файл ключа в корне сайта.
	 *
	 * @return void
	 */
	public function handle_create_key_file() {
		$this->guard();

		$result = MII_IndexNow::create_physical_key_file();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => 'Физический файл создан: /' . MII_IndexNow::get_key() . '.txt',
			)
		);
	}

	/**
	 * Проверить ключ IndexNow (локально и тестовым запросом).
	 *
	 * @return void
	 */
	public function handle_check_key() {
		$this->guard();

		$result = MII_IndexNow::instance()->check_key();

		wp_send_json_success(
			array(
				'local_ok'       => (bool) $result['local_ok'],
				'local_message'  => $result['local_message'],
				'remote_ok'      => (bool) $result['remote_ok'],
				'remote_message' => $result['remote_message'],
				'code'           => (int) $result['code'],
				'key_file_url'   => $result['key_file_url'],
				'physical'       => MII_IndexNow::has_physical_key_file(),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Google
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Сохранить JSON сервисного аккаунта (вставка текстом или загрузка файла).
	 *
	 * @return void
	 */
	public function handle_save_google_json() {
		$this->guard();

		$json = '';

		// Вариант 1: файл.
		if ( ! empty( $_FILES['mii_json_file']['tmp_name'] ) ) {
			$file = $_FILES['mii_json_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( ! empty( $file['error'] ) ) {
				wp_send_json_error( array( 'message' => 'Ошибка загрузки файла (код ' . (int) $file['error'] . ').' ) );
			}

			// Максимум 64 КБ — реальный JSON сервисного аккаунта меньше 4 КБ.
			if ( (int) $file['size'] > 65536 ) {
				wp_send_json_error( array( 'message' => 'Файл слишком большой. Ожидается JSON сервисного аккаунта Google (до 64 КБ).' ) );
			}

			if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
				wp_send_json_error( array( 'message' => 'Файл не был загружен корректно.' ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$json = (string) file_get_contents( $file['tmp_name'] );
		} elseif ( isset( $_POST['mii_json_text'] ) ) {
			// Вариант 2: текст из textarea. JSON не проходит sanitize_text_field,
			// поэтому снимаем только слэши и проверяем структуру через json_decode.
			$json = wp_unslash( (string) $_POST['mii_json_text'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( '' === trim( $json ) ) {
			wp_send_json_error( array( 'message' => 'Вставьте JSON или выберите файл.' ) );
		}

		$saved = MII_Google::instance()->save_credentials( $json );

		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ) );
		}

		// Сразу проверяем, что ключ работает: получаем токен.
		$token = MII_Google::instance()->get_access_token( true );

		$meta = MII_Google::instance()->get_meta();

		if ( is_wp_error( $token ) ) {
			wp_send_json_success(
				array(
					'active'  => false,
					'email'   => $meta['client_email'],
					'project' => $meta['project_id'],
					'message' => 'JSON сохранён и зашифрован, но авторизация не прошла: ' . $token->get_error_message(),
				)
			);
		}

		wp_send_json_success(
			array(
				'active'  => true,
				'email'   => $meta['client_email'],
				'project' => $meta['project_id'],
				'message' => 'JSON сохранён и зашифрован. Авторизация прошла успешно.',
			)
		);
	}

	/**
	 * Удалить сохранённый JSON сервисного аккаунта.
	 *
	 * @return void
	 */
	public function handle_delete_google_json() {
		$this->guard();

		MII_Google::instance()->delete_credentials();

		wp_send_json_success( array( 'message' => 'JSON сервисного аккаунта удалён.' ) );
	}

	/**
	 * Проверить подключение к Google Indexing API.
	 *
	 * @return void
	 */
	public function handle_check_google() {
		$this->guard();

		$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$result = MII_Google::instance()->check_connection( $url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'token_ok'  => (bool) $result['token_ok'],
				'meta_ok'   => (bool) $result['meta_ok'],
				'meta'      => is_array( $result['meta'] ) ? wp_json_encode( $result['meta'] ) : (string) $result['meta'],
				'quota'     => (int) $result['quota']['count'],
				'limit'     => (int) $result['limit'],
				'remaining' => (int) $result['remaining'],
				'email'     => MII_Google::instance()->get_meta()['client_email'],
				'message'   => $result['meta_ok']
					? 'Подключение работает: токен получен, доступ к данным Search Console есть.'
					: 'Токен получен, но метаданные недоступны: ' . ( is_array( $result['meta'] ) ? '' : $result['meta'] ) . ' Проверьте права сервисного аккаунта в Search Console.',
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Отправка
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Ручная отправка одного URL.
	 *
	 * @return void
	 */
	public function handle_send_single() {
		$this->guard();

		$url    = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'both';

		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => 'Укажите URL страницы.' ) );
		}

		// Проверяем, что URL принадлежит этому сайту (IndexNow иначе вернёт 422).
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );

		if ( $url_host && $site_host && strtolower( $url_host ) !== strtolower( $site_host ) ) {
			wp_send_json_error( array( 'message' => 'URL должен принадлежать сайту ' . $site_host . '.' ) );
		}

		$results = array();

		if ( in_array( $target, array( 'both', 'indexnow' ), true ) ) {
			$results['indexnow'] = MII_Settings::get( 'indexnow_enabled' )
				? MII_IndexNow::instance()->submit_urls( array( $url ), 'manual' )
				: new WP_Error( 'mii_disabled', 'Модуль IndexNow выключен в настройках.' );
		}

		if ( in_array( $target, array( 'both', 'google' ), true ) ) {
			$results['google'] = MII_Settings::get( 'google_enabled' )
				? MII_Google::instance()->publish( $url, 'URL_UPDATED' )
				: new WP_Error( 'mii_disabled', 'Модуль Google Indexing API выключен в настройках.' );
		}

		if ( empty( $results ) ) {
			wp_send_json_error( array( 'message' => 'Не выбран ни один сервис.' ) );
		}

		$described = $this->describe_results( $results );

		wp_send_json_success(
			array(
				'ok'       => $described['ok'],
				'url'      => $url,
				'messages' => $described['messages'],
			)
		);
	}

	/**
	 * Сколько всего записей доступно для массовой отправки.
	 *
	 * @return void
	 */
	public function handle_count_posts() {
		$this->guard();

		wp_send_json_success(
			array(
				'total' => MII_Plugin::count_indexable_posts(),
				'types' => MII_Plugin::allowed_post_types(),
			)
		);
	}

	/**
	 * Пакетная отправка: одна порция за запрос (прогресс-бар в админке).
	 *
	 * @return void
	 */
	public function handle_send_batch() {
		$this->guard();

		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$limit  = isset( $_POST['limit'] ) ? min( 25, max( 1, (int) $_POST['limit'] ) ) : 10;
		$target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : 'both';

		$posts = MII_Plugin::get_posts_batch( $offset, $limit );

		if ( empty( $posts ) ) {
			wp_send_json_success(
				array(
					'done'      => true,
					'offset'    => $offset,
					'processed' => 0,
					'urls'      => array(),
					'messages'  => array(),
				)
			);
		}

		$urls    = array();
		$skipped = 0;

		foreach ( $posts as $post ) {
			$url = get_permalink( $post->ID );

			if ( ! $url ) {
				++$skipped;
				continue;
			}

			$url = esc_url_raw( $url );

			if ( MII_Plugin::is_excluded( $url, $post->ID ) ) {
				++$skipped;
				continue;
			}

			$urls[ $post->ID ] = $url;
		}

		$results = array();

		// IndexNow — отправляем всю порцию одним запросом (быстро и экономно).
		if ( ! empty( $urls ) && in_array( $target, array( 'both', 'indexnow' ), true ) && MII_Settings::get( 'indexnow_enabled' ) ) {
			$results['indexnow'] = MII_IndexNow::instance()->submit_urls( array_values( $urls ), 'batch' );
		}

		// Google — по одному URL, чтобы контролировать дневную квоту.
		if ( in_array( $target, array( 'both', 'google' ), true ) && MII_Settings::get( 'google_enabled' ) ) {
			$google_ok  = 0;
			$google_err = '';

			foreach ( $urls as $url ) {
				$one = MII_Google::instance()->publish( $url, 'URL_UPDATED' );
				if ( is_wp_error( $one ) ) {
					$google_err = $one->get_error_message();
					break; // Скорее всего, квота или права — дальше слать нет смысла.
				}
				++$google_ok;
			}

			$results['google'] = $google_err
				? new WP_Error( 'mii_google_batch_error', sprintf( 'Отправлено: %d. Остановлено: %s', $google_ok, $google_err ) )
				: array(
					'status'  => 'success',
					'message' => sprintf( 'Google: отправлено URL — %d.', $google_ok ),
				);
		}

		$described = $this->describe_results( $results );

		wp_send_json_success(
			array(
				'done'      => count( $posts ) < $limit,
				'offset'    => $offset + count( $posts ),
				'processed' => count( $posts ),
				'skipped'   => $skipped,
				'urls'      => array_values( $urls ),
				'messages'  => $described['messages'],
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Логи и статус
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Очистить логи.
	 *
	 * @return void
	 */
	public function handle_clear_logs() {
		$this->guard();

		MII_Logger::clear();

		wp_send_json_success( array( 'message' => 'Логи очищены.' ) );
	}

	/**
	 * Получить последние записи лога.
	 *
	 * @return void
	 */
	public function handle_get_logs() {
		$this->guard();

		wp_send_json_success( array( 'entries' => MII_Logger::get_entries( 20 ) ) );
	}

	/**
	 * Текущий статус модулей (для обновления меток без перезагрузки).
	 *
	 * @return void
	 */
	public function handle_get_status() {
		$this->guard();

		$google = MII_Google::instance();
		$quota  = $google->get_quota();

		wp_send_json_success(
			array(
				'indexnow' => array(
					'enabled'  => (bool) MII_Settings::get( 'indexnow_enabled' ),
					'key_set'  => '' !== MII_IndexNow::get_key(),
					'key_valid' => MII_IndexNow::is_valid_key( MII_IndexNow::get_key() ),
					'key_url'  => MII_IndexNow::key_file_url(),
					'physical' => MII_IndexNow::has_physical_key_file(),
				),
				'google'   => array(
					'enabled'   => (bool) MII_Settings::get( 'google_enabled' ),
					'connected' => $google->has_credentials(),
					'email'     => $google->get_meta()['client_email'],
					'quota'     => (int) $quota['count'],
					'limit'     => $google->daily_limit(),
					'remaining' => $google->remaining_quota(),
					'queue'     => $google->queue_size(),
				),
			)
		);
	}
}
