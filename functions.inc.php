<?php
// Lone Worker — dialplan generation.
if (!defined('FREEPBX_IS_AUTH')) { /* may also be included during reload */ }

/** Asterisk sounds directory (where the built-in announcement clips are installed). */
function loneworker_sounds_dir() {
	try { $base = \FreePBX::Config()->get('ASTVARLIBDIR'); } catch (\Throwable $e) { $base = ''; }
	return rtrim($base ?: '/var/lib/asterisk', '/') . '/sounds';
}

/** Playback path of the built-in clip for an announcement segment (e.g. 'armed-pre'),
 *  or '' if it is not installed. The audio set is fixed and ships with the module. */
function loneworker_default($seg) {
	$rel = 'loneworker/lw-' . $seg;
	$dir = loneworker_sounds_dir();
	foreach (['wav', 'ulaw', 'sln', 'gsm'] as $ext) {
		if (is_file($dir . '/' . $rel . '.' . $ext)) { return $rel; }
	}
	return '';
}

/** Add an announcement block (pre + SayDigits of the extension + post) to an extension.
 *  The extension travels in the originated channel's CallerID(num) (robust on Local channels).
 *  If $sayNum is set, that number (e.g. the check-in feature code) is also spoken with SayDigits
 *  after the "post": so changing the code updates the audio automatically. */
function loneworker_add_ann(&$ext, $ctx, $exten, $pre, $post, $sayNum = '', $lang = '', $agi = '') {
	$ext->add($ctx, $exten, '', new ext_answer(''));
	if ($lang !== '') { $ext->add($ctx, $exten, '', new ext_setvar('CHANNEL(language)', $lang)); }
	$ext->add($ctx, $exten, '', new ext_wait('1'));
	if ($pre !== '')  { $ext->add($ctx, $exten, '', new ext_playback($pre)); }
	$ext->add($ctx, $exten, '', new ext_saydigits('${CALLERID(num)}'));
	if ($post !== '') { $ext->add($ctx, $exten, '', new ext_playback($post)); }
	if ($sayNum !== '') { $ext->add($ctx, $exten, '', new ext_saydigits($sayNum)); }
	// When this announcement finishes, free the speakers and play the next queued one (back-to-back).
	if ($agi !== '') { $ext->add($ctx, $exten, '', new ext_agi($agi . ',drain')); }
	$ext->add($ctx, $exten, '', new ext_hangup(''));
}

