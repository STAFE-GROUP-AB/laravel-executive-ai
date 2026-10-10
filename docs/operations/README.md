Install measurement protocol, release policy, uninstall, backup/restore, recovery.

| Document | Covers |
|---|---|
| `install-measurement.md` | §12.2 install gate protocol: clean-machine definition, what core-ready means, human prompts, async steps reported separately, 30-run requirement, how to submit (S0-20). |
| `release-policy.md` | Commits, versioning, branching, tagging, changelog, release checklist, update channel (S0-28). |
| `release-build.md` | The CI-built Mac disk image (`build-macos` workflow): unsigned vs. Developer-ID debug-signed modes, how to download and verify it on a clean Mac with Gatekeeper on, what "debug-signed" means, why no runtime is bundled yet (A23), what is still missing before notarization (§14, Sprint 4 E4-6) (S1-12). |
| `uninstall.md` | Settings → Uninstall: the seven-step order, exactly what is removed and what "keep my data" leaves where, the Keychain promise (ADR-003 D8), resumable retries, reinstalling over kept data, deleting kept data by hand, FileVault/Time Machine notes, the S1-14 lab checklist (S1-13). |
