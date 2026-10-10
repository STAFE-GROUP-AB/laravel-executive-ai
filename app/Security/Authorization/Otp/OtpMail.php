<?php

declare(strict_types=1);

namespace App\Security\Authorization\Otp;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

/**
 * The one place the plaintext code leaves the process (auth.md §3). Sent
 * synchronously and deliberately not ShouldQueue: a queued mailable would
 * serialise the code into the jobs table. HTML and plaintext parts use the
 * S0-24 mail-safe tokens; the body carries the code, its expiry and nothing
 * else about the account. The subject never contains the code, since
 * subjects end up in notification previews and server logs.
 */
final class OtpMail extends Mailable
{
    public function __construct(
        public readonly string $recipientName,
        #[SensitiveParameter] public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your sign-in code for '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(html: 'mail.auth.otp', text: 'mail.auth.otp-text');
    }
}
