<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pwa;

use App\Briefs\BriefDocumentMapper;
use App\Briefs\Render\BriefCatalog;
use App\Briefs\Render\BriefPage;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Health\LatestBrief;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * /home — the executive dashboard (§1.2): the latest brief with its fresh,
 * stale or error state, the AI state banner and the pending approvals count.
 */
final class HomeController extends Controller
{
    public function __invoke(
        Request $request,
        BriefCatalog $catalog,
        LatestBrief $latestBrief,
        BriefDocumentMapper $mapper,
    ): View {
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
