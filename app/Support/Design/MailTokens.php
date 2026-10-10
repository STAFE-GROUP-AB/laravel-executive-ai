<?php

declare(strict_types=1);

namespace App\Support\Design;

/**
 * Mail-safe design token subset (S0-24, blueprint §1.3).
 *
 * HTML mail templates under resources/views/mail/ MUST use these literal values as inline
 * styles and nothing else: no CSS custom properties, no box-shadow, no web fonts, no
 * flexbox/grid, no background images. Everything here renders consistently in Apple Mail
 * (macOS/iOS), Gmail (web/iOS/Android) and HEY. Light theme only: mail clients that
 * auto-invert are told not to via <meta name="color-scheme" content="light">.
 *
 * Values mirror the light theme in resources/css/tokens.css; change the CSS first.
 *
 *   <td style="{{ MailTokens::style('body') }}">
 *   <h1 style="{{ MailTokens::style('h1') }}">{{ $brief->subject }}</h1>
 *   <a href="…" style="{{ MailTokens::style('button') }}">Open full brief</a>
 */
final class MailTokens
{
    public const string FONT_SANS = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    public const string FONT_MONO = "Menlo, Consolas, 'Courier New', monospace";

    public const string COLOR_CANVAS = '#f4f4f2';

    public const string COLOR_SURFACE = '#ffffff';

    public const string COLOR_SURFACE_SUNKEN = '#ebebe8';

    public const string COLOR_TEXT_PRIMARY = '#15181c';

    public const string COLOR_TEXT_SECONDARY = '#4a4f57';

    public const string COLOR_TEXT_MUTED = '#60666f';

    public const string COLOR_TEXT_ON_ACCENT = '#ffffff';

    public const string COLOR_BORDER = '#e1e2df';

    public const string COLOR_ACCENT = '#0f5f70';

    public const string COLOR_ACCENT_SUBTLE = '#e3f1f3';

    public const string COLOR_ACCENT_TEXT = '#0b4d5b';

    public const string COLOR_SUCCESS_SURFACE = '#e4f3ea';

    public const string COLOR_SUCCESS_TEXT = '#135c33';

    public const string COLOR_WARNING_SURFACE = '#fbf1dc';

    public const string COLOR_WARNING_TEXT = '#704800';

    public const string COLOR_DANGER_SURFACE = '#fbe7e5';

    public const string COLOR_DANGER_TEXT = '#8f1c14';

    public const string COLOR_INFO_SURFACE = '#e6eefa';

    public const string COLOR_INFO_TEXT = '#1f4790';

    public const int TEXT_XS = 12;

    public const int TEXT_SM = 14;

    public const int TEXT_MD = 16;

    public const int TEXT_LG = 19;

    public const int TEXT_XL = 23;

    public const int TEXT_2XL = 28;

    public const int LEADING_XS = 16;

    public const int LEADING_SM = 20;

    public const int LEADING_MD = 24;

    public const int LEADING_LG = 26;

    public const int LEADING_XL = 30;

    public const int LEADING_2XL = 34;

    public const int SPACE_1 = 4;

    public const int SPACE_2 = 8;

    public const int SPACE_3 = 12;

    public const int SPACE_4 = 16;

    public const int SPACE_6 = 24;

    public const int SPACE_8 = 32;

    public const int RADIUS_SM = 6;

    public const int RADIUS_MD = 8;

    public const int CONTENT_WIDTH = 600;

    public const int BUTTON_MIN_HEIGHT = 44;

