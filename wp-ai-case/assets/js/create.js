/**
 * 施工事例をAIで作成（顧客向け画面）。
 *
 * - 表示する文字はすべて textContent で入れる（入力やAIの文章を HTML として扱わない）。
 * - 入力は sessionStorage に一時保存し、離れて戻っても復元できる。作成中のジョブも再開できる。
 * - 1回の「進める」でサーバーは外部呼び出しを最大1回だけ行う。
 */
( function () {
	'use strict';

	var cfg = window.WPAIC || {};
	var root = document.getElementById( 'wpaic-app' );
	if ( ! root ) {
		return;
	}
	var FORM_KEY = 'wpaic_form_v1';
	var JOB_KEY = 'wpaic_job_v1';
	var PHASES = { unknown: '未指定', before: '施工前', during: '施工中', after: '施工後' };
	var SECTION_LABELS = {
		title: 'タイトル', concept: 'コンセプト（見出し）', summary: 'コンセプト説明', body: '本文（各セクション）',
		client_request: 'お客様のご要望', proposal: 'ご提案（実施した工事）', facts: '施工概要の項目',
		photos: '写真（キャプション）', before_after: '写真の工程', layout: 'トップの写真レイアウト', seo: 'SEO'
	};
	var FACT_LABELS = {
		overview: '施工概要', client_request: 'お客様のご要望', work_done: '実施した工事・工夫', area: '地域',
		building_type: '建物種別', duration: '工期', cost: '施工費用', materials: '使用商材・建材', notes: 'メモ',
		floor_plan: '間取り', site_area: '敷地面積'
	};
	var STATUS_TEXT = {
		queued: '順番待ちです', analyzing: '写真と入力内容を解析しています', proposal_ready: '下書き案ができました',
		saving: '保存しています', committed: '下書きとして保存しました', cancelled: '取り消しました',
		expired: '有効期限が切れました', failed: '処理を完了できませんでした', needs_recovery: '送信結果を確認できませんでした'
	};

	var state = { options: null, job: null, busy: false, form: loadForm() };

	// ---------- 小さな道具 ----------
	function h( tag, attrs ) {
		var el = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( k === 'text' ) {
				el.textContent = v;
			} else if ( k === 'on' ) {
				Object.keys( v ).forEach( function ( ev ) { el.addEventListener( ev, v[ ev ] ); } );
			} else if ( k === 'class' ) {
				el.className = v;
			} else if ( v === true ) {
				el.setAttribute( k, '' );
			} else if ( v !== false && v !== null && v !== undefined ) {
				el.setAttribute( k, String( v ) );
			}
		} );
		for ( var i = 2; i < arguments.length; i++ ) {
			var c = arguments[ i ];
			if ( c === null || c === undefined || c === false ) {
				continue;
			}
			( Array.isArray( c ) ? c : [ c ] ).forEach( function ( x ) {
				if ( x ) {
					el.appendChild( typeof x === 'string' ? document.createTextNode( x ) : x );
				}
			} );
		}
		return el;
	}
	function uuid() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		var b = new Uint8Array( 16 );
		window.crypto.getRandomValues( b );
		b[ 6 ] = ( b[ 6 ] & 0x0f ) | 0x40;
		b[ 8 ] = ( b[ 8 ] & 0x3f ) | 0x80;
		var s = Array.prototype.map.call( b, function ( x ) { return ( '0' + x.toString( 16 ) ).slice( -2 ); } ).join( '' );
		return s.slice( 0, 8 ) + '-' + s.slice( 8, 12 ) + '-' + s.slice( 12, 16 ) + '-' + s.slice( 16, 20 ) + '-' + s.slice( 20 );
	}
	function api( path, method, body ) {
		return fetch( cfg.root + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) {
			return r.json().catch( function () { return {}; } ).then( function ( j ) {
				if ( ! r.ok ) {
					throw ( j && j.data && j.data.error ) ? { error: j.data.error, fields: j.data.fields || [] } : { error: { message: '通信に失敗しました。ログイン状態を確認して、もう一度お試しください。', error_code: 'AI-015' } };
				}
				return j;
			} );
		} );
	}
	function loadForm() {
		try {
			var f = JSON.parse( window.sessionStorage.getItem( FORM_KEY ) || 'null' );
			if ( f && f.idempotency_key ) {
				return f;
			}
		} catch ( e ) {}
		return { idempotency_key: uuid(), facts: {}, photo_set_description: '', photos: [], voice: '', staff: [ { id: '', comment: '' } ], supervisors: [], consent: false };
	}
	function saveForm() {
		try { window.sessionStorage.setItem( FORM_KEY, JSON.stringify( state.form ) ); } catch ( e ) {}
	}
	function setJob( job ) {
		state.job = job;
		try {
			if ( job && [ 'committed', 'cancelled', 'expired' ].indexOf( job.status ) === -1 ) {
				window.sessionStorage.setItem( JOB_KEY, job.job_id );
			} else {
				window.sessionStorage.removeItem( JOB_KEY );
			}
		} catch ( e ) {}
		render();
	}
	function errorBox( err, fields ) {
		if ( ! err ) {
			return null;
		}
		var list = ( fields || [] ).length ? h( 'p', { class: 'wpaic-fields', text: '確認が必要な項目：' + fields.map( fieldLabel ).join( '、' ) } ) : null;
		return h( 'div', { class: 'notice notice-error inline wpaic-error' },
			h( 'p', {}, h( 'strong', { text: err.message || '処理を完了できませんでした。' } ), ' （' + ( err.error_code || '' ) + '）' ),
			list,
			err.support_id ? h( 'p', { class: 'description', text: 'サポートID：' + err.support_id + '（お問い合わせの際にお伝えください）' } ) : null
		);
	}
	function fieldLabel( f ) {
		var m = String( f ).match( /primary_facts\.(\w+)/ );
		if ( m ) {
			return FACT_LABELS[ m[ 1 ] ] || m[ 1 ];
		}
		return { photo_set_description: '写真一式の説明', images: '写真', consent_external_ai: '外部AIへの送信の同意' }[ f ] || f;
	}
	function busy( fn ) {
		if ( state.busy ) {
			return;
		}
		state.busy = true;
		state.lastError = null;
		render();
		return Promise.resolve().then( fn ).catch( function ( e ) {
			state.lastError = e;
		} ).then( function () {
			state.busy = false;
			render();
		} );
	}

	// ---------- 画面 ----------
	function render() {
		root.textContent = '';
		if ( cfg.insecure ) {
			root.appendChild( h( 'div', { class: 'notice notice-warning inline' }, h( 'p', { text: 'このサイトは暗号化されていない通信（HTTP）です。実際のお客様の写真や個人情報は使わないでください。' } ) ) );
		}
		if ( state.lastError ) {
			root.appendChild( errorBox( state.lastError.error, state.lastError.fields ) );
		}
		var job = state.job;
		if ( ! job ) {
			root.appendChild( formView() );
		} else if ( job.status === 'proposal_ready' || job.status === 'saving' ) {
			root.appendChild( proposalView( job ) );
		} else if ( job.status === 'committed' ) {
			root.appendChild( doneView( job ) );
		} else {
			root.appendChild( progressView( job ) );
		}
	}

	function formView() {
		var f = state.form;
		var opts = state.options || { voices: [], staff: [] };
		var wrap = h( 'div', { class: 'wpaic-form' } );
		function area( key, label, required, rows ) {
			var ta = h( 'textarea', { rows: rows || 3, class: 'large-text', on: { input: function ( e ) { f.facts[ key ] = e.target.value; saveForm(); } } } );
			ta.value = f.facts[ key ] || '';
			return h( 'p', {}, h( 'label', {}, h( 'strong', { text: label } ), required ? h( 'span', { class: 'wpaic-req', text: ' 必須' } ) : null, h( 'br' ), ta ) );
		}
		function input( key, label ) {
			var el = h( 'input', { type: 'text', class: 'regular-text', on: { input: function ( e ) { f.facts[ key ] = e.target.value; saveForm(); } } } );
			el.value = f.facts[ key ] || '';
			return h( 'p', {}, h( 'label', {}, label, h( 'br' ), el ) );
		}
		wrap.appendChild( h( 'h2', { text: '1. 一次情報' } ) );
		wrap.appendChild( area( 'overview', '施工概要', true ) );
		wrap.appendChild( area( 'client_request', 'お客様のご要望', true ) );
		wrap.appendChild( area( 'work_done', '実施した工事・工夫', true ) );
		wrap.appendChild( h( 'details', {}, h( 'summary', { text: '任意の項目（地域・工期・費用など）' } ),
			h( 'p', { class: 'description', text: '分かる項目だけ入力してください。空欄の項目は記事に載せません（推測で埋めません）。' } ),
			input( 'area', '地域' ), input( 'building_type', '建物種別' ), input( 'duration', '工期' ), input( 'cost', '施工費用' ),
			input( 'materials', '使用商材・建材' ), area( 'notes', 'メモ', false, 2 ) ) );

		wrap.appendChild( h( 'h2', { text: '2. 写真' } ) );
		var desc = h( 'textarea', { rows: 3, class: 'large-text', on: { input: function ( e ) { f.photo_set_description = e.target.value; saveForm(); } } } );
		desc.value = f.photo_set_description || '';
		wrap.appendChild( h( 'p', {}, h( 'label', {}, h( 'strong', { text: '写真一式の説明' } ), h( 'span', { class: 'wpaic-req', text: ' 必須' } ), h( 'br' ), desc ) ) );
		wrap.appendChild( h( 'p', {}, h( 'button', { type: 'button', class: 'button', on: { click: pickPhotos } }, '写真を選ぶ（メディアライブラリ）' ), ' ', h( 'span', { class: 'description', text: f.photos.length + ' 枚選択中（1枚以上）' } ) ) );
		var list = h( 'ol', { class: 'wpaic-photos' } );
		f.photos.forEach( function ( p, i ) {
			var phase = h( 'select', { on: { change: function ( e ) { p.hint = e.target.value; saveForm(); } } } );
			Object.keys( PHASES ).forEach( function ( k ) { phase.appendChild( h( 'option', { value: k, selected: p.hint === k, text: PHASES[ k ] } ) ); } );
			var cap = h( 'input', { type: 'text', placeholder: 'メモ（任意）', on: { input: function ( e ) { p.caption = e.target.value; saveForm(); } } } );
			cap.value = p.caption || '';
			list.appendChild( h( 'li', {},
				h( 'img', { src: p.thumb, alt: '', width: 80, height: 80 } ), phase, cap,
				h( 'button', { type: 'button', class: 'button-link', disabled: i === 0, on: { click: function () { move( f.photos, i, -1 ); } } }, '↑' ),
				h( 'button', { type: 'button', class: 'button-link', disabled: i === f.photos.length - 1, on: { click: function () { move( f.photos, i, 1 ); } } }, '↓' ),
				h( 'button', { type: 'button', class: 'button-link wpaic-danger', on: { click: function () { f.photos.splice( i, 1 ); saveForm(); render(); } } }, '外す' ) ) );
		} );
		wrap.appendChild( list );

		wrap.appendChild( h( 'h2', { text: '3. 関連付け（任意）' } ) );
		var voice = h( 'select', { on: { change: function ( e ) { f.voice = e.target.value; saveForm(); } } }, h( 'option', { value: '', text: '（紐付けなし）' } ) );
		opts.voices.forEach( function ( v ) { voice.appendChild( h( 'option', { value: v.id, selected: String( f.voice ) === String( v.id ), text: v.title } ) ); } );
		wrap.appendChild( h( 'p', {}, h( 'label', {}, 'お客様の声', h( 'br' ), voice ) ) );
		f.staff.forEach( function ( s, i ) {
			var sel = h( 'select', { on: { change: function ( e ) { s.id = e.target.value; saveForm(); } } }, h( 'option', { value: '', text: '（紐付けなし）' } ) );
			opts.staff.forEach( function ( v ) { sel.appendChild( h( 'option', { value: v.id, selected: String( s.id ) === String( v.id ), text: v.title } ) ); } );
			var cm = h( 'input', { type: 'text', class: 'regular-text', placeholder: '担当者のコメント', on: { input: function ( e ) { s.comment = e.target.value; saveForm(); } } } );
			cm.value = s.comment || '';
			wrap.appendChild( h( 'p', {}, h( 'label', {}, '担当スタッフ ' + ( i + 1 ), h( 'br' ), sel, ' ', cm ) ) );
		} );
		if ( f.staff.length < 3 ) {
			wrap.appendChild( h( 'p', {}, h( 'button', { type: 'button', class: 'button-link', on: { click: function () { f.staff.push( { id: '', comment: '' } ); saveForm(); render(); } } }, '＋ 担当スタッフを追加' ) ) );
		}
		var sup = h( 'select', { multiple: true, size: Math.min( 5, Math.max( 2, opts.staff.length ) ), on: { change: function ( e ) { f.supervisors = Array.prototype.filter.call( e.target.options, function ( o ) { return o.selected; } ).map( function ( o ) { return o.value; } ); saveForm(); } } } );
		opts.staff.forEach( function ( v ) { sup.appendChild( h( 'option', { value: v.id, selected: f.supervisors.indexOf( String( v.id ) ) !== -1, text: v.title } ) ); } );
		wrap.appendChild( h( 'p', {}, h( 'label', {}, '監修者（複数選択可）', h( 'br' ), sup ) ) );

		wrap.appendChild( h( 'h2', { text: '4. 外部AIへの送信' } ) );
		wrap.appendChild( h( 'div', { class: 'wpaic-consent' },
			h( 'p', { text: '「AIで下書きを作成」を押すと、次の内容を Anthropic 社の AI（Claude）へ送信します。' } ),
			h( 'ul', {}, h( 'li', { text: '入力した一次情報と写真一式の説明' } ), h( 'li', { text: '選んだ写真のコピー（縮小し、撮影場所などの情報を取り除いたもの）' } ) ),
			h( 'p', { text: '写真に人の顔・表札・住所・車のナンバーなどが写っていないか確認してください。元の写真は変更しません。' } ),
			h( 'label', {}, h( 'input', { type: 'checkbox', checked: !! f.consent, on: { change: function ( e ) { f.consent = e.target.checked; saveForm(); render(); } } } ), ' 上記を確認し、外部AIへの送信に同意します' ) ) );
		var available = root.getAttribute( 'data-available' ) === '1' && ( ! state.options || state.options.available );
		wrap.appendChild( h( 'p', { class: 'wpaic-actions' },
			h( 'button', { type: 'button', class: 'button button-primary button-hero', disabled: ! available || ! f.consent || state.busy, on: { click: submit } }, state.busy ? '送信中…' : 'AIで下書きを作成' ),
			' ', h( 'button', { type: 'button', class: 'button-link', on: { click: resetForm } }, '入力をすべて消す' ) ) );
		if ( ! available && state.options && state.options.error ) {
			wrap.appendChild( errorBox( state.options.error ) );
		}
		return wrap;
	}

	function move( arr, i, d ) {
		var t = arr[ i ];
		arr[ i ] = arr[ i + d ];
		arr[ i + d ] = t;
		saveForm();
		render();
	}
	function pickPhotos() {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var frame = window.wp.media( { title: '写真を選ぶ', library: { type: 'image' }, multiple: true, button: { text: '追加' } } );
		frame.on( 'select', function () {
			frame.state().get( 'selection' ).toJSON().forEach( function ( a ) {
				if ( state.form.photos.some( function ( p ) { return p.id === a.id; } ) ) {
					return;
				}
				var thumb = ( a.sizes && ( a.sizes.thumbnail || a.sizes.medium || a.sizes.full ) || {} ).url || a.url;
				state.form.photos.push( { id: a.id, thumb: thumb, caption: '', hint: 'unknown' } );
			} );
			saveForm();
			render();
		} );
		frame.open();
	}
	function resetForm() {
		if ( ! window.confirm( '入力した内容と選んだ写真をすべて消しますか？' ) ) {
			return;
		}
		try { window.sessionStorage.removeItem( FORM_KEY ); } catch ( e ) {}
		state.form = loadForm();
		render();
	}
	function submit() {
		var f = state.form;
		var body = {
			client_case_id: 'wpaic-' + f.idempotency_key.slice( 0, 8 ),
			idempotency_key: f.idempotency_key,
			mode: 'create',
			post_type: 'works',
			primary_facts: f.facts,
			photo_set_description: f.photo_set_description,
			images: f.photos.map( function ( p, i ) { return { attachment_id: p.id, original_position: i, caption: p.caption || '', user_phase_hint: p.hint || 'unknown' }; } ),
			consent_external_ai: !! f.consent,
			relations: {
				customer_voice_post_ids: f.voice ? [ Number( f.voice ) ] : [],
				staff: f.staff.filter( function ( s ) { return s.id; } ).map( function ( s ) { return { post_id: Number( s.id ), comment: s.comment || '' }; } ),
				supervisor_post_ids: f.supervisors.map( Number )
			}
		};
		busy( function () {
			return api( 'jobs', 'POST', body ).then( function ( job ) {
				setJob( job );
				return pump();
			} );
		} );
	}

	// サーバーで1ステップずつ進める（外部呼び出しは1回のリクエストで最大1回）。
	function pump() {
		var job = state.job;
		if ( ! job || [ 'queued', 'analyzing' ].indexOf( job.status ) === -1 ) {
			return Promise.resolve();
		}
		return api( 'jobs/' + job.job_id + '/tick', 'POST', {} ).then( function ( j ) {
			setJob( j );
			if ( [ 'queued', 'analyzing' ].indexOf( j.status ) !== -1 ) {
				return new Promise( function ( r ) { window.setTimeout( r, 1500 ); } ).then( pump );
			}
		} );
	}

	function progressView( job ) {
		var box = h( 'div', { class: 'wpaic-progress' }, h( 'h2', { text: STATUS_TEXT[ job.status ] || job.status } ) );
		if ( job.status === 'queued' || job.status === 'analyzing' ) {
			box.appendChild( h( 'p', {}, h( 'span', { class: 'spinner is-active wpaic-spin' } ), ' 進行状況：' + job.progress.step + ' / ' + job.progress.total + '（画面を閉じても、戻ってくれば続きから再開できます）' ) );
			if ( ! state.busy ) {
				box.appendChild( h( 'p', {}, h( 'button', { type: 'button', class: 'button', on: { click: function () { busy( pump ); } } }, '処理を続ける' ) ) );
			}
		}
		if ( job.error ) {
			box.appendChild( errorBox( job.error ) );
		}
		if ( job.status === 'needs_recovery' ) {
			box.appendChild( h( 'p', { text: '前回の送信が途中で止まったため、AIが処理したかどうかを確認できませんでした。もう一度送信すると、料金が再度かかる可能性があります。' } ) );
		}
		var acts = h( 'p', { class: 'wpaic-actions' } );
		if ( job.status === 'failed' || job.status === 'needs_recovery' ) {
			acts.appendChild( h( 'button', { type: 'button', class: 'button button-primary', disabled: state.busy, on: { click: function () {
				if ( job.status === 'needs_recovery' && ! window.confirm( 'もう一度送信します。料金が再度かかる可能性があります。よろしいですか？' ) ) {
					return;
				}
				busy( function () { return api( 'jobs/' + job.job_id + '/retry', 'POST', {} ).then( function ( j ) { setJob( j ); return pump(); } ); } );
			} } }, '同じ内容で再試行' ) );
			acts.appendChild( document.createTextNode( ' ' ) );
		}
		if ( [ 'queued', 'analyzing', 'failed', 'needs_recovery' ].indexOf( job.status ) !== -1 ) {
			acts.appendChild( h( 'button', { type: 'button', class: 'button', disabled: state.busy && job.status !== 'analyzing', on: { click: cancelJob } }, '取り消して入力に戻る' ) );
		} else {
			acts.appendChild( h( 'button', { type: 'button', class: 'button', on: { click: function () { setJob( null ); } } }, '入力に戻る' ) );
		}
		box.appendChild( acts );
		return box;
	}
	function cancelJob() {
		var job = state.job;
		state.busy = false;
		api( 'jobs/' + job.job_id + '/cancel', 'POST', {} ).then( function () { setJob( null ); } ).catch( function ( e ) { state.lastError = e; render(); } );
	}

	function revise( body ) {
		var job = state.job;
		body.base_index = job.version_index;
		return busy( function () { return api( 'jobs/' + job.job_id + '/revise', 'POST', body ).then( setJob ); } );
	}
	function op( section, operation ) {
		operation.section = section;
		revise( { allowed_sections: [ section ], operations: [ operation ] } );
	}

	function proposalView( job ) {
		var doc = job.proposal;
		var wrap = h( 'div', { class: 'wpaic-proposal' } );
		var head = h( 'div', { class: 'wpaic-toolbar' },
			h( 'strong', { text: '下書き案（版 ' + ( job.version_index + 1 ) + ' / ' + job.version_count + '）' } ), ' ',
			h( 'button', { type: 'button', class: 'button', disabled: state.busy || job.version_index <= 0, on: { click: function () { busy( function () { return api( 'jobs/' + job.job_id + '/undo', 'POST', { base_index: job.version_index } ).then( setJob ); } ); } } }, '元に戻す' ), ' ',
			h( 'button', { type: 'button', class: 'button', disabled: state.busy || job.version_index >= job.version_count - 1, on: { click: function () { busy( function () { return api( 'jobs/' + job.job_id + '/redo', 'POST', { base_index: job.version_index } ).then( setJob ); } ); } } }, 'やり直す' ), ' ',
			h( 'span', { class: 'description', text: 'まだ公開されていません。保存しても「下書き」になります。' } ) );
		wrap.appendChild( head );
		if ( job.error ) {
			wrap.appendChild( errorBox( job.error ) );
		}
		if ( job.questions && job.questions.length ) {
			wrap.appendChild( h( 'div', { class: 'notice notice-info inline' }, h( 'p', { text: 'AIから確認したいこと（必要なら下の項目に追記してください）：' } ), h( 'ul', {}, job.questions.map( function ( q ) { return h( 'li', { text: q } ); } ) ) ) );
		}

		function textBlock( label, value, onApply, multi ) {
			var el = h( multi ? 'textarea' : 'input', multi ? { rows: 3, class: 'large-text' } : { type: 'text', class: 'large-text' } );
			el.value = value || '';
			return h( 'div', { class: 'wpaic-field' }, h( 'label', {}, h( 'strong', { text: label } ), h( 'br' ), el ),
				h( 'button', { type: 'button', class: 'button button-small', disabled: state.busy, on: { click: function () { onApply( el.value ); } } }, '反映' ) );
		}
		var intro = doc.sections.filter( function ( s ) { return s.node_id === 'intro'; } )[ 0 ] || { heading: '', body: '' };
		wrap.appendChild( h( 'h2', { text: '基本' } ) );
		wrap.appendChild( textBlock( 'タイトル', doc.title, function ( v ) { op( 'title', { op: 'text_set', value: v } ); } ) );
		wrap.appendChild( textBlock( 'コンセプト（見出し）', intro.heading, function ( v ) { op( 'concept', { op: 'text_set', value: v } ); } ) );
		wrap.appendChild( textBlock( 'コンセプト説明', doc.summary, function ( v ) { op( 'summary', { op: 'text_set', value: v } ); }, true ) );

		wrap.appendChild( h( 'h2', { text: '施工概要（入力された事実だけを載せます）' } ) );
		var tbl = h( 'table', { class: 'widefat striped wpaic-facts' } );
		Object.keys( FACT_LABELS ).forEach( function ( k ) {
			var f = doc.facts[ k ];
			var el = h( 'input', { type: 'text', class: 'regular-text' } );
			el.value = f && f.value ? f.value : '';
			tbl.appendChild( h( 'tr', {}, h( 'th', { text: FACT_LABELS[ k ] } ), h( 'td', {}, el, ' ',
				h( 'button', { type: 'button', class: 'button button-small', disabled: state.busy, on: { click: function () { op( 'facts', { op: 'fact_set', fact_key: k, value: el.value, source: 'user_input' } ); } } }, '反映' ) ) ) );
		} );
		wrap.appendChild( tbl );

		wrap.appendChild( h( 'h2', { text: '本文' } ) );
		doc.sections.forEach( function ( s ) {
			if ( s.node_id === 'intro' ) {
				return;
			}
			var hd = h( 'input', { type: 'text', class: 'large-text' } );
			hd.value = s.heading;
			var bd = h( 'textarea', { rows: 3, class: 'large-text' } );
			bd.value = s.body;
			var thumbs = h( 'div', { class: 'wpaic-thumbs' }, ( s.image_ids || [] ).map( function ( id ) { return job.images[ id ] ? h( 'img', { src: job.images[ id ].thumb, alt: '', width: 64, height: 64 } ) : null; } ) );
			wrap.appendChild( h( 'div', { class: 'wpaic-section' }, h( 'span', { class: 'wpaic-kind', text: s.kind } ), hd, bd, thumbs,
				h( 'button', { type: 'button', class: 'button button-small', disabled: state.busy, on: { click: function () { op( 'body', { op: 'body_section_set', node_id: s.node_id, heading: hd.value, body: bd.value } ); } } }, '反映' ) ) );
		} );

		wrap.appendChild( h( 'h2', { text: '写真' } ) );
		var order = doc.images.map( function ( i ) { return i.attachment_id; } );
		var grid = h( 'div', { class: 'wpaic-grid' } );
		doc.images.forEach( function ( img, i ) {
			var phase = h( 'select', { disabled: state.busy, on: { change: function ( e ) { op( 'before_after', { op: 'image_phase_set', attachment_id: img.attachment_id, phase: e.target.value } ); } } } );
			Object.keys( PHASES ).forEach( function ( k ) { phase.appendChild( h( 'option', { value: k, selected: img.phase === k, text: k === 'unknown' ? '不明' : PHASES[ k ] } ) ); } );
			var cap = h( 'input', { type: 'text', placeholder: 'キャプション' } );
			cap.value = img.caption || '';
			grid.appendChild( h( 'div', { class: 'wpaic-card' + ( img.included ? '' : ' is-excluded' ) },
				job.images[ img.attachment_id ] ? h( 'img', { src: job.images[ img.attachment_id ].medium || job.images[ img.attachment_id ].thumb, alt: '' } ) : null,
				h( 'p', { class: 'description', text: img.description + ( img.uncertain ? '（工程は推定）' : '' ) } ),
				phase, cap,
				h( 'button', { type: 'button', class: 'button button-small', disabled: state.busy, on: { click: function () { op( 'photos', { op: 'image_caption_set', attachment_id: img.attachment_id, caption: cap.value } ); } } }, 'キャプション反映' ),
				h( 'label', {}, h( 'input', { type: 'checkbox', checked: !! img.included, disabled: state.busy, on: { change: function ( e ) { op( 'photos', { op: 'image_visibility_set', attachment_id: img.attachment_id, included: e.target.checked } ); } } } ), ' 記事に使う' ),
				h( 'span', {},
					h( 'button', { type: 'button', class: 'button-link', disabled: state.busy || i === 0, on: { click: function () { var o = order.slice(); o.splice( i - 1, 0, o.splice( i, 1 )[ 0 ] ); op( 'photos', { op: 'image_order_set', attachment_ids: o } ); } } }, '← 前へ' ), ' ',
					h( 'button', { type: 'button', class: 'button-link', disabled: state.busy || i === doc.images.length - 1, on: { click: function () { var o = order.slice(); o.splice( i + 1, 0, o.splice( i, 1 )[ 0 ] ); op( 'photos', { op: 'image_order_set', attachment_ids: o } ); } } }, '次へ →' ) ) ) );
		} );
		wrap.appendChild( grid );

		var hero = h( 'select', { disabled: state.busy, on: { change: function ( e ) { op( 'layout', { op: 'layout_set', hero: e.target.value } ); } } } );
		[ [ 'auto', '自動' ], [ 'before_after', 'ビフォーアフター' ], [ 'gallery', 'ギャラリー' ], [ 'single', '1枚' ] ].forEach( function ( o ) { hero.appendChild( h( 'option', { value: o[ 0 ], selected: doc.layout_intent.hero === o[ 0 ], text: o[ 1 ] } ) ); } );
		wrap.appendChild( h( 'p', {}, h( 'label', {}, h( 'strong', { text: 'トップの写真レイアウト ' } ), hero ) ) );

		wrap.appendChild( h( 'h2', { text: 'SEO' } ) );
		[ [ 'title', 'タイトル' ], [ 'description', '説明文' ], [ 'h1', 'H1' ], [ 'keywords', 'キーワード' ] ].forEach( function ( x ) {
			wrap.appendChild( textBlock( 'SEO：' + x[ 1 ], ( doc.seo || {} )[ x[ 0 ] ], function ( v ) { op( 'seo', { op: 'seo_set', field: x[ 0 ], value: v } ); } ) );
		} );

		// AIによる修正（選んだ部分だけ）
		var checks = h( 'div', { class: 'wpaic-checks' } );
		var aiSections = [ 'title', 'concept', 'summary', 'body', 'client_request', 'proposal', 'photos', 'before_after', 'layout', 'seo' ];
		var all = h( 'input', { type: 'checkbox', on: { change: function ( e ) { Array.prototype.forEach.call( checks.querySelectorAll( 'input[data-sec]' ), function ( c ) { c.checked = e.target.checked; } ); } } } );
		checks.appendChild( h( 'label', {}, all, ' すべて' ) );
		aiSections.forEach( function ( s ) { checks.appendChild( h( 'label', {}, h( 'input', { type: 'checkbox', 'data-sec': s } ), ' ' + SECTION_LABELS[ s ] ) ); } );
		var instr = h( 'textarea', { rows: 3, class: 'large-text', placeholder: '例：タイトルをもう少し短く、本文はやわらかい言い方にしてください' } );
		wrap.appendChild( h( 'div', { class: 'wpaic-ai' }, h( 'h2', { text: 'AIで修正する（選んだ部分だけ）' } ), checks, instr,
			h( 'p', {}, h( 'button', { type: 'button', class: 'button', disabled: state.busy, on: { click: function () {
				var secs = Array.prototype.filter.call( checks.querySelectorAll( 'input[data-sec]' ), function ( c ) { return c.checked; } ).map( function ( c ) { return c.getAttribute( 'data-sec' ); } );
				if ( ! secs.length || ! instr.value.trim() ) {
					window.alert( '修正する部分と、修正の指示を入力してください。' );
					return;
				}
				revise( { allowed_sections: secs, instruction: instr.value } );
			} } }, state.busy ? '処理中…' : 'AIで修正' ) ) ) );

		wrap.appendChild( h( 'p', { class: 'wpaic-actions' },
			h( 'button', { type: 'button', class: 'button button-primary button-hero', disabled: state.busy, on: { click: function () {
				busy( function () { return api( 'jobs/' + job.job_id + '/save', 'POST', { base_index: job.version_index } ).then( function ( j ) {
					try { window.sessionStorage.removeItem( FORM_KEY ); } catch ( e ) {}
					state.form = loadForm();
					setJob( j );
				} ); } );
			} } }, state.busy ? '処理中…' : '下書きとして保存' ), ' ',
			h( 'button', { type: 'button', class: 'button', disabled: state.busy, on: { click: cancelJob } }, '取り消す' ) ) );
		return wrap;
	}

	function doneView( job ) {
		return h( 'div', { class: 'wpaic-done' },
			h( 'div', { class: 'notice notice-success inline' }, h( 'p', { text: '施工事例の下書きとして保存しました（まだ公開されていません）。' } ) ),
			h( 'p', {},
				job.edit_url ? h( 'a', { class: 'button button-primary', href: job.edit_url }, '編集画面で開く' ) : null, ' ',
				job.preview_url ? h( 'a', { class: 'button', href: job.preview_url, target: '_blank', rel: 'noopener' }, 'プレビュー' ) : null, ' ',
				h( 'button', { type: 'button', class: 'button-link', on: { click: function () { setJob( null ); } } }, '別の施工事例を作成' ) ) );
	}

	// ---------- 起動 ----------
	api( 'form-options' ).then( function ( o ) { state.options = o; } ).catch( function ( e ) { state.lastError = e; } ).then( function () {
		var saved = null;
		try { saved = window.sessionStorage.getItem( JOB_KEY ); } catch ( e ) {}
		if ( ! saved ) {
			render();
			return;
		}
		return api( 'jobs/' + saved ).then( function ( j ) { setJob( j ); return busy( pump ); } ).catch( function () {
			try { window.sessionStorage.removeItem( JOB_KEY ); } catch ( e ) {}
			render();
		} );
	} );
}() );
