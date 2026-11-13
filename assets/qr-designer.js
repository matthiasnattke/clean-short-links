/* Clean Short Links – QR designer: preview, logo from the media library, SVG/PNG export. */
( function () {
	'use strict';
	var D = window.CleanShortLinksAdmin || { i18n: {} };
	var T = D.i18n;
	var box = document.querySelector( '.csl-designer' );
	if ( ! box || ! window.CleanShortLinksQR ) {
		return;
	}
	var store = document.getElementById( 'csl-qr-design' );
	var preview = box.querySelector( '.csl-qr-preview' );
	var info = box.querySelector( '.csl-qr-info' );
	var warn = box.querySelector( '.csl-qr-warn' );
	var design = { fg: '#0e2440', bg: '#ffffff', style: 'square', eyes: 'square', logo: 0, logo_size: 22, upper: 1 };
	var logoData = '', logoUrl = '', current = null;

	try {
		var saved = JSON.parse( store.value || '{}' );
		Object.keys( saved ).forEach( function ( k ) {
			design[ k ] = saved[ k ];
		} );
	} catch ( e ) {}

	/* ---- Controls ---- */
	box.querySelectorAll( '[data-qr]' ).forEach( function ( el ) {
		var k = el.getAttribute( 'data-qr' );
		if ( 'checkbox' === el.type ) {
			el.checked = !! design[ k ];
		} else {
			el.value = design[ k ];
		}
		el.addEventListener( 'input', function () {
			design[ k ] = 'checkbox' === el.type ? ( el.checked ? 1 : 0 ) : ( 'range' === el.type ? parseInt( el.value, 10 ) : el.value );
			render();
		} );
	} );

	/* ---- Logo ---- */
	var pick = box.querySelector( '.csl-qr-logo-pick' ), remove = box.querySelector( '.csl-qr-logo-remove' ), sizeRow = box.querySelector( '.csl-qr-logo-size' );
	function loadLogo( url ) {
		logoUrl = url;
		return fetch( url, { credentials: 'same-origin' } ).then( function ( r ) {
			return r.blob();
		} ).then( function ( b ) {
			return new Promise( function ( ok ) {
				var fr = new FileReader();
				fr.onload = function () {
					ok( fr.result );
				};
				fr.readAsDataURL( b );
			} );
		} ).then( function ( data ) {
			logoData = data;
			render();
		} );
	}
	function logoUi() {
		remove.hidden = ! design.logo;
		sizeRow.hidden = ! design.logo;
	}
	pick.addEventListener( 'click', function () {
		var frame = window.wp.media( { title: T.chooseLogo, button: { text: T.useLogo }, library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var a = frame.state().get( 'selection' ).first().toJSON();
			design.logo = a.id;
			var url = a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url;
			logoUi();
			loadLogo( url );
		} );
		frame.open();
	} );
	remove.addEventListener( 'click', function () {
		design.logo = 0;
		logoData = '';
		logoUi();
		render();
	} );

	/* ---- Drawing ---- */
	function luminance( hex ) {
		var c = hex.replace( '#', '' );
		if ( 3 === c.length ) {
			c = c[ 0 ] + c[ 0 ] + c[ 1 ] + c[ 1 ] + c[ 2 ] + c[ 2 ];
		}
		var rgb = [ 0, 2, 4 ].map( function ( i ) {
			var v = parseInt( c.substr( i, 2 ), 16 ) / 255;
			return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * rgb[ 0 ] + 0.7152 * rgb[ 1 ] + 0.0722 * rgb[ 2 ];
	}
	function esc( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
	}
	function inEye( x, y, n ) {
		return ( x < 7 && y < 7 ) || ( x >= n - 7 && y < 7 ) || ( x < 7 && y >= n - 7 );
	}

	/**
	 * Builds the SVG of the current design.
	 *
	 * @return {string}
	 */
	function buildSvg() {
		var text = design.upper && box.dataset.upper ? box.dataset.upper : box.dataset.url;
		var q = window.CleanShortLinksQR.encode( text, { ec: design.logo ? 'H' : 'M' } );
		current = q;
		var n = q.size, qz = 4, full = n + 2 * qz, fg = esc( design.fg ), bg = esc( design.bg ), parts = [];
		// Area kept free for the logo, in modules, centred and odd so it sits exactly in the middle.
		var hole = 0, h0 = 0, h1 = -1;
		if ( design.logo && logoData ) {
			hole = Math.round( n * design.logo_size / 100 );
			hole += 0 === hole % 2 ? 1 : 0;
			h0 = ( n - hole ) / 2 - 1;
			h1 = h0 + hole + 1;
		}
		var path = '';
		for ( var y = 0; y < n; y++ ) {
			for ( var x = 0; x < n; x++ ) {
				if ( ! q.modules[ y ][ x ] || inEye( x, y, n ) || ( hole && x >= h0 && x <= h1 && y >= h0 && y <= h1 ) ) {
					continue;
				}
				var X = x + qz, Y = y + qz;
				if ( 'dots' === design.style ) {
					parts.push( '<circle cx="' + ( X + 0.5 ) + '" cy="' + ( Y + 0.5 ) + '" r="0.45"/>' );
				} else if ( 'rounded' === design.style ) {
					parts.push( '<rect x="' + ( X + 0.05 ) + '" y="' + ( Y + 0.05 ) + '" width="0.9" height="0.9" rx="0.32"/>' );
				} else {
					path += 'M' + X + ' ' + Y + 'h1v1h-1z';
				}
			}
		}
		if ( path ) {
			parts.push( '<path d="' + path + '"/>' );
		}
		// Finder patterns ("eyes").
		var r1 = 'rounded' === design.eyes ? 2 : 0, r2 = 'rounded' === design.eyes ? 1 : 0;
		[ [ 0, 0 ], [ n - 7, 0 ], [ 0, n - 7 ] ].forEach( function ( p ) {
			var X = p[ 0 ] + qz, Y = p[ 1 ] + qz;
			parts.push( '<rect x="' + ( X + 0.5 ) + '" y="' + ( Y + 0.5 ) + '" width="6" height="6" rx="' + r1 + '" fill="none" stroke="' + fg + '" stroke-width="1"/>' );
			parts.push( '<rect x="' + ( X + 2 ) + '" y="' + ( Y + 2 ) + '" width="3" height="3" rx="' + r2 + '"/>' );
		} );
		var logo = '';
		if ( hole ) {
			var size = hole * 0.86, off = qz + ( n - size ) / 2;
			logo = '<image href="' + esc( logoData ) + '" x="' + off + '" y="' + off + '" width="' + size + '" height="' + size + '" preserveAspectRatio="xMidYMid meet"/>';
		}
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + full + ' ' + full + '" shape-rendering="' + ( 'square' === design.style ? 'crispEdges' : 'geometricPrecision' ) + '">' +
			'<rect width="' + full + '" height="' + full + '" fill="' + bg + '"/>' +
			'<g fill="' + fg + '">' + parts.join( '' ) + '</g>' + logo + '</svg>';
	}

	function render() {
		var svg;
		try {
			svg = buildSvg();
		} catch ( e ) {
			preview.textContent = T.tooLong;
			return;
		}
		preview.innerHTML = svg;
		info.textContent = T.qrInfo.replace( '%1$s', current.version ).replace( '%2$s', current.ec ).split( '%3$s' ).join( current.size ) + ( 'alnum' === current.mode ? ' · ' + T.compact : '' );
		var lf = luminance( design.fg ), lb = luminance( design.bg ), ratio = ( Math.max( lf, lb ) + 0.05 ) / ( Math.min( lf, lb ) + 0.05 );
		warn.textContent = lf > lb ? T.inverted : ( ratio < 4 ? T.contrast : '' );
		store.value = JSON.stringify( design );
	}

	/* ---- Export ---- */
	function filename( ext ) {
		return 'qr-' + ( box.dataset.slug || 'code' ) + '.' + ext;
	}
	function download( blob, name ) {
		var a = document.createElement( 'a' );
		a.href = URL.createObjectURL( blob );
		a.download = name;
		document.body.appendChild( a );
		a.click();
		setTimeout( function () {
			URL.revokeObjectURL( a.href );
			a.remove();
		}, 500 );
	}
	function svgBlob() {
		return new Blob( [ '<?xml version="1.0" encoding="UTF-8"?>\n' + buildSvg() ], { type: 'image/svg+xml' } );
	}
	function pngBlob() {
		var px = parseInt( document.getElementById( 'csl-qr-px' ).value, 10 ) || 1024;
		return new Promise( function ( ok, fail ) {
			var img = new Image();
			img.onload = function () {
				var c = document.createElement( 'canvas' );
				c.width = c.height = px;
				var g = c.getContext( '2d' );
				g.imageSmoothingEnabled = false;
				g.drawImage( img, 0, 0, px, px );
				c.toBlob( ok, 'image/png' );
			};
			img.onerror = fail;
			img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( buildSvg() );
		} );
	}
	box.querySelector( '.csl-qr-svg' ).addEventListener( 'click', function () {
		download( svgBlob(), filename( 'svg' ) );
	} );
	box.querySelector( '.csl-qr-png' ).addEventListener( 'click', function () {
		pngBlob().then( function ( b ) {
			download( b, filename( 'png' ) );
		} );
	} );
	box.querySelector( '.csl-qr-media' ).addEventListener( 'click', function () {
		var btn = this;
		btn.disabled = true;
		pngBlob().then( function ( b ) {
			var fd = new FormData();
			fd.append( 'file', b, filename( 'png' ) );
			return window.wp.apiFetch( { path: '/wp/v2/media', method: 'POST', body: fd } );
		} ).then( function () {
			warn.textContent = T.saved;
		} ).catch( function () {
			warn.textContent = T.saveFailed;
		} ).then( function () {
			btn.disabled = false;
		} );
	} );

	/* ---- Open on demand ---- */
	function open() {
		box.hidden = false;
		document.querySelector( '.csl-qr-open' ).hidden = true;
		logoUi();
		if ( design.logo && window.wp.media ) {
			var att = window.wp.media.attachment( design.logo );
			att.fetch().then( function () {
				var s = att.get( 'sizes' );
				loadLogo( s && s.medium ? s.medium.url : att.get( 'url' ) );
			} );
		}
		render();
	}
	document.querySelector( '.csl-qr-open' ).addEventListener( 'click', open );
	if ( '#csl-qr' === window.location.hash || ( design.logo || 'square' !== design.style || '#0e2440' !== design.fg ) && store.value.length > 2 ) {
		open();
	}
} )();
