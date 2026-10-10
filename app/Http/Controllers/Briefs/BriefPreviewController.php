<?php

declare(strict_types=1);

namespace App\Http\Controllers\Briefs;

use App\Briefs\Render\BriefMailRenderer;
use App\Http\Controllers\Controller;
use App\Models\Brief;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Local preview of the brief mail (§1.3 "lokalt preview-läge utan utgående
 * mejl", §16 step 8 "Preview exact email"). Returns the exact HTML or
 * plaintext part BriefMailRenderer produces for the stored brief.
 *
 * Preview-only by construction: this controller depends on the renderer
 * alone. There is no Mailer, no Mailable and no transport in reach, so no
 * configuration mistake can turn a preview into a send (§5.5).
 * tests/Feature/Briefs/Mail/PreviewCannotSendTest.php boots the app with a
 * transport whose send() throws and renders every fixture through here.
 */
final class BriefPreviewController extends Controller
{
    public function __construct(private readonly BriefMailRenderer $renderer) {}

    public function html(Brief $brief): Response
    {
        Gate::authorize('view', $brief);

        $rendered = $this->renderer->renderBrief($brief);

        return $this->respond($rendered->html, 'text/html; charset=UTF-8')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    }

    public function text(Brief $brief): Response
    {
        Gate::authorize('view', $brief);

        $rendered = $this->renderer->renderBrief($brief);

        return $this->respond($rendered->text, 'text/plain; charset=UTF-8');
    }

    private function respond(string $body, string $contentType): Response
    {
        return new Response($body, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'no-referrer',
            'X-LEA-Brief-Preview' => 'nothing-sent',
        ]);
    }
}
