# The PolicyGate — every rule, its inputs, its failure mode and its test

**Status:** Living document · **Owner:** Workstream H (security/privacy) with D (AI)
**Issues:** #89 (S2-07, this page) · #85 (the tables it reads) · #88 (the row policies it composes
with) · #92 (S2-08, the approvals engine that writes what it reads) · #96 (S2-14, the egress guard)
**Source of truth:** `app/Approvals/Policies/`, `app/Approvals/Policies/Rules/`
**Spec:** blueprint §4, §7, §11, §16.7 · ADR-004 **D2** (the gate inside `SdkToolBridge::handle()`,
contract matrix row 3) · ADR-005 **D1.5–D1.8**, **D4.8**, **D4.10** ·
[authorization.md](authorization.md) §9 · threat model **T-01**, **T-05**, **T-06**, **T-46**

## 1. The rule

> **The LLM generates proposals, never its own permissions.**

Blueprint §4 is the sentence the whole product rests on, and `App\Approvals\Policies\PolicyGate` is
where it stops being a sentence. Nothing a model proposed executes until the gate has validated
seven things about it — tool name, input schema, org/account/scope, data class, destination, budget,
approval — and recorded what it decided.

Two things it is **not**:

- **It is not the row policy.** S2-06's `ToolAuthorizer` answers *"may this principal touch this
  row"* with the dispatching user's memberships. The gate answers *"may this action be performed at
  all, and does it need a human"*. Both must pass, and neither substitutes for the other
  ([authorization.md §9](authorization.md#9-what-this-does-not-cover)).
- **It is not the approvals engine.** The gate **reads** `action_approvals`; it never writes one and
  never spends a nonce. Creating and consuming approvals is S2-08, from a re-authenticated UI action
  (ADR-005 D1.6: "session grants and approvals are never issued from a prompt or a tool result").

## 2. The parts

| Part | What it is |
|---|---|
| `PolicyGate` | the seven checks, in order, plus the frozen-payload binding ahead of them |
| `ProposedAction` | what the model proposed, frozen: tool name, arguments, destinations, data class, and the hash of the payload at freeze time |
| `ToolCallContext` | the scope the call is decided **inside** — organization, account, trace, actor, data class. Built by the server; nothing the model emits reaches it |
| `PolicyVerdict` | the decision, **bound to the payload it was made about** (`binds()`), carrying the §11 approval evidence on an allow |
| `PolicyReason` | the frozen reason vocabulary, written to `tool_runs.policy_reason` and `audit_events.context` |
| `PolicyRefusal` + eight subclasses | one `PolicyDeniedException` subclass per check |
| `ApprovalLedger` | the gate's only database reads: the proposal, its approval, the run's spent budget. **No write method** |
| `PolicyJournal` | the two rows every decision leaves: `tool_runs` and `audit_events` |
| `Rules/ActionRule` + `Rules/RuleBook` | one rule per action, indexed by tool name |
| `Rules/ArgumentSchema`, `DestinationRule`, `ActionBudget` | the declaration types a rule is written in |

### 2.1 Why the action and the context are two objects

The split is the whole of §4. The **action** is the model's: tool name and arguments. The **context**
is the server's: the organization comes from the authenticated principal, the account from the
connection the run was started on, the trace from the `ai_runs` row. A model that wants another
organization's data can say so in the action; it cannot say so in the context, because nothing it
emits reaches that object. The gate compares the two, which is how §5.6 criterion 4 — "account A can
never read or send through account B" — is a check rather than a convention.

## 3. How a rule is matched to an action

**By tool name, in `RuleBook`, and by nothing else.** One lookup in one table. Not a chain of `if`s
in the callers, not the tool's description, not what the model said it wanted.

A rule then *declares* the other six inputs as data. `ActionRule`'s methods take **no parameters** —
there is nowhere for model text to enter a declaration — and `PolicyGate` runs the checks over what
they return. The consequence is the one ADR-005 D4.10 needs for E3-5 and E3-6: a new action is a
**rule**, a class in `Rules/` and a line in `RuleBook::__construct()`, not a new code path.

`PolicyGateFailsClosedTest` enumerates `Rules/` and fails if a rule class is not registered, so a
rule nobody wired up cannot sit there looking like protection.

## 4. What happens when none matches

The call is refused.

- reason `no_rule_matched`, exception `NoRuleMatchedException`, rule name on the record `none`;
- `tool_runs.policy_decision = denied`, `policy_reason = none:no_rule_matched`;
- an `audit_events` row with `outcome: refused` and `context.rule = none`.

There is no default-allow anywhere in `PolicyGate::evaluate()`: every branch either returns a
refusal or falls through, and the single `PolicyVerdict::allow…` call is the last statement.
`PolicyGateFailsClosedTest` asserts that structurally — it counts the returns and checks that every
refusal appears in the source *before* the allow, so an allow inserted higher up fails CI.

Two tool names the §16.7 corpus proposes will **never** get a rule: `settings.write` and
`approvals.grant`. There is no tool that widens the agent's own authority, and an absence cannot be
edited into permission the way a permissive rule could. `ProposalKind::GithubWrite` and
`ProposalKind::ExternalForm` have no rule either, for a duller reason — their tool names are not
fixed yet — and are refused until one names them.

## 5. The seven inputs

| # | §4 input | Declared by | Refused when | Reason | Exception |
|---|---|---|---|---|---|
| 1 | tool name | `ActionRule::toolNames()` | no rule claims the tool | `no_rule_matched` | `NoRuleMatchedException` |
| 2 | input schema | `ArgumentSchema` | a required argument is missing, one is mistyped, or one is **not declared at all** | `invalid_tool_input` | `InvalidToolInputException` |
| 3 | org / account / scope | `ActionRule::requiresAccountScope()` | the action names another organization; or the rule needs an account and the action names none or another | `scope_violation`, `account_scope_missing` | `ScopeViolationException` |
| 4 | data class | `ActionRule::dataClasses()` | the action declares none, or one the rule does not permit | `data_class_missing`, `data_class_not_allowed` | `DataClassViolationException` |
| 5 | destination | `DestinationRule` | wrong shape, outside a declared set, or **not in the frozen payload** | `destination_not_allowed` | `DestinationNotAllowedException` |
| 6 | budget | `ActionBudget` | calls per run, canonical payload size or destination count exceeded; or the rule declares **no** budget | `budget_exceeded`, `budget_missing` | `BudgetViolationException` |
| 7 | approval | `ActionRule::requiresApproval()` | no `action_approvals` row authorises it: absent, spent, expired, or the proposal expired or is terminal | `approval_missing`, `approval_consumed`, `approval_expired`, `proposal_expired`, `proposal_not_pending` | `ApprovalRequiredException` |
| 0 | — | the proposal itself | the payload is not the one that was frozen and hashed | `payload_not_frozen` | `PayloadNotFrozenException` |

Three of those deserve the explanation:

**An undeclared argument is a refusal (2).** The model writes the arguments, and a gate that ignored
unknown keys would let it attach a field some later executor happens to read — `reply_to`, `bcc`,
`force` — without any rule having permitted it. An allowlist of names is the only shape that closes
that. It is also what refuses the T-05 identity spoof: `from` is not a field `OutgoingEmailRule`
declares, so a proposed From is `unexpected:from` before any address is compared.

**A missing data class is a refusal (4).** There is no "unclassified" default. An action whose
author never decided what data it touches is refused rather than quietly treated as `internal`.

**A missing budget is a refusal (6).** `ActionRule::budget()` may return null, and the gate reads
that as `budget_missing`. A rule author who omits the bound gets a refusal they will notice, not an
action with no ceiling.

### 5.1 The destination check is not an egress guard

`DestinationRule`'s strongest condition is `mustBeInFrozenPayload`: every destination must already
appear in the payload the approver read. A recipient that arrived after the freeze — from a mail body,
or from a second model turn — is therefore not in the payload and is refused. That is
`recipient_added` and `from_address_changed` from the §16.7 effect vocabulary, as a check.

Comparison is per kind, and the asymmetry is deliberate: an **email** destination is folded to lower
case, because a domain is case-insensitive and a recipient differing only in case is the same
recipient the approver read; a **bundle id** is compared unfolded, because `com.apple.Safari` is the
identifier Apple publishes and the ADR-005 D1.5 allowlist is written that way. `PolicyGateFailsClosedTest`
asserts the positive half of this as well as the refusals — a check that refuses everything proves
nothing, and folding one side of an exact-match comparison would have refused the one app the product
permits while looking like security.

Whether a socket may be opened to a host *at all* — DNS rebinding, link-local ranges, the
`LOCAL_ONLY` tier, ADR-005 D4.9's approved host set — is `App\Security\Egress` (S2-14) and runs as
well as this, not instead of it. The gate deliberately ships **no** `DestinationKind` for HTTP hosts:
a kind nothing declares would be untested code on the fail-closed path, and
`PolicyGateFailsClosedTest` asserts every kind is declared by some rule.

## 6. The frozen-payload binding

**A decision is about a frozen payload, not about a request.** Enforced three times.

1. **Before the checks.** `ProposedAction::hashMatchesFrozen()` compares the hash that was frozen
   against the hash of the content now. It runs first, ahead of all seven inputs: a decision about
   content nobody froze is not a decision at all.
2. **Against the row.** When the action names an `action_proposals` row, the gate compares
   `proposal_hash` to the content *and* calls `ActionProposal::hashMatchesPayload()` — so a raw
   `UPDATE` around the model is caught even when the action's own two hashes agree with each other
   (because the action was built from the edited payload).
3. **At execution.** `PolicyVerdict` carries the hash it was issued for, and
   `PolicyGate::assertStillBound()` is what an executor calls immediately before it acts. A payload
   changed between the allow and the act invalidates the allow instead of riding on it.

The hash is `sha256:` over the RFC 8785 canonical form (`App\Support\Json\Jcs`), the same definition
`ActionProposal::hashPayload()` uses for the row and the brief uses for `content_hash`, so a hash
computed in the gate and one computed from the database are the same bytes.

## 7. Expiry, replay, and why the gate cannot bypass the index

An expired proposal cannot be approved (`proposal_expired`); an expired approval authorises nothing
(`approval_expired`); a spent nonce authorises nothing (`approval_consumed`). The gate checks all
three, and `ActionApproval::authorises()` — the model's own statement of the same rule — is asked
last, so the gate and the row cannot drift apart.

The replay guarantee itself is **not** the gate's. `action_approvals` is unique on both
`action_proposal_id` and `nonce`, so a replayed approval is *unrecordable* rather than merely
detectable. What S2-07 adds is the proof that the gate has no path around those indexes:

- `ApprovalLedger` has no write method at all;
- `PolicyIsNotPromptTest` scans every file in `app/Approvals/Policies` for a write to
  `action_approvals` — a `create`, a `new ActionApproval`, a `consumed_at` assignment — and fails on
  any of them;
- `PolicyGateTest` then attempts both replays directly (a second approval for one proposal, a reused
  nonce on another) and asserts the database refuses them.

A gate that could record an approval could record one for a spent nonce — or become the path by
which a model-elicited "approval" came into existence. It cannot do either.

## 8. Every decision is recorded

Allow and refuse alike, in two rows that answer different questions.

**`tool_runs`** answers *"did the gate run on this call"*. `policy_decision` is not nullable, so a
tool invocation that was never put to the gate has no representable row (§4, and S2-02's migration
says so in its own docblock). The row is written before anything could execute:

| | allow | refuse |
|---|---|---|
| `policy_decision` | `allowed` | `denied` |
| `outcome` | `running` — the executor finishes the row | `denied` |
| `policy_reason` | `<rule>:allowed` | `<rule>:<reason>` |
| `action_proposal_id` | the proposal, when the gate loaded one | null unless the row was loaded |

An allow whose executor then crashed leaves a `running` row, which is the honest record of what
happened. `action_proposal_id` is only ever written from a **loaded** row: an unverified id from the
model would break the foreign key and turn a recorded refusal into an unrecorded 500.

**`audit_events`** answers *"which rule decided, and why"* — through `App\Security\Audit\AuditLog`,
the one writer of that table, rather than a second audit path. Action is `policy.<tool>` (as a row
policy's is `authz.<ability>`), and the context is exactly five reference keys:

```
{rule, tool, surface, result, reason}
```

No argument value can reach it: the reason is a `PolicyReason` enum value, the rule and tool are
names, and `PolicyRefusal` builds its message from those three alone. `AuditCompletenessTest` asserts
both — the row exists per decision and names the rule, and nothing in the context quotes a recipient
or a body. `tool_runs.arguments_redacted` keeps argument **names** plus the payload hash, because
"which fields were proposed" is what an incident review needs and cannot leak content.

## 9. The rules the product ships

| Tool | Rule | Kind | Approval | Account | Destination | Budget (calls / bytes / destinations) |
|---|---|---|---|---|---|---|
| `email.send` | `OutgoingEmailRule` | `outgoing_email` | required | required | recipients, from the frozen payload | 1 / 65536 / 20 |
| `crm.delete` | `CrmDeletionRule` | `crm_deletion` | required | — | none | 1 / 4096 / 0 |
| `automation.execute` | `UiActionRule` | `ui_action` | required | — | app bundle ids, ADR-005 D1.5 allowlist | 25 / 8192 / 1 |
| `crm.search`, `tasks.list`, `brief.read` | `ReadOnlyQueryRule` | — | **not** required | — | none | 8 / 2048 / 0 |

`ReadOnlyQueryRule` is the only rule that does not require an approval, and the reason it is safe to
say so is narrow: a read reaches no destination, freezes no proposal and changes no row, so
"nothing was executed" and "the action happened" are the same outcome for the outside world.
Approval exists to put a human between the model and an irreversible effect; a query has none. The
row policy still decides whether this user may read those rows.

`UiActionRule`'s declarations are ADR-005 D1 as data: the schema names D1.6's frozen target columns
and takes a `value_hash` rather than a value; the destination is D1.5's single v1 allowlist entry
(`com.apple.Safari`), which is the **PHP half** of D1.5's "two independent checks, both fail closed"
— the Swift helper runs its own, and `browser_sessions.allowlist` is what the owner widens one app at
a time; `maxCallsPerRun: 25` is D1.8's per-session action cap, counted from `tool_runs` so a retried
job cannot reset it.

## 10. The tool bridge (ADR-004 D2, matrix row 3)

ADR-004 D2 puts the gate inside `SdkToolBridge::handle()`, before the wrapped tool runs, and matrix
row 3 requires that a denial produce a tool result the model can read with nothing executed.

S2-13 had made `shouldRequestApproval()` return `Approval::required()` for **every** tool
unconditionally and `handle()` throw — deliberately stricter than the ADR sketched, because there was
no gate yet to decide. S2-07 moves the decision from that blanket to a rule a human wrote, and every
path stays closed:

| case | before S2-07 | after |
|---|---|---|
| no rule claims the tool | approval required; nothing runs | **approval required; nothing runs** |
| a mutating rule claims it | approval required; nothing runs | approval required; nothing runs |
| a read-only rule claims it | approval required; nothing runs | the loop reaches `handle()`, which puts the proposal to the gate and returns the verdict — still nothing runs |
| `withoutApproval()` | throws | throws |

`handle()` executes nothing on either branch, and that is structural rather than promised: an
`AiToolDefinition` **carries no executor** (the DTO says so itself), so there is no wrapped body for
the bridge to call, and the result always ends `executed=none`. An allowed call therefore comes back
in the ADR-005 D4.10 shape — the decision exists, the model is told so, acting on it is the approvals
engine's job in another process.

A bridge built without a `ToolCallContext` (which is every bridge in Sprint 2, because no
orchestrator supplies one yet — S2-15/E2-4 does) cannot identify a scope, and answers that the way
everything else here answers an unknown: `handle()` refuses with `scope_violation` and nothing runs.
The absent collaborator is a closed door, not an open one.

## 11. Policy is code, never prompt (ADR-005 D4.8)

`PolicyIsNotPromptTest` reads the source, with comments stripped, and fails when:

- any class in the decision path — the gate, the verdict, the action, the context, the ledger, the
  journal, the rule book, or **any rule** — names a tool description, `instructions`, an `AiMessage`,
  an `AiResponse`, an `AiToolDefinition`, an `AiToolCall`, `outputText` or anything under
  `Laravel\Ai`;
- any `ActionRule` method takes a parameter, which would give model text somewhere to enter;
- `ProposedAction` grows a field named `description`, `prompt`, `text`, `message` or `reasoning`;
- a call in `SdkToolBridge::handle()` appears **ahead of** `PolicyGate::decide()`, or the body names
  an execution verb at all;
- `shouldRequestApproval()` reads anything off the `$request`;
- any class in the decision path reads `request()` or `auth()`;
- the tool result departs from its fixed shape, or contains a recipient, a subject or a body.

## 12. The tests

| Test | Proves | Profile |
|---|---|---|
| `tests/Security/PolicyGateTest.php` | the mechanism: an allow needs a frozen proposal **and** a matching approval; the verdict carries exactly the §11 evidence the S0-16 DTOs require; a payload edited after the decision invalidates it; a row edited around the model is caught; expiry; a spent nonce, and that the unique indexes cannot be routed around; both rows written per decision; no customer content in either; a hostile run from the frozen corpus refused with nothing executed; a second send in one run refused on the budget | `local` |
| `tests/Security/PolicyGateFailsClosedTest.php` | each of the seven inputs refused on its own, naming the rule and raising its own `PolicyDeniedException` subclass; the binding refused ahead of all of them; every refusal recorded; `Rules/` fully registered; no allow before all seven checks; every `PolicyReason` mapped to a refusal; every `DestinationKind` declared by some rule | `local` |
| `tests/Security/PolicyIsNotPromptTest.php` | §11 above | `local` |
| `tests/Security/AuditCompletenessTest.php` | the gate's rows alongside the five authorization surfaces, through the same `AuditLog` writer — one row per decision, naming actor, rule, reason and surface, context references only | `local` |
| `tests/Security/Support/PolicyGatedAgentRun.php` | the `AgentRunUnderTest` adapter for the half the gate answers. **Not bound**: S0-21's gate also needs the brief half, which is S2-08/E2-4 | — |

## 13. Adding an action

1. Write a rule class in `app/Approvals/Policies/Rules/`. Declare all seven: tool names, kind, tool
   kind, schema, data classes, destination, budget, and the two booleans.
2. Register it in `RuleBook::__construct()`. `PolicyGateFailsClosedTest` fails until you do.
3. If it is mutating, it needs `requiresApproval(): true` and S2-08 has to be able to freeze an
   `action_proposals` row for it. ADR-005 D4.10's forward constraint for MCP is exactly this: a
   mutating MCP tool creates a proposal and returns "pending approval" instead of acting, which is
   what `PolicyVerdict::toToolResult()` already says.
4. Add a row to §9 and a line to §5's table if the rule introduces a new reason.
5. Implement the tool as a `GatedTool` (S2-06) so the row policy decides too. The two layers are
   independent and both run.

## 14. What this does not cover

- **The approvals engine** — freezing a proposal, the `/approvals` screen, re-authentication,
  consuming the nonce and executing — is **S2-08** (#92). The gate reads what that writes.
- **The egress guard** — whether a socket may open at all — is **S2-14** (#96), under
  `app/Security/Egress/`. See §5.1.
- **The orchestrator** that supplies a `ToolCallContext` and composes a brief is **S2-15 / E2-4**.
  Until it exists, every bridged tool call is refused for want of a scope.
- **Re-authentication** (`App\Security\Authorization\Reauthentication`, S2-05) is a separate
  requirement. A critical action needs the policy, the gate **and** the proof; the gate reads
  `action_approvals.reauth_at` as evidence but does not verify the proof itself.
