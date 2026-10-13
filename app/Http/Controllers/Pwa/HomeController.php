<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\Briefs\BriefDocumentMapper;
use App\Briefs\Render\BriefCatalog;
use App\Briefs\Render\BriefPage;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Security\Authorization\Gates\Abilities;
use App\Support\Health\LatestBrief;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * /home — the executive dashboard (§1.2): the latest brief with its fresh,
 * stale or error state, the AI state banner and the pending approvals count.
 *
 * `auth` proves who the visitor is; `viewHome` proves they belong to an
 * organization at all (S2-06). The brief itself is then scoped by
 * `BriefCatalog::latestFor()`, the query form of `BriefPolicy`.
 */
final class HomeController extends Controller
{
    public function __invoke(
        Request $request,
        BriefCatalog $catalog,
        LatestBrief $latestBrief,
        BriefDocumentMapper $mapper,
    ): View {
        Gate::authorize(Abilities::VIEW_HOME);

        /** @var User $user */
        $user = $request->user();

        $brief = $catalog->latestFor($user);
        $now = CarbonImmutable::now();

        return view('home.index', [
            'page' => $brief === null ? null : BriefPage::fromBrief($brief, $mapper, $now),
            'briefState' => $latestBrief->state($brief, $now),
        ]);
    }
}
