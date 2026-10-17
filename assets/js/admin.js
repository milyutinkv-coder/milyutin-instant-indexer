/**
 * Milyutin Instant Indexer — скрипты страницы настроек.
 *
 * Всё общение с сервером идёт через admin-ajax.php с nonce (miiData.nonce).
 * Скрипт ничего не решает сам: только собирает данные, шлёт запрос и рисует ответ.
 *
 * @package Milyutin_Instant_Indexer
 */

/* global jQuery, miiData */
( function ( $ ) {
	'use strict';

	/**
	 * Обёртка над $.post: всегда возвращает объект { success, data }.
	 *
	 * @param {string} action Имя действия без префикса mii_.
	 * @param {Object} data   Данные запроса.
	 * @return {Object} jQuery Deferred.
	 */
	function miiRequest( action, data ) {
		return $.post(
			miiData.ajaxUrl,
			$.extend(
				{
					action: 'mii_' + action,
					nonce: miiData.nonce
				},
				data || {}
			)
		).fail( function ( xhr ) {
			return xhr;
		} );
	}

	/**
	 * Показать сообщение в блоке результата.
	 *
	 * @param {string} selector Селектор блока.
	 * @param {string} type     ok|error|info.
	 * @param {string} html     Текст (безопасный, экранируется).
	 */
	function miiNotice( selector, type, html ) {
		var $box = $( selector );

		$box
			.prop( 'hidden', false )
			.removeClass( 'mii-result--ok mii-result--error mii-result--info' )
			.addClass( 'mii-result--' + type )
			.html(
				'<span class="mii-result__icon">' + ( type === 'ok' ? '✓' : type === 'error' ? '✕' : 'i' ) + '</span>' +
				'<span class="mii-result__text">' + html + '</span>'
			);
	}

	/**
	 * Экранирование текста перед вставкой в HTML.
	 *
	 * @param {string} text Текст.
	 * @return {string}
	 */
	function miiEsc( text ) {
		return $( '<div/>' ).text( text == null ? '' : String( text ) ).html();
	}

	/**
	 * Заблокировать/разблокировать кнопку и показать статус «Выполняется…».
	 *
	 * @param {Object} $btn    Кнопка.
	 * @param {string} label   Изначальный текст.
	 * @param {boolean} busy   Блокировать?
	 */
	function miiBusy( $btn, label, busy ) {
		if ( busy ) {
			$btn.data( 'label', $btn.text() ).prop( 'disabled', true ).text( label || miiData.i18n.working );
		} else {
			$btn.prop( 'disabled', false ).text( $btn.data( 'label' ) || label );
		}
	}

	/**
	 * Разобрать ответ сервера и вернуть данные или null.
	 *
	 * @param {Object} response Ответ.
	 * @param {string} box      Селектор блока для вывода ошибки.
	 * @return {Object|null}
	 */
	function miiUnpack( response, box ) {
		if ( response && response.success && response.data ) {
			return response.data;
		}

		var message = ( response && response.data && response.data.message )
			? response.data.message
			: 'Неизвестная ошибка. Откройте консоль браузера и повторите запрос.';

		if ( box ) {
			miiNotice( box, 'error', miiEsc( message ) );
		}

		return null;
	}

	$( function () {
		var $indexnowResult = '#mii-indexnow-result';
		var $googleResult = '#mii-google-result';
		var $manualResult = '#mii-manual-result';

		// ─── IndexNow: сгенерировать ключ ─────────────────────────────────────
		$( '#mii-generate-key' ).on( 'click', function () {
			var $btn = $( this );
			miiBusy( $btn, null, true );

			miiRequest( 'generate_key' ).always( function ( response ) {
				miiBusy( $btn, 'Сгенерировать', false );
				var data = miiUnpack( response, $indexnowResult );
				if ( ! data ) {
					return;
				}

				$( '#mii-indexnow-key' ).val( data.key );
				miiNotice(
					$indexnowResult,
					'ok',
					'Ключ создан: <code>' + miiEsc( data.key ) + '</code>. ' +
					'<strong>Не забудьте нажать «Сохранить настройки».</strong> Файл ключа: ' +
					'<a href="' + miiEsc( data.key_file_url ) + '" target="_blank" rel="noopener noreferrer">' + miiEsc( data.key_file_url ) + '</a>'
				);
			} );
		} );

		// ─── IndexNow: проверить ключ ─────────────────────────────────────────
		$( '#mii-check-key' ).on( 'click', function () {
			var $btn = $( this );
			miiBusy( $btn, null, true );

			miiRequest( 'check_key' ).always( function ( response ) {
				miiBusy( $btn, 'Проверить ключ', false );
				var data = miiUnpack( response, $indexnowResult );
				if ( ! data ) {
					return;
				}

				var localOk = data.local_ok;
				var remoteOk = data.remote_ok;

				$( '#mii-indexnow-badge' )
					.removeClass( 'mii-badge--ok mii-badge--bad' )
					.addClass( remoteOk ? 'mii-badge--ok' : 'mii-badge--bad' )
					.text( remoteOk ? 'Ключ подтверждён' : 'Ключ не подтверждён' );

				miiNotice(
					$indexnowResult,
					remoteOk ? 'ok' : ( localOk ? 'info' : 'error' ),
					'<strong>Файл ключа:</strong> ' + miiEsc( data.local_message ) + '<br />' +
					'<strong>Ответ IndexNow (HTTP ' + miiEsc( data.code ) + '):</strong> ' + miiEsc( data.remote_message )
				);
			} );
		} );

		// ─── IndexNow: создать физический файл ────────────────────────────────
		$( '#mii-create-key-file' ).on( 'click', function () {
			var $btn = $( this );
			miiBusy( $btn, null, true );

			miiRequest( 'create_key_file' ).always( function ( response ) {
				miiBusy( $btn, 'Создать файл ключа на диске', false );
				var data = miiUnpack( response, $indexnowResult );
				if ( ! data ) {
					return;
				}
				miiNotice( $indexnowResult, 'ok', miiEsc( data.message ) );
			} );
		} );

		// ─── Google: сохранить JSON ───────────────────────────────────────────
		$( '#mii-save-google' ).on( 'click', function () {
			var $btn = $( this );
			var text = $( '#mii-json-text' ).val();
			var fileInput = $( '#mii-json-file' )[ 0 ];
			var formData = new FormData();

			formData.append( 'action', 'mii_save_google_json' );
			formData.append( 'nonce', miiData.nonce );

			if ( fileInput && fileInput.files && fileInput.files.length ) {
				formData.append( 'mii_json_file', fileInput.files[ 0 ] );
			} else if ( text && text.trim() ) {
				formData.append( 'mii_json_text', text );
			} else {
				miiNotice( $googleResult, 'error', 'Вставьте JSON в поле или выберите файл.' );
				return;
			}

			miiBusy( $btn, null, true );

			$.ajax( {
				url: miiData.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false
			} ).always( function ( response ) {
				miiBusy( $btn, 'Сохранить и проверить', false );
				var data = miiUnpack( response, $googleResult );
				if ( ! data ) {
					return;
				}

				$( '#mii-google-badge' )
					.removeClass( 'mii-badge--ok mii-badge--bad' )
					.addClass( data.active ? 'mii-badge--ok' : 'mii-badge--bad' )
					.text( data.active ? 'Сервисный аккаунт активен' : 'Не подключено' );

				miiNotice(
					$googleResult,
					data.active ? 'ok' : 'error',
					miiEsc( data.message ) + ( data.email ? '<br /><code>' + miiEsc( data.email ) + '</code>' : '' )
				);
			} );
		} );

		// ─── Google: проверить подключение ────────────────────────────────────
		$( '#mii-check-google' ).on( 'click', function () {
			var $btn = $( this );
			miiBusy( $btn, null, true );

			miiRequest( 'check_google', { url: $( '#mii-single-url' ).val() || '' } ).always( function ( response ) {
				miiBusy( $btn, 'Проверить подключение', false );
				var data = miiUnpack( response, $googleResult );
				if ( ! data ) {
					return;
				}

				$( '#mii-google-quota' ).text( data.quota );
				$( '#mii-google-remaining' ).text( data.remaining );

				miiNotice(
					$googleResult,
					data.meta_ok ? 'ok' : 'info',
					miiEsc( data.message ) +
					'<br /><strong>Квота:</strong> ' + miiEsc( data.quota ) + ' из ' + miiEsc( data.limit ) + ' за сутки.'
				);
			} );
		} );

		// ─── Google: удалить JSON ─────────────────────────────────────────────
		$( '#mii-delete-google' ).on( 'click', function () {
			if ( ! window.confirm( miiData.i18n.confirm ) ) {
				return;
			}

			var $btn = $( this );
			miiBusy( $btn, null, true );

			miiRequest( 'delete_google_json' ).always( function ( response ) {
				miiBusy( $btn, 'Удалить ключ', false );
				var data = miiUnpack( response, $googleResult );
				if ( ! data ) {
					return;
				}

				$( '#mii-google-badge' ).removeClass( 'mii-badge--ok' ).addClass( 'mii-badge--bad' ).text( 'Не подключено' );
				$( '#mii-json-text' ).val( '' );
				miiNotice( $googleResult, 'info', miiEsc( data.message ) );
			} );
		} );

		// ─── Ручная отправка: выбрать запись из списка ────────────────────────
		$( '#mii-post-select' ).on( 'change', function () {
			var url = $( this ).val();
			if ( url ) {
				$( '#mii-single-url' ).val( url );
			}
		} );

		// ─── Ручная отправка: один URL ────────────────────────────────────────
		$( '#mii-send-single' ).on( 'click', function () {
			var $btn = $( this );
			var url = ( $( '#mii-single-url' ).val() || '' ).trim();

			if ( ! url ) {
				miiNotice( $manualResult, 'error', 'Укажите URL страницы.' );
				return;
			}

			miiBusy( $btn, null, true );

			miiRequest( 'send_single', { url: url, target: 'both' } ).always( function ( response ) {
				miiBusy( $btn, 'Отправить URL', false );
				var data = miiUnpack( response, $manualResult );
				if ( ! data ) {
					return;
				}

				miiNotice( $manualResult, data.ok ? 'ok' : 'error', miiMessages( data.messages ) + '<br /><code>' + miiEsc( data.url ) + '</code>' );
			} );
		} );

		// ─── Ручная отправка: все статьи с прогресс-баром ────────────────────
		$( '#mii-send-all' ).on( 'click', function () {
			var $btn = $( this );
			var target = $( '#mii-batch-target' ).val();
			var offset = 0;
			var limit = 10;
			var sent = 0;
			var errors = [];

			miiBusy( $btn, 'Отправляем…', true );
			$( '#mii-progress' ).prop( 'hidden', false );
			$( '#mii-progress-fill' ).css( 'width', '0%' );
			$( '#mii-progress-text' ).text( '0%' );

			miiRequest( 'count_posts' ).always( function ( response ) {
				var info = miiUnpack( response, $manualResult );
				if ( ! info ) {
					miiBusy( $btn, 'Отправить все статьи', false );
					return;
				}

				var total = info.total;

				if ( ! total ) {
					miiNotice( $manualResult, 'info', miiData.i18n.noPosts );
					miiBusy( $btn, 'Отправить все статьи', false );
					$( '#mii-progress' ).prop( 'hidden', true );
					return;
				}

				/**
				 * Отправляет очередную порцию и рекурсивно вызывает себя.
				 */
				function step() {
					miiRequest( 'send_batch', { offset: offset, limit: limit, target: target } ).always( function ( res ) {
						var page = miiUnpack( res, $manualResult );

						if ( ! page ) {
							miiBusy( $btn, 'Отправить все статьи', false );
							return;
						}

						offset += page.processed || 0;
						sent += ( page.urls ? page.urls.length : 0 );

						( page.messages || [] ).forEach( function ( m ) {
							if ( ! m.ok ) {
								errors.push( m.module + ': ' + m.message );
							}
						} );

						var percent = Math.min( 100, Math.round( ( offset / total ) * 100 ) );
						$( '#mii-progress-fill' ).css( 'width', percent + '%' );
						$( '#mii-progress-text' ).text( percent + '% (' + offset + ' из ' + total + ')' );

						if ( page.done || ! page.processed ) {
							miiBusy( $btn, 'Отправить все статьи', false );

							var html = '<strong>Готово.</strong> Обработано записей: ' + miiEsc( offset ) +
								', отправлено URL: ' + miiEsc( sent ) + '.';

							if ( errors.length ) {
								html += '<br /><strong>Замечания:</strong><br />' +
									errors.slice( 0, 5 ).map( function ( e ) {
										return '• ' + miiEsc( e );
									} ).join( '<br />' );
							}

							miiNotice( $manualResult, errors.length ? 'info' : 'ok', html );
							return;
						}

						step();
					} );
				}

				step();
			} );
		} );

		// ─── Логи ─────────────────────────────────────────────────────────────
		$( '#mii-clear-logs' ).on( 'click', function () {
			if ( ! window.confirm( miiData.i18n.confirmLog ) ) {
				return;
			}

			var $btn = $( this );
			miiBusy( $btn, null, true );

			miiRequest( 'clear_logs' ).always( function ( response ) {
				miiBusy( $btn, 'Очистить логи', false );
				var data = miiUnpack( response, $manualResult );
				if ( ! data ) {
					return;
				}
				$( '#mii-logs-table tbody' ).empty();
				$( '#mii-logs-table' ).after( '<p class="mii-hint" id="mii-logs-empty">Записей пока нет.</p>' );
			} );
		} );

		// ─── Вывод списка сообщений от модулей ───────────────────────────────
		/**
		 * Собрать HTML из массива сообщений модулей.
		 *
		 * @param {Array} messages Сообщения вида { ok, module, message }.
		 * @return {string}
		 */
		function miiMessages( messages ) {
			if ( ! messages || ! messages.length ) {
				return 'Готово.';
			}

			return messages.map( function ( m ) {
				return '<span class="mii-line mii-line--' + ( m.ok ? 'ok' : 'error' ) + '">' +
					'<strong>' + miiEsc( m.module ) + ':</strong> ' + miiEsc( m.message ) + '</span>';
			} ).join( '<br />' );
		}
	} );
} )( jQuery );
