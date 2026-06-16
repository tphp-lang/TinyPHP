<?php

declare(strict_types=1);

namespace Tphp;

/**
 * x86-64 machine code builder - emits raw bytes for common instructions.
 *
 * Supports two modes:
 *   - Linux: strings embedded inline, syscalls
 *   - Windows: strings emitted to separate data section, IAT-based calls
 */
final class X64Builder
{
    /** @var string raw machine code bytes */
    private string $code = '';

    /** @var array<string, int> label → code offset */
    private array $labels = [];

    /** @var array<int, string> offset → label name (for backpatching) */
    private array $patches = [];

    /** @var array<string, string> string constant name → raw bytes */
    private array $strings = [];

    /** Reverse map: string value → label name (for deduplication) */
    private array $stringMap = [];

    /** @var array<int, int> code offset → target absolute RVA (for IAT calls) */
    private array $rvaPatches = [];

    /** @var array<int, int> code offset → instruction byte length */
    private array $rvaPatchLen = [];

    private int $stringCounter = 0;

    /** IAT slot allocation record: slotIndex => functionName */
    private array $iatSlots = [];
    private bool $modeWindows = false;

    // ---- Register encodings (public for external use) ----

    public const int RAX = 0;  public const int RCX = 1;
    public const int RDX = 2;  public const int RBX = 3;
    public const int RSP = 4;  public const int RBP = 5;
    public const int RSI = 6;  public const int RDI = 7;
    public const int R8  = 8;  public const int R9  = 9;
    public const int R10 = 10; public const int R11 = 11;
    public const int R12 = 12; public const int R13 = 13;
    public const int R14 = 14; public const int R15 = 15;

    // ---- Mode ----

    public function setWindowsMode(bool $v = true): void { $this->modeWindows = $v; }
    public function isWindowsMode(): bool { return $this->modeWindows; }

    // ---- Code buffer ----

    public function getCode(): string { return $this->code; }

    public function getCodeLength(): int
    {
        $len = strlen($this->code);
        if ($len < 0) throw new \RuntimeException('Code length overflow');
        return $len;
    }

    public function currentOffset(): int
    {
        $len = strlen($this->code);
        if ($len < 0) throw new \RuntimeException('Code offset overflow');
        return $len;
    }

    /** @return array<string, string> */
    public function getStrings(): array { return $this->strings; }

    /** @return array<int, int> code offset → target RVA */
    public function getRvaPatches(): array { return $this->rvaPatches; }

    /** @return array<int, int> code offset → instruction length */
    public function getRvaPatchLens(): array { return $this->rvaPatchLen; }

    /** @return array<int, string> slot index → function name */
    public function getIatSlots(): array { return $this->iatSlots; }

    // ---- String constants ----

    public function addString(string $value): string
    {
        // Deduplicate: return existing label if same string already added
        if (isset($this->stringMap[$value])) {
            return $this->stringMap[$value];
        }
        $name = '.LC' . $this->stringCounter++;
        $this->strings[$name] = $value . "\0";
        $this->stringMap[$value] = $name;
        return $name;
    }

    // ---- Label support ----

    public function defineLabel(string $name): void
    {
        $this->labels[$name] = strlen($this->code);
    }

    /**
     * Place a 4-byte relative offset and schedule for patching.
     * offset = target_label_offset - (patch_offset + 4)
     */
    public function rel32(string $targetLabel): void
    {
        $this->patches[strlen($this->code)] = $targetLabel;
        $this->emit("\x00\x00\x00\x00");
    }

    /**
     * Place a 4-byte relative displacement for a CALL [RIP+disp32] to an IAT entry.
     * Records the offset + target RVA for later resolution by the PE writer.
     *
     * call [rip+disp32] = FF 15 XX XX XX XX (6 bytes)
     * The disp32 = targetRVA - (codeRVA + offset + 6)
     */
    public function rel32Rva(int $targetRva, int $instrLen = 6): void
    {
        $offset = strlen($this->code);
        $this->rvaPatches[$offset] = $targetRva;
        $this->rvaPatchLen[$offset] = $instrLen;
        $this->emit("\x00\x00\x00\x00");
    }

