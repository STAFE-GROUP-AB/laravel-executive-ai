<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use App\Briefs\DeliveryChannel;

/**
 * Deterministic mail Message-ID (brief schema §2 "Idempotent delivery",
 * data-model §2): derived from the brief version's idempotency_key, the
 * channel and the recipient, so a retry that reaches the provider twice is
 * deduplicated downstream. The canonical fixture shows the form:
 *
 *   <01M4FJ7ZJRDVPBT5PEZTZWC6BX.email.andreas=example.se@laravel-executive-ai.local>
 */
final class BriefMessageId
{
    public const string DEFAULT_DOMAIN = 'laravel-executive-ai.local';

    /** The RFC 5322 form with angle brackets, as stored on brief_deliveries.message_id. */
    public static function for(string $idempotencyKey, DeliveryChannel $channel, string $recipient, ?string $domain = null): string
    {
        return '<'.self::bare($idempotencyKey, $channel, $recipient, $domain).'>';
    }

    /** The id without angle brackets, as Symfony's IdentificationHeader expects it. */
    public static function bare(string $idempotencyKey, DeliveryChannel $channel, string $recipient, ?string $domain = null): string
    {
        $domain ??= self::domain();

        return "{$idempotencyKey}.{$channel->value}.".self::localPart($recipient).'@'.$domain;
    }

    public static function domain(): string
    {
        $configured = config('mail.message_id_domain');

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_DOMAIN;
    }

    private static function localPart(string $recipient): string
    {
        $encoded = str_replace('@', '=', mb_strtolower(trim($recipient)));

        $sanitised = preg_replace('/[^a-z0-9.=_+:-]/', '-', $encoded);

        return $sanitised === null || $sanitised === '' ? 'recipient' : $sanitised;
    }
}
