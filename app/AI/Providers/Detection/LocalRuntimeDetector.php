<?php

declare(strict_types=1);

namespace App\AI\Providers\Detection;

/**
 * Detects one local inference runtime on the owner's own Mac (§12.1 "detect an
 * installed Ollama or LM Studio and *reuse* it"). Every implementation probes
 * loopback only, with ADR-004 D3's budget — 500 ms to connect, 2 s in total —
 * and reports what it found without ever choosing it.
 */
interface LocalRuntimeDetector
{
    public function providerId(): string;

    /**
     * Never throws: an absent runtime, a refused connection and a timeout are
     * all `DetectedRuntime::absent()`, because detection runs on the installer's
     * critical path and must not be able to fail it.
     */
    public function detect(): DetectedRuntime;
}
