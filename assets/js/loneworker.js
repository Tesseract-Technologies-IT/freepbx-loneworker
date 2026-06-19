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
	if (value === 'ACKED') { return '<span class="label label-warning">' + lwG('acked', 'TAKEN CHARGE') + '</span>'; }
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

// --- per-message "Play on speakers" preview (Announcements tab) ---
var lwPlayPoll = null;
function lwPlaySet(cls, icon, html) {
	$('#lw-play-status').removeClass('alert-info alert-success alert-warning alert-danger').addClass(cls)
		.html('<i class="fa ' + icon + '"></i> ' + html).show();
}
function lwPlayPollStart() {
	if (lwPlayPoll) { clearInterval(lwPlayPoll); }
	lwPlayPoll = setInterval(function () {
		$.getJSON(LW.test.ajax + '&command=teststatus', function (d) {
			if (!d || !d.active) {
				clearInterval(lwPlayPoll); lwPlayPoll = null;
				lwPlaySet('alert-success', 'fa-check', LW.test.ended);
				$('#lw-play-stop').hide();
			}
		});
	}, 2000);
}
function lwPlayMsg(msg) {
	if (typeof LW === 'undefined' || !LW.test) { return; }
	lwPlaySet('alert-info', 'fa-spinner fa-spin', LW.test.playing);
	$('#lw-play-stop').show();
	$.getJSON(LW.test.ajax + '&command=playmsg&msg=' + encodeURIComponent(msg), function (r) {
		if (!r || !r.ok) {
			lwPlaySet('alert-danger', 'fa-exclamation-triangle', LW.test.failed);
			$('#lw-play-stop').hide();
			return;
		}
		lwPlayPollStart();
	}).fail(function () {
		lwPlaySet('alert-danger', 'fa-exclamation-triangle', LW.test.failed);
		$('#lw-play-stop').hide();
	});
}
function lwPlayStop() {
	if (typeof LW === 'undefined' || !LW.test) { return; }
	$.getJSON(LW.test.ajax + '&command=teststop', function (r) {
		if (lwPlayPoll) { clearInterval(lwPlayPoll); lwPlayPoll = null; }
		lwPlaySet('alert-warning', 'fa-stop', LW.test.stoppedMsg.replace('%d', (r && r.stopped) || 0));
		$('#lw-play-stop').hide();
	});
}

// ----------------------------------------- Live dashboard & reporting
var lwDashData = null, lwDashSrvNow = 0, lwDashBase = 0, lwRepData = null;

function lwFmtDur(secs) {
	secs = Math.max(0, Math.round(secs));
	if (secs < 60) { return secs + 's'; }
	var m = Math.floor(secs / 60), s = secs % 60;
	if (m < 60) { return m + 'm' + (s ? ' ' + s + 's' : ''); }
	var h = Math.floor(m / 60); m = m % 60;
	return h + 'h' + (m ? ' ' + m + 'm' : '');
}
function lwEffNow() { return lwDashSrvNow + (Date.now() / 1000 - lwDashBase); }

