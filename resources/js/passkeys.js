/*
 * The browser half of the WebAuthn ceremonies (S2-05, docs/architecture/auth.md §7).
 *
 * Loaded through Vite like everything else: the login page runs under S1-02's
 * strict CSP with one nonce per request and no CDN, so there is no inline
 * script anywhere here. The module only translates between the JSON a
 * Livewire component sends and the ArrayBuffers `navigator.credentials`
 * wants; every decision — challenge, rp.id, allowed origins, verification —
 * is made on the server.
 *
 * Protocol: a component dispatches `passkey:create` or `passkey:get` with
 * `{ id, options }`; we run the ceremony and call back into that component
 * with `completeRegistration` / `completePasskey`, or `passkeyFailed` with
 * the DOMException name (never the payload, never a message that could carry
 * one).
 */

const base64UrlToBytes = (value) => {
    const padded = value.replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4));
    const bytes = new Uint8Array(binary.length);

    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
};

const bytesToBase64Url = (buffer) => {
    const bytes = new Uint8Array(buffer);
    let binary = '';

    for (let index = 0; index < bytes.length; index += 1) {
        binary += String.fromCharCode(bytes[index]);
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

const descriptors = (list) =>
    (list ?? []).map((descriptor) => ({
        ...descriptor,
        id: base64UrlToBytes(descriptor.id),
    }));

const creationOptions = (options) => ({
    ...options,
    challenge: base64UrlToBytes(options.challenge),
    user: { ...options.user, id: base64UrlToBytes(options.user.id) },
    excludeCredentials: descriptors(options.excludeCredentials),
});

const requestOptions = (options) => ({
    ...options,
    challenge: base64UrlToBytes(options.challenge),
    allowCredentials: descriptors(options.allowCredentials),
});

/* The shape the server's denormalizer reads: base64url strings, one level deep. */
const registrationPayload = (credential) => ({
    id: credential.id,
    rawId: bytesToBase64Url(credential.rawId),
    type: credential.type,
    response: {
        clientDataJSON: bytesToBase64Url(credential.response.clientDataJSON),
        attestationObject: bytesToBase64Url(credential.response.attestationObject),
        transports:
            typeof credential.response.getTransports === 'function'
                ? credential.response.getTransports()
                : [],
    },
});

const assertionPayload = (credential) => ({
    id: credential.id,
    rawId: bytesToBase64Url(credential.rawId),
    type: credential.type,
    response: {
        clientDataJSON: bytesToBase64Url(credential.response.clientDataJSON),
        authenticatorData: bytesToBase64Url(credential.response.authenticatorData),
        signature: bytesToBase64Url(credential.response.signature),
        userHandle:
            credential.response.userHandle === null
                ? null
                : bytesToBase64Url(credential.response.userHandle),
    },
});

const component = (id) => (window.Livewire ? window.Livewire.find(id) : null);

const supported = () =>
    typeof window.PublicKeyCredential !== 'undefined' && navigator.credentials !== undefined;

const run = async (id, ceremony, onSuccess) => {
    const target = component(id);

    if (target === null) {
        return;
    }

    if (!supported()) {
        target.call('passkeyFailed', 'NotSupportedError');

        return;
    }

    try {
        const credential = await ceremony();

        if (credential === null) {
            target.call('passkeyFailed', 'NotAllowedError');

            return;
        }

        target.call(onSuccess, credential);
    } catch (error) {
        // Only the exception's name crosses back: a message may quote the payload.
        target.call('passkeyFailed', error && error.name ? error.name : 'UnknownError');
    }
};

window.addEventListener('passkey:create', (event) => {
    const { id, options } = event.detail ?? {};

    run(
        id,
        async () => {
            const credential = await navigator.credentials.create({
                publicKey: creationOptions(options.publicKey ?? options),
            });

            return credential === null ? null : registrationPayload(credential);
        },
        'completeRegistration',
    );
});

window.addEventListener('passkey:get', (event) => {
    const { id, options } = event.detail ?? {};

    run(
        id,
        async () => {
            const credential = await navigator.credentials.get({
                publicKey: requestOptions(options.publicKey ?? options),
            });

            return credential === null ? null : assertionPayload(credential);
        },
        'completePasskey',
    );
});

/* The login page asks whether a passkey ceremony is possible at all before offering it. */
window.addEventListener('passkey:probe', (event) => {
    const target = component((event.detail ?? {}).id);

    if (target !== null) {
        target.call('passkeySupportReported', supported());
    }
});
