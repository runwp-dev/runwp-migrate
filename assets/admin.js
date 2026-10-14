/**
 * Tools → RunWP Migrate: preview, then run the migration one step per
 * request until the server reports done. Everything shown comes from the
 * server as text and is inserted as text.
 */
( function () {
	'use strict';

	const config = window.runwpMigrate;
	if ( ! config ) {
		return;
	}

	const t = config.i18n;
	const $ = ( id ) => document.getElementById( id );

	/**
	 * Show or hide. The hidden attribute alone does not do for buttons:
	 * WordPress's .button sets display: inline-block, which wins over it.
	 *
	 * @param {HTMLElement} node    Element.
	 * @param {boolean}     visible Show it.
	 */
	const show = ( node, visible ) => {
		node.hidden = ! visible;
		node.style.display = visible ? '' : 'none';
	};

	/**
	 * POST to admin-ajax. Resolves with the payload of a success response,
	 * rejects with a message otherwise.
	 *
	 * @param {string} action Action without prefix.
	 * @param {Object} data   Fields.
	 * @return {Promise<Object>} Response data.
	 */
	function request( action, data = {} ) {
		const body = new URLSearchParams();
		body.append( 'action', 'runwp_migrate_' + action );
		body.append( '_ajax_nonce', config.nonce );

		Object.entries( data ).forEach( ( [ key, value ] ) => {
			if ( Array.isArray( value ) ) {
				value.forEach( ( item ) => body.append( key + '[]', item ) );
			} else if ( value !== undefined && value !== null ) {
				body.append( key, value );
			}
		} );

		return fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } )
			.then( ( response ) => response.json().catch( () => null ) )
			.then( ( json ) => {
				if ( json && json.success ) {
					return json.data;
				}
				throw new Error( ( json && json.data && json.data.message ) || t.networkError );
			} );
	}

	/**
	 * @param {string} format  Text with one %s.
	 * @param {string} value   Inserted value.
	 * @return {string} Text.
	 */
	const fill = ( format, value ) => format.replace( '%s', value );

	/**
	 * @param {string} tag      Element name.
	 * @param {string} text     Text content.
	 * @param {Object} attrs    Attributes.
	 * @return {HTMLElement} Element.
	 */
	function el( tag, text = '', attrs = {} ) {
		const node = document.createElement( tag );
		node.textContent = text;
		Object.entries( attrs ).forEach( ( [ key, value ] ) => node.setAttribute( key, value ) );
		return node;
	}

	/**
	 * A notice in the result area. Links are passed separately so no
	 * server text is ever parsed as HTML.
	 *
	 * @param {string} type    success, error, warning, info.
	 * @param {string} message Text.
	 * @param {Array}  links   [ [ label, url ] ].
	 */
	function notice( type, message, links = [] ) {
		const box = el( 'div', '', { class: 'notice inline notice-' + type } );
		const p = el( 'p', message + ' ' );
		links.forEach( ( [ label, url ] ) => {
			p.appendChild( el( 'a', label, { href: url, class: 'button' } ) );
			p.appendChild( document.createTextNode( ' ' ) );
		} );
		box.appendChild( p );
		$( 'runwp-migrate-result' ).replaceChildren( box );
	}

	// --- Copy button (This site tab) ----------------------------------------

	document.querySelectorAll( '[data-runwp-migrate-copy]' ).forEach( ( button ) => {
		const field = $( button.dataset.runwpMigrateCopy );
		const label = button.textContent;
		button.addEventListener( 'click', () => {
			field.select();
			navigator.clipboard?.writeText( field.value ).then( () => {
				button.textContent = t.copied;
				setTimeout( () => ( button.textContent = label ), 2000 );
			} ).catch( () => {} );
		} );
	} );

	// --- Migrate tab --------------------------------------------------------

	const planButton = $( 'runwp-migrate-plan' );
	if ( ! planButton ) {
		return;
	}

	const choice = () => ( {
		connection: $( 'runwp-migrate-connection' ).value,
		direction: document.querySelector( 'input[name="runwp_migrate_direction"]:checked' ).value,
		db: $( 'runwp-migrate-db' ).checked ? 1 : 0,
		media: $( 'runwp-migrate-media' ).checked ? 1 : 0,
	} );

	const spinner = planButton.nextElementSibling;
	const startButton = $( 'runwp-migrate-start' );
	const confirmBox = $( 'runwp-migrate-confirm' );

	// A changed choice invalidates the preview.
	document.querySelectorAll( '#runwp-migrate-setup input, #runwp-migrate-setup select' ).forEach( ( input ) =>
		input.addEventListener( 'change', () => {
			$( 'runwp-migrate-preview' ).hidden = true;
		} )
	);

	planButton.addEventListener( 'click', () => {
		const chosen = choice();
		if ( ! chosen.db && ! chosen.media ) {
			notice( 'error', t.none );
			return;
		}

		spinner.classList.add( 'is-active' );
		planButton.disabled = true;
		$( 'runwp-migrate-result' ).replaceChildren();

		request( 'plan', chosen )
			.then( ( plan ) => showPlan( plan, chosen ) )
			.catch( ( error ) => notice( 'error', error.message ) )
			.finally( () => {
				spinner.classList.remove( 'is-active' );
				planButton.disabled = false;
			} );
	} );

	/**
	 * @param {Object} plan   From the server.
	 * @param {Object} chosen The form choice.
	 */
	function showPlan( plan, chosen ) {
		const sites = $( 'runwp-migrate-sites' );
		const head = el( 'thead' );
		const headRow = el( 'tr' );
		[ '', t.source, t.target ].forEach( ( label ) => headRow.appendChild( el( 'th', label ) ) );
		head.appendChild( headRow );

		const body = el( 'tbody' );
		[
			[ t.address, plan.from.home, plan.to.home ],
			[ t.prefix, plan.from.prefix, plan.to.prefix ],
			[ t.tables, String( plan.from.tables ), String( plan.to.tables ) ],
		].forEach( ( cells ) => {
			const row = el( 'tr' );
			cells.forEach( ( cell, index ) => row.appendChild( el( index ? 'td' : 'th', cell ) ) );
			body.appendChild( row );
		} );
		sites.replaceChildren( head, body );

		const pairs = $( 'runwp-migrate-pairs' );
		const pairsBody = el( 'tbody' );
		if ( ! plan.pairs.length ) {
			pairsBody.appendChild( el( 'tr' ) ).appendChild( el( 'td', t.noPairs ) );
		}
		plan.pairs.forEach( ( [ find, replace ] ) => {
			const row = el( 'tr' );
			row.appendChild( el( 'td' ) ).appendChild( el( 'code', find ) );
			row.appendChild( el( 'td', '→' ) );
			row.appendChild( el( 'td' ) ).appendChild( el( 'code', replace ) );
			pairsBody.appendChild( row );
		} );
		pairs.replaceChildren( pairsBody );

		const list = $( 'runwp-migrate-tables' );
		list.replaceChildren();
		plan.tables.forEach( ( table ) => {
			const label = el( 'label', '', { style: 'display: block; margin: 2px 0;' } );
			const box = el( 'input', '', { type: 'checkbox', value: table.name } );
			box.checked = true;
			box.className = 'runwp-migrate-table';
			label.appendChild( box );
			label.appendChild( document.createTextNode( ' ' + table.name + '  (' + table.rows + ' ' + t.rows + ', ' + table.size + ')' ) );
			list.appendChild( label );
		} );
		$( 'runwp-migrate-all' ).checked = true;
		$( 'runwp-migrate-tables-box' ).hidden = ! chosen.db;

		const remote = chosen.direction === 'pull' ? plan.from.home : plan.to.home;
		const warnings = [];
		if ( chosen.db ) {
			warnings.push( fill( chosen.direction === 'pull' ? t.warnPull : t.warnPush, remote ) );
		}
		if ( chosen.media ) {
			warnings.push( t.warnMedia );
		}
		$( 'runwp-migrate-warning' ).textContent = warnings.join( ' ' );
		$( 'runwp-migrate-confirm-text' ).textContent = t.confirm;
		confirmBox.checked = false;
		startButton.disabled = true;

		$( 'runwp-migrate-preview' ).hidden = false;
	}

	$( 'runwp-migrate-all' ).addEventListener( 'change', ( event ) => {
		document.querySelectorAll( '.runwp-migrate-table' ).forEach( ( box ) => ( box.checked = event.target.checked ) );
	} );

	confirmBox.addEventListener( 'change', () => {
		startButton.disabled = ! confirmBox.checked;
	} );

	startButton.addEventListener( 'click', () => {
		const chosen = choice();
		const tables = Array.from( document.querySelectorAll( '.runwp-migrate-table:checked' ) ).map( ( box ) => box.value );

		running( true );
		request( 'start', { ...chosen, tables: chosen.db ? tables : [] } )
			.then( update )
			.then( loop )
			.catch( failed );
	} );

	// --- Running ------------------------------------------------------------

	const bar = $( 'runwp-migrate-bar' );
	const status = $( 'runwp-migrate-status' );
	const retryButton = $( 'runwp-migrate-retry' );
	const cancelButton = $( 'runwp-migrate-cancel' );
	let last = null;

	/**
	 * @param {boolean} on Show the progress area instead of the form.
	 */
	function running( on ) {
		$( 'runwp-migrate-setup' ).hidden = on;
		$( 'runwp-migrate-preview' ).hidden = true;
		$( 'runwp-migrate-progress' ).hidden = ! on;
		show( retryButton, false );
		show( cancelButton, true );
		$( 'runwp-migrate-result' ).replaceChildren();
		if ( on ) {
			status.textContent = t.running;
		}
	}

	/**
	 * @param {Object} progress From the server.
	 * @return {Object} The same.
	 */
	function update( progress ) {
		last = progress;
		bar.value = progress.percent;
		status.textContent = progress.percent + ' %  ·  ' + progress.message;
		return progress;
	}

	/**
	 * Step until done. A dropped request is retried a few times before the
	 * run is reported as stopped; the server keeps its place either way.
	 *
	 * @param {Object} progress Latest progress.
	 * @param {number} attempt  Retries so far.
	 * @return {Promise} Settles when done or stopped.
	 */
	function loop( progress, attempt = 0 ) {
		if ( progress.done ) {
			return finished( progress );
		}

		return request( 'step' )
			.then( ( next ) => loop( update( next ) ) )
			.catch( ( error ) => {
				if ( attempt < 2 && error.message === t.networkError ) {
					return new Promise( ( resolve ) => setTimeout( resolve, 3000 ) ).then( () => loop( progress, attempt + 1 ) );
				}
				throw error;
			} );
	}

	/**
	 * @param {Error} error What stopped the run.
	 */
	function failed( error ) {
		status.textContent = fill( t.failed, error.message );
		show( retryButton, true );
		notice( 'error', fill( t.failed, error.message ) + ' ' + t.retryHint );
	}

	/**
	 * @param {Object} progress Final progress.
	 */
	function finished( progress ) {
		bar.value = 100;
		show( retryButton, false );
		show( cancelButton, false );

		if ( progress.db && progress.direction === 'pull' ) {
			status.textContent = '';
			notice( 'success', fill( t.doneDbPull, progress.remote ), [ [ t.logIn, config.loginUrl ] ] );
		} else if ( progress.db ) {
			status.textContent = '';
			notice( 'success', fill( t.doneDbPush, progress.remote ), [ [ t.open, progress.remote ] ] );
		} else {
			status.textContent = '';
			notice( 'success', t.doneMedia );
		}
	}

	retryButton.addEventListener( 'click', () => {
		show( retryButton, false );
		$( 'runwp-migrate-result' ).replaceChildren();
		status.textContent = t.running;
		loop( last || { done: false } ).catch( failed );
	} );

	cancelButton.addEventListener( 'click', () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( t.cancelAsk ) ) {
			return;
		}
		request( 'cancel' ).finally( () => {
			running( false );
			notice( 'info', t.cancelled );
		} );
	} );

	// A run left over from an earlier visit (closed tab, lost connection)
	// can be picked up or cancelled.
	if ( config.running ) {
		running( true );
		show( retryButton, true );
		status.textContent = t.retryHint;
	}
}() );
