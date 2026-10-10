<?php

declare(strict_types=1);

namespace Tests\Security\Support;

/**
 * The Sprint 0 implementation of `AuditLogSpy`: an in-memory recorder, so the gate
 * can assert the §11 audit requirement without touching a database S1-01 has not
 * built yet. Replaced by an adapter over `audit_events` once that lands; see
 * `AuditLogSpy` for the binding point.
 */
final class InMemoryAuditLogSpy implements AuditLogSpy
{
    /**
     * @var list<array{type: string, context: array<string, mixed>}>
     */
    private array $events = [];

    public function record(string $type, array $context = []): void
    {
        $this->events[] = ['type' => $type, 'context' => $context];
    }

    public function events(): array
    {
        return $this->events;
    }

    public function types(): array
    {
        return array_map(
            static fn (array $event): string => $event['type'],
            $this->events,
        );
    }

    /**
     * Every scalar that ended up in an event context, flattened, so the suite can
     * assert the audit trail carries references and not the hostile payload.
     *
     * @return list<string>
     */
    public function contextStrings(): array
    {
        $strings = [];

        foreach ($this->events as $event) {
            array_walk_recursive(
                $event['context'],
                static function (mixed $value) use (&$strings): void {
                    if (is_string($value)) {
                        $strings[] = $value;
                    }
                },
            );
        }

        return $strings;
    }
}
