<?php

declare(strict_types=1);

namespace App\Security\Secrets;

/**
 * Pattern-based half of the Redactor (ADR-003 D7). Every format here has a
 * synthetic example in tests/Security/fixtures/secrets-corpus.json, and the
 * corpus is reviewed by the security lead on every new adapter (RR-17).
 *
 * The patterns replace the whole match. Patterns that must keep a prefix
 * (an assignment `token=`) capture it as the named group `keep`, which the
 * Redactor writes back in front of the placeholder.
 */
final class SecretPatterns
{
    public const string PLACEHOLDER = '[REDACTED]';

    /** @return array<string, string> name => PCRE pattern */
    public static function all(): array
    {
        return [
            'private_key_block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/',
            'lea_ipc_session_token' => '/\blxai1\.[a-z]+\.[0-7][0-9A-HJKMNP-TV-Z]{25}\.\d+\.[0-9a-f]{32}\b/',
            'lea_ipc_elevation_token' => '/\blxai1e\.[a-z_-]+\.[A-Za-z0-9_-]+\.\d+\.[0-9a-f]{32}\b/',
            'anthropic_api_key' => '/\bsk-ant-[A-Za-z0-9_-]{20,}/',
            'openai_api_key' => '/\bsk-(?:proj-|svcacct-|admin-)?[A-Za-z0-9_-]{20,}/',
            'github_token' => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
            'github_fine_grained_pat' => '/\bgithub_pat_[A-Za-z0-9_]{22,}\b/',
            'google_oauth_access_token' => '/\bya29\.[A-Za-z0-9_-]{20,}/',
            'google_oauth_refresh_token' => '#\b1//[A-Za-z0-9_-]{20,}#',
            'google_api_key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
            'google_client_secret' => '/\bGOCSPX-[A-Za-z0-9_-]{20,}/',
            'jwt' => '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
            'microsoft_client_secret' => '/\b[A-Za-z0-9~._-]{3}8Q~[A-Za-z0-9~._-]{30,}/',
            'slack_token' => '/\bxox[abprs]-[A-Za-z0-9-]{10,}\b/',
            'aws_access_key_id' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
            'laravel_app_key' => '#\bbase64:[A-Za-z0-9+/]{40,}={0,2}#',
            'bearer_header' => '/\bbearer\s+[A-Za-z0-9._~+\/=-]{16,}/i',
            'basic_auth_header' => '/\bbasic\s+[A-Za-z0-9+\/=]{16,}/i',
            'url_credential_query' => '/(?<keep>[?&](?:access_token|refresh_token|id_token|client_secret|api_key|apikey|token|code|key|sig|signature)=)[^&\s"\'#]+/i',
            'keyed_assignment' => '/(?<keep>\b(?:authorization|proxy-authorization|x-api-key|api[_-]?key|apikey|access[_-]?token|refresh[_-]?token|id[_-]?token|client[_-]?secret|secret|password|passwd|pwd|token|cookie|set-cookie|code[_-]?verifier|private[_-]?key|app[_-]?key|otp|otp[_-]?code)\b["\']?\s*[:=]\s*["\']?)[^\s"\',;]{6,}/i',
        ];
    }

    /**
     * Key-based half: an array value under any of these keys (case-insensitive,
     * `-` and `_` equivalent) is replaced wholesale, whatever it looks like.
     *
     * @return array<int, string>
     */
    public static function sensitiveKeys(): array
    {
        return [
            'authorization', 'proxy_authorization', 'x_api_key', 'api_key', 'apikey', 'app_key', 'secret_key',
            'access_key', 'private_key', 'token', 'access_token', 'refresh_token', 'id_token', 'bearer',
            'password', 'passwd', 'pwd', 'new_password', 'current_password', 'password_confirmation',
            'secret', 'client_secret', 'code_verifier', 'cookie', 'set_cookie', 'session', 'otp', 'otp_code',
            'one_time_code', 'verification_code', 'signing_key', 'mac', 'vapid_private_key',
            // WebAuthn ceremony material (S2-05): no sink needs any of it, and the
            // authenticator never passes it on purpose — this is the second layer.
            'challenge', 'signature', 'client_data_json', 'clientdatajson', 'authenticator_data',
            'authenticatordata', 'attestation_object', 'attestationobject', 'credential_id',
            'credentialid', 'public_key', 'raw_id', 'rawid', 'user_handle', 'userhandle',
        ];
    }

    /**
     * Keys whose *children* are header-like: any high-entropy string under
     * them is redacted even when its own key is harmless (threat model T-75).
     *
     * @return array<int, string>
     */
    public static function headerContainers(): array
    {
        return ['headers', 'request_headers', 'response_headers', 'http_headers'];
    }
}