    /**
     * Reserve an IAT slot for a function and return its slot index + RVA.
     * @return array{int, int} [slotIndex, placeholderRva]
     */
    public function allocIatSlot(string $funcName): array
    {
        $idx = count($this->iatSlots);
        $this->iatSlots[$idx] = $funcName;
        // Return slot index; actual RVA will be computed by PEWriter
        return [$idx, 0];
    }

    // ---- Patch resolution ----

    /**
     * Resolve all label-based relative patches.
     * For Linux mode: embeds string data at end of code.
     * For Windows mode: skips string labels (resolved later by resolveStringPatches).
     */
    public function resolvePatches(): void
    {
        // In Linux mode, append string data and define their labels
        if (!$this->modeWindows) {
            foreach ($this->strings as $label => $data) {
                $this->defineLabel($label);
                $this->code .= $data;
            }
        }

        // Resolve relative patches, skipping string labels in Windows mode
        $unresolved = [];
        foreach ($this->patches as $offset => $label) {
            if (!isset($this->labels[$label])) {
                // In Windows mode, string labels will be resolved later
                if ($this->modeWindows) {
                    $unresolved[$offset] = $label;
                    continue;
                }
                throw new \RuntimeException("Undefined label: $label");
            }
            $target = $this->labels[$label];
            $rel = $target - ($offset + 4);
            $bytes = pack('V', $rel);
            for ($i = 0; $i < 4; $i++) {
                $this->code[$offset + $i] = $bytes[$i];
            }
        }
        // Keep unresolved patches for later resolution
        $this->patches = $unresolved;
    }

    /**
     * Resolve RVA-based patches using the code base RVA.
     * Called by PEWriter after section layout is computed.
     *
     * For call [rip+disp32]:
     *   disp32 = targetRVA - (codeBaseRVA + patchOffset + instrLen)
     */
    public function resolveRvaPatches(int $codeBaseRva): void
    {
        foreach ($this->rvaPatches as $offset => $targetRva) {
            $instrLen = $this->rvaPatchLen[$offset] ?? 6;
            $rip = $codeBaseRva + $offset + $instrLen;
            $rel = $targetRva - $rip;
            $bytes = pack('V', $rel);
            for ($i = 0; $i < 4; $i++) {
                $this->code[$offset + $i] = $bytes[$i];
            }
        }
    }

    /**
     * Offset all IAT patch target RVAs by a delta.
     * Used when .rdata section RVA shifts to accommodate large .text sections.
     */
    public function offsetIatPatches(int $delta): void
    {
        foreach ($this->rvaPatches as $offset => $targetRva) {
            $this->rvaPatches[$offset] = $targetRva + $delta;
        }
    }

    /**
     * Resolve string label patches using absolute RVAs (for Windows PE mode).
     *
     * For LEA [rip+disp32] and similar RIP-relative instructions:
     *   disp32 = targetRVA - (codeBaseRVA + patchOffset + 4)
     *
     * @param array<string,int> $stringRvas  label → absolute RVA
     * @param int               $codeBaseRva RVA of .text section
     */
    public function resolveStringPatches(array $stringRvas, int $codeBaseRva): void
    {
        foreach ($this->patches as $offset => $label) {
            // Skip labels that were already resolved (non-string labels)
            if (isset($this->labels[$label])) continue;

            if (!isset($stringRvas[$label])) {
                throw new \RuntimeException("Undefined string label: $label (not in RVA map)");
            }
            $targetRva = $stringRvas[$label];
            // RIP = codeBaseRVA + offset + 4
            $rip = $codeBaseRva + $offset + 4;
            $rel = $targetRva - $rip;
            $bytes = pack('V', $rel);
            for ($i = 0; $i < 4; $i++) {
                $this->code[$offset + $i] = $bytes[$i];
            }
        }
    }

    // ---- Raw byte emission ----

    public function emit(string $bytes): void
    {
        $this->code .= $bytes;
    }

    public function emit8(int $b): void
    {
        $this->code .= chr($b & 0xFF);
    }

    public function emit16(int $w): void
    {
        $this->code .= pack('v', $w & 0xFFFF);
    }