function lwDashRenderStatic(d) {
	var t = LW.dash.i18n, k = d.kpi;
	$('#lw-kpi-armed').text(k.armed);
	$('#lw-kpi-alarming').text(k.alarming);
	$('#lw-kpi-acked').text(k.acked);
	$('#lw-kpi-arms-today').text(k.arms_today);
	$('#lw-kpi-alarms-today').text(k.alarms_today);
	$('#lw-kpi-avgack').text(k.avg_ack == null ? t.none : lwFmtDur(k.avg_ack));
	// health bar
	var h = d.health, html;
	if (!h.ami) { html = '<span class="label label-danger"><i class="fa fa-times"></i> ' + t.amiDown + '</span>'; }
	else { html = '<span class="label label-success"><i class="fa fa-check"></i> ' + t.amiUp + '</span>'; }
	html += ' ';
	if (h.fails > 0 || h.warns > 0) {
		html += '<span class="label label-' + (h.fails ? 'danger' : 'warning') + '"><i class="fa fa-stethoscope"></i> '
			+ t.issues.replace('%d', h.fails).replace('%d', h.warns) + '</span>';
	} else {
		html += '<span class="label label-success"><i class="fa fa-stethoscope"></i> ' + t.ready + '</span>';
	}
	$('#lw-health').html(html);
	// queue
	if (!d.queue || !d.queue.length) { $('#lw-live-queue').html('<span class="text-muted">' + t.queueEmpty + '</span>'); }
	else {
		var q = '<div style="font-size:12px;color:#888;margin-bottom:4px">' + t.nowPlaying + '</div>';
		d.queue.forEach(function (it, i) {
			var lbl = LW.dash.evLabels['PAGING'] || it.msg;
			q += '<span class="label label-' + (it.msg === 'alarm' ? 'danger' : (i === 0 ? 'primary' : 'default')) + '" style="margin:2px;display:inline-block">'
				+ (it.msg || '') + ' → ' + (it.ext || '') + '</span>';
		});
		$('#lw-live-queue').html(q);
	}
	// event feed
	var f = '';
	(d.events || []).forEach(function (e) {
		var lbl = LW.dash.evLabels[e.event] || e.event;
		var cls = (e.event === 'ALARM' || e.event === 'ERROR') ? 'text-danger'
			: (e.event === 'ACK' || e.event === 'ALARM_REPEAT') ? 'text-warning'
			: (e.event === 'ARM' || e.event === 'CHECKIN') ? 'text-success' : 'text-muted';
		var when = new Date(e.ts * 1000).toLocaleTimeString();
		f += '<div style="border-bottom:1px solid #f0f0f0;padding:3px 0;font-size:12px">'
			+ '<span class="text-muted">' + when + '</span> '
			+ '<strong class="' + cls + '">' + lbl + '</strong>'
			+ (e.ext ? ' · ' + e.ext + (e.name ? ' (' + $('<i>').text(e.name).html() + ')' : '') : '') + '</div>';
	});
	$('#lw-live-feed').html(f || '<span class="text-muted">—</span>');
}

function lwDashTick() {
	if (!lwDashData) { return; }
	var t = LW.dash.i18n, now = lwEffNow(), rows = '';
	var ss = lwDashData.sessions || [];
	if (!ss.length) { $('#lw-live-sessions').html('<tr><td colspan="4" class="text-muted">' + t.noSessions + '</td></tr>'); }
	else {
		ss.forEach(function (s) {
			var when;
			if (s.state === 'ALARMING' || s.state === 'ACKED') {
				when = '<span class="text-danger">' + t.ago.replace('%s', lwFmtDur(now - (s.alarm_started_ts || now))) + '</span>';
			} else {
				var left = (s.deadline_ts || now) - now;
				when = (left <= 0) ? '<span class="text-danger">0s</span>'
					: t.inMin.replace('%s', lwFmtDur(left));
			}
			rows += '<tr><td>' + s.ext + '</td><td>' + $('<i>').text(s.name || '').html() + '</td><td>'
				+ lwStateFormatter(s.state) + '</td><td>' + when + '</td></tr>';
		});
		$('#lw-live-sessions').html(rows);
	}
	// "updated Xs ago" + live dot
	var age = Math.max(0, Math.round(Date.now() / 1000 - lwDashBase));
	$('#lw-dash-updated').html('<span style="color:#5cb85c">●</span> ' + LW.dash.i18n.live + ' · ' + LW.dash.i18n.updated + ' ' + age + 's');
}

function lwDashPoll() {
	$.getJSON(LW.dash.ajax + '&command=dashboard', function (d) {
		if (!d) { return; }
		lwDashData = d; lwDashSrvNow = d.now; lwDashBase = Date.now() / 1000;
		lwDashRenderStatic(d);
		lwDashTick();
	});
}

