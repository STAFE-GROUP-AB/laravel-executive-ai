<?php

declare(strict_types=1);

use App\Briefs\Render\BriefMailRenderer;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Contracts\Mail\MailQueue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\PendingMail;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BriefFixtures;
use Tests\Support\FailingMailTransport;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

/*
 * S1-03 acceptance: "Preview mode ... provably cannot dispatch outgoing mail —
 * asserted by a test using a failing mail transport." Blueprint §1.3 (local
 * preview without outgoing mail) and §5.5 (no mail before recipient and
 * sender account are confirmed). Threat model T-01/T-05: the preview surface
 * is not a send path.
 */

uses(DatabaseTransactions::class);

function bootWithFailingTransport(): void
{
    FailingMailTransport::reset();

    config([
        'mail.mailers.failing' => ['transport' => 'failing'],
        'mail.default' => 'failing',
    ]);

    Mail::extend('failing', static fn (): FailingMailTransport => new FailingMailTransport);
}

/** @return array{0: User, 1: Organization} */
function previewOwner(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->owner()->for($organization)->for($user)->create();

    return [$user, $organization];
}

it('renders every fixture preview over http while the only mail transport throws on send', function (): void {
    bootWithFailingTransport();
    [$user, $organization] = previewOwner();
    actingAs($user);

    foreach (array_keys(BriefFixtures::all()) as $name) {
        $brief = BriefFixtures::import($name, $organization);

        get("/briefs/{$brief->id}/preview/mail")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('X-LEA-Brief-Preview', 'nothing-sent')
            ->assertSee($brief->subject);

        $text = get("/briefs/{$brief->id}/preview/text")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        // The plaintext part soft-wraps long subjects at 76 columns.
        expect(preg_replace('/\s+/u', ' ', (string) $text))->toContain($brief->subject);
    }

    expect(FailingMailTransport::$attempts)->toBe(0)
        ->and(app()->resolved('mailer'))->toBeFalse('the preview path resolved a mailer');
});

it('writes preview files from the console while the only mail transport throws on send', function (): void {
    bootWithFailingTransport();
    $out = storage_path('framework/testing/brief-preview-'.uniqid());

    try {
        foreach (array_keys(BriefFixtures::all()) as $name) {
            artisan('lea:brief:preview', ['--fixture' => BriefFixtures::path($name), '--out' => $out])
                ->expectsOutputToContain('Nothing was sent.')
                ->assertSuccessful();
        }

        expect(File::glob("{$out}/*.html"))->toHaveCount(11)
            ->and(File::glob("{$out}/*.txt"))->toHaveCount(11)
            ->and(File::glob("{$out}/*.parity.json"))->toHaveCount(11)
            ->and(FailingMailTransport::$attempts)->toBe(0)
            ->and(app()->resolved('mailer'))->toBeFalse();
    } finally {
        File::deleteDirectory($out);
    }
});

it('renders the latest stored brief from the console when no argument is given', function (): void {
    bootWithFailingTransport();
    [, $organization] = previewOwner();
    $brief = BriefFixtures::import('canonical', $organization);
    $out = storage_path('framework/testing/brief-preview-'.uniqid());

    try {
        artisan('lea:brief:preview', ['--out' => $out, '--format' => 'text'])->assertSuccessful();
        artisan('lea:brief:preview', ['brief' => $brief->document_id, '--out' => $out, '--format' => 'html'])->assertSuccessful();

        expect(File::exists("{$out}/brief-{$brief->document_id}-v1.txt"))->toBeTrue()
            ->and(File::exists("{$out}/brief-{$brief->document_id}-v1.html"))->toBeTrue()
            ->and(FailingMailTransport::$attempts)->toBe(0);
    } finally {
        File::deleteDirectory($out);
    }
});

it('rejects an unknown --format', function (): void {
    artisan('lea:brief:preview', ['--fixture' => BriefFixtures::path('canonical'), '--format' => 'pdf'])
        ->assertFailed();
});

it('sends and queues nothing across all fixtures under Mail::fake', function (): void {
    Mail::fake();
    [$user, $organization] = previewOwner();
    actingAs($user);

    foreach (array_keys(BriefFixtures::all()) as $name) {
        $brief = BriefFixtures::import($name, $organization);

        get("/briefs/{$brief->id}/preview/mail")->assertOk();
        get("/briefs/{$brief->id}/preview/text")->assertOk();
        app(BriefMailRenderer::class)->render(BriefFixtures::load($name));
    }

    Mail::assertNothingOutgoing();
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('never references a mailer from the renderer namespace or the preview surfaces', function (): void {
    expect(['App\Briefs\Render', 'App\Http\Controllers\Briefs', 'App\Console\Commands\BriefPreviewCommand'])
        ->not->toUse([
            Mail::class,
            Mailer::class,
            MailManager::class,
            Illuminate\Contracts\Mail\Mailer::class,
            Factory::class,
            MailQueue::class,
            PendingMail::class,
            SentMessage::class,
        ]);
});

it('contains no send or queue call in the preview code path', function (): void {
    $files = [
        app_path('Briefs/Render/BriefMailRenderer.php'),
        app_path('Briefs/Render/PlaintextBriefRenderer.php'),
        app_path('Briefs/Render/BriefViewModel.php'),
        app_path('Briefs/Render/BriefParityPayload.php'),
        app_path('Briefs/Render/RenderedBrief.php'),
        app_path('Http/Controllers/Briefs/BriefPreviewController.php'),
        app_path('Console/Commands/BriefPreviewCommand.php'),
        base_path('routes/briefs.php'),
    ];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        expect(preg_match('/->(send|sendNow|queue|later|dispatch)\s*\(/', $source))->toBe(0, basename($file).' contains a send/queue call')
            ->and(str_contains($source, 'Mail::'))->toBeFalse(basename($file).' uses the Mail facade');
    }
});
