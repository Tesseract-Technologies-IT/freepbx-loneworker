<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
// Lone Worker — live dashboard & reporting. Data via ajax.php?module=loneworker (polled).
// Event-code labels (shared with the history view) for the live feed.
$evLabels = [
	'ARM' => _('Armed'), 'CHECKIN' => _('Check-in'), 'DISARM' => _('Disarmed'),
	'REMINDER' => _('Reminder'), 'ALARM' => _('Alarm'), 'ALARM_REPEAT' => _('Alarm re-call'),
	'ACK' => _('Taken charge'), 'ACK_HOLD' => _('Taken charge (held)'), 'CASCADE' => _('Calling responders'),
	'PAGING' => _('Announcement'), 'PAGING_QUEUED' => _('Announcement queued'), 'PAGING_SKIPPED' => _('Announcement skipped'),
	'TEST' => _('Test'), 'ERROR' => _('Error'),
];
?>
<div id="toolbar-dashboard" style="margin-bottom:12px">
	<a href="config.php?display=loneworker" class="btn btn-primary"><i class="fa fa-dashboard"></i> <?php echo _('Dashboard') ?></a>
	<a href="config.php?display=loneworker&amp;view=sessions" class="btn btn-default"><i class="fa fa-list"></i> <?php echo _('Active sessions') ?></a>
	<a href="config.php?display=loneworker&amp;view=history" class="btn btn-default"><i class="fa fa-folder-open"></i> <?php echo _('Session log') ?></a>
	<a href="config.php?display=loneworker&amp;view=events" class="btn btn-default"><i class="fa fa-history"></i> <?php echo _('Event history') ?></a>
	<a href="config.php?display=loneworker&amp;view=settings" class="btn btn-default"><i class="fa fa-cog"></i> <?php echo _('Settings') ?></a>
	<span class="pull-right" id="lw-dash-updated" style="line-height:34px;color:#888"></span>
</div>

<!-- LIVE KPI CARDS -->
<div class="row" style="margin-bottom:4px">
	<?php
	$cards = [
		['lw-kpi-armed', _('Armed now'), 'success', 'fa-shield'],
		['lw-kpi-alarming', _('In alarm now'), 'danger', 'fa-bell'],
		['lw-kpi-acked', _('Taken charge'), 'warning', 'fa-check'],
		['lw-kpi-arms-today', _('Armed today'), 'info', 'fa-shield'],
		['lw-kpi-alarms-today', _('Alarms today'), 'info', 'fa-bell'],
		['lw-kpi-avgack', _('Avg. take-charge'), 'info', 'fa-clock-o'],
	];
	foreach ($cards as $c): ?>
	<div class="col-sm-2 col-xs-4" style="padding:4px">
		<div class="panel panel-<?php echo $c[2] ?>" style="margin:0">
			<div class="panel-body" style="padding:10px;text-align:center">
				<div style="font-size:26px;font-weight:bold" id="<?php echo $c[0] ?>">–</div>
				<div style="font-size:11px"><i class="fa <?php echo $c[3] ?>"></i> <?php echo $c[1] ?></div>
			</div>
		</div>
	</div>
	<?php endforeach; ?>
</div>
<div id="lw-health" style="margin-bottom:14px"></div>

<div class="row">
	<!-- LIVE SESSIONS -->
	<div class="col-md-7">
		<div class="panel panel-default">
			<div class="panel-heading"><strong><i class="fa fa-users"></i> <?php echo _('Live sessions') ?></strong></div>
			<table class="table table-condensed table-striped" style="margin:0">
				<thead><tr>
					<th><?php echo _('Extension') ?></th><th><?php echo _('Operator') ?></th>
					<th><?php echo _('State') ?></th><th><?php echo _('Time left / since') ?></th>
				</tr></thead>
				<tbody id="lw-live-sessions"></tbody>
			</table>
		</div>
	</div>
	<div class="col-md-5">
		<!-- ANNOUNCEMENT QUEUE -->
		<div class="panel panel-default">
			<div class="panel-heading"><strong><i class="fa fa-volume-up"></i> <?php echo _('Announcement queue (speakers)') ?></strong></div>
			<div class="panel-body" id="lw-live-queue" style="padding:10px"></div>
		</div>
		<!-- LIVE EVENT FEED -->
		<div class="panel panel-default">
			<div class="panel-heading"><strong><i class="fa fa-rss"></i> <?php echo _('Live events') ?></strong></div>
			<div id="lw-live-feed" style="max-height:320px;overflow:auto;padding:6px 12px"></div>
		</div>
	</div>
