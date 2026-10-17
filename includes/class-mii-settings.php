<?php
/**
 * Страница настроек плагина в админке.
 *
 * Здесь всё, что видит пользователь: поля IndexNow и Google, выключатели
 * авто-отправки, исключения, ручная отправка, таблица логов, инструкция
 * и блок об авторе.
 *
 * Сохранение настроек идёт через Settings API (options.php):
 *  - nonce создаётся автоматически функцией settings_fields();
 *  - права проверяет options.php (manage_options) и наш sanitize_callback.
 *
 * @package Milyutin_Instant_Indexer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Класс MII_Settings.
 */
class MII_Settings {

	/**
	 * Имя опции в таблице wp_options.
	 */
	const OPTION = 'mii_settings';

	/**
	 * Идентификатор страницы настроек.
	 */
	const PAGE_SLUG = 'milyutin-instant-indexer';

	/**
	 * Группа настроек (должна совпадать с settings_fields()).
	 */
	const GROUP = 'mii_settings_group';

	/**
	 * Экземпляр класса.
	 *
	 * @var MII_Settings|null
	 */
	private static $instance = null;

	/**
	 * Получить экземпляр.
	 *
	 * @return MII_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Конструктор: подключаем хуки админки.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . MII_BASENAME, array( $this, 'add_action_links' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Хранилище настроек
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Значения по умолчанию.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// IndexNow.
			'indexnow_enabled'       => 1,
			'indexnow_key'           => '',
			'indexnow_endpoint'      => 'yandex',
			'indexnow_physical_file' => 0,

			// Google.
			'google_enabled'         => 1,
			'google_daily_limit'     => 200,

			// Авто-отправка.
			'send_on_publish'        => 1,
			'send_on_update'         => 1,
			'post_types'             => array( 'post' ),
			'exclusions'             => '',

			// Логи и прочее.
			'log_enabled'            => 1,
		);
	}

	/**
	 * Все настройки (defaults + сохранённые значения).
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * Одно значение настройки.
	 *
	 * @param string $key     Ключ.
	 * @param mixed  $default Значение по умолчанию (null — взять из defaults()).
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( null === $default ) {
			$fallback = isset( self::defaults()[ $key ] ) ? self::defaults()[ $key ] : '';
		} else {
			$fallback = $default;
		}

		return isset( $all[ $key ] ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Записать массив настроек (используется AJAX-обработчиками для ключа).
	 *
	 * @param array $data Данные.
	 * @return void
	 */
	public static function update( $data ) {
		$merged = wp_parse_args( (array) $data, self::defaults() );
		update_option( self::OPTION, $merged );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Регистрация настроек и санитизация
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * register_setting + секции/поля (минимум — основной рендер свой, но
	 * регистрация обязательна, чтобы options.php принял данные).
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Санитизация всех полей формы.
	 *
	 * @param mixed $input Данные из $_POST.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$old    = self::all();
		$output = $old;

		// ── Флаги (чекбоксы приходят только когда отмечены) ──
		$output['indexnow_enabled']       = empty( $input['indexnow_enabled'] ) ? 0 : 1;
		$output['indexnow_physical_file'] = empty( $input['indexnow_physical_file'] ) ? 0 : 1;
		$output['google_enabled']         = empty( $input['google_enabled'] ) ? 0 : 1;
		$output['send_on_publish']        = empty( $input['send_on_publish'] ) ? 0 : 1;
		$output['send_on_update']         = empty( $input['send_on_update'] ) ? 0 : 1;
		$output['log_enabled']            = empty( $input['log_enabled'] ) ? 0 : 1;

		// ── Ключ IndexNow ──
		$new_key = isset( $input['indexnow_key'] ) ? trim( (string) $input['indexnow_key'] ) : '';
		$new_key = preg_replace( '/[^A-Za-z0-9\-]/', '', $new_key );

		if ( '' === $new_key ) {
			// Оставляем прежний ключ, не даём случайно обнулить рабочий файл.
			$output['indexnow_key'] = $old['indexnow_key'];
			add_settings_error( self::OPTION, 'mii_key_empty', 'Ключ IndexNow не может быть пустым — оставлен прежний.', 'warning' );
		} elseif ( ! MII_IndexNow::is_valid_key( $new_key ) ) {
			$output['indexnow_key'] = $old['indexnow_key'];
			add_settings_error(
				self::OPTION,
				'mii_key_invalid',
				'Ключ IndexNow должен содержать от 8 до 128 символов: латиница, цифры, дефис. Оставлен прежний ключ.',
				'error'
			);
		} elseif ( $new_key !== $old['indexnow_key'] ) {
			// Ключ изменился: убираем старый файл, помечаем необходимость сброса ЧПУ.
			MII_IndexNow::delete_physical_key_file( $old['indexnow_key'] );
			$output['indexnow_key'] = $new_key;
			update_option( 'mii_flush_rewrite_needed', 1, false );

			if ( ! empty( $output['indexnow_physical_file'] ) ) {
				// Передаём ключ явно: настройки ещё не сохранены, get_key() вернёт старый.
				MII_IndexNow::create_physical_key_file( $new_key );
			}

			MII_Logger::log( 'info', 'indexnow', 'Ключ IndexNow изменён вручную.' );
		} else {
			$output['indexnow_key'] = $new_key;
		}

		// ── Точка приёма IndexNow (только из белого списка) ──
		$endpoint                = isset( $input['indexnow_endpoint'] ) ? sanitize_key( $input['indexnow_endpoint'] ) : 'yandex';
		$endpoints               = MII_IndexNow::endpoints();
		$output['indexnow_endpoint'] = isset( $endpoints[ $endpoint ] ) ? $endpoint : 'yandex';

		// ── Дневной лимит Google (1–200) ──
		$limit                     = isset( $input['google_daily_limit'] ) ? absint( $input['google_daily_limit'] ) : 200;
		$output['google_daily_limit'] = max( 1, min( 200, $limit ) );

		// ── Типы записей ──
		$public_types = get_post_types( array( 'public' => true ), 'names' );
		$types        = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
		$types        = array_values( array_intersect( array_map( 'sanitize_key', $types ), (array) $public_types ) );
		$output['post_types'] = ! empty( $types ) ? $types : array( 'post' );

		// ── Исключения ──
		$output['exclusions'] = isset( $input['exclusions'] ) ? sanitize_textarea_field( wp_unslash( $input['exclusions'] ) ) : '';

		// Физический файл ключа: создаём при включении, удаляем при выключении.
		if ( ! empty( $output['indexnow_physical_file'] ) ) {
			if ( ! MII_IndexNow::has_physical_key_file( $output['indexnow_key'] ) ) {
				$created = MII_IndexNow::create_physical_key_file( $output['indexnow_key'] );
				if ( is_wp_error( $created ) ) {
					add_settings_error( self::OPTION, 'mii_key_file', 'Ключ сохранён, но физический файл создать не удалось: ' . $created->get_error_message(), 'warning' );
				}
			}
		} elseif ( MII_IndexNow::has_physical_key_file( $output['indexnow_key'] ) ) {
			MII_IndexNow::delete_physical_key_file( $output['indexnow_key'] );
		}

		return $output;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Меню и ассеты
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Пункт меню в админке.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		add_menu_page(
			'Milyutin Instant Indexer',
			'Instant Indexer',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-search',
			81
		);
	}

