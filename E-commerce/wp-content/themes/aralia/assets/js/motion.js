/**
 * Movimiento de entrada de Aralía (GSAP auto-alojado). Solo se encola en páginas públicas (ver functions.php).
 * Respeta prefers-reduced-motion: con esa preferencia no se anima nada y todo queda visible.
 */
( function () {
	'use strict';
	if ( ! window.gsap || ! window.matchMedia ) {
		return;
	}
	if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return;
	}
	var targets = document.querySelectorAll( '.ara-reveal' );
	if ( ! targets.length ) {
		return;
	}
	if ( window.ScrollTrigger ) {
		window.gsap.registerPlugin( window.ScrollTrigger );
	}
	targets.forEach( function ( el ) {
		window.gsap.from( el, {
			opacity: 0,
			y: 8,
			duration: 1.2,
			ease: 'power2.out',
			scrollTrigger: window.ScrollTrigger ? { trigger: el, start: 'top 90%', once: true } : null,
		} );
	} );
} )();
