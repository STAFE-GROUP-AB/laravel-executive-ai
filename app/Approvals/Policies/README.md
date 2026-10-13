The server-side `PolicyGate` (§4, §11): tool name, input schema, org/account/scope, data class,
destination, budget and approval, decided before anything executes. Rules live in `Rules/` and are
**data about an action**, not code; a tool with no rule is refused. The full map — every rule, its
inputs, its failure mode and the test that proves it — is
[`docs/architecture/policy-gate.md`](../../../docs/architecture/policy-gate.md).
