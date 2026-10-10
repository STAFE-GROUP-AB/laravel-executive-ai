@php
    use App\Support\Design\MailTokens;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Your sign-in code</title>
</head>
<body style="{{ MailTokens::style('body') }}">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ MailTokens::style('body') }}">
        <tr>
            <td align="center" style="padding:{{ MailTokens::SPACE_8 }}px {{ MailTokens::SPACE_4 }}px;">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ MailTokens::style('container') }}">
                    <tr>
                        <td style="{{ MailTokens::style('card') }}">
                            <p style="{{ MailTokens::style('label') }}">{{ config('app.name') }}</p>
                            <h1 style="{{ MailTokens::style('h1') }}">Your sign-in code</h1>
                            <p style="{{ MailTokens::style('p') }}">Hello {{ $recipientName }}, enter this code on the sign-in page:</p>
                            <p style="margin:{{ MailTokens::SPACE_4 }}px 0 {{ MailTokens::SPACE_4 }}px; font-family:{{ MailTokens::FONT_MONO }}; font-size:{{ MailTokens::TEXT_2XL }}px; line-height:{{ MailTokens::LEADING_2XL }}px; font-weight:600; letter-spacing:0.12em; color:{{ MailTokens::COLOR_TEXT_PRIMARY }}; background-color:{{ MailTokens::COLOR_SURFACE_SUNKEN }}; border-radius:{{ MailTokens::RADIUS_MD }}px; padding:{{ MailTokens::SPACE_3 }}px {{ MailTokens::SPACE_4 }}px; text-align:center;">{{ $code }}</p>
                            <p style="{{ MailTokens::style('secondary') }}">It expires in {{ $ttlMinutes }} minutes and works once.</p>
                            <hr style="{{ MailTokens::style('divider') }}">
                            <p style="{{ MailTokens::style('muted') }}">If you did not try to sign in, ignore this message. Nobody can use the code without access to this inbox.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
