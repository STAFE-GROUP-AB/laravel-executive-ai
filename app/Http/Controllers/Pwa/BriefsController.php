<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\Briefs\BriefDocumentMapper;
use App\Briefs\Render\BriefCatalog;
use App\Briefs\Render\BriefPage;
use App\Http\Controllers\Controller;
use App\Models\Brief;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * /briefs — version history (§1.2) and the server-rendered brief itself,
 * built from the parity payload like the mail (brief-parity.md). Every
 * response is network-only in the service worker; nothing here is ever
 * cached on the client (§1.2, ADR-006 D3).
 */
final class BriefsController extends Controller
{
    public function __construct(
        private readonly BriefCatalog $catalog,
        private readonly BriefDocumentMapper $mapper,
    ) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('briefs.index', [
            'briefs' => $this->catalog->versionsFor($user),
            'now' => CarbonImmutable::now(),
        ]);
    }

    public function show(string $brief): View
    {
        $page = BriefPage::fromBrief($this->authorized($brief), $this->mapper, CarbonImmutable::now());

        return view('briefs.show', ['page' => $page]);
    }

    /**
     * The parity payload this page was rendered from, as RFC 8785 canonical
     * JSON, for the S1-10 tri-surface comparison.
     */
    public function parity(string $brief): Response
    {
        $page = BriefPage::fromBrief($this->authorized($brief), $this->mapper, CarbonImmutable::now());

        return new Response($page->parityJson(), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-LEA-Parity-Hash' => $page->parityHash(),
        ]);
    }

    private function authorized(string $key): Brief
    {
        $brief = $this->catalog->resolve($key);

        if ($brief === null) {
            abort(404);
        }

        Gate::authorize('view', $brief);

        return $brief;
    }
}
