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

/**
 * Sycomp B2B Portal — premium custom address field transformation at checkout.
 */
(function ($) {
	'use strict';

	if (typeof $ === 'undefined') {
		return;
	}

	function setupFieldPair($select, $customField) {
		if (!$select.length || !$customField.length) {
			return;
		}

		var $textarea = $customField.find('textarea');
		if (!$textarea.length) {
			return;
		}

		// Prevent multiple wrapper additions
		if ($textarea.parent().hasClass('sy-address-textarea-wrapper')) {
			return;
		}

		// Wrap textarea
		$textarea.wrap('<div class="sy-address-textarea-wrapper"></div>');
		var $wrapper = $customField.find('.sy-address-textarea-wrapper');

		// Append controls
		var controlsHtml = 
			'<div class="sy-address-controls">' +
			'  <button type="button" class="sy-address-back-btn" title="Go back to dropdown">← Go back to select</button>' +
			'  <button type="button" class="sy-address-confirm-btn" title="Confirm address">✓</button>' +
			'</div>';
		$wrapper.append(controlsHtml);

		// Initial state
		var currentSelectVal = $select.val();
		if (currentSelectVal === 'custom') {
			$select.closest('.form-row').hide();
			$customField.show();
			if ($textarea.val().trim() !== '') {
				$textarea.prop('readonly', true);
				$wrapper.addClass('is-confirmed');
			}
		} else {
			$customField.hide();
			$select.closest('.form-row').show();
		}
	}

	function initSelect2Backup() {
		var initFn = null;
		if (typeof $.fn.selectWoo !== 'undefined') {
			initFn = 'selectWoo';
		} else if (typeof $.fn.select2 !== 'undefined') {
			initFn = 'select2';
		}

		if (initFn) {
			$('#sycomp_billing_address, #sycomp_delivery_address').each(function() {
				var $select = $(this);
				if (!$select.hasClass('select2-hidden-accessible') && !$select.hasClass('selectwoo-hidden-accessible')) {
					$select[initFn]({
						placeholder: $select.attr('placeholder') || '',
						minimumResultsForSearch: 10,
						width: '100%'
					});
				}
			});
		}
	}

	function initCheckoutTransformation() {
		initSelect2Backup();
		setupFieldPair($('#sycomp_billing_address'), $('#sycomp_custom_billing_address_field'));
		setupFieldPair($('#sycomp_delivery_address'), $('#sycomp_custom_delivery_address_field'));
	}

	// ── Address dropdown overflow fix ───────────────────────────────────────
	$(document.body).on('select2:open selectwoo:open', '#sycomp_billing_address, #sycomp_delivery_address', function () {
		var $select = $(this);

		function constrainDropdown() {
			// Select the actual floating dropdown container (not the inline selector container)
			var $openContainer = $('.select2-dropdown').closest('.select2-container');
			if (!$openContainer.length) { return; }

			// Tag for our targeted CSS rules
			$openContainer.addClass('sy-address-dropdown');

			// Get the inline select2 container (the selector field)
			var $selectorField = $select.next('.select2-container');
			var dropdownW = $selectorField.length ? $selectorField.outerWidth() : 0;

			if (!dropdownW) {
				// Fallback to form row/field width
				var $field = $select.closest('.form-row');
				var fieldEl = ($field.length ? $field[0] : $select[0]);
				var rect = fieldEl.getBoundingClientRect();
				dropdownW = rect.width || 300;
			}

			// Apply the width custom property
			$openContainer[0].style.setProperty('--sy-address-dropdown-width', dropdownW + 'px');

			// Force width on container and panel with !important to prevent Select2/selectWoo JS overrides
			$openContainer[0].style.setProperty('width', dropdownW + 'px', 'important');
			$openContainer[0].style.setProperty('max-width', dropdownW + 'px', 'important');

			var $dropdown = $openContainer.find('.select2-dropdown');
			if ($dropdown.length) {
				$dropdown[0].style.setProperty('width', dropdownW + 'px', 'important');
				$dropdown[0].style.setProperty('max-width', dropdownW + 'px', 'important');
			}

			// Force each option and any descendants to wrap & detect custom/separator elements
			$dropdown.find('.select2-results__options li').each(function() {
				var $li = $(this);
				var text = $li.text();
				if (text.indexOf('[ + ]') !== -1 || text.indexOf('Add new') !== -1) {
					$li.addClass('sy-select2-custom-option');
				} else if (text.indexOf('──') !== -1) {
					$li.addClass('sy-select2-separator-option');
				}

				$li.css({
					'white-space'   : 'normal',
					'overflow-wrap' : 'break-word',
					'word-break'    : 'break-word',
					'text-overflow' : 'clip',
					'max-width'     : '100%',
					'box-sizing'    : 'border-box'
				});
				$li.find('*').css({
					'white-space'   : 'normal',
					'overflow-wrap' : 'break-word',
					'word-break'    : 'break-word',
					'text-overflow' : 'clip',
					'max-width'     : '100%',
					'box-sizing'    : 'border-box'
				});
			});
		}

		// Run immediately and after brief delays to survive any layout reflows
		constrainDropdown();
		setTimeout(constrainDropdown, 50);
		setTimeout(constrainDropdown, 200);
	});

	// ── Event delegation for address fields ───────────────────────────────
	$(document.body).on('change', '#sycomp_billing_address, #sycomp_delivery_address', function () {
		var $select = $(this);
		var val = $select.val();
		var isBilling = $select.attr('id') === 'sycomp_billing_address';
		var $customField = isBilling ? $('#sycomp_custom_billing_address_field') : $('#sycomp_custom_delivery_address_field');
		var $textarea = $customField.find('textarea');

		if (val === 'separator') {
			var initFn = typeof $.fn.selectWoo !== 'undefined' ? 'selectWoo' : 'select2';
			$select.val('').trigger('change.' + initFn).trigger('change');
			return;
		}

		if (val === 'custom') {
			$select.closest('.form-row').slideUp(250);
			$customField.slideDown(250, function() {
				$textarea.focus();
			});
		} else {
			$customField.hide();
			$select.closest('.form-row').show();
		}
	});

	$(document.body).on('click', '.sy-address-confirm-btn', function (e) {
		e.stopPropagation();
		e.preventDefault();
		var $btn = $(this);
		var $wrapper = $btn.closest('.sy-address-textarea-wrapper');
		var $textarea = $wrapper.find('textarea');
		if ($wrapper.hasClass('is-confirmed')) {
			$textarea.prop('readonly', false);
			$wrapper.removeClass('is-confirmed');
			$btn.html('✓').attr('title', 'Confirm address');
			$textarea.focus();
		} else {
			if ($textarea.val().trim() === '') {
				$textarea.focus();
			} else {
				$textarea.prop('readonly', true);
				$wrapper.addClass('is-confirmed');
				$btn.html('✓').attr('title', 'Confirm address');
			}
		}
	});

	$(document.body).on('click', '.sy-address-textarea-wrapper textarea', function () {
		var $textarea = $(this);
		var $wrapper = $textarea.closest('.sy-address-textarea-wrapper');
		if ($wrapper.hasClass('is-confirmed')) {
			$textarea.prop('readonly', false);
			$wrapper.removeClass('is-confirmed');
			$wrapper.find('.sy-address-confirm-btn').html('✓').attr('title', 'Confirm address');
		}
	});

	$(document.body).on('click', '.sy-address-back-btn', function (e) {
		e.stopPropagation();
		e.preventDefault();
		var $btn = $(this);
		var $wrapper = $btn.closest('.sy-address-textarea-wrapper');
		var $textarea = $wrapper.find('textarea');
		var $customField = $wrapper.closest('.form-row');
		var isBilling = $customField.attr('id') === 'sycomp_custom_billing_address_field';
		var $select = isBilling ? $('#sycomp_billing_address') : $('#sycomp_delivery_address');

		$textarea.val('');
		$textarea.prop('readonly', false);
		$wrapper.removeClass('is-confirmed');
		$wrapper.find('.sy-address-confirm-btn').html('✓').attr('title', 'Confirm address');

		$customField.slideUp(250);
		$select.closest('.form-row').slideDown(250);
		
		var initFn = typeof $.fn.selectWoo !== 'undefined' ? 'selectWoo' : 'select2';
		$select.val('').trigger('change.' + initFn).trigger('change');
	});

	$(document.body).on('updated_checkout', function () {
		initCheckoutTransformation();
		setTimeout(initCheckoutTransformation, 100);
		setTimeout(initCheckoutTransformation, 500);
	});

	$(function () {
		initCheckoutTransformation();
		setTimeout(initCheckoutTransformation, 100);
		setTimeout(initCheckoutTransformation, 500);
	});
})(window.jQuery);

