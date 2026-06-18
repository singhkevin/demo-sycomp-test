/**
 * Sycomp Portal — theme interactions.
 *
 * Wires up the small dropdown components in the chrome:
 *  - the buyer location switcher (.sy-locsw), and
 *  - the Shop Manager account menu (.sy-accountmenu).
 * Both share the same open/close behaviour: click to toggle, click-outside
 * and Escape to close. The markup is rendered elsewhere (plugin / theme
 * header); this file only adds behaviour.
 */
(function () {
	'use strict';

	/**
	 * Wire up a set of dropdown components.
	 *
	 * @param {string} rootSelector Container selector.
	 * @param {string} btnSelector  Toggle-button selector within the container.
	 */
	function initDropdowns(rootSelector, btnSelector) {
		var roots = document.querySelectorAll(rootSelector);
		if (!roots.length) {
			return;
		}

		roots.forEach(function (root) {
			var btn = root.querySelector(btnSelector);
			if (!btn) {
				return;
			}
			btn.setAttribute('aria-expanded', 'false');

			btn.addEventListener('click', function (e) {
				e.stopPropagation();
				var isOpen = root.classList.toggle('is-open');
				btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			});
		});

		function closeAll() {
			roots.forEach(function (root) {
				root.classList.remove('is-open');
				var btn = root.querySelector(btnSelector);
				if (btn) {
					btn.setAttribute('aria-expanded', 'false');
				}
			});
		}

		document.addEventListener('click', function (e) {
			roots.forEach(function (root) {
				if (!root.contains(e.target)) {
					root.classList.remove('is-open');
					var btn = root.querySelector(btnSelector);
					if (btn) {
						btn.setAttribute('aria-expanded', 'false');
					}
				}
			});
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				closeAll();
			}
		});
	}

	function init() {
		initDropdowns('.sy-locsw', '.sy-locsw__btn');
		initDropdowns('.sy-accountmenu', '.sy-accountmenu__btn');
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
