# Lone Worker (loneworker) — FreePBX 17 module

Native multi-operator "lone worker" / man-down safety module. Each operator arms, confirms
or disarms their own session from their own extension; if the timeout elapses without a
confirmation, an alarm is announced on the speakers and calls are placed to the configured
responders (internal extensions and external numbers).

UI is in **English** with a full **Italian** translation (`i18n/`, gettext). The Settings page
explains every field and shows a **live example** of what will happen based on the current config.

## How it works

| Code | Action | Effect |
|------|--------|--------|
| **701** | Arm | Creates a session for `${CALLERID(num)}`; "armed" announcement on the speakers; countdown to the timeout |
| **702** | Check-in | Resets the timer of the caller's own session; "confirmed" announcement |
| **703** | Disarm | Closes the caller's own session; "disarmed" announcement |

- Reminders on the speakers at `reminder_after`, then every `reminder_interval`, until the deadline.
- On `timeout`: "alarm" announcement on the speakers + **all configured responders are called at
  the same time** (one `Dial` to every internal extension and external number). The **first
  responder to press 1** takes charge (ACK) — at that instant Asterisk's `Dial` drops every other
  ringing/answered call. The list is baked into the dialplan at reload time; the ring duration is
  the "Alarm ring time" setting.
- **If nobody takes charge**, the alarm keeps re-announcing on the speakers and re-calling the
  responders every "Repeat alarm every" seconds until someone presses 1 or an operator disarms.
  Multiple operators can be in alarm at once — each escalates independently (no blocking).
- The extension and the check-in number are spoken with `SayDigits` in the language set by
  "Spoken number language" (default Italian).
- The Settings page has **Test** buttons (test announcement / test alarm call) and an
  **Event history** view (audit log of arm/check-in/alarm/re-call/ack/…).
- Independent sessions per extension; multiple alarms run in parallel. Announcements are serialised
  (speaker gate) so they don't overlap on the speakers.

## Architecture (all native FreePBX)

- **Tables** `loneworker_sessions` / `loneworker_events` in the `asterisk` DB (module.xml/Doctrine).
- **Feature codes** 701/702/703 (Admin → Feature Codes), configurable.
- **Dialplan** from `loneworker_get_config()`: contexts `app-loneworker`, `app-loneworker-announce`,
  `app-loneworker-emer`.
- **AGI** `agi/loneworker.agi.php` (copied to `/var/lib/asterisk/agi-bin/`): handles 701/702/703
  and the # key (ACK), delegating to the BMO class.
- **Job** `loneworker tick` (every minute, `\FreePBX::Job`): evaluates reminders/alarms.
- **Announcements** = System Recordings chosen by id on the Settings page; between the "before"
  and "after" parts the dialplan speaks the extension with `SayDigits(${CALLERID(num)})`.
- **Settings** in kvstore; **locks** (alarm/speaker) in kvstore.
- **i18n**: English source strings + `i18n/it_IT/LC_MESSAGES/loneworker.mo` (and `it/`).

## Suggested recording scripts

Each announcement is split in two; the system speaks the extension number (e.g. "3-0-1") between
the two parts. Suggested wording (IT in parentheses):

| Message | Before « ext » After |
|---|---|
| **Armed** | "Attention. Lone worker system armed for extension" « N » "must confirm they are OK within 30 minutes by calling number" « check-in code » |
| **Confirmed** | "Lone worker: extension" « N » "has confirmed their presence. The system stays active." |
| **Reminder** | "Lone worker reminder. Extension" « N » "must confirm presence. Only a few minutes remain before the alarm. Call number" « check-in code » |
| **Alarm (speakers)** | "Lone worker alarm. Extension" « N » "did not confirm. Check the operator immediately. Emergency calls have been started." |
| **Acknowledged** | "Lone worker alarm taken charge of for extension" « N » "A responder is checking the situation on site." |
| **Disarmed** | "Lone worker system disarmed for extension" « N » (no second part) |
| **Emergency call** | "Lone worker alarm. The operator of extension" « N » "did not confirm. Press 1 to take charge of the alarm." |

The « check-in code » is **not recorded** into the audio: it is spoken dynamically with SayDigits
from the current Check-in feature code, so changing the code updates the announcement automatically.

The three feature codes (Arm / Check-in / Disarm, default 701/702/703) are editable directly on the
Settings page ("Feature codes" section) as well as in Admin → Feature Codes.

## Configuring alarm responders (no Ring Group needed)

Responders are entered **directly in the module** (Settings → *Audio & call routing*), no Ring
Group required:
- **Internal responders** — a multiselect of extensions.
- **External numbers** — a textarea, one number per line.

When an alarm fires the module dials **all** of them at once with a press-1 confirmation; the
first to press **1** takes charge and every other call stops.

### External numbers & outbound routes (Caller ID matters)
External numbers are dialed via `from-internal`, so they follow your **outbound routes**. Two
things determine whether the call goes out and what number is shown:
1. **Route matching / CID filter** — a route matches by dialed pattern; if a route has a
   `match_cid` filter (`outbound_route_patterns.match_cid`) it only applies to calls whose Caller
   ID matches, so a call with a different CID is *skipped*.
