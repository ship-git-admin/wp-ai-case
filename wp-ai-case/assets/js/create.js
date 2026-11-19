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
	var PROVIDER = cfg.providerLabel || 'AI サービス';
	var JOB_KEY = 'wpaic_job_v1';
	var MODE_KEY = 'wpaic_mode_v1';
	var PHASES = { unknown: '未指定', before: '施工前', during: '施工中', after: '施工後' };
	var KIND_LABELS = {
		introduction: '導入', before: '施工前', during: '施工中', after: '施工後', comparison: '比較（ビフォーアフター）',
		feature: '特徴', summary: 'まとめ', custom: 'その他'
	};
	var SECTION_LABELS = {
		title: 'タイトル', concept: 'コンセプト（見出し）', summary: 'コンセプト説明', body: '本文（各セクション）',
		client_request: 'お客様のご要望', proposal: 'ご提案（実施した工事）', facts: '施工概要の項目',
		photos: '写真（キャプション）', before_after: '写真の工程', layout: 'トップの写真レイアウト', seo: 'SEO',
		staff: '担当スタッフのコメント'
	};
	var FACT_LABELS = {
		overview: '施工概要', client_request: 'お客様のご要望', work_done: '実施した工事・工夫', area: '地域',
		building_type: '建物種別', building_age: '築年数', parts: '施工箇所', work_type: '施工内容（短い一覧）',
		duration: '工期', cost: '施工費用', materials: '使用商材・建材', notes: 'メモ',
		floor_plan: '間取り', site_area: '敷地面積', customer_voice: 'お客様からの声'
	};
	// 下書き案の「施工概要」の表に出す項目（ご要望・ご提案・お客様の声は別の欄で直す）。
	var OUTLINE_KEYS = [ 'area', 'building_type', 'building_age', 'parts', 'work_type', 'duration', 'cost', 'materials', 'floor_plan', 'site_area', 'overview', 'notes' ];
	var PROSE_NODES = [ 'request', 'proposal' ];
	var STATUS_TEXT = {
		queued: '順番待ちです', analyzing: '写真と入力内容を解析しています', proposal_ready: '下書き案ができました',
		saving: '保存しています', committed: '下書きとして保存しました', cancelled: '取り消しました',
		expired: '有効期限が切れました', failed: '処理を完了できませんでした', needs_recovery: '送信結果を確認できませんでした'
	};

	var state = { options: null, job: null, busy: false, form: loadForm(), drafts: {}, mode: loadMode(), draftList: null, reviseConsent: false };

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
					throw ( j && j.data && j.data.error ) ? { error: j.data.error, fields: j.data.fields || [], cost: j.data.cost || null } : { error: { message: '通信に失敗しました。ログイン状態を確認して、もう一度お試しください。', error_code: 'AI-015' } };
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
		return { idempotency_key: uuid(), facts: {}, photo_set_description: '', photos: [], voice: '', staff: [ { id: '', comment: '' } ], supervisors: [], consent: false, policy: '', terms: [], importText: '' };
	}
	function loadMode() {
		try { return window.sessionStorage.getItem( MODE_KEY ) === 'revise' ? 'revise' : 'create'; } catch ( e ) { return 'create'; }
	}
	function setMode( m ) {
		state.mode = m;
		state.lastError = null;
		try { window.sessionStorage.setItem( MODE_KEY, m ); } catch ( e ) {}
		render();
	}
	function saveForm() {
		try { window.sessionStorage.setItem( FORM_KEY, JSON.stringify( state.form ) ); } catch ( e ) {}
	}
	function setJob( job ) {
		// 「反映」していない入力は、同じジョブの提案中だけ保持する（再描画で消さない）。
		if ( ! job || ! state.job || job.job_id !== state.job.job_id || job.status !== 'proposal_ready' ) {
			state.drafts = {};
		}
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
	// ---------- 費用の表示（管理者だけ・一時的な機能） ----------
	var COST_KIND = { create: '下書きの作成', analyze: '写真の解析（分割）', compose: '下書きの作成（分割後）', revise: 'AIで修正', 'import': '既存ページの取り込み' };
	function money( usd, rate ) {
		var yen = usd * ( rate || 150 );
		return '$' + usd.toFixed( 4 ) + '（約' + ( yen < 1 ? yen.toFixed( 2 ) : yen.toFixed( 1 ) ) + '円）';
	}
	function costLine( entry, rate ) {
		if ( ! entry ) {
			return '';
		}
		return ( COST_KIND[ entry.kind ] || entry.kind ) + '：' + money( entry.usd, rate ) +
			'・入力 ' + ( entry.input_tokens + entry.cache_read_tokens ) + ' / 出力 ' + entry.output_tokens + ' トークン' + ( entry.ok ? '' : '（失敗。費用はかかっています）' );
	}
	function costBox( cost ) {
		if ( ! cost || ! cost.calls ) {
			return null;
		}
		return h( 'div', { class: 'notice notice-info inline wpaic-cost' },
			h( 'p', {}, h( 'strong', { text: 'AIの費用（推定・管理者にだけ表示）' } ) ),
			h( 'p', { text: '直近：' + costLine( cost.last, cost.jpy_per_usd ) } ),
			h( 'p', { text: 'この下書きの合計：' + money( cost.total_usd, cost.jpy_per_usd ) + '（' + cost.calls + '回）。円は1ドル=' + cost.jpy_per_usd + '円で計算した目安です。' } ) );
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
		return { photo_set_description: '写真一式の説明', images: '写真', consent_external_ai: '外部AIへの送信の同意', terms: '施工事例カテゴリ', thumbnail_id: 'アイキャッチ画像' }[ f ] || f;
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
			if ( state.lastError.cost ) {
				root.appendChild( h( 'div', { class: 'notice notice-info inline wpaic-cost' }, h( 'p', { text: 'この操作のAIの費用（推定・管理者にだけ表示）：' + costLine( state.lastError.cost, state.job && state.job.cost ? state.job.cost.jpy_per_usd : 150 ) } ) ) );
			}
		}
		var job = state.job;
		if ( ! job ) {
			root.appendChild( modeTabs() );
			root.appendChild( state.mode === 'revise' ? reviseListView() : formView() );
		} else if ( job.status === 'proposal_ready' || job.status === 'saving' ) {
			root.appendChild( proposalView( job ) );
		} else if ( job.status === 'committed' ) {
			root.appendChild( doneView( job ) );
		} else {
			root.appendChild( progressView( job ) );
		}
	}

	// ---------- 新規作成／既存の下書きの修正 の切り替え ----------
	function modeTabs() {
		function tab( m, label ) {
			return h( 'button', { type: 'button', class: 'button' + ( state.mode === m ? ' button-primary' : '' ), 'aria-pressed': state.mode === m ? 'true' : 'false', disabled: state.busy, on: { click: function () { setMode( m ); } } }, label );
		}
		return h( 'p', { class: 'wpaic-modes', role: 'group', 'aria-label': '作業の種類' }, tab( 'create', '新規作成' ), ' ', tab( 'revise', '既存の下書きを修正' ) );
	}

	function reviseListView() {
		var wrap = h( 'div', { class: 'wpaic-revise' } );
		wrap.appendChild( h( 'h2', { text: '修正する下書きを選ぶ' } ) );
		wrap.appendChild( h( 'p', { class: 'description', text: 'この画面で保存した施工事例の下書きだけを修正できます。公開済み・予約・非公開の記事は対象外です。' } ) );
		if ( state.draftList === null ) {
			wrap.appendChild( h( 'p', {}, h( 'span', { class: 'spinner is-active wpaic-spin' } ), ' 読み込み中…' ) );
			api( 'drafts' ).then( function ( r ) { state.draftList = r.drafts || []; } ).catch( function ( e ) { state.draftList = []; state.lastError = e; } ).then( render );
			return wrap;
		}
		wrap.appendChild( h( 'div', { class: 'wpaic-consent' },
			h( 'p', { text: '修正の画面で「AIで修正」を押すと、選んだ部分の文章と写真の説明（写真そのものは送りません）を ' + PROVIDER + ' へ送信します。画面での手直しだけなら外部へは送信しません。' } ),
			h( 'label', {}, h( 'input', { type: 'checkbox', checked: state.reviseConsent, on: { change: function ( e ) { state.reviseConsent = e.target.checked; render(); } } } ), ' 上記を確認し、外部AIへの送信に同意します' ) ) );
		if ( ! state.draftList.length ) {
			wrap.appendChild( h( 'p', { text: '修正できる下書きはありません（編集画面で作った下書きは、ここでは修正できません）。' } ) );
			return wrap;
		}
		var list = h( 'ul', { class: 'wpaic-draft-list' } );
		state.draftList.forEach( function ( d ) {
			var notes = [];
			if ( d.edited_in_editor ) {
				notes.push( h( 'li', { text: '保存後に編集画面で更新されています（編集画面で変えた部分に重なる修正は保存できません）' } ) );
			}
			if ( d.was_published ) {
				notes.push( h( 'li', { text: '一度公開された可能性があります。下書きの間は公開ページは表示されません' } ) );
			}
			list.appendChild( h( 'li', { class: 'wpaic-draft' },
				h( 'div', {}, h( 'strong', { text: d.title || '（タイトルなし）' } ), h( 'br' ), h( 'span', { class: 'description', text: '更新：' + d.modified + '　版 ' + d.version } ),
					notes.length ? h( 'ul', { class: 'wpaic-draft-notes' }, notes ) : null ),
				h( 'p', {},
					h( 'button', { type: 'button', class: 'button button-primary', disabled: ! state.reviseConsent || state.busy, on: { click: function () { openDraft( d ); } } }, 'この下書きを修正' ), ' ',
					d.edit_url ? h( 'a', { class: 'button-link', href: d.edit_url }, '編集画面' ) : null ) ) );
		} );
		wrap.appendChild( list );
		return wrap;
	}
	function openDraft( d ) {
		busy( function () {
			return api( 'jobs', 'POST', {
				client_case_id: 'wpaic-rev-' + d.post_id,
				idempotency_key: uuid(),
				mode: 'revise_draft',
				post_type: 'works',
				post_id: d.post_id,
				base_version: d.version,
				consent_external_ai: true
			} ).then( setJob ).catch( function ( e ) {
				state.draftList = null; // 版が進んでいた等。一覧を読み直す
				throw e;
			} );
		} );
	}

	function formView() {
		var f = state.form;
		f.terms = f.terms || [];
		var opts = state.options || { voices: [], staff: [], categories: [] };
		var wrap = h( 'div', { class: 'wpaic-form' } );
		wrap.appendChild( importView( f ) );
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
		wrap.appendChild( area( 'customer_voice', 'お客様からの声（任意）', false, 4 ) );
		wrap.appendChild( h( 'p', { class: 'description', text: 'お客様からの声は、入力した文章をそのまま載せます（AIは書き換えません。空欄なら載せません）。' } ) );
		wrap.appendChild( h( 'details', { open: [ 'area', 'building_type', 'building_age', 'parts', 'work_type', 'duration', 'cost', 'materials', 'notes' ].some( function ( k ) { return f.facts[ k ]; } ) },
			h( 'summary', { text: '任意の項目（地域・築年数・施工箇所・工期・費用など）' } ),
			h( 'p', { class: 'description', text: '分かる項目だけ入力してください。空欄の項目は記事に載せません（推測で埋めません。施工内容の一覧だけは、空欄ならAIが入力から案を作ります）。' } ),
			input( 'area', '地域（例：〇〇市）' ), input( 'building_type', '建物種別' ), input( 'building_age', '築年数（例：築25年）' ), input( 'parts', '施工箇所（例：外壁・屋根）' ),
			input( 'work_type', '施工内容（短い一覧。例：外壁塗装・下地補修）' ), input( 'duration', '工期' ), input( 'cost', '施工費用' ),
			input( 'materials', '使用商材・建材' ), area( 'notes', 'メモ', false, 2 ) ) );
		var policy = h( 'textarea', { rows: 3, class: 'large-text', maxlength: 1000, placeholder: '例：お客様の不安に寄り添う、やわらかい文体で。下地補修の丁寧さを強調してください。', on: { input: function ( e ) { f.policy = e.target.value; saveForm(); } } } );
		policy.value = f.policy || '';
		wrap.appendChild( h( 'p', {}, h( 'label', {}, h( 'strong', { text: '記事の方針（任意）' } ), h( 'br' ), policy ) ) );
		wrap.appendChild( h( 'p', { class: 'description', text: '文体・強調したい点・構成の希望を書いてください（1000文字まで）。事実を足す・推測で書くといった指示は守られません。' } ) );

		wrap.appendChild( h( 'h2', { text: '2. 写真' } ) );
		var desc = h( 'textarea', { rows: 3, class: 'large-text', on: { input: function ( e ) { f.photo_set_description = e.target.value; saveForm(); } } } );
		desc.value = f.photo_set_description || '';
		wrap.appendChild( h( 'p', {}, h( 'label', {}, h( 'strong', { text: '写真一式の説明' } ), h( 'span', { class: 'description', text: ' 任意' } ), h( 'br' ), desc ) ) );
		wrap.appendChild( h( 'p', { class: 'description', text: '写真全体について伝えたいこと（撮影の順番・写っている場所など）。各写真のメモで足りていれば空欄で構いません。' } ) );
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
			var cm = h( 'input', { type: 'text', class: 'regular-text', placeholder: '担当者のコメント（空欄ならAIが下書きします）', on: { input: function ( e ) { s.comment = e.target.value; saveForm(); } } } );
			cm.value = s.comment || '';
			wrap.appendChild( h( 'p', {}, h( 'label', {}, '担当スタッフ ' + ( i + 1 ), h( 'br' ), sel, ' ', cm ) ) );
		} );
		if ( f.staff.length < 3 ) {
			wrap.appendChild( h( 'p', {}, h( 'button', { type: 'button', class: 'button-link', on: { click: function () { f.staff.push( { id: '', comment: '' } ); saveForm(); render(); } } }, '＋ 担当スタッフを追加' ) ) );
		}
		var sup = h( 'select', { multiple: true, size: Math.min( 5, Math.max( 2, opts.staff.length ) ), on: { change: function ( e ) { f.supervisors = Array.prototype.filter.call( e.target.options, function ( o ) { return o.selected; } ).map( function ( o ) { return o.value; } ); saveForm(); } } } );
		opts.staff.forEach( function ( v ) { sup.appendChild( h( 'option', { value: v.id, selected: f.supervisors.indexOf( String( v.id ) ) !== -1, text: v.title } ) ); } );
		wrap.appendChild( h( 'p', {}, h( 'label', {}, '監修者（複数選択可）', h( 'br' ), sup ) ) );

		if ( ( opts.categories || [] ).length ) {
			wrap.appendChild( h( 'h2', { text: '4. 施工事例カテゴリ（任意）' } ) );
			wrap.appendChild( h( 'p', { class: 'description', text: '選ばなければ、AIが一覧から合うものを選びます（保存の前に変えられます）。' } ) );
			wrap.appendChild( categoryChecks( opts.categories, f.terms, false, function ( c, on ) {
				f.terms = f.terms.filter( function ( t ) { return t !== c.id; } );
				if ( on ) {
					f.terms.push( c.id );
				}
				saveForm();
			} ) );
		}

		wrap.appendChild( h( 'h2', { text: ( ( opts.categories || [] ).length ? '5' : '4' ) + '. 外部AIへの送信' } ) );
		wrap.appendChild( h( 'div', { class: 'wpaic-consent' },
			h( 'p', { text: '「AIで下書きを作成」を押すと、次の内容を ' + PROVIDER + ' へ送信します。' } ),
			h( 'ul', {}, h( 'li', { text: '入力した一次情報・お客様からの声・記事の方針・写真一式の説明' } ), h( 'li', { text: '選んだ写真のコピー（縮小し、撮影場所などの情報を取り除いたもの）' } ) ),
			h( 'p', { text: '写真に人の顔・表札・住所・車のナンバーなどが写っていないか確認してください。元の写真は変更しません。' } ),
			h( 'label', {}, h( 'input', { type: 'checkbox', checked: !! f.consent, on: { change: function ( e ) { f.consent = e.target.checked; saveForm(); render(); } } } ), ' 上記を確認し、外部AIへの送信に同意します' ) ) );
		var available = root.getAttribute( 'data-available' ) === '1' && ( ! state.options || state.options.available );
		var quota = state.options && state.options.quota;
		var noQuota = !! quota && quota.remaining < 1;
		if ( quota ) {
			wrap.appendChild( h( 'p', { class: noQuota ? 'wpaic-danger' : 'description', text: noQuota
				? '今月の新規作成枠（' + quota.limit + '件）を使い切りました。' + quota.resets_on + ' から作成できます。既存の下書きの修正はできます。（AI-013）'
				: '今月の新規作成：残り ' + quota.remaining + ' 件（' + quota.limit + '件中）。作成を始めると1件を予約し、下書きの保存に成功したら確定します（取り消すと戻ります）。' } ) );
		}
		wrap.appendChild( h( 'p', { class: 'wpaic-actions' },
			h( 'button', { type: 'button', class: 'button button-primary button-hero', disabled: ! available || noQuota || ! f.consent || state.busy, on: { click: submit } }, state.busy ? '送信中…' : 'AIで下書きを作成' ),
			' ', h( 'button', { type: 'button', class: 'button-link', on: { click: resetForm } }, '入力をすべて消す' ) ) );
		if ( ! available && state.options && state.options.error ) {
			wrap.appendChild( errorBox( state.options.error ) );
		}
		return wrap;
	}

	// 施工事例カテゴリのチェック（親の分類ごとにまとめる）。
	function categoryChecks( cats, chosen, disabled, onChange ) {
		var box = h( 'div', { class: 'wpaic-cats' } );
		var groups = {};
		var order = [];
		cats.forEach( function ( c ) {
			var g = c.group || 'その他';
			if ( ! groups[ g ] ) {
				groups[ g ] = [];
				order.push( g );
			}
			groups[ g ].push( c );
		} );
		order.forEach( function ( g ) {
			box.appendChild( h( 'div', { class: 'wpaic-cat-group' }, h( 'span', { class: 'wpaic-cat-label', text: g } ),
				h( 'div', { class: 'wpaic-checks' }, groups[ g ].map( function ( c ) {
					return h( 'label', {}, h( 'input', { type: 'checkbox', checked: chosen.indexOf( c.id ) !== -1, disabled: disabled, on: { change: function ( e ) { onChange( c, e.target.checked ); } } } ), ' ' + c.name );
				} ) ) ) );
		} );
		return box;
	}

	// ---------- 既存のページから取り込む ----------
	function importView( f ) {
		var ta = h( 'textarea', { rows: 6, class: 'large-text code', placeholder: '既存の施工事例ページの HTML（ソース）か、ページの文章をそのまま貼り付けてください', on: { input: function ( e ) { f.importText = e.target.value; saveForm(); } } } );
		ta.value = f.importText || '';
		var ok = h( 'input', { type: 'checkbox' } );
		var msg = state.importResult ? h( 'div', { class: 'notice notice-success inline' }, h( 'p', { text: state.importResult } ) ) : null;
		return h( 'details', { class: 'wpaic-import', open: !! ( f.importText || state.importResult ) },
			h( 'summary', {}, h( 'strong', { text: '既存のページから取り込む（任意）' } ) ),
			h( 'p', { class: 'description', text: '貼り付けた内容から、AIが下の入力欄の項目（ご要望・施工内容・お客様からの声など）と、ページの写真（工程・説明）を取り出します。写真は、このサイトのメディアになければダウンロードしてメディアに登録し、写真の一覧に入れます（自社のページの写真だけを使ってください。写真はこの時点ではAIへ送信しません）。取り込んだ内容は、確認・修正してから作成してください。' } ),
			ta,
			h( 'p', {}, h( 'label', {}, ok, ' 貼り付けた文章（タグを除いたもの）を ' + PROVIDER + ' へ送信することに同意します' ) ),
			h( 'p', {}, h( 'button', { type: 'button', class: 'button', disabled: state.busy, on: { click: function () {
				if ( ! ok.checked ) {
					window.alert( '送信への同意にチェックを入れてください。' );
					return;
				}
				runImport( f );
			} } }, state.busy ? '取り込み中…' : '取り込む' ) ),
			msg );
	}
	function runImport( f ) {
		var filled = Object.keys( f.facts ).some( function ( k ) { return f.facts[ k ]; } );
		if ( filled && ! window.confirm( '入力済みの項目があります。取り込んだ内容で上書きしますか？（取り込めなかった項目は、今の入力のまま残します）' ) ) {
			return;
		}
		state.importResult = null;
		busy( function () {
			return api( 'import', 'POST', { html: f.importText || '', consent_external_ai: true } ).then( function ( r ) {
				var n = 0;
				Object.keys( r.facts || {} ).forEach( function ( k ) {
					f.facts[ k ] = r.facts[ k ];
					n++;
				} );
				var staffSet = false;
				if ( r.staff_comment && f.staff[ 0 ] && ! f.staff[ 0 ].comment ) {
					f.staff[ 0 ].comment = r.staff_comment;
					staffSet = true;
				}
				saveForm();
				var head = '項目 ' + n + ' 件を取り込みました' + ( r.title ? '（元のタイトル：' + r.title + '）' : '' ) + '。';
				var tail = ( staffSet ? ' 担当スタッフのコメントも見つかりました（担当スタッフ 1 の欄に入れました。スタッフを選んでください）。' : '' ) +
					( r.photos_skipped ? ' ロゴ・バナーなどと判断した画像 ' + r.photos_skipped + ' 枚は入れていません。' : '' ) +
					( r.cost ? ' AIの費用（推定・管理者にだけ表示）：' + costLine( r.cost.last, r.cost.jpy_per_usd ) : '' );
				return importPhotos( f, r.photos || [], head, tail );
			} );
		} );
	}
	// ページの写真を、ページの順に写真の一覧へ入れる。このサイトのメディアにないものは1枚ずつ取り込む。
	function importPhotos( f, photos, head, tail ) {
		var added = 0;
		var failed = [];
		var done = 0;
		function push( id, thumb, p ) {
			if ( f.photos.some( function ( x ) { return x.id === id; } ) ) {
				return;
			}
			f.photos.push( { id: id, thumb: thumb, caption: p.caption || '', hint: p.phase || 'unknown' } );
			added++;
		}
		function show( running ) {
			state.importResult = head + ' 写真 ' + added + ' 枚を一覧に入れました' + ( running ? '（取り込み中 ' + done + ' / ' + photos.length + '）' : '' ) + '。' +
				( failed.length ? ' 取り込めなかった写真 ' + failed.length + ' 枚：' + failed.join( '、' ) + '。' : '' ) +
				( running ? '' : ' 内容・写真の工程を確認・修正してから作成してください。' ) + tail;
			saveForm();
			render();
		}
		return photos.reduce( function ( chain, p ) {
			return chain.then( function () {
				if ( p.attachment_id ) {
					push( p.attachment_id, p.thumb, p );
					done++;
					return;
				}
				if ( ! p.url ) {
					failed.push( '写真' + p.no + '（アドレスが分かりません）' );
					done++;
					return;
				}
				show( true );
				return api( 'import/media', 'POST', { url: p.url, title: p.caption || p.alt || '' } ).then( function ( m ) {
					push( m.attachment_id, m.thumb, p );
				} ).catch( function ( e ) {
					failed.push( '写真' + p.no + '（' + ( ( e.fields && e.fields[ 0 ] ) || ( e.error && e.error.message ) || '失敗' ) + '）' );
				} ).then( function () { done++; } );
			} );
		}, Promise.resolve() ).then( function () { show( false ); } );
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
			article_policy: f.policy || '',
			terms: f.terms || [],
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
		var box = h( 'div', { class: 'wpaic-progress' }, h( 'h2', { text: STATUS_TEXT[ job.status ] || job.status } ), costBox( job.cost ) );
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
		var cb = costBox( job.cost );
		if ( cb ) {
			wrap.appendChild( cb );
		}
		var revising = job.mode === 'revise_draft';
		if ( revising && job.target ) {
			wrap.appendChild( h( 'div', { class: 'notice notice-info inline' }, h( 'p', { text: '「' + job.target.title + '」を修正しています。保存すると、この下書きのうち変更した部分だけを書き換えます（公開はされません）。' } ) ) );
			if ( job.target.edited && job.target.edited.length ) {
				wrap.appendChild( h( 'div', { class: 'notice notice-warning inline' },
					h( 'p', { text: '次の部分は、保存後に編集画面で変更されています。これらに関わる修正は保存できません（編集画面で直接直してください）：' } ),
					h( 'ul', {}, job.target.edited.map( function ( t ) { return h( 'li', { text: t } ); } ) ) ) );
			}
			if ( job.target.was_published ) {
				wrap.appendChild( h( 'div', { class: 'notice notice-warning inline' }, h( 'p', { text: 'この投稿は一度公開された可能性があります。下書きの間は、公開ページは表示されません。ここから公開はできません。' } ) ) );
			}
		}
		if ( job.error ) {
			wrap.appendChild( errorBox( job.error ) );
		}
		if ( job.questions && job.questions.length ) {
			wrap.appendChild( h( 'div', { class: 'notice notice-info inline' }, h( 'p', { text: 'AIから確認したいこと（必要なら下の項目に追記してください）：' } ), h( 'ul', {}, job.questions.map( function ( q ) { return h( 'li', { text: q } ); } ) ) ) );
		}

		// 未反映の入力：再描画しても値を残し、黄色で示す。保存はすべて反映か破棄するまで止める。
		var dirtyMsg = h( 'p', { class: 'wpaic-dirty-msg', role: 'status' } );
		var saveBtn = null;
		var discardBtn = null;
		function dirtyCount() {
			return Object.keys( state.drafts ).length;
		}
		function updateDirty() {
			var n = dirtyCount();
			dirtyMsg.textContent = n ? '「反映」していない変更が ' + n + ' か所あります（黄色の欄）。各欄の「反映」を押すか、変更を破棄してから保存してください。' : '';
			if ( saveBtn ) {
				saveBtn.disabled = state.busy || n > 0;
			}
			if ( discardBtn ) {
				discardBtn.style.display = n ? '' : 'none'; // WordPress の .button は hidden 属性より強いので style で消す
			}
		}
		function bindDraft( el, key, value ) {
			var base = value || '';
			var d = state.drafts[ key ];
			if ( d !== undefined && d !== base ) {
				el.value = d;
				el.classList.add( 'is-dirty' );
			} else {
				delete state.drafts[ key ];
				el.value = base;
			}
			el.addEventListener( 'input', function () {
				if ( el.value === base ) {
					delete state.drafts[ key ];
					el.classList.remove( 'is-dirty' );
				} else {
					state.drafts[ key ] = el.value;
					el.classList.add( 'is-dirty' );
				}
				updateDirty();
			} );
			return el;
		}

		function textBlock( label, value, onApply, multi, key ) {
			var el = h( multi ? 'textarea' : 'input', multi ? { rows: 3, class: 'large-text' } : { type: 'text', class: 'large-text' } );
			bindDraft( el, key, value );
			return h( 'div', { class: 'wpaic-field' }, h( 'label', {}, h( 'strong', { text: label } ), h( 'br' ), el ),
				h( 'button', { type: 'button', class: 'button button-small', disabled: state.busy, on: { click: function () { onApply( el.value ); } } }, '反映' ) );
		}
		var intro = doc.sections.filter( function ( s ) { return s.node_id === 'intro'; } )[ 0 ] || { heading: '', body: '' };
		wrap.appendChild( h( 'h2', { text: '基本' } ) );
		wrap.appendChild( textBlock( 'タイトル', doc.title, function ( v ) { op( 'title', { op: 'text_set', value: v } ); }, false, 'title' ) );
		wrap.appendChild( textBlock( 'コンセプト（見出し）', intro.heading, function ( v ) { op( 'concept', { op: 'text_set', value: v } ); }, false, 'concept' ) );
		wrap.appendChild( textBlock( 'コンセプト説明', doc.summary, function ( v ) { op( 'summary', { op: 'text_set', value: v } ); }, true, 'summary' ) );

		function prose( node, factKey ) {
			var s = doc.sections.filter( function ( x ) { return x.node_id === node; } )[ 0 ];
			if ( s ) {
				return s.body;
			}
			var f = doc.facts[ factKey ];
			return f && f.value ? f.value : '';
		}
		wrap.appendChild( h( 'h2', { text: 'ご要望・ご提案' } ) );
		wrap.appendChild( textBlock( 'お客様のご要望', prose( 'request', 'client_request' ), function ( v ) { op( 'client_request', { op: 'text_set', value: v } ); }, true, 'prose:request' ) );
		wrap.appendChild( textBlock( 'ご提案（実施した工事）', prose( 'proposal', 'work_done' ), function ( v ) { op( 'proposal', { op: 'text_set', value: v } ); }, true, 'prose:proposal' ) );

		wrap.appendChild( h( 'h2', { text: '施工概要（入力された事実だけを載せます）' } ) );
		var tbl = h( 'table', { class: 'widefat striped wpaic-facts' } );
		OUTLINE_KEYS.forEach( function ( k ) {
			var f = doc.facts[ k ];
			var el = bindDraft( h( 'input', { type: 'text', class: 'regular-text', 'aria-label': FACT_LABELS[ k ] } ), 'fact:' + k, f && f.value ? f.value : '' );
			var review = f && f.source === 'editor_review' ? h( 'span', { class: 'description', text: '（AIの案。確認してください）' } ) : null;
			tbl.appendChild( h( 'tr', {}, h( 'th', { text: FACT_LABELS[ k ] } ), h( 'td', {}, el, ' ',
				h( 'button', { type: 'button', class: 'button button-small', disabled: state.busy, on: { click: function () { op( 'facts', { op: 'fact_set', fact_key: k, value: el.value, source: 'user_input' } ); } } }, '反映' ), review ) ) );
		} );
		wrap.appendChild( tbl );

		var voiceFact = doc.facts.customer_voice;
		wrap.appendChild( h( 'h2', { text: 'お客様からの声' } ) );
		wrap.appendChild( h( 'p', { class: 'description', text: '入力した文章をそのまま載せます（AIでは修正しません）。施主様名には地域が入ります。' } ) );
		wrap.appendChild( textBlock( 'お客様からの声', voiceFact && voiceFact.value ? voiceFact.value : '', function ( v ) { op( 'facts', { op: 'fact_set', fact_key: 'customer_voice', value: v, source: 'user_input' } ); }, true, 'fact:customer_voice' ) );

		var staffRows = ( doc.relations && doc.relations.staff ) || [];
		if ( staffRows.length ) {
			var names = {};
			( ( state.options && state.options.staff ) || [] ).forEach( function ( v ) { names[ v.id ] = v.title; } );
			wrap.appendChild( h( 'h2', { text: '担当スタッフのコメント' } ) );
			wrap.appendChild( h( 'p', { class: 'description', text: '空欄で作成した場合は、AIが下書きしています。自由に書き換えられます。' } ) );
			staffRows.forEach( function ( s ) {
				wrap.appendChild( textBlock( names[ s.post_id ] || ( '担当スタッフ（ID: ' + s.post_id + '）' ), s.comment, function ( v ) { op( 'staff', { op: 'staff_comment_set', post_id: s.post_id, comment: v } ); }, true, 'staff:' + s.post_id ) );
			} );
		}

		// 保存時と同じ決まりで写真を並べる（施工前・中・後は写真の工程で、比較は前後1枚ずつ）。
		var incl = doc.images.filter( function ( i ) { return i.included; } ).sort( function ( a, b ) { return a.original_position - b.original_position; } );
		function phaseIds( p ) {
			return incl.filter( function ( i ) { return i.phase === p; } ).map( function ( i ) { return i.attachment_id; } );
		}
		function firstIn( ids, p ) {
			var hit = phaseIds( p ).filter( function ( id ) { return ids.indexOf( id ) !== -1; } );
			return hit.length ? hit[ 0 ] : null;
		}
		// 施工中は工程（節）ごとに、その節の写真のうちまだ使っていないもの。どの節にも入らない写真は最後にまとめる。
		var duringIds = phaseIds( 'during' );
		var used = {};
		var stepPhotos = {};
		var firstBefore = null;
		var firstAfter = null;
		doc.sections.forEach( function ( s ) {
			if ( s.kind === 'during' ) {
				stepPhotos[ s.node_id ] = duringIds.filter( function ( id ) {
					if ( ( s.image_ids || [] ).indexOf( id ) !== -1 && ! used[ id ] ) {
						used[ id ] = true;
						return true;
					}
					return false;
				} );
			} else if ( s.kind === 'before' && firstBefore === null ) {
				firstBefore = s.node_id;
			} else if ( s.kind === 'after' && firstAfter === null ) {
				firstAfter = s.node_id;
			}
		} );
		var leftDuring = duringIds.filter( function ( id ) { return ! used[ id ]; } );
		wrap.appendChild( h( 'h2', { text: '本文' } ) );
		wrap.appendChild( h( 'p', { class: 'description', text: '施工前・施工後の写真は、下の「写真」で選んだ工程ごとに入ります（施工後は1枚目を大きく表示）。施工中は工程ごとに、見出し → その工程の写真 → 説明 の順に並びます。' } ) );
		doc.sections.forEach( function ( s ) {
			if ( s.node_id === 'intro' || PROSE_NODES.indexOf( s.node_id ) !== -1 ) {
				return;
			}
			var shown = [];
			var note = null;
			if ( s.kind === 'during' ) {
				shown = stepPhotos[ s.node_id ] || [];
			} else if ( s.kind === 'before' || s.kind === 'after' ) {
				shown = s.node_id === ( s.kind === 'before' ? firstBefore : firstAfter ) ? phaseIds( s.kind ) : [];
			} else if ( s.kind === 'comparison' ) {
				var pb = firstIn( s.image_ids || [], 'before' );
				var pa = firstIn( s.image_ids || [], 'after' );
				if ( pb && pa ) {
					shown = [ pb, pa ];
				} else {
					note = h( 'p', { class: 'description', text: '施工前と施工後の写真がそろわないため、この比較は保存されません。' } );
				}
			}
			var kind = KIND_LABELS[ s.kind ] || s.kind;
			var hd = bindDraft( h( 'input', { type: 'text', class: 'large-text', 'aria-label': kind + '：見出し' } ), 'sec:' + s.node_id + ':h', s.heading );
			var bd = bindDraft( h( 'textarea', { rows: 3, class: 'large-text', 'aria-label': kind + '：本文' } ), 'sec:' + s.node_id + ':b', s.body );
			var thumbs = h( 'div', { class: 'wpaic-thumbs' }, shown.map( function ( id ) { return job.images[ id ] ? h( 'img', { src: job.images[ id ].thumb, alt: '', width: 64, height: 64 } ) : null; } ) );
			wrap.appendChild( h( 'div', { class: 'wpaic-section' }, h( 'span', { class: 'wpaic-kind', text: kind } ), hd, bd, thumbs, note,
				h( 'button', { type: 'button', class: 'button button-small', disabled: state.busy, on: { click: function () { op( 'body', { op: 'body_section_set', node_id: s.node_id, heading: hd.value, body: bd.value } ); } } }, '反映' ) ) );
		} );

		if ( leftDuring.length ) {
			wrap.appendChild( h( 'div', { class: 'wpaic-section' }, h( 'span', { class: 'wpaic-kind', text: '施工中（どの工程にも入っていない写真）' } ),
				h( 'p', { class: 'description', text: '次の写真は、施工中の最後に見出しなしでまとめて入ります。AIで修正するか、写真の工程を変えてください。' } ),
				h( 'div', { class: 'wpaic-thumbs' }, leftDuring.map( function ( id ) { return job.images[ id ] ? h( 'img', { src: job.images[ id ].thumb, alt: '', width: 64, height: 64 } ) : null; } ) ) ) );
		}

		wrap.appendChild( h( 'h2', { text: '写真' } ) );
		var unknownCount = phaseIds( 'unknown' ).length;
		if ( unknownCount ) {
			wrap.appendChild( h( 'p', { class: 'description', text: '工程が「不明」の写真（' + unknownCount + '枚）は、「特徴」の欄にまとめて入ります。' } ) );
		}
		var order = doc.images.map( function ( i ) { return i.attachment_id; } );
		var grid = h( 'div', { class: 'wpaic-grid' } );
		doc.images.forEach( function ( img, i ) {
			var phase = h( 'select', { disabled: state.busy, on: { change: function ( e ) { op( 'before_after', { op: 'image_phase_set', attachment_id: img.attachment_id, phase: e.target.value } ); } } } );
			Object.keys( PHASES ).forEach( function ( k ) { phase.appendChild( h( 'option', { value: k, selected: img.phase === k, text: k === 'unknown' ? '不明' : PHASES[ k ] } ) ); } );
			var cap = bindDraft( h( 'input', { type: 'text', placeholder: 'キャプション', 'aria-label': '写真' + ( i + 1 ) + 'のキャプション' } ), 'cap:' + img.attachment_id, img.caption );
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

		wrap.appendChild( metaView( job, incl ) );

		wrap.appendChild( h( 'h2', { text: 'SEO' } ) );
		[ [ 'title', 'タイトル' ], [ 'description', '説明文' ], [ 'h1', 'H1' ], [ 'keywords', 'キーワード' ] ].forEach( function ( x ) {
			wrap.appendChild( textBlock( 'SEO：' + x[ 1 ], ( doc.seo || {} )[ x[ 0 ] ], function ( v ) { op( 'seo', { op: 'seo_set', field: x[ 0 ], value: v } ); }, false, 'seo:' + x[ 0 ] ) );
		} );

		// AIによる修正（選んだ部分だけ）
		var checks = h( 'div', { class: 'wpaic-checks' } );
		var aiSections = [ 'title', 'concept', 'summary', 'body', 'client_request', 'proposal', 'photos', 'before_after', 'layout', 'seo' ].concat( staffRows.length ? [ 'staff' ] : [] );
		var all = h( 'input', { type: 'checkbox', on: { change: function ( e ) { Array.prototype.forEach.call( checks.querySelectorAll( 'input[data-sec]' ), function ( c ) { c.checked = e.target.checked; } ); } } } );
		checks.appendChild( h( 'label', {}, all, ' すべて' ) );
		aiSections.forEach( function ( s ) { checks.appendChild( h( 'label', {}, h( 'input', { type: 'checkbox', 'data-sec': s } ), ' ' + SECTION_LABELS[ s ] ) ); } );
		var instr = h( 'textarea', { rows: 3, class: 'large-text', placeholder: '例：タイトルをもう少し短く、本文はやわらかい言い方にしてください', 'aria-label': '修正の指示' } );
		var aiMsg = h( 'p', { class: 'wpaic-danger', role: 'alert' } );
		wrap.appendChild( h( 'div', { class: 'wpaic-ai' }, h( 'h2', { text: 'AIで修正する（選んだ部分だけ）' } ), checks, instr, aiMsg,
			h( 'p', {}, h( 'button', { type: 'button', class: 'button', disabled: state.busy, on: { click: function () {
				var secs = Array.prototype.filter.call( checks.querySelectorAll( 'input[data-sec]' ), function ( c ) { return c.checked; } ).map( function ( c ) { return c.getAttribute( 'data-sec' ); } );
				if ( ! secs.length || ! instr.value.trim() ) {
					aiMsg.textContent = ! secs.length ? '修正する部分を1つ以上選んでください。' : '修正の指示を入力してください。';
					return;
				}
				aiMsg.textContent = '';
				revise( { allowed_sections: secs, instruction: instr.value } );
			} } }, state.busy ? '処理中…' : 'AIで修正' ) ) ) );

		saveBtn = h( 'button', { type: 'button', class: 'button button-primary button-hero', on: { click: function () {
			if ( dirtyCount() ) {
				updateDirty();
				return;
			}
			busy( function () { return api( 'jobs/' + job.job_id + '/save', 'POST', { base_index: job.version_index } ).then( function ( j ) {
				if ( ! revising ) {
					try { window.sessionStorage.removeItem( FORM_KEY ); } catch ( e ) {}
					state.form = loadForm();
				}
				setJob( j );
			} ); } );
		} } }, state.busy ? '処理中…' : ( revising ? '下書きを更新' : '下書きとして保存' ) );
		discardBtn = h( 'button', { type: 'button', class: 'button', on: { click: function () { state.drafts = {}; render(); } } }, '未反映の変更を破棄' );
		wrap.appendChild( dirtyMsg );
		wrap.appendChild( h( 'p', { class: 'wpaic-actions' }, saveBtn, ' ', discardBtn, ' ',
			h( 'button', { type: 'button', class: 'button', disabled: state.busy, on: { click: cancelJob } }, revising ? '修正をやめる' : '取り消す' ) ) );
		updateDirty();
		return wrap;
	}

	// 施工事例カテゴリ・アイキャッチ（変えるとすぐ記録する。版の履歴には入らない）。
	function metaView( job, incl ) {
		var meta = job.meta || { terms: [], thumbnail_id: 0 };
		var cats = ( state.options && state.options.categories ) || [];
		function send( body ) {
			body.base_index = job.version_index;
			busy( function () { return api( 'jobs/' + job.job_id + '/meta', 'POST', body ).then( setJob ); } );
		}
		var box = h( 'div', { class: 'wpaic-meta' }, h( 'h2', { text: '施工事例カテゴリ・アイキャッチ' } ) );
		if ( cats.length ) {
			var terms = ( meta.terms || [] ).slice();
			box.appendChild( h( 'p', { class: 'description', text: meta.terms_source === 'ai' ? 'AIが候補から選びました。確認して、必要なら変えてください。' : '保存のときに下書きへ設定します。' } ) );
			box.appendChild( categoryChecks( cats, terms, state.busy, function ( c, on ) {
				var next = terms.filter( function ( t ) { return t !== c.id; } );
				if ( on ) {
					next.push( c.id );
				}
				send( { terms: next } );
			} ) );
		}
		box.appendChild( h( 'p', {}, h( 'strong', { text: 'アイキャッチ画像' } ), h( 'span', { class: 'description', text: '（一覧などに表示される画像。最初は施工後の1枚目）' } ) ) );
		var name = 'wpaic-thumb-' + job.job_id;
		var list = h( 'div', { class: 'wpaic-thumb-pick' } );
		incl.forEach( function ( img ) {
			var id = img.attachment_id;
			list.appendChild( h( 'label', { class: meta.thumbnail_id === id ? 'is-selected' : '' },
				h( 'input', { type: 'radio', name: name, value: id, checked: meta.thumbnail_id === id, disabled: state.busy, on: { change: function () { send( { thumbnail_id: id } ); } } } ),
				job.images[ id ] ? h( 'img', { src: job.images[ id ].thumb, alt: '', width: 64, height: 64 } ) : String( id ) ) );
		} );
		list.appendChild( h( 'label', { class: meta.thumbnail_id ? '' : 'is-selected' },
			h( 'input', { type: 'radio', name: name, value: 0, checked: ! meta.thumbnail_id, disabled: state.busy, on: { change: function () { send( { thumbnail_id: 0 } ); } } } ), ' 設定しない' ) );
		box.appendChild( list );
		return box;
	}

	function doneView( job ) {
		var revised = job.mode === 'revise_draft';
		var changes = job.saved_changes || [];
		return h( 'div', { class: 'wpaic-done' }, costBox( job.cost ),
			h( 'div', { class: 'notice notice-success inline' },
				h( 'p', { text: revised ? ( changes.length ? '下書きを更新しました（まだ公開されていません）。' : '変更がなかったため、下書きは書き換えていません。' ) : '施工事例の下書きとして保存しました（まだ公開されていません）。' } ),
				revised && changes.length ? h( 'p', { text: '更新した部分：' + changes.join( '、' ) } ) : null ),
			h( 'p', {},
				job.edit_url ? h( 'a', { class: 'button button-primary', href: job.edit_url }, '編集画面で開く' ) : null, ' ',
				job.preview_url ? h( 'a', { class: 'button', href: job.preview_url, target: '_blank', rel: 'noopener' }, 'プレビュー' ) : null, ' ',
				h( 'button', { type: 'button', class: 'button-link', on: { click: function () { state.draftList = null; setJob( null ); } } }, revised ? '別の下書きを修正' : '別の施工事例を作成' ) ) );
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
