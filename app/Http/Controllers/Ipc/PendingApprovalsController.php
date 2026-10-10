<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ipc;

use App\Http\Controllers\Controller;
use App\Security\Authorization\OwnerAbilities;
use App\Security\Ipc\IpcBody;
use App\Security\Ipc\IpcRequest;
use App\Security\Ipc\IpcResponder;
use App\Support\Health\PendingApproval;
use App\Support\Health\PendingApprovals;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /ipc/v1/approvals?state=pending (IPC contract §6.3): the read-only
 * list with PWA deep links. There is no approve, reject or cancel operation
 * over IPC, with or without elevation (§11.2); `open_url` is where the owner
 * re-authenticates and decides. Gate: `viewApprovals`.
 */
final class PendingApprovalsController extends Controller
{
    private const string RFC3339 = 'Y-m-d\TH:i:sP';

    public function __invoke(Request $request, PendingApprovals $approvals, IpcResponder $responder): Response
    {
        $query = IpcBody::validateQuery($request, [
            'state' => ['required', 'string', 'in:pending'],
            'limit' => ['integer', 'min:1', 'max:50'],
            'cursor' => ['string', 'regex:/^[A-Za-z0-9_-]{1,128}$/'],
        ]);

        IpcRequest::principal($request)->authorize(OwnerAbilities::VIEW_APPROVALS);

        $page = $approvals->page((int) ($query['limit'] ?? 20), isset($query['cursor']) ? (string) $query['cursor'] : null);
        $timezone = (string) config('app.timezone', 'UTC');
        $origin = IpcResponder::originUrl();

        return $responder->success($request, [
            'items' => array_map(static fn (PendingApproval $item): array => [
                'id' => $item->id,
                'kind' => $item->kind,
                'title' => $item->title,
                'summary' => $item->summary,
                'created_at' => $item->createdAt->setTimezone($timezone)->format(self::RFC3339),
                'expires_at' => $item->expiresAt->setTimezone($timezone)->format(self::RFC3339),
                'brief_item_id' => $item->briefItemId,
                'open_url' => "{$origin}/approvals/{$item->id}",
            ], $page->items),
            'total_pending' => max(0, $approvals->count()),
            'next_cursor' => $page->nextCursor,
        ]);
    }
}
