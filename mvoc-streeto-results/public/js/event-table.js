/**
 * Sortable event results table.
 *
 * Clicking a column heading sorts the table by that column; clicking it again
 * reverses the order. Numbers sort as numbers (so 10 follows 9, and a tied
 * position such as "=3" sorts as 3), names sort as text, and a blank or dash
 * always sorts last in either direction so it never crowds the top.
 *
 * Rows tied on the chosen column keep their original (published) order.
 *
 * Everything works with this file absent: the table is already in position
 * order. This is enhancement only.
 */
( function () {
	'use strict';

	var BLANK = /^[\s—–-]*$/;

	function cellText( row, index ) {
		var cell = row.cells[ index ];
		return cell ? cell.textContent.replace( /\s+/g, ' ' ).trim() : '';
	}

	function toNumber( text ) {
		var match = text.replace( /,/g, '' ).match( /-?\d+(?:\.\d+)?/ );
		return match ? parseFloat( match[ 0 ] ) : NaN;
	}

	function makeSortable( table ) {
		var headRow = table.tHead && table.tHead.rows[ 0 ];
		var body = table.tBodies[ 0 ];
		if ( ! headRow || ! body ) {
			return;
		}

		var rows = Array.prototype.slice.call( body.rows );
		rows.forEach( function ( row, i ) {
			row.dataset.originalIndex = i;
		} );

		var headers = Array.prototype.slice.call( headRow.cells );

		// A column is numeric if every non-blank cell in it starts with a number.
		function isNumeric( index ) {
			return rows.every( function ( row ) {
				var text = cellText( row, index );
				return BLANK.test( text ) || ! isNaN( toNumber( text ) );
			} );
		}

		function sortBy( index, direction ) {
			var numeric = isNumeric( index );

			rows.slice().sort( function ( a, b ) {
				var ta = cellText( a, index );
				var tb = cellText( b, index );
				var blankA = BLANK.test( ta );
				var blankB = BLANK.test( tb );

				if ( blankA !== blankB ) {
					return blankA ? 1 : -1;
				}

				var result = 0;
				if ( ! blankA ) {
					result = numeric
						? toNumber( ta ) - toNumber( tb )
						: ta.localeCompare( tb, undefined, { sensitivity: 'base' } );
				}

				if ( 0 === result ) {
					return a.dataset.originalIndex - b.dataset.originalIndex;
				}
				return direction * result;
			} ).forEach( function ( row ) {
				body.appendChild( row );
			} );

			headers.forEach( function ( th, i ) {
				th.setAttribute(
					'aria-sort',
					i === index ? ( 1 === direction ? 'ascending' : 'descending' ) : 'none'
				);
			} );
		}

		headers.forEach( function ( th, index ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'mvoc-streeto-sort';
			while ( th.firstChild ) {
				button.appendChild( th.firstChild );
			}
			th.appendChild( button );
			th.setAttribute( 'aria-sort', 'none' );

			button.addEventListener( 'click', function () {
				var direction = 'ascending' === th.getAttribute( 'aria-sort' ) ? -1 : 1;
				sortBy( index, direction );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.mvoc-streeto-event .mvoc-streeto-table' ).forEach( makeSortable );
	} );
}() );
