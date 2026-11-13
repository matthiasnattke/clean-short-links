/*
 * Clean Short Links – QR code encoder.
 *
 * A small, dependency-free implementation of ISO/IEC 18004 for what short links need:
 * versions 1 to 10, alphanumeric mode (for URLs in capitals, which gives coarser and more
 * robust codes) and byte mode (UTF-8), all four error correction levels and automatic
 * mask selection.
 *
 *   CleanShortLinksQR.encode( 'HTTPS://EXAMPLE.COM/Q/ABC', { ec: 'H' } )
 *   → { size, modules: [[bool]], version, ec, mask, mode }
 *
 * License: GPL-2.0-or-later, like the plugin.
 */
( function ( root ) {
	'use strict';

	var ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
	var FORMAT_EC = { L: 1, M: 0, Q: 3, H: 2 };

	/* Per version and level: error correction codewords per block, then [blocks, data codewords per block] groups. */
	var BLOCKS = {
		1: { L: [ 7, [ 1, 19 ] ], M: [ 10, [ 1, 16 ] ], Q: [ 13, [ 1, 13 ] ], H: [ 17, [ 1, 9 ] ] },
		2: { L: [ 10, [ 1, 34 ] ], M: [ 16, [ 1, 28 ] ], Q: [ 22, [ 1, 22 ] ], H: [ 28, [ 1, 16 ] ] },
		3: { L: [ 15, [ 1, 55 ] ], M: [ 26, [ 1, 44 ] ], Q: [ 18, [ 2, 17 ] ], H: [ 22, [ 2, 13 ] ] },
		4: { L: [ 20, [ 1, 80 ] ], M: [ 18, [ 2, 32 ] ], Q: [ 26, [ 2, 24 ] ], H: [ 16, [ 4, 9 ] ] },
		5: { L: [ 26, [ 1, 108 ] ], M: [ 24, [ 2, 43 ] ], Q: [ 18, [ 2, 15 ], [ 2, 16 ] ], H: [ 22, [ 2, 11 ], [ 2, 12 ] ] },
		6: { L: [ 18, [ 2, 68 ] ], M: [ 16, [ 4, 27 ] ], Q: [ 24, [ 4, 19 ] ], H: [ 28, [ 4, 15 ] ] },
		7: { L: [ 20, [ 2, 78 ] ], M: [ 18, [ 4, 31 ] ], Q: [ 18, [ 2, 14 ], [ 4, 15 ] ], H: [ 26, [ 4, 13 ], [ 1, 14 ] ] },
		8: { L: [ 24, [ 2, 97 ] ], M: [ 22, [ 2, 38 ], [ 2, 39 ] ], Q: [ 22, [ 4, 18 ], [ 2, 19 ] ], H: [ 26, [ 4, 14 ], [ 2, 15 ] ] },
		9: { L: [ 30, [ 2, 116 ] ], M: [ 22, [ 3, 36 ], [ 2, 37 ] ], Q: [ 20, [ 4, 16 ], [ 4, 17 ] ], H: [ 24, [ 4, 12 ], [ 4, 13 ] ] },
		10: { L: [ 18, [ 2, 68 ], [ 2, 69 ] ], M: [ 26, [ 4, 43 ], [ 1, 44 ] ], Q: [ 24, [ 6, 19 ], [ 2, 20 ] ], H: [ 28, [ 6, 15 ], [ 2, 16 ] ] },
	};
	var ALIGN = { 1: [], 2: [ 6, 18 ], 3: [ 6, 22 ], 4: [ 6, 26 ], 5: [ 6, 30 ], 6: [ 6, 34 ], 7: [ 6, 22, 38 ], 8: [ 6, 24, 42 ], 9: [ 6, 26, 46 ], 10: [ 6, 28, 50 ] };

	/* ---- Galois field GF(256) with polynomial 0x11D ---- */
	var EXP = new Array( 512 ), LOG = new Array( 256 );
	( function () {
		var x = 1;
		for ( var i = 0; i < 255; i++ ) {
			EXP[ i ] = x;
			LOG[ x ] = i;
			x <<= 1;
			if ( x & 0x100 ) {
				x ^= 0x11d;
			}
		}
		for ( var j = 255; j < 512; j++ ) {
			EXP[ j ] = EXP[ j - 255 ];
		}
	} )();
	function mul( a, b ) {
		return a && b ? EXP[ LOG[ a ] + LOG[ b ] ] : 0;
	}
	function generator( n ) {
		var g = [ 1 ];
		for ( var i = 0; i < n; i++ ) {
			var next = new Array( g.length + 1 ).fill( 0 );
			for ( var j = 0; j < g.length; j++ ) {
				next[ j ] ^= g[ j ];
				next[ j + 1 ] ^= mul( g[ j ], EXP[ i ] );
			}
			g = next;
		}
		return g;
	}
	function ecBytes( data, n ) {
		var gen = generator( n );
		var res = data.concat( new Array( n ).fill( 0 ) );
		for ( var i = 0; i < data.length; i++ ) {
			var coef = res[ i ];
			if ( coef ) {
				for ( var j = 0; j < gen.length; j++ ) {
					res[ i + j ] ^= mul( gen[ j ], coef );
				}
			}
		}
		return res.slice( data.length );
	}

	/* ---- Data ---- */
	function utf8( text ) {
		var out = [], bytes = unescape( encodeURIComponent( text ) );
		for ( var i = 0; i < bytes.length; i++ ) {
			out.push( bytes.charCodeAt( i ) );
		}
		return out;
	}
	function isAlnum( text ) {
		for ( var i = 0; i < text.length; i++ ) {
			if ( ALNUM.indexOf( text.charAt( i ) ) < 0 ) {
				return false;
			}
		}
		return true;
	}
	function dataCodewords( v, ec ) {
		var b = BLOCKS[ v ][ ec ], n = 0;
		for ( var i = 1; i < b.length; i++ ) {
			n += b[ i ][ 0 ] * b[ i ][ 1 ];
		}
		return n;
	}
	function segmentBits( text, mode, v ) {
		var bits = [];
		function put( val, len ) {
			for ( var i = len - 1; i >= 0; i-- ) {
				bits.push( ( val >>> i ) & 1 );
			}
		}
		if ( 'alnum' === mode ) {
			put( 2, 4 );
			put( text.length, v < 10 ? 9 : 11 );
			for ( var i = 0; i + 1 < text.length; i += 2 ) {
				put( ALNUM.indexOf( text.charAt( i ) ) * 45 + ALNUM.indexOf( text.charAt( i + 1 ) ), 11 );
			}
			if ( text.length % 2 ) {
				put( ALNUM.indexOf( text.charAt( text.length - 1 ) ), 6 );
			}
		} else {
			var bytes = utf8( text );
			put( 4, 4 );
			put( bytes.length, v < 10 ? 8 : 16 );
			bytes.forEach( function ( b ) {
				put( b, 8 );
			} );
		}
		return bits;
	}
	function codewords( text, mode, v, ec ) {
		var bits = segmentBits( text, mode, v ), cap = dataCodewords( v, ec ) * 8;
		if ( bits.length > cap ) {
			return null;
		}
		for ( var t = 0; t < 4 && bits.length < cap; t++ ) {
			bits.push( 0 );
		}
		while ( bits.length % 8 ) {
			bits.push( 0 );
		}
		var data = [];
		for ( var i = 0; i < bits.length; i += 8 ) {
			var byte = 0;
			for ( var j = 0; j < 8; j++ ) {
				byte = ( byte << 1 ) | bits[ i + j ];
			}
			data.push( byte );
		}
		for ( var p = 0; data.length < cap / 8; p++ ) {
			data.push( p % 2 ? 0x11 : 0xec );
		}
		// Split into blocks, add error correction, interleave.
		var spec = BLOCKS[ v ][ ec ], blocks = [], pos = 0;
		for ( var g = 1; g < spec.length; g++ ) {
			for ( var k = 0; k < spec[ g ][ 0 ]; k++ ) {
				var d = data.slice( pos, pos + spec[ g ][ 1 ] );
				pos += spec[ g ][ 1 ];
				blocks.push( { data: d, ec: ecBytes( d, spec[ 0 ] ) } );
			}
		}
		var out = [], maxData = 0;
		blocks.forEach( function ( b ) {
			maxData = Math.max( maxData, b.data.length );
		} );
		for ( var x = 0; x < maxData; x++ ) {
			blocks.forEach( function ( b ) {
				if ( x < b.data.length ) {
					out.push( b.data[ x ] );
				}
			} );
		}
		for ( var y = 0; y < spec[ 0 ]; y++ ) {
			blocks.forEach( function ( b ) {
				out.push( b.ec[ y ] );
			} );
		}
		return out;
	}

	/* ---- Matrix ---- */
	function Matrix( v ) {
		this.size = 17 + 4 * v;
		this.m = [];
		this.f = [];
		for ( var i = 0; i < this.size; i++ ) {
			this.m.push( new Array( this.size ).fill( false ) );
			this.f.push( new Array( this.size ).fill( false ) );
		}
	}
	Matrix.prototype.set = function ( x, y, dark ) {
		this.m[ y ][ x ] = !! dark;
		this.f[ y ][ x ] = true;
	};

	function drawFunctionPatterns( M, v ) {
		var s = M.size, i, j;
		for ( i = 0; i < s; i++ ) {
			M.set( 6, i, i % 2 === 0 );
			M.set( i, 6, i % 2 === 0 );
		}
		[ [ 3, 3 ], [ s - 4, 3 ], [ 3, s - 4 ] ].forEach( function ( c ) {
			for ( var dy = -4; dy <= 4; dy++ ) {
				for ( var dx = -4; dx <= 4; dx++ ) {
					var x = c[ 0 ] + dx, y = c[ 1 ] + dy, d = Math.max( Math.abs( dx ), Math.abs( dy ) );
					if ( x >= 0 && x < s && y >= 0 && y < s ) {
						M.set( x, y, d !== 2 && d !== 4 );
					}
				}
			}
		} );
		var a = ALIGN[ v ], n = a.length;
		for ( i = 0; i < n; i++ ) {
			for ( j = 0; j < n; j++ ) {
				if ( ( 0 === i && 0 === j ) || ( 0 === i && j === n - 1 ) || ( i === n - 1 && 0 === j ) ) {
					continue;
				}
				for ( var ay = -2; ay <= 2; ay++ ) {
					for ( var ax = -2; ax <= 2; ax++ ) {
						M.set( a[ i ] + ax, a[ j ] + ay, Math.max( Math.abs( ax ), Math.abs( ay ) ) !== 1 );
					}
				}
			}
		}
		drawFormat( M, 0, 0 ); // Reserve the area, real bits come later.
		if ( v >= 7 ) {
			var rem = v;
			for ( i = 0; i < 12; i++ ) {
				rem = ( rem << 1 ) ^ ( ( rem >>> 11 ) * 0x1f25 );
			}
			var bits = ( v << 12 ) | rem;
			for ( i = 0; i < 18; i++ ) {
				var bit = ( bits >>> i ) & 1, p = s - 11 + ( i % 3 ), q = Math.floor( i / 3 );
				M.set( p, q, bit );
				M.set( q, p, bit );
			}
		}
	}

	function drawFormat( M, ecBits, mask ) {
		var data = ( ecBits << 3 ) | mask, rem = data, i, s = M.size;
		for ( i = 0; i < 10; i++ ) {
			rem = ( rem << 1 ) ^ ( ( rem >>> 9 ) * 0x537 );
		}
		var bits = ( ( data << 10 ) | rem ) ^ 0x5412;
		function bit( k ) {
			return ( bits >>> k ) & 1;
		}
		for ( i = 0; i <= 5; i++ ) {
			M.set( 8, i, bit( i ) );
		}
		M.set( 8, 7, bit( 6 ) );
		M.set( 8, 8, bit( 7 ) );
		M.set( 7, 8, bit( 8 ) );
		for ( i = 9; i < 15; i++ ) {
			M.set( 14 - i, 8, bit( i ) );
		}
		for ( i = 0; i < 8; i++ ) {
			M.set( s - 1 - i, 8, bit( i ) );
		}
		for ( i = 8; i < 15; i++ ) {
			M.set( 8, s - 15 + i, bit( i ) );
		}
		M.set( 8, s - 8, true );
	}

	function drawData( M, cw ) {
		var s = M.size, i = 0, total = cw.length * 8;
		for ( var right = s - 1; right >= 1; right -= 2 ) {
			if ( 6 === right ) {
				right = 5;
			}
			for ( var vert = 0; vert < s; vert++ ) {
				for ( var j = 0; j < 2; j++ ) {
					var x = right - j, up = 0 === ( ( right + 1 ) & 2 ), y = up ? s - 1 - vert : vert;
					if ( ! M.f[ y ][ x ] ) {
						M.m[ y ][ x ] = i < total ? 1 === ( ( cw[ i >>> 3 ] >>> ( 7 - ( i & 7 ) ) ) & 1 ) : false;
						i++;
					}
				}
			}
		}
	}

	var MASKS = [
		function ( x, y ) { return ( x + y ) % 2 === 0; },
		function ( x, y ) { return y % 2 === 0; },
		function ( x ) { return x % 3 === 0; },
		function ( x, y ) { return ( x + y ) % 3 === 0; },
		function ( x, y ) { return ( Math.floor( x / 3 ) + Math.floor( y / 2 ) ) % 2 === 0; },
		function ( x, y ) { return ( x * y ) % 2 + ( x * y ) % 3 === 0; },
		function ( x, y ) { return ( ( x * y ) % 2 + ( x * y ) % 3 ) % 2 === 0; },
		function ( x, y ) { return ( ( x + y ) % 2 + ( x * y ) % 3 ) % 2 === 0; },
	];
	function applyMask( M, k ) {
		for ( var y = 0; y < M.size; y++ ) {
			for ( var x = 0; x < M.size; x++ ) {
				if ( ! M.f[ y ][ x ] && MASKS[ k ]( x, y ) ) {
					M.m[ y ][ x ] = ! M.m[ y ][ x ];
				}
			}
		}
	}

	function penalty( M ) {
		var s = M.size, m = M.m, score = 0, x, y, dark = 0;
		function line( get ) {
			var p = 0, run = 1, prev = get( 0 ), str = prev ? '1' : '0';
			for ( var i = 1; i < s; i++ ) {
				var c = get( i );
				str += c ? '1' : '0';
				if ( c === prev ) {
					run++;
				} else {
					if ( run >= 5 ) {
						p += run - 2;
					}
					run = 1;
					prev = c;
				}
			}
			if ( run >= 5 ) {
				p += run - 2;
			}
			var re = /(?=(10111010000|00001011101))/g;
			while ( re.exec( str ) ) {
				p += 40;
				re.lastIndex++;
			}
			return p;
		}
		for ( y = 0; y < s; y++ ) {
			score += line( function ( i ) { return m[ y ][ i ]; } );
		}
		for ( x = 0; x < s; x++ ) {
			score += line( function ( i ) { return m[ i ][ x ]; } );
		}
		for ( y = 0; y < s - 1; y++ ) {
			for ( x = 0; x < s - 1; x++ ) {
				var c = m[ y ][ x ];
				if ( c === m[ y ][ x + 1 ] && c === m[ y + 1 ][ x ] && c === m[ y + 1 ][ x + 1 ] ) {
					score += 3;
				}
			}
		}
		for ( y = 0; y < s; y++ ) {
			for ( x = 0; x < s; x++ ) {
				dark += m[ y ][ x ] ? 1 : 0;
			}
		}
		var total = s * s;
		score += ( Math.ceil( Math.abs( dark * 20 - total * 10 ) / total ) - 1 ) * 10;
		return score;
	}

	/**
	 * Encodes text.
	 *
	 * @param {string} text           Text, usually a URL.
	 * @param {Object} [opt]          Options.
	 * @param {string} [opt.ec]       Minimum error correction: L, M, Q or H (default M).
	 * @param {number} [opt.mask]     Force a mask 0–7 (for tests).
	 * @return {{size:number,modules:boolean[][],version:number,ec:string,mask:number,mode:string}}
	 */
	function encode( text, opt ) {
		opt = opt || {};
		var levels = [ 'H', 'Q', 'M', 'L' ], want = levels.indexOf( opt.ec || 'M' );
		var mode = isAlnum( text ) ? 'alnum' : 'byte';
		if ( want < 0 ) {
			want = 2;
		}
		// The strongest level from the wished one downwards that fits in version 10.
		for ( var li = want; li < levels.length; li++ ) {
			var ec = levels[ li ];
			for ( var v = 1; v <= 10; v++ ) {
				var cw = codewords( text, mode, v, ec );
				if ( ! cw ) {
					continue;
				}
				// A stronger level that fits the same version costs nothing – take it.
				for ( var up = 0; up < li; up++ ) {
					var stronger = codewords( text, mode, v, levels[ up ] );
					if ( stronger ) {
						ec = levels[ up ];
						cw = stronger;
						break;
					}
				}
				var best = null, bestScore = Infinity;
				for ( var k = 0; k < 8; k++ ) {
					if ( 'number' === typeof opt.mask && opt.mask !== k ) {
						continue;
					}
					var M = new Matrix( v );
					drawFunctionPatterns( M, v );
					drawData( M, cw );
					applyMask( M, k );
					drawFormat( M, FORMAT_EC[ ec ], k );
					var sc = penalty( M );
					if ( sc < bestScore ) {
						bestScore = sc;
						best = { M: M, k: k };
					}
				}
				return { size: best.M.size, modules: best.M.m, version: v, ec: ec, mask: best.k, mode: mode };
			}
		}
		throw new Error( 'Text too long for a QR code up to version 10.' );
	}

	var api = { encode: encode, isAlnum: isAlnum, _codewords: codewords };
	if ( 'object' === typeof module && module.exports ) {
		module.exports = api;
	} else {
		root.CleanShortLinksQR = api;
	}
} )( this );
