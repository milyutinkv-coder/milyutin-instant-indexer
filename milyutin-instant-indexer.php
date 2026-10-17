<?php
/**
 * Plugin Name:       Milyutin Instant Indexer
 * Plugin URI:        https://milyutin-codes.ru/
 * Description:       Мгновенная отправка новых и обновлённых записей на индексацию в Яндекс (IndexNow) и Google (Google Indexing API). Работает бесплатно, без сторонних сервисов-посредников: только ваш ключ IndexNow и ваш собственный сервисный аккаунт Google.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Константин Милютин
 * Author URI:        https://milyutin-codes.ru
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       milyutin-instant-indexer
 * Domain Path:       /languages
 *
 * @package Milyutin_Instant_Indexer
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * БЫСТРАЯ КАРТА ФАЙЛОВ (что править под себя):
 *   milyutin-instant-indexer.php  — этот файл: точка входа, константы, авто-отправка при публикации/обновлении.
 *   includes/class-mii-settings.php — страница настроек в админке (вкладки, поля, инструкция).
 *   includes/class-mii-ajax.php     — все AJAX-обработчики (ручная отправка, тесты, логи).
 *   includes/class-mii-indexnow.php — модуль IndexNow (Яндекс, Bing, Naver, Seznam).
 *   includes/class-mii-google.php   — модуль Google Indexing API (JWT сервисного аккаунта).
 *   includes/class-mii-logger.php   — логирование в файл + кольцевой буфер для таблицы в админке.
 *   includes/class-mii-crypto.php   — шифрование JSON-ключа Google (AES-256-CBC + HMAC).
 *   assets/css/admin.css            — стили страницы настроек.
 *   assets/js/admin.js              — AJAX, прогресс-бар, кнопки «Проверить».
 *   uninstall.php                   — полная очистка при удалении плагина.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// Защита от прямого доступа к файлу.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Константы плагина ────────────────────────────────────────────────────────
define( 'MII_VERSION', '1.0.0' );
define( 'MII_FILE', __FILE__ );
define( 'MII_DIR', plugin_dir_path( __FILE__ ) );
define( 'MII_URL', plugin_dir_url( __FILE__ ) );
define( 'MII_BASENAME', plugin_basename( __FILE__ ) );
define( 'MII_SLUG', 'milyutin-instant-indexer' );

// ─── Подключение классов ──────────────────────────────────────────────────────
require_once MII_DIR . 'includes/class-mii-crypto.php';
require_once MII_DIR . 'includes/class-mii-logger.php';
require_once MII_DIR . 'includes/class-mii-settings.php';
require_once MII_DIR . 'includes/class-mii-indexnow.php';
require_once MII_DIR . 'includes/class-mii-google.php';
require_once MII_DIR . 'includes/class-mii-ajax.php';

/**
 * Главный класс плагина (синглтон).
 *
 * Отвечает за:
 *  - инициализацию модулей;
 *  - автоматическую отправку URL при публикации и обновлении записи;
 *  - WP-Cron задачи (повторные попытки IndexNow, слив очереди Google, обслуживание).
 */
final class MII_Plugin {

	/**
	 * Единственный экземпляр класса.
	 *
	 * @var MII_Plugin|null
	 */
	private static $instance = null;

	/**
	 * URL, уже отправленные в рамках текущего HTTP-запроса (защита от дублей).
	 *
	 * @var array<string,bool>
	 */
	private static $sent_in_request = array();

	/**
	 * Получить экземпляр (и создать его при первом вызове).
	 *
	 * @return MII_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор: подключаем хуки.
	 */
	private function __construct() {
		// Инициализация модулей.
		MII_Settings::instance();
		MII_IndexNow::instance();
		MII_Google::instance();
		MII_Ajax::instance();

		/*
		 * Основной хук авто-отправки.
		 * transition_post_status ловит и первую публикацию (draft → publish),
		 * и обновление уже опубликованной записи (publish → publish).
		 */
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );

		/*
		 * Дополнительный хук: некоторые темы/плагины сохраняют запись в обход
		 * transition_post_status (прямой $wpdb->update). Дубли отсекаются
		 * статическим массивом + транзиентной блокировкой.
		 */
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 3 );

		// WP-Cron задачи.
		add_action( 'mii_indexnow_retry', array( 'MII_IndexNow', 'process_retry' ), 10, 3 );
		add_action( 'mii_google_drain_queue', array( __CLASS__, 'drain_google_queue' ) );
		add_action( 'mii_daily_maintenance', array( __CLASS__, 'daily_maintenance' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Активация / деактивация
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Активация плагина: настройки по умолчанию, ключ IndexNow, rewrite rules, cron.
	 *
	 * @return void
	 */
	public static function activate() {
		$settings = MII_Settings::all();

		// При первой активации сразу генерируем ключ IndexNow — меньше ручной работы.
		if ( empty( $settings['indexnow_key'] ) ) {
			$settings['indexnow_key'] = MII_IndexNow::generate_key();
			MII_Settings::update( $settings );
			MII_IndexNow::create_physical_key_file();
		}

		// Регистрируем правило ЧПУ для виртуального файла ключа и сбрасываем кэш правил.
		MII_IndexNow::instance()->add_rewrite_rule();
		flush_rewrite_rules();

		// Планируем задачи обслуживания.
		if ( ! wp_next_scheduled( 'mii_daily_maintenance' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'mii_daily_maintenance' );
		}
		if ( ! wp_next_scheduled( 'mii_google_drain_queue' ) ) {
			wp_schedule_event( time() + 1800, 'hourly', 'mii_google_drain_queue' );
		}

		MII_Logger::log( 'info', 'system', 'Плагин активирован. Ключ IndexNow: ' . MII_IndexNow::masked_key() );
	}

	/**
	 * Деактивация: снимаем cron, чистим правила ЧПУ и (опционально) физический файл ключа.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'mii_daily_maintenance' );
		wp_clear_scheduled_hook( 'mii_google_drain_queue' );
		wp_clear_scheduled_hook( 'mii_indexnow_retry' );

		// Физический файл ключа — временный артефакт, при деактивации убираем.
		MII_IndexNow::delete_physical_key_file();

		flush_rewrite_rules();
		MII_Logger::log( 'info', 'system', 'Плагин деактивирован.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Автоматическая отправка
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Реакция на смену статуса записи.
	 *
	 * @param string  $new_status Новый статус.
	 * @param string  $old_status Предыдущий статус.
	 * @param WP_Post $post       Объект записи.
	 * @return void
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// Нас интересует только опубликованное содержимое.
		if ( 'publish' !== $new_status ) {
			return;
		}

		// Первая публикация или обновление уже опубликованного?
		$event = ( 'publish' === $old_status ) ? 'update' : 'publish';

		self::dispatch( $post, $event, 'transition_post_status' );
	}

	/**
	 * Резервный хук save_post (см. комментарий в конструкторе).
	 *
	 * @param int     $post_id ID записи.
	 * @param WP_Post $post    Объект записи.
	 * @param bool    $update  true — обновление существующей записи.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post, $update ) {
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		$event = $update ? 'update' : 'publish';
		self::dispatch( $post, $event, 'save_post' );
	}

	/**
	 * Проверки и отправка URL записи в оба сервиса.
	 *
	 * @param WP_Post $post   Запись.
	 * @param string  $event  'publish' (новая) или 'update' (обновление).
	 * @param string  $source Источник вызова (для логов).
	 * @return void
	 */
	private static function dispatch( $post, $event, $source = '' ) {
		// Служебные ситуации, когда отправлять нельзя.
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status || 'trash' === $post->post_status ) {
			return;
		}
		// Защищённые паролем записи индексировать нельзя.
		if ( '' !== (string) $post->post_password ) {
			return;
		}

		$settings = MII_Settings::all();

		// Выключатели «Что нового».
		if ( 'publish' === $event && empty( $settings['send_on_publish'] ) ) {
			return;
		}
		if ( 'update' === $event && empty( $settings['send_on_update'] ) ) {
			return;
		}

		// Тип записи разрешён?
		$post_types = (array) $settings['post_types'];
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		$url = get_permalink( $post->ID );
		if ( ! $url ) {
			return;
		}
		$url = esc_url_raw( $url );

		// Исключения из настроек.
		if ( self::is_excluded( $url, $post->ID ) ) {
			MII_Logger::log( 'info', 'skip', 'URL в списке исключений: ' . $url );
			return;
		}

		self::send_url( $url, $event, $source );
	}

	/**
	 * Отправить один URL во включённые сервисы (с защитой от дублей).
	 *
	 * @param string $url    Абсолютный URL страницы.
	 * @param string $event  'publish' | 'update' | 'manual'.
	 * @param string $source Источник вызова.
	 * @return array{indexnow:mixed,google:mixed} Результаты по каждому модулю.
	 */
	public static function send_url( $url, $event = 'manual', $source = 'manual' ) {
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return array(
				'indexnow' => new WP_Error( 'mii_empty_url', 'Пустой URL.' ),
				'google'   => new WP_Error( 'mii_empty_url', 'Пустой URL.' ),
			);
		}

		$lock_key = 'mii_lock_' . md5( $url );

		// Защита от повторной отправки в течение 5 минут (двойной вызов хуков, F5 и т.п.).
		if ( isset( self::$sent_in_request[ $url ] ) || get_transient( $lock_key ) ) {
			return array(
				'indexnow' => new WP_Error( 'mii_duplicate', 'URL уже отправлялся только что (защита от дублей).' ),
				'google'   => new WP_Error( 'mii_duplicate', 'URL уже отправлялся только что (защита от дублей).' ),
			);
		}

		self::$sent_in_request[ $url ] = true;
		set_transient( $lock_key, 1, 5 * MINUTE_IN_SECONDS );

		$results = array(
			'indexnow' => null,
			'google'   => null,
		);

		// ── Модуль 1: IndexNow (Яндекс, Bing, Naver, Seznam) ──
		if ( MII_Settings::get( 'indexnow_enabled' ) ) {
			$results['indexnow'] = MII_IndexNow::instance()->submit_urls( array( $url ), $source );
		} else {
			$results['indexnow'] = new WP_Error( 'mii_disabled', 'Модуль IndexNow выключен в настройках.' );
		}

		// ── Модуль 2: Google Indexing API ──
		if ( MII_Settings::get( 'google_enabled' ) ) {
			$results['google'] = MII_Google::instance()->publish( $url, 'URL_UPDATED' );
		} else {
			$results['google'] = new WP_Error( 'mii_disabled', 'Модуль Google Indexing API выключен в настройках.' );
		}

		/**
		 * Точка расширения: действие после отправки URL.
		 *
		 * @param string $url     Отправленный URL.
		 * @param string $event   Тип события.
		 * @param array  $results Результаты модулей.
		 */
		do_action( 'mii_after_send_url', $url, $event, $results );

		return $results;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Исключения и выборки записей
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Проверить URL/ID по списку исключений.
	 *
	 * Поддерживаются:
	 *  - ID записи (число в отдельной строке);
	 *  - точный URL;
	 *  - маска со звёздочкой: https://milyutin-codes.ru/private/*
	 *
	 * @param string $url Абсолютный URL.
	 * @param int    $id  ID записи.
	 * @return bool true — отправлять не нужно.
	 */
	public static function is_excluded( $url, $id = 0 ) {
		$raw = (string) MII_Settings::get( 'exclusions', '' );
		if ( '' === trim( $raw ) ) {
			return false;
		}

		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			// Число — сравнение по ID записи.
			if ( ctype_digit( $line ) ) {
				if ( $id && (int) $line === (int) $id ) {
					return true;
				}
				continue;
			}

			// Маска/URL.
			$pattern = '#^' . str_replace( '\*', '.*', preg_quote( $line, '#' ) ) . '$#i';
			if ( preg_match( $pattern, $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Разрешённые типы записей из настроек (только публичные).
	 *
	 * @return string[]
	 */
	public static function allowed_post_types() {
		$types = (array) MII_Settings::get( 'post_types', array( 'post' ) );
		$types = array_filter( array_map( 'sanitize_key', $types ) );

		// Оставляем только реально существующие публичные типы.
		$public = get_post_types( array( 'public' => true ), 'names' );
		$types  = array_values( array_intersect( $types, (array) $public ) );

		if ( empty( $types ) ) {
			$types = array( 'post' );
		}

		return $types;
	}

	/**
	 * Общее количество опубликованных записей разрешённых типов.
	 *
	 * @return int
	 */
	public static function count_indexable_posts() {
		$total = 0;
		foreach ( self::allowed_post_types() as $type ) {
			$counts = wp_count_posts( $type );
			if ( isset( $counts->publish ) ) {
				$total += (int) $counts->publish;
			}
		}
		return $total;
	}

	/**
	 * Порция записей для пакетной отправки.
	 *
	 * @param int $offset Смещение.
	 * @param int $limit  Сколько записей взять.
	 * @return WP_Post[]
	 */
	public static function get_posts_batch( $offset = 0, $limit = 10 ) {
		$offset = max( 0, (int) $offset );
		$limit  = min( 100, max( 1, (int) $limit ) );

		$posts = get_posts(
			array(
				'post_type'           => self::allowed_post_types(),
				'post_status'         => 'publish',
				'posts_per_page'      => $limit,
				'offset'              => $offset,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'suppress_filters'    => false,
			)
		);

		return is_array( $posts ) ? $posts : array();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// WP-Cron
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Слив очереди Google: добираем URL, которые не ушли из-за дневного лимита.
	 *
	 * @return void
	 */
	public static function drain_google_queue() {
		if ( ! MII_Settings::get( 'google_enabled' ) ) {
			return;
		}

		$queue = (array) get_option( 'mii_google_queue', array() );
		if ( empty( $queue ) ) {
			return;
		}

		$google = MII_Google::instance();
		$left   = $google->remaining_quota();
		if ( $left <= 0 ) {
			MII_Logger::log( 'warning', 'google', 'Очередь Google ждёт: дневной лимит исчерпан.' );
			return;
		}

		$sent = 0;
		while ( $sent < $left && ! empty( $queue ) ) {
			$url = array_shift( $queue );
			$res = $google->publish( $url, 'URL_UPDATED', true );

			if ( is_wp_error( $res ) ) {
				// Любая ошибка (квота, права, сеть) — URL возвращаем в очередь и останавливаемся,
				// иначе часть ссылок потерялась бы до следующего запуска.
				array_unshift( $queue, $url );
				MII_Logger::log( 'warning', 'google', 'Слив очереди прерван: ' . $res->get_error_message(), array( 'url' => $url ) );
				break;
			}

			++$sent;
		}

		update_option( 'mii_google_queue', array_values( $queue ), false );
		MII_Logger::log( 'info', 'google', sprintf( 'Из очереди Google отправлено URL: %d. Осталось в очереди: %d.', $sent, count( $queue ) ) );
	}

	/**
	 * Ежедневное обслуживание: сброс кэша правил ЧПУ, ротация логов, чистка старых логов.
	 *
	 * @return void
	 */
	public static function daily_maintenance() {
		if ( get_option( 'mii_flush_rewrite_needed' ) ) {
			flush_rewrite_rules();
			delete_option( 'mii_flush_rewrite_needed' );
		}

		MII_Logger::rotate_file();
		MII_Google::instance()->reset_quota_if_new_day();
	}
}

// ─── Запуск ───────────────────────────────────────────────────────────────────
MII_Plugin::instance();

// Хуки активации/деактивации регистрируем строго на уровне файла.
register_activation_hook( __FILE__, array( 'MII_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MII_Plugin', 'deactivate' ) );
