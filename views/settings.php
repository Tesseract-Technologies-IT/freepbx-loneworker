<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
// Lone Worker — settings form (English UI; Italian via i18n/.mo).
$s = $settings;

// Active feature codes (for the live example).
$fcArm     = (new featurecode('loneworker', 'arm'))->getCodeActive() ?: '701';
$fcCheckin = (new featurecode('loneworker', 'checkin'))->getCodeActive() ?: '702';
$fcDisarm  = (new featurecode('loneworker', 'disarm'))->getCodeActive() ?: '703';

// Paging / Ring group lists (defensive: auto-detect the number key; fall back to a text box).
$pagings = function_exists('paging_list') ? paging_list() : [];
$rgs     = function_exists('ringgroups_list') ? ringgroups_list() : [];
function lw_group_select($name, $list, $selected) {
	if (empty($list) || !is_array($list)) {
		return '<input type="text" class="form-control" id="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($selected) . '" placeholder="' . _('group number') . '">';
	}
	$numkeys = ['page_group', 'page_number', 'grpnum', 'number', 'group', 'extension'];
	$h = '<select class="form-control" id="' . $name . '" name="' . $name . '"><option value="">' . _('(none)') . "</option>\n";
	foreach ($list as $idx => $g) {
		if (!is_array($g)) { $num = (string) $idx; $des = (string) $g; }
		else {
			$num = '';
			foreach ($numkeys as $k) { if (isset($g[$k]) && $g[$k] !== '') { $num = $g[$k]; break; } }
			if ($num === '' && is_string($idx)) { $num = $idx; }
			$des = $g['description'] ?? '';
		}
		if ($num === '') { continue; }
		$h .= '<option value="' . htmlspecialchars($num) . '"' . ((string) $num === (string) $selected ? ' selected' : '') . '>' . htmlspecialchars($num . ($des !== '' ? ' - ' . $des : '')) . "</option>\n";
	}
	$h .= '</select>';
	return $h;
}

// Helper to render one number field with label + help.
function lw_num($label, $name, $val, $help) {
	echo '<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">';
	echo '<div class="col-md-4"><label class="control-label" for="' . $name . '">' . $label . '</label></div>';
	echo '<div class="col-md-8"><input type="number" min="1" class="form-control" id="' . $name . '" name="' . $name . '" value="' . htmlspecialchars($val) . '"></div>';
	echo '</div></div></div></div>';
	echo '<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block">' . $help . '</span></div></div></div>';
}
?>
<script>
var LW = {
	fcArm: <?php echo json_encode($fcArm); ?>,
	fcCheckin: <?php echo json_encode($fcCheckin); ?>,
	fcDisarm: <?php echo json_encode($fcDisarm); ?>,
	used: <?php echo json_encode(\FreePBX::Loneworker()->usedNumbers(), JSON_UNESCAPED_UNICODE); ?>,
	fcUsed: <?php echo json_encode(_('⚠ %CODE% is already used by %WHO%')); ?>,
	fcDup: <?php echo json_encode(_('⚠ %CODE% is the same as another code above')); ?>,
	i18n: {
		title: <?php echo json_encode(_('What will happen (live example)')); ?>,
		intro: <?php echo json_encode(_('Example for extension %EXT% based on the current settings:')); ?>,
		arm: <?php echo json_encode(_('%EXT% dials %ARM% → the speakers (paging group %PG%) announce that the lone worker system is armed for extension %EXT%, and a %TIMEOUT%-minute countdown starts.')); ?>,
		reminder: <?php echo json_encode(_('After %REMAFTER% minutes a reminder is announced on the speakers, then repeated every %REMINT% minutes until the deadline.')); ?>,
		checkin: <?php echo json_encode(_('%EXT% dials %CHK% at any time → the timer resets to %TIMEOUT% minutes ("I am OK") and everyone hears the confirmation.')); ?>,
		alarm: <?php echo json_encode(str_replace('%KEY%', (string) $s['confirm_key'], _('If %TIMEOUT% minutes pass with no %CHK% → an alarm is announced on the speakers and ALL configured responders are called at the same time. The first responder to press %KEY% takes charge of the alarm, and all the other calls stop immediately.'))); ?>,
		alarmSeq: <?php echo json_encode(str_replace('%KEY%', (string) $s['confirm_key'], _('If %TIMEOUT% minutes pass with no %CHK% → an alarm is announced on the speakers and the responders are called one at a time, in list order, until someone presses %KEY% to take charge.'))); ?>,
		disarm: <?php echo json_encode(_('%EXT% dials %DIS% → the session is closed and the speakers announce it was disarmed.')); ?>,
		noPg: <?php echo json_encode(_('⚠ No paging group selected: announcements on the speakers will not work until you pick one below.')); ?>,
		noRg: <?php echo json_encode(_('⚠ No responders configured: the emergency call cascade will not work until you add internal and/or external numbers below.')); ?>,
		exLabel: <?php echo json_encode(_('Preview extension (used only for this example, not saved)')); ?>
	}
};
</script>

