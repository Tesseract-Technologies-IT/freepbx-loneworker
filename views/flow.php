<?php
if (!defined('FREEPBX_IS_AUTH')) { die('No direct script access allowed'); }
// Lone Worker — dynamic "Alarm flow" preview: where calls will go, from the current config.
$f = $flow;
function lwfbox($html, $color = '#f5f5f5', $border = '#ccc') {
	return '<div style="display:inline-block;background:' . $color . ';border:1px solid ' . $border
		. ';border-radius:6px;padding:8px 14px;margin:4px;text-align:center;max-width:520px">' . $html . '</div>';
}
function lwarrow($label = '') {
	$l = $label !== '' ? '<div style="font-size:11px;color:#888">' . $label . '</div>' : '';
	return '<div style="text-align:center;color:#bbb;line-height:1.1">' . $l . '<div style="font-size:20px">&darr;</div></div>';
}
$esc = fn($v) => htmlspecialchars((string) $v);
?>
<div id="toolbar-flow" style="margin-bottom:12px">
	<a href="config.php?display=loneworker" class="btn btn-default"><i class="fa fa-dashboard"></i> <?php echo _('Dashboard') ?></a>
	<a href="config.php?display=loneworker&amp;view=sessions" class="btn btn-default"><i class="fa fa-list"></i> <?php echo _('Active sessions') ?></a>
	<a href="config.php?display=loneworker&amp;view=history" class="btn btn-default"><i class="fa fa-folder-open"></i> <?php echo _('Session log') ?></a>
	<a href="config.php?display=loneworker&amp;view=flow" class="btn btn-primary"><i class="fa fa-sitemap"></i> <?php echo _('Alarm flow') ?></a>
	<a href="config.php?display=loneworker&amp;view=settings" class="btn btn-default"><i class="fa fa-cog"></i> <?php echo _('Settings') ?></a>
</div>

<div class="alert alert-info"><i class="fa fa-sitemap"></i> <?php echo _('What happens on an alarm, based on the current configuration — so you can see in advance where the calls will go. Update it by changing the Settings.') ?></div>

<div style="text-align:center">
<?php
// 1) arm
echo lwfbox('<strong>' . _('Operator arms') . '</strong><br><span class="text-muted">' . sprintf(_('dials %s from their extension'), '<code>' . $esc($f['arm']) . '</code>') . '</span>', '#dff0d8', '#3c763d');
echo lwarrow(sprintf(_('no check-in (%s) within %d min'), '<code>' . $esc($f['checkin']) . '</code>', $f['timeout_min']));
// 2) alarm
echo lwfbox('<strong style="color:#a94442">' . _('ALARM') . '</strong>', '#f2dede', '#a94442');
echo lwarrow();
// 3) speakers
if ($f['paging'] !== '') {
	echo lwfbox('<i class="fa fa-volume-up"></i> ' . sprintf(_('Announcement on the speakers (paging group %s)'), '<code>' . $esc($f['paging']) . '</code>'), '#d9edf7', '#31708f');
} else {
	echo lwfbox('<i class="fa fa-exclamation-triangle"></i> ' . _('No paging group set — no speaker announcement'), '#fcf8e3', '#8a6d3b');
}
echo lwarrow();
// 4) responder cascade
$mode = $f['mode'] === 'sequence'
	? sprintf(_('Responders called ONE AT A TIME, in order (ring %ds each)'), $f['ring_time'])
	: sprintf(_('ALL responders called at the same time (ring %ds)'), $f['ring_time']);
