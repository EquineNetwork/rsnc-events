/* RSNC Events: flyer galleries and filter drop-downs. Swiping is the browser's own scrolling (scroll-snap);
   this only wires up the arrows, arrow keys and the "1 / 3" counter. No libraries. */
(function () {
	'use strict';

	function setup(gallery) {
		var track = gallery.querySelector('.rsnc-ev-track');
		var slides = track ? track.querySelectorAll('.rsnc-ev-slide') : [];
		if (!track || slides.length < 2) { return; }
		var prev = gallery.querySelector('.rsnc-ev-prev');
		var next = gallery.querySelector('.rsnc-ev-next');
		var now = gallery.querySelector('.rsnc-ev-count-now');

		function index() {
			return Math.round(track.scrollLeft / Math.max(1, track.clientWidth));
		}
		function go(i) {
			i = Math.max(0, Math.min(slides.length - 1, i));
			track.scrollTo({ left: i * track.clientWidth, behavior: 'smooth' });
		}
		function update() {
			var i = index();
			if (now) { now.textContent = String(i + 1); }
			if (prev) { prev.disabled = i <= 0; }
			if (next) { next.disabled = i >= slides.length - 1; }
			for (var t = 0; t < thumbs.length; t++) {
				var on = t === i;
				thumbs[t].classList.toggle('is-active', on);
				if (on) { thumbs[t].setAttribute('aria-current', 'true'); } else { thumbs[t].removeAttribute('aria-current'); }
			}
		}
		var thumbs = gallery.querySelectorAll('[data-rsnc-ev-thumb]');
		for (var k = 0; k < thumbs.length; k++) {
			thumbs[k].addEventListener('click', function () { go(parseInt(this.getAttribute('data-rsnc-ev-thumb'), 10) || 0); });
		}
		var pending = null;
		track.addEventListener('scroll', function () {
			if (pending) { return; }
			pending = window.requestAnimationFrame(function () { pending = null; update(); });
		}, { passive: true });
		if (prev) { prev.addEventListener('click', function () { go(index() - 1); }); }
		if (next) { next.addEventListener('click', function () { go(index() + 1); }); }
		track.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowLeft') { e.preventDefault(); go(index() - 1); }
			if (e.key === 'ArrowRight') { e.preventDefault(); go(index() + 1); }
		});
		window.addEventListener('resize', update);
		gallery.classList.add('rsnc-ev-ready');
		update();
	}

	function init() {
		var galleries = document.querySelectorAll('[data-rsnc-ev-gallery]');
		for (var i = 0; i < galleries.length; i++) { setup(galleries[i]); }
		// Filters: picking from a drop-down searches straight away (the Search button still works).
		var selects = document.querySelectorAll('[data-rsnc-ev-auto]');
		for (var j = 0; j < selects.length; j++) {
			selects[j].addEventListener('change', function () { if (this.form) { this.form.submit(); } });
		}
		// Leaderboards: "See status" opens the qualification status in a pop-up.
		var dialog = document.getElementById('rsnc-ev-lb-dialog');
		if (dialog && typeof dialog.showModal === 'function') {
			var frame = dialog.querySelector('iframe');
			document.addEventListener('click', function (e) {
				var a = e.target.closest ? e.target.closest('[data-rsnc-status]') : null;
				if (!a) { return; }
				e.preventDefault();
				frame.src = a.href;
				dialog.showModal();
			});
			dialog.addEventListener('click', function (e) { if (e.target === dialog) { dialog.close(); } });
			dialog.addEventListener('close', function () { frame.src = 'about:blank'; });
		}
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
