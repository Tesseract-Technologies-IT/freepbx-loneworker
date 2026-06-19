<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
// Live grid of active sessions. Data via ajax.php?module=loneworker&command=sessions
?>
<script>
(window.LW = window.LW || {}).grid = {
	armed: <?php echo json_encode(_('ARMED')); ?>,
	alarm: <?php echo json_encode(_('ALARM')); ?>,
	acked: <?php echo json_encode(_('TAKEN CHARGE')); ?>,
	disarm: <?php echo json_encode(_('Disarm')); ?>,
	confirmDisarm: <?php echo json_encode(_('Disarm session %s?')); ?>,
	inMin: <?php echo json_encode(_('in %dm')); ?>,
	minAgo: <?php echo json_encode(_('%dm ago')); ?>
};
</script>
<?php
$rc = \FreePBX::Loneworker()->checkReadiness();
$rf = count(array_filter($rc, fn($c) => $c['level'] === 'fail'));
$rw = count(array_filter($rc, fn($c) => $c['level'] === 'warn'));
if ($rf || $rw):
?>
<div class="alert alert-<?php echo $rf ? 'danger' : 'warning' ?>">
	<i class="fa fa-stethoscope"></i>
	<?php echo $rf ? sprintf(_('Readiness: %d problem(s) to fix before alarms work reliably.'), $rf) : sprintf(_('Readiness: %d warning(s).'), $rw); ?>
	<a href="config.php?display=loneworker&amp;view=settings"><?php echo _('Open the readiness check') ?></a>
</div>
<?php else: ?>
<div class="alert alert-success" style="padding:6px 10px"><i class="fa fa-check"></i> <?php echo _('Readiness: ready for alarms.') ?></div>
<?php endif; ?>
<div id="toolbar-grid">
	<a href="config.php?display=loneworker" class="btn btn-default"><i class="fa fa-dashboard"></i> <?php echo _('Dashboard') ?></a>
	<a href="config.php?display=loneworker&amp;view=sessions" class="btn btn-primary"><i class="fa fa-list"></i> <?php echo _('Active sessions') ?></a>
	<a href="config.php?display=loneworker&amp;view=events" class="btn btn-default"><i class="fa fa-history"></i> <?php echo _('Event history') ?></a>
	<a href="config.php?display=loneworker&amp;view=settings" class="btn btn-default"><i class="fa fa-cog"></i> <?php echo _('Settings') ?></a>
	<button type="button" id="lw-refresh" class="btn btn-default"><i class="fa fa-refresh"></i> <?php echo _('Refresh') ?></button>
</div>
<table data-toolbar="#toolbar-grid" data-escape="true" data-toggle="table"
	data-url="ajax.php?module=loneworker&amp;command=sessions"
	data-show-refresh="false" data-pagination="true" data-search="true" id="lw-table">
	<thead>
		<tr>
			<th data-field="ext" data-sortable="true"><?php echo _('Extension') ?></th>
			<th data-field="name" data-sortable="true"><?php echo _('Operator') ?></th>
			<th data-field="state" data-formatter="lwStateFormatter" data-sortable="true"><?php echo _('State') ?></th>
			<th data-field="start_ts" data-formatter="lwTimeFormatter"><?php echo _('Armed at') ?></th>
			<th data-field="next_reminder_ts" data-formatter="lwTimeFormatter"><?php echo _('Next reminder') ?></th>
			<th data-field="deadline_ts" data-formatter="lwTimeFormatter"><?php echo _('Deadline') ?></th>
			<th data-field="ext" data-formatter="lwActionFormatter"><?php echo _('Actions') ?></th>
		</tr>
	</thead>
	<tbody></tbody>
</table>
<p class="help-block"><?php echo _('This page auto-refreshes every 15 seconds. Monitoring is driven by the "loneworker tick" job (runs every minute).') ?></p>