    public function emit32(int $d): void
    {
        $this->code .= pack('V', $d);
    }

    public function emit64(int $q): void
    {
        $this->code .= pack('P', $q);
    }

    // ==================== x86-64 Instructions ====================

    private function modRM(int $mod, int $reg, int $rm): int
    {
        return (($mod & 3) << 6) | (($reg & 7) << 3) | ($rm & 7);
    }

    private function rexW(bool $w, int $reg, int $rm, int $index = 0): int
    {
        $rex = 0x40;
        if ($w)       $rex |= 0x08; // REX.W
        if ($reg & 8) $rex |= 0x04; // REX.R
        if ($index & 8) $rex |= 0x02; // REX.X
        if ($rm & 8)   $rex |= 0x01; // REX.B
        return $rex;
    }

    // ---- Stack frame ----

    public function pushReg(int $reg): void
    {
        if ($reg >= 8) $this->emit8(0x41);
        $this->emit8(0x50 | ($reg & 7));
    }

    public function popReg(int $reg): void
    {
        if ($reg >= 8) $this->emit8(0x41);
        $this->emit8(0x58 | ($reg & 7));
    }

    // ---- Mov ----

    public function movRR(int $dst, int $src): void
    {
        $this->emit8($this->rexW(true, $dst, $src, 0));
        $this->emit8(0x8B);
        $this->emit8($this->modRM(3, $dst & 7, $src & 7));
    }

    public function movRI64(int $reg, int $imm64): void
    {
        $this->emit8($this->rexW(true, 0, 0, $reg));
        $this->emit8(0xB8 | ($reg & 7));
        $this->emit64($imm64);
    }

    public function movRI32(int $reg, int $imm32): void
    {
        if ($reg >= 8) $this->emit8(0x41);
        $this->emit8(0xB8 | ($reg & 7));
        $this->emit32($imm32 & 0xFFFFFFFF);
    }

    public function movRM64(int $reg, int $baseReg, int $disp): void
    {
        $this->emit8($this->rexW(true, $reg, $baseReg, 0));
        $this->emit8(0x8B);
        if ($disp === 0 && ($baseReg & 7) !== 5) {
            $this->emit8($this->modRM(0, $reg & 7, $baseReg & 7));
        } elseif ($disp >= -128 && $disp <= 127) {
            $this->emit8($this->modRM(1, $reg & 7, $baseReg & 7));
            $this->emit8($disp & 0xFF);
        } else {
            $this->emit8($this->modRM(2, $reg & 7, $baseReg & 7));
            $this->emit32($disp);
        }
    }

    public function movMR64(int $baseReg, int $disp, int $src): void
    {
        $this->emit8($this->rexW(true, $src, $baseReg, 0));
        $this->emit8(0x89);
        if ($disp === 0 && ($baseReg & 7) !== 5) {
            $this->emit8($this->modRM(0, $src & 7, $baseReg & 7));
        } elseif ($disp >= -128 && $disp <= 127) {
            $this->emit8($this->modRM(1, $src & 7, $baseReg & 7));
            $this->emit8($disp & 0xFF);
        } else {
            $this->emit8($this->modRM(2, $src & 7, $baseReg & 7));
            $this->emit32($disp);
        }
    }

    /**
     * LEA r64, [RIP+disp32] - RIP-relative address load
     * Used for both Linux (string access) and Windows (IAT access)
     */
    public function leaRipRel(int $dst, int $disp): void
    {
        $this->emit8($this->rexW(true, $dst, 0, 0));
        $this->emit8(0x8D);
        $this->emit8($this->modRM(0, $dst & 7, 5));
        $this->emit32($disp);
    }

    // ---- Arithmetic ----

    public function addRR(int $dst, int $src): void
    {
        $this->emit8($this->rexW(true, $dst, $src, 0));
        $this->emit8(0x03);
        $this->emit8($this->modRM(3, $dst & 7, $src & 7));
    }

    public function subRR(int $dst, int $src): void
    {
        $this->emit8($this->rexW(true, $dst, $src, 0));
        $this->emit8(0x2B);
        $this->emit8($this->modRM(3, $dst & 7, $src & 7));
    }