echo lwfbox('<i class="fa fa-phone"></i> <strong>' . $mode . '</strong>', '#fcf8e3', '#8a6d3b');
echo lwarrow();
// responders
if (empty($f['responders'])) {
	echo lwfbox('<i class="fa fa-exclamation-triangle"></i> <strong>' . _('No responders configured — nobody is called!') . '</strong>', '#f2dede', '#a94442');
} else {
	echo '<div style="display:flex;flex-wrap:wrap;justify-content:center;align-items:stretch">';
	foreach ($f['responders'] as $i => $r) {
		if ($r['type'] === 'internal') {
			$body = '<strong>' . $esc($r['num']) . '</strong>' . ($r['name'] !== '' ? '<br><span class="text-muted">' . $esc($r['name']) . '</span>' : '')
				. '<br><span class="label label-success">' . _('internal') . '</span>';
			$col = '#dff0d8'; $bd = '#3c763d';
		} else {
			if (!$r['matched']) { $line = '<span class="label label-danger">' . _('no outbound route!') . '</span>'; $col = '#f2dede'; $bd = '#a94442'; }
			elseif ($r['cid_blocked']) { $line = '<span class="label label-warning">' . _('route filtered by Caller ID') . '</span>'; $col = '#fcf8e3'; $bd = '#8a6d3b'; }
			elseif (!$r['trunk_ok']) { $line = '<span class="label label-danger">' . sprintf(_('route "%s" — trunk disabled'), $esc($r['route'])) . '</span>'; $col = '#f2dede'; $bd = '#a94442'; }
			else { $line = '<span class="label label-info">' . sprintf(_('via route "%s"'), $esc($r['route'])) . '</span>'; $col = '#d9edf7'; $bd = '#31708f'; }
			$body = '<strong>' . $esc($r['num']) . '</strong><br><span class="text-muted">' . _('external') . '</span><br>' . $line;
		}
		$seq = $f['mode'] === 'sequence' ? ('<div style="font-size:11px;color:#888">' . sprintf(_('step %d'), $i + 1) . '</div>') : '';
		echo lwfbox($seq . $body, $col, $bd);
	}
	echo '</div>';
	if ($f['has_external']) {
		$cid = $f['cid'] !== '' ? ('<code>' . $esc($f['cid']) . '</code>') : ('<span class="text-warning">' . _('the down worker\'s extension (may be rejected)') . '</span>');
		echo '<div style="font-size:12px;color:#666;margin:6px">' . sprintf(_('External calls present Caller ID: %s'), $cid) . '</div>';
	}
}
echo lwarrow();
// 5) take charge
echo lwfbox(sprintf(_('First to press %s (within %ds) takes charge → all other calls stop'),
	'<code>' . $esc($f['confirm_key']) . '</code>', $f['confirm_timeout']), '#dff0d8', '#3c763d');
echo lwarrow();
// 6) post-confirm
$post = $f['confirm_action'] === 'hold' ? _('Session kept as "taken charge" until an operator disarms') : _('Session closed (incident over)');
if ($f['confirm_announce']) { $post .= ' · ' . _('"taken charge" announced on the speakers'); }
echo lwfbox($post, '#f5f5f5', '#ccc');
?>
</div>

<div class="alert alert-warning" style="margin-top:14px"><i class="fa fa-repeat"></i>
	<?php echo sprintf(_('If nobody takes charge, the whole alarm (announcement + calls) repeats every %d seconds until someone presses %s or an operator disarms (%s). Multiple operators can be in alarm at once, each escalating independently.'),
		$f['repeat'], '<code>' . $esc($f['confirm_key']) . '</code>', '<code>' . $esc($f['disarm']) . '</code>'); ?>
</div>

<?php
// Mermaid source for export (mermaid.live, docs)
$mm = "flowchart TD\n";
$mm .= "  A[\"Operator arms (" . $f['arm'] . ")\"] -->|no check-in " . $f['timeout_min'] . "m| B((ALARM))\n";
$mm .= "  B --> P[\"Speakers: paging " . ($f['paging'] !== '' ? $f['paging'] : 'NOT SET') . "\"]\n";
$mm .= "  B --> C{\"" . ($f['mode'] === 'sequence' ? 'Call in sequence' : 'Call all at once') . "\"}\n";
if (empty($f['responders'])) {
	$mm .= "  C --> X[\"NO RESPONDERS\"]\n";
} else {
	foreach ($f['responders'] as $i => $r) {
		$lbl = $r['num'] . ($r['type'] === 'internal' ? (' ' . str_replace(['"', "\n"], '', (string) $r['name'])) : (' ext' . (!empty($r['route']) ? ' via ' . str_replace('"', '', $r['route']) : '')));
		$mm .= "  C --> R{$i}[\"" . trim($lbl) . "\"]\n";
		$mm .= "  R{$i} --> K\n";
	}
}
$mm .= "  K[\"First to press " . $f['confirm_key'] . " takes charge\"] --> Z[\"" . ($f['confirm_action'] === 'hold' ? 'Kept as taken-charge' : 'Session closed') . "\"]\n";
$mm .= "  K -.->|nobody: repeat every " . $f['repeat'] . "s| B\n";
?>
<div style="margin-top:10px">
	<button type="button" class="btn btn-default btn-sm" onclick="var t=document.getElementById('lw-mm');t.style.display=t.style.display==='none'?'block':'none';"><i class="fa fa-code"></i> <?php echo _('Diagram source (Mermaid) — for export') ?></button>
	<textarea id="lw-mm" class="form-control" rows="10" style="display:none;margin-top:8px;font-family:monospace;font-size:12px" readonly><?php echo htmlspecialchars($mm); ?></textarea>
</div>
