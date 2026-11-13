/* Clean Short Links – panel in the block editor. */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.plugins || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement, useState = wp.element.useState, useEffect = wp.element.useEffect;
	var C = wp.components, D = window.CleanShortLinksEditor || { i18n: {} }, T = D.i18n;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}

	function Copy( props ) {
		var s = useState( false ), copied = s[ 0 ], setCopied = s[ 1 ];
		return el( 'div', { className: 'csl-ed-row', style: { display: 'flex', alignItems: 'center', gap: '6px', margin: '0 0 8px' } },
			props.label ? el( 'small', { style: { color: '#757575' } }, props.label ) : null,
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

	function ShortLinkPanel() {
		var postId = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		}, [] );
		var isNew = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).isEditedPostNew();
		}, [] );
		var s1 = useState( undefined ), link = s1[ 0 ], setLink = s1[ 1 ];
		var s2 = useState( D.kind || 'random' ), kind = s2[ 0 ], setKind = s2[ 1 ];
		var s3 = useState( false ), busy = s3[ 0 ], setBusy = s3[ 1 ];
		var s4 = useState( '' ), error = s4[ 0 ], setError = s4[ 1 ];

		useEffect( function () {
			if ( ! postId || isNew ) {
				return;
			}
			wp.apiFetch( { path: '/clean-short-links/v1/post/' + postId } ).then( function ( r ) {
				setLink( r.link );
			} ).catch( function () {
				setLink( null );
			} );
		}, [ postId, isNew ] );

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
					isBusy: busy,
					disabled: busy,
					onClick: function () {
						setBusy( true );
						setError( '' );
						wp.apiFetch( { path: '/clean-short-links/v1/post/' + postId, method: 'POST', data: { kind: kind } } ).then( function ( r ) {
							setLink( r.link );
						} ).catch( function () {
							setError( T.createFail );
						} ).then( function () {
							setBusy( false );
						} );
					},
				}, T.create ),
				error ? el( 'p', { style: { color: '#cc1818' } }, error ) : null
			) : el( 'p', null, T.noRights );
		} else {
			var state = 'active' !== link.state ? el( 'p', null, el( 'strong', null, T[ link.state ] || link.state ) ) : null;
			body = el( 'div', null,
				state,
				el( Copy, { url: link.urls.link } ),
				link.urls.root ? el( Copy, { url: link.urls.root, label: T.short } ) : null,
				link.rules ? el( 'p', null, el( 'small', null, T.scheduled ) ) : null,
				el( 'p', null, el( 'small', null, T.visits.replace( '%d', link.visits ) ) ),
				el( 'p', null,
					el( C.Button, { variant: 'secondary', href: link.qr, target: '_blank' }, T.qr ), ' ',
					el( C.Button, { variant: 'link', href: link.edit, target: '_blank' }, T.edit )
				)
			);
		}
		return el( Panel, { name: 'clean-short-links', title: T.panel, className: 'clean-short-links-panel' }, body );
	}

	wp.plugins.registerPlugin( 'clean-short-links', { render: ShortLinkPanel, icon: 'admin-links' } );
} )( window.wp );
