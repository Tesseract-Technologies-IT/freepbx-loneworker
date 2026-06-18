<?php
// Lone Worker (loneworker) — main BMO class.
// Business logic (arm/checkin/disarm/ack/tick) shared by the AGI (feature codes) and the Job (tick).
namespace FreePBX\modules;

#[\AllowDynamicProperties]
class Loneworker extends \FreePBX_Helpers implements \BMO {

	private $freepbx;
	private $db;

	/** Default settings (stored in kvstore). */
	private $defaults = [
		'timeout'                => 1800,   // seconds before the alarm
		'reminder_after'         => 900,    // first reminder after arm/checkin
		'reminder_interval'      => 300,    // interval between reminders
		'paging_group'           => '',     // paging group number (speakers)
		'ring_group'             => '',     // (deprecated) ring group: kept only as fallback
		'alarm_numbers'          => '',     // single ORDERED list (internal+external), one per line
		'alarm_mode'             => 'simultaneous', // simultaneous | sequence
		'alarm_internal_exts'    => '',     // (deprecated) back-compat fallback
		'alarm_external_numbers' => '',     // (deprecated) back-compat fallback
		'alarm_caller_ext'       => '',     // service extension for the outbound identity (optional)
		'alarm_outbound_cid'     => '',     // explicit outbound CallerID (optional, takes priority)
		'authorized_extensions'  => '',     // CSV/space; empty = everyone
		'max_sessions'           => 10,
		'retention_days'         => 365,
		'ring_time'              => 45,     // ring seconds of the alarm cascade
		'alarm_repeat'           => 120,    // how often to repeat the alarm until taken charge of
		'confirm_key'            => '1',    // DTMF key the responder presses to take charge (0-9 or *)
		'confirm_timeout'        => 15,     // seconds to wait for the key on each prompt
		'confirm_action'         => 'disarm', // after a responder confirms: 'disarm' (close) | 'hold' (keep as acknowledged until manual disarm)
		'confirm_announce'       => 1,      // play the "taken charge" announcement on the speakers after confirmation
		'digit_language'         => 'it',   // language SayDigits uses to speak the extension and numbers
		// recording id (System Recordings) for each announcement
		'rec_armed_pre'       => '', 'rec_armed_post'       => '',
		'rec_confirmed_pre'     => '', 'rec_confirmed_post'     => '',
		'rec_reminder_pre'     => '', 'rec_reminder_post'     => '',
		'rec_alarm_pre' => '', 'rec_alarm_post' => '',
		'rec_ack_pre'      => '', 'rec_ack_post'      => '',
		'rec_disarmed_pre'    => '',
		'rec_call_prompt_pre'  => '', 'rec_call_prompt_post' => '',
		'rec_call_pre'         => '', 'rec_call_post'        => '',
	];

	public function __construct($freepbx = null) {
		parent::__construct($freepbx);
		$this->freepbx = $freepbx;
		$this->db = $freepbx->Database;
	}

	// ---------------------------------------------------------------- BMO

	public function install() {
		// Tables are created by Doctrine (module.xml). Here: feature codes + Job.
		foreach (['arm' => '701', 'checkin' => '702', 'disarm' => '703'] as $name => $code) {
			$fcc = new \featurecode('loneworker', $name);
			if ($fcc->getCode() == '' && $fcc->getDefault() == '') {
				$labels = ['arm' => _('Lone Worker: Arm'), 'checkin' => _('Lone Worker: Check-in'), 'disarm' => _('Lone Worker: Disarm')];
				$fcc->setDescription($labels[$name] ?? ('Lone Worker: ' . $name));
				$fcc->setDefault($code);
				$fcc->update();
			}
			unset($fcc);
		}
		// install the AGI script into agi-bin (resolved by FreePBX's FastAGI server)
		$this->installAgi();
		// run the Job every minute
		$this->freepbx->Job->addClass('loneworker', 'tick', \FreePBX\modules\Loneworker\Job::class, '* * * * *');
	}

	public function uninstall() {
		foreach (['arm', 'checkin', 'disarm'] as $name) {
			$fcc = new \featurecode('loneworker', $name);
			$fcc->delete();
			unset($fcc);
		}
		try { $this->freepbx->Job->remove('loneworker', 'tick'); } catch (\Throwable $e) {}
		@unlink('/var/lib/asterisk/agi-bin/loneworker.agi.php');
		try { $this->db->query('DROP TABLE IF EXISTS loneworker_sessions'); } catch (\Throwable $e) {}
		try { $this->db->query('DROP TABLE IF EXISTS loneworker_events'); } catch (\Throwable $e) {}
	}

	/** Copy the AGI script into /var/lib/asterisk/agi-bin and make it executable. */
	private function installAgi() {
		$src = __DIR__ . '/agi/loneworker.agi.php';
		$dst = '/var/lib/asterisk/agi-bin/loneworker.agi.php';
		if (file_exists($src)) {
			@copy($src, $dst);
			@chmod($dst, 0755);
		}
	}

	public function backup($backup) {}
	public function restore($backup) {}
	public function doTests($db) { return true; }

	// ----------------------------------------------------------- Settings

	/** Map of legacy (pre-1.7) recording keys to the current English keys, for back-compat. */
	private $legacyRecKeys = [
		'rec_attivato_pre' => 'rec_armed_pre', 'rec_attivato_post' => 'rec_armed_post',
		'rec_confermato_pre' => 'rec_confirmed_pre', 'rec_confermato_post' => 'rec_confirmed_post',
		'rec_promemoria_pre' => 'rec_reminder_pre', 'rec_promemoria_post' => 'rec_reminder_post',
		'rec_allarme_paging_pre' => 'rec_alarm_pre', 'rec_allarme_paging_post' => 'rec_alarm_post',
		'rec_acquisito_pre' => 'rec_ack_pre', 'rec_acquisito_post' => 'rec_ack_post',
		'rec_disattivato_pre' => 'rec_disarmed_pre',
		'rec_allarme_chiamata_remote_pre' => 'rec_call_prompt_pre', 'rec_allarme_chiamata_remote_post' => 'rec_call_prompt_post',
		'rec_allarme_chiamata_pre' => 'rec_call_pre', 'rec_allarme_chiamata_post' => 'rec_call_post',
	];

