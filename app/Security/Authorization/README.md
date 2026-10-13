Policies and gates applied to every HTTP action, Livewire action, job, MCP tool, AI tool and integration call; IPC auth middleware (§11).

S1-02 (docs/architecture/auth.md): `CreateOwner` (the §12.1 CREATE_OWNER step, one owner ever), `Otp/` (one-time-code issue and verify — the fallback and recovery credential, and the only one on an origin where WebAuthn cannot run) and `SessionRevoker` (server-side revocation over the `sessions` table; still the only code that deletes a session row).

S2-05 (auth.md §5.1, §7, issue #69): `Passkeys/` (`WebAuthnAuthenticator` over web-auth/webauthn-lib, one relying party per ADR-006 D3 origin, the challenge held in the session for one ceremony), `Devices/` (the device list and labels, revoking through `SessionRevoker`) and `Reauthentication/` (`Reauthenticator` — the PWA-side proof of presence for the T-71 critical actions, bound to an action, a single-use nonce and a time window; S2-08, S2-14 and S2-19 call `challenge()`/`assert()`).

S1-08 (docs/architecture/ipc-server.md): `OwnerAbilities` defines the named abilities of the IPC contract's §6 table (`viewBrief`, `viewApprovals`, `pauseAi`, `resumeAi`, `completeOAuth`, `viewRemoteAccess`, `enableRemoteAccess`, `disableRemoteAccess`) once, so the PWA and the IPC (`app/Security/Ipc/`) run the same gate as the owner.