<!-- NAV -->
<div style="margin-bottom:12px">
	<a href="config.php?display=loneworker" class="btn btn-default"><i class="fa fa-dashboard"></i> <?php echo _('Dashboard') ?></a>
	<a href="config.php?display=loneworker&amp;view=sessions" class="btn btn-default"><i class="fa fa-list"></i> <?php echo _('Active sessions') ?></a>
	<a href="config.php?display=loneworker&amp;view=events" class="btn btn-default"><i class="fa fa-history"></i> <?php echo _('Event history') ?></a>
	<a href="config.php?display=loneworker&amp;view=settings" class="btn btn-primary"><i class="fa fa-cog"></i> <?php echo _('Settings') ?></a>
</div>

<!-- LIVE EXAMPLE -->
<div class="element-container">
	<div class="row"><div class="col-md-12">
		<div class="panel panel-info" id="lw-example-panel">
			<div class="panel-heading"><i class="fa fa-info-circle"></i> <strong id="lw-ex-title"></strong></div>
			<div class="panel-body">
				<div class="form-inline" style="margin-bottom:10px">
					<label for="lw-ex-ext" id="lw-ex-extlabel" style="font-weight:normal"></label>
					<input type="text" class="form-control input-sm" id="lw-ex-ext" value="301" style="width:90px">
				</div>
				<p id="lw-ex-intro" style="font-weight:bold"></p>
				<ul id="lw-ex-list" style="line-height:1.7"></ul>
				<div id="lw-ex-warn"></div>
			</div>
		</div>
	</div></div>
</div>

<?php
// Readiness / preflight panel
$checks = \FreePBX::Loneworker()->checkReadiness();
$nfail = count(array_filter($checks, fn($c) => $c['level'] === 'fail'));
$nwarn = count(array_filter($checks, fn($c) => $c['level'] === 'warn'));
$panelClass = $nfail ? 'danger' : ($nwarn ? 'warning' : 'success');
$verdict = $nfail ? _('Problems to fix before alarms work reliably') : ($nwarn ? _('Ready, with warnings') : _('Ready for alarms'));
$icon = ['ok' => 'fa-check text-success', 'warn' => 'fa-exclamation-triangle text-warning', 'fail' => 'fa-times-circle text-danger'];
?>
<div class="element-container"><div class="row"><div class="col-md-12">
	<div class="panel panel-<?php echo $panelClass ?>">
		<div class="panel-heading"><i class="fa fa-stethoscope"></i> <strong><?php echo _('Readiness check') ?></strong> — <?php echo $verdict ?>
			<a class="btn btn-xs btn-default pull-right" href="config.php?display=loneworker&amp;view=settings"><i class="fa fa-refresh"></i> <?php echo _('Re-check') ?></a>
		</div>
		<div class="panel-body"><ul class="list-unstyled" style="margin:0">
		<?php foreach ($checks as $c): ?>
			<li><i class="fa <?php echo $icon[$c['level']] ?? 'fa-info' ?>"></i> <?php echo htmlspecialchars($c['msg']) ?></li>
		<?php endforeach; ?>
		</ul></div>
	</div>
</div></div></div>

<form class="fpbx-submit" name="lwsettings" action="config.php?display=loneworker&amp;view=settings" method="post">
<input type="hidden" name="display" value="loneworker">
<input type="hidden" name="view" value="settings">
<input type="hidden" name="action" value="savesettings">

<ul class="nav nav-tabs" role="tablist" style="margin-bottom:15px">
	<li class="active"><a href="#lw-tab-howto" data-toggle="tab"><i class="fa fa-book"></i> <?php echo _('How it works') ?></a></li>
	<li><a href="#lw-tab-timers" data-toggle="tab"><?php echo _('Timers') ?></a></li>
	<li><a href="#lw-tab-codes" data-toggle="tab"><?php echo _('Feature codes') ?></a></li>
	<li><a href="#lw-tab-calls" data-toggle="tab"><?php echo _('Responders & calls') ?></a></li>
	<li><a href="#lw-tab-limits" data-toggle="tab"><?php echo _('Limits') ?></a></li>
	<li><a href="#lw-tab-ann" data-toggle="tab"><?php echo _('Announcements') ?></a></li>
