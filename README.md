# Lone Worker (loneworker) — FreePBX 17 module

Native multi-operator "lone worker" / man-down safety module. Each operator arms, confirms
or disarms their own session from their own extension; if the timeout elapses without a
confirmation, an alarm is announced on the speakers and calls are placed to the configured
responders (internal extensions and external numbers).

UI is in **English** with a full **Italian** translation (`i18n/`, gettext). The Settings page
explains every field and shows a **live example** of what will happen based on the current config.

## Install

**Recommended — install the published Release straight from GitHub** (one command, on the PBX):

```
sudo fwconsole ma downloadinstall https://github.com/Tesseract-Technologies-IT/freepbx-loneworker/releases/latest/download/loneworker.tgz
sudo fwconsole reload
```

The `…/releases/latest/download/loneworker.tgz` URL always points at the newest release, so the
same command installs and later upgrades the module. To pin a specific version instead:

```
sudo fwconsole ma downloadinstall https://github.com/Tesseract-Technologies-IT/freepbx-loneworker/releases/download/v<version>/loneworker-<version>.tgz
sudo fwconsole reload
```

**Requirements:** FreePBX 17 with the `core` and `recordings` modules. The module is GPG-signed; if
its key is not in the PBX keyring, Module Admin shows an "unsigned/unknown key" warning — confirm it,
import the public key, or allow unsigned modules (see [Signing](#signing-optional)).

**Alternative install methods:**

- **GUI upload** — download the `.tgz` from the [Releases](https://github.com/Tesseract-Technologies-IT/freepbx-loneworker/releases)
  page, then Admin → Module Admin → *Upload modules* → upload it → Install.
- **From a checkout of this repo** (development):
  ```
  git clone https://github.com/Tesseract-Technologies-IT/freepbx-loneworker.git /var/www/html/admin/modules/loneworker
  chown -R asterisk:asterisk /var/www/html/admin/modules/loneworker
  fwconsole ma installlocal loneworker && fwconsole reload
  ```

After installing, go to **Lone Worker → Settings** in the GUI and set the Paging group, the alarm
responders (internal extensions + external numbers) and the System Recordings, then adjust the
timers. The **live example** and the **Readiness** panel update as you edit.

### Updating

Upgrades use the **same command** as the install — `downloadinstall` upgrades an already-installed
module in place when the package `<version>` is higher than the installed one:

```
sudo fwconsole ma downloadinstall https://github.com/Tesseract-Technologies-IT/freepbx-loneworker/releases/latest/download/loneworker.tgz
sudo fwconsole reload
```

- Your **data is preserved**: kvstore settings and the `loneworker_sessions` / `loneworker_events`
  tables are kept across upgrades (only `uninstall` removes them); `<database>` schema changes are
  applied automatically.
- There is **no automatic "update available" badge** in Module Admin (the module ships via GitHub
  Releases, not a FreePBX online repo feed): updating is a pull — re-run the command above or
  re-upload in the GUI.

### Uninstall

```
fwconsole ma uninstall loneworker   # removes feature codes, job, AGI, tables
fwconsole ma delete loneworker
fwconsole reload
```

### Italian UI

Generate the locale once (`locale-gen it_IT.UTF-8`), then set the GUI language to Italian
(Admin → User Management, per-user language, or the default language setting). All labels, help text
and the live example are translated.

## How it works

| Code | Action | Effect |
|------|--------|--------|
| **701** | Arm | Creates a session for `${CALLERID(num)}`; "armed" announcement on the speakers; countdown to the timeout |
| **702** | Check-in | Resets the timer of the caller's own session; "confirmed" announcement |
| **703** | Disarm | Closes the caller's own session; "disarmed" announcement |

(701/702/703 are the default feature codes; they are editable on the Settings page and in
Admin → Feature Codes.)

- Reminders on the speakers at `reminder_after`, then every `reminder_interval`, until the deadline.
- On `timeout`: "alarm" announcement on the speakers + **all configured responders are called at
  the same time** (one `Dial` to every internal extension and external number). The **first
  responder to press the confirm key** takes charge (ACK) — at that instant Asterisk's `Dial` drops
  every other ringing/answered call. The list is baked into the dialplan at reload time; the ring
  duration is the "Alarm ring time" setting.
- **Responder confirmation is configurable** (Settings → *Responders & calls*):
  - **Key to take charge** (`confirm_key`, default `1`) — the DTMF key the responder presses.
  - **Confirmation timeout** (`confirm_timeout`, default 15s) — how long they have to press the key
    on each prompt (the prompt repeats up to 3 times).
  - **After the alarm is confirmed** (`confirm_action`): `disarm` closes the session (incident over)
    or `hold` keeps it as `ACKED` ("TAKEN CHARGE") until an operator manually disarms.
  - **Announce on the speakers** (`confirm_announce`, default yes) — play the "taken charge"
    announcement when a responder confirms.
- **If nobody takes charge**, the alarm keeps re-announcing on the speakers and re-calling the
  responders every "Repeat alarm every" seconds until someone presses the confirm key or an operator
  disarms. Multiple operators can be in alarm at once — each escalates independently (no blocking).
- The extension and the check-in number are spoken with `SayDigits` in the language set by
  "Spoken number language" (default Italian).
- The Settings page has **Test** buttons (test announcement / test alarm call) and an
  **Event history** view (audit log of arm/check-in/alarm/re-call/ack/…).
- Independent sessions per extension; multiple alarms run in parallel. **Spoken announcements are
  queued** and played back-to-back so they never overlap on the speakers: when two operators alarm
  at once, both responder cascades start **immediately and in parallel**, and the announcements play
  one after another as soon as the speakers free up — **alarm announcements first, oldest alarm
  first**, reminders after. Each announcement, when it finishes, triggers the next via an AGI hook,
  so the queue drains as fast as the speakers allow (with the per-minute tick as a safety net).

## Configuring alarm responders (no Ring Group needed)

Responders are entered **directly in the module** (Settings → *Responders & calls*), no Ring Group
required: a single ordered list of numbers (internal extensions, picked from a selector, and
external numbers), plus a selector for **all at once** vs **in sequence**.

When an alarm fires the module dials them with a confirmation routine; the first responder to press
the confirm key takes charge and every other call stops.

### External numbers & outbound routes (Caller ID matters)

External numbers are dialed via `from-internal`, so they follow your **outbound routes**. Two things
determine whether the call goes out and what number is shown:

1. **Route matching / CID filter** — a route matches by dialed pattern; if a route has a `match_cid`
   filter (`outbound_route_patterns.match_cid`) it only applies to calls whose Caller ID matches, so
   a call with a different CID is *skipped*.
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

**No.** The risk you'd have with a plain ring group (a voicemail or accidental pick-up counting as
"answered" and stopping the others) does **not** apply here. The cascade is
`Dial(member1&member2&…, ring_time, U(confirm))`: when a phone answers, the confirmation routine runs
on that phone and **the other phones keep ringing**. Only when someone actually presses the confirm
key does that call win and Asterisk drops the others. A phone that answers without pressing the key
(e.g. a voicemail) is left looping the prompt while everyone else still rings — and on the next
repeat the whole round fires again. (Verified on Asterisk 22: the other legs stay in `Ring` while one
answerer is in the confirm subroutine.)

## Suggested recording scripts

Each announcement is split in two; the system speaks the extension number (e.g. "3-0-1") between the
two parts. Suggested wording:

| Message | Before « ext » After |
|---|---|
| **Armed** | "Attention. Lone worker system armed for extension" « N » "must confirm they are OK within 30 minutes by calling number" « check-in code » |
| **Confirmed** | "Lone worker: extension" « N » "has confirmed their presence. The system stays active." |
| **Reminder** | "Lone worker reminder. Extension" « N » "must confirm presence. Only a few minutes remain before the alarm. Call number" « check-in code » |
| **Alarm (speakers)** | "Lone worker alarm. Extension" « N » "did not confirm. Check the operator immediately. Emergency calls have been started." |
| **Acknowledged** | "Lone worker alarm taken charge of for extension" « N » "A responder is checking the situation on site." |
| **Disarmed** | "Lone worker system disarmed for extension" « N » (no second part) |
| **Emergency call** | "Lone worker alarm. The operator of extension" « N » "did not confirm. Press the confirm key to take charge of the alarm." |

The « check-in code » is **not recorded** into the audio: it is spoken dynamically with SayDigits
from the current Check-in feature code, so changing the code updates the announcement automatically.

## Architecture (all native FreePBX)

- **Tables** `loneworker_sessions` / `loneworker_events` in the `asterisk` DB (module.xml/Doctrine).
- **Feature codes** 701/702/703 (Admin → Feature Codes), configurable.
- **Dialplan** from `loneworker_get_config()`: contexts `app-loneworker`, `app-loneworker-announce`,
  `app-loneworker-emer`, `app-loneworker-confirm`.
- **AGI** `agi/loneworker.agi.php` (copied to `/var/lib/asterisk/agi-bin/`): handles 701/702/703, the
  take-charge key (ACK) and the announcement-queue `drain` hook, delegating to the BMO class.
- **Job** `loneworker tick` (every minute, `\FreePBX::Job`): evaluates reminders/alarms and drains
  the announcement queue as a safety net.
- **Announcements** = System Recordings chosen by id on the Settings page; between the "before" and
  "after" parts the dialplan speaks the extension with `SayDigits(${CALLERID(num)})`.
- **Settings** in kvstore; **announcement queue** and **speaker gate** in kvstore.
- **i18n**: English source strings + `i18n/it_IT/LC_MESSAGES/loneworker.mo` (and `it/`).

## Notes

- The acting extension is the phone's `CALLERID(num)`: each code only affects the caller's own
  session (by design, for safety).
- `authorized_extensions` empty = every extension is allowed.

---

## For developers — version control, packaging, releases & signing

This folder is a self-contained git repository (`module.xml` + PHP + AGI + i18n).

### Versioning conventions (same as FreePBX/framework)

- `<version>` in `module.xml` is the **single source of truth**; bump it and add a
  `*X.Y.Z* description` line to `<changelog>` for each release.
- `module.sig` is **built at packaging time, never committed** (it's git-ignored).
- `.gitattributes` keeps `module.xml`/`*.mo` on `merge=ours` and `*.po` on a gettext merge driver,
  so generated/version files don't cause merge conflicts — exactly like Sangoma's repo.
- Sangoma tags `release/X.Y.Z` on a release branch and publishes through their own mirror; this
  third-party module has no mirror, so it publishes via **GitHub Releases** (tag `vX.Y.Z`) instead.

### Cut a release (GitHub Actions)

`.github/workflows/release.yml` runs on a version tag and does the build/publish:

```
# 1) bump <version> in module.xml + add a *X.Y.Z* changelog line, commit
# 2) tag and push:
git tag v1.0.0
git push origin v1.0.0
```

The workflow verifies the tag matches `module.xml`, runs `build.sh` (signing it if the
`LONEWORKER_GPG_PRIVATE_KEY` repo secret is set), and creates a Release with two assets:
`loneworker-<version>.tgz` and the stable `loneworker.tgz` used by the install command above.

### Build a tarball locally

```
./build.sh                  # compiles i18n .mo and writes ./loneworker-<version>.tgz
./build.sh --sign <KEYID>   # also writes a signed module.sig before packaging
```

The tarball always contains a top-level `loneworker/` folder (the FreePBX rawname) regardless of the
checkout folder name — the exact format FreePBX expects.

### Signing (optional)

FreePBX validates a `module.sig` (a GPG clear-signed manifest of SHA256 file hashes) against keys in
its keyring. To self-sign:

```
gpg --full-generate-key                    # one time: RSA 4096; note the KEYID
./build.sh --sign <KEYID>                  # writes module.sig, then the signed .tgz
```

For the signature to be *trusted* on each target PBX, import your PUBLIC key into FreePBX's keyring
(kept at `/home/asterisk/.gnupg`):

```
gpg --export -a <KEYID> > tesseract.asc    # copy this file to the PBX, then on the PBX:
sudo -u asterisk gpg --homedir /home/asterisk/.gnupg --import tesseract.asc
```

Otherwise FreePBX reports "signed by an unknown key". To appear in the official online Module Admin
and be trusted everywhere without importing keys, the module would have to go through Sangoma's
module-vendor / signing process.
