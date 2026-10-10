<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

use App\Browser\ComputerUse\Exceptions\AutomationDeniedException;
use App\Browser\ComputerUse\Exceptions\AutomationUnavailableException;
use App\Support\Cancellation\CancellationToken;
use App\Support\Cancellation\OperationCancelledException;

/**
 * Blueprint §3, third contract: `ComputerUseProvider` with `capabilities()`,
 * `observe()` and `execute()`; blueprint §6; ADR-005 D1–D3. The PHP core talks only
 * to the signed automation helper over authenticated local IPC; it never spawns
 * Peekaboo itself.
 *
 * Deliberate differences from the §3 sketch (see docs/architecture/module-contracts.md):
 * `capabilities()` returns a typed DTO instead of `array`; `observe()` takes an
 * ObservationRequest naming the target; `observe()` and `execute()` take a
 * CancellationToken; `stop()` is added because the panic stop (§6, ADR-005 D1.9) must
 * be part of the contract, not a convention.
 *
 * Authority model: the model proposes a UiAction; the PolicyGate freezes it as an
 * `action_proposals` row; a human approves it; only then does `App\Approvals` build
 * the AuthorizedUiAction this method accepts. Nothing here can be reached with a
 * bare UiAction. Everything observed from the screen is data, never instruction.
 */
interface ComputerUseProvider
{
    /**
     * Never throws for a missing helper or permission: it reports `available: false`
     * so the UI can explain, and the rest of the product keeps working (§6).
     */
    public function capabilities(): AutomationCapabilities;

    /**
     * Capture the allowlisted target window after redaction. Covered by the session
     * grant; counts toward the session's idle timeout, not its action budget.
     *
     * @throws AutomationDeniedException target not allowlisted, session not live
     * @throws AutomationUnavailableException helper, permission or locked-session problem
     * @throws OperationCancelledException
     */
    public function observe(
        AutomationSession $session,
        ObservationRequest $request,
        CancellationToken $cancellation,
    ): Observation;

    /**
     * Perform one approved action. Expected outcomes (helper-side denial, timeout,
     * cancellation) come back as an ActionResult; only PHP-side policy refusals and
     * environmental failures throw.
     *
     * @throws AutomationDeniedException approval expired, nonce reused, hash mismatch, target not allowlisted
     * @throws AutomationUnavailableException
     * @throws OperationCancelledException
     */
    public function execute(AuthorizedUiAction $action, CancellationToken $cancellation): ActionResult;

    /**
     * Panic stop: kill the current child process, release held keys and buttons,
     * mark the session `aborted`, revoke its grant, write the audit event. Must
     * complete within one second and must not throw when the session is already
     * stopped.
     */
    public function stop(AutomationSession $session, string $reason): void;
}
