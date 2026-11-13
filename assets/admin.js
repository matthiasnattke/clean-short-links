/* Clean Short Links – admin screens. */
( function () {
	'use strict';
	var D = window.CleanShortLinksAdmin || { i18n: {} };
	var T = D.i18n;
	var api = window.wp && window.wp.apiFetch;

	function $( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}
	function $$( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}
	function debounce( fn, ms ) {
		var t;
		return function () {
			var a = arguments, self = this;
			clearTimeout( t );
			t = setTimeout( function () {
				fn.apply( self, a );
			}, ms );
		};
	}

	/* ---- Copy buttons ---- */
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '.csl-copy-btn' );
		if ( ! b ) {
			return;
		}
		e.preventDefault();
		var text = b.getAttribute( 'data-copy' );
		var done = function () {
			b.classList.add( 'is-copied' );
			b.setAttribute( 'data-label', T.copied );
			setTimeout( function () {
				b.classList.remove( 'is-copied' );
			}, 1500 );
		};
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			ta.remove();
			done();
		}
	} );

	/* ---- Delete ---- */
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '.csl-delete' ) && ! window.confirm( T.confirmDel ) ) {
			e.preventDefault();
		}
	} );

	/* ---- Target pickers: search content or paste a URL ---- */
	function initPicker( box ) {
		if ( box.dataset.ready ) {
			return;
		}
		box.dataset.ready = '1';
		var value = $( '.csl-picker-value', box ), input = $( '.csl-picker-input', box ), list = $( '.csl-picker-results', box ), kind = $( '.csl-picker-kind', box );
		function close() {
			list.hidden = true;
			list.innerHTML = '';
		}
		var search = debounce( function () {
			var q = input.value.trim();
			if ( /^https?:\/\//i.test( q ) ) {
				value.value = q;
				kind.textContent = T.urlTarget;
				close();
				input.dispatchEvent( new Event( 'csl-target', { bubbles: true } ) );
				return;
			}
			if ( q.length < 2 || ! api ) {
				close();
				return;
			}
			list.hidden = false;
			list.innerHTML = '<li class="csl-picker-note">' + T.searching + '</li>';
			api( { path: '/wp/v2/search?type=post&per_page=8&search=' + encodeURIComponent( q ) } ).then( function ( rows ) {
				list.innerHTML = '';
				if ( ! rows.length ) {
					list.innerHTML = '<li class="csl-picker-note">' + T.noResults + '</li>';
					return;
				}
				rows.forEach( function ( r ) {
					var li = document.createElement( 'li' ), b = document.createElement( 'button' );
					b.type = 'button';
					b.textContent = r.title || r.url;
					var small = document.createElement( 'small' );
					small.textContent = ' ' + r.subtype;
					b.appendChild( small );
					b.addEventListener( 'click', function () {
						value.value = 'post:' + r.id;
						input.value = r.title || r.url;
						kind.textContent = r.subtype;
						close();
						input.dispatchEvent( new Event( 'csl-target', { bubbles: true } ) );
					} );
					li.appendChild( b );
					list.appendChild( li );
				} );
			} ).catch( close );
		}, 250 );
		input.addEventListener( 'input', function () {
			if ( '' === input.value.trim() ) {
				value.value = '';
				kind.textContent = '';
			}
			search();
		} );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				close();
			}
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( ! box.contains( e.target ) ) {
				close();
			}
		} );
	}
	$$( '.csl-picker' ).forEach( initPicker );

	var form = $( '#csl-form' );
	if ( ! form ) {
		return;
	}

	/* ---- Short address ---- */
	var slug = $( '#csl-slug' ), msg = $( '.csl-slug-msg' ), rootMsg = $( '.csl-root-msg' ), root = $( '#csl-root' );
	var id = parseInt( slug.getAttribute( 'data-id' ), 10 ) || 0, original = slug.getAttribute( 'data-original' );
	var kind = function () {
		var r = $( 'input[name="kind"]:checked' );
		return r ? r.value : 'custom';
	};
	function setKind( k ) {
		var r = $( 'input[name="kind"][value="' + k + '"]' );
		if ( r ) {
			r.checked = true;
		}
	}
	var check = debounce( function () {
		var v = slug.value.trim();
		if ( ! v || ! api ) {
			return;
		}
		msg.className = 'csl-slug-msg';
		msg.textContent = T.checking;
		api( { path: '/clean-short-links/v1/check?id=' + id + '&slug=' + encodeURIComponent( v ) } ).then( function ( r ) {
			if ( r.slug !== v ) {
				slug.value = r.slug;
			}
			msg.className = 'csl-slug-msg ' + ( r.ok ? 'is-ok' : 'is-bad' );
			msg.textContent = r.ok ? ( r.reserved ? T.reserved : T.free ) : r.message;
			if ( rootMsg ) {
				rootMsg.textContent = r.root ? T.collision.replace( '%s', r.root ) : '';
				rootMsg.className = 'csl-root-msg' + ( r.root ? ' is-warn' : '' );
			}
			var old = $( '.csl-old-slug' );
			if ( old ) {
				old.hidden = ! id || r.slug === original;
			}
		} );
	}, 300 );
	slug.addEventListener( 'input', function () {
		setKind( 'custom' );
		check();
	} );
	function suggest() {
		var k = kind();
		if ( 'custom' === k || ! api ) {
			slug.focus();
			return;
		}
		var title = $( '#csl-title' ).value || $( '#csl-target' ).value;
		api( { path: '/clean-short-links/v1/suggest?kind=' + k + '&title=' + encodeURIComponent( title ) } ).then( function ( r ) {
			slug.value = r.slug;
			if ( root && ! id ) {
				root.checked = 'random' !== k;
			}
			check();
		} );
	}
	$$( 'input[name="kind"]' ).forEach( function ( r ) {
		r.addEventListener( 'change', suggest );
	} );
	$( '.csl-reroll' ).addEventListener( 'click', suggest );
	if ( slug.value ) {
		check();
	}

	/* ---- QR address ---- */
	var same = $( '#csl-qr-same' ), qrRow = $( '.csl-qr-slug-row' ), qrSlug = $( '#csl-qr-slug' );
	same.addEventListener( 'change', function () {
		qrRow.hidden = same.checked;
		if ( same.checked ) {
			qrSlug.value = '';
		} else if ( ! qrSlug.value ) {
			qrSlug.value = slug.value + '-qr';
			qrSlug.focus();
		}
	} );

	/* ---- Start with publication only for content ---- */
	function publishOption() {
		var v = $( 'input[name="target"]' ).value, p = $( '.csl-publish-start' );
		p.hidden = 0 !== v.indexOf( 'post:' );
		if ( p.hidden && $( 'input', p ).checked ) {
			$( 'input[name="start_mode"][value=""]' ).checked = true;
		}
	}
	form.addEventListener( 'csl-target', publishOption );
	publishOption();
	var startAt = $( 'input[name="starts_at"]' );
	startAt.addEventListener( 'input', function () {
		$( 'input[name="start_mode"][value="date"]' ).checked = !! startAt.value;
	} );

	/* ---- Rules ---- */
	var rules = $( '.csl-rules' ), tpl = $( '#csl-rule-template' ), next = $$( '.csl-rule', rules ).length + 100;
	function number() {
		$$( '.csl-rule', rules ).forEach( function ( r, i ) {
			$( '.csl-rule-no', r ).textContent = String( i + 1 );
		} );
	}
	$( '.csl-rule-add' ).addEventListener( 'click', function () {
		var html = tpl.innerHTML.replace( /__i__/g, String( next++ ) );
		var wrap = document.createElement( 'div' );
		wrap.innerHTML = html;
		var row = wrap.firstElementChild;
		rules.appendChild( row );
		$$( '.csl-picker', row ).forEach( initPicker );
		number();
		$( '.csl-rule-label', row ).focus();
	} );
	rules.addEventListener( 'click', function ( e ) {
		var row = e.target.closest( '.csl-rule' );
		if ( ! row ) {
			return;
		}
		if ( e.target.closest( '.csl-rule-remove' ) && window.confirm( T.removeRule ) ) {
			row.remove();
			number();
		}
		if ( e.target.closest( '.csl-rule-up' ) && row.previousElementSibling ) {
			rules.insertBefore( row, row.previousElementSibling );
			number();
		}
	} );
	number();
} )();
