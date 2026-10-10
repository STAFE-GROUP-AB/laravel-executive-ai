<?php

declare(strict_types=1);

use App\Briefs\DeliveryChannel;
use App\Briefs\Render\BriefMailable;
use App\Briefs\Render\BriefMailRenderer;
use App\Briefs\Render\BriefMessageId;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Tests\Support\BriefFixtures;

/*
 * The Mailable the Sprint 2 delivery job will send: subject, both parts,
 * the deterministic Message-ID (brief schema §2, data-model §2) and no
 * List-Unsubscribe. Built through the `preview` mailer (array transport):
 * nothing leaves the process.
 */

beforeEach(function (): void {
    URL::forceRootUrl('http://localhost:8000');
});

it('derives the Message-ID from idempotency key, channel and recipient exactly like the fixture', function (): void {
    $document = BriefFixtures::load('canonical');
    $sent = collect($document['deliveries'])->firstWhere('status', 'sent');
    $emailSent = collect($document['deliveries'])->where('channel', 'email')->firstWhere('status', 'sent');

    expect($sent)->not->toBeNull()
        ->and(BriefMessageId::for($document['idempotency_key'], DeliveryChannel::Email, $emailSent['recipient']))
        ->toBe($emailSent['message_id'])
        ->and(BriefMessageId::for($document['idempotency_key'], DeliveryChannel::Email, 'Andreas@Example.SE'))
        ->toBe($emailSent['message_id'])
        ->and(BriefMessageId::bare($document['idempotency_key'], DeliveryChannel::Email, $emailSent['recipient']))
        ->toBe(trim($emailSent['message_id'], '<>'))
        ->and(BriefMessageId::for('01M4FJ7ZJRDVPBT5PEZTZWC6BX', DeliveryChannel::Email, 'other@example.se'))
        ->not->toBe($emailSent['message_id']);
});

it('reproduces the Message-ID of every fixture delivery that was sent by email', function (string $name): void {
    $document = BriefFixtures::load($name);
    $sentByEmail = array_filter(
        $document['deliveries'],
        static fn (array $delivery): bool => $delivery['channel'] === 'email' && $delivery['status'] === 'sent',
    );

    foreach ($sentByEmail as $delivery) {
        expect(BriefMessageId::for($document['idempotency_key'], DeliveryChannel::Email, $delivery['recipient']))
            ->toBe($delivery['message_id']);
    }

    // Fixtures with a queued, failed or skipped email delivery have nothing to reproduce; say so explicitly.
    expect(count($sentByEmail))->toBeLessThanOrEqual(1);
})->with(BriefFixtures::dataset());

it('builds a message with both parts, the brief subject and the deterministic Message-ID through the preview mailer', function (): void {
    $document = BriefFixtures::load('canonical');
    $rendered = app(BriefMailRenderer::class)->render($document);
    $mailable = new BriefMailable($rendered, $document['idempotency_key'], 'andreas@example.se');

    $mailer = Mail::mailer('preview');
    $transport = $mailer->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class);
    assert($transport instanceof ArrayTransport);

    $mailer->to('andreas@example.se')->send($mailable);

    $messages = $transport->messages();
    expect($messages)->toHaveCount(1);

    $email = $messages->first()->getOriginalMessage();
    assert($email instanceof Email);

    expect($email->getSubject())->toBe($document['subject'])
        ->and($email->getHtmlBody())->toBe($rendered->html)
        ->and($email->getTextBody())->toBe($rendered->text)
        ->and($email->getHeaders()->get('Message-ID')?->getBodyAsString())->toBe($mailable->deliveryMessageId())
        ->and($mailable->deliveryMessageId())->toBe('<01M4FJ7ZJRDVPBT5PEZTZWC6BX.email.andreas=example.se@laravel-executive-ai.local>')
        ->and($email->getHeaders()->has('List-Unsubscribe'))->toBeFalse()
        ->and($email->getHeaders()->has('List-Unsubscribe-Post'))->toBeFalse()
        ->and($email->getHeaders()->has('Precedence'))->toBeFalse()
        ->and($mailable->locale)->toBe('sv');
});

it('renders the same html through the mailable as the renderer', function (): void {
    $document = BriefFixtures::load('multilingual-it');
    $rendered = app(BriefMailRenderer::class)->render($document);

    $html = (new BriefMailable($rendered, $document['idempotency_key'], 'andreas@example.it'))->render();

    expect((string) $html)->toBe($rendered->html);
});

it('honours a configured Message-ID domain', function (): void {
    config(['mail.message_id_domain' => 'brief.example.test']);

    expect(BriefMessageId::for('01M4FJ7ZJRDVPBT5PEZTZWC6BX', DeliveryChannel::Email, 'owner@example.se'))
        ->toBe('<01M4FJ7ZJRDVPBT5PEZTZWC6BX.email.owner=example.se@brief.example.test>');
});
