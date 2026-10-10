Install-state marker, TunnelProvider contract, first-run and reinstall support (§12, §8).

`InstallState` (`not_installed`, `installing`, `core_ready`, `ai_ready`) is persisted in the
`install_state` table by `InstallStateStore`; `php artisan lea:install-state [state] [--reason=]
[--json]` reads or records a transition. The installer (S1-05) drives it through the Supervisor;
`/health/ready` never reads it (health contract §9). `php artisan lea:brief:placeholder` creates the
install-time placeholder brief that `brief_placeholder` requires.