</ul>
<div class="tab-content">
<?php
// --- "How it works" guide, filled with the current configuration so it is concrete ---
$gTimeout  = max(1, (int) round($s['timeout'] / 60));
$gRemAfter = max(1, (int) round($s['reminder_after'] / 60));
$gRemInt   = max(1, (int) round($s['reminder_interval'] / 60));
$gRepeat   = max(1, (int) round($s['alarm_repeat'] / 60));
$gPg       = trim((string) $s['paging_group']) !== '' ? $s['paging_group'] : _('(not set)');
$gMembers  = \FreePBX::Loneworker()->alarmMembers();
$gMode     = ($s['alarm_mode'] === 'sequence') ? _('one at a time, in list order') : _('all at the same time');
$gKey      = htmlspecialchars((string) $s['confirm_key']);
$gConfMin  = max(1, (int) round((int) $s['confirm_timeout'] / 1)); // seconds (shown as-is)
$gHold     = ($s['confirm_action'] === 'hold');
?>
<div role="tabpanel" class="tab-pane active" id="lw-tab-howto">
	<p class="lead" style="font-size:15px"><?php echo _('How the Lone Worker system works for everyone involved: the operator working alone, the people near the speakers, and the responders called in an emergency. The numbers below reflect your current settings.') ?></p>

	<h3><?php echo _('1) What the operator does — feature codes') ?></h3>
	<table class="table table-bordered">
		<thead><tr>
			<th><?php echo _('Dial') ?></th>
			<th><?php echo _('What it means') ?></th>
			<th><i class="fa fa-mobile"></i> <?php echo _('The operator hears (own phone)') ?></th>
			<th><i class="fa fa-volume-up"></i> <?php echo _('The speakers announce (everyone nearby hears)') ?></th>
		</tr></thead>
		<tbody>
			<tr>
				<td><strong><?php echo htmlspecialchars($fcArm) ?></strong></td>
				<td><?php echo sprintf(_('Arm: "I am starting to work alone." A %d-minute countdown begins.'), $gTimeout) ?></td>
				<td><?php echo _('A short confirmation beep.') ?></td>
				<td><?php echo sprintf(_('"Attention. Lone worker system armed for extension N. Confirm within %1$d minutes by calling %2$s."'), $gTimeout, htmlspecialchars($fcCheckin)) ?></td>
			</tr>
			<tr>
				<td><strong><?php echo htmlspecialchars($fcCheckin) ?></strong></td>
				<td><?php echo _('Check-in: "I am OK." Resets the countdown.') ?></td>
				<td><?php echo _('A short confirmation beep.') ?></td>
				<td><?php echo _('"Lone worker: extension N has confirmed presence. The system stays active."') ?></td>
			</tr>
			<tr>
				<td><strong><?php echo htmlspecialchars($fcDisarm) ?></strong></td>
				<td><?php echo _('Disarm: "I have finished." Monitoring stops.') ?></td>
				<td><?php echo _('A short confirmation beep.') ?></td>
				<td><?php echo _('"Lone worker system disarmed for extension N."') ?></td>
			</tr>
		</tbody>
	</table>
	<p class="help-block"><?php echo _('The acting extension is taken automatically from the phone\'s Caller ID, so each operator can only arm, confirm or disarm their OWN session. If the code cannot be applied (e.g. already armed, or not authorised) the phone plays an error tone instead of a beep.') ?></p>

	<h3><?php echo _('2) While armed — reminders') ?></h3>
	<ul style="line-height:1.8">
		<li><?php echo sprintf(_('After arming, the operator has %1$d minutes to check in by dialing %2$s.'), $gTimeout, htmlspecialchars($fcCheckin)) ?></li>
		<li><?php echo sprintf(_('After %1$d minutes a reminder is announced on the speakers, then repeated every %2$d minutes until the deadline.'), $gRemAfter, $gRemInt) ?></li>
		<li><?php echo sprintf(_('Dialing %1$s at any time resets the countdown back to %2$d minutes ("I am still OK").'), htmlspecialchars($fcCheckin), $gTimeout) ?></li>
		<li><?php echo sprintf(_('Dialing %s stops the monitoring for that operator.'), htmlspecialchars($fcDisarm)) ?></li>
	</ul>

	<h3><?php echo _('3) If the operator does not confirm — the alarm') ?></h3>
	<ul style="line-height:1.8">
		<li><?php echo _('When the countdown reaches zero, the speakers announce: "Extension N did not confirm — check the operator immediately."') ?></li>
		<li><?php echo sprintf(_('The configured responders are then called %1$s (%2$d configured).'), $gMode, count($gMembers)) ?></li>
		<li><?php echo sprintf(_('Whoever answers hears: "Lone worker alarm, the operator of extension N did not confirm — press %1$s to take charge." They have %2$d seconds to press %1$s (the prompt repeats up to 3 times). Pressing %1$s takes charge: every other call stops immediately.'), $gKey, (int) $s['confirm_timeout']) ?></li>
		<li><?php echo $gHold
			? _('Once taken charge of, the session is kept as "TAKEN CHARGE" (no more calls or announcements) until an operator disarms it manually.')
			: _('Once taken charge of, the session is closed (incident over).'); ?>
			<?php echo !empty($s['confirm_announce'])
				? _('The speakers announce that the alarm was taken charge of.')
				: _('No announcement is played on the speakers.'); ?></li>
		<li><?php echo sprintf(_('If nobody presses %1$s, the alarm keeps re-announcing on the speakers and re-calling the responders every %2$d minutes, until someone takes charge or an operator disarms.'), $gKey, $gRepeat) ?></li>
	</ul>

	<h3><?php echo _('Who hears what, from where') ?></h3>
	<div class="alert alert-info"><i class="fa fa-volume-up"></i> <strong><?php echo sprintf(_('Speakers — paging group %s'), htmlspecialchars((string) $gPg)) ?></strong><br>
		<?php echo _('Every announcement: armed, reminder, confirmed, alarm, taken charge, disarmed. Heard by everyone near the speakers.') ?></div>
	<div class="alert alert-success"><i class="fa fa-mobile"></i> <strong><?php echo _('The operator\'s own phone (cordless)') ?></strong><br>
		<?php echo sprintf(_('Only a short confirmation beep when dialing %1$s / %2$s / %3$s. No spoken message.'), htmlspecialchars($fcArm), htmlspecialchars($fcCheckin), htmlspecialchars($fcDisarm)) ?></div>
	<div class="alert alert-warning"><i class="fa fa-phone"></i> <strong><?php echo _('The responders\' phones (internal extensions and external numbers)') ?></strong><br>
		<?php echo sprintf(_('Only during an alarm: the emergency call asking to press %s to take charge. Only the person who answers each call hears it.'), $gKey) ?></div>
