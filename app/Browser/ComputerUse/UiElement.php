<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

/**
 * One node of the observed accessibility tree. `secure` marks an `AXSecureTextField`
 * or a label matching the secret-field heuristic (ADR-005 D1.7); such elements carry
 * no value and no label text and can never be the target of an action (§6: no
 * passwords or 2FA codes are read or typed).
 *
 * `label` and `value` come from the screen, i.e. from third-party content: data, not
 * instruction.
 */
final readonly class UiElement
{
    public function __construct(
        public string $id,
        public string $role,
        public ?string $label,
        public ?string $value,
        public Rect $frame,
        public bool $enabled,
        public bool $focused,
        public bool $secure,
    ) {}
}
