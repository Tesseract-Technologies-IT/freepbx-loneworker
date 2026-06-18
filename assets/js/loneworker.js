// Lone Worker — live settings example + sessions grid formatters.

// ---- Sessions grid (labels come translated from grid.php via LW.grid) ----
function lwG(k, def) { return (typeof LW !== 'undefined' && LW.grid && LW.grid[k]) ? LW.grid[k] : def; }
function lwFmtTime(ts) {
	if (!ts) { return ''; }
	var d = new Date(ts * 1000);
	var hh = ('0' + d.getHours()).slice(-2);
	var mm = ('0' + d.getMinutes()).slice(-2);
	var ss = ('0' + d.getSeconds()).slice(-2);
	var now = Math.floor(Date.now() / 1000);
	var diff = ts - now;
	var m = Math.round(Math.abs(diff) / 60);
	var rel = diff >= 0 ? lwG('inMin', 'in %dm').replace('%d', m) : lwG('minAgo', '%dm ago').replace('%d', m);
	return hh + ':' + mm + ':' + ss + ' (' + rel + ')';
}
function lwTimeFormatter(value) { return lwFmtTime(value); }
function lwStateFormatter(value) {
	if (value === 'ALARMING') { return '<span class="label label-danger">' + lwG('alarm', 'ALARM') + '</span>'; }
	return '<span class="label label-success">' + lwG('armed', 'ARMED') + '</span>';
}
function lwActionFormatter(value, row) {
	var msg = lwG('confirmDisarm', 'Disarm session %s?').replace('%s', value).replace(/'/g, "\\'");
	return '<a class="btn btn-xs btn-warning" href="config.php?display=loneworker&action=admindisarm&ext=' +
		encodeURIComponent(value) + '" onclick="return confirm(\'' + msg + '\')">' + lwG('disarm', 'Disarm') + '</a>';
}

// ---- Live example on the settings page ----
function lwRenderExample() {
	if (typeof LW === 'undefined' || !document.getElementById('lw-ex-list')) { return; }
	var t  = LW.i18n;
	var ext = ($('#lw-ex-ext').val() || '301').trim() || '301';
	var timeout = Math.max(1, Math.round((parseInt($('#timeout').val(), 10) || 1800) / 60));
	var remAfter = Math.max(1, Math.round((parseInt($('#reminder_after').val(), 10) || 900) / 60));
	var remInt = Math.max(1, Math.round((parseInt($('#reminder_interval').val(), 10) || 300) / 60));
	var pg = ($('#paging_group').val() || '').trim();
	// responders: single ordered list (one per line)
	var respLines = ($('#alarm_numbers').val() || '').split(/[\r\n,;]+/).filter(function (x) { return x.trim() !== ''; });
	var nResp = respLines.length;
	var seqMode = ($('#alarm_mode').val() === 'sequence');
	// feature codes: live from the editable fields, fall back to the rendered values
	var fcArm = ($('#fc_arm').val() || LW.fcArm || '701').trim();
	var fcChk = ($('#fc_checkin').val() || LW.fcCheckin || '702').trim();
	var fcDis = ($('#fc_disarm').val() || LW.fcDisarm || '703').trim();

	function fill(s) {
		return s.replace(/%EXT%/g, ext).replace(/%ARM%/g, fcArm).replace(/%CHK%/g, fcChk)
			.replace(/%DIS%/g, fcDis).replace(/%PG%/g, pg || '—')
			.replace(/%TIMEOUT%/g, timeout).replace(/%REMAFTER%/g, remAfter).replace(/%REMINT%/g, remInt);
	}
	$('.lw-ex-num').text(ext);
	$('.lw-cn-num').text(fcChk);
	$('.lw-pg-num').text(pg || '—');
	$('#lw-ex-title').text(t.title);
	$('#lw-ex-extlabel').text(t.exLabel + ': ');
	$('#lw-ex-intro').text(fill(t.intro));
	var alarmStep = (seqMode && t.alarmSeq) ? t.alarmSeq : t.alarm;
	var steps = [t.arm, t.reminder, t.checkin, alarmStep, t.disarm];
	var html = '';
	for (var i = 0; i < steps.length; i++) { html += '<li>' + fill(steps[i]) + '</li>'; }
	$('#lw-ex-list').html(html);
	var warn = '';
	if (!pg) { warn += '<div class="text-danger">' + t.noPg + '</div>'; }
	if (!nResp) { warn += '<div class="text-danger">' + t.noRg + '</div>'; }
	$('#lw-ex-warn').html(warn);
}

// Live conflict check for the feature-code fields.
function lwCheckFcCodes() {
	if (typeof LW === 'undefined' || !LW.used) { return; }
	var fields = ['fc_arm', 'fc_checkin', 'fc_disarm'];
	var seen = {};
	fields.forEach(function (id) {
		var el = $('#' + id);
		if (!el.length) { return; }
		var code = (el.val() || '').trim();
		var warn = '';
		if (code !== '') {
			if (seen[code]) {
				warn = LW.fcDup.replace('%CODE%', code);
			} else if (LW.used[code]) {
				warn = LW.fcUsed.replace('%CODE%', code).replace('%WHO%', LW.used[code]);
			}
			seen[code] = id;
		}
		$('#' + id + '-warn').text(warn);
		el.closest('.form-group').toggleClass('has-error', warn !== '');
	});
}

// Rebuild the "add extension" picker, excluding extensions already in the list.
function lwRefreshExtPicker() {
	var sel = $('#lw-add-ext');
	if (!sel.length || typeof LW === 'undefined' || !LW.extList) { return; }
	var present = {};
	($('#alarm_numbers').val() || '').split(/[\r\n,;]+/).forEach(function (x) { x = x.trim(); if (x) { present[x] = 1; } });
	var cur = sel.val();
	sel.find('option:gt(0)').remove(); // keep the placeholder (first option)
	LW.extList.forEach(function (o) {
		if (!present[o.e]) { sel.append($('<option>').val(o.e).text(o.e + ' - ' + o.n)); }
	});
	if (sel.find('option[value="' + cur + '"]').length) { sel.val(cur); }
}

// ---- Test announcement / alarm: live status + stop ----
var lwTestPoll = null;
function lwTestBox() { return $('#lw-test-status'); }
function lwTestSet(cls, icon, html) {
	lwTestBox().removeClass('alert-info alert-success alert-warning alert-danger').addClass(cls)
		.html('<i class="fa ' + icon + '"></i> ' + html).show();
}
function lwTestRenderActive(d, kind) {
	var t = LW.test;
	var list = (d.channels || []).map(function (c) {
		var m = (c.name || '').match(/Local\/([0-9*#+]+)@/);
		var who = m ? m[1] : (c.name || '');
		var s = c.state || '';
		var lbl = (s === 'Ring' || s === 'Ringing' || s === 'Dialing') ? t.stRinging : (s === 'Up' ? t.stUp : t.stOther);
		return who + ' (' + lbl + ')';
	});
	var head = (kind === 'announce') ? t.announcing : t.running.replace('%d', d.count);
	lwTestSet('alert-info', 'fa-spinner fa-spin', head + (list.length ? ' — ' + list.join(', ') : ''));
	$('#lw-test-stop').show();
}
function lwTestBeginPoll(kind) {
	if (lwTestPoll) { clearInterval(lwTestPoll); }
	function tick() {
		$.getJSON(LW.test.ajax + '&command=teststatus', function (d) {
			if (d && d.active) { lwTestRenderActive(d, kind); }
			else {
				clearInterval(lwTestPoll); lwTestPoll = null;
				lwTestSet('alert-success', 'fa-check', LW.test.ended);
				$('#lw-test-stop').hide();
			}
		});
	}
	tick();
	lwTestPoll = setInterval(tick, 2000);
}
function lwTestStart(kind) {
	if (typeof LW === 'undefined' || !LW.test) { return; }
	if (kind === 'alarm' && !confirm(LW.test.confirmAlarm)) { return; }
	lwTestSet('alert-info', 'fa-spinner fa-spin', LW.test.starting);
	$('#lw-test-stop').show();
	$.getJSON(LW.test.ajax + '&command=teststart&type=' + kind, function (r) {
		if (r && r.ok === false) {
			lwTestSet('alert-danger', 'fa-exclamation-triangle', LW.test.failed);
			$('#lw-test-stop').hide();
			return;
		}
		lwTestBeginPoll(kind);
	}).fail(function () {
		lwTestSet('alert-danger', 'fa-exclamation-triangle', LW.test.failed);
		$('#lw-test-stop').hide();
	});
}
function lwTestStop() {
	if (typeof LW === 'undefined' || !LW.test) { return; }
	$.getJSON(LW.test.ajax + '&command=teststop', function (r) {
		if (lwTestPoll) { clearInterval(lwTestPoll); lwTestPoll = null; }
		lwTestSet('alert-warning', 'fa-stop', LW.test.stoppedMsg.replace('%d', (r && r.stopped) || 0));
		$('#lw-test-stop').hide();
	});
}

$(function () {
	// test buttons (live)
	if ($('#lw-test-announce').length) {
		$('#lw-test-announce').on('click', function () { lwTestStart('announce'); });
		$('#lw-test-alarm').on('click', function () { lwTestStart('alarm'); });
		$('#lw-test-stop').on('click', lwTestStop);
	}
	// feature-code conflict check
	if (typeof LW !== 'undefined' && LW.used) {
		lwCheckFcCodes();
		$('#fc_arm, #fc_checkin, #fc_disarm').on('input change keyup', lwCheckFcCodes);
	}
	// sessions grid auto-refresh
	if ($('#lw-table').length) {
		$('#lw-refresh').on('click', function () { $('#lw-table').bootstrapTable('refresh'); });
		setInterval(function () { $('#lw-table').bootstrapTable('refresh', { silent: true }); }, 15000);
	}
	// "Add extension to list" picker -> append a line to the ordered numbers list
	if ($('#lw-add-ext').length) {
		lwRefreshExtPicker();
		$('#alarm_numbers').on('input change keyup', lwRefreshExtPicker);
		$('#lw-add-ext-btn').on('click', function () {
			var v = ($('#lw-add-ext').val() || '').trim();
			if (!v) { return; }
			var ta = $('#alarm_numbers');
			var cur = ta.val();
			ta.val((cur && !/\n$/.test(cur) ? cur + '\n' : cur) + v);
			ta.trigger('input'); // updates example + refreshes the picker
			$('#lw-add-ext').val('');
		});
	}
	// settings live example
	if (typeof LW !== 'undefined' && document.getElementById('lw-ex-list')) {
		lwRenderExample();
		$('#timeout, #reminder_after, #reminder_interval, #paging_group, #alarm_numbers, #alarm_mode, #lw-ex-ext, #fc_arm, #fc_checkin, #fc_disarm')
			.on('input change keyup', lwRenderExample);
	}
});
