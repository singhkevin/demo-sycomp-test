/**
 * Sycomp B2B Portal — catalogue add-to-cart.
 *
 * Progressive enhancement: each catalogue row is a real <form> that posts
 * to WooCommerce's add-to-cart handler with no JavaScript. When JS is
 * available, this intercepts the submit and adds via AJAX so the buyer
 * stays on the catalogue.
 */
(function () {
	'use strict';

	if (typeof window.SycompB2B === 'undefined') {
		return;
	}
	var cfg = window.SycompB2B;

	function setCartCount(count) {
		var nodes = document.querySelectorAll('.sy-cart-count');
		nodes.forEach(function (node) {
			node.textContent = String(count);
		});
	}

	function showRowMessage(form, text, isError) {
		var msg = form.querySelector('.sy-row-msg');
		if (!msg) {
			msg = document.createElement('span');
			msg.className = 'sy-row-msg';
			form.appendChild(msg);
		}
		msg.textContent = text;
		msg.classList.toggle('is-error', !!isError);
	}

	function handleSubmit(e) {
		var form = e.currentTarget;
		var button = form.querySelector('.sy-addbtn');
		var qtyInput = form.querySelector('.sy-qty');
		if (!button) {
			return; // Let the browser submit normally.
		}

		e.preventDefault();

		// Clear any stale message from a previous attempt.
		var staleMsg = form.querySelector('.sy-row-msg');
		if (staleMsg) {
			staleMsg.remove();
		}

		var productId = button.getAttribute('data-product');
		var quantity = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;

		button.classList.add('is-loading');
		button.disabled = true;
		var originalText = button.textContent;
		button.textContent = cfg.i18n.adding;

		var body = new URLSearchParams();
		body.append('action', 'sycomp_add_to_cart');
		body.append('nonce', cfg.nonce);
		body.append('product_id', productId);
		body.append('quantity', String(quantity));

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (res) { return res.json(); })
			.then(function (json) {
				button.classList.remove('is-loading');
				button.disabled = false;

				if (json && json.success) {
					// Feedback is the button's own "Added" state plus the
					// header cart count — no verbose message that would
					// stretch the product card.
					setCartCount(json.data.count);
					button.classList.add('is-done');
					button.textContent = cfg.i18n.added;
					setTimeout(function () {
						button.classList.remove('is-done');
						button.textContent = originalText;
					}, 1800);
				} else {
					var err = (json && json.data && json.data.message) ? json.data.message : cfg.i18n.error;
					showRowMessage(form, err, true);
					button.textContent = originalText;
				}
			})
			.catch(function () {
				button.classList.remove('is-loading');
				button.disabled = false;
				button.textContent = originalText;
				showRowMessage(form, cfg.i18n.error, true);
			});
	}

	function init() {
		var forms = document.querySelectorAll('.sy-addform');
		forms.forEach(function (form) {
			form.addEventListener('submit', handleSubmit);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

/**
 * Sycomp B2B Portal — mobile catalogue filter drawer.
 *
 * On phones the filter rail is hidden behind a "Filters" toggle so it does
 * not bury the product grid. Delegated so it works regardless of load order.
 */
(function () {
	'use strict';

	document.addEventListener('click', function (e) {
		var toggle = e.target.closest('.sy-cat-filters-toggle');
		if (!toggle) {
			return;
		}
		var layout = toggle.closest('.sy-cat-layout');
		if (!layout) {
			return;
		}
		var open = layout.classList.toggle('sy-filters-open');
		toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
	});
})();

/**
 * Sycomp B2B Portal — live line + order totals on the PO builder.
 *
 * For every #sy-po-lines table, each line shows qty x unit price, and the
 * footer shows the running order total. The unit price is whatever the
 * manager typed, or the product option's market price (data-price).
 */
(function () {
	'use strict';

	function format(amount, symbol, decimals) {
		var fixed = amount.toFixed(decimals);
		var parts = fixed.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		return symbol + parts.join('.');
	}

	function recalc() {
		var tables = document.querySelectorAll('#sy-po-lines');
		Array.prototype.forEach.call(tables, function (table) {
			var symbol = table.getAttribute('data-currency-symbol') || '';
			var decimals = parseInt(table.getAttribute('data-decimals'), 10);
			if (isNaN(decimals)) {
				decimals = 2;
			}
			var grand = 0;
			var anyPriced = false;
			var rows = table.querySelectorAll('tbody tr.sy-po-line');
			Array.prototype.forEach.call(rows, function (row) {
				var cell = row.querySelector('.sy-po-linetotal');
				if (!cell) {
					return;
				}
				var sel = row.querySelector('.sy-po-prod');
				var qtyEl = row.querySelector('.sy-po-qty');
				var priceEl = row.querySelector('.sy-po-price');
				var qty = qtyEl ? parseFloat(qtyEl.value) : 0;
				if (isNaN(qty) || qty < 0) {
					qty = 0;
				}
				var price = (priceEl && priceEl.value.trim() !== '') ? parseFloat(priceEl.value) : NaN;
				if (isNaN(price) && sel && sel.selectedOptions && sel.selectedOptions.length) {
					var dp = sel.selectedOptions[0].getAttribute('data-price');
					if (dp) {
						price = parseFloat(dp);
					}
				}
				if (!sel || !sel.value || isNaN(price)) {
					cell.textContent = '—';
					return;
				}
				var line = qty * price;
				anyPriced = true;
				grand += line;
				cell.textContent = format(line, symbol, decimals);
			});
			var gt = table.querySelector('.sy-po-grandtotal');
			if (gt) {
				gt.textContent = anyPriced ? format(grand, symbol, decimals) : '—';
			}
		});
	}

	document.addEventListener('input', function (e) {
		if (e.target.closest && e.target.closest('#sy-po-lines')) {
			recalc();
		}
	});
	document.addEventListener('change', function (e) {
		if (e.target.closest && e.target.closest('#sy-po-lines')) {
			recalc();
		}
	});
	document.addEventListener('click', function (e) {
		if (e.target.closest && (e.target.closest('#sy-po-addline') || e.target.closest('.sy-po-del'))) {
			setTimeout(recalc, 0);
		}
	});

	if (document.querySelector('#sy-po-lines')) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', recalc);
		} else {
			recalc();
		}
	}
})();

/**
 * Sycomp B2B Portal — stacked-card tables on phones.
 *
 * For every .sy-table--stack table, copy the column headers onto each cell
 * as a data-label, then flag the table .sy-stacked so the mobile CSS can
 * render each row as a labelled card. No-JS fallback: the table stays a
 * normal (scrollable) table.
 */
(function () {
	'use strict';

	function stackify() {
		var tables = document.querySelectorAll('.sy-table--stack');
		Array.prototype.forEach.call(tables, function (table) {
			var labels = Array.prototype.map.call(
				table.querySelectorAll('thead th'),
				function (th) { return th.textContent.trim(); }
			);
			if (!labels.length) {
				return;
			}
			Array.prototype.forEach.call(table.querySelectorAll('tbody tr'), function (tr) {
				Array.prototype.forEach.call(tr.children, function (td, i) {
					if (labels[i] && !td.hasAttribute('data-label')) {
						td.setAttribute('data-label', labels[i]);
					}
				});
			});
			table.classList.add('sy-stacked');
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', stackify);
	} else {
		stackify();
	}
})();