	public function getSettings() {
		$saved = $this->getConfig('settings');
		$saved = is_array($saved) ? $saved : [];
		$merged = array_merge($this->defaults, $saved);
		// migrate legacy Italian recording keys to the new English keys
		foreach ($this->legacyRecKeys as $old => $new) {
			if (empty($merged[$new]) && !empty($saved[$old])) { $merged[$new] = $saved[$old]; }
		}
		return $merged;
	}

	public function saveSettings($values) {
		$clean = [];
		foreach ($this->defaults as $k => $def) {
			$v = $values[$k] ?? $def;
			if (is_array($v)) { $v = implode(',', $v); } // multiselect -> CSV
			$clean[$k] = $v;
		}
		// validate responder-confirmation settings
		$clean['confirm_key'] = preg_match('/^[0-9*]$/', (string) $clean['confirm_key']) ? (string) $clean['confirm_key'] : '1';
		$clean['confirm_timeout'] = max(3, min(120, (int) $clean['confirm_timeout']));
		$clean['confirm_action'] = in_array($clean['confirm_action'], ['disarm', 'hold'], true) ? $clean['confirm_action'] : 'disarm';
		$clean['confirm_announce'] = !empty($clean['confirm_announce']) ? 1 : 0;
		$this->setConfig('settings', $clean);
		return $clean;
	}

