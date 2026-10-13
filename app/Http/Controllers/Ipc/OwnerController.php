<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ipc;

use App\Http\Controllers\Controller;
use App\Security\Authorization\CreateOwner;
use App\Security\Authorization\OwnerAlreadyExistsException;
use App\Security\Authorization\OwnerDetails;
use App\Security\Ipc\IpcBody;
use App\Security\Ipc\IpcException;
use App\Security\Ipc\IpcRequest;
use App\Security\Ipc\IpcResponder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /ipc/v1/owner (IPC contract §6.9): the installer's CREATE_OWNER step
 * (§12.1, installer.md screen 4) over the paired `menubar` session, so the
 * person never sees a Terminal. The work itself is `CreateOwner` — the same
 * action the artisan command and the in-app Livewire form call (auth.md §2),
 * one organization, one user, one owner membership, in one transaction
 * behind `Cache::lock('lea:create-owner')`.
 *
 * This is the one operation with **no owner-bound gate**, because it runs
 * before an owner exists; `Middleware\IpcRequireNoOwner` is its
 * authorization and refuses with `409` / `owner_exists` from the moment one
 * does. `CreateOwner`'s check inside the lock is the backstop for the race
 * and lands on the same refusal (threat model T-72).
 *
 * The answer is `{"created": true}` and nothing else: no token, no one-time
 * code, no user, organization or membership id, not even the address that
 * was submitted. The installer needs one fact, so one fact is what crosses
 * the wire (§6.9, §11.4).
 */
final class OwnerController extends Controller
{
    public function __invoke(Request $request, CreateOwner $createOwner, IpcResponder $responder): Response
    {
        $body = IpcBody::validate($request, [
            // `regex:/\S/` so a whitespace-only name cannot leave the
            // organization nameless when `organization` is omitted.
            'name' => ['required', 'string', 'max:200', 'regex:/\S/'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'organization' => ['sometimes', 'string', 'max:200'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'language' => ['sometimes', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/'],
        ]);

        $principal = IpcRequest::principal($request);

        try {
            $createOwner->create($this->details($body), 'job:IpcCreateOwner', [
                ...$principal->context(),
                'request_id' => IpcRequest::requestId($request),
            ]);
        } catch (OwnerAlreadyExistsException) {
            // Lost the race inside the lock: the same refusal the guard gives.
            throw IpcException::ownerExists();
        }

        return $responder->success($request, ['created' => true]);
    }

    /**
     * Screen 4 asks for a name and an address only, so the three remaining
     * fields are optional on the wire: the Mac sends the zone and the
     * language it is running in, and the workspace is named after its owner
     * until they rename it. No field is ever defaulted from request content
     * that could assert an identity (§11.4).
     *
     * @param  array<string, mixed>  $body
     */
    private function details(array $body): OwnerDetails
    {
        $name = (string) $body['name'];

        return new OwnerDetails(
            name: $name,
            email: (string) $body['email'],
            organizationName: isset($body['organization']) ? (string) $body['organization'] : trim($name),
            timezone: isset($body['timezone']) ? (string) $body['timezone'] : (string) config('app.timezone', 'UTC'),
            language: isset($body['language']) ? (string) $body['language'] : (string) config('app.locale', 'en'),
        );
    }
}
