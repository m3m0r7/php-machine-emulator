<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Trait for instructions that support prefix application.
 *
 * Provides methods to:
 * - Generate opcode combinations with prefixes (for opcodes() method)
 * - Parse and apply prefixes at runtime (for process() method)
 */
trait InstructionPrefixApplyable
{
    /**
     * Prefix opcode constants
     */
    private const PREFIX_OPERAND_SIZE = 0x66;
    private const PREFIX_ADDRESS_SIZE = 0x67;
    private const PREFIX_LOCK = 0xF0;
    private const PREFIX_ES = 0x26;
    private const PREFIX_CS = 0x2E;
    private const PREFIX_SS = 0x36;
    private const PREFIX_DS = 0x3E;
    private const PREFIX_FS = 0x64;
    private const PREFIX_GS = 0x65;

    /**
     * All prefix bytes
     */
    private const ALL_PREFIXES = [
        self::PREFIX_OPERAND_SIZE,
        self::PREFIX_ADDRESS_SIZE,
        self::PREFIX_LOCK,
        self::PREFIX_ES,
        self::PREFIX_CS,
        self::PREFIX_SS,
        self::PREFIX_DS,
        self::PREFIX_FS,
        self::PREFIX_GS,
    ];

    /**
     * Segment override prefixes mapped to register types
     */
    private const SEGMENT_PREFIXES = [
        self::PREFIX_ES => RegisterType::ES,
        self::PREFIX_CS => RegisterType::CS,
        self::PREFIX_SS => RegisterType::SS,
        self::PREFIX_DS => RegisterType::DS,
        self::PREFIX_FS => RegisterType::FS,
        self::PREFIX_GS => RegisterType::GS,
    ];

    /**
     * Generate all prefix combinations for given opcodes.
     * Generates combinations for 0, 1, 2, 3 prefixes.
     *
     * @param array $baseOpcodes Base opcodes (single bytes or arrays)
     * @param PrefixClass[]|null $prefixClasses Which prefix classes to apply (default: Operand, Address, Segment)
     * @return array Combined opcodes including prefix combinations
     */
    protected function applyPrefixes(array $baseOpcodes, ?array $prefixClasses = null): array
    {
        $prefixClasses ??= [PrefixClass::Operand, PrefixClass::Address, PrefixClass::Segment, PrefixClass::Lock];

        // Get prefix bytes grouped by class (only one prefix per class is valid)
        $prefixGroups = $this->getPrefixGroups($prefixClasses);

        $result = [];

        foreach ($baseOpcodes as $opcode) {
            $baseArray = is_array($opcode) ? $opcode : [$opcode];

            // Generate all combinations using power set of prefix groups
            $combinations = $this->generatePrefixCombinations($prefixGroups);

            foreach ($combinations as $prefixCombo) {
                $result[] = array_merge($prefixCombo, $baseArray);
            }
        }

        return $result;
    }

    /**
     * Get prefix bytes grouped by class.
     *
     * @param PrefixClass[] $classes
     * @return array<int, int[]> Array of prefix groups
     */
    private function getPrefixGroups(array $classes): array
    {
        $groups = [];
        foreach ($classes as $class) {
            $groups[] = match ($class) {
                PrefixClass::Operand => [self::PREFIX_OPERAND_SIZE],
                PrefixClass::Address => [self::PREFIX_ADDRESS_SIZE],
                PrefixClass::Lock => [self::PREFIX_LOCK],
                PrefixClass::Segment => [
                    self::PREFIX_ES,
                    self::PREFIX_CS,
                    self::PREFIX_SS,
                    self::PREFIX_DS,
                    self::PREFIX_FS,
                    self::PREFIX_GS,
                ],
            };
        }
        return $groups;
    }

    /**
     * Generate all prefix combinations and permutations from groups.
     * Each group can contribute 0 or 1 prefix, and all orderings are generated.
     *
     * @param array<int, int[]> $groups
     * @return array<int[]> All possible prefix combinations with all permutations
     */
    private function generatePrefixCombinations(array $groups): array
    {
        // First, generate all combinations (which prefixes to include)
        $combinations = $this->generateCombinations($groups);

        // Then, for each combination, generate all permutations (orderings)
        $result = [];
        foreach ($combinations as $combo) {
            if (empty($combo)) {
                $result[] = [];
            } else {
                foreach ($this->generatePermutations($combo) as $perm) {
                    $result[] = $perm;
                }
            }
        }

        return $result;
    }

    /**
     * Generate all combinations of prefixes (which prefixes to include).
     *
     * @param array<int, int[]> $groups
     * @return array<int[]>
     */
    private function generateCombinations(array $groups): array
    {
        $result = [[]];

        foreach ($groups as $group) {
            $newResult = [];
            foreach ($result as $current) {
                // Option 1: don't add any prefix from this group
                $newResult[] = $current;

                // Option 2: add each prefix from this group
                foreach ($group as $prefix) {
                    $newResult[] = array_merge($current, [$prefix]);
                }
            }
            $result = $newResult;
        }

        return $result;
    }

    /**
     * Generate all permutations of an array.
     *
     * @param array $items
     * @return array<array>
     */
    private function generatePermutations(array $items): array
    {
        if (count($items) <= 1) {
            return [$items];
        }

        $result = [];
        foreach ($items as $i => $item) {
            $remaining = array_values(array_diff_key($items, [$i => true]));
            foreach ($this->generatePermutations($remaining) as $perm) {
                $result[] = array_merge([$item], $perm);
            }
        }

        return $result;
    }

    /**
     * Parse prefix bytes from opcode array and apply their effects.
     *
     * @param RuntimeInterface $runtime
     * @param array $opcodes The opcode bytes array
     * @return array The actual instruction opcode bytes (without prefixes)
     */
    protected function parsePrefixes(RuntimeInterface $runtime, array $opcodes): array
    {
        $i = 0;
        $count = count($opcodes);

        while ($i < $count) {
            $byte = $opcodes[$i];
            if (in_array($byte, self::ALL_PREFIXES, true)) {
                $this->applyPrefix($runtime, $byte);
                $i++;
            } else {
                // Found the actual opcode - return from this position
                break;
            }
        }

        // Return opcodes starting from first non-prefix byte
        return array_slice($opcodes, $i);
    }

    /**
     * Apply a single prefix byte's effect.
     */
    private function applyPrefix(RuntimeInterface $runtime, int $prefix): void
    {
        $cpu = $runtime->context()->cpu();

        switch ($prefix) {
            case self::PREFIX_OPERAND_SIZE:
                $cpu->setOperandSizeOverride(true);
                break;

            case self::PREFIX_ADDRESS_SIZE:
                $cpu->setAddressSizeOverride(true);
                break;

            case self::PREFIX_LOCK:
                // LOCK prefix - no runtime effect in emulator
                break;

            default:
                // Segment override
                if (isset(self::SEGMENT_PREFIXES[$prefix])) {
                    $cpu->setSegmentOverride(self::SEGMENT_PREFIXES[$prefix]);
                }
                break;
        }
    }
}
