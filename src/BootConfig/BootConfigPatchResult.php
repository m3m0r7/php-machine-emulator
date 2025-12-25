<?php

declare(strict_types=1);

namespace PHPMachineEmulator\BootConfig;

final class BootConfigPatchResult
{
    /**
     * @param array<int,string> $appliedRules
     */
    public function __construct(
        public readonly string $data,
        public readonly array $appliedRules,
    ) {
    }

    public function isPatched(): bool
    {
        return $this->appliedRules !== [];
    }
}