</div>

<div role="tabpanel" class="tab-pane" id="lw-tab-timers">
<h3><?php echo _('Timers') ?></h3>
<?php
lw_num(_('Timeout before alarm (seconds)'), 'timeout', $s['timeout'], _('How long an armed operator has to confirm before the alarm fires. Resets on every check-in (702). Default 1800 = 30 minutes.'));
lw_num(_('First reminder after (seconds)'), 'reminder_after', $s['reminder_after'], _('Time from arm/check-in to the first reminder on the speakers. Default 900 = 15 minutes. Must be smaller than the timeout.'));
lw_num(_('Reminder interval (seconds)'), 'reminder_interval', $s['reminder_interval'], _('How often the reminder repeats after the first one, until the deadline. Default 300 = 5 minutes.'));
?>

</div><!-- /timers -->
<div role="tabpanel" class="tab-pane" id="lw-tab-codes">
<h3><?php echo _('Feature codes (numbers to dial)') ?></h3>
<?php
$fcRows = [
	['fc_arm',     _('Arm code'),      $fcArm,     _('Number the operator dials to arm a session (default 701).')],
	['fc_checkin', _('Check-in code'), $fcCheckin, _('Number the operator dials to confirm presence (default 702). This number is announced dynamically inside the "armed" and "reminder" messages, so if you change it the audio follows automatically.')],
	['fc_disarm',  _('Disarm code'),   $fcDisarm,  _('Number the operator dials to disarm their session (default 703).')],
];
foreach ($fcRows as $fr) {
	echo '<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">';
	echo '<div class="col-md-4"><label class="control-label" for="' . $fr[0] . '">' . $fr[1] . '</label></div>';
	echo '<div class="col-md-8"><input type="text" class="form-control lw-fc" id="' . $fr[0] . '" name="' . $fr[0] . '" value="' . htmlspecialchars($fr[2]) . '">'
		. '<div class="text-danger" style="font-weight:bold;margin-top:4px" id="' . $fr[0] . '-warn"></div></div>';
	echo '</div></div></div></div>';
	echo '<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block">' . $fr[3] . '</span></div></div></div>';
}
?>

