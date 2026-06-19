#!/usr/bin/env php
<?php
// Lone Worker AGI: invoked by the dialplan on feature codes 701/702/703 and on the
// confirm key (take charge). Bootstraps FreePBX and delegates to the BMO class.
//
// Dialplan usage:
//   AGI(/var/www/html/admin/modules/loneworker/agi/loneworker.agi.php,arm,${CALLERID(num)})
//   AGI(.../loneworker.agi.php,checkin,${CALLERID(num)})
//   AGI(.../loneworker.agi.php,disarm,${CALLERID(num)})
//   AGI(.../loneworker.agi.php,ack)

$bootstrap_settings = ['freepbx_auth' => false];
$conf = getenv('FREEPBX_CONF') ?: '/etc/freepbx.conf';
require_once $conf;
require_once '/var/lib/asterisk/agi-bin/phpagi.php';

$agi = new AGI();

$action = isset($argv[1]) ? $argv[1] : '';
$ext    = isset($argv[2]) ? $argv[2] : '';
if ($ext === '' && !empty($agi->request['agi_callerid'])) {
	$ext = $agi->request['agi_callerid'];
}

$res = ['result' => 'err', 'ext' => $ext];
try {
	$lw = \FreePBX::Loneworker();
	switch ($action) {
		case 'arm':     $res = $lw->arm($ext);     break;
		case 'checkin': $res = $lw->checkin($ext); break;
		case 'disarm':  $res = $lw->disarm($ext);  break;
		case 'ack':     $res = ($ext !== '') ? $lw->ackByExt($ext) : $lw->ackOldest(); break;
		case 'isactive': $agi->set_variable('LW_ACTIVE', $lw->isAlarming($ext) ? '1' : '0'); $res = ['result' => 'ok', 'ext' => $ext]; break;
		case 'nextann': // drain loop: fetch the next queued announcement and expose it as channel vars
			$a = $lw->nextAnnouncement();
			if (!$a) {
				$agi->set_variable('LW_MSG', '');
			} else {
				$agi->set_variable('LW_MSG', $a['msg']);
				$agi->set_variable('LW_EXT', $a['ext']);
				$agi->set_variable('LW_PRE', $a['pre']);
				$agi->set_variable('LW_POST', $a['post']);
				$agi->set_variable('LW_SAY', $a['say']);
				$agi->set_variable('LW_LANG', $a['lang']);
			}
			$res = ['result' => 'ok', 'ext' => $ext];
			break;
		default:        $res = ['result' => 'err', 'ext' => $ext];
	}
} catch (\Throwable $e) {
	$res = ['result' => 'err', 'ext' => $ext];
	@file_put_contents('/var/log/asterisk/loneworker-agi.log', date('c') . " ERROR " . $e->getMessage() . "\n", FILE_APPEND);
}

$agi->set_variable('LW_RESULT', $res['result']);
$agi->set_variable('LW_EXT', isset($res['ext']) ? $res['ext'] : $ext);
exit(0);
