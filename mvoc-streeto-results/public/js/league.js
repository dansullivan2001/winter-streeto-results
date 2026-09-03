/**
 * League table expander.
 *
 * The per-event detail is rendered inside a <details> element, which already
 * collapses and expands on its own — so this file adds only the two things
 * native behaviour does not give:
 *
 *   an "expand all" control, for anyone who would rather read the whole table;
 *   and opening the details containing a match when the browser's find-in-page
 *   lands inside a collapsed section, which is how a runner finds their name.
 *
 * Everything works with this file absent. It is enhancement, not scaffolding.
 */
( function () {
	'use strict';

	function addExpandAll( league ) {
		var details = league.querySelectorAll( '.mvoc-streeto-detail' );
		if ( details.length < 2 ) {
			return;
		}

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'mvoc-streeto-expand-all';
		button.setAttribute( 'aria-expanded', 'false' );
		button.textContent = league.dataset.expandLabel || 'Show all scores';

		button.addEventListener( 'click', function () {
			var expanding = button.getAttribute( 'aria-expanded' ) !== 'true';

			details.forEach( function ( detail ) {
				detail.open = expanding;
			} );

			button.setAttribute( 'aria-expanded', expanding ? 'true' : 'false' );
			button.textContent = expanding
				? ( league.dataset.collapseLabel || 'Hide scores' )
				: ( league.dataset.expandLabel || 'Show all scores' );
		} );

		league.insertBefore( button, league.querySelector( 'table' ) );
	}

	/**
	 * Open any collapsed section the browser has just scrolled a match into.
	 *
	 * Without this, find-in-page reports a hit the reader cannot see.
	 */
	function revealOnFind() {
		if ( ! ( 'onbeforematch' in document.body ) ) {
			return;
		}

		document.addEventListener( 'beforematch', function ( event ) {
			var detail = event.target.closest( '.mvoc-streeto-detail' );
			if ( detail ) {
				detail.open = true;
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.mvoc-streeto-league' ).forEach( addExpandAll );
		revealOnFind();
	} );
}() );
