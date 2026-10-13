# Uninstall and data-preserving reinstall (S1-13)

| | |
|---|---|
| **Status** | Implemented (S1-13, #41): `apps/macos/LaravelExecutiveAI/Install/Uninstaller.swift` (the order, the journal, the Trash step), `apps/macos/LaravelExecutiveAI/MenuBar/UninstallView.swift` (Settings → Uninstall, the keep-or-delete sheet), `removeAllSecrets(keepApplicationKey:)` on the Supervisor's XPC surface ([services.md](../architecture/services.md) §7–§8; the A25 argument landed with S2-04, #87). The real launchd, Keychain and Trash paths run only on the S1-14 lab Mac (§9); every test here uses the recording doubles. |
| **Blueprint** | §12.2 (uninstall and data-preserving reinstall with 100 % end-to-end coverage; "an explicit choice to keep or delete user data"), §1.1 (Uninstall is a Settings surface), §12.1 (nothing asks for a Terminal) |
| **Decisions** | ADR-001 D4 (what is runtime, what is data), ADR-003 D2 (only the Supervisor holds the Keychain access group — so the sweep runs *through* it), D8 ("the uninstaller enumerates `se.stafe.executive-ai.*` and deletes **every item except the application key when data is kept**; the Keychain does not remove items when an app is deleted, so this is an explicit step and a user-visible promise" — amended from "every item" by S2-04, #87, per **A25**), threat model T-41, T-72 |
| **Tests** | `apps/macos/LaravelExecutiveAITests/Install/UninstallerTests.swift`, `MenuBar/UninstallViewTests.swift`, `Services/SupervisorControlServiceTests.swift` (`testRemoveAllSecrets…`), `Services/SupervisorSecretsTests.swift` (`testRemoveAllSparesOnlyTheApplicationKeyWhenDataIsKept`), `tests/Feature/Install/ReinstallPreservesDataTest.php` (the A25 round trip, and the fail-closed read under a different key) |

## 1. Where it is

**Settings** (⌘, from the app, or *Settings…* in the menu bar panel) → **Uninstall** → *Uninstall…*.
A sheet explains what is about to happen and offers exactly three ways out:

| Button | Does |
|---|---|
| **Keep my data** (default) | removes everything in §3 except `data/`; a later install continues with the same owner account and the same briefs (§5) |
| **Delete everything** | removes everything in §3, `data/` included; cannot be undone |
| **Cancel** | nothing happens — nothing runs before *Uninstall* is clicked with a choice made |

The sheet is in English or Swedish with the rest of the menu bar (*Avinstallera…*, *Behåll mina
data*, *Ta bort allt*, *Avbryt*). No admin password is asked for: nothing here needs one
(§12.2 — everything removed is owned by the person's own account).

Rendered from fixture data by `UninstallViewTests`:
[Settings](../architecture/images/menubar/06-settings.png) ·
[the sheet](../architecture/images/menubar/07-uninstall-choose.png)
([Swedish](../architecture/images/menubar/07-uninstall-choose-sv.png)) ·
[done, data kept](../architecture/images/menubar/08-uninstall-done-kept.png) ·
[stopped half-way](../architecture/images/menubar/09-uninstall-failed.png).

## 2. What happens, in this order

| # | Step | Why here |
|---|---|---|
| 1 | The Supervisor stops the core, the queue worker and the scheduler (`stopAll` over XPC) | nothing may write to the database or the Keychain while they are being removed |
| 2 | The Supervisor deletes the `se.stafe.executive-ai.*` Keychain items and reports their names (`removeAllSecrets(keepApplicationKey:)` over XPC, services.md §7) — **every item, except `core.app-key` when you keep your data** (§5) | only the Supervisor holds the shared access group (ADR-003 D2); the app itself cannot see those items, so the sweep must run while the Supervisor is still alive |
| 3 | The launch agent is unregistered (`SMAppService.unregister`) | launchd can no longer respawn a Supervisor that would recreate `K` — and `APP_KEY`, when a delete-everything uninstall took it — on its next boot; the Login Items entry disappears |
| 4 | `runtime/`, `run/`, `~/Library/Caches/<bundle id>/` and `~/Library/Logs/<bundle id>/` are removed — and `data/` unless it is kept (`RuntimeUninstaller`, runtime-layout.md §1) | the file-system half; `data/storage/logs`, a symlink into the removed Logs directory, becomes an empty real directory so a kept tree is self-contained |
| 5 | The menu bar's brief cache is cleared | belt and braces: it lives under Caches |
| 6 | `install/`: **keep** → `checkpoint.json` is rewritten as the reinstall marker (§5); **delete** → the directory and the now-empty Application Support directory go | the marker is what makes a reinstall skip owner creation |
| 7 | The app bundle is moved to the Trash (`NSWorkspace.recycle`, what the Finder does) | last, because the app is still running from it; *Quit* is the person's final click |

Each step is idempotent and each is journaled before the next starts (§6), so the order above is
also the order a retry resumes in.

## 3. What is removed and what remains

`<bundle id>` is `se.stafe.executive-ai.app` until A12 settles (runtime-layout.md §1).

| Item | Where | Keep my data | Delete everything |
|---|---|---|---|
| Core, queue worker, scheduler (the three PHP processes) | children of the Supervisor | stopped | stopped |
| Supervisor launch agent + Login Items entry | launchd, `<prefix>.supervisor` | unregistered | unregistered |
| **Keychain items** — `ipc.menubar`, `email`, `email-sender`, `github`, `ai-provider`, `mcp`, `webpush`, `tunnel`, `backup` (every account under each) | the Supervisor's access group in the data-protection keychain | **deleted** | **deleted** |
| **`core.app-key`** — the one exception, and the one that matters (A25, §5) | same | **kept**: it is the only thing that can read the database you chose to keep | **deleted**, with the data it protected |
| The runtime (`frankenphp`, Caddyfile, php.ini, the Laravel tree, `current`/`previous`) | `~/Library/Application Support/<bundle id>/runtime/` | removed | removed |
| The secrets-broker socket directory | `…/<bundle id>/run/` | removed | removed |
| Caches, including the menu bar's `menubar/latest-brief.json` | `~/Library/Caches/<bundle id>/` | removed | removed |
| Logs (core, worker, scheduler, Supervisor, install events) | `~/Library/Logs/<bundle id>/` | removed | removed |
| Install bookkeeping | `…/<bundle id>/install/checkpoint.json` | rewritten as the reinstall marker (§5) | removed |
| **Your data**: the database (`executive.sqlite`, `-wal`, `-shm`), `storage/app` (files), `storage/framework` (scratch), `backups/` (verified snapshots) | `~/Library/Application Support/<bundle id>/data/` | **kept, byte for byte** | **removed** |
| `~/Library/Application Support/<bundle id>/` itself | | stays (holds `data/` and `install/`) | removed once empty — anything in it that this app did not create is left alone |
| The app | wherever it was opened from | moved to the Trash | moved to the Trash |

Not touched by either choice, on purpose:

- **Time Machine.** Snapshots are Apple's; a verified `data/backups/` snapshot that Time Machine
  copied earlier stays in your backup history until Time Machine expires it. The live database was
  never in Time Machine (runtime-layout.md §4), so there is no stale copy of it to worry about.
- **iCloud Keychain.** Every item is written `kSecAttrSynchronizable = false` (ADR-003 D1), so
  nothing was ever synced to another device; there is nothing to remove elsewhere.
- **Mail already sent, repositories already written to, provider accounts.** Uninstall removes
  the credentials on this Mac; it does not revoke them at the provider. Disconnect accounts in the
  PWA first if you want provider-side revocation (ADR-003 D8 *Disconnect*).
- **The Trash.** Empty it yourself.

## 4. Deleting kept data later, by hand

If you kept your data and later want it gone without reinstalling, it is one directory:

```
~/Library/Application Support/se.stafe.executive-ai.app/
```

Drag it to the Trash in the Finder (⇧⌘G in the Finder, paste the path), or in a Terminal:
`rm -rf ~/Library/Application\ Support/se.stafe.executive-ai.app`. That is the whole of what
"keep my data" left behind on disk: the database, your files, the verified snapshots and the marker.

One Keychain item is still there, on purpose: **`core.app-key`** (§5, A25). Everything else was
removed by the uninstall. Deleting the directory above makes the key useless but does not remove it,
so if you want a clean slate, also open **Keychain Access**, search for
`Laravel Executive AI — core.app-key` and delete it. Do that **after** the directory, not before:
the key is the only thing that can read the encrypted columns, so deleting it first turns a database
you might still want into one nobody can open.

If you dragged the app to the Trash **without** running the uninstaller, the Keychain items are
still there (that is exactly why the uninstaller exists — ADR-003 R6, threat model RR-16). Open
**Keychain Access**, search for `Laravel Executive AI`, and delete every item whose name starts
with `Laravel Executive AI —`; then remove the directories in §3 and the launch agent with
`launchctl bootout gui/$(id -u)/se.stafe.executive-ai.supervisor`. The uninstaller does all of
this without a Terminal; this paragraph exists for the case where it was never run.

## 5. Reinstalling over kept data

Open the app (from the DMG or wherever it was installed again). The installer's preflight sees the
existing `data/` (installer.md §2, check 8) and the checkpoint carries the marker a keep-data
uninstall wrote:

```json
{
  "schema_version": 1,
  "run_id": "…",
  "last_completed_state": null,
  "owner_created": true,
  "uninstalled_preserving_data": { "at": "…", "product_version": "0.1.0", "owner_created": true }
}
```

With no completed state the run starts at `PREFLIGHT`, verifies and installs the runtime again
(the old one is gone), re-links `data/storage/logs`, and **skips `CREATE_OWNER`** because
`owner_created` is true. The first core boot applies any pending migrations to the kept database
and comes up with the previous owner account and the previous briefs; the `install_state` marker
in the database is still `core_ready`. A second owner cannot be created through either path
(threat model T-72): `/install/owner` is a 404 while an owner exists, and `lea:owner:create`
exits 3. That is the whole of what "data-preserving reinstall" means, and it is asserted on both
sides: `UninstallerTests.testReinstallOverPreservedDataSkipsCreateOwner` (the installer state
machine over a kept tree) and `tests/Feature/Install/ReinstallPreservesDataTest.php` (a second
Laravel boot against the same `LEA_DATA_DIR`).

### Your application key is kept with your data, and nothing else is

`K` (`ipc.menubar`) was deleted with every other Keychain item, so the Supervisor generates a fresh
one on its first boot and the menu bar pairs again. **`core.app-key` was not deleted.** It is the
single exception a *Keep my data* uninstall makes, and it exists for one reason: since S2-04 the
database holds §3.1 sensitive attributes **encrypted at rest** — contact email addresses and phone
numbers, the promises extracted from your mail, your assistant's memory, the prompts and answers of
every AI run — and `core.app-key` is the only thing that can read them. Deleting it while keeping
the database would hand you back a database you chose to keep and can no longer open. That is
decision **A25**, taken on 2026-10-11, and it amends ADR-003 D8's wording to "every item except the
application key when data is kept".

**If `core.app-key` is ever lost, those columns cannot be recovered. Not by us, not by anyone.**
There is no backdoor, no escrow copy, no recovery code and no support path: the key is a
32-byte random value in your Mac's data-protection keychain, it is never written to a file, and it
is never synced to iCloud (ADR-003 D1). This is deliberate — a recovery path for you would be a
recovery path for anyone who took your Mac — but it means the key is worth the same care as the
data. In practice:

- **A *Keep my data* uninstall is safe.** The key stays; a reinstall reads everything back. This is
  asserted on both sides: `UninstallerTests.testKeepDataPreservesTheDataDirectoryAndWritesTheReinstallMarker`
  (the sweep leaves exactly `core.app-key` behind) and
  `tests/Feature/Install/ReinstallPreservesDataTest.php` (a second Laravel boot over the same
  `LEA_DATA_DIR` reads the encrypted rows).
- **A *Delete everything* uninstall takes the key and the data together**, which is the point of
  it — `UninstallerTests.testDeleteEverythingLeavesNothingBehind`. Choosing *Delete everything*
  while something else still holds a copy of `data/` (an old Time Machine snapshot, a
  `data/backups/` snapshot you moved elsewhere) leaves that copy permanently unreadable. That is the
  intended outcome, not a bug; if you want the data, keep it, do not delete it and hope.
- **Deleting the kept `data/` by hand later (§4) does not delete the key**, and deleting the key in
  Keychain Access does not delete the data. Either one alone leaves the other useless.
- The app's own §9 encrypted backup uses a separate Keychain item (`backup`), which a keep-data
  uninstall *does* delete — so an exported backup taken before an uninstall is restorable only
  while that key exists. Restoring an encrypted backup across an uninstall is not supported in v1.

If the key and the database do get out of step anyway — a restored snapshot older than a key
rotation, say — the core does not guess. A read of an encrypted attribute that no key in the
envelope can decrypt raises `UnreadableEncryptedAttributeException`, naming the row and the key, so
you get a clear failure rather than empty fields in a brief. Rotating the key deliberately is a
different thing and is safe: the envelope keeps the previous key until a queued job has re-encrypted
every row (ADR-003 D8).

## 6. If it stops half-way

Progress is written to `install/uninstall.json` after every step — step names, the data choice,
how many Keychain items were removed; never a secret, never a path outside the layout, never a
name. Clicking *Uninstall…* again (or *Try again* in the sheet) reads it and continues from the
first step that did not finish. Nothing is done twice: the Supervisor is stopped and swept once,
the agent is unregistered once, the bundle is trashed once (`UninstallerTests.testAnInterrupted
UninstallResumesWithoutRepeatingAStep` interrupts after every step and checks exactly that).
Changing the choice between runs is honoured: the file-system steps run again under the new choice.

| The sheet says | What happened | What to do |
|---|---|---|
| *The Supervisor did not answer. Start the services and try again; nothing has been removed yet.* | the launch agent is enabled but the Supervisor did not reply within 10 s | *Restart services* from the menu bar, then *Try again* |
| *The Keychain items could not be removed. Try again; nothing has been removed yet.* | the Supervisor refused the sweep (a Keychain error) | *Try again*; if it persists, the S1-14 lab notes apply (Keychain Access, §4) |
| *The launch agent could not be unregistered. Try again.* | `SMAppService.unregister` failed; the Keychain is already clean | *Try again* — the sweep is not repeated |
| *… could not be removed. Try again.* | a directory could not be deleted (a file in use, permissions) | close what holds it, *Try again* |
| *The app could not be moved to the Trash. Drag it there yourself; everything else has been removed.* | `NSWorkspace.recycle` failed (a read-only volume, the DMG itself) | quit, then drag the app to the Trash |
| *The Keychain could not be swept because the Supervisor was not running. Search Keychain Access for "Laravel Executive AI" and delete any remaining items.* | the launch agent was **not** enabled (never registered, never approved in Login Items, or disabled there later), so no process held the access group. Uninstall finished; this line is the honest report that the one step it cannot do alone was skipped | follow §4's Keychain Access paragraph — in the common case (never approved) there are no items to find, because the Supervisor that writes them never ran |

A `uninstall.json` with a schema version this build does not know is ignored, never trusted.

## 7. The Keychain promise, precisely

ADR-003 D8 promises that uninstall deletes every item except the application key when data is kept,
because macOS deletes nothing by itself. What ships:

- The inventory is fixed in code (`SecretService.inventory`, services.md §6) and is independent
  of the bundle prefix, so A12 never leaves an item behind under an old name.
- The sweep runs **inside the Supervisor** (`SupervisorSecrets.removeAll(keepApplicationKey:)`
  behind `removeAllSecrets(keepApplicationKey:)` on the XPC surface), the one process with the
  access group — the app never gains a Keychain entitlement for this (ADR-003 D2 stands). It
  enumerates every account under every service and deletes them one by one; a missing item is not
  an error.
- **The one exception is matched against the inventory's own `SecretService.appKey` constant**, not
  against a spelled-out name, so if A12 moves the prefix the exception moves with it. It spares
  every account under that service — a rotated-out key sitting beside the current one is kept too,
  because the envelope's `previous[]` may still be what reads an un-re-encrypted row (A25,
  ADR-003 D8).
- The flag's default is `false`. A caller that says nothing gets ADR-003 D8's original sweep, so the
  only way to keep the key is for the uninstaller to ask for it on the strength of the person's
  data choice.
- The result travels back as service and account names only (the wire form is tested never to
  carry a value), and the sheet shows the count — of what was actually removed, so a spared key is
  not counted as deleted.
- It runs **before** the agent is unregistered (§2) and before anything on disk is touched, so a
  failure there stops the uninstall with everything still in place.
- Changing your mind between runs is handled: a keep-data run that was interrupted and retried as
  *Delete everything* sweeps again with the flag off, so the key the first sweep spared is taken
  after all — as long as the agent is still registered, which is the only state in which anything
  can reach the access group.
- What CI proves: the in-memory Keychain double holds exactly `core.app-key` after a keep-data
  sweep and nothing of ours after a delete-everything one, a foreign item is untouched in both
  cases, and the kept key is still the same envelope the first boot wrote
  (`UninstallerTests.testKeepDataPreservesTheDataDirectoryAndWritesTheReinstallMarker`,
  `UninstallerTests.testDeleteEverythingLeavesNothingBehind`,
  `UninstallerTests.testChangingTheDataChoiceBeforeUnregisteringSweepsTheApplicationKeyAfterAll`,
  `SupervisorSecretsTests.testRemoveAllSparesOnlyTheApplicationKeyWhenDataIsKept`,
  `SupervisorControlServiceTests.testRemoveAllSecretsSparesTheApplicationKeyWhenDataIsKept`). What
  only the lab proves: the data-protection keychain from a signed Supervisor (§9).

## 8. FileVault and Time Machine

- **FileVault** changes nothing about uninstall; the directories in §3 are inside the encrypted
  volume like everything else and are deleted the same way. A kept `data/` stays FileVault-
  protected as long as the volume is.
- **Time Machine** keeps whatever it already copied of `data/backups/` and `data/storage/app/`
  (the live database and `storage/framework` were excluded with `tmutil addexclusion`,
  runtime-layout.md §4). Deleting everything on the Mac does not reach into those snapshots; if
  that matters, remove the directory from your Time Machine history too (*Enter Time Machine* →
  the directory → *Delete All Backups of…*).
- A **kept** `data/` is picked up by Time Machine's next run as before (the exclusions are
  item-level and sticky; a reinstall re-applies them anyway).

## 9. What S1-14 verifies on the lab Mac (signed build, clean machine)

1. Install, create the owner, let a brief exist. Settings → Uninstall → **Delete everything**.
   Then: `ls ~/Library/Application\ Support/se.stafe.executive-ai.app ~/Library/Caches/se.stafe.executive-ai.app ~/Library/Logs/se.stafe.executive-ai.app`
   all report *No such file or directory*; `launchctl print gui/$(id -u)/se.stafe.executive-ai.supervisor`
   reports *Could not find service*; System Settings › General › Login Items has no entry; Keychain
   Access finds no `Laravel Executive AI` item; the app is in the Trash and nothing else of it is
   on disk (`mdfind -name LaravelExecutiveAI`).
2. Install again, create the owner, let a brief exist, and add a contact with an email address and
   a phone number so there is an encrypted row to lose. Settings → Uninstall → **Keep my data**.
   Then: only `data/` and `install/checkpoint.json` (the marker) remain under Application Support;
   Keychain Access finds **exactly one** item, `Laravel Executive AI — core.app-key`, and no other
   (A25, §7). Open the app from the DMG again: no *Create account* screen; the menu bar shows the
   previous brief; `/install/owner` is a 404; **and the contact still shows its address and phone
   number**, which is the half only a signed build on a real data-protection keychain can prove —
   CI proves the sweep against an in-memory double, the lab proves the key really survived.
3. During an uninstall, `kill -9` the app after the Login Items entry disappears. Open the app
   again → Settings → Uninstall → the same choice: it finishes, and the unified log shows no second
   `removeAllSecrets`.
4. Record the longest step. The whole uninstall is expected well under the §12.2 install budget;
   anything over 30 s is a finding.
