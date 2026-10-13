<?php

declare(strict_types=1);

namespace Tests\Security\Support\Surfaces;

use App\Models\CRM\Customer;
use App\Security\Authorization\Gates\Abilities;
use App\Security\Authorization\Gates\GatedTool;
use Illuminate\Database\Eloquent\Model;

/**
 * A tool in the shape `GatedTool` requires: it names the ability it needs and
 * the row it intends to touch, and `ToolAuthorizer` decides before it runs
 * (blueprint §4, threat model T-73's "an MCP tool" and "an agent").
 *
 * The arguments are the model's, so `subject()` resolves a customer **by the
 * id the model proposed** — which is exactly the IDOR attempt the policy has
 * to refuse, and what `AuditCompletenessTest` exercises on the `tool` surface.
 * The application has no tools yet; this is the first consumer of the contract,
 * so the contract is tested rather than merely declared.
 */
final class CustomerNotesTool implements GatedTool
{
    public function name(): string
    {
        return 'crm.customer.update_notes';
    }

    public function ability(): string
    {
        return Abilities::UPDATE;
    }

    /** @param array<string, mixed> $arguments */
    public function subject(array $arguments): Model|string|null
    {
        $id = $arguments['customer_id'] ?? null;

        // No id proposed: the subject is the class, so the ability is answered
        // against the table rather than against a row — the `create` and
        // `viewAny` shape, which `BusinessObjectPolicy` also handles.
        return is_string($id) ? Customer::query()->find($id) : Customer::class;
    }
}