	/**
	 * Ссылка «Настройки» в списке плагинов.
	 *
	 * @param string[] $links Ссылки.
	 * @return string[]
	 */
	public function add_action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Настройки</a>' );

		return $links;
	}

	/**
	 * Подключить CSS/JS только на нашей странице.
	 *
	 * @param string $hook Суффикс текущей страницы админки.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'mii-admin',
			MII_URL . 'assets/css/admin.css',
			array(),
			MII_VERSION
		);

		wp_enqueue_script(
			'mii-admin',
			MII_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			MII_VERSION,
			true
		);

		wp_localize_script(
			'mii-admin',
			'miiData',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( MII_Ajax::NONCE_ACTION ),
				'keyUrl'   => MII_IndexNow::key_file_url(),
				'i18n'     => array(
					'working'   => 'Выполняется…',
					'done'      => 'Готово',
					'error'     => 'Ошибка',
					'confirm'   => 'Удалить сохранённый JSON сервисного аккаунта?',
					'confirmLog' => 'Очистить все логи?',
					'noPosts'   => 'Нет записей для отправки.',
				),
			)
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Рендер страницы
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Вывести страницу настроек.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Недостаточно прав для просмотра этой страницы.' ) );
		}

		$s = self::all();
		?>
		<div class="wrap mii-wrap">

			<?php $this->render_header( $s ); ?>

			<?php settings_errors( self::OPTION ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( self::GROUP ); ?>

				<div class="mii-grid">
					<?php
					$this->render_indexnow_card( $s );
					$this->render_google_card( $s );
					$this->render_auto_card( $s );
					$this->render_logs_card( $s );
					?>
				</div>

				<?php submit_button( 'Сохранить настройки', 'primary large', 'submit', false ); ?>
			</form>

			<div class="mii-grid">
				<?php
				$this->render_manual_card();
				$this->render_author_card();
				?>
			</div>

			<?php $this->render_help_card(); ?>
		</div>
		<?php
	}

	/**
	 * Шапка страницы: название, версия, автор, ссылки.
	 *
	 * @param array $s Настройки.
	 * @return void
	 */
	private function render_header( $s ) {
		unset( $s );
		?>
		<div class="mii-header">
			<div class="mii-header__main">
				<h1>
					<span class="mii-logo">MII</span>
					Milyutin Instant Indexer
					<span class="mii-version">v<?php echo esc_html( MII_VERSION ); ?></span>
				</h1>
				<p class="mii-header__desc">
					Мгновенная отправка новых и обновлённых статей на индексацию:
					<strong>Яндекс (IndexNow)</strong> и <strong>Google (Indexing API)</strong>.
					Без подписок и сторонних сервисов — только ваши собственные бесплатные ключи.
				</p>
			</div>
			<div class="mii-header__author">
				<div class="mii-header__author-name">Автор: Константин Милютин</div>
				<div class="mii-header__links">
					<a href="https://milyutin-codes.ru" target="_blank" rel="noopener noreferrer" class="mii-link mii-link--site">
						milyutin-codes.ru
					</a>
					<a href="https://vk.com/milyutin_codes" target="_blank" rel="noopener noreferrer" class="mii-link mii-link--vk">
						Группа ВКонтакте
					</a>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Блок IndexNow.
	 *
	 * @param array $s Настройки.
	 * @return void
	 */
	private function render_indexnow_card( $s ) {
		$key       = (string) $s['indexnow_key'];
		$key_valid = MII_IndexNow::is_valid_key( $key );
		$key_url   = MII_IndexNow::key_file_url();
		$physical  = MII_IndexNow::has_physical_key_file();
		$endpoints = MII_IndexNow::endpoints();
		?>
		<div class="mii-card">
			<div class="mii-card__head">
				<h2>1. IndexNow — Яндекс, Bing, Naver, Seznam</h2>
				<span class="mii-badge <?php echo $key_valid ? 'mii-badge--ok' : 'mii-badge--bad'; ?>" id="mii-indexnow-badge">
					<?php echo $key_valid ? 'Ключ задан' : 'Ключ не задан'; ?>
				</span>
			</div>

			<p class="mii-hint">
				IndexNow — открытый протокол: один запрос уведомляет сразу Яндекс, Bing, Naver и Seznam.
				Регистрация не нужна, ключ генерируется прямо здесь.
			</p>

			<p>
				<label class="mii-switch">
					<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[indexnow_enabled]" value="1" <?php checked( $s['indexnow_enabled'], 1 ); ?> />
					<span>Включить модуль IndexNow</span>
				</label>
			</p>

			<div class="mii-field">
				<label for="mii-indexnow-key"><strong>Ключ IndexNow</strong> (8–128 символов: латиница, цифры, дефис)</label>
				<div class="mii-field__row">
					<input type="text"
						id="mii-indexnow-key"
						name="<?php echo esc_attr( self::OPTION ); ?>[indexnow_key]"
						value="<?php echo esc_attr( $key ); ?>"
						class="regular-text code"
						autocomplete="off"
						spellcheck="false" />
					<button type="button" class="button button-secondary" id="mii-generate-key">Сгенерировать</button>
					<button type="button" class="button" id="mii-check-key">Проверить ключ</button>
				</div>
			</div>

			<?php if ( '' !== $key_url ) : ?>
				<div class="mii-field">
					<label>Файл ключа (должен открываться и содержать ровно ключ)</label>
					<code class="mii-code">
						<a href="<?php echo esc_url( $key_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $key_url ); ?></a>
					</code>
					<p class="mii-hint">
						Статус: <?php echo $physical ? 'создан физический файл в корне сайта' : 'отдаётся виртуально через rewrite rules (файл на диск не пишется)'; ?>.
					</p>
				</div>
			<?php endif; ?>

			<div class="mii-field">
				<label for="mii-indexnow-endpoint"><strong>Куда отправлять</strong></label>
				<select id="mii-indexnow-endpoint" name="<?php echo esc_attr( self::OPTION ); ?>[indexnow_endpoint]">
					<?php foreach ( $endpoints as $slug => $url ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['indexnow_endpoint'], $slug ); ?>>
							<?php echo esc_html( $this->endpoint_label( $slug ) . ' — ' . $url ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="mii-hint">
					<code>api.indexnow.org</code> — универсальная точка (передаёт уведомления всем участникам протокола).
					<code>yandex.com/indexnow</code> — напрямую в Яндекс (рекомендуется).
				</p>
			</div>

			<p>
				<label class="mii-switch">
					<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[indexnow_physical_file]" value="1" <?php checked( $s['indexnow_physical_file'], 1 ); ?> />
					<span>Создавать физический файл ключа в корне сайта (резерв, если rewrite rules не работают)</span>
				</label>
			</p>

			<p>
				<button type="button" class="button" id="mii-create-key-file">Создать файл ключа на диске</button>
			</p>

			<div class="mii-result" id="mii-indexnow-result" hidden></div>
		</div>
		<?php
	}

	/**
	 * Человекочитаемое название точки приёма.
	 *
	 * @param string $slug Слаг.
	 * @return string
	 */
	private function endpoint_label( $slug ) {
		$labels = array(
			'yandex'   => 'Яндекс',
			'indexnow' => 'IndexNow (все участники)',
			'bing'     => 'Bing',
			'naver'    => 'Naver',
			'seznam'   => 'Seznam',
		);

		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;
	}

	/**
	 * Блок Google Indexing API.
	 *
	 * @param array $s Настройки.
	 * @return void
	 */
	private function render_google_card( $s ) {
		$google     = MII_Google::instance();
		$connected  = $google->has_credentials();
		$meta       = $google->get_meta();
		$quota      = $google->get_quota();
		$limit      = $google->daily_limit();
		$remaining  = $google->remaining_quota();
		$queue      = $google->queue_size();
		$crypto_ok  = MII_Crypto::is_available();
		?>
		<div class="mii-card">
			<div class="mii-card__head">
				<h2>2. Google Indexing API</h2>
				<span class="mii-badge <?php echo $connected ? 'mii-badge--ok' : 'mii-badge--bad'; ?>" id="mii-google-badge">
					<?php echo $connected ? 'Сервисный аккаунт активен' : 'Не подключено'; ?>
				</span>
			</div>

			<p class="mii-hint">
				Используется ваш собственный сервисный аккаунт Google Cloud — регистрация в платных сервисах не нужна.
				Лимит: <strong><?php echo (int) $limit; ?> запросов в сутки</strong> на проект.
				Сегодня отправлено: <strong id="mii-google-quota"><?php echo (int) $quota['count']; ?></strong>
				из <?php echo (int) $limit; ?> (осталось <span id="mii-google-remaining"><?php echo (int) $remaining; ?></span>).
				<?php if ( $queue > 0 ) : ?>
					<br />В очереди на завтра: <strong id="mii-google-queue"><?php echo (int) $queue; ?></strong> URL.
				<?php endif; ?>
			</p>

			<?php if ( ! $crypto_ok ) : ?>
				<div class="mii-alert mii-alert--error">
					<strong>OpenSSL недоступен на сервере.</strong> Безопасно сохранить JSON-ключ невозможно.
					Попросите хостинг включить расширение openssl (php-openssl).
				</div>
			<?php endif; ?>

			<p>
				<label class="mii-switch">
					<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[google_enabled]" value="1" <?php checked( $s['google_enabled'], 1 ); ?> />
					<span>Включить модуль Google Indexing API</span>
				</label>
			</p>

			<?php if ( $connected ) : ?>
				<div class="mii-field">
					<label>Сохранённый сервисный аккаунт</label>
					<p class="mii-saved">
						<span class="mii-dot mii-dot--ok"></span>
						<code><?php echo esc_html( $meta['client_email'] ); ?></code>
						<?php if ( '' !== $meta['project_id'] ) : ?>
							<span class="mii-hint">проект: <?php echo esc_html( $meta['project_id'] ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $meta['saved_at'] ) : ?>
							<span class="mii-hint">загружен: <?php echo esc_html( $meta['saved_at'] ); ?></span>
						<?php endif; ?>
					</p>
					<p class="mii-hint">
						Приватный ключ хранится в базе в зашифрованном виде (AES-256-CBC + HMAC) и никогда не выводится на эту страницу.
					</p>
				</div>
			<?php endif; ?>

			<div class="mii-field">
				<label for="mii-json-text">
					<strong><?php echo $connected ? 'Заменить JSON-ключ' : 'Вставьте JSON-ключ сервисного аккаунта'; ?></strong>
				</label>
				<textarea id="mii-json-text" rows="5" class="large-text code" spellcheck="false"
					placeholder='{ "type": "service_account", "project_id": "...", "private_key": "-----BEGIN PRIVATE KEY-----...", "client_email": "milyutin-indexer@....iam.gserviceaccount.com", ... }'></textarea>

				<p class="mii-field__row">
					<input type="file" id="mii-json-file" accept=".json,application/json" />
					<button type="button" class="button button-primary" id="mii-save-google">Сохранить и проверить</button>
					<button type="button" class="button" id="mii-check-google">Проверить подключение</button>
					<?php if ( $connected ) : ?>
						<button type="button" class="button button-link-delete" id="mii-delete-google">Удалить ключ</button>
					<?php endif; ?>
				</p>
			</div>

			<div class="mii-alert mii-alert--warn">
				<strong>Важно про Google.</strong> Официально Indexing API предназначен для вакансий (JobPosting)
				и прямых трансляций (BroadcastEvent). Для обычных статей Google не гарантирует ускорение индексации —
				используйте Google Indexing API как дополнение, а основным каналом считайте IndexNow и качественный sitemap.
				Также убедитесь, что email сервисного аккаунта добавлен в Google Search Console с правами «Владелец».
			</div>

			<div class="mii-field">
				<label for="mii-google-limit"><strong>Дневной лимит отправок</strong> (1–200)</label>
				<input type="number" id="mii-google-limit" min="1" max="200" step="1"
					name="<?php echo esc_attr( self::OPTION ); ?>[google_daily_limit]"
					value="<?php echo (int) $s['google_daily_limit']; ?>" class="small-text" />
				<p class="mii-hint">Оставьте 200, если не хотите расходовать квоту полностью.</p>
			</div>

			<div class="mii-result" id="mii-google-result" hidden></div>
		</div>
		<?php
	}

	/**
	 * Блок «Что нового» (авто-отправка и исключения).
	 *
	 * @param array $s Настройки.
	 * @return void
	 */
	private function render_auto_card( $s ) {
		$public_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="mii-card">
			<div class="mii-card__head">
				<h2>3. Автоматическая отправка</h2>
			</div>

			<p>
				<label class="mii-switch">
					<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[send_on_publish]" value="1" <?php checked( $s['send_on_publish'], 1 ); ?> />
					<span>Отправлять при <strong>публикации</strong> новой записи</span>
				</label>
			</p>

			<p>
				<label class="mii-switch">
					<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[send_on_update]" value="1" <?php checked( $s['send_on_update'], 1 ); ?> />
					<span>Отправлять при <strong>обновлении</strong> опубликованной записи</span>
				</label>
			</p>

			<div class="mii-field">
				<label><strong>Типы записей</strong></label>
				<div class="mii-checkboxes">
					<?php foreach ( $public_types as $type ) : ?>
						<label class="mii-checkbox">
							<input type="checkbox"
								name="<?php echo esc_attr( self::OPTION ); ?>[post_types][]"
								value="<?php echo esc_attr( $type->name ); ?>"
								<?php checked( in_array( $type->name, (array) $s['post_types'], true ) ); ?> />
							<span><?php echo esc_html( $type->labels->singular_name . ' (' . $type->name . ')' ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="mii-field">
				<label for="mii-exclusions"><strong>Исключения</strong> — эти URL/записи не отправляются</label>
				<textarea id="mii-exclusions" rows="5" class="large-text code" spellcheck="false"
					name="<?php echo esc_attr( self::OPTION ); ?>[exclusions]"
					placeholder="123&#10;https://milyutin-codes.ru/about/&#10;https://milyutin-codes.ru/private/*"><?php echo esc_textarea( (string) $s['exclusions'] ); ?></textarea>
				<p class="mii-hint">По одной записи в строке: ID записи, точный URL или маска со звёздочкой (<code>*</code>).</p>
			</div>

			<p>
				<label class="mii-switch">
					<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[log_enabled]" value="1" <?php checked( $s['log_enabled'], 1 ); ?> />
					<span>Вести логи запросов</span>
				</label>
			</p>
		</div>
		<?php
	}

	/**
	 * Блок «Логи».
	 *
	 * @param array $s Настройки.
	 * @return void
	 */
	private function render_logs_card( $s ) {
		unset( $s );
		$entries = MII_Logger::get_entries( 20 );
		$file    = MII_Logger::file_path();
		?>
		<div class="mii-card">
			<div class="mii-card__head">
				<h2>4. Логи — последние 20 запросов</h2>
				<button type="button" class="button" id="mii-clear-logs">Очистить логи</button>
			</div>

			<?php if ( empty( $entries ) ) : ?>
				<p class="mii-hint" id="mii-logs-empty">Записей пока нет. Опубликуйте запись или нажмите «Проверить ключ».</p>
			<?php endif; ?>

			<div class="mii-table-wrap">
				<table class="widefat striped mii-table" id="mii-logs-table">
					<thead>
						<tr>
							<th>Дата и время</th>
							<th>Сервис</th>
							<th>URL</th>
							<th>Код</th>
							<th>Результат</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['time'] ); ?></td>
								<td><span class="mii-tag mii-tag--<?php echo esc_attr( $entry['context'] ); ?>"><?php echo esc_html( $entry['context'] ); ?></span></td>
								<td class="mii-cell-url">
									<?php if ( ! empty( $entry['url'] ) ) : ?>
										<a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $entry['url'] ); ?></a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td><?php echo $entry['code'] ? (int) $entry['code'] : '—'; ?></td>
								<td>
									<span class="mii-level mii-level--<?php echo esc_attr( $entry['level'] ); ?>"><?php echo esc_html( $this->level_label( $entry['level'] ) ); ?></span>
									<?php echo esc_html( $entry['message'] ); ?>
									<?php if ( ! empty( $entry['extra'] ) ) : ?>
										<span class="mii-hint">(<?php echo esc_html( $entry['extra'] ); ?>)</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $file ) : ?>
				<p class="mii-hint">Файл лога: <code><?php echo esc_html( $file ); ?></code> (защищён .htaccess, доступ извне закрыт).</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Название уровня логирования.
	 *
	 * @param string $level Уровень.
	 * @return string
	 */
	private function level_label( $level ) {
		$labels = array(
			'success' => 'Успех',
			'info'    => 'Инфо',
			'warning' => 'Внимание',
			'error'   => 'Ошибка',
		);

		return isset( $labels[ $level ] ) ? $labels[ $level ] : $level;
	}

	/**
	 * Блок «Ручная отправка» (вне формы настроек, работает через AJAX).
	 *
	 * @return void
	 */
	private function render_manual_card() {
		$total = MII_Plugin::count_indexable_posts();
		?>
		<div class="mii-card">
			<div class="mii-card__head">
				<h2>5. Ручная отправка</h2>
			</div>

			<div class="mii-field">
				<label><strong>Отправить все статьи</strong> — найдено записей: <span id="mii-total-posts"><?php echo (int) $total; ?></span></label>
				<p class="mii-field__row">
					<select id="mii-batch-target">
						<option value="both">В Яндекс (IndexNow) и Google</option>
						<option value="indexnow">Только IndexNow (Яндекс)</option>
						<option value="google">Только Google Indexing API</option>
					</select>
					<button type="button" class="button button-primary" id="mii-send-all">Отправить все статьи</button>
				</p>
				<div class="mii-progress" id="mii-progress" hidden>
					<div class="mii-progress__bar"><span id="mii-progress-fill"></span></div>
					<div class="mii-progress__text" id="mii-progress-text">0%</div>
				</div>
				<p class="mii-hint">
					Отправка идёт порциями по 10 записей — можно закрыть страницу, уже отправленные URL останутся отправленными.
					Учтите дневной лимит Google: остаток — <?php echo (int) MII_Google::instance()->remaining_quota(); ?> запросов.
				</p>
			</div>

			<hr />

			<div class="mii-field">
				<label for="mii-single-url"><strong>Отправить конкретную статью</strong></label>
				<p class="mii-field__row">
					<input type="url" id="mii-single-url" class="regular-text" placeholder="https://milyutin-codes.ru/моя-статья/" />
					<button type="button" class="button" id="mii-send-single">Отправить URL</button>
				</p>
				<p class="mii-field__row">
					<select id="mii-post-select">
						<option value="">— выбрать запись из списка —</option>
						<?php
						$recent = MII_Plugin::get_posts_batch( 0, 50 );
						foreach ( $recent as $post ) :
							$permalink = get_permalink( $post->ID );
							if ( ! $permalink ) {
								continue;
							}
							?>
							<option value="<?php echo esc_url( $permalink ); ?>">
								<?php echo esc_html( wp_trim_words( $post->post_title, 12, '…' ) . ' — ' . $permalink ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
			</div>

			<div class="mii-result" id="mii-manual-result" hidden></div>
		</div>
		<?php
	}

	/**
	 * Блок об авторе.
	 *
	 * @return void
	 */
	private function render_author_card() {
		?>
		<div class="mii-card mii-card--author">
			<div class="mii-card__head">
				<h2>Об авторе</h2>
			</div>

			<div class="mii-author">
				<div class="mii-author__avatar">КМ</div>
				<div class="mii-author__info">
					<div class="mii-author__name">Константин Милютин</div>
					<div class="mii-author__role">WordPress-разработчик · Milyutin Codes</div>
					<p class="mii-hint">
						Плагин распространяется бесплатно. Если он помог вашему сайту быстрее попадать в индекс —
						заходите на сайт и в группу ВКонтакте, там разборы и другие решения.
					</p>
					<p class="mii-author__links">
						<a class="mii-btn mii-btn--primary" href="https://milyutin-codes.ru" target="_blank" rel="noopener noreferrer">
							Сайт milyutin-codes.ru
						</a>
						<a class="mii-btn mii-btn--vk" href="https://vk.com/milyutin_codes" target="_blank" rel="noopener noreferrer">
							Группа ВКонтакте
						</a>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Блок с подробной инструкцией по настройке.
	 *
	 * @return void
	 */
	private function render_help_card() {
		?>
		<div class="mii-card mii-card--help">
			<div class="mii-card__head">
				<h2>Инструкция по настройке</h2>
			</div>

			<details open>
				<summary>Часть 1. IndexNow (Яндекс, Bing, Naver, Seznam) — 2 минуты</summary>
				<ol class="mii-steps">
					<li>В блоке «1. IndexNow» нажмите <strong>«Сгенерировать»</strong> — плагин создаст случайную строку из 32 символов (латиница и цифры).</li>
					<li>
						Сохраните настройки кнопкой <strong>«Сохранить настройки»</strong>.
						Плагин отдаёт файл ключа виртуально по адресу
						<code>https://milyutin-codes.ru/<em>{ключ}</em>.txt</code> через rewrite rules — запись на диск не требуется.
					</li>
					<li>
						Проверьте в браузере: откройте <code>https://milyutin-codes.ru/<em>{ключ}</em>.txt</code>.
						На странице должна быть <strong>только сгенерированная строка</strong>, без HTML и пробелов.
						Если файл не открывается — включите «красивые ссылки» (Настройки → Постоянные ссылки → «Название записи»),
						либо поставьте галочку «Создавать физический файл ключа» и нажмите «Создать файл ключа на диске».
					</li>
					<li>
						Нажмите <strong>«Проверить ключ»</strong>: плагин скачает файл ключа со стороны и отправит тестовый URL
						на <code>yandex.com/indexnow</code>. Код <strong>200</strong> — ключ подтверждён.
						Код <strong>202</strong> — уведомление принято, но ключ ещё проверяется: подождите и повторите (плагин
						сделает это сам через WP-Cron).
					</li>
					<li>
						Готово. Теперь при публикации или обновлении записи плагин сам отправляет URL в IndexNow.
						Массовая отправка — до <strong>10 000 URL в одном запросе</strong> (кнопка «Отправить все статьи»).
					</li>
				</ol>
				<p class="mii-hint">
					Проверить, что Яндекс получил уведомление, можно в Яндекс.Вебмастере → «Индексирование» → «Переобход страниц».
				</p>
			</details>

			<details>
				<summary>Часть 2. Google Indexing API (свой сервисный аккаунт) — 8 минут</summary>
				<ol class="mii-steps">
					<li>Откройте <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">Google Cloud Console</a> и создайте новый проект (или выберите существующий).</li>
					<li>В меню слева: <strong>APIs &amp; Services → Library</strong>. Найдите <strong>«Web Search Indexing API»</strong> и нажмите <strong>Enable</strong>.</li>
					<li>Перейдите в <strong>IAM &amp; Admin → Service Accounts</strong> и нажмите <strong>Create Service Account</strong>.</li>
					<li>Введите имя, например <code>milyutin-indexer</code> → <strong>Create and Continue</strong> → <strong>Done</strong>.</li>
					<li>Откройте созданный аккаунт → вкладка <strong>Keys</strong> → <strong>Add Key → Create new key → JSON</strong>. Браузер скачает файл <code>.json</code>.</li>
					<li>Скопируйте <strong>email сервисного аккаунта</strong> (вида <code>milyutin-indexer@проект.iam.gserviceaccount.com</code>).</li>
					<li>
						Откройте <a href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer">Google Search Console</a> →
						выберите сайт <code>milyutin-codes.ru</code> → <strong>Настройки → Пользователи и разрешения → Добавить пользователя</strong>.
						Вставьте email сервисного аккаунта и выдайте права <strong>«Владелец»</strong>.
					</li>
					<li>В блоке «2. Google Indexing API» вставьте содержимое JSON в текстовое поле или выберите файл и нажмите <strong>«Сохранить и проверить»</strong>.</li>
					<li>
						Нажмите <strong>«Проверить подключение»</strong>: плагин получит JWT-токен и запросит метаданные URL.
						Успех = «токен получен, доступ к данным Search Console есть».
					</li>
				</ol>
				<p class="mii-hint">
					<strong>Лимит Google — 200 запросов в сутки на проект.</strong> Если публикуете больше — лишние URL
					автоматически попадут в очередь и будут отправлены на следующий день (WP-Cron). IndexNow такого лимита не имеет.
				</p>
			</details>

			<details>
				<summary>Часть 3. Частые ошибки и что делать</summary>
				<ul class="mii-steps">
					<li><strong>IndexNow 403/404</strong> — файл ключа недоступен: включите «красивые ссылки», очистите кэш, создайте физический файл.</li>
					<li><strong>IndexNow 422</strong> — отправленный URL не принадлежит домену сайта. Проверьте, что адрес сайта в WordPress (Настройки → Общие) совпадает с реальным доменом и без лишнего <code>www</code>.</li>
					<li><strong>Google 403 PERMISSION_DENIED</strong> — email сервисного аккаунта не добавлен в Search Console владельцем, либо не включён Web Search Indexing API.</li>
					<li><strong>Google 429 / «лимит исчерпан»</strong> — дневная квота. URL встанут в очередь и уйдут автоматически.</li>
					<li><strong>«Не удалось расшифровать сохранённый JSON»</strong> — сменились соли в <code>wp-config.php</code>. Загрузите JSON заново.</li>
					<li><strong>Письма/уведомления не при чём</strong>: плагин ничего не пишет в базу, кроме настроек, логов и счётчика квоты.</li>
				</ul>
			</details>
		</div>
		<?php
	}
}
