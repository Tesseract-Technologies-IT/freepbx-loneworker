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

	// --- Dynamic, self-explaining announcements on the speakers --------------
	// Each message is a sequence of fragments interleaved with spoken numbers, so it explains
	// exactly what happens next using the REAL configured values (minutes, codes), spoken aloud.
	// The config-derived numbers are baked here at reload, so changing the timers and reloading
	// updates the audio automatically; the extension and the per-occurrence value (e.g. minutes
	// left until the deadline) come from the channel at play time.
	$ac = 'app-loneworker-announce';
	$checkin = $actions['checkin'] ?: '';                                   // check-in code (SayDigits, baked)
	$lang  = trim((string) ($s['digit_language'] ?? 'it')) ?: 'it';
	$raMin = max(1, (int) round((int) $s['reminder_after'] / 60));          // first reminder after N min
	$toMin = max(1, (int) round((int) $s['timeout'] / 60));                 // alarm after N min
	$riMin = max(1, (int) round((int) $s['reminder_interval'] / 60));       // reminder repeats every N min
	$ckey  = preg_match('/^[0-9*]$/', (string) ($s['confirm_key'] ?? '1')) ? (string) $s['confirm_key'] : '1';
	// token kinds: ['p',base] Playback fragment | ['ext'] SayDigits ${LW_EXT} | ['num'] SayNumber ${LW_NUM}
	//              ['n',int] SayNumber baked config | ['d',digits] SayDigits baked config
	$scripts = [
		'arm'      => [['p','arm-1'],['ext'],['p','arm-2'],['n',$raMin],['p','arm-3'],['d',$checkin],['p','arm-4'],['n',$toMin],['p','arm-5']],
		'reminder' => [['p','rem-1'],['ext'],['p','rem-2'],['num'],['p','rem-3'],['d',$checkin],['p','rem-4'],['n',$riMin],['p','rem-5']],
		'alarm'    => [['p','alarm-1'],['ext'],['p','alarm-2']],
		'ack'      => [['p','ack-1'],['ext'],['p','ack-2']],
		'confirm'  => [['p','conf-1'],['ext'],['p','conf-2'],['n',$raMin],['p','conf-3'],['n',$toMin],['p','conf-4']],
		'disarm'   => [['p','dis-1'],['ext'],['p','dis-2']],
		'call'     => [['p','call-1'],['ext'],['p','call-2'],['d',$ckey]],  // preview of the responder prompt
	];
	$emit = function ($exten, $tokens) use (&$ext, $ac) {
		foreach ($tokens as $t) {
			switch ($t[0]) {
				case 'p':   $c = loneworker_default($t[1]); if ($c !== '') { $ext->add($ac, $exten, '', new ext_playback($c)); } break;
				case 'ext': $ext->add($ac, $exten, '', new ext_saydigits('${LW_EXT}')); break;
				case 'num': $ext->add($ac, $exten, '', new ext_execif('$["${LW_NUM}" != ""]', 'SayNumber', '${LW_NUM},m')); break;
				case 'n':   if ((int) $t[1] > 0) { $ext->add($ac, $exten, '', new ext_saynumber((string) (int) $t[1], 'm')); } break;
				case 'd':   if ((string) $t[1] !== '') { $ext->add($ac, $exten, '', new ext_saydigits((string) $t[1])); } break;
			}
		}
	};
	foreach ($scripts as $type => $tokens) {
		// shared routine (assumes channel already answered + language set); plays the message; returns
		$emit('ann-' . $type, $tokens);
		$ext->add($ac, 'ann-' . $type, '', new ext_return(''));
		// GUI preview entry (Originate Exten=<type>, CallerID=<ext>): answer, set vars, play, hang up
		$ext->add($ac, $type, '1', new ext_answer(''));
		$ext->add($ac, $type, '', new ext_setvar('CHANNEL(language)', $lang));
		$ext->add($ac, $type, '', new ext_setvar('LW_EXT', '${CALLERID(num)}'));
		$ext->add($ac, $type, '', new ext_setvar('LW_NUM', (string) max(1, $toMin - $raMin))); // sample "minutes left"
		$ext->add($ac, $type, '', new ext_wait('1'));
		$ext->add($ac, $type, '', new ext_gosub('1', 'ann-' . $type, $ac));
		$ext->add($ac, $type, '', new ext_hangup(''));
	}

	// 'drain' = one paging channel that plays the WHOLE queue in sequence (keeps the page up
	// between messages so back-to-back announcements don't collide). Each loop the AGI 'nextann'
	// pops the next item and exposes LW_MSG/LW_EXT/LW_NUM/LW_LANG; empty LW_MSG => queue drained.
	$dr = 'drain';
	$ext->add($ac, $dr, '1', new ext_answer(''));
	$ext->add($ac, $dr, '', new ext_wait('1'));
	$ext->add($ac, $dr, 'loop', new ext_agi($agi . ',nextann'));
	$ext->add($ac, $dr, '', new ext_gotoif('$["${LW_MSG}" = ""]', 'done'));
	$ext->add($ac, $dr, '', new ext_setvar('CHANNEL(language)', '${LW_LANG}'));
	$ext->add($ac, $dr, '', new ext_gosub('1', 'ann-${LW_MSG}', $ac));
	$ext->add($ac, $dr, '', new ext_goto('loop'));
	$ext->add($ac, $dr, 'done', new ext_hangup(''));

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
	// __LWEXT (double underscore) is inherited by the dialled responder legs, so the confirm
	// subroutine can read ${LWEXT} directly (passing it as a Gosub arg via U() is unreliable).
	$ext->add($em, 'start', '', new ext_setvar('__LWEXT', '${CALLERID(num)}'));
	// outbound identity (outbound CID + matching CID-filtered routes)
	if ($outcid !== '') {
		$ext->add($em, 'start', '', new ext_setvar('CALLERID(all)', 'Lone Worker <' . $outcid . '>'));
	} elseif ($callerExt !== '') {
		$ext->add($em, 'start', '', new ext_setvar('CALLERID(num)', $callerExt));
		$ext->add($em, 'start', '', new ext_setvar('CALLERID(name)', 'Lone Worker'));
	}
	$uopt = 'U(app-loneworker-confirm^s^1)'; // LWEXT is inherited (__LWEXT), no Gosub arg needed
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
	$cpre  = loneworker_default('call-1');     // "Lone worker alarm. The operator of extension"
	$cpost = loneworker_default('call-2');     // "did not confirm ... to take charge, press the key"
	$ckey  = preg_match('/^[0-9*]$/', (string) ($s['confirm_key'] ?? '1')) ? (string) $s['confirm_key'] : '1'; // key to take charge
	$ctmo  = max(3, (int) ($s['confirm_timeout'] ?? 15)); // seconds to wait for the key on each prompt
	$crep  = max(1, min(10, (int) ($s['confirm_repeat'] ?? 3))); // times the prompt repeats if the key is not pressed
	// Responder-specific take-charge confirmation (deliberately DIFFERENT wording from the speakers):
	// "you have taken charge of the alarm of extension N. Reach them now ...".
	$rcpre  = loneworker_default('taken-1');
	$rcpost = loneworker_default('taken-2');
	$keyfile = ctype_digit($ckey) ? 'digits/' . $ckey : ''; // spoken key as an interruptible digit file
	$ext->add($cf, 's', '', new ext_answer(''));
	$ext->add($cf, 's', '', new ext_setvar('CHANNEL(language)', $lang));
	// the responder we reached = the dialled number, parsed from the channel name (Local/<num>@from-internal-...)
	$ext->add($cf, 's', '', new ext_setvar('LWRESP', '${CUT(CUT(CHANNEL,@,1),/,2)}'));
	$ext->add($cf, 's', '', new ext_agi($agi . ',answered,${LWEXT},${LWRESP}')); // call log: who answered
	// Render the down-worker extension as individual digit sound files (digits/0..9) so the WHOLE
	// prompt can be played as a single, interruptible Read — the responder can press the key at ANY
	// moment while it is still speaking, not only during the silent wait. SayDigits cannot be
	// interrupted, so it is not used in the prompt anymore. NF becomes e.g. "&digits/1&digits/0".
	$ext->add($cf, 's', '', new ext_setvar('NF', ''));
	for ($i = 0; $i < 6; $i++) {
		$ext->add($cf, 's', '', new ext_execif('$[${LEN(${LWEXT})} > ' . $i . ']', 'Set', 'NF=${NF}&digits/${LWEXT:' . $i . ':1}'));
	}
	// Build the &-joined, interruptible prompt: pre sentence + extension digits + post sentence + key digit.
	$ext->add($cf, 's', '', new ext_setvar('LWPROMPT', ($cpre !== '' ? $cpre : '') . '${NF}'));
	if ($cpost !== '')   { $ext->add($cf, 's', '', new ext_setvar('LWPROMPT', '${LWPROMPT}&' . $cpost)); }
	if ($keyfile !== '') { $ext->add($cf, 's', '', new ext_setvar('LWPROMPT', '${LWPROMPT}&' . $keyfile)); }
	$ext->add($cf, 's', '', new ext_setvar('LWTRY', '0'));
	$ext->add($cf, 's', '', new ext_wait('1'));
	$ext->add($cf, 's', 'loop', new ext_setvar('LWTRY', '$[${LWTRY} + 1]'));
	// Interruptible prompt: a DTMF pressed at ANY point during playback is captured immediately
	// (Read stops the prompt on the first digit), then we wait up to confirm_timeout for it.
	$ext->add($cf, 's', '', new ext_read('LWKEY', '${LWPROMPT}', '1', '', '1', (string) $ctmo));
	$ext->add($cf, 's', '', new ext_gotoif('$["${LWKEY}" = "' . $ckey . '"]', 's,take'));
	// not confirmed yet: repeat the whole prompt up to confirm_repeat times, then hang up
	$ext->add($cf, 's', '', new ext_gotoif('$[${LWTRY} < ' . $crep . ']', 's,loop'));
	$ext->add($cf, 's', '', new ext_hangup(''));
	// confirmed: acknowledge, then play the RESPONDER-specific confirmation (distinct from the speakers)
	$ext->add($cf, 's', 'take', new ext_agi($agi . ',ack,${LWEXT},${LWRESP}'));
	$ext->add($cf, 's', '', new ext_playback('beep'));
	if ($rcpre !== '') { $ext->add($cf, 's', '', new ext_playback($rcpre)); } // "you have taken charge of the alarm of extension"
	$ext->add($cf, 's', '', new ext_saydigits('${LWEXT}'));                    // ... N ...
	if ($rcpost !== '') { $ext->add($cf, 's', '', new ext_playback($rcpost)); } // "... good response/intervention."
	$ext->add($cf, 's', '', new ext_wait('1'));
	// Hang up after the confirmation instead of Return(): a Return would bridge the responder back
	// to the orchestration channel (which is parked in Wait), leaving the call up until they hang up
	// manually. The alarm is already acknowledged, so end the call cleanly.
	$ext->add($cf, 's', '', new ext_hangup(''));
}
