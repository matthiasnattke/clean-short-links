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

	/* ---- Folding cards: open on a click, through a link to them, and when a field in them needs attention ---- */
	function setFold( card, open ) {
		var btn = $( '.csl-fold-btn', card ), body = $( '.csl-fold-body', card );
		if ( ! btn || ! body ) {
			return;
		}
		card.classList.toggle( 'is-open', open );
		btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		body.hidden = ! open;
	}
	function openFoldAt( hash ) {
		var id = ( hash || '' ).replace( /^#/, '' );
		var el = id && document.getElementById( id );
		var card = el && el.closest( '.csl-fold' );
		if ( card ) {
			setFold( card, true );
		}
	}
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '.csl-fold-btn' );
		if ( b ) {
			var card = b.closest( '.csl-fold' );
			setFold( card, ! card.classList.contains( 'is-open' ) );
			return;
		}
		var a = e.target.closest( 'a[href*="#csl-"]' );
		if ( a ) {
			openFoldAt( a.hash );
		}
	} );
	window.addEventListener( 'hashchange', function () {
		openFoldAt( location.hash );
	} );
	if ( location.hash ) {
		openFoldAt( location.hash );
		var at = document.getElementById( location.hash.slice( 1 ) );
		if ( at && at.closest( '.csl-fold' ) ) {
			at.scrollIntoView();
		}
	}
	// A field the browser rejects must be visible, or the form silently does not save.
	document.addEventListener( 'invalid', function ( e ) {
		var card = e.target.closest && e.target.closest( '.csl-fold' );
		if ( card ) {
			setFold( card, true );
		}
	}, true );

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
			var form = document.getElementById( 'csl-form' ), self = form ? ( parseInt( ( form.querySelector( 'input[name="id"]' ) || {} ).value, 10 ) || 0 ) : 0;
			Promise.all( [
				api( { path: '/wp/v2/search?type=post&csl_skip=1&per_page=8&search=' + encodeURIComponent( q ) } ).catch( function () {
					return [];
				} ),
				api( { path: '/clean-short-links/v1/links?skip=' + self + '&search=' + encodeURIComponent( q ) } ).catch( function () {
					return [];
				} ),
			] ).then( function ( res ) {
				var rows = res[ 0 ].map( function ( r ) {
					return { value: 'post:' + r.id, label: r.title || r.url, kind: r.subtype };
				} ).concat( res[ 1 ].map( function ( r ) {
					return { value: 'link:' + r.id, label: r.title + ' (/' + D.prefix + '/' + r.slug + ')', kind: T.shortLink };
				} ) );
				if ( T.homePage.toLowerCase().indexOf( q.toLowerCase() ) === 0 || 'home'.indexOf( q.toLowerCase() ) === 0 ) {
					rows.unshift( { value: 'home', label: T.homePage, kind: '' } );
				}
				list.innerHTML = '';
				if ( ! rows.length ) {
					list.innerHTML = '<li class="csl-picker-note">' + T.noResults + '</li>';
					return;
				}
				rows.forEach( function ( r ) {
					var li = document.createElement( 'li' ), b = document.createElement( 'button' );
					b.type = 'button';
					b.textContent = r.label;
					var small = document.createElement( 'small' );
					small.textContent = r.kind ? ' ' + r.kind : '';
					b.appendChild( small );
					b.addEventListener( 'click', function () {
						value.value = r.value;
						input.value = r.label;
						kind.textContent = r.kind || r.label;
						close();
						input.dispatchEvent( new Event( 'csl-target', { bubbles: true } ) );
					} );
					li.appendChild( b );
					list.appendChild( li );
				} );
			} );
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
		var hosts = $$( 'input[name="meta[hosts][]"]:checked' ).map( function ( c ) {
			return c.value;
		} ).join( ',' );
		api( { path: '/clean-short-links/v1/check?id=' + id + '&slug=' + encodeURIComponent( v ) + '&hosts=' + encodeURIComponent( hosts ) } ).then( function ( r ) {
			if ( r.slug !== v ) {
				slug.value = r.slug;
			}
			msg.className = 'csl-slug-msg ' + ( r.ok ? 'is-ok' : 'is-bad' );
			msg.textContent = r.ok ? ( r.reserved ? T.reserved : T.free ) : r.message;
			if ( rootMsg ) {
				rootMsg.textContent = r.root ? T.collision.replace( '%s', r.root ) : '';
				rootMsg.className = 'csl-root-msg' + ( r.root ? ' is-warn' : '' );
			}
			showHosts( r );
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
	// Further domains: the address as it reads there, and free alternatives when it is taken.
	var hostsMsg = $( '.csl-hosts-msg' );
	function showHosts( r ) {
		$$( '.csl-host-slug' ).forEach( function ( el ) {
			el.textContent = r.slug || '…';
		} );
		$$( '.csl-host' ).forEach( function ( l ) {
			var c = l.querySelector( 'input' );
			l.classList.toggle( 'is-taken', !! ( c && c.checked && r.taken_hosts && -1 !== r.taken_hosts.indexOf( c.value ) ) );
		} );
		if ( ! hostsMsg ) {
			return;
		}
		var taken = r.taken_hosts || [];
		var hostsCard = hostsMsg.closest( '.csl-fold' );
		if ( hostsCard ) {
			var on = $$( 'input[name="meta[hosts][]"]:checked' ).map( function ( c ) {
				return c.value;
			} );
			$( '.csl-fold-sum', hostsCard ).textContent = on.length ? on.join( ', ' ) : ( T.onlyHere || '' );
			if ( taken.length ) {
				setFold( hostsCard, true );
			}
		}
		hostsMsg.hidden = ! taken.length;
		if ( ! taken.length ) {
			return;
		}
		hostsMsg.querySelector( '.csl-host-warn' ).textContent = T.hostTaken.replace( '%s', taken.join( ', ' ) );
		var alts = hostsMsg.querySelector( '.csl-hosts-alts' );
		alts.querySelectorAll( 'button' ).forEach( function ( b ) {
			alts.removeChild( b );
		} );
		alts.hidden = ! ( r.alternatives && r.alternatives.length );
		( r.alternatives || [] ).forEach( function ( a ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'button csl-btn csl-alt';
			b.textContent = a;
			b.addEventListener( 'click', function () {
				slug.value = a;
				setKind( 'custom' );
				chosen = true;
				check();
			} );
			alts.appendChild( b );
		} );
	}
	$$( 'input[name="meta[hosts][]"]' ).forEach( function ( c ) {
		c.addEventListener( 'change', check );
	} );
	function suggest() {
		var k = kind();
		if ( 'custom' === k || ! api ) {
			slug.focus();
			return;
		}
		// Content brings its own plain title (the server reads it); otherwise the title field or the URL.
		var target = $( 'input[name="target"]' ).value, own = $( '#csl-title' ).value;
		var title = own || ( 0 === target.indexOf( 'post:' ) ? '' : $( '#csl-target' ).value );
		api( { path: '/clean-short-links/v1/suggest?kind=' + k + '&title=' + encodeURIComponent( title ) + '&target=' + encodeURIComponent( target ) } ).then( function ( r ) {
			slug.value = r.slug;
			if ( root && ! id ) {
				root.checked = 'random' !== k;
			}
			check();
		} );
	}
	// New links: posts and pages get their address from the title, other targets random
	// or memorable – until the kind or the address is chosen by hand.
	var chosen = !! id;
	slug.addEventListener( 'input', function () {
		chosen = true;
	} );
	$$( 'input[name="kind"]' ).forEach( function ( r ) {
		r.addEventListener( 'change', function () {
			chosen = true;
			suggest();
		} );
	} );
	form.addEventListener( 'csl-target', function () {
		if ( chosen ) {
			return;
		}
		var isPost = 0 === $( 'input[name="target"]' ).value.indexOf( 'post:' );
		setKind( isPost ? D.postKind : D.otherKind );
		var pub = $( 'input[name="start_mode"][value="publish"]' );
		if ( isPost && pub ) {
			pub.checked = true;
		}
		suggest();
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

	/* ---- Sharing: own preview only for addresses outside the site ---- */
	var own = $( '.csl-social-own' );
	if ( own ) {
		form.addEventListener( 'csl-target', function () {
			own.hidden = ! /^https?:\/\//i.test( $( 'input[name="target"]' ).value );
		} );
		var imgInput = $( 'input[name="meta[social][image]"]' ), img = $( '.csl-social-image img' ), clear = $( '.csl-social-clear' );
		$( '.csl-social-pick' ).addEventListener( 'click', function () {
			var frame = window.wp.media( { title: T.chooseImage, button: { text: T.useLogo }, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				imgInput.value = a.id;
				img.src = a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url;
				img.hidden = false;
				clear.hidden = false;
			} );
			frame.open();
		} );
		clear.addEventListener( 'click', function () {
			imgInput.value = '';
			img.hidden = true;
			clear.hidden = true;
		} );
	}
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

	/* ---- Variants by device and screen ---- */
	var variants = $( '.csl-variants' ), vtpl = $( '#csl-variant-template' ), vnext = $$( '.csl-variant', variants ).length + 100;
	function vnumber() {
		$$( '.csl-variant', variants ).forEach( function ( r, i ) {
			$( '.csl-rule-no', r ).textContent = String( i + 1 );
		} );
	}
	if ( variants && vtpl ) {
		$( '.csl-variant-add' ).addEventListener( 'click', function () {
			var wrap = document.createElement( 'div' );
			wrap.innerHTML = vtpl.innerHTML.replace( /__i__/g, String( vnext++ ) );
			var row = wrap.firstElementChild;
			variants.appendChild( row );
			$$( '.csl-picker', row ).forEach( initPicker );
			vnumber();
			$( 'select', row ).focus();
		} );
		variants.addEventListener( 'click', function ( e ) {
			var row = e.target.closest( '.csl-variant' );
			if ( ! row ) {
				return;
			}
			if ( e.target.closest( '.csl-variant-remove' ) ) {
				row.remove();
				vnumber();
			}
			if ( e.target.closest( '.csl-variant-up' ) && row.previousElementSibling ) {
				variants.insertBefore( row, row.previousElementSibling );
				vnumber();
			}
		} );
		vnumber();
	}

	/* ---- Locations ---- */
	var places = $( '.csl-places' ), ptpl = $( '#csl-place-template' ), pnext = $$( '.csl-place', places ).length + 100;
	if ( places && ptpl ) {
		$( '.csl-place-add' ).addEventListener( 'click', function () {
			var wrap = document.createElement( 'div' );
			wrap.innerHTML = ptpl.innerHTML.replace( /__i__/g, String( pnext++ ) );
			var row = wrap.firstElementChild;
			places.appendChild( row );
			$( 'input[type="text"]', row ).focus();
		} );
		places.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '.csl-place-remove' ) ) {
				e.target.closest( '.csl-place' ).remove();
			}
		} );
	}

	/* ---- NFC tag ---- */
	var nfc = $( '.csl-nfc' );
	if ( nfc ) {
		var nfcCode = $( '.csl-nfc-address code', nfc ), nfcCopy = $( '.csl-nfc-address .csl-copy-btn', nfc ), nfcStatus = $( '.csl-nfc-status', nfc );
		var nfcFor = $( 'select[data-code-for="nfc"]' );
		var nfcSize = function () {
			var n = nfcCopy.getAttribute( 'data-copy' ).replace( /^https?:\/\/(www\.)?/, '' ).length;
			$( '.csl-nfc-size', nfc ).textContent = ( n <= 130 ? nfc.dataset.chars : nfc.dataset.long ).replace( '%d', String( n ) );
		};
		if ( nfcFor ) {
			nfcFor.addEventListener( 'change', function () {
				var url = nfcFor.selectedOptions[ 0 ].dataset.url;
				nfcCopy.setAttribute( 'data-copy', url );
				nfcCode.textContent = url.replace( /^https?:\/\//, '' );
				nfcStatus.textContent = '';
				nfcSize();
			} );
		}
		nfcSize();
		if ( 'NDEFReader' in window ) {
			$( '.csl-nfc-write', nfc ).hidden = false;
			$( '.csl-nfc-other', nfc ).hidden = true;
			$( '.csl-nfc-go', nfc ).addEventListener( 'click', function () {
				var btn = this, lock = $( '.csl-nfc-lock', nfc ).checked, reader = new window.NDEFReader();
				btn.disabled = true;
				nfcStatus.textContent = T.nfcHold;
				reader.write( { records: [ { recordType: 'url', data: nfcCopy.getAttribute( 'data-copy' ) } ] } ).then( function () {
					return lock ? reader.makeReadOnly() : null;
				} ).then( function () {
					nfcStatus.textContent = lock ? T.nfcLocked : T.nfcDone;
				} ).catch( function ( err ) {
					nfcStatus.textContent = T.nfcFailed + ' ' + ( err && err.message ? err.message : '' );
				} ).then( function () {
					btn.disabled = false;
				} );
			} );
		}
	}
	/* ---- Wallet card ---- */
	var walletPanels = $$( '.csl-wallet-panel' );
	if ( walletPanels.length ) {
		var walletQr = function ( box ) {
			var designer = window.CleanShortLinksDesigner;
			if ( designer ) {
				// In the link's own QR design, encoded as the designer would encode it.
				try {
					box.innerHTML = designer.svg( box.dataset.url, box.dataset.upper || '', box.dataset.print || '' );
					var drawn = box.firstChild;
					if ( drawn ) {
						drawn.setAttribute( 'role', 'img' );
						drawn.setAttribute( 'aria-label', box.dataset.url );
					}
					return;
				} catch ( err ) {
					box.textContent = '';
				}
			}
			if ( box.firstChild || ! window.CleanShortLinksQR ) {
				return;
			}
			var q = window.CleanShortLinksQR.encode( box.dataset.url, { ec: 'M' } ), n = q.size, d = '', x, y, ns = 'http://www.w3.org/2000/svg';
			for ( y = 0; y < n; y++ ) {
				for ( x = 0; x < n; x++ ) {
					if ( q.modules[ y ][ x ] ) {
						d += 'M' + ( x + 2 ) + ' ' + ( y + 2 ) + 'h1v1h-1z';
					}
				}
			}
			var svg = document.createElementNS( ns, 'svg' ), bg = document.createElementNS( ns, 'rect' ), path = document.createElementNS( ns, 'path' );
			svg.setAttribute( 'viewBox', '0 0 ' + ( n + 4 ) + ' ' + ( n + 4 ) );
			svg.setAttribute( 'role', 'img' );
			svg.setAttribute( 'aria-label', box.dataset.url );
			bg.setAttribute( 'width', String( n + 4 ) );
			bg.setAttribute( 'height', String( n + 4 ) );
			bg.setAttribute( 'fill', '#fff' );
			path.setAttribute( 'd', d );
			path.setAttribute( 'fill', '#0e2440' );
			svg.appendChild( bg );
			svg.appendChild( path );
			box.appendChild( svg );
		};
		var walletShow = function ( key ) {
			walletPanels.forEach( function ( panel ) {
				var on = panel.dataset.walletPanel === key;
				panel.hidden = ! on;
				if ( on ) {
					$$( '.csl-wallet-qr', panel ).forEach( walletQr );
				}
			} );
		};
		// "Wallet card first" in the QR section only while a card is offered.
		var walletFirst = $( '.csl-wallet-first' );
		if ( walletFirst ) {
			document.addEventListener( 'change', function ( e ) {
				if ( e.target.matches && e.target.matches( '.csl-wallet-panel input[name$="[offer]"]' ) ) {
					walletFirst.hidden = ! $$( '.csl-wallet-panel input[name$="[offer]"]' ).some( function ( box ) {
						return box.checked;
					} );
				}
			} );
		}
		var walletFor = $( 'select[data-code-for="wallet"]' );
		if ( walletFor ) {
			walletFor.addEventListener( 'change', function () {
				walletShow( walletFor.value );
			} );
		}
		// The designer repaints the previews when its design changes.
		document.addEventListener( 'csl-qr-render', function () {
			$$( '.csl-wallet-panel:not([hidden]) .csl-wallet-qr' ).forEach( walletQr );
		} );
		document.addEventListener( 'click', function ( e ) {
			var go = e.target.closest( '.csl-wallet-design' );
			if ( ! go || ! window.CleanShortLinksDesigner ) {
				return;
			}
			var sec = document.getElementById( 'csl-sec-qr' );
			if ( sec ) {
				sec.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
			window.CleanShortLinksDesigner.show( go.dataset.entry );
		} );
		// The QR encoder is loaded after this script.
		window.addEventListener( 'load', function () {
			walletShow( walletFor ? walletFor.value : walletPanels[ 0 ].dataset.walletPanel );
		} );
		document.addEventListener( 'click', function ( e ) {
			var box = e.target.closest( '.csl-wallet-anew' ), open = e.target.closest( '.csl-wallet-anew-start' ), cancel = e.target.closest( '.csl-wallet-anew-cancel' );
			// Only one question at a time.
			$$( '.csl-wallet-anew' ).forEach( function ( b ) {
				if ( b !== box || cancel ) {
					$( '.csl-wallet-anew-confirm', b ).hidden = true;
					$( '.csl-wallet-anew-start', b ).hidden = false;
				}
			} );
			if ( open ) {
				open.hidden = true;
				$( '.csl-wallet-anew-confirm', box ).hidden = false;
				$( '.csl-wallet-anew-confirm .button-primary', box ).focus();
			}
			if ( cancel ) {
				$( '.csl-wallet-anew-start', box ).focus();
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) {
				$$( '.csl-wallet-anew-cancel' ).forEach( function ( c ) {
					if ( ! c.closest( '.csl-wallet-anew-confirm' ).hidden ) {
						c.click();
					}
				} );
			}
		} );
	}
} )();
