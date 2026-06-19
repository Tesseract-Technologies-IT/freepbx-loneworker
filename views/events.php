<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
// Event history (read-only audit view).
$labels = [
	'ARM'            => [_('Armed'), 'success'],
	'CHECKIN'        => [_('Check-in'), 'info'],
	'DISARM'         => [_('Disarmed'), 'default'],
	'REMINDER'       => [_('Reminder'), 'info'],
	'ALARM'          => [_('Alarm'), 'danger'],
	'ALARM_REPEAT'   => [_('Alarm re-call'), 'danger'],
	'ACK'            => [_('Taken charge'), 'warning'],
	'ACK_HOLD'       => [_('Taken charge (held)'), 'warning'],
	'CASCADE'        => [_('Calling responders'), 'warning'],
	'PAGING'         => [_('Announcement'), 'default'],
	'PAGING_QUEUED'  => [_('Announcement queued'), 'default'],
	'PAGING_SKIPPED' => [_('Announcement skipped'), 'default'],
	'TEST'           => [_('Test'), 'primary'],
	'ERROR'          => [_('Error'), 'danger'],
];
?>
<div id="toolbar-events">
	<a href="config.php?display=loneworker" class="btn btn-default"><i class="fa fa-dashboard"></i> <?php echo _('Dashboard') ?></a>
	<a href="config.php?display=loneworker&amp;view=sessions" class="btn btn-default"><i class="fa fa-list"></i> <?php echo _('Active sessions') ?></a>
	<a href="config.php?display=loneworker&amp;view=events" class="btn btn-primary"><i class="fa fa-history"></i> <?php echo _('Event history') ?></a>
	<a href="config.php?display=loneworker&amp;view=settings" class="btn btn-default"><i class="fa fa-cog"></i> <?php echo _('Settings') ?></a>
	<a href="config.php?display=loneworker&amp;view=events" class="btn btn-default"><i class="fa fa-refresh"></i> <?php echo _('Refresh') ?></a>
</div>
<table data-toggle="table" data-pagination="true" data-search="true" data-page-size="25" class="table table-striped" id="lw-events">
	<thead>
		<tr>
			<th data-sortable="true"><?php echo _('Time') ?></th>
			<th><?php echo _('Event') ?></th>
			<th data-sortable="true"><?php echo _('Extension') ?></th>
			<th><?php echo _('Operator') ?></th>
			<th><?php echo _('Details') ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ($events as $e):
		$lab = $labels[$e['event']] ?? [$e['event'], 'default'];
		$detail = '';
		if (!empty($e['payload'])) {
			$p = json_decode($e['payload'], true);
			if (is_array($p)) {
				$parts = [];
				foreach ($p as $k => $v) { $parts[] = $k . '=' . (is_scalar($v) ? $v : json_encode($v)); }
				$detail = implode(', ', $parts);
			}
		}
	?>
		<tr>
			<td data-sort="<?php echo (int) $e['ts'] ?>"><?php echo date('Y-m-d H:i:s', (int) $e['ts']) ?></td>
			<td><span class="label label-<?php echo $lab[1] ?>"><?php echo $lab[0] ?></span></td>
			<td><?php echo htmlspecialchars((string) $e['ext']) ?></td>
			<td><?php echo htmlspecialchars((string) $e['name']) ?></td>
			<td><small class="text-muted"><?php echo htmlspecialchars($detail) ?></small></td>
		</tr>
	<?php endforeach; ?>
	<?php if (empty($events)): ?>
		<tr><td colspan="5" class="text-muted"><?php echo _('No events yet.') ?></td></tr>
	<?php endif; ?>
	</tbody>
</table>
