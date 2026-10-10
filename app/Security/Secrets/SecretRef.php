<?php

declare(strict_types=1);

namespace App\Security\Secrets;

use InvalidArgumentException;

/**
 * Service identity plus account (ADR-003 D1): what a row may store instead
 * of a credential. Services are the fixed `se.stafe.executive-ai.<service>`
 * names; accounts are ULIDs or `default`, never an e-mail address.
 */
final readonly class SecretRef
{
    public const string PREFIX = 'se.stafe.executive-ai.';

    public const string APP_KEY = self::PREFIX.'core.app-key';

    public const string IPC_MENUBAR = self::PREFIX.'ipc.menubar';

    public const string EMAIL = self::PREFIX.'email';

    public const string EMAIL_SENDER = self::PREFIX.'email-sender';

    public const string GITHUB = self::PREFIX.'github';

    public const string AI_PROVIDER = self::PREFIX.'ai-provider';

    public const string MCP = self::PREFIX.'mcp';

    public const string WEBPUSH = self::PREFIX.'webpush';

    public const string TUNNEL = self::PREFIX.'tunnel';

    public const string BACKUP = self::PREFIX.'backup';

    public const string DEFAULT_ACCOUNT = 'default';

    public function __construct(
        public string $service,
        public string $account = self::DEFAULT_ACCOUNT,
    ) {
        if (! str_starts_with($service, self::PREFIX) || strlen($service) <= strlen(self::PREFIX)) {
            throw new InvalidArgumentException('A secret service must be a se.stafe.executive-ai.<service> name.');
        }

        if ($account === '' || strlen($account) > 200 || preg_match('/^[A-Za-z0-9._-]+$/', $account) !== 1) {
            throw new InvalidArgumentException('A secret account must be a ULID-like identifier.');
        }
    }

    public static function appKey(): self
    {
        return new self(self::APP_KEY);
    }

    public static function ipcKey(): self
    {
        return new self(self::IPC_MENUBAR);
    }

    public function __toString(): string
    {
        return "{$this->service}/{$this->account}";
    }
}
