/**
 * League table expander.
 *
 * The per-event detail is rendered inside a <details> element, which already
 * collapses and expands on its own — so this file adds only the things
 * native behaviour does not give:
 *
 *   an "expand all" control, for anyone who would rather read the whole table;
 *   buttons that filter the table down to one category, reusing the ranking
 *   columns the server already renders rather than fetching a second table;
 *   and opening the details containing a match when the browser's find-in-page
 *   lands inside a collapsed section, which is how a runner finds their name.
 *
 * Everything works with this file absent. It is enhancement, not scaffolding.
 */
( function () {
	'use strict';

	// Both controls are inserted immediately before the scrollable wrapper
	// around the table, not the table itself — the wrapper is the direct
	// child of `league`, which is what insertBefore requires.
	function scrollWrapper( league ) {
		return league.querySelector( '.mvoc-streeto-scroll' );
	}

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

		league.insertBefore( button, scrollWrapper( league ) );
	}

	/**
	 * "All / Ladies / M55 / W55" buttons that show or hide rows.
	 *
	 * Reads the category keys and labels straight from the category column
	 * headers the server already rendered, so the buttons never need their
	 * own copy of the category list, and hides rows using the same
	 * data-categories attribute the server put on each <tr>.
	 */
	function addCategoryFilter( league ) {
		var wrapper = scrollWrapper( league );
		var headers = league.querySelectorAll( '.mvoc-streeto-category-col[data-category]' );
		if ( ! wrapper || ! headers.length ) {
			return;
		}

		var rows = wrapper.querySelectorAll( 'tbody tr' );

		var nav = document.createElement( 'div' );
		nav.className = 'mvoc-streeto-league-filters';
		nav.setAttribute( 'role', 'group' );
		nav.setAttribute( 'aria-label', league.dataset.filterLabel || 'Filter' );

		var buttons = [];

		function select( category, button ) {
			rows.forEach( function ( row ) {
				var categories = ( row.dataset.categories || '' ).split( ' ' );
				row.hidden = !! category && -1 === categories.indexOf( category );
			} );

			buttons.forEach( function ( btn ) {
				btn.setAttribute( 'aria-pressed', btn === button ? 'true' : 'false' );
			} );
		}

		var allButton = document.createElement( 'button' );
		allButton.type = 'button';
		allButton.textContent = league.dataset.allLabel || 'All';
		allButton.setAttribute( 'aria-pressed', 'true' );
		allButton.addEventListener( 'click', function () {
			select( '', allButton );
		} );
		nav.appendChild( allButton );
		buttons.push( allButton );

		headers.forEach( function ( header ) {
			var category = header.dataset.category;
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.textContent = header.textContent;
			button.setAttribute( 'aria-pressed', 'false' );
			button.addEventListener( 'click', function () {
				select( category, button );
			} );
			nav.appendChild( button );
			buttons.push( button );
		} );

		league.insertBefore( nav, wrapper );
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
		document.querySelectorAll( '.mvoc-streeto-league' ).forEach( function ( league ) {
			addCategoryFilter( league );
			addExpandAll( league );
		} );
		revealOnFind();
	} );
}() );
