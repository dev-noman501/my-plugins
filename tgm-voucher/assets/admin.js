/* TGM Voucher — admin form behaviour */
jQuery(function ($) {
	var $form = $('#tgmv-form');

	/* ---------- Multi-step navigation ---------- */
	var totalSteps = $('.tgmv-step').length;

	function showStep(n, skipSave) {
		n = Math.max(1, Math.min(totalSteps, n));
		var changed = ($form.data('step') || 1) !== n;
		$('.tgmv-step').removeClass('active').filter('[data-step="' + n + '"]').addClass('active');
		$('.tgmv-step-btn').removeClass('active').filter('[data-step="' + n + '"]').addClass('active');
		$('#tgmv-prev').toggle(n > 1);
		$('#tgmv-next').toggle(n < totalSteps);
		$form.data('step', n);
		if (changed && !skipSave) {
			autosave();
		}
	}

	/* ---------- Autosave (fires on every step change) ---------- */
	var saving = false;

	function autosave() {
		// Skip autosave until a Family Head is entered (avoids wasting voucher numbers).
		if (saving || !$.trim($('input[name="family_head"]').val())) {
			return;
		}
		saving = true;
		var $status = $('#tgmv-autosave-status');
		$status.text('Saving…').removeClass('ok err');

		$.post(window.ajaxurl, $form.serialize() + '&action=tgmv_autosave')
			.done(function (res) {
				if (res && res.success) {
					var isNew = !parseInt($('input[name="voucher_id"]').val(), 10);
					$('input[name="voucher_id"]').val(res.data.voucher_id);
					$status.text('Saved ✓ ' + res.data.voucher_no).addClass('ok');
					if (isNew && window.history && res.data.edit_url) {
						window.history.replaceState(null, '', res.data.edit_url);
					}
				} else {
					$status.text('Save failed!').addClass('err');
				}
			})
			.fail(function () {
				$status.text('Save failed!').addClass('err');
			})
			.always(function () {
				saving = false;
			});
	}

	if ($form.length) {
		showStep(1);
	}

	$('.tgmv-step-btn').on('click', function () {
		showStep(parseInt($(this).data('step'), 10));
	});
	$('#tgmv-next').on('click', function () {
		showStep(($form.data('step') || 1) + 1);
	});
	$('#tgmv-prev').on('click', function () {
		showStep(($form.data('step') || 1) - 1);
	});

	/* Enter key should not submit mid-form */
	$form.on('keydown', 'input', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
		}
	});

	/* ---------- Repeaters ---------- */
	$('.tgmv-add-row').on('click', function () {
		var $tbody = $('#' + $(this).data('repeater')).find('tbody');
		var $row = $tbody.find('tr').last().clone();
		$row.find('input').val('');
		$row.find('select').prop('selectedIndex', 0);
		$tbody.append($row);
		renumber();
		recalcPax();
	});

	$form.on('click', '.tgmv-remove-row', function () {
		var $tbody = $(this).closest('tbody');
		if ($tbody.find('tr').length > 1) {
			$(this).closest('tr').remove();
		} else {
			var $row = $(this).closest('tr');
			$row.find('input').val('');
			$row.find('select').prop('selectedIndex', 0);
		}
		renumber();
		recalcNights();
		recalcPax();
	});

	function renumber() {
		$('#tgmv-mutamers tbody tr').each(function (i) {
			$(this).find('.tgmv-sno').text(i + 1);
		});
	}
	renumber();

	/* Copy first GRP number to every mutamer row */
	$('#tgmv-copy-grp').on('click', function () {
		var first = $('#tgmv-mutamers tbody tr').first().find('input[name="mutamers[grp][]"]').val();
		if (first) {
			$('#tgmv-mutamers tbody input[name="mutamers[grp][]"]').val(first);
		}
	});

	/* ---------- PAX auto-calc (from Mutamers rows) ---------- */
	function recalcPax() {
		var a = 0, c = 0, i = 0, b = 0;
		$('#tgmv-mutamers tbody tr').each(function () {
			var pax = $(this).find('select[name="mutamers[pax][]"]').val();
			if (pax === 'Adult') { a++; }
			if (pax === 'Child') { c++; }
			if (pax === 'Infant') { i++; }
			if ($(this).find('select[name="mutamers[bed][]"]').val() === 'Yes') { b++; }
		});
		$('input[name="pax_adult"]').val(a);
		$('input[name="pax_child"]').val(c);
		$('input[name="pax_infant"]').val(i);
		$('input[name="beds"]').val(b);
		$('#tgmv-pax-preview').text((a + c + i) + ' (A:' + a + ',C:' + c + ',I:' + i + '),Beds=' + b);
	}
	$form.on('change', '#tgmv-mutamers select', recalcPax);
	recalcPax();

	/* ---------- Nights auto-calc ---------- */
	function recalcNights() {
		var total = 0;
		$('#tgmv-hotels tbody tr').each(function () {
			var ci = $(this).find('.tgmv-checkin').val();
			var co = $(this).find('.tgmv-checkout').val();
			var $n = $(this).find('.tgmv-nights');
			if (ci && co) {
				var nights = Math.round((new Date(co) - new Date(ci)) / 86400000);
				if (nights >= 0) {
					$n.val(nights);
				}
			}
			total += parseInt($n.val(), 10) || 0;
		});
		$('#tgmv-total-nights').text(total);
	}
	$form.on('change', '.tgmv-checkin, .tgmv-checkout, .tgmv-nights', recalcNights);
	recalcNights();

	/* ---------- Media picker (logos) ---------- */
	$(document).on('click', '.tgmv-media', function (e) {
		e.preventDefault();
		var target = $(this).data('target');
		var frame = wp.media({
			title: 'Select Logo',
			multiple: false,
			library: { type: 'image' }
		});
		frame.on('select', function () {
			var url = frame.state().get('selection').first().toJSON().url;
			$('#' + target).val(url);
			$('#' + target + '-preview').attr('src', url).show();
		});
		frame.open();
	});

	$(document).on('click', '.tgmv-media-clear', function (e) {
		e.preventDefault();
		var target = $(this).data('target');
		$('#' + target).val('');
		$('#' + target + '-preview').hide();
	});
});
