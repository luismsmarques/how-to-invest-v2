/**
 * Glossary hub: search + topic filter + A–Z filter (progressive enhancement).
 *
 * Without JS every term is visible and the controls are inert. With JS, the
 * list narrows live by free-text search, topic and initial letter; the result
 * count is announced via an aria-live region and an empty state appears when
 * nothing matches.
 *
 * @package HowToInvest
 */
( function () {
	'use strict';

	var root = document.querySelector( '.hti-gloss' );
	if ( ! root ) {
		return;
	}

	var input    = root.querySelector( '.hti-gloss__input' );
	var clearBtn = root.querySelector( '.hti-gloss__clear' );
	var countEl  = root.querySelector( '.hti-gloss__count' );
	var empty    = root.querySelector( '.hti-gloss__empty' );
	var emptyBtn = root.querySelector( '.hti-gloss__empty-btn' );
	var topics   = root.querySelectorAll( '.hti-gloss__topic' );
	var letters  = root.querySelectorAll( '.hti-gloss__letter[data-letter]' );
	var rows     = root.querySelectorAll( '.hti-gloss__row' );
	var groups   = root.querySelectorAll( '.hti-gloss__group' );

	var tpl = ( countEl && countEl.getAttribute( 'data-template' ) ) || '%s';
	var one = ( countEl && countEl.getAttribute( 'data-one' ) ) || '%s';

	var state = { q: '', topic: 'all', letter: 'all' };

	// Accent-fold + lowercase so "acoes" matches "Ações".
	function fold( str ) {
		str = str || '';
		return str.normalize ?
			str.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' ).toLowerCase() :
			str.toLowerCase();
	}

	function anyFilter() {
		return '' !== state.q || 'all' !== state.topic || 'all' !== state.letter;
	}

	function setPressed( nodes, attr, value ) {
		Array.prototype.forEach.call( nodes, function ( b ) {
			var on = b.getAttribute( attr ) === value;
			b.classList.toggle( 'is-active', on );
			if ( b.hasAttribute( 'aria-pressed' ) ) {
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			}
		} );
	}

	function apply() {
		var shown = 0;

		Array.prototype.forEach.call( rows, function ( row ) {
			var okTopic = 'all' === state.topic ||
				( ' ' + ( row.getAttribute( 'data-topic' ) || '' ) + ' ' ).indexOf( ' ' + state.topic + ' ' ) > -1;
			var okLetter = 'all' === state.letter || row.getAttribute( 'data-letter' ) === state.letter;
			var okSearch = '' === state.q ||
				( row.getAttribute( 'data-search' ) || '' ).indexOf( state.q ) > -1;
			var show = okTopic && okLetter && okSearch;

			if ( show ) {
				row.removeAttribute( 'hidden' );
				shown++;
			} else {
				row.setAttribute( 'hidden', 'hidden' );
			}
		} );

		// Hide letter groups that have no visible rows.
		Array.prototype.forEach.call( groups, function ( group ) {
			var visible = group.querySelector( '.hti-gloss__row:not([hidden])' );
			if ( visible ) {
				group.removeAttribute( 'hidden' );
			} else {
				group.setAttribute( 'hidden', 'hidden' );
			}
		} );

		// Live count + empty state.
		if ( countEl ) {
			countEl.textContent = ( 1 === shown ? one : tpl ).replace( '%s', String( shown ) );
		}
		if ( empty ) {
			if ( 0 === shown ) {
				empty.removeAttribute( 'hidden' );
			} else {
				empty.setAttribute( 'hidden', 'hidden' );
			}
		}
		if ( clearBtn ) {
			if ( anyFilter() ) {
				clearBtn.removeAttribute( 'hidden' );
			} else {
				clearBtn.setAttribute( 'hidden', 'hidden' );
			}
		}
	}

	function reset() {
		state.q = '';
		state.topic = 'all';
		state.letter = 'all';
		if ( input ) {
			input.value = '';
		}
		setPressed( topics, 'data-topic', 'all' );
		setPressed( letters, 'data-letter', 'all' );
		apply();
	}

	if ( input ) {
		input.addEventListener( 'input', function () {
			state.q = fold( input.value.trim() );
			apply();
		} );
	}

	Array.prototype.forEach.call( topics, function ( btn ) {
		btn.addEventListener( 'click', function () {
			state.topic = btn.getAttribute( 'data-topic' );
			setPressed( topics, 'data-topic', state.topic );
			apply();
		} );
	} );

	Array.prototype.forEach.call( letters, function ( btn ) {
		btn.addEventListener( 'click', function () {
			state.letter = btn.getAttribute( 'data-letter' );
			setPressed( letters, 'data-letter', state.letter );
			apply();
		} );
	} );

	if ( clearBtn ) {
		clearBtn.addEventListener( 'click', reset );
	}
	if ( emptyBtn ) {
		emptyBtn.addEventListener( 'click', function () {
			reset();
			if ( input ) {
				input.focus();
			}
		} );
	}
}() );