</div><!-- /codes -->
<div role="tabpanel" class="tab-pane" id="lw-tab-calls">
<h3><?php echo _('Audio & call routing') ?></h3>
<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
	<div class="col-md-4"><label class="control-label" for="paging_group"><?php echo _('Paging group (speakers)') ?></label></div>
	<div class="col-md-8"><?php echo lw_group_select('paging_group', $pagings, $s['paging_group']); ?></div>
</div></div></div></div>
<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block"><?php echo _('The paging/intercom group whose speakers play every announcement (armed, reminder, confirmed, alarm, acknowledged, disarmed). Create it under Applications → Paging and Intercom.') ?></span></div></div></div>

<?php
$allUsers = [];
try { $allUsers = \FreePBX::Core()->getAllUsers(); } catch (\Throwable $e) {}
// soft migration: if the single list is empty but the old fields exist, pre-fill the textarea
$numbersVal = (string) $s['alarm_numbers'];
if (trim($numbersVal) === '') {
	$mig = array_merge(
		preg_split('/[\s,]+/', (string) $s['alarm_internal_exts'], -1, PREG_SPLIT_NO_EMPTY),
		preg_split('/[\s,]+/', (string) $s['alarm_external_numbers'], -1, PREG_SPLIT_NO_EMPTY)
	);
	$numbersVal = implode("\n", $mig);
}
?>
<!-- Single ORDERED list of numbers to call -->
<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
	<div class="col-md-4"><label class="control-label" for="alarm_numbers"><?php echo _('Numbers to call (in order)') ?></label></div>
	<div class="col-md-8">
		<textarea class="form-control" id="alarm_numbers" name="alarm_numbers" rows="6" placeholder="301&#10;3331234567&#10;0119876543"><?php echo htmlspecialchars($numbersVal); ?></textarea>
		<div class="input-group" style="margin-top:6px">
			<span class="input-group-btn">
				<select class="form-control" id="lw-add-ext" style="border-radius:4px 0 0 4px">
					<option value=""><?php echo _('Pick an extension…') ?></option>
				</select>
			</span>
			<span class="input-group-btn"><button type="button" class="btn btn-default" id="lw-add-ext-btn"><i class="fa fa-plus"></i> <?php echo _('Add to list') ?></button></span>
		</div>
		<script>(window.LW = window.LW || {}).extList = <?php
			$extListArr = [];
			foreach ($allUsers as $u) { $extListArr[] = ['e' => (string) $u['extension'], 'n' => (string) $u['name']]; }
			echo json_encode($extListArr, JSON_UNESCAPED_UNICODE);
		?>;</script>
	</div>
</div></div></div></div>
<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block"><?php echo _('One number per line, in the order they should be tried — internal extensions and external numbers together. Use the picker to add an internal extension, or just type a number. External numbers are dialed through your outbound routes (see the Readiness panel above).') ?></span></div></div></div>

<!-- Call mode: simultaneous vs sequence -->
<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
	<div class="col-md-4"><label class="control-label" for="alarm_mode"><?php echo _('Call mode') ?></label></div>
	<div class="col-md-8">
		<select class="form-control" id="alarm_mode" name="alarm_mode">
			<option value="simultaneous"<?php echo ($s['alarm_mode'] !== 'sequence') ? ' selected' : '' ?>><?php echo _('All at once (ring everyone together)') ?></option>
			<option value="sequence"<?php echo ($s['alarm_mode'] === 'sequence') ? ' selected' : '' ?>><?php echo _('In sequence (one at a time, in list order)') ?></option>
		</select>
	</div>
</div></div></div></div>
<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block"><?php echo sprintf(_('All at once: every number rings at the same time; the first to press %1$s takes charge and the others stop. In sequence: each number is tried for the ring time, one after another in list order, until someone presses %1$s.'), $gKey) ?></span></div></div></div>
<?php
lw_num(_('Alarm ring time (seconds)'), 'ring_time', $s['ring_time'], _('How long each responder rings during an alarm before giving up (per attempt). Default 45.'));
lw_num(_('Repeat alarm every (seconds)'), 'alarm_repeat', $s['alarm_repeat'], _('If nobody presses the confirm key, the alarm is re-announced and the responders are called again every this many seconds, until someone takes charge or an operator disarms. Keep it larger than the ring time. Default 120 (2 min).'));
?>

