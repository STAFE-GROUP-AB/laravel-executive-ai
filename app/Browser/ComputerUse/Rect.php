<?php

declare(strict_types=1);

namespace App\Browser\ComputerUse;

/**
 * Screen rectangle in points, origin top-left of the main display.
 */
final readonly class Rect
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}
}
