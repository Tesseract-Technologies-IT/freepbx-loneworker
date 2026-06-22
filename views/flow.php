<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
// Lone Worker — dynamic "Alarm flow" diagram (complete, with all branches + spoken messages),
// generated from the current config and rendered with the bundled Mermaid.
$f = $flow;
$esc = fn($v) => htmlspecialchars((string) $v);
$modeLabel = $f['mode'] === 'sequence' ? _('in sequence') : _('all at once');
$postLabel = $f['confirm_action'] === 'hold' ? _('Session kept as taken-charge until disarm') : _('Session closed (incident over)');

// --- build the complete branching flowchart (Mermaid) ---
$L = [
	'arm'      => sprintf(_('Operator arms (%s)'), $f['arm']),
	'msgArmed' => _('Speakers: lone worker armed for extension N'),
	'confirm'  => sprintf(_('Check-in (%1$s) within %2$d min? (periodic reminders meanwhile)'), $f['checkin'], $f['timeout_min']),
	'msgConfirmed' => _('Speakers: presence confirmed — back to monitoring (timer reset)'),
	'disarmLbl' => sprintf(_('Disarm (%s)'), $f['disarm']),
	'msgDisarmed' => _('Speakers: system disarmed — session closed'),
	'alarm'    => _('ALARM'),
	'msgAlarm' => _('Speakers: extension N did not confirm'),
	'call'     => sprintf(_('Call responders (%s)'), $modeLabel),
	'answered' => _('A responder answers?'),
	'prompt'   => sprintf(_('To the responder: press %s to take charge'), $f['confirm_key']),
	'key'      => sprintf(_('Presses %1$s within %2$ds?'), $f['confirm_key'], $f['confirm_timeout']),
	'ack'      => _('Takes charge — all other calls stop'),
	'msgAck'   => _('Speakers: alarm taken charge of'),
	'post'     => $postLabel,
	'repeatNode' => sprintf(_('Nobody takes charge — repeat announcement + calls every %ds'), $f['repeat']),
	'yes' => _('yes'), 'timeout' => _('no: timed out'), 'no' => _('no answer'), 'hangup' => _('no'),
];
// sanitise label text for Mermaid (inside double quotes): drop quotes/newlines
$q = function ($s) { return str_replace(['"', "\n", "\r"], ['', ' ', ' '], (string) $s); };
// Clean top-down layout: one main path, the only back-edge is the "repeat" loop.
$mm  = "flowchart TD\n";
$mm .= '  A["📟 ' . $q($L['arm']) . '"]:::act --> MA["📢 ' . $q($L['msgArmed']) . '"]:::msg' . "\n";
$mm .= '  MA --> W{"' . $q($L['confirm']) . '"}:::dec' . "\n";
$mm .= '  W -->|"' . $q($L['yes']) . '"| MC["📢 ' . $q($L['msgConfirmed']) . '"]:::done' . "\n";
$mm .= '  W -->|"' . $q($L['disarmLbl']) . '"| DZ["📢 ' . $q($L['msgDisarmed']) . '"]:::done' . "\n";
$mm .= '  W -->|"' . $q($L['timeout']) . '"| AL(["⚠ ' . $q($L['alarm']) . '"]):::al' . "\n";
$mm .= '  AL --> MAL["📢 ' . $q($L['msgAlarm']) . '"]:::msg' . "\n";
$mm .= '  MAL --> C["📞 ' . $q($L['call']) . '"]:::act' . "\n";
$mm .= '  C --> ANS{"' . $q($L['answered']) . '"}:::dec' . "\n";
$mm .= '  ANS -->|"' . $q($L['yes']) . '"| PR["📢 ' . $q($L['prompt']) . '"]:::msg' . "\n";
$mm .= '  PR --> K{"' . $q($L['key']) . '"}:::dec' . "\n";
$mm .= '  K -->|"' . $q($L['yes']) . '"| ACK["✅ ' . $q($L['ack']) . '"]:::act' . "\n";
$mm .= '  ACK --> MACK["📢 ' . $q($L['msgAck']) . '"]:::msg' . "\n";
$mm .= '  MACK --> P["🏁 ' . $q($L['post']) . '"]:::done' . "\n";
$mm .= '  ANS -->|"' . $q($L['no']) . '"| REP["🔁 ' . $q($L['repeatNode']) . '"]:::al' . "\n";
$mm .= '  K -->|"' . $q($L['hangup']) . '"| REP' . "\n";
$mm .= '  REP --> AL' . "\n";
$mm .= "  classDef msg fill:#d9edf7,stroke:#31708f,color:#222;\n";
$mm .= "  classDef dec fill:#fcf8e3,stroke:#8a6d3b,color:#222;\n";
$mm .= "  classDef al fill:#f2dede,stroke:#a94442,color:#222;\n";
$mm .= "  classDef act fill:#dff0d8,stroke:#3c763d,color:#222;\n";
$mm .= "  classDef done fill:#dff0d8,stroke:#3c763d,color:#222;\n";
?>
<div id="toolbar-flow" style="margin-bottom:12px">
	<a href="config.php?display=loneworker" class="btn btn-default"><i class="fa fa-dashboard"></i> <?php echo _('Dashboard') ?></a>
	<a href="config.php?display=loneworker&amp;view=sessions" class="btn btn-default"><i class="fa fa-list"></i> <?php echo _('Active sessions') ?></a>
	<a href="config.php?display=loneworker&amp;view=history" class="btn btn-default"><i class="fa fa-folder-open"></i> <?php echo _('Session log') ?></a>
	<a href="config.php?display=loneworker&amp;view=flow" class="btn btn-primary"><i class="fa fa-sitemap"></i> <?php echo _('Alarm flow') ?></a>
	<a href="config.php?display=loneworker&amp;view=settings" class="btn btn-default"><i class="fa fa-cog"></i> <?php echo _('Settings') ?></a>