function lwRepRender(r) {
	var t = LW.dash.i18n;
	var tt = r.totals || {};
	var cards = [['ARM', LW.dash.evLabels.ARM], ['CHECKIN', LW.dash.evLabels.CHECKIN],
		['ALARM', LW.dash.evLabels.ALARM], ['ACK', LW.dash.evLabels.ACK], ['DISARM', LW.dash.evLabels.DISARM]];
	var h = '';
	cards.forEach(function (c) {
		h += '<div class="col-sm-2 col-xs-4" style="padding:4px"><div class="well well-sm" style="text-align:center;margin:0">'
			+ '<div style="font-size:22px;font-weight:bold">' + (tt[c[0]] || 0) + '</div><div style="font-size:11px">' + c[1] + '</div></div></div>';
	});
	h += '<div class="col-sm-2 col-xs-4" style="padding:4px"><div class="well well-sm" style="text-align:center;margin:0">'
		+ '<div style="font-size:22px;font-weight:bold">' + (r.avg_ack == null ? t.none : lwFmtDur(r.avg_ack)) + '</div>'
		+ '<div style="font-size:11px">' + t.avgAck + '</div></div></div>';
	$('#lw-rep-totals').html(h);
	// chart
	var s = r.series || [], max = 1;
	s.forEach(function (x) { max = Math.max(max, x.alarm, x.checkin); });
	var c = '';
	if (!s.length) { c = '<span class="text-muted" style="align-self:center">' + t.none + '</span>'; }
	s.forEach(function (x) {
		var ah = Math.round(x.alarm / max * 130), ch = Math.round(x.checkin / max * 130);
		c += '<div style="display:flex;flex-direction:column;align-items:center;min-width:22px">'
			+ '<div style="display:flex;align-items:flex-end;gap:2px;height:135px">'
			+ '<div title="' + x.alarm + '" style="width:9px;background:#d9534f;height:' + ah + 'px"></div>'
			+ '<div title="' + x.checkin + '" style="width:9px;background:#5bc0de;height:' + ch + 'px"></div>'
			+ '</div><div style="font-size:10px;color:#888;white-space:nowrap;transform:rotate(0)">' + x.bucket + '</div></div>';
	});
	$('#lw-rep-chart').html(c);
	// per-operator
	var ops = r.operators || [], o = '';
	if (!ops.length) { o = '<tr><td colspan="6" class="text-muted">' + t.none + '</td></tr>'; }
	ops.forEach(function (op) {
		o += '<tr><td>' + op.ext + '</td><td>' + $('<i>').text(op.name || '').html() + '</td><td>' + op.arms + '</td><td>'
			+ op.alarms + '</td><td>' + op.acks + '</td><td>' + (op.avg_ack == null ? t.none : lwFmtDur(op.avg_ack)) + '</td></tr>';
	});
	$('#lw-rep-ops').html(o);
}
function lwRepLoad() {
	var w = $('#lw-rep-window').val();
	$.getJSON(LW.dash.ajax + '&command=report&window=' + encodeURIComponent(w), function (r) {
		lwRepData = r; lwRepRender(r);
	});
}
function lwRepCsv() {
	if (!lwRepData) { return; }
	var rows = [['extension', 'operator', 'armed', 'alarms', 'taken_charge', 'avg_take_charge_secs']];
	(lwRepData.operators || []).forEach(function (op) {
		rows.push([op.ext, op.name || '', op.arms, op.alarms, op.acks, op.avg_ack == null ? '' : op.avg_ack]);
	});
	rows.push([]);
	var tt = lwRepData.totals || {};
	Object.keys(tt).forEach(function (k) { rows.push(['total_' + k, tt[k]]); });
	rows.push(['avg_take_charge_secs_overall', lwRepData.avg_ack == null ? '' : lwRepData.avg_ack]);
	var csv = rows.map(function (r) {
		return r.map(function (v) { v = ('' + v).replace(/"/g, '""'); return /[",\n]/.test(v) ? '"' + v + '"' : v; }).join(',');
	}).join('\n');
	var blob = new Blob([csv], { type: 'text/csv' });
	var a = document.createElement('a');
	a.href = URL.createObjectURL(blob);
	a.download = LW.dash.i18n.csvName + '-' + (lwRepData.window || '') + '.csv';
	document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

// ----------------------------------------- Per-session log (active + past)
var lwHistData = null, lwHistExpanded = {};
function lwEsc(v) { return $('<i>').text(v == null ? '' : ('' + v)).html(); }
function lwHistDetail(s) {
	var t = LW.hist.i18n;
	var h = '<div style="font-size:11px;color:#888;margin-bottom:6px">' + t.sessionId + ': <code>' + lwEsc(s.id) + '</code></div>';
	if (!s.events || !s.events.length) { return h + '<span class="text-muted">' + t.noEvents + '</span>'; }
	h += '<div style="font-weight:bold;margin-bottom:4px">' + t.timeline + '</div>';
	s.events.forEach(function (e) {
		var lbl = LW.hist.evLabels[e.event] || e.event;
		var cls = (e.event === 'ALARM' || e.event === 'ALARM_REPEAT') ? 'text-danger'
			: (e.event === 'ACK' || e.event === 'ACK_HOLD') ? 'text-warning'
			: (e.event === 'ARM' || e.event === 'CHECKIN') ? 'text-success' : 'text-muted';
		var extra = '';
		if (e.payload) { try {
			var p = JSON.parse(e.payload);
			if (p) {
				if (p.responder) { extra = ' · ' + ((e.event === 'ACK' || e.event === 'ACK_HOLD') ? (t.by + ' ') : '') + '<strong>' + lwEsc(p.responder) + '</strong>'; }
				else if (p.members && p.members.length) { extra = ' · ' + t.called + ' ' + lwEsc(p.members.join(', ')); }
				else if (p.reason) { extra = ' (' + lwEsc(p.reason) + ')'; }
			}
		} catch (x) {} }
		h += '<div style="padding:2px 0;border-bottom:1px solid #f3f3f3;font-size:12px">'
			+ '<span class="text-muted">' + new Date(e.ts * 1000).toLocaleString() + '</span> · '
			+ '<strong class="' + cls + '">' + lbl + '</strong>' + extra + '</div>';
	});
	return h;
}
function lwHistSummary(s) {
	var t = LW.hist.i18n, b = [];
	if (s.checkins) { b.push('<span class="label label-success">' + t.checkins.replace('%d', s.checkins) + '</span>'); }
	if (s.reminders) { b.push('<span class="label label-info">' + t.reminders.replace('%d', s.reminders) + '</span>'); }
	if (s.alarms) { b.push('<span class="label label-danger">' + t.alarms.replace('%d', s.alarms) + '</span>'); }
	if (s.recalls) { b.push('<span class="label label-warning">' + t.recalls.replace('%d', s.recalls) + '</span>'); }
	if (s.acked) { b.push('<span class="label label-warning">' + (s.acked_by ? t.ackedBy.replace('%s', lwEsc(s.acked_by)) : t.takenCharge) + '</span>'); }
	return b.join(' ') || '<span class="text-muted">—</span>';
}
function lwHistRender() {
	if (!lwHistData) { return; }
	var t = LW.hist.i18n, q = ($('#lw-hist-search').val() || '').trim().toLowerCase();
	var rows = (lwHistData.sessions || []).filter(function (s) {
		return !q || ('' + s.ext).toLowerCase().indexOf(q) >= 0 || ('' + (s.name || '')).toLowerCase().indexOf(q) >= 0
			|| ('' + (s.id || '')).toLowerCase().indexOf(q) >= 0;
	});
	if (!rows.length) { $('#lw-hist-rows').html('<tr><td colspan="6" class="text-muted">' + t.none + '</td></tr>'); return; }
	var html = '';
	rows.forEach(function (s) {
		var op = lwEsc(s.ext) + (s.name ? ' <span class="text-muted">' + lwEsc(s.name) + '</span>' : '');
		var started = new Date(s.start_ts * 1000).toLocaleString();
		var ended = s.active
			? '<span class="label label-' + (s.state === 'ALARMING' ? 'danger' : (s.state === 'ACKED' ? 'warning' : 'success')) + '">' + t.active + ' · ' + s.state + '</span>'
			: new Date(s.end_ts * 1000).toLocaleString()
				+ (s.end_reason === 'alarm-acked' ? ' <span class="text-muted">(' + t.endAcked + ')</span>'
					: (s.end_reason === 'manual' ? ' <span class="text-muted">(' + t.endManual + ')</span>' : ''));
		var dur = lwFmtDur(s.duration) + (s.active ? ' <span class="text-muted">(' + t.ongoing + ')</span>' : '');
		var open = !!lwHistExpanded[s.id];
		html += '<tr class="lw-hist-row" data-id="' + lwEsc(s.id) + '" style="cursor:pointer">'
			+ '<td><i class="fa fa-caret-' + (open ? 'down' : 'right') + '"></i></td>'
			+ '<td>' + op + '</td><td>' + started + '</td><td>' + ended + '</td><td>' + dur + '</td><td>' + lwHistSummary(s) + '</td></tr>';
		html += '<tr class="lw-hist-detail" data-id="' + lwEsc(s.id) + '"' + (open ? '' : ' style="display:none"') + '>'
			+ '<td></td><td colspan="5" style="background:#fafafa">' + lwHistDetail(s) + '</td></tr>';
	});
	$('#lw-hist-rows').html(html);
}
function lwHistLoad() {
	var w = $('#lw-hist-window').val();
	$.getJSON(LW.hist.ajax + '&command=history&window=' + encodeURIComponent(w), function (d) {
		lwHistData = d; lwHistRender();
	});
}

$(function () {
	// per-session log
	if (typeof LW !== 'undefined' && LW.hist && $('#lw-hist-rows').length) {
		lwHistLoad();
		setInterval(lwHistLoad, LW.hist.pollMs);
		$('#lw-hist-window').on('change', lwHistLoad);
		$('#lw-hist-refresh').on('click', lwHistLoad);
		$('#lw-hist-search').on('input', lwHistRender);
		$('#lw-hist-rows').on('click', '.lw-hist-row', function () {
			var id = $(this).data('id');
			lwHistExpanded[id] = !lwHistExpanded[id];
			$('tr.lw-hist-detail[data-id="' + id + '"]').toggle(!!lwHistExpanded[id]);
			$(this).find('.fa-caret-right,.fa-caret-down').toggleClass('fa-caret-right fa-caret-down');
		});
	}
	// live dashboard
	// live dashboard
	if (typeof LW !== 'undefined' && LW.dash && $('#lw-live-sessions').length) {
		lwDashPoll();
		setInterval(lwDashPoll, LW.dash.pollMs);
		setInterval(lwDashTick, 1000);
		lwRepLoad();
		$('#lw-rep-window').on('change', lwRepLoad);
		$('#lw-rep-csv').on('click', lwRepCsv);
	}
	// test buttons (live)
	if ($('#lw-test-announce').length) {
		$('#lw-test-announce').on('click', function () { lwTestStart('announce'); });
		$('#lw-test-alarm').on('click', function () { lwTestStart('alarm'); });
		$('#lw-test-stop').on('click', lwTestStop);
	}
	// per-message "Play on speakers" buttons (Announcements tab)
	if ($('.lw-play').length) {
		$('.lw-play').on('click', function () { lwPlayMsg($(this).data('msg')); });
		$('#lw-play-stop').on('click', lwPlayStop);
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
