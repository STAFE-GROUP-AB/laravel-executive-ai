{{ config('app.name') }} — your sign-in code

Hello {{ $recipientName }}, enter this code on the sign-in page:

    {{ $code }}

It expires in {{ $ttlMinutes }} minutes and works once.

If you did not try to sign in, ignore this message. Nobody can use the code without access to this inbox.