    /**
     * Prebuilt inline style strings for the roles every mail template needs.
     *
     * @return array<string, string>
     */
    public static function styles(): array
    {
        $sans = self::FONT_SANS;

        return [
            'body' => 'margin:0; padding:0; background-color:'.self::COLOR_CANVAS."; font-family:{$sans}; color:".self::COLOR_TEXT_PRIMARY.'; -webkit-text-size-adjust:100%;',
            'container' => 'width:100%; max-width:'.self::CONTENT_WIDTH.'px; margin:0 auto;',
            'card' => 'background-color:'.self::COLOR_SURFACE.'; border:1px solid '.self::COLOR_BORDER.'; border-radius:'.self::RADIUS_MD.'px; padding:'.self::SPACE_6.'px;',
            'h1' => 'margin:0 0 '.self::SPACE_3."px; font-family:{$sans}; font-size:".self::TEXT_2XL.'px; line-height:'.self::LEADING_2XL.'px; font-weight:600; letter-spacing:-0.01em; color:'.self::COLOR_TEXT_PRIMARY.';',
            'h2' => 'margin:'.self::SPACE_6.'px 0 '.self::SPACE_2."px; font-family:{$sans}; font-size:".self::TEXT_LG.'px; line-height:'.self::LEADING_LG.'px; font-weight:600; color:'.self::COLOR_TEXT_PRIMARY.';',
            'p' => 'margin:0 0 '.self::SPACE_3."px; font-family:{$sans}; font-size:".self::TEXT_MD.'px; line-height:'.self::LEADING_MD.'px; color:'.self::COLOR_TEXT_PRIMARY.';',
            'secondary' => "margin:0; font-family:{$sans}; font-size:".self::TEXT_SM.'px; line-height:'.self::LEADING_SM.'px; color:'.self::COLOR_TEXT_SECONDARY.';',
            'muted' => "margin:0; font-family:{$sans}; font-size:".self::TEXT_XS.'px; line-height:'.self::LEADING_XS.'px; color:'.self::COLOR_TEXT_MUTED.';',
            'label' => 'margin:0 0 '.self::SPACE_1."px; font-family:{$sans}; font-size:".self::TEXT_XS.'px; line-height:'.self::LEADING_XS.'px; font-weight:500; letter-spacing:0.04em; text-transform:uppercase; color:'.self::COLOR_TEXT_MUTED.';',
            'link' => 'color:'.self::COLOR_ACCENT.'; text-decoration:underline;',
            'button' => 'display:inline-block; min-height:'.self::BUTTON_MIN_HEIGHT.'px; padding:'.self::SPACE_3.'px '.self::SPACE_6.'px; box-sizing:border-box; background-color:'.self::COLOR_ACCENT.'; color:'.self::COLOR_TEXT_ON_ACCENT."; font-family:{$sans}; font-size:".self::TEXT_MD.'px; line-height:'.(self::BUTTON_MIN_HEIGHT - 2 * self::SPACE_3).'px; font-weight:500; text-decoration:none; border-radius:'.self::RADIUS_MD.'px;',
            'divider' => 'border:0; border-top:1px solid '.self::COLOR_BORDER.'; margin:'.self::SPACE_6.'px 0;',
            'code' => 'font-family:'.self::FONT_MONO.'; font-size:'.self::TEXT_SM.'px; background-color:'.self::COLOR_SURFACE_SUNKEN.'; padding:2px 6px; border-radius:'.self::RADIUS_SM.'px;',
            'badge-success' => self::badge(self::COLOR_SUCCESS_SURFACE, self::COLOR_SUCCESS_TEXT),
            'badge-warning' => self::badge(self::COLOR_WARNING_SURFACE, self::COLOR_WARNING_TEXT),
            'badge-danger' => self::badge(self::COLOR_DANGER_SURFACE, self::COLOR_DANGER_TEXT),
            'badge-info' => self::badge(self::COLOR_INFO_SURFACE, self::COLOR_INFO_TEXT),
            'badge-accent' => self::badge(self::COLOR_ACCENT_SUBTLE, self::COLOR_ACCENT_TEXT),
        ];
    }

    public static function style(string $role): string
    {
        $styles = self::styles();

        if (! array_key_exists($role, $styles)) {
            throw new \InvalidArgumentException("Unknown mail style role `{$role}`.");
        }

        return $styles[$role];
    }

    private static function badge(string $background, string $text): string
    {
        return 'display:inline-block; padding:2px '.self::SPACE_2.'px; border-radius:'.self::RADIUS_SM.'px; background-color:'.$background.'; color:'.$text.'; font-family:'.self::FONT_SANS.'; font-size:'.self::TEXT_XS.'px; line-height:'.self::LEADING_XS.'px; font-weight:500;';
    }
}
