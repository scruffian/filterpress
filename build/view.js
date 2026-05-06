/**
 * FilterPress — frontend Interactivity API store.
 *
 * Animates the SVG turbulence filter's feDisplacementMap.scale on press/release.
 * Holding the pointer keeps the button in the deformed state; releasing (or
 * dragging off, or pointer cancelled) eases it back. Mid-animation re-triggers
 * pick up from the current value instead of snapping.
 *
 * Per-button state lives in data-wp-context. The active rAF id per filter id
 * is kept in a module-level Map so we can cancel it without polluting the
 * reactive context.
 */
import { store, getContext } from '@wordpress/interactivity';

const RAMP_MS = 200;
const animationFrames = new Map();

function cancelAnim( filterId ) {
	const id = animationFrames.get( filterId );
	if ( id ) {
		cancelAnimationFrame( id );
		animationFrames.delete( filterId );
	}
}

function easeInOut( t ) {
	return 0.5 - 0.5 * Math.cos( t * Math.PI );
}

function animate( filterId, mapEl, fromScale, toScale, onDone ) {
	cancelAnim( filterId );
	const start = performance.now();
	const tick = ( now ) => {
		const t = Math.min( 1, ( now - start ) / RAMP_MS );
		const e = easeInOut( t );
		const scale = fromScale + ( toScale - fromScale ) * e;
		mapEl.setAttribute( 'scale', scale.toFixed( 2 ) );
		if ( t < 1 ) {
			animationFrames.set( filterId, requestAnimationFrame( tick ) );
		} else {
			animationFrames.delete( filterId );
			if ( onDone ) {
				onDone();
			}
		}
	};
	animationFrames.set( filterId, requestAnimationFrame( tick ) );
}

function getMapForContext( ctx ) {
	const filterEl = document.getElementById( ctx.filterId );
	return filterEl ? filterEl.querySelector( 'feDisplacementMap' ) : null;
}

store( 'filterpress', {
	actions: {
		startTurbulence: () => {
			const ctx = getContext();
			const map = getMapForContext( ctx );
			if ( ! map ) {
				return;
			}

			if ( ! map.dataset.fpTargetScale ) {
				map.dataset.fpTargetScale = map.getAttribute( 'scale' ) || '0';
			}
			const target = parseFloat( map.dataset.fpTargetScale ) || 0;
			const current = parseFloat( map.getAttribute( 'scale' ) ) || 0;

			ctx.on = true;
			animate( ctx.filterId, map, current, target );
		},
		endTurbulence: () => {
			const ctx = getContext();
			if ( ! ctx.on ) {
				return;
			}
			const map = getMapForContext( ctx );
			if ( ! map ) {
				return;
			}

			const current = parseFloat( map.getAttribute( 'scale' ) ) || 0;
			animate( ctx.filterId, map, current, 0, () => {
				ctx.on = false;
			} );
		},
	},
} );
