<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ipc;

use App\Briefs\BriefDocumentMapper;
use App\Briefs\DeliveryState;
use App\Http\Controllers\Controller;
use App\Models\Brief;
use App\Security\Authorization\OwnerAbilities;
use App\Security\Ipc\IpcException;
use App\Security\Ipc\IpcRequest;
use App\Security\Ipc\IpcResponder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /ipc/v1/briefs/latest (IPC contract §6.2): the latest brief whose
 * delivery_state is frozen or later, as a full brief.schema.json document,
 * with `ETag: "<id>:<version>"` and a signed 304 on If-None-Match. Gate:
 * `viewBrief` as the owner.
 */
final class LatestBriefController extends Controller
{
    public function __invoke(Request $request, BriefDocumentMapper $mapper, IpcResponder $responder): Response
    {
        $brief = Brief::query()
            ->where('delivery_state', '!=', DeliveryState::Draft->value)
            ->latestGenerated()
            ->first();

        if ($brief === null) {
            throw IpcException::notFound();
        }

        IpcRequest::principal($request)->authorize(OwnerAbilities::VIEW_BRIEF, [$brief]);

        $etag = "\"{$brief->document_id}:{$brief->version}\"";

        if ($request->headers->get('If-None-Match') === $etag) {
            return $responder->notModified($request, $etag);
        }

        return $responder->success($request, $mapper->export($brief))->header('ETag', $etag);
    }
}