<h3><?php echo _('Responder confirmation') ?></h3>
<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
	<div class="col-md-4"><label class="control-label" for="confirm_key"><?php echo _('Key to take charge') ?></label></div>
	<div class="col-md-8">
		<select class="form-control" id="confirm_key" name="confirm_key">
		<?php foreach (['1','2','3','4','5','6','7','8','9','0','*'] as $k): ?>
			<option value="<?php echo $k ?>"<?php echo ((string) $s['confirm_key'] === $k) ? ' selected' : '' ?>><?php echo $k ?></option>
		<?php endforeach; ?>
		</select>
	</div>
</div></div></div></div>
<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block"><?php echo _('The DTMF key a responder presses on their phone to take charge of the alarm (which stops all the other calls). Default 1.') ?></span></div></div></div>
<?php
lw_num(_('Confirmation timeout (seconds)'), 'confirm_timeout', $s['confirm_timeout'], _('How long a responder has to press the key after each prompt. The prompt is repeated up to 3 times. Default 15.'));
?>
<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
	<div class="col-md-4"><label class="control-label" for="confirm_action"><?php echo _('After the alarm is confirmed') ?></label></div>
	<div class="col-md-8">
		<select class="form-control" id="confirm_action" name="confirm_action">
			<option value="disarm"<?php echo ($s['confirm_action'] !== 'hold') ? ' selected' : '' ?>><?php echo _('Close the session (incident over)') ?></option>
			<option value="hold"<?php echo ($s['confirm_action'] === 'hold') ? ' selected' : '' ?>><?php echo _('Keep it as "taken charge" until an operator disarms') ?></option>
		</select>
	</div>
</div></div></div></div>
<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block"><?php echo _('What happens once a responder takes charge. Close the session: it ends and no more calls or announcements are made. Keep as taken charge: the calls and re-announcements stop, but the session stays visible (state "TAKEN CHARGE") until someone disarms it manually — useful to keep a record that it is being handled.') ?></span></div></div></div>

<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
	<div class="col-md-4"><label class="control-label" for="confirm_announce"><?php echo _('Announce "taken charge" on the speakers') ?></label></div>
	<div class="col-md-8">
		<select class="form-control" id="confirm_announce" name="confirm_announce">
			<option value="1"<?php echo !empty($s['confirm_announce']) ? ' selected' : '' ?>><?php echo _('Yes') ?></option>
			<option value="0"<?php echo empty($s['confirm_announce']) ? ' selected' : '' ?>><?php echo _('No') ?></option>
		</select>
	</div>
</div></div></div></div>
<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block"><?php echo _('When a responder takes charge, play the "alarm taken charge of" announcement on the speakers (paging group) so everyone on site knows it is being handled.') ?></span></div></div></div>

<!-- Spoken-digits language -->
<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
	<div class="col-md-4"><label class="control-label" for="digit_language"><?php echo _('Spoken number language') ?></label></div>
	<div class="col-md-8"><?php
		$langs = ['it' => 'Italiano', 'en' => 'English', 'fr' => 'Français', 'de' => 'Deutsch', 'es' => 'Español'];
		echo '<select class="form-control" id="digit_language" name="digit_language">';
		foreach ($langs as $code => $lab) { echo '<option value="' . $code . '"' . ($s['digit_language'] === $code ? ' selected' : '') . '>' . $lab . ' (' . $code . ')</option>'; }
		echo '</select>';
	?></div>
</div></div></div></div>
<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block"><?php echo _('Language used to speak the extension and the check-in number (SayDigits). Set it to match your recordings (default Italian), otherwise the numbers are spoken in the system default language.') ?></span></div></div></div>

<h3><?php echo _('Alarm calls Caller ID (for external numbers)') ?></h3>
<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
	<div class="col-md-4"><label class="control-label" for="alarm_caller_ext"><?php echo _('Service extension for alarm calls') ?></label></div>
	<div class="col-md-8">
		<select class="form-control" id="alarm_caller_ext" name="alarm_caller_ext">
			<option value=""><?php echo _('(the down worker\'s own extension)') ?></option>
			<?php foreach ($allUsers as $u): $e = (string) $u['extension']; ?>
				<option value="<?php echo htmlspecialchars($e) ?>"<?php echo ((string) $s['alarm_caller_ext'] === $e) ? ' selected' : '' ?>><?php echo htmlspecialchars($e . ' - ' . $u['name']) ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</div></div></div></div>
<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block"><?php echo _('External alarm calls go out with this extension\'s identity — they inherit its Outbound CID and pass its CID-filtered outbound routes. Leave on default to call out as the down worker\'s extension.') ?></span></div></div></div>