2. **Outbound Caller ID** — priority: route forced CID > trunk CID > the extension's outbound CID
   (AstDB `AMPUSER/<ext>/outboundcid`) > the originating Caller ID.

Because of (2), the alarm's Caller ID (which becomes `AMPUSER`) decides both the presented CID and
which CID-filtered routes it passes. The module gives you two settings:
- **Service extension for alarm calls** (`alarm_caller_ext`): external calls go out *as* that
  extension — inheriting its outbound CID and passing its CID-filtered routes. Default = the down
  worker's own extension.
- **Explicit outbound Caller ID** (`alarm_outbound_cid`): a fixed number to present (overrides the
  service extension). Use one your carrier accepts and your routes don't filter out.

The **Readiness check** panel verifies, per external number, that an enabled route matches it (and
isn't CID-blocked), the trunk is enabled, and that a sane outbound CID will be presented — so you
don't discover a misconfiguration during a real emergency.

> Real-world example (galvanoplast): a single catch-all route `Tutte` with pattern `X.`, no
> `match_cid` filter, to one pjsip trunk, with no `outcid` set. Any number routes out, but since no
> outbound CID is configured anywhere, the alarm would present the internal extension number to the
> carrier — set a service extension with an Outbound CID, or an explicit Caller ID, to be safe.

### Does "answered but not confirmed" count as answered?
**No.** The risk you'd have with a plain ring group (a voicemail or accidental pick-up counting
as "answered" and stopping the others) does **not** apply here. The cascade is
`Dial(member1&member2&…, ring_time, U(confirm))`: when a phone answers, the confirmation routine
runs on that phone and **the other phones keep ringing**. Only when someone actually presses **1**
does that call win and Asterisk drops the others. A phone that answers without pressing 1 (e.g. a
voicemail) is left looping the prompt while everyone else still rings — and on the next repeat the
whole round fires again. (Verified on Asterisk 22: the other legs stay in `Ring` while one answerer
is in the confirm subroutine.)

## Install

```
# copy to /var/www/html/admin/modules/loneworker
fwconsole ma installlocal loneworker
fwconsole reload
```
Then in the GUI → **Lone Worker → Settings**: choose the Paging group, Ring group and the 15
System Recordings; adjust the timers. The live example updates as you edit.

### Italian UI
Generate the locale once (`locale-gen it_IT.UTF-8`), then set the GUI language to Italian
(Admin → User Management, per-user language, or the default language setting). All labels,
help text and the live example are translated.

## Uninstall

```
fwconsole ma uninstall loneworker   # removes feature codes, job, AGI, tables
fwconsole ma delete loneworker
fwconsole reload
```

## Notes
- 701/702/703 are feature codes: changeable in Admin → Feature Codes.
- The acting extension is the phone's `CALLERID(num)`: each code only affects the caller's own
  session (by design, for safety).
- `authorized_extensions` empty = every extension is allowed.

## Version control, packaging, distribution & signing

This folder is a self-contained git repository (`module.xml` + PHP + AGI + i18n).

### Track it with git / publish
```
git remote add origin <your-repo-url>      # e.g. GitHub/GitLab
git push -u origin master
```

### Build an installable tarball
```
./build.sh                 # compiles i18n .mo and writes /tmp/loneworker-<version>.tgz
```
The tarball contains a top-level `loneworker/` folder — the exact format FreePBX expects.

### Install on another FreePBX 17 PBX (any of these)
1. **GUI**: Admin → Module Admin → *Upload modules* → upload the `.tgz` → Install.
2. **CLI from a URL** (e.g. a GitHub release asset):
   `fwconsole ma downloadinstall https://example.com/loneworker-<version>.tgz && fwconsole reload`
3. **git clone / copy**:
   ```
   git clone <repo> /var/www/html/admin/modules/loneworker
   chown -R asterisk:asterisk /var/www/html/admin/modules/loneworker
   fwconsole ma installlocal loneworker && fwconsole reload
   ```
Target requirements: FreePBX 17 with the `core` and `recordings` modules. An unsigned module
installs with a warning (confirm it, or set Module Admin signature checking to allow unsigned).

### Sign the module (optional — removes the "unsigned" warning)
FreePBX validates a `module.sig` (a GPG clear-signed manifest of SHA256 file hashes) against keys
in its keyring. To self-sign:
```
gpg --full-generate-key                    # one time: RSA 4096; note the KEYID
./build.sh --sign <KEYID>                  # writes module.sig, then the signed .tgz
```
For the signature to be *trusted* on each target PBX, import your PUBLIC key into FreePBX's
keyring (kept at `/home/asterisk/.gnupg`):
```
gpg --export -a <KEYID> > tesseract.asc    # copy this file to the PBX, then on the PBX:
sudo -u asterisk gpg --homedir /home/asterisk/.gnupg --import tesseract.asc
```
Otherwise FreePBX reports "signed by an unknown key". To appear in the official online Module
Admin and be trusted everywhere without importing keys, the module must go through Sangoma's
module-vendor / signing process.
