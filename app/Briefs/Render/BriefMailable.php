<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use App\Briefs\DeliveryChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

/**
 * The brief as a Laravel Mailable: pre-rendered HTML and plaintext parts
 * from BriefMailRenderer, subject = brief subject, a deterministic
 * Message-ID from (idempotency_key, email, recipient) and nothing else.
 * No List-Unsubscribe (this is the owner's own brief, not a bulk mail),
 * no tracking, no remote assets.
 *
 * Sprint 1 never sends it: the only callers are the preview surfaces, which
 * read $rendered directly, and tests that build the message through the
 * `preview` (array) mailer. Sending is the Sprint 2 delivery job behind the
 * §5.5 consent gate and a confirmed sender account (ADR-009).
 */
final class BriefMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly RenderedBrief $rendered,
        public readonly string $idempotencyKey,
        public readonly string $recipient,
    ) {
        $this->locale = $rendered->locale;
    }

    public function envelope(): Envelope
    {
        $text = $this->rendered->text;

        return new Envelope(
            subject: $this->rendered->subject,
            using: [
                static function (Email $message) use ($text): void {
                    $message->text($text, 'utf-8');
                },
            ],
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->rendered->html);
    }

    public function headers(): Headers
    {
        return new Headers(messageId: $this->messageId());
    }

    /** RFC 5322 form with angle brackets, as recorded on brief_deliveries.message_id. */
    public function deliveryMessageId(): string
    {
        return BriefMessageId::for($this->idempotencyKey, DeliveryChannel::Email, $this->recipient);
    }

    private function messageId(): string
    {
        return BriefMessageId::bare($this->idempotencyKey, DeliveryChannel::Email, $this->recipient);
    }
}
