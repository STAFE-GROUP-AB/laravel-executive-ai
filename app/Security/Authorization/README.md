Policies and gates applied to every HTTP action, Livewire action, job, MCP tool, AI tool and integration call; IPC auth middleware (§11).

S1-02 (docs/architecture/auth.md): `CreateOwner` (the §12.1 CREATE_OWNER step, one owner ever), `Otp/` (one-time-code issue and verify — the only login credential), `SessionRevoker` (server-side revocation over the `sessions` table) and `Passkeys/` (the interface plus the Sprint 1 `NotYetAvailable` stub, replaced by E2-3 #69).