<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
	<div class="col-md-4"><label class="control-label" for="alarm_outbound_cid"><?php echo _('Explicit outbound Caller ID') ?></label></div>
	<div class="col-md-8"><input type="text" class="form-control" id="alarm_outbound_cid" name="alarm_outbound_cid" value="<?php echo htmlspecialchars($s['alarm_outbound_cid']); ?>" placeholder="<?php echo _('e.g. 0119876543') ?>"></div>
</div></div></div></div>
<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block"><?php echo _('If set, this number is presented as Caller ID on external alarm calls and OVERRIDES the service extension. Use a number your carrier accepts and that your outbound routes do not filter out. The spoken announcement still says the down worker\'s extension.') ?></span></div></div></div>

<!-- Test buttons (live AJAX, with stop) -->
<div class="element-container"><div class="row"><div class="col-md-12">
	<button type="button" class="btn btn-default" id="lw-test-announce"><i class="fa fa-volume-up"></i> <?php echo _('Test announcement (speakers)') ?></button>
	<button type="button" class="btn btn-default" id="lw-test-alarm"><i class="fa fa-phone"></i> <?php echo _('Test alarm call') ?></button>
	<button type="button" class="btn btn-danger" id="lw-test-stop" style="display:none"><i class="fa fa-stop"></i> <?php echo _('Stop test') ?></button>
	<div id="lw-test-status" class="alert alert-info" style="display:none;margin-top:10px"></div>
	<span class="help-block"><?php echo _('Tests use the saved settings and a fake extension (000); they do not create a session. The status updates live and you can stop a running test.') ?></span>
</div></div></div>
<script>
(window.LW = window.LW || {}).test = {
	ajax: 'ajax.php?module=loneworker',
	starting: <?php echo json_encode(_('Starting…')); ?>,
	running: <?php echo json_encode(_('Test running — %d active call(s).')); ?>,
	announcing: <?php echo json_encode(_('Announcement playing on the speakers…')); ?>,
	playing: <?php echo json_encode(_('Playing on the speakers…')); ?>,
	ended: <?php echo json_encode(_('Test ended.')); ?>,
	stoppedMsg: <?php echo json_encode(_('Test stopped (%d call(s) hung up).')); ?>,
	failed: <?php echo json_encode(_('Could not start the test — check the Readiness panel above (paging group / responders / AMI).')); ?>,
	confirmAlarm: <?php echo json_encode(_('Place a test alarm call to the responders now?')); ?>,
	stRinging: <?php echo json_encode(_('ringing')); ?>,
	stUp: <?php echo json_encode(_('answered')); ?>,
	stOther: <?php echo json_encode(_('in progress')); ?>
};
</script>

</div><!-- /calls -->
<div role="tabpanel" class="tab-pane" id="lw-tab-limits">
<h3><?php echo _('Limits & authorisation') ?></h3>
<div class="element-container"><div class="row"><div class="col-md-12"><div class="row"><div class="form-group">
	<div class="col-md-4"><label class="control-label" for="authorized_extensions"><?php echo _('Authorised extensions') ?></label></div>
	<div class="col-md-8"><input type="text" class="form-control" id="authorized_extensions" name="authorized_extensions" value="<?php echo htmlspecialchars($s['authorized_extensions']); ?>" placeholder="<?php echo _('e.g. 301 302 121') ?>"></div>
</div></div></div></div>
<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block"><?php echo _('Space- or comma-separated list of extensions allowed to use 701/702/703. Leave empty to allow every extension. The acting extension is taken from the phone\'s Caller ID, so each operator can only control their own session.') ?></span></div></div></div>
<?php
lw_num(_('Max concurrent sessions'), 'max_sessions', $s['max_sessions'], _('Safety cap on how many operators can be armed at the same time. Further arm attempts are rejected with an error tone.'));
lw_num(_('Event retention (days)'), 'retention_days', $s['retention_days'], _('Audit events (arm, check-in, alarm, acknowledge, etc.) older than this are deleted automatically. 0 = keep forever.'));
?>