function loneworker_get_config($engine) {
	global $ext;
	if ($engine !== 'asterisk') { return; }

	$lw  = \FreePBX::Loneworker();
	$s   = $lw->getSettings();
	// called by basename: FreePBX's FastAGI server resolves it in /var/lib/asterisk/agi-bin
	$agi = 'loneworker.agi.php';

	// --- Feature codes 701/702/703 -> AGI ------------------------------------
	$ctx = 'app-loneworker';
	$actions = [
		'arm'     => (new featurecode('loneworker', 'arm'))->getCodeActive(),
		'checkin' => (new featurecode('loneworker', 'checkin'))->getCodeActive(),
		'disarm'  => (new featurecode('loneworker', 'disarm'))->getCodeActive(),
	];
	foreach ($actions as $action => $code) {
		if (!$code) { continue; }
		$ext->add($ctx, $code, '', new ext_answer(''));
		$ext->add($ctx, $code, '', new ext_wait('1'));
		$ext->add($ctx, $code, '', new ext_agi($agi . ',' . $action . ',${CALLERID(num)}'));
		$ext->add($ctx, $code, '', new ext_gotoif('$["${LW_RESULT}" = "ok"]', 'fbok,1', 'fberr,1'));
	}
	// audible feedback on the cordless
	$ext->add($ctx, 'fbok', '1', new ext_playback('beep'));
	$ext->add($ctx, 'fbok', '', new ext_hangup(''));
	$ext->add($ctx, 'fberr', '1', new ext_playback('pbx-invalid'));
	$ext->add($ctx, 'fberr', '', new ext_hangup(''));
	// reachable when the feature code is dialled (ext-featurecodes -> from-internal,<code>,1)
	$ext->addInclude('from-internal-additional', $ctx);

	// --- Dynamic announcement context on the speakers ------------------------
	// The Originate sets Exten=<message type> and CallerID=<extension>; each exten here
	// plays pre + extension number (from CALLERID) + post. No channel variables.
	$ac = 'app-loneworker-announce';
	$checkin = $actions['checkin']; // check-in number, spoken dynamically
	$lang = trim((string) ($s['digit_language'] ?? 'it')) ?: 'it';
	// Audio is the built-in, fixed set shipped with the module (not user-editable).
	loneworker_add_ann($ext, $ac, 'arm',      loneworker_default('armed-pre'),     loneworker_default('armed-post'),     $checkin, $lang, $agi);
	loneworker_add_ann($ext, $ac, 'confirm',  loneworker_default('confirmed-pre'), loneworker_default('confirmed-post'), '',       $lang, $agi);
	loneworker_add_ann($ext, $ac, 'reminder', loneworker_default('reminder-pre'),  loneworker_default('reminder-post'),  $checkin, $lang, $agi);
	loneworker_add_ann($ext, $ac, 'alarm',    loneworker_default('alarm-pre'),     loneworker_default('alarm-post'),     '',       $lang, $agi);
	loneworker_add_ann($ext, $ac, 'ack',      loneworker_default('ack-pre'),       loneworker_default('ack-post'),       '',       $lang, $agi);
	loneworker_add_ann($ext, $ac, 'disarm',   loneworker_default('disarmed-pre'),  '',                                   '',       $lang, $agi);

	// --- Emergency cascade: ring ALL responders simultaneously --------------
	// One channel Dials every responder at once. The first one to press 1 in the
	// confirmation routine "wins": Dial bridges it and automatically drops all the
	// other ringing/answered calls. Members + ring time arrive as channel vars.
	// Members are baked in at reload time (any Ring Group change triggers a reload).
	// The alarmed extension travels in CALLERID(num) and is passed to the confirm routine.
	// Two modes (setting alarm_mode): SIMULTANEOUS = one Dial to all responders together;
	// SEQUENCE = call them one at a time, in list order, stopping as soon as one takes charge.
	// In both modes pressing 1 runs the ACK; the alarmed ext travels in LWEXT for the announcement.
	$em = 'app-loneworker-emer';
	$members  = $lw->alarmMembers();
	$ringtime = (int) ($s['ring_time'] ?? 45);
	$outcid   = trim((string) ($s['alarm_outbound_cid'] ?? ''));
	$callerExt = trim((string) ($s['alarm_caller_ext'] ?? ''));
	$sequence = (($s['alarm_mode'] ?? 'simultaneous') === 'sequence');
	$ext->add($em, 'start', '', new ext_noop('LW emergency ext=${CALLERID(num)}'));
	$ext->add($em, 'start', '', new ext_setvar('LWEXT', '${CALLERID(num)}'));
	// outbound identity (outbound CID + matching CID-filtered routes)
	if ($outcid !== '') {
		$ext->add($em, 'start', '', new ext_setvar('CALLERID(all)', 'Lone Worker <' . $outcid . '>'));
	} elseif ($callerExt !== '') {
		$ext->add($em, 'start', '', new ext_setvar('CALLERID(num)', $callerExt));
		$ext->add($em, 'start', '', new ext_setvar('CALLERID(name)', 'Lone Worker'));
	}
	$uopt = 'U(app-loneworker-confirm^s^1(${LWEXT}))';
	if (empty($members)) {
		$ext->add($em, 'start', '', new ext_noop('LW: no responders configured'));
	} elseif ($sequence) {                    // SEQUENCE: one at a time, in order
		foreach ($members as $m) {
			$ext->add($em, 'start', '', new ext_dial('Local/' . $m . '@from-internal', $ringtime . ',' . $uopt));
			$ext->add($em, 'start', '', new ext_agi($agi . ',isactive,${LWEXT}'));
			$ext->add($em, 'start', '', new ext_gotoif('$["${LW_ACTIVE}" = "0"]', 'done'));
		}
	} else {                                   // SIMULTANEOUS: all together
		$dialstr = implode('&', array_map(fn($m) => 'Local/' . $m . '@from-internal', $members));
		$ext->add($em, 'start', '', new ext_dial($dialstr, $ringtime . ',' . $uopt));
	}
	$ext->add($em, 'start', 'done', new ext_hangup(''));

	// Confirmation routine on each ANSWERED responder: up to 3 prompts; press 1 = take charge
	// (AGI ack + Return → wins the Dial). If no confirm, hang up so the cascade moves on.
	$cf    = 'app-loneworker-confirm';
	$cpre  = loneworker_default('call-pre');
	$cpost = loneworker_default('call-post');
	$ckey  = preg_match('/^[0-9*]$/', (string) ($s['confirm_key'] ?? '1')) ? (string) $s['confirm_key'] : '1'; // key to take charge
	$ctmo  = max(3, (int) ($s['confirm_timeout'] ?? 15)); // seconds to wait for the key on each prompt
	$ext->add($cf, 's', '', new ext_answer(''));
	$ext->add($cf, 's', '', new ext_setvar('CHANNEL(language)', $lang));
	$ext->add($cf, 's', '', new ext_setvar('LWTRY', '0'));
	$ext->add($cf, 's', '', new ext_wait('1'));
	$ext->add($cf, 's', 'loop', new ext_setvar('LWTRY', '$[${LWTRY} + 1]'));
	if ($cpre !== '') { $ext->add($cf, 's', '', new ext_playback($cpre)); }
	else              { $ext->add($cf, 's', '', new ext_noop('LW confirm ext=${ARG1}')); }
	$ext->add($cf, 's', '', new ext_saydigits('${ARG1}'));
	if ($cpost !== '') { $ext->add($cf, 's', '', new ext_playback($cpost)); }
	$ext->add($cf, 's', '', new ext_read('LWKEY', '', '1', '', '1', (string) $ctmo));
	$ext->add($cf, 's', '', new ext_gotoif('$["${LWKEY}" = "' . $ckey . '"]', 's,take'));
	$ext->add($cf, 's', '', new ext_gotoif('$[${LWTRY} < 3]', 's,loop'));
	$ext->add($cf, 's', '', new ext_hangup(''));
	$ext->add($cf, 's', 'take', new ext_agi($agi . ',ack,${ARG1}'));
	$ext->add($cf, 's', '', new ext_playback('beep'));
	$ext->add($cf, 's', '', new ext_return(''));
}
