<?php

declare(strict_types=1);

namespace App\Briefs\Render;

use App\Support\Design\MailTokens;

/**
 * The complete inline-style vocabulary of resources/views/mail/brief.blade.php.
 *
 * Every `style=""` in the mail template is one entry of all(); nothing is
 * written ad hoc in Blade. The entries are MailTokens::styles() (S0-24)
 * plus a few layout roles the brief needs, composed strictly from MailTokens
 * constants: tests/Feature/Briefs/Mail/MailStyleAllowlistTest.php proves
 * that every colour, size and font in the extras is a token value and that
 * the rendered HTML uses nothing outside all().
 */
final class BriefMailStyles
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [...MailTokens::styles(), ...self::extras()];
    }

    public static function style(string $role): string
    {
        $styles = self::all();

        if (! array_key_exists($role, $styles)) {
            throw new \InvalidArgumentException("Unknown brief mail style role `{$role}`.");
        }

        return $styles[$role];
    }

    /** @return array<string, string> */
    public static function extras(): array
    {
        $sans = MailTokens::FONT_SANS;

        return [
            'table' => 'width:100%; border-collapse:collapse; border-spacing:0;',
            'canvas-cell' => 'padding:'.MailTokens::SPACE_6.'px '.MailTokens::SPACE_3.'px; background-color:'.MailTokens::COLOR_CANVAS.';',
            'gap' => 'height:'.MailTokens::SPACE_4.'px; line-height:'.MailTokens::SPACE_4.'px; font-size:'.MailTokens::TEXT_XS.'px;',
            'section-heading-cell' => 'padding:'.MailTokens::SPACE_2.'px 0 '.MailTokens::SPACE_3.'px;',
            'h2-section' => "margin:0; font-family:{$sans}; font-size:".MailTokens::TEXT_LG.'px; line-height:'.MailTokens::LEADING_LG.'px; font-weight:600; color:'.MailTokens::COLOR_TEXT_PRIMARY.';',
            'h3' => 'margin:0 0 '.MailTokens::SPACE_2."px; font-family:{$sans}; font-size:".MailTokens::TEXT_MD.'px; line-height:'.MailTokens::LEADING_MD.'px; font-weight:600; color:'.MailTokens::COLOR_TEXT_PRIMARY.';',
            'item-cell' => 'padding:0 0 '.MailTokens::SPACE_3.'px;',
            'badge-row' => 'margin:0 0 '.MailTokens::SPACE_2."px; font-family:{$sans}; font-size:".MailTokens::TEXT_XS.'px; line-height:'.MailTokens::LEADING_SM.'px;',
            'badge-neutral' => 'display:inline-block; padding:2px '.MailTokens::SPACE_2.'px; border-radius:'.MailTokens::RADIUS_SM.'px; background-color:'.MailTokens::COLOR_SURFACE_SUNKEN.'; color:'.MailTokens::COLOR_TEXT_SECONDARY."; font-family:{$sans}; font-size:".MailTokens::TEXT_XS.'px; line-height:'.MailTokens::LEADING_XS.'px; font-weight:500;',
            'meta' => 'margin:0 0 '.MailTokens::SPACE_1."px; font-family:{$sans}; font-size:".MailTokens::TEXT_SM.'px; line-height:'.MailTokens::LEADING_SM.'px; color:'.MailTokens::COLOR_TEXT_SECONDARY.';',
            'source-list' => 'margin:0 0 '.MailTokens::SPACE_3.'px; padding:0 0 0 '.MailTokens::SPACE_4."px; font-family:{$sans}; font-size:".MailTokens::TEXT_SM.'px; line-height:'.MailTokens::LEADING_SM.'px; color:'.MailTokens::COLOR_TEXT_SECONDARY.';',
            'source-item' => 'margin:0 0 '.MailTokens::SPACE_1.'px;',
            'callout-warning' => 'margin:0 0 '.MailTokens::SPACE_3.'px; padding:'.MailTokens::SPACE_3.'px; border-radius:'.MailTokens::RADIUS_SM.'px; background-color:'.MailTokens::COLOR_WARNING_SURFACE."; font-family:{$sans}; font-size:".MailTokens::TEXT_SM.'px; line-height:'.MailTokens::LEADING_SM.'px; color:'.MailTokens::COLOR_WARNING_TEXT.';',
            'cta-cell' => 'padding:'.MailTokens::SPACE_4.'px 0 '.MailTokens::SPACE_6.'px; text-align:center;',
            'footer-cell' => 'padding:0 '.MailTokens::SPACE_4.'px '.MailTokens::SPACE_6.'px; text-align:center;',
            'footer' => 'margin:0 0 '.MailTokens::SPACE_1."px; font-family:{$sans}; font-size:".MailTokens::TEXT_XS.'px; line-height:'.MailTokens::LEADING_XS.'px; color:'.MailTokens::COLOR_TEXT_MUTED.'; text-align:center;',
        ];
    }

    /** The badge role for a priority; colour is never the only signal, the label is always printed. */
    public static function priorityBadge(string $priority): string
    {
        return match ($priority) {
            'critical' => 'badge-danger',
            'high' => 'badge-warning',
            'normal' => 'badge-info',
            default => 'badge-neutral',
        };
    }
}