</div>

<div class="alert alert-info"><i class="fa fa-sitemap"></i> <?php echo _('Complete alarm flow generated from the current configuration — every branch (check-in, answered, key pressed, nobody confirms, disarm) and every spoken message (📢). Update it by changing the Settings.') ?></div>

<div class="panel panel-default">
	<div class="panel-body" style="overflow:auto">
		<pre class="mermaid" style="text-align:center;background:transparent;border:0"><?php echo $esc($mm); ?></pre>
		<div id="lw-mm-fallback" class="text-muted" style="display:none"><?php echo _('(Diagram could not be rendered here — use the source below.)') ?></div>
	</div>
</div>

<!-- Concrete responders & outbound routing (what the generic "Call responders" box expands to) -->
<div class="panel panel-default">
	<div class="panel-heading"><strong><i class="fa fa-phone"></i> <?php echo sprintf(_('Responders, in call order — %s'), $modeLabel) ?></strong></div>
	<div class="panel-body">
		<?php if (empty($f['responders'])): ?>
			<div class="alert alert-danger" style="margin:0"><i class="fa fa-exclamation-triangle"></i> <?php echo _('No responders configured — nobody is called!') ?></div>
		<?php else: ?>
		<ol style="margin:0 0 0 18px">
			<?php foreach ($f['responders'] as $r): ?>
			<li style="margin:3px 0">
				<strong><?php echo $esc($r['num']) ?></strong>
				<?php if ($r['type'] === 'internal'): ?>
					<span class="label label-success"><?php echo _('internal') ?></span> <?php echo $esc($r['name']) ?>
				<?php else:
					if (!$r['matched']) { echo '<span class="label label-danger">' . _('external — NO outbound route!') . '</span>'; }
					elseif ($r['cid_blocked']) { echo '<span class="label label-warning">' . _('external — route filtered by Caller ID') . '</span>'; }
					elseif (!$r['trunk_ok']) { echo '<span class="label label-danger">' . sprintf(_('external — route "%s", trunk disabled'), $esc($r['route'])) . '</span>'; }
					else { echo '<span class="label label-info">' . sprintf(_('external — via route "%s"'), $esc($r['route'])) . '</span>'; }
				endif; ?>
			</li>
			<?php endforeach; ?>
		</ol>
		<?php if ($f['has_external']): ?>
			<p class="help-block" style="margin-top:8px"><?php echo sprintf(_('External calls present Caller ID: %s'),
				$f['cid'] !== '' ? '<code>' . $esc($f['cid']) . '</code>' : ('<span class="text-warning">' . _('the down worker\'s extension (may be rejected)') . '</span>')); ?></p>
		<?php endif; ?>
		<?php endif; ?>
		<p class="help-block" style="margin-top:6px">
			<?php echo sprintf(_('Speakers: paging group %s · take-charge key: %s · ring %ds · repeat every %ds'),
				$f['paging'] !== '' ? ('<code>' . $esc($f['paging']) . '</code>') : ('<span class="text-danger">' . _('NOT SET') . '</span>'),
				'<code>' . $esc($f['confirm_key']) . '</code>', $f['ring_time'], $f['repeat']); ?>
		</p>
	</div>
</div>

<div style="margin-top:6px">
	<button type="button" class="btn btn-default btn-sm" onclick="var t=document.getElementById('lw-mm-src');t.style.display=t.style.display==='none'?'block':'none';"><i class="fa fa-code"></i> <?php echo _('Diagram source (Mermaid) — for export') ?></button>
	<textarea id="lw-mm-src" class="form-control" rows="12" style="display:none;margin-top:8px;font-family:monospace;font-size:12px" readonly><?php echo $esc($mm); ?></textarea>
</div>

<script src="modules/loneworker/assets/vendor/mermaid.min.js"></script>
<script>
(function () {
	try {
		if (typeof mermaid === 'undefined') { document.getElementById('lw-mm-fallback').style.display = 'block'; return; }
		mermaid.initialize({ startOnLoad: false, securityLevel: 'loose', flowchart: { useMaxWidth: true } });
		mermaid.run({ querySelector: '.mermaid' }).catch(function () { document.getElementById('lw-mm-fallback').style.display = 'block'; });
	} catch (e) { document.getElementById('lw-mm-fallback').style.display = 'block'; }
})();
</script>
