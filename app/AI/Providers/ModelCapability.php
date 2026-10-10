<?php

declare(strict_types=1);

namespace App\AI\Providers;

/**
 * The capabilities a job can require from a model (blueprint §4 "capability-aware
 * model routing"; ADR-004 D3). Context window, cost and concurrency are bounds, not
 * capabilities, and live as scalars on ModelCapabilities.
 */
enum ModelCapability: string
{
    case Text = 'text';
    case Streaming = 'streaming';
    case Tools = 'tools';
    case StructuredOutput = 'structured_output';
    case Vision = 'vision';
    case Embeddings = 'embeddings';
}