</div>

<!-- REPORTS -->
<div class="panel panel-default">
	<div class="panel-heading">
		<strong><i class="fa fa-bar-chart"></i> <?php echo _('Reports') ?></strong>
		<div class="pull-right" style="margin-top:-4px">
			<select id="lw-rep-window" class="form-control input-sm" style="display:inline-block;width:auto">
				<option value="today"><?php echo _('Today') ?></option>
				<option value="7d" selected><?php echo _('Last 7 days') ?></option>
				<option value="30d"><?php echo _('Last 30 days') ?></option>
			</select>
			<button type="button" id="lw-rep-csv" class="btn btn-default btn-sm"><i class="fa fa-download"></i> <?php echo _('Export CSV') ?></button>
		</div>
	</div>
	<div class="panel-body">
		<div id="lw-rep-totals" class="row" style="margin-bottom:10px"></div>
		<h4><?php echo _('Alarms vs check-ins over time') ?></h4>
		<div id="lw-rep-chart" style="display:flex;align-items:flex-end;gap:4px;height:160px;border-bottom:1px solid #ddd;padding-bottom:2px;overflow-x:auto"></div>
		<div style="margin:6px 0 14px;font-size:12px">
			<span style="display:inline-block;width:12px;height:12px;background:#d9534f;vertical-align:middle"></span> <?php echo _('Alarms') ?>
			&nbsp;<span style="display:inline-block;width:12px;height:12px;background:#5bc0de;vertical-align:middle"></span> <?php echo _('Check-ins') ?>
		</div>
		<h4><?php echo _('Per operator') ?></h4>
		<table class="table table-condensed table-striped">
			<thead><tr>
				<th><?php echo _('Extension') ?></th><th><?php echo _('Operator') ?></th>
				<th><?php echo _('Armed') ?></th><th><?php echo _('Alarms') ?></th>
				<th><?php echo _('Taken charge') ?></th><th><?php echo _('Avg. take-charge') ?></th>
			</tr></thead>
			<tbody id="lw-rep-ops"></tbody>
		</table>
	</div>
</div>

<script>
(window.LW = window.LW || {}).dash = {
	ajax: 'ajax.php?module=loneworker',
	pollMs: 3000,
	evLabels: <?php echo json_encode($evLabels, JSON_UNESCAPED_UNICODE); ?>,
	i18n: {
		armed: <?php echo json_encode(_('ARMED')); ?>,
		alarm: <?php echo json_encode(_('ALARM')); ?>,
		acked: <?php echo json_encode(_('TAKEN CHARGE')); ?>,
		noSessions: <?php echo json_encode(_('No active sessions.')); ?>,
		queueEmpty: <?php echo json_encode(_('Nothing queued — speakers idle.')); ?>,
		nowPlaying: <?php echo json_encode(_('now playing / next:')); ?>,
		inMin: <?php echo json_encode(_('%s left')); ?>,
		ago: <?php echo json_encode(_('%s ago')); ?>,
		updated: <?php echo json_encode(_('updated')); ?>,
		live: <?php echo json_encode(_('LIVE')); ?>,
		amiUp: <?php echo json_encode(_('AMI OK')); ?>,
		amiDown: <?php echo json_encode(_('AMI DOWN')); ?>,
		ready: <?php echo json_encode(_('Ready for alarms')); ?>,
		issues: <?php echo json_encode(_('%d problem(s), %d warning(s)')); ?>,
		total: <?php echo json_encode(_('Total')); ?>,
		avgAck: <?php echo json_encode(_('Avg. take-charge')); ?>,
		none: <?php echo json_encode(_('—')); ?>,
		csvName: <?php echo json_encode('loneworker-report'); ?>
	}
};
</script>