    public function subRI32(int $reg, int $imm32): void
    {
        // SUB r/m64, imm32: REX.W + 0x81 /5 + ModRM(3, 5, reg) + imm32
        $this->emit8($this->rexW(true, 0, $reg, 0));
        $this->emit8(0x81);
        $this->emit8($this->modRM(3, 5, $reg & 7));
        $this->emit32($imm32);
    }

    public function xorRR(int $dst, int $src): void
    {
        if ($dst >= 8 || $src >= 8) $this->emit8($this->rexW(false, $dst, $src, 0));
        $this->emit8(0x33);
        $this->emit8($this->modRM(3, $dst & 7, $src & 7));
    }

    public function movsxRR(int $dst, int $src): void
    {
        $this->emit8($this->rexW(true, $dst, 0, $src));
        $this->emit("\x0F\xBE");
        $this->emit8($this->modRM(3, $dst & 7, $src & 7));
    }

    public function movzxRR(int $dst, int $src): void
    {
        $this->emit8($this->rexW(true, $dst, 0, $src));
        $this->emit("\x0F\xB6");
        $this->emit8($this->modRM(3, $dst & 7, $src & 7));
    }

    // ---- Comparison ----

    public function cmpRR(int $a, int $b): void
    {
        $this->emit8($this->rexW(true, $a, $b, 0));
        $this->emit8(0x3B);
        $this->emit8($this->modRM(3, $a & 7, $b & 7));
    }

    public function cmpRI32(int $reg, int $imm32): void
    {
        $this->emit8($this->rexW(true, 0, $reg, 0));
        $this->emit8(0x81);
        $this->emit8($this->modRM(3, 7, $reg & 7));
        $this->emit32($imm32);
    }

    public function testRR(int $a, int $b): void
    {
        if ($a >= 8 || $b >= 8) $this->emit8($this->rexW(true, $a, $b, 0));
        $this->emit8(0x85);
        $this->emit8($this->modRM(3, $a & 7, $b & 7));
    }

    // ---- Jumps (label-based, relative) ----

    public function jmpLabel(string $label): void
    {
        $this->emit("\xE9");
        $this->rel32($label);
    }

    public function jeLabel(string $label): void
    {
        $this->emit("\x0F\x84");
        $this->rel32($label);
    }

    public function jneLabel(string $label): void
    {
        $this->emit("\x0F\x85");
        $this->rel32($label);
    }

    public function jlLabel(string $label): void
    {
        $this->emit("\x0F\x8C");
        $this->rel32($label);
    }

    public function jgLabel(string $label): void
    {
        $this->emit("\x0F\x8F");
        $this->rel32($label);
    }

    public function jleLabel(string $label): void
    {
        $this->emit("\x0F\x8E");
        $this->rel32($label);
    }

    public function jgeLabel(string $label): void
    {
        $this->emit("\x0F\x8D");
        $this->rel32($label);
    }

    public function jnsLabel(string $label): void
    {
        $this->emit("\x0F\x89");
        $this->rel32($label);
    }

    public function jaeLabel(string $label): void
    {
        $this->emit("\x0F\x83");
        $this->rel32($label);
    }

    // ---- ADD r64, imm32 (REX.W + 81 /0 + ModRM(3,0,reg) + imm32) ----
    public function emitAddRI32(int $reg, int $imm32): void
    {
        $this->emit8($this->rexW(true, 0, $reg, 0));
        $this->emit8(0x81);
        $this->emit8($this->modRM(3, 0, $reg & 7));
        $this->emit32($imm32);
    }

    // ---- ADD qword [base+disp32], imm32 ----
    public function emitAddMemImm64(int $baseReg, int $disp, int $imm32): void
    {
        $this->emit8($this->rexW(true, 0, $baseReg, 0));
        $this->emit8(0x81);
        if ($disp >= -128 && $disp <= 127) {
            $this->emit8($this->modRM(1, 0, $baseReg & 7));
            $this->emit8($disp & 0xFF);
        } else {
            $this->emit8($this->modRM(2, 0, $baseReg & 7));
            $this->emit32($disp);
        }
        $this->emit32($imm32);
    }

