/* Clean Short Links – panel in the sidebar of the block editor: the short link of the post
 * or page with its essential settings. Everything else stays on the full edit screen. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.plugins || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement, useState = wp.element.useState, useEffect = wp.element.useEffect, useRef = wp.element.useRef;
	var C = wp.components, D = window.CleanShortLinksEditor || { i18n: {} }, T = D.i18n;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}
	var API = '/clean-short-links/v1/';
	var FIELDS = [ 'slug', 'kind', 'keep_old', 'root', 'paused', 'start_mode', 'starts_at', 'ends_at', 'max_clicks', 'reset_clicks', 'permanent', 'bio' ];
	var muted = { color: '#757575' };

	function sprintf( text ) {
		var args = Array.prototype.slice.call( arguments, 1 ), i = 0;
		return text.replace( /%(\d\$)?[sd]/g, function ( m, pos ) {
			return String( pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ] );
		} );
	}

	function formFrom( link ) {
		return {
			slug: link.slug,
			kind: link.kind,
			keep_old: true,
			root: link.root,
			paused: link.paused,
			start_mode: link.start_mode,
			starts_at: link.starts_at,
			ends_at: link.ends_at,
			max_clicks: String( link.max_clicks || 0 ),
			reset_clicks: false,
			permanent: link.permanent,
			bio: link.bio,
		};
	}

	function isDirty( form, link ) {
		var base = formFrom( link );
		return FIELDS.some( function ( k ) {
			return 'keep_old' !== k && String( form[ k ] ) !== String( base[ k ] );
		} );
	}

	function Copy( props ) {
		var s = useState( false ), copied = s[ 0 ], setCopied = s[ 1 ];
		return el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '6px', margin: '0 0 8px' } },
			props.label ? el( 'small', { style: muted }, props.label ) : null,
			el( 'code', { style: { flex: '1', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, props.url.replace( /^https?:\/\//, '' ) ),
			el( C.Button, {
				variant: 'secondary',
				size: 'small',
				onClick: function () {
					navigator.clipboard.writeText( props.url ).then( function () {
						setCopied( true );
						setTimeout( function () {
							setCopied( false );
						}, 1500 );
					} );
				},
			}, copied ? T.copied : T.copy )
		);
	}

	function CheckLine( props ) {
		var c = props.link.check;
		var name = { ok: T.checkOk, warning: T.checkWarn, broken: T.checkBad }[ c.status ] || T.checkNone;
		var color = { ok: '#00701c', warning: '#996800', broken: '#cc1818' }[ c.status ] || '#757575';
		return el( 'div', { style: { margin: '12px 0' } },
			el( 'div', { style: { color: color, fontWeight: 600 } }, name, c.at ? el( 'small', { style: { fontWeight: 400, color: '#757575' } }, ' · ' + c.at ) : null ),
			c.problems.length ? el( 'ul', { style: { margin: '4px 0 0 16px', listStyle: 'disc' } }, c.problems.map( function ( p, i ) {
				return el( 'li', { key: i }, p );
			} ) ) : null,
			el( C.Button, { variant: 'link', isBusy: props.busy, disabled: props.busy, onClick: props.onCheck }, T.checkNow )
		);
	}

	function Settings( props ) {
		var link = props.link, form = props.form, set = props.set;
		var title = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
		}, [] );
		var s1 = useState( '' ), slugMsg = s1[ 0 ], setSlugMsg = s1[ 1 ];
		var s2 = useState( '' ), rootTaken = s2[ 0 ], setRootTaken = s2[ 1 ];

		function checkSlug( slug ) {
			if ( ! slug ) {
				return;
			}
			wp.apiFetch( { path: API + 'check?slug=' + encodeURIComponent( slug ) + '&id=' + link.id } ).then( function ( r ) {
				setSlugMsg( r.ok ? '' : r.message );
				setRootTaken( r.root || '' );
				if ( r.slug !== slug ) {
					set( { slug: r.slug } );
				}
			} );
		}

		function suggest( kind ) {
			wp.apiFetch( { path: API + 'suggest?kind=' + kind + '&title=' + encodeURIComponent( title ) } ).then( function ( r ) {
				set( { slug: r.slug, kind: kind } );
				checkSlug( r.slug );
			} );
		}

		var max = parseInt( form.max_clicks, 10 ) || 0;
		return el( 'div', { className: 'csl-ed-settings' },
			el( C.ToggleControl, {
				label: T.active,
				checked: ! form.paused,
				onChange: function ( v ) {
					set( { paused: ! v } );
				},
				__nextHasNoMarginBottom: true,
			} ),
			el( 'div', { style: { marginTop: '16px' } },
				el( C.TextControl, {
					label: T.address,
					value: form.slug,
					help: slugMsg ? el( 'span', { style: { color: '#cc1818' } }, slugMsg ) : D.base + ( form.slug || '…' ),
					onChange: function ( v ) {
						set( { slug: v, kind: 'custom' } );
						setSlugMsg( '' );
					},
					onBlur: function () {
						checkSlug( form.slug );
					},
					__nextHasNoMarginBottom: true,
				} ),
				el( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: '4px', margin: '6px 0 0' } },
					[ [ 'random', T.random ], [ 'memorable', T.memorable ], [ 'title', T.title ] ].map( function ( k ) {
						return el( C.Button, { key: k[ 0 ], variant: 'secondary', size: 'small', onClick: function () {
							suggest( k[ 0 ] );
						} }, k[ 1 ] );
					} )
				),
				form.slug !== link.slug ? el( 'div', { style: { marginTop: '8px' } }, el( C.CheckboxControl, {
					label: T.keepOld,
					checked: form.keep_old,
					onChange: function ( v ) {
						set( { keep_old: v } );
					},
					__nextHasNoMarginBottom: true,
				} ) ) : null
			),
			D.root ? el( 'div', { style: { marginTop: '12px' } },
				el( C.ToggleControl, {
					label: T.rootToggle,
					checked: form.root,
					help: form.root && rootTaken ? sprintf( T.rootTaken, rootTaken ) : null,
					onChange: function ( v ) {
						set( { root: v } );
						if ( v ) {
							checkSlug( form.slug );
						}
					},
					__nextHasNoMarginBottom: true,
				} )
			) : null,
			el( 'div', { style: { marginTop: '16px' } },
				el( C.SelectControl, {
					label: T.start,
					value: form.start_mode,
					options: [ { label: T.startNow, value: '' }, { label: T.startPub, value: 'publish' }, { label: T.startDate, value: 'date' } ],
					onChange: function ( v ) {
						set( { start_mode: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				'date' === form.start_mode ? el( 'div', { style: { marginTop: '8px' } }, el( C.TextControl, {
					type: 'datetime-local',
					label: T.startDate,
					hideLabelFromVision: true,
					value: form.starts_at,
					onChange: function ( v ) {
						set( { starts_at: v } );
					},
					__nextHasNoMarginBottom: true,
				} ) ) : null
			),
			el( 'div', { style: { marginTop: '12px' } }, el( C.TextControl, {
				type: 'datetime-local',
				label: T.end,
				help: T.endHelp,
				value: form.ends_at,
				onChange: function ( v ) {
					set( { ends_at: v } );
				},
				__nextHasNoMarginBottom: true,
			} ) ),
			el( 'div', { style: { marginTop: '12px' } },
				el( C.TextControl, {
					type: 'number',
					min: 0,
					label: T.limit,
					help: max ? sprintf( T.used, form.reset_clicks ? 0 : link.clicks, max ) : T.limitHelp,
					value: form.max_clicks,
					onChange: function ( v ) {
						set( { max_clicks: v } );
					},
					__nextHasNoMarginBottom: true,
				} ),
				max && link.clicks ? el( 'div', { style: { marginTop: '6px' } }, el( C.CheckboxControl, {
					label: T.reset,
					checked: form.reset_clicks,
					onChange: function ( v ) {
						set( { reset_clicks: v } );
					},
					__nextHasNoMarginBottom: true,
				} ) ) : null
			),
			el( 'div', { style: { marginTop: '16px' } }, el( C.ToggleControl, {
				label: T.permanent,
				checked: form.permanent,
				help: form.permanent ? T.permHelp : null,
				onChange: function ( v ) {
					set( { permanent: v } );
				},
				__nextHasNoMarginBottom: true,
			} ) ),
			D.bio ? el( 'div', { style: { marginTop: '12px' } }, el( C.ToggleControl, {
				label: T.bioToggle,
				checked: form.bio,
				onChange: function ( v ) {
					set( { bio: v } );
				},
				__nextHasNoMarginBottom: true,
			} ) ) : null
		);
	}

	function ShortLinkPanel() {
		var postId = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );
		var isNew = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).isEditedPostNew();
		}, [] );
		var saving = wp.data.useSelect( function ( select ) {
			var e = select( 'core/editor' );
			return e.isSavingPost() && ! e.isAutosavingPost();
		}, [] );
		var s1 = useState( undefined ), link = s1[ 0 ], setLinkState = s1[ 1 ];
		var s2 = useState( D.kind || 'random' ), kind = s2[ 0 ], setKind = s2[ 1 ];
		var s3 = useState( '' ), busy = s3[ 0 ], setBusy = s3[ 1 ];
		var s4 = useState( '' ), error = s4[ 0 ], setError = s4[ 1 ];
		var s5 = useState( null ), form = s5[ 0 ], setForm = s5[ 1 ];
		var s6 = useState( false ), saved = s6[ 0 ], setSaved = s6[ 1 ];
		var latest = useRef( {} );

		function setLink( l ) {
			setLinkState( l );
			setForm( l ? formFrom( l ) : null );
		}

		function set( changes ) {
			setSaved( false );
			setForm( function ( f ) {
				return Object.assign( {}, f, changes );
			} );
		}

		function save() {
			var data = {};
			FIELDS.forEach( function ( k ) {
				data[ k ] = form[ k ];
			} );
			setBusy( 'save' );
			setError( '' );
			return wp.apiFetch( { path: API + 'link/' + link.id, method: 'POST', data: data } ).then( function ( r ) {
				setLink( r.link );
				setSaved( true );
			} ).catch( function ( e ) {
				setError( ( e && e.message ) || T.saveFail );
			} ).then( function () {
				setBusy( '' );
			} );
		}

		function check() {
			setBusy( 'check' );
			wp.apiFetch( { path: API + 'link/' + link.id + '/check', method: 'POST' } ).then( function ( r ) {
				setLinkState( r.link );
			} ).catch( function () {} ).then( function () {
				setBusy( '' );
			} );
		}

		latest.current = { link: link, form: form, save: save };

		useEffect( function () {
			if ( ! postId || isNew ) {
				return;
			}
			wp.apiFetch( { path: API + 'post/' + postId } ).then( function ( r ) {
				setLink( r.link );
			} ).catch( function () {
				setLink( null );
			} );
		}, [ postId, isNew ] );

		// Saving the post saves pending changes of the short link too.
		var wasSaving = useRef( false );
		useEffect( function () {
			var cur = latest.current;
			if ( wasSaving.current && ! saving && cur.link && cur.form && isDirty( cur.form, cur.link ) ) {
				cur.save();
			}
			wasSaving.current = saving;
		}, [ saving ] );

		var body;
		if ( isNew ) {
			body = el( 'p', null, T.saveFirst );
		} else if ( undefined === link ) {
			body = el( C.Spinner );
		} else if ( ! link ) {
			body = D.canCreate ? el( 'div', null,
				el( 'p', null, T.none ),
				el( C.RadioControl, {
					selected: kind,
					options: [ { label: T.random, value: 'random' }, { label: T.memorable, value: 'memorable' }, { label: T.title, value: 'title' } ],
					onChange: setKind,
				} ),
				el( C.Button, {
					variant: 'primary',
					isBusy: !! busy,
					disabled: !! busy,
					onClick: function () {
						setBusy( 'create' );
						setError( '' );
						wp.apiFetch( { path: API + 'post/' + postId, method: 'POST', data: { kind: kind } } ).then( function ( r ) {
							setLink( r.link );
						} ).catch( function () {
							setError( T.createFail );
						} ).then( function () {
							setBusy( '' );
						} );
					},
				}, T.create ),
				error ? el( 'p', { style: { color: '#cc1818' } }, error ) : null
			) : el( 'p', null, T.noRights );
		} else {
			var dirty = form && isDirty( form, link );
			body = el( 'div', null,
				'active' !== link.state ? el( 'p', null, el( 'strong', null, T[ link.state ] || link.state ) ) : null,
				el( Copy, { url: link.urls.link } ),
				link.urls.root ? el( Copy, { url: link.urls.root, label: T.short } ) : null,
				el( 'p', { style: muted }, sprintf( T.visits, link.visits ), link.rules ? ' · ' + T.scheduled : '' ),
				D.canCreate && form ? el( 'div', null,
					el( Settings, { link: link, form: form, set: set } ),
					el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '10px', marginTop: '16px' } },
						el( C.Button, { variant: 'primary', isBusy: 'save' === busy, disabled: ! dirty || !! busy, onClick: save }, T.save ),
						saved && ! dirty ? el( 'span', { style: { color: '#00701c' } }, T.saved ) : null
					),
					dirty ? el( 'p', { style: Object.assign( { margin: '6px 0 0' }, muted ) }, el( 'small', null, T.unsaved ) ) : null,
					error ? el( 'p', { style: { color: '#cc1818' } }, error ) : null,
					el( CheckLine, { link: link, busy: 'check' === busy, onCheck: check } )
				) : null,
				el( 'p', { style: { marginTop: '12px' } },
					el( C.Button, { variant: 'secondary', href: link.qr, target: '_blank' }, T.qr ), ' ',
					el( C.Button, { variant: 'link', href: link.edit, target: '_blank' }, T.more )
				)
			);
		}
		return el( Panel, { name: 'clean-short-links', title: T.panel, className: 'clean-short-links-panel' }, body );
	}

	wp.plugins.registerPlugin( 'clean-short-links', { render: ShortLinkPanel, icon: 'admin-links' } );
} )( window.wp );
