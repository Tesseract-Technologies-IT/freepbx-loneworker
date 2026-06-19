<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
// Lone Worker — per-session log (active + past), reconstructed from the event history.
$evLabels = [
	'ARM' => _('Armed'), 'CHECKIN' => _('Check-in'), 'DISARM' => _('Disarmed'),
	'REMINDER' => _('Reminder'), 'ALARM' => _('Alarm'), 'ALARM_REPEAT' => _('Alarm re-call'),
	'ACK' => _('Taken charge'), 'ACK_HOLD' => _('Taken charge (held)'), 'CASCADE' => _('Calling responders'),
	'ANSWERED' => _('Responder answered'),
];
?>
<div id="toolbar-history" style="margin-bottom:12px">
	<a href="config.php?display=loneworker" class="btn btn-default"><i class="fa fa-dashboard"></i> <?php echo _('Dashboard') ?></a>
	<a href="config.php?display=loneworker&amp;view=sessions" class="btn btn-default"><i class="fa fa-list"></i> <?php echo _('Active sessions') ?></a>
	<a href="config.php?display=loneworker&amp;view=history" class="btn btn-primary"><i class="fa fa-folder-open"></i> <?php echo _('Session log') ?></a>
	<a href="config.php?display=loneworker&amp;view=events" class="btn btn-default"><i class="fa fa-history"></i> <?php echo _('Event history') ?></a>
	<a href="config.php?display=loneworker&amp;view=settings" class="btn btn-default"><i class="fa fa-cog"></i> <?php echo _('Settings') ?></a>
</div>

<div class="panel panel-default">
	<div class="panel-heading">
		<strong><i class="fa fa-folder-open"></i> <?php echo _('Sessions (active &amp; past)') ?></strong>
		<div class="pull-right" style="margin-top:-4px">
			<input type="text" id="lw-hist-search" class="form-control input-sm" style="display:inline-block;width:170px" placeholder="<?php echo _('Filter by extension / name') ?>">
			<select id="lw-hist-window" class="form-control input-sm" style="display:inline-block;width:auto">
				<option value="today"><?php echo _('Today') ?></option>
				<option value="7d" selected><?php echo _('Last 7 days') ?></option>
				<option value="30d"><?php echo _('Last 30 days') ?></option>
				<option value="all"><?php echo _('All') ?></option>
			</select>
			<button type="button" id="lw-hist-refresh" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> <?php echo _('Refresh') ?></button>
		</div>
	</div>
	<table class="table table-condensed table-hover" style="margin:0">
		<thead><tr>
			<th style="width:24px"></th>
			<th><?php echo _('Operator') ?></th>
			<th><?php echo _('Started') ?></th>
			<th><?php echo _('Ended') ?></th>
			<th><?php echo _('Duration') ?></th>
			<th><?php echo _('Summary') ?></th>
		</tr></thead>
		<tbody id="lw-hist-rows"></tbody>
	</table>
</div>

<script>
(window.LW = window.LW || {}).hist = {
	ajax: 'ajax.php?module=loneworker',
	pollMs: 10000,
	evLabels: <?php echo json_encode($evLabels, JSON_UNESCAPED_UNICODE); ?>,
	i18n: {
		active: <?php echo json_encode(_('ACTIVE')); ?>,
		armed: <?php echo json_encode(_('ARMED')); ?>,
		alarm: <?php echo json_encode(_('ALARM')); ?>,
		acked: <?php echo json_encode(_('TAKEN CHARGE')); ?>,
		none: <?php echo json_encode(_('No sessions in this period.')); ?>,
		checkins: <?php echo json_encode(_('%d check-in(s)')); ?>,
		alarms: <?php echo json_encode(_('%d alarm(s)')); ?>,
		reminders: <?php echo json_encode(_('%d reminder(s)')); ?>,
		recalls: <?php echo json_encode(_('%d re-call(s)')); ?>,
		takenCharge: <?php echo json_encode(_('taken charge')); ?>,
		ackedBy: <?php echo json_encode(_('taken charge by %s')); ?>,
		by: <?php echo json_encode(_('by')); ?>,
		called: <?php echo json_encode(_('called')); ?>,
		endManual: <?php echo json_encode(_('disarmed manually')); ?>,
		endAcked: <?php echo json_encode(_('closed after take-charge')); ?>,
		ongoing: <?php echo json_encode(_('ongoing')); ?>,
		timeline: <?php echo json_encode(_('Timeline')); ?>,
		sessionId: <?php echo json_encode(_('Session ID')); ?>,
		noEvents: <?php echo json_encode(_('No recorded actions.')); ?>
	}
};
</script>