    // ---- SUB qword [base+disp32], imm32 ----
    public function emitSubMemImm64(int $baseReg, int $disp, int $imm32): void
    {
        $this->emit8($this->rexW(true, 0, $baseReg, 0));
        $this->emit8(0x81);
        if ($disp >= -128 && $disp <= 127) {
            $this->emit8($this->modRM(1, 5, $baseReg & 7));
            $this->emit8($disp & 0xFF);
        } else {
            $this->emit8($this->modRM(2, 5, $baseReg & 7));
            $this->emit32($disp);
        }
        $this->emit32($imm32);
    }

    // ---- Short conditional jumps (8-bit) ----

    public function jeRel8(int $offset): void
    {
        $this->emit("\x74");
        $this->emit8($offset & 0xFF);
    }

    public function jneRel8(int $offset): void
    {
        $this->emit("\x75");
        $this->emit8($offset & 0xFF);
    }

    public function jmpRel8(int $offset): void
    {
        $this->emit("\xEB");
        $this->emit8($offset & 0xFF);
    }

    // ---- Return / syscall ----

    public function ret(): void
    {
        $this->emit("\xC3");
    }

    public function syscall(): void
    {
        $this->emit("\x0F\x05");
    }

    // ---- Float operations (XMM) ----

    public function movsdLoad(int $xmmReg, int $baseReg, int $disp): void
    {
        $this->emit("\xF2");
        if ($xmmReg >= 8 || $baseReg >= 8) {
            $this->emit8($this->rexW(false, $xmmReg, 0, $baseReg));
        }
        $this->emit("\x0F\x10");
        $this->emit8($this->modRM(2, $xmmReg & 7, $baseReg & 7));
        $this->emit32($disp);
    }

    public function movsdStore(int $xmmReg, int $baseReg, int $disp): void
    {
        $this->emit("\xF2");
        if ($xmmReg >= 8 || $baseReg >= 8) {
            $this->emit8($this->rexW(false, $xmmReg, 0, $baseReg));
        }
        $this->emit("\x0F\x11");
        $this->emit8($this->modRM(2, $xmmReg & 7, $baseReg & 7));
        $this->emit32($disp);
    }

    public function movqFromXmm(int $gpReg, int $xmmReg): void
    {
        $this->emit("\x66");
        $this->emit8($this->rexW(true, $gpReg, 0, $xmmReg));
        $this->emit("\x0F\x7E");
        $this->emit8($this->modRM(3, $gpReg & 7, $xmmReg & 7));
    }

    public function movqToXmm(int $xmmReg, int $gpReg): void
    {
        $this->emit("\x66");
        $this->emit8($this->rexW(true, $gpReg, 0, $xmmReg));
        $this->emit("\x0F\x6E");
        $this->emit8($this->modRM(3, $xmmReg & 7, $gpReg & 7));
    }

    // ---- Windows-specific: IAT call ----

    /**
     * Emit CALL [RIP+disp32] pointing to an IAT entry.
     * FF 15 XX XX XX XX
     *
     * The disp32 will be resolved later using resolveRvaPatches().
     *
     * @param int $iatRva  the absolute RVA of the IAT entry
     */
    public function callIat(int $iatRva): void
    {
        $this->emit("\xFF\x15");
        // rel32Rva records offset AFTER FF 15; the remaining 4 bytes are the disp32
        // RIP = codeBaseRVA + patchOffset + 4
        $this->rel32Rva($iatRva, 4);
    }

    // ---- Misc ----

    public function int3(): void
    {
        $this->emit("\xCC");
    }

    public function nopAlign(int $alignment): void
    {
        $current = strlen($this->code);
        $pad = ($alignment - ($current % $alignment)) % $alignment;
        for ($i = 0; $i < $pad; $i++) {
            $this->emit("\x90");
        }
    }

    // ---- idiv helper ----

    public function cqo(): void
    {
        $this->emit("\x48\x99"); // CQO (sign-extend RAX → RDX:RAX)
    }

    // ---- neg ----

    public function negReg(int $reg): void
    {
        $this->emit8($this->rexW(true, 0, 0, $reg));
        $this->emit8(0xF7);
        $this->emit8($this->modRM(3, 3, $reg & 7));
    }
}
