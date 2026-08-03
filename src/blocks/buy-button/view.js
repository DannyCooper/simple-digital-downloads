( function () {
	'use strict';

	var restUrl = '/wp-json/simple-digital-downloads/v1/checkout';

	function getRestUrl( wrapper ) {
		if ( wrapper && wrapper.getAttribute( 'data-checkout-url' ) ) {
			return wrapper.getAttribute( 'data-checkout-url' );
		}
		if ( window.sddBuyButton && window.sddBuyButton.restUrl ) {
			return window.sddBuyButton.restUrl;
		}
		return restUrl;
	}

	function isFree( wrapper ) {
		return wrapper && wrapper.getAttribute( 'data-free' ) === '1';
	}

	function showError( wrapper, message ) {
		var errorEl = wrapper ? wrapper.querySelector( '.sdd-buy-error' ) : null;
		if ( errorEl ) {
			errorEl.textContent = message;
			errorEl.hidden = false;
		}
	}

	function hideError( wrapper ) {
		var errorEl = wrapper ? wrapper.querySelector( '.sdd-buy-error' ) : null;
		if ( errorEl ) {
			errorEl.hidden = true;
		}
	}

	function setOverlayState( wrapper, open ) {
		var overlay = wrapper ? wrapper.querySelector( '.sdd-email-overlay' ) : null;
		if ( overlay ) {
			overlay.hidden = ! open;
			overlay.setAttribute( 'aria-hidden', open ? 'false' : 'true' );
		}
		if ( open ) {
			document.body.classList.add( 'sdd-buy-button-modal-open' );
		} else if ( ! document.querySelector( '.sdd-buy-button-wrapper.is-open' ) ) {
			document.body.classList.remove( 'sdd-buy-button-modal-open' );
		}
	}

	function openPopover( wrapper ) {
		if ( ! wrapper ) {
			return;
		}
		closeAllPopovers( wrapper );
		wrapper.classList.add( 'is-open' );
		setOverlayState( wrapper, true );
		var input = wrapper.querySelector( '.sdd-email-input' );
		if ( input ) {
			input.focus();
		}
	}

	function closePopover( wrapper ) {
		if ( ! wrapper ) {
			return;
		}
		wrapper.classList.remove( 'is-open' );
		setOverlayState( wrapper, false );
	}

	function closeAllPopovers( except ) {
		document.querySelectorAll( '.sdd-buy-button-wrapper.is-open' ).forEach( function ( wrapper ) {
			if ( wrapper !== except ) {
				closePopover( wrapper );
			}
		} );
	}

	function togglePopover( wrapper ) {
		if ( wrapper.classList.contains( 'is-open' ) ) {
			closePopover( wrapper );
		} else {
			openPopover( wrapper );
		}
	}

	function handlePaidClick( btn, wrapper ) {
		var downloadId = btn.getAttribute( 'data-download-id' );
		if ( ! downloadId ) {
			return;
		}

		hideError( wrapper );
		var checkoutUrl = getRestUrl( wrapper );

		btn.disabled = true;

		fetch( checkoutUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				download_id: parseInt( downloadId, 10 ),
				cancel_url: window.location.href,
			} ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok && result.data.checkout_url ) {
					window.location.href = result.data.checkout_url;
				} else {
					throw new Error( result.data.message || 'Checkout failed.' );
				}
			} )
			.catch( function ( err ) {
				btn.disabled = false;
				showError( wrapper, err.message || 'Something went wrong. Please try again.' );
			} );
	}

	function handleFreeSubmit( wrapper ) {
		var input = wrapper.querySelector( '.sdd-email-input' );
		var submitBtn = wrapper.querySelector( '.sdd-email-submit' );
		var downloadId = wrapper.getAttribute( 'data-download-id' );

		if ( ! input || ! downloadId ) {
			return;
		}

		var email = input.value.trim();
		if ( ! email || email.indexOf( '@' ) === -1 ) {
			showError( wrapper, 'Please enter a valid email address.' );
			return;
		}

		if ( submitBtn ) {
			submitBtn.disabled = true;
		}
		hideError( wrapper );

		var checkoutUrl = getRestUrl( wrapper );

		fetch( checkoutUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				download_id: parseInt( downloadId, 10 ),
				email: email,
			} ),
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok && result.data.delivery_url ) {
					window.location.href = result.data.delivery_url;
				} else {
					throw new Error( result.data.message || 'Something went wrong.' );
				}
			} )
			.catch( function ( err ) {
				if ( submitBtn ) {
					submitBtn.disabled = false;
				}
				showError( wrapper, err.message || 'Something went wrong. Please try again.' );
			} );
	}

	document.addEventListener( 'click', function ( e ) {
		var backdrop = e.target && e.target.closest ? e.target.closest( '.sdd-email-overlay-backdrop' ) : null;
		if ( backdrop ) {
			e.preventDefault();
			var backdropWrapper = backdrop.closest( '.sdd-buy-button-wrapper' );
			if ( backdropWrapper ) {
				closePopover( backdropWrapper );
			}
			return;
		}

		var closeBtn = e.target && e.target.closest ? e.target.closest( '.sdd-email-close' ) : null;
		if ( closeBtn ) {
			e.preventDefault();
			var closeWrapper = closeBtn.closest( '.sdd-buy-button-wrapper' );
			if ( closeWrapper ) {
				closePopover( closeWrapper );
			}
			return;
		}

		var submitBtn = e.target && e.target.closest ? e.target.closest( '.sdd-email-submit' ) : null;
		if ( submitBtn ) {
			e.preventDefault();
			var submitWrapper = submitBtn.closest( '.sdd-buy-button-wrapper' );
			if ( submitWrapper ) {
				handleFreeSubmit( submitWrapper );
			}
			return;
		}

		var btn = e.target && e.target.closest ? e.target.closest( '.sdd-buy-button' ) : null;
		if ( btn ) {
			e.preventDefault();
			var wrapper = btn.closest( '.sdd-buy-button-wrapper' );
			if ( isFree( wrapper ) ) {
				togglePopover( wrapper );
			} else {
				handlePaidClick( btn, wrapper );
			}
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			closeAllPopovers();
			return;
		}

		if ( e.key === 'Enter' ) {
			var input = e.target && e.target.closest ? e.target.closest( '.sdd-email-input' ) : null;
			if ( input ) {
				e.preventDefault();
				var wrapper = input.closest( '.sdd-buy-button-wrapper' );
				if ( wrapper ) {
					handleFreeSubmit( wrapper );
				}
			}
		}
	} );
} )();
