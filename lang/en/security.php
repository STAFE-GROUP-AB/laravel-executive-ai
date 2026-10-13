<?php

declare(strict_types=1);

/*
 * Passkeys, devices and re-authentication copy (S2-05,
 * docs/architecture/auth.md §5, §7). Nothing here may suggest a password
 * exists: the two credentials are a passkey and the emailed one-time code,
 * and the code is always the fallback and the recovery path.
 */
return [
    'settings' => [
        'title' => 'Sign-in and devices',
        'nav' => 'Sign-in and devices',
        'lead' => 'Add a passkey for a faster sign-in and see every device that is signed in. The emailed one-time code always works, so losing a device never locks you out.',
    ],

    'passkeys' => [
        'heading' => 'Passkeys',
        'lead' => 'A passkey signs you in with Touch ID, Face ID or your device unlock instead of opening the mail. It is stored on the device, never on this Mac, and it is an addition to the emailed code rather than a replacement.',
        'sign_in' => 'Sign in with a passkey',
        'sign_in_hint' => 'Your device will ask for Touch ID, Face ID or your unlock. Prefer the code? It is above.',
        'add' => 'Add a passkey',
        'adding' => 'Waiting for your device…',
        'name_label' => 'Name for this passkey (optional)',
        'default_name' => 'Passkey added :date',
        'none' => 'No passkey is registered yet. Sign-in uses the emailed code.',
        'registered' => 'The passkey is registered. You can use it the next time you sign in.',
        'removed' => 'The passkey ":name" was removed.',
        'remove' => 'Remove',
        'remove_confirm' => 'Remove the passkey ":name"? You can always sign in with the emailed code.',
        'bound_to' => 'Works on :rp',
        'synced' => 'synced by your password manager',
        'last_used' => 'Last used :when',
        'never_used' => 'Not used yet',
        'fallback_note' => 'A passkey works only on the address it was created for, so add one per address you use. The emailed one-time code works everywhere and is the recovery path.',
        'status' => [
            'available' => 'Passkeys work on this address (:rp).',
            'insecure' => 'Passkeys need an HTTPS address or localhost; this page is on :origin.',
            'ip_literal' => 'Passkeys need a host name rather than an IP address; this page is on :origin. The app opens on localhost, where passkeys work — open it from the menu bar, or use your private remote address.',
        ],
        'errors' => [
            'unavailable' => 'Passkeys are not available on this address. Use the emailed code.',
            'not_verified' => 'That passkey was not accepted. Use the emailed code instead.',
            'no_pending_ceremony' => 'That passkey request is no longer open. Start again.',
            'ceremony_expired' => 'That passkey request timed out. Start again.',
            'origin_mismatch' => 'That passkey request was started on a different address. Start again here.',
            'malformed_credential' => 'The browser sent something we could not read. Start again.',
            'verification_failed' => 'The passkey could not be verified. Start again, or use the emailed code.',
            'already_registered' => 'That passkey is already registered.',
            'browser' => 'Your browser stopped the passkey request (:reason). Start again, or use the emailed code.',
        ],
    ],

    'devices' => [
        'heading' => 'Signed-in devices',
        'lead' => 'Every browser that is signed in right now. Signing a device out ends its session on the server, so it is signed out the next time it loads a page — whatever it still has in its cookie jar.',
        'this_device' => 'This device',
        'revoke' => 'Sign out',
        'revoke_others' => 'Sign out every other device',
        'revoke_others_confirm' => 'Sign out every device except this one?',
        'revoked_one' => 'That device is signed out.',
        'revoked_others' => 'Signed :count other device(s) out.',
        'already_gone' => 'That device was already signed out.',
        'cannot_revoke_current' => 'This is the device you are using. Use "Log out" instead.',
        'signed_in' => 'Signed in :when',
        'last_active' => 'last active :when',
        'unknown_device' => 'Unrecognised browser',
        'mac_app' => 'Mac app',
        'methods' => [
            'otp' => 'Signed in with an emailed code',
            'passkey' => 'Signed in with a passkey',
            'owner_creation' => 'Signed in while creating the account',
        ],
    ],

    'reauth' => [
        'heading' => 'Confirm it is you',
        'lead' => 'This step needs fresh proof that you are at the keyboard before :action.',
        'satisfied' => 'Confirmed. You can complete the action now.',
        'use_passkey' => 'Confirm with a passkey',
        'email_code' => 'Email me a code',
        'sending' => 'Sending…',
        'code_label' => 'One-time code',
        'code_sent' => 'A one-time code is on its way. It expires in :minutes minutes.',
        'code_reused' => 'A code was already sent a moment ago; use that one. It is still valid.',
        'verify' => 'Confirm',
        'back' => 'Choose another way',
        'actions' => [
            'approvals.approve' => 'approving an outbound action',
            'remote-access.enable' => 'turning private remote access on',
            'keys.rotate' => 'rotating a key',
        ],
    ],
];