	private function isAuthorized($ext, $s) {
		$list = trim((string) $s['authorized_extensions']);
		if ($list === '') { return true; }
		$allowed = preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY);
		return in_array((string) $ext, $allowed, true);
	}

	// ----------------------------------------------------- Feature codes

	/** Current active codes of the module's feature codes (arm/checkin/disarm). */
	public function getFeatureCodes() {
		$out = [];
		foreach (['arm', 'checkin', 'disarm'] as $n) {
			$out[$n] = (new \featurecode('loneworker', $n))->getCodeActive();
		}
		return $out;
	}

	/** Numbers already in use (extensions + other feature codes), code => owner description. */
	public function usedNumbers() {
		$map = [];
		try {
			foreach (\FreePBX::Core()->getAllUsers() as $u) {
				$map[(string) $u['extension']] = sprintf(_('extension %s (%s)'), $u['extension'], $u['name']);
			}
		} catch (\Throwable $e) {}
		try {
			$sth = $this->db->query("SELECT modulename, featurename, defaultcode, customcode FROM featurecodes WHERE modulename <> 'loneworker'");
			foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $r) {
				$code = ($r['customcode'] !== '' && $r['customcode'] !== null) ? $r['customcode'] : $r['defaultcode'];
				if ($code !== '' && $code !== null) {
					$map[(string) $code] = sprintf(_('feature code %s/%s'), $r['modulename'], $r['featurename']);
				}
			}
		} catch (\Throwable $e) {}
		return $map;
	}

	/** Conflicts of the chosen codes: returns [arm|checkin|disarm => reason]. */
	public function featureCodeConflicts($vals) {
		$used = $this->usedNumbers();
		$labels = ['arm' => _('Arm code'), 'checkin' => _('Check-in code'), 'disarm' => _('Disarm code')];
		$chosen = [];
		$conflicts = [];
		foreach (['arm', 'checkin', 'disarm'] as $n) {
			$code = preg_replace('/[^0-9*#]/', '', (string) ($vals['fc_' . $n] ?? ''));
			if ($code === '') { continue; }
			if (isset($chosen[$code])) {
				$conflicts[$n] = sprintf(_('%s is the same as the %s'), $code, $labels[$chosen[$code]]);
			} elseif (isset($used[$code])) {
				$conflicts[$n] = sprintf(_('%s is already used by %s'), $code, $used[$code]);
			}
			$chosen[$code] = $n;
		}
		return $conflicts;
	}

	/** Save the feature codes from the form. Conflicting codes are NOT applied and
	 *  raise a FreePBX notification. Returns the list of detected conflicts. */
	public function saveFeatureCodes($vals) {
		$conflicts = $this->featureCodeConflicts($vals);
		foreach (['arm', 'checkin', 'disarm'] as $n) {
			if (!isset($vals['fc_' . $n]) || isset($conflicts[$n])) { continue; }
			$code = preg_replace('/[^0-9*#]/', '', (string) $vals['fc_' . $n]);
			if ($code === '') { continue; }
			$fcc = new \featurecode('loneworker', $n);
			if ($fcc->getCodeActive() !== $code) {
				$fcc->setCode($code);
				$fcc->update();
			}
			unset($fcc);
		}
		// notify about conflicts (FreePBX notifications panel)
		try {
			$N = \FreePBX::Notifications();
			$N->delete('loneworker', 'fc_conflict');
			if (!empty($conflicts)) {
				$msg = [];
				foreach ($conflicts as $n => $why) { $msg[] = $why; }
				$N->add_warning('loneworker', 'fc_conflict',
					_('Lone Worker: some feature codes were not applied (conflict)'),
					implode("\n", $msg));
			}
		} catch (\Throwable $e) {}
		return $conflicts;
	}

	// speaker gate = timestamp until which the speakers are busy (avoids overlapping announcements)
	private function speakerBusy()    { return (int) $this->getConfig('speaker_until') > time(); }
	private function setSpeakerGate($secs = 12) { $this->setConfig('speaker_until', time() + (int) $secs); }

	// ----------------------------------------------------------- DB CRUD

	public function getSessions() {
		$sth = $this->db->prepare('SELECT * FROM loneworker_sessions ORDER BY start_ts ASC');
		$sth->execute();
		return $sth->fetchAll(\PDO::FETCH_ASSOC);
	}

	public function getSession($ext) {
		$sth = $this->db->prepare('SELECT * FROM loneworker_sessions WHERE ext = ?');
		$sth->execute([$ext]);
		$r = $sth->fetch(\PDO::FETCH_ASSOC);
		return $r ?: null;
	}

	private function deleteSession($ext) {
		$sth = $this->db->prepare('DELETE FROM loneworker_sessions WHERE ext = ?');
		return $sth->execute([$ext]);
	}

	public function logEvent($event, $ext = null, $payload = null) {
		$sth = $this->db->prepare('INSERT INTO loneworker_events (ts, event, ext, payload) VALUES (?,?,?,?)');
		$sth->execute([time(), $event, $ext, $payload !== null ? json_encode($payload) : null]);
	}

	public function getEvents($limit = 50) {
		$limit = (int) $limit;
		$sth = $this->db->prepare("SELECT * FROM loneworker_events ORDER BY id DESC LIMIT $limit");
		$sth->execute();
		return $sth->fetchAll(\PDO::FETCH_ASSOC);
	}

	// -------------------------------------------------- Operator actions

	/** Arm a session for extension $ext. Returns ['result'=>..., 'ext'=>...]. */
	public function arm($ext) {
		$ext = (string) $ext;
		$s = $this->getSettings();
		if ($ext === '') { $this->logEvent('ERROR', $ext, ['detail' => 'no-callerid']); return ['result' => 'err', 'ext' => $ext]; }
		if (!$this->isAuthorized($ext, $s)) { $this->logEvent('ERROR', $ext, ['detail' => 'not-authorized']); return ['result' => 'unauth', 'ext' => $ext]; }
		if ($this->getSession($ext)) { $this->logEvent('ERROR', $ext, ['detail' => 'already-armed']); return ['result' => 'dup', 'ext' => $ext]; }
		if (count($this->getSessions()) >= (int) $s['max_sessions']) { $this->logEvent('ERROR', $ext, ['detail' => 'max-sessions']); return ['result' => 'max', 'ext' => $ext]; }
		$now = time();
		$sth = $this->db->prepare('INSERT INTO loneworker_sessions (ext,state,start_ts,next_reminder_ts,deadline_ts,alarm_started_ts) VALUES (?,?,?,?,?,NULL)');
		$sth->execute([$ext, 'ARMED', $now, $now + (int) $s['reminder_after'], $now + (int) $s['timeout']]);
		$this->logEvent('ARM', $ext, ['deadline' => $now + (int) $s['timeout']]);
		$this->enqueueAnnounce('arm', $ext);
		return ['result' => 'ok', 'ext' => $ext];
	}

	/** Check-in (reset timer) for $ext. */
	public function checkin($ext) {
		$ext = (string) $ext;
		$s = $this->getSettings();
		$row = $this->getSession($ext);
		if (!$row || $row['state'] !== 'ARMED') { $this->logEvent('ERROR', $ext, ['detail' => 'not-active']); return ['result' => 'notactive', 'ext' => $ext]; }
		$now = time();
		$sth = $this->db->prepare('UPDATE loneworker_sessions SET start_ts=?, next_reminder_ts=?, deadline_ts=? WHERE ext=? AND state=\'ARMED\'');
		$sth->execute([$now, $now + (int) $s['reminder_after'], $now + (int) $s['timeout'], $ext]);
		$this->logEvent('CHECKIN', $ext, ['deadline' => $now + (int) $s['timeout']]);
		$this->enqueueAnnounce('confirm', $ext);
		return ['result' => 'ok', 'ext' => $ext];
	}

	/** Manually disarm the session of $ext. */
	public function disarm($ext) {
		$ext = (string) $ext;
		$s = $this->getSettings();
		$row = $this->getSession($ext);
		if (!$row) { $this->logEvent('ERROR', $ext, ['detail' => 'not-active']); return ['result' => 'notactive', 'ext' => $ext]; }
		$this->deleteSession($ext);
		$this->logEvent('DISARM', $ext, ['reason' => 'manual']);
		$this->enqueueAnnounce('disarm', $ext);
		return ['result' => 'ok', 'ext' => $ext];
	}

	/** Apply the configured post-confirmation behaviour to a taken-charge alarm.
	 *  confirm_action: 'disarm' closes the session (incident over); 'hold' keeps it as
	 *  ACKNOWLEDGED (no more calls/announcements) until an operator manually disarms.
	 *  confirm_announce: whether to play the "taken charge" announcement on the speakers. */
	private function finishAck($ext, $s) {
		$ext = (string) $ext;
		$this->logEvent('ACK', $ext, []);
		if (($s['confirm_action'] ?? 'disarm') === 'hold') {
			$u = $this->db->prepare("UPDATE loneworker_sessions SET state='ACKED', next_reminder_ts=0 WHERE ext=?");
			$u->execute([$ext]);
			$this->logEvent('ACK_HOLD', $ext, []); // kept as acknowledged until manual disarm
		} else {
			$this->deleteSession($ext);
			$this->logEvent('DISARM', $ext, ['reason' => 'alarm-acked']);
		}
		if (!empty($s['confirm_announce'])) { $this->enqueueAnnounce('ack', $ext); }
	}

	/** Take charge (#) of the oldest ALARMING alarm. */
	public function ackOldest() {
		$s = $this->getSettings();
		$sth = $this->db->prepare("SELECT * FROM loneworker_sessions WHERE state='ALARMING' ORDER BY alarm_started_ts ASC LIMIT 1");
		$sth->execute();
		$row = $sth->fetch(\PDO::FETCH_ASSOC);
		if (!$row) { $this->logEvent('ERROR', null, ['detail' => 'ack-no-session']); return ['result' => 'none', 'ext' => '']; }
		$ext = $row['ext'];
		$this->finishAck($ext, $s);
		return ['result' => 'ok', 'ext' => $ext];
	}

	/** Take charge of the alarm of a specific extension (responder pressed the confirm key). */
	public function ackByExt($ext) {
		$ext = (string) $ext;
		$s = $this->getSettings();
		$row = $this->getSession($ext);
		if (!$row || $row['state'] !== 'ALARMING') {
			// already taken charge of / no longer in alarm: idempotent no-op
			$this->logEvent('ERROR', $ext, ['detail' => 'ack-not-alarming']);
			return ['result' => 'none', 'ext' => $ext];
		}
		$this->finishAck($ext, $s);
		return ['result' => 'ok', 'ext' => $ext];
	}

	// --------------------------------------------------------------- Tick

	/** Periodic evaluation (every 60s) of reminders/alarms across all sessions. */
	public function tick($output = null) {
		$s = $this->getSettings();
		$now = time();
		$repeat = max(30, (int) $s['alarm_repeat']);

		// 1) deadline expired on ARMED sessions -> become ALARMING: announce + cascade,
		//    and schedule the next re-call (next_reminder_ts reused as the "next action time").
		$sth = $this->db->prepare("SELECT * FROM loneworker_sessions WHERE state='ARMED' AND deadline_ts <= ? ORDER BY deadline_ts ASC");
		$sth->execute([$now]);
		foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $r) {
			$ext = $r['ext'];
			$this->enqueueAnnounce('alarm', $ext); // queued: plays ASAP, after any older alarm announcement
			$this->originateAlarm($ext, $s);
			$u = $this->db->prepare("UPDATE loneworker_sessions SET state='ALARMING', alarm_started_ts=?, next_reminder_ts=? WHERE ext=?");
			$u->execute([$now, $now + $repeat, $ext]);
			$this->logEvent('ALARM', $ext, []);
			if ($output) { $output->writeln("ALARM $ext"); }
		}

		// 2) ALARMING sessions not yet taken charge of -> RE-CALL until acknowledged.
		$sth = $this->db->prepare("SELECT * FROM loneworker_sessions WHERE state='ALARMING' AND next_reminder_ts <= ? ORDER BY alarm_started_ts ASC");
		$sth->execute([$now]);
		foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $r) {
			$ext = $r['ext'];
			$this->enqueueAnnounce('alarm', $ext);
			$this->originateAlarm($ext, $s);
			$u = $this->db->prepare('UPDATE loneworker_sessions SET next_reminder_ts = next_reminder_ts + ? WHERE ext=?');
			$u->execute([$repeat, $ext]);
			$this->logEvent('ALARM_REPEAT', $ext, []);
			if ($output) { $output->writeln("ALARM_REPEAT $ext"); }
		}

		// 3) reminders due on ARMED sessions (queued; the queue serialises the speakers)
		$sth = $this->db->prepare("SELECT * FROM loneworker_sessions WHERE state='ARMED' AND next_reminder_ts <= ? AND deadline_ts > ? ORDER BY next_reminder_ts ASC");
		$sth->execute([$now, $now]);
		foreach ($sth->fetchAll(\PDO::FETCH_ASSOC) as $r) {
			$this->enqueueAnnounce('reminder', $r['ext']); // dedup avoids piling up duplicate reminders
			$u = $this->db->prepare('UPDATE loneworker_sessions SET next_reminder_ts = next_reminder_ts + ? WHERE ext=?');
			$u->execute([(int) $s['reminder_interval'], $r['ext']]);
			$this->logEvent('REMINDER', $r['ext'], []);
			if ($output) { $output->writeln('REMINDER ' . $r['ext']); }
		}

		// Safety net: if the AGI 'drain' hook ever failed to chain, keep the queue moving.
		$this->drainAnnounce();

		// 4) event retention (roughly once an hour)
		if ((int) $s['retention_days'] > 0 && ($now % 3600) < 60) {
			$cutoff = $now - ((int) $s['retention_days'] * 86400);
			$d = $this->db->prepare('DELETE FROM loneworker_events WHERE ts < ?');
			$d->execute([$cutoff]);
		}
		return true;
	}

	// ----------------------------------------------------- Originate / AMI

	// ------------------------------------- Announcement queue (speakers)
	// The physical speakers can only play one announcement at a time. Announcements
	// are queued and played back-to-back: each one, when it finishes, triggers the
	// next via the AGI 'drain' hook appended to app-loneworker-announce. So when two
	// operators alarm at once, both responder cascades start immediately (in parallel)
	// and their spoken announcements are played one after another, as soon as the
	// speakers free up — alarms first, oldest first — instead of being dropped.
	// The speaker gate marks "an announcement is in flight"; it is released by
	// onAnnounceFinished() at the real end of each announcement.

	/** Queue priority for a message type: lower number = played sooner. */
	private function announcePriority($msg) {
		switch ($msg) {
			case 'alarm':    return 0;   // safety-critical: always first
			case 'ack':      return 1;
			case 'reminder': return 2;
			default:         return 3;   // arm / confirm / disarm
		}
	}

	private function getAnnounceQueue() {
		$raw = (string) $this->getConfig('announce_queue');
		$q = $raw !== '' ? json_decode($raw, true) : [];
		return is_array($q) ? $q : [];
	}
	private function saveAnnounceQueue($q) { $this->setConfig('announce_queue', json_encode(array_values($q))); }

	/** Enqueue an announcement (dedup identical pending msg+ext) and try to play it now. */
	public function enqueueAnnounce($msg, $ext) {
		$ext = (string) $ext;
		$q = $this->getAnnounceQueue();
		foreach ($q as $it) {
			if (($it['msg'] ?? '') === $msg && (string) ($it['ext'] ?? '') === $ext) {
				$this->drainAnnounce(); // already pending: just make sure the queue is moving
				return true;
			}
		}
		$q[] = ['msg' => $msg, 'ext' => $ext, 'ts' => time()];
		$this->saveAnnounceQueue($q);
		$this->logEvent('PAGING_QUEUED', $ext, ['msg' => $msg, 'depth' => count($q)]);
		$this->drainAnnounce();
		return true;
	}

	/** If the speakers are free, pop the highest-priority (then oldest) announcement and play it. */
	public function drainAnnounce() {
		if ($this->speakerBusy()) { return false; }   // one is playing; the AGI hook will chain the next
		$q = $this->getAnnounceQueue();
		if (empty($q)) { return false; }
		$best = 0;
		foreach ($q as $i => $it) {
			$ip = $this->announcePriority($it['msg'] ?? '');         $its = (int) ($it['ts'] ?? 0);
			$bp = $this->announcePriority($q[$best]['msg'] ?? '');   $bts = (int) ($q[$best]['ts'] ?? 0);
			if ($ip < $bp || ($ip === $bp && $its < $bts)) { $best = $i; }
		}
		$item = $q[$best];
		unset($q[$best]);
		$this->saveAnnounceQueue($q);
		return $this->announceNow($item['msg'], $item['ext'], $this->getSettings());
	}

	/** Called by the AGI 'drain' hook at the end of an announcement: release the
	 *  speakers and immediately start the next queued announcement (back-to-back). */
	public function onAnnounceFinished() {
		$this->setConfig('speaker_until', '0'); // release the gate at the real end of the audio
		return $this->drainAnnounce();
	}

	/** Low-level: play ONE announcement on the speakers now (does not touch the queue).
	 *  Dynamic announcement via the paging group (bridge trick). $account, if set, tags the
	 *  channel (accountcode) so a test can be tracked/stopped. */
	private function announceNow($msg, $ext, $s, $account = '') {
		$pg = trim((string) $s['paging_group']);
		if ($pg === '') { $this->logEvent('ERROR', $ext, ['detail' => 'no-paging-group']); return false; }
		$ast = $this->freepbx->astman;
		if (!$ast || !$ast->connected()) { $this->logEvent('ERROR', $ext, ['detail' => 'ami-down']); return false; }
		// Generous fallback ceiling so the once-a-minute tick can't barge in mid-announcement;
		// the real release happens in onAnnounceFinished() via the AGI 'drain' hook.
		$this->setSpeakerGate(60);
		// Exten = message type; CallerID(num) = extension (read by the dialplan via ${CALLERID(num)})
		$params = [
			'Channel'  => 'Local/' . $pg . '@from-internal',
			'Context'  => 'app-loneworker-announce',
			'Exten'    => $msg,
			'Priority' => 1,
			'CallerID' => $ext,
			'Async'    => 'true',
		];
		if ($account !== '') { $params['Account'] = $account; }
		$ast->Originate($params);
		$this->logEvent('PAGING', $ext, ['msg' => $msg, 'pg' => $pg]);
		return true;
	}

	/** Resolve a ring group's members into a list of numbers to call. */
	private function ringGroupMembers($rg) {
		$rg = trim((string) $rg);
		if ($rg === '') { return []; }
		try {
			$sth = $this->db->prepare('SELECT grplist FROM ringgroups WHERE grpnum = ?');
			$sth->execute([$rg]);
			$row = $sth->fetch(\PDO::FETCH_ASSOC);
		} catch (\Throwable $e) { $row = null; }
		if (!$row || trim((string) $row['grplist']) === '') {
			return [$rg]; // no group: dial the configured number directly
		}
		$list = preg_split('/[-\s,]+/', $row['grplist'], -1, PREG_SPLIT_NO_EMPTY);
		return array_values(array_filter(array_map(fn($m) => rtrim(trim($m), '#'), $list), fn($m) => $m !== ''));
	}

	/** ORDERED list of responders (internal+external). Order = order of the single list. */
	public function alarmMembers() {
		$s = $this->getSettings();
		$members = [];
		$raw = trim((string) $s['alarm_numbers']);
		if ($raw !== '') {
			foreach (preg_split('/[\r\n,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $m) {
				$m = preg_replace('/[^0-9*#+]/', '', trim($m));
				if ($m !== '') { $members[] = $m; }
			}
		} else { // back-compat fallback (old separate fields, then ring group)
			foreach (preg_split('/[\s,]+/', (string) $s['alarm_internal_exts'], -1, PREG_SPLIT_NO_EMPTY) as $m) { $m = preg_replace('/[^0-9*#+]/', '', $m); if ($m !== '') { $members[] = $m; } }
			foreach (preg_split('/[\s,]+/', (string) $s['alarm_external_numbers'], -1, PREG_SPLIT_NO_EMPTY) as $m) { $m = preg_replace('/[^0-9*#+]/', '', $m); if ($m !== '') { $members[] = $m; } }
			if (empty($members)) { $members = $this->ringGroupMembers($s['ring_group']); }
		}
		$seen = []; $out = [];                       // dedup while preserving order
		foreach ($members as $m) { if (!isset($seen[$m])) { $seen[$m] = 1; $out[] = $m; } }
		return $out;
	}

	/** True if the extension has a session in ALARMING state (used to stop the sequence). */
	public function isAlarming($ext) {
		$r = $this->getSession((string) $ext);
		return ($r && $r['state'] === 'ALARMING');
	}

	/** Dial string of all responders (together), baked into the dialplan at reload time. */
	public function emergencyDialString() {
		$members = $this->alarmMembers();
		if (empty($members)) { return ''; }
		return implode('&', array_map(fn($m) => 'Local/' . $m . '@from-internal', $members));
	}

	/** Alarm cascade: ring ALL responders. The first to press 1 takes charge and Dial()
	 *  drops every other call. Members live in the dialplan (app-loneworker-emer); here we
	 *  only pass the down worker's extension via CallerID. */
	private function originateAlarm($ext, $s, $account = '') {
		$members = $this->alarmMembers();
		if (empty($members)) { $this->logEvent('ERROR', $ext, ['detail' => 'no-alarm-members']); return false; }
		$ast = $this->freepbx->astman;
		if (!$ast || !$ast->connected()) { $this->logEvent('ERROR', $ext, ['detail' => 'ami-down']); return false; }
		$params = [
			'Channel'     => 'Local/start@app-loneworker-emer',
			'Application' => 'Wait',
			'Data'        => '3600',
			'CallerID'    => $ext,
			'Async'       => 'true',
		];
		if ($account !== '') { $params['Account'] = $account; }
		$ast->Originate($params);
		$this->logEvent('CASCADE', $ext, ['members' => $members]);
		return true;
	}

	// ------------------------------------------------- Readiness / preflight

	/** Convert an Asterisk dialplan pattern (X N Z [] . ! + * #) into a PHP regex. */
	private function asteriskPatternToRegex($pat) {
		$p = (string) $pat;
		if ($p === '') { return null; }
		if ($p[0] === '_') { $p = substr($p, 1); }
		$re = ''; $i = 0; $n = strlen($p);
		while ($i < $n) {
			$c = $p[$i];
			if ($c === 'X' || $c === 'x') { $re .= '[0-9]'; }
			elseif ($c === 'N' || $c === 'n') { $re .= '[2-9]'; }
			elseif ($c === 'Z' || $c === 'z') { $re .= '[1-9]'; }
			elseif ($c === '.' || $c === '!') { $re .= '.*'; }
			elseif ($c === '[') {
				$j = strpos($p, ']', $i);
				if ($j === false) { $re .= '\['; } else { $re .= '[' . substr($p, $i + 1, $j - $i - 1) . ']'; $i = $j; }
			}
			else { $re .= preg_quote($c, '/'); }
			$i++;
		}
		return '/^' . $re . '$/';
	}

	/** Load the outbound routes (in sequence order) with their patterns and trunks. */
	private function loadOutboundRoutes() {
		$seq = [];
		try { $seq = $this->db->query('SELECT route_id FROM outbound_route_sequence ORDER BY seq')->fetchAll(\PDO::FETCH_COLUMN); } catch (\Throwable $e) {}
		if (empty($seq)) { try { $seq = $this->db->query('SELECT route_id FROM outbound_routes ORDER BY route_id')->fetchAll(\PDO::FETCH_COLUMN); } catch (\Throwable $e) { return []; } }
		$routes = [];
		foreach ($seq as $rid) {
			try {
				$rt = $this->db->prepare('SELECT route_id,name,outcid FROM outbound_routes WHERE route_id=?'); $rt->execute([$rid]); $route = $rt->fetch(\PDO::FETCH_ASSOC);
				if (!$route) { continue; }
				$pt = $this->db->prepare('SELECT match_pattern_prefix,match_pattern_pass,match_cid FROM outbound_route_patterns WHERE route_id=?'); $pt->execute([$rid]); $route['patterns'] = $pt->fetchAll(\PDO::FETCH_ASSOC);
				$tr = $this->db->prepare('SELECT trunk_id FROM outbound_route_trunks WHERE route_id=? ORDER BY seq'); $tr->execute([$rid]); $route['trunks'] = $tr->fetchAll(\PDO::FETCH_COLUMN);
				$routes[] = $route;
			} catch (\Throwable $e) {}
		}
		return $routes;
	}

	private function trunkEnabled($trunkid) {
		try { $t = $this->db->prepare('SELECT disabled FROM trunks WHERE trunkid=?'); $t->execute([$trunkid]); $d = $t->fetchColumn(); return !($d === 'on' || $d === '1'); } catch (\Throwable $e) { return true; }
	}

	/** Check whether an external number would find an enabled outbound route (and whether a CID filter would block it). */
	private function matchOutbound($number, $cid, $routes) {
		$cidBlocked = false;
		foreach ($routes as $route) {
			foreach ((array) $route['patterns'] as $p) {
				$full = (string) $p['match_pattern_prefix'] . (string) $p['match_pattern_pass'];
				if ($full === '') { continue; }
				$re = $this->asteriskPatternToRegex($full);
				if ($re === null || !@preg_match($re, $number)) { continue; }
				$mcid = trim((string) ($p['match_cid'] ?? ''));
				if ($mcid !== '') {
					$cre = $this->asteriskPatternToRegex($mcid);
					$cidOk = ($cid !== '' && (($cre !== null && @preg_match($cre, $cid)) || $mcid === $cid));
					if (!$cidOk) { $cidBlocked = true; continue; }
				}
				$trunkOk = false;
				foreach ((array) $route['trunks'] as $tid) { if ($this->trunkEnabled($tid)) { $trunkOk = true; break; } }
				return ['matched' => true, 'route' => $route['name'], 'trunk_ok' => $trunkOk, 'cid_blocked' => false];
			}
		}
		if ($cidBlocked) { return ['matched' => true, 'route' => '', 'trunk_ok' => true, 'cid_blocked' => true]; }
		return ['matched' => false, 'route' => '', 'trunk_ok' => false, 'cid_blocked' => false];
	}

	/** Effective outbound CID of an extension, read from AstDB (what the dialplan uses). */
	private function astdbOutboundCid($ext) {
		try {
			$ast = $this->freepbx->astman;
			if ($ast && $ast->connected()) {
				$v = $ast->database_get('AMPUSER', $ext . '/outboundcid');
				if (is_array($v)) { $v = $v['Val'] ?? ($v['val'] ?? ''); }
				return is_string($v) ? trim($v) : '';
			}
		} catch (\Throwable $e) {}
		return '';
	}

	/** Readiness check: returns [['level'=>ok|warn|fail,'msg'=>...], ...]. */
	public function checkReadiness() {
		$s = $this->getSettings();
		$r = [];
		$add = function ($level, $msg) use (&$r) { $r[] = ['level' => $level, 'msg' => $msg]; };

		// Paging group
		$pg = trim((string) $s['paging_group']);
		if ($pg === '') { $add('fail', _('No paging group set: announcements on the speakers will not play.')); }
		else {
			$ok = false;
			try { $st = $this->db->prepare('SELECT 1 FROM paging_config WHERE page_group=?'); $st->execute([$pg]); $ok = (bool) $st->fetchColumn(); } catch (\Throwable $e) {}
			$add($ok ? 'ok' : 'fail', $ok ? sprintf(_('Paging group %s exists.'), $pg) : sprintf(_('Paging group %s does not exist.'), $pg));
		}

		// Responders (single ordered list). Classify: internal = exists in users, else external.
		$members = $this->alarmMembers();
		$mode = ($s['alarm_mode'] ?? 'simultaneous') === 'sequence' ? _('in sequence') : _('all at once');
		if (empty($members)) { $add('fail', _('No alarm responders configured (internal or external).')); }
		else { $add('ok', sprintf(_('%d alarm responder(s) configured, called %s.'), count($members), $mode)); }

		$names = [];
		try { foreach (\FreePBX::Core()->getAllUsers() as $u) { $names[(string) $u['extension']] = true; } } catch (\Throwable $e) {}
		$externals = [];
		foreach ($members as $m) { if (!isset($names[$m])) { $externals[] = $m; } }

		// External numbers -> outbound routing
		$identity = trim((string) $s['alarm_outbound_cid']) !== '' ? trim((string) $s['alarm_outbound_cid']) : trim((string) $s['alarm_caller_ext']);
		if (!empty($externals)) {
			$routes = $this->loadOutboundRoutes();
			if (empty($routes)) { $add('fail', _('There are external responder numbers but no outbound routes exist: the alarm cannot call them.')); }
			else {
				foreach ($externals as $num) {
					$res = $this->matchOutbound($num, $identity, $routes);
					if (!$res['matched']) { $add('fail', sprintf(_('No enabled outbound route matches external number %s.'), $num)); }
					elseif ($res['cid_blocked']) { $add('warn', sprintf(_('External number %s only matches a route filtered by Caller ID; the alarm Caller ID may not pass it.'), $num)); }
					elseif (!$res['trunk_ok']) { $add('fail', sprintf(_('External number %s matches route "%s" but its trunk is disabled.'), $num, $res['route'])); }
					else { $add('ok', sprintf(_('External number %s will route out via "%s".'), $num, $res['route'])); }
				}
			}
			// Effective outbound CID
			if (trim((string) $s['alarm_outbound_cid']) !== '') { $add('ok', sprintf(_('External calls will present Caller ID %s.'), $s['alarm_outbound_cid'])); }
			else {
				$cidExt = trim((string) $s['alarm_caller_ext']);
				if ($cidExt === '') { $add('warn', _('No alarm Caller ID set: external calls go out as the down worker extension. If the trunk/route has no outbound CID, the carrier may receive an internal number. Set a service extension or an explicit Caller ID.')); }
				else {
					$eff = $this->astdbOutboundCid($cidExt);
					if ($eff === '') { $add('warn', sprintf(_('Service extension %s has no outbound Caller ID: the carrier may receive an internal number. Set its Outbound CID or an explicit Caller ID.'), $cidExt)); }
					else { $add('ok', sprintf(_('External calls will use the outbound Caller ID of extension %s (%s).'), $cidExt, $eff)); }
				}
			}
		}

		// Recordings (minimum set)
		$reqd = ['rec_armed_pre' => _('armed'), 'rec_alarm_pre' => _('alarm'), 'rec_call_pre' => _('emergency call')];
		$miss = [];
		foreach ($reqd as $k => $lbl) { if (empty($s[$k])) { $miss[] = $lbl; } }
		$add($miss ? 'warn' : 'ok', $miss ? sprintf(_('Some key recordings are not set: %s.'), implode(', ', $miss)) : _('Key recordings are set.'));

		// Feature codes
		foreach (['arm', 'checkin', 'disarm'] as $n) {
			if (!(new \featurecode('loneworker', $n))->getCodeActive()) { $add('warn', sprintf(_('Feature code "%s" is not active.'), $n)); }
		}

		// AMI + job
		$ast = $this->freepbx->astman;
		$amiOk = ($ast && $ast->connected());
		$add($amiOk ? 'ok' : 'fail', $amiOk ? _('Asterisk Manager (AMI) reachable.') : _('Asterisk Manager (AMI) not reachable: announcements and calls will fail.'));
		try {
			$found = false;
			foreach ((array) $this->freepbx->Job->getAll() as $j) { if (($j['modulename'] ?? '') === 'loneworker') { $found = true; } }
			$add($found ? 'ok' : 'warn', $found ? _('Monitoring job is registered.') : _('Monitoring job is not registered.'));
		} catch (\Throwable $e) {}

		// Timer sanity
		if ((int) $s['reminder_after'] >= (int) $s['timeout']) { $add('warn', _('First reminder is not before the timeout: reminders will not play.')); }
		if ((int) $s['alarm_repeat'] < (int) $s['ring_time']) { $add('warn', _('Alarm repeat interval is shorter than the ring time: alarm rounds may overlap.')); }

		return $r;
	}

	// --------------------------------------------------------------- GUI

	public function getActionBar($request) {
		$buttons = [];
		if (($request['display'] ?? '') == 'loneworker' && ($_GET['view'] ?? '') == 'settings') {
			$buttons = [
				'reset'  => ['name' => 'reset',  'id' => 'reset',  'value' => _('Reset')],
				'submit' => ['name' => 'submit', 'id' => 'submit', 'value' => _('Submit')],
			];
		}
		return $buttons;
	}

	public function doConfigPageInit($page) {
		$request = $_REQUEST;
		$action = $request['action'] ?? '';
		if ($action === 'savesettings') {
			$this->saveSettings($request);
			$this->saveFeatureCodes($request);
			needreload();
		} elseif ($action === 'admindisarm' && !empty($request['ext'])) {
			$this->disarm($request['ext']);
		} elseif ($action === 'testannounce') {
			$this->testAnnounce();
		} elseif ($action === 'testalarm') {
			$this->testAlarm();
		}
	}

	/** accountcode used to tag test calls so they can be tracked and stopped. */
	const TEST_ACCOUNT = 'lwtest';

	/** Test: play an announcement on the speakers (fake extension), without arming anything. */
	public function testAnnounce() {
		$s = $this->getSettings();
		$this->setConfig('speaker_until', '0');
		$ok = $this->announceNow('reminder', '000', $s, self::TEST_ACCOUNT);
		$this->logEvent('TEST', '000', ['what' => 'announce', 'ok' => $ok]);
		return $ok;
	}

	/** Test: launch the alarm cascade to the responders (fake extension), without a session. */
	public function testAlarm() {
		$s = $this->getSettings();
		$ok = $this->originateAlarm('000', $s, self::TEST_ACCOUNT);
		$this->logEvent('TEST', '000', ['what' => 'alarm', 'ok' => $ok]);
		return $ok;
	}

	/** Active test channels (tagged with the test accountcode), with their live state. */
	public function testChannels() {
		$ast = $this->freepbx->astman;
		if (!$ast || !$ast->connected()) { return []; }
		$resp = $ast->Command('core show channels concise');
		$text = is_array($resp) ? ($resp['data'] ?? '') : (string) $resp;
		$out = [];
		foreach (preg_split('/\r?\n/', (string) $text) as $line) {
			if ($line === '' || strpos($line, '!') === false) { continue; }
			$f = explode('!', $line);
			if (!in_array(self::TEST_ACCOUNT, $f, true)) { continue; } // accountcode tag
			$out[] = ['name' => $f[0], 'state' => $f[4] ?? ''];
		}
		return $out;
	}

	/** Live status of a running test (for the GUI). */
	public function testStatus() {
		$ch = $this->testChannels();
		return ['active' => !empty($ch), 'count' => count($ch), 'channels' => $ch];
	}

	/** Stop a running test: hang up all test-tagged channels. */
	public function testStop() {
		$ast = $this->freepbx->astman;
		if (!$ast || !$ast->connected()) { return ['stopped' => 0]; }
		$n = 0;
		foreach ($this->testChannels() as $c) {
			try { $ast->Hangup($c['name']); $n++; } catch (\Throwable $e) {}
		}
		$this->logEvent('TEST', '000', ['what' => 'stop', 'stopped' => $n]);
		return ['stopped' => $n];
	}

	/** Latest events with operator name, for the history page. */
	public function getEventsForView($limit = 200) {
		$limit = (int) $limit;
		$rows = $this->db->query("SELECT ts,event,ext,payload FROM loneworker_events ORDER BY id DESC LIMIT $limit")->fetchAll(\PDO::FETCH_ASSOC);
		$names = [];
		try {
			foreach (\FreePBX::Core()->getAllUsers() as $u) { $names[$u['extension']] = $u['name']; }
		} catch (\Throwable $e) {}
		foreach ($rows as &$r) { $r['name'] = $names[$r['ext']] ?? ''; }
		return $rows;
	}

	public function ajaxRequest($req, $setting) {
		return in_array($req, ['sessions', 'getJSON', 'teststart', 'teststatus', 'teststop'], true);
	}

	public function ajaxHandler() {
		$cmd = $_REQUEST['command'] ?? '';
		switch ($cmd) {
			case 'sessions':
			case 'getJSON':
				return $this->getSessionsForGrid();
			case 'teststart':
				$type = $_REQUEST['type'] ?? '';
				$ok = ($type === 'alarm') ? $this->testAlarm() : $this->testAnnounce();
				return ['ok' => (bool) $ok, 'type' => $type];
			case 'teststatus':
				return $this->testStatus();
			case 'teststop':
				return $this->testStop();
		}
		return false;
	}

	/** Sessions enriched with operator name + time labels for the grid. */
	public function getSessionsForGrid() {
		$rows = $this->getSessions();
		$names = [];
		try {
			foreach (\FreePBX::Core()->getAllUsers() as $u) { $names[$u['extension']] = $u['name']; }
		} catch (\Throwable $e) {}
		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'ext'              => $r['ext'],
				'name'             => $names[$r['ext']] ?? '',
				'state'            => $r['state'],
				'start_ts'         => (int) $r['start_ts'],
				'next_reminder_ts' => (int) $r['next_reminder_ts'],
				'deadline_ts'      => (int) $r['deadline_ts'],
				'alarm_started_ts' => $r['alarm_started_ts'] !== null ? (int) $r['alarm_started_ts'] : null,
			];
		}
		return $out;
	}
}
