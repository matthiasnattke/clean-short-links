/*
 * Clean Short Links – QR designer.
 *
 * The design is first turned into a list of simple shapes (in QR module units). The same
 * list is then written as SVG (preview, SVG and PNG download) and as a vector PDF, so all
 * exports look identical.
 */
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
	var FONT = '"Helvetica Neue", Helvetica, Arial, sans-serif';
	var design = { fg: '#0e2440', bg: '#ffffff', transparent: 0, style: 'square', eyes: 'square', cta: '', cta_text: '', logo: 0, logo_size: 22, upper: 1, url: 0 };
	var logoData = '', logoImg = null, current = null;

	try {
		var saved = JSON.parse( store.value || '{}' );
		Object.keys( saved ).forEach( function ( k ) {
			design[ k ] = saved[ k ];
		} );
	} catch ( e ) {}

	/* ---- Controls ---- */
	box.querySelectorAll( '[data-qr]' ).forEach( function ( el ) {
		var k = el.getAttribute( 'data-qr' );
		if ( 'radio' === el.type ) {
			el.checked = String( design[ k ] ) === el.value;
		} else if ( 'checkbox' === el.type ) {
			el.checked = !! design[ k ];
		} else {
			el.value = design[ k ];
		}
		var update = function () {
			if ( 'radio' === el.type ) {
				if ( ! el.checked ) {
					return;
				}
				design[ k ] = el.value;
			} else if ( 'checkbox' === el.type ) {
				design[ k ] = el.checked ? 1 : 0;
			} else {
				design[ k ] = 'range' === el.type ? parseInt( el.value, 10 ) : el.value;
			}
			render();
		};
		el.addEventListener( 'input', update );
		el.addEventListener( 'change', update );
	} );

	/* ---- Code for the short link or one of its locations ---- */
	var codeFor = box.querySelector( 'select[data-code-for="qr"]' );
	if ( codeFor ) {
		box.dataset.slugMain = box.dataset.slug;
		codeFor.addEventListener( 'change', function () {
			var o = codeFor.selectedOptions[ 0 ].dataset;
			box.dataset.url = o.url;
			box.dataset.upper = o.upper;
			box.dataset.print = o.print;
			box.dataset.slug = o.slug || box.dataset.slugMain;
			render();
		} );
	}

	/* ---- Logo ---- */
	var pick = box.querySelector( '.csl-qr-logo-pick' ), remove = box.querySelector( '.csl-qr-logo-remove' ), sizeRow = box.querySelector( '.csl-qr-logo-size' );
	function loadLogo( url ) {
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
			logoImg = new Image();
			logoImg.onload = render;
			logoImg.src = data;
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
			logoUi();
			loadLogo( a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url );
		} );
		frame.open();
	} );
	remove.addEventListener( 'click', function () {
		design.logo = 0;
		logoData = '';
		logoImg = null;
		logoUi();
		render();
	} );

	/* ---- Geometry ---- */
	var measureCtx = document.createElement( 'canvas' ).getContext( '2d' );
	function textWidth( text, size, weight ) {
		measureCtx.font = ( weight || 700 ) + ' 100px ' + FONT;
		return measureCtx.measureText( text ).width / 100 * size;
	}
	function inEye( x, y, n ) {
		return ( x < 7 && y < 7 ) || ( x >= n - 7 && y < 7 ) || ( x < 7 && y >= n - 7 );
	}
	function ctaText() {
		return ( design.cta_text || box.dataset.cta || 'Scan me' ).trim();
	}

	/**
	 * Turns the design into shapes.
	 *
	 * @return {{W:number,H:number,shapes:Array,q:Object}}
	 */
	function layout() {
		var text = design.upper && box.dataset.upper ? box.dataset.upper : box.dataset.url;
		var q = window.CleanShortLinksQR.encode( text, { ec: design.logo ? 'H' : 'M' } );
		var n = q.size, m = q.modules, shapes = [], fg = design.fg, i, x, y;
		var ox = 4, W = n + 8, H = n + 8;
		var ph = Math.max( 4, Math.round( n * 0.16 ) ), frame = null, pill = null;

		if ( 'frame' === design.cta ) {
			var mg = 1.5, fw = 1, gap = 2;
			ox = mg + fw + gap;
			W = n + 2 * ox;
			var fs = n + 2 * gap + fw, fh = fs + ph / 2;
			frame = { x: mg + fw / 2, y: mg + fw / 2, w: fs, h: fh, sw: fw };
			pill = { cy: frame.y + fh };
			H = pill.cy + ph / 2 + mg;
		} else if ( 'pill' === design.cta ) {
			pill = { cy: ox + n + 2 + ph / 2 };
			H = pill.cy + ph / 2 + 3;
		}

		// Logo area kept free, centred and odd so it sits exactly in the middle.
		var hole = 0, h0 = 0, h1 = -1;
		if ( design.logo && logoImg ) {
			hole = Math.round( n * design.logo_size / 100 );
			hole += 0 === hole % 2 ? 1 : 0;
			h0 = ( n - hole ) / 2 - 1;
			h1 = h0 + hole + 1;
		}
		function on( xx, yy ) {
			return xx >= 0 && yy >= 0 && xx < n && yy < n && m[ yy ][ xx ] && ! inEye( xx, yy, n ) && ! ( hole && xx >= h0 && xx <= h1 && yy >= h0 && yy <= h1 );
		}
		for ( y = 0; y < n; y++ ) {
			for ( x = 0; x < n; x++ ) {
				if ( ! on( x, y ) ) {
					continue;
				}
				var X = ox + x, Y = ox + y;
				if ( 'dots' === design.style ) {
					shapes.push( { t: 'circle', cx: X + 0.5, cy: Y + 0.5, r: 0.44 } );
				} else if ( 'connected' === design.style ) {
					shapes.push( { t: 'circle', cx: X + 0.5, cy: Y + 0.5, r: 0.47 } );
					if ( on( x + 1, y ) ) {
						shapes.push( { t: 'rect', x: X + 0.5, y: Y + 0.03, w: 1, h: 0.94, r: 0 } );
					}
					if ( on( x, y + 1 ) ) {
						shapes.push( { t: 'rect', x: X + 0.03, y: Y + 0.5, w: 0.94, h: 1, r: 0 } );
					}
				} else if ( 'rounded' === design.style ) {
					shapes.push( { t: 'rect', x: X + 0.05, y: Y + 0.05, w: 0.9, h: 0.9, r: 0.32 } );
				} else {
					// Squares: join horizontal runs into one rectangle.
					var run = 1;
					while ( on( x + run, y ) ) {
						run++;
					}
					shapes.push( { t: 'rect', x: X, y: Y, w: run, h: 1, r: 0 } );
					x += run - 1;
				}
			}
		}
		// Finder patterns.
		[ [ 0, 0 ], [ n - 7, 0 ], [ 0, n - 7 ] ].forEach( function ( p ) {
			var X = ox + p[ 0 ], Y = ox + p[ 1 ];
			var r1 = { square: 0, rounded: 1.9, circle: 3 }[ design.eyes ] || 0, r2 = { square: 0, rounded: 0.9, circle: 1.5 }[ design.eyes ] || 0;
			shapes.push( { t: 'ring', x: X + 0.5, y: Y + 0.5, w: 6, h: 6, r: r1, sw: 1 } );
			shapes.push( { t: 'rect', x: X + 2, y: Y + 2, w: 3, h: 3, r: r2 } );
		} );
		if ( frame ) {
			shapes.push( { t: 'ring', x: frame.x, y: frame.y, w: frame.w, h: frame.h, r: 2.6, sw: frame.sw } );
		}
		if ( pill ) {
			var label = ctaText(), fsz = ph * 0.46, tw = textWidth( label, fsz ), ic = ph * 0.46, gapI = ph * 0.22;
			var pw = tw + ic + gapI + ph * 1.1, px = ( W - pw ) / 2, py = pill.cy - ph / 2;
			var ink = design.transparent ? '#ffffff' : design.bg;
			shapes.push( { t: 'rect', x: px, y: py, w: pw, h: ph, r: ph / 2 } );
			// A small scan symbol: four corners.
			var sx = px + ph * 0.55, sy = pill.cy - ic / 2, c = ic * 0.32, sw = ph * 0.075;
			[ [ [ sx, sy + c ], [ sx, sy ], [ sx + c, sy ] ], [ [ sx + ic - c, sy ], [ sx + ic, sy ], [ sx + ic, sy + c ] ], [ [ sx + ic, sy + ic - c ], [ sx + ic, sy + ic ], [ sx + ic - c, sy + ic ] ], [ [ sx + c, sy + ic ], [ sx, sy + ic ], [ sx, sy + ic - c ] ] ].forEach( function ( pts ) {
				shapes.push( { t: 'line', pts: pts, sw: sw, c: ink } );
			} );
			shapes.push( { t: 'line', pts: [ [ sx + ic * 0.22, pill.cy ], [ sx + ic * 0.78, pill.cy ] ], sw: sw, c: ink } );
			shapes.push( { t: 'text', x: sx + ic + gapI, y: pill.cy + fsz * 0.36, size: fsz, text: label, w: tw, c: ink } );
		}
		// The address in plain text below everything, for people who want to type it.
		if ( design.url && box.dataset.print ) {
			var addr = box.dataset.print, asz = Math.max( 2.2, n * 0.075 ), aw = textWidth( addr, asz, 400 );
			if ( aw > W - 4 ) {
				asz *= ( W - 4 ) / aw;
				aw = W - 4;
			}
			var top = pill ? pill.cy + ph / 2 + 1.6 : ox + n + 2;
			shapes.push( { t: 'text', x: ( W - aw ) / 2, y: top + asz * 0.8, size: asz, text: addr, w: aw, wt: 400 } );
			H = Math.max( H, top + asz * 1.1 + ( frame ? 1.5 : 3 ) );
		}
		if ( hole ) {
			var size = hole * 0.86, off = ( n - size ) / 2;
			shapes.push( { t: 'image', x: ox + off, y: ox + off, w: size, h: size } );
		}
		for ( i = 0; i < shapes.length; i++ ) {
			shapes[ i ].c = shapes[ i ].c || fg;
		}
		return { W: W, H: H, shapes: shapes, q: q };
	}

	/* ---- SVG ---- */
	function esc( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
	}
	function f( v ) {
		return Math.round( v * 1000 ) / 1000;
	}
	function buildSvg( L ) {
		var out = [];
		L.shapes.forEach( function ( s ) {
			var c = esc( s.c );
			if ( 'rect' === s.t ) {
				out.push( '<rect x="' + f( s.x ) + '" y="' + f( s.y ) + '" width="' + f( s.w ) + '" height="' + f( s.h ) + '"' + ( s.r ? ' rx="' + f( s.r ) + '"' : '' ) + ' fill="' + c + '"/>' );
			} else if ( 'circle' === s.t ) {
				out.push( '<circle cx="' + f( s.cx ) + '" cy="' + f( s.cy ) + '" r="' + f( s.r ) + '" fill="' + c + '"/>' );
			} else if ( 'ring' === s.t ) {
				out.push( '<rect x="' + f( s.x ) + '" y="' + f( s.y ) + '" width="' + f( s.w ) + '" height="' + f( s.h ) + '" rx="' + f( s.r ) + '" fill="none" stroke="' + c + '" stroke-width="' + f( s.sw ) + '"/>' );
			} else if ( 'line' === s.t ) {
				out.push( '<polyline points="' + s.pts.map( function ( p ) {
					return f( p[ 0 ] ) + ',' + f( p[ 1 ] );
				} ).join( ' ' ) + '" fill="none" stroke="' + c + '" stroke-width="' + f( s.sw ) + '" stroke-linecap="round" stroke-linejoin="round"/>' );
			} else if ( 'text' === s.t ) {
				out.push( '<text x="' + f( s.x ) + '" y="' + f( s.y ) + '" font-family="' + esc( FONT ) + '" font-weight="' + ( s.wt || 700 ) + '" font-size="' + f( s.size ) + '" fill="' + c + '">' + esc( s.text ) + '</text>' );
			} else if ( 'image' === s.t && logoData ) {
				out.push( '<image href="' + esc( logoData ) + '" x="' + f( s.x ) + '" y="' + f( s.y ) + '" width="' + f( s.w ) + '" height="' + f( s.h ) + '" preserveAspectRatio="xMidYMid meet"/>' );
			}
		} );
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + f( L.W ) + ' ' + f( L.H ) + '" width="' + f( L.W * 10 ) + '" height="' + f( L.H * 10 ) + '"' + ( 'square' === design.style ? ' shape-rendering="crispEdges"' : '' ) + '>' +
			( design.transparent ? '' : '<rect width="' + f( L.W ) + '" height="' + f( L.H ) + '" fill="' + esc( design.bg ) + '"/>' ) +
			out.join( '' ) + '</svg>';
	}

	/* ---- PDF (vector) ---- */
	function rgb( hex ) {
		var c = hex.replace( '#', '' );
		if ( 3 === c.length ) {
			c = c[ 0 ] + c[ 0 ] + c[ 1 ] + c[ 1 ] + c[ 2 ] + c[ 2 ];
		}
		return [ 0, 2, 4 ].map( function ( i ) {
			return ( parseInt( c.substr( i, 2 ), 16 ) / 255 ).toFixed( 4 );
		} ).join( ' ' );
	}
	function deflate( bytes ) {
		var cs = new CompressionStream( 'deflate' );
		var stream = new Blob( [ bytes ] ).stream().pipeThrough( cs );
		return new Response( stream ).arrayBuffer().then( function ( b ) {
			return new Uint8Array( b );
		} );
	}
	function latin1( s ) {
		var out = new Uint8Array( s.length );
		for ( var i = 0; i < s.length; i++ ) {
			var code = s.charCodeAt( i );
			out[ i ] = code < 256 ? code : 63;
		}
		return out;
	}
	function logoPixels( maxSide ) {
		if ( ! logoImg ) {
			return null;
		}
		var r = Math.min( 1, maxSide / Math.max( logoImg.naturalWidth, logoImg.naturalHeight ) );
		var w = Math.max( 1, Math.round( logoImg.naturalWidth * r ) ), h = Math.max( 1, Math.round( logoImg.naturalHeight * r ) );
		var c = document.createElement( 'canvas' );
		c.width = w;
		c.height = h;
		var g = c.getContext( '2d' );
		g.drawImage( logoImg, 0, 0, w, h );
		var d = g.getImageData( 0, 0, w, h ).data, rgbB = new Uint8Array( w * h * 3 ), a = new Uint8Array( w * h );
		for ( var i = 0, j = 0; i < d.length; i += 4, j++ ) {
			rgbB[ j * 3 ] = d[ i ];
			rgbB[ j * 3 + 1 ] = d[ i + 1 ];
			rgbB[ j * 3 + 2 ] = d[ i + 2 ];
			a[ j ] = d[ i + 3 ];
		}
		return { w: w, h: h, rgb: rgbB, alpha: a };
	}
	function buildPdf( L ) {
		var u = 240 / L.W, PW = L.W * u, PH = L.H * u, ops = [];
		function X( v ) {
			return ( v * u ).toFixed( 3 );
		}
		function Y( v ) {
			return ( ( L.H - v ) * u ).toFixed( 3 );
		}
		function roundRect( x, y, w, h, r ) {
			if ( ! r ) {
				return X( x ) + ' ' + Y( y + h ) + ' ' + X( w ) + ' ' + ( h * u ).toFixed( 3 ) + ' re';
			}
			r = Math.min( r, w / 2, h / 2 );
			var k = 0.5523 * r, p = [];
			p.push( X( x + r ) + ' ' + Y( y ) + ' m' );
			p.push( X( x + w - r ) + ' ' + Y( y ) + ' l' );
			p.push( X( x + w - r + k ) + ' ' + Y( y ) + ' ' + X( x + w ) + ' ' + Y( y + r - k ) + ' ' + X( x + w ) + ' ' + Y( y + r ) + ' c' );
			p.push( X( x + w ) + ' ' + Y( y + h - r ) + ' l' );
			p.push( X( x + w ) + ' ' + Y( y + h - r + k ) + ' ' + X( x + w - r + k ) + ' ' + Y( y + h ) + ' ' + X( x + w - r ) + ' ' + Y( y + h ) + ' c' );
			p.push( X( x + r ) + ' ' + Y( y + h ) + ' l' );
			p.push( X( x + r - k ) + ' ' + Y( y + h ) + ' ' + X( x ) + ' ' + Y( y + h - r + k ) + ' ' + X( x ) + ' ' + Y( y + h - r ) + ' c' );
			p.push( X( x ) + ' ' + Y( y + r ) + ' l' );
			p.push( X( x ) + ' ' + Y( y + r - k ) + ' ' + X( x + r - k ) + ' ' + Y( y ) + ' ' + X( x + r ) + ' ' + Y( y ) + ' c h' );
			return p.join( '\n' );
		}
		if ( ! design.transparent ) {
			ops.push( rgb( design.bg ) + ' rg 0 0 ' + PW.toFixed( 3 ) + ' ' + PH.toFixed( 3 ) + ' re f' );
		}
		ops.push( '1 J 1 j' );
		var img = null;
		L.shapes.forEach( function ( s ) {
			if ( 'rect' === s.t ) {
				ops.push( rgb( s.c ) + ' rg ' + roundRect( s.x, s.y, s.w, s.h, s.r ) + ' f' );
			} else if ( 'circle' === s.t ) {
				ops.push( rgb( s.c ) + ' rg ' + roundRect( s.cx - s.r, s.cy - s.r, 2 * s.r, 2 * s.r, s.r ) + ' f' );
			} else if ( 'ring' === s.t ) {
				ops.push( rgb( s.c ) + ' RG ' + ( s.sw * u ).toFixed( 3 ) + ' w ' + roundRect( s.x, s.y, s.w, s.h, s.r ) + ' S' );
			} else if ( 'line' === s.t ) {
				ops.push( rgb( s.c ) + ' RG ' + ( s.sw * u ).toFixed( 3 ) + ' w ' + s.pts.map( function ( p, i ) {
					return X( p[ 0 ] ) + ' ' + Y( p[ 1 ] ) + ( i ? ' l' : ' m' );
				} ).join( ' ' ) + ' S' );
			} else if ( 'text' === s.t ) {
				var str = s.text.replace( /\\/g, '\\\\' ).replace( /\(/g, '\\(' ).replace( /\)/g, '\\)' );
				ops.push( 'BT ' + rgb( s.c ) + ' rg /' + ( 400 === s.wt ? 'F2' : 'F1' ) + ' ' + ( s.size * u ).toFixed( 3 ) + ' Tf ' + X( s.x ) + ' ' + Y( s.y ) + ' Td (' + str + ') Tj ET' );
			} else if ( 'image' === s.t ) {
				img = logoPixels( 600 );
				if ( img ) {
					// Fit the logo into the square like preserveAspectRatio "meet".
					var sc = Math.min( s.w / img.w, s.h / img.h ), iw = img.w * sc, ih = img.h * sc, ix = s.x + ( s.w - iw ) / 2, iy = s.y + ( s.h - ih ) / 2;
					ops.push( 'q ' + ( iw * u ).toFixed( 3 ) + ' 0 0 ' + ( ih * u ).toFixed( 3 ) + ' ' + X( ix ) + ' ' + Y( iy + ih ) + ' cm /Im1 Do Q' );
				}
			}
		} );
		var content = ops.join( '\n' );
		return Promise.all( img ? [ deflate( img.rgb ), deflate( img.alpha ) ] : [] ).then( function ( z ) {
			var objs = [];
			objs[ 1 ] = { d: '<< /Type /Catalog /Pages 2 0 R >>' };
			objs[ 2 ] = { d: '<< /Type /Pages /Kids [3 0 R] /Count 1 >>' };
			objs[ 3 ] = { d: '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' + PW.toFixed( 3 ) + ' ' + PH.toFixed( 3 ) + '] /Resources << /Font << /F1 5 0 R /F2 6 0 R >>' + ( img ? ' /XObject << /Im1 7 0 R >>' : '' ) + ' >> /Contents 4 0 R >>' };
			objs[ 4 ] = { d: '<< /Length ' + content.length + ' >>', s: latin1( content ) };
			objs[ 5 ] = { d: '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>' };
			objs[ 6 ] = { d: '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' };
			if ( img ) {
				objs[ 7 ] = { d: '<< /Type /XObject /Subtype /Image /Width ' + img.w + ' /Height ' + img.h + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /SMask 8 0 R /Length ' + z[ 0 ].length + ' >>', s: z[ 0 ] };
				objs[ 8 ] = { d: '<< /Type /XObject /Subtype /Image /Width ' + img.w + ' /Height ' + img.h + ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' + z[ 1 ].length + ' >>', s: z[ 1 ] };
			}
			var parts = [ latin1( '%PDF-1.4\n%âãÏÓ\n' ) ], offsets = [], pos = parts[ 0 ].length;
			for ( var i = 1; i < objs.length; i++ ) {
				offsets[ i ] = pos;
				var head = latin1( i + ' 0 obj\n' + objs[ i ].d + ( objs[ i ].s ? '\nstream\n' : '' ) );
				parts.push( head );
				pos += head.length;
				if ( objs[ i ].s ) {
					parts.push( objs[ i ].s );
					pos += objs[ i ].s.length;
				}
				var tail = latin1( ( objs[ i ].s ? '\nendstream' : '' ) + '\nendobj\n' );
				parts.push( tail );
				pos += tail.length;
			}
			var xref = 'xref\n0 ' + objs.length + '\n0000000000 65535 f \n';
			for ( var j = 1; j < objs.length; j++ ) {
				xref += String( offsets[ j ] ).padStart( 10, '0' ) + ' 00000 n \n';
			}
			xref += 'trailer\n<< /Size ' + objs.length + ' /Root 1 0 R >>\nstartxref\n' + pos + '\n%%EOF\n';
			parts.push( latin1( xref ) );
			return new Blob( parts, { type: 'application/pdf' } );
		} );
	}

	/* ---- Preview ---- */
	function luminance( hex ) {
		var c = hex.replace( '#', '' );
		if ( 3 === c.length ) {
			c = c[ 0 ] + c[ 0 ] + c[ 1 ] + c[ 1 ] + c[ 2 ] + c[ 2 ];
		}
		var v = [ 0, 2, 4 ].map( function ( i ) {
			var x = parseInt( c.substr( i, 2 ), 16 ) / 255;
			return x <= 0.03928 ? x / 12.92 : Math.pow( ( x + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * v[ 0 ] + 0.7152 * v[ 1 ] + 0.0722 * v[ 2 ];
	}
	function render() {
		var L;
		try {
			L = layout();
		} catch ( e ) {
			preview.textContent = T.tooLong;
			return;
		}
		current = L;
		preview.innerHTML = buildSvg( L );
		info.textContent = T.qrInfo.replace( '%1$s', L.q.version ).replace( '%2$s', L.q.ec ).split( '%3$s' ).join( L.q.size ) + ( 'alnum' === L.q.mode ? ' · ' + T.compact : '' );
		var lf = luminance( design.fg ), lb = design.transparent ? 1 : luminance( design.bg ), ratio = ( Math.max( lf, lb ) + 0.05 ) / ( Math.min( lf, lb ) + 0.05 );
		warn.textContent = design.transparent ? ( lf > 0.4 ? T.contrast : '' ) : ( lf > lb ? T.inverted : ( ratio < 4 ? T.contrast : '' ) );
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
		}, 1000 );
	}
	function pngBlob() {
		var px = parseInt( document.getElementById( 'csl-qr-px' ).value, 10 ) || 1024;
		var L = layout(), w = px, h = Math.round( px * L.H / L.W );
		return new Promise( function ( ok, fail ) {
			var img = new Image();
			img.onload = function () {
				var c = document.createElement( 'canvas' );
				c.width = w;
				c.height = h;
				c.getContext( '2d' ).drawImage( img, 0, 0, w, h );
				c.toBlob( ok, 'image/png' );
			};
			img.onerror = fail;
			img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( buildSvg( L ) );
		} );
	}
	box.querySelector( '.csl-qr-svg' ).addEventListener( 'click', function () {
		download( new Blob( [ '<?xml version="1.0" encoding="UTF-8"?>\n' + buildSvg( layout() ) ], { type: 'image/svg+xml' } ), filename( 'svg' ) );
	} );
	box.querySelector( '.csl-qr-png' ).addEventListener( 'click', function () {
		pngBlob().then( function ( b ) {
			download( b, filename( 'png' ) );
		} );
	} );
	box.querySelector( '.csl-qr-pdf' ).addEventListener( 'click', function () {
		buildPdf( layout() ).then( function ( b ) {
			download( b, filename( 'pdf' ) );
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

	/* For tests: the current shapes and exports. */
	box.cleanShortLinks = { layout: layout, svg: function () {
		return buildSvg( layout() );
	}, pdf: function () {
		return buildPdf( layout() );
	}, png: pngBlob };

	/* ---- Open on demand ---- */
	function open() {
		box.hidden = false;
		document.querySelector( '.csl-qr-start .csl-qr-open' ).hidden = true;
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
	if ( '#csl-sec-qr' === window.location.hash || ( store.value.length > 2 && ( design.logo || design.cta || design.url || 'square' !== design.style || design.transparent ) ) ) {
		open();
	}
} )();