</div><!-- /limits -->
<div role="tabpanel" class="tab-pane" id="lw-tab-ann">
<h3><?php echo _('Announcements (built-in)') ?></h3>
<div class="alert alert-info"><i class="fa fa-lock"></i> <?php echo _('The announcement audio is built into the module (Italian) and is not editable: the clips ship pre-installed and are played automatically. Between the two parts of each message the system speaks the extension number digit by digit, e.g. "...extension 3-0-1...".') ?></div>
<p class="help-block"><i class="fa fa-volume-up"></i> <?php echo _('Use the "Play on speakers" buttons below to preview each message live on the paging group (a sample extension number is spoken). You can stop playback at any time.') ?></p>
<div style="margin-bottom:12px">
	<button type="button" class="btn btn-danger" id="lw-play-stop" style="display:none"><i class="fa fa-stop"></i> <?php echo _('Stop playback') ?></button>
	<div id="lw-play-status" class="alert alert-info" style="display:none;margin-top:10px"></div>
</div>

<?php
// Read-only reference of the fixed built-in messages and who hears each one.
$groups = [
	['title' => _('Armed — played when an operator dials 701'),                 'aud' => 'speakers', 'callnum' => true, 'msg' => 'arm',
		'pre' => _('Attention. Lone worker system armed for extension'),
		'post' => _('must confirm their presence by calling number')],
	['title' => _('Confirmed — played when an operator dials 702'),              'aud' => 'speakers', 'msg' => 'confirm',
		'pre' => _('Lone worker: extension'),
		'post' => _('has confirmed their presence. The system stays active.')],
	['title' => _('Reminder — played before the deadline'),                     'aud' => 'speakers', 'callnum' => true, 'msg' => 'reminder',
		'pre' => _('Lone worker reminder. Extension'),
		'post' => _('must confirm presence. Only a few minutes remain before the alarm. Call number')],
	['title' => _('Alarm — played when the timeout expires'),                    'aud' => 'speakers', 'msg' => 'alarm',
		'pre' => _('Lone worker alarm. Extension'),
		'post' => _('did not confirm. Check the operator immediately. Emergency calls have been started.')],
	['title' => _('Acknowledged — played when a responder takes charge'),        'aud' => 'speakers', 'msg' => 'ack',
		'pre' => _('Lone worker alarm taken charge of for extension'),
		'post' => _('A responder is checking the situation on site.')],
	['title' => _('Disarmed — played when an operator dials 703'),               'aud' => 'speakers', 'msg' => 'disarm',
		'pre' => _('Lone worker system disarmed for extension'),
		'post' => null],
	['title' => _('Emergency call — played to the responders during the alarm'), 'aud' => 'phone', 'msg' => 'call',
		'pre' => _('Lone worker alarm. The operator of extension'),
		'post' => _('did not confirm. Press one to take charge of the alarm.')],
];

$audienceHtml = function ($aud) {
	if ($aud === 'phone') {
		return '<div class="alert alert-warning" style="padding:6px 10px;margin-bottom:10px"><i class="fa fa-phone"></i> '
			. _('Heard on the responders\' phones (the internal extensions and external numbers configured below) — only the person who answers the call hears this.') . '</div>';
	}
	return '<div class="alert alert-info" style="padding:6px 10px;margin-bottom:10px"><i class="fa fa-volume-up"></i> '
		. sprintf(_('Heard on the speakers (paging group %s) — everyone near the speakers hears this.'), '<span class="label label-primary lw-pg-num">91</span>') . '</div>';
};
?>
<?php foreach ($groups as $g): ?>
<div class="panel panel-<?php echo $g['aud'] === 'phone' ? 'warning' : 'info' ?>">
	<div class="panel-heading"><strong><?php echo $g['title'] ?></strong></div>
	<div class="panel-body">
		<?php echo $audienceHtml($g['aud']); ?>
		<blockquote style="font-size:14px;margin:0">
			&ldquo;<?php echo htmlspecialchars($g['pre']); ?>
			<span class="label label-success lw-ex-num">301</span>
			<?php if ($g['post']) echo htmlspecialchars($g['post']); ?>
			<?php if (!empty($g['callnum'])): ?><span class="label label-warning lw-cn-num">702</span><?php endif; ?>&rdquo;
		</blockquote>
		<?php if (!empty($g['callnum'])): ?>
		<p class="help-block" style="margin-top:6px"><i class="fa fa-info-circle"></i> <?php echo _('The check-in number is spoken automatically here (SayDigits), taken from the Check-in feature code above.') ?></p>
		<?php endif; ?>
		<button type="button" class="btn btn-sm btn-primary lw-play" data-msg="<?php echo htmlspecialchars($g['msg']) ?>"><i class="fa fa-play"></i> <?php echo _('Play on speakers') ?></button>
	</div>
</div>
<?php endforeach; ?>
</div><!-- /announcements -->
</div><!-- /tab-content -->
</form>
