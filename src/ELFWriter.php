<?php

declare(strict_types=1);

namespace Tphp;

/**
 * ELF (Executable and Linkable Format) binary writer for x86-64 Linux.
 *
 * Produces a minimal statically-linked ET_EXEC ELF file with:
 *   - One PT_LOAD segment (R+X) containing all code and string data
 *   - One PT_GNU_STACK segment (RW, no-exec)
 */
final class ELFWriter
{
    /** @var resource */
    private $fh;
    private int $fileOffset = 0;

    private const int ET_EXEC = 2;
    private const int EM_X86_64 = 62;
    private const int EV_CURRENT = 1;
    private const int ELFCLASS64 = 2;
    private const int ELFDATA2LSB = 1;
    private const int ELFOSABI_LINUX = 3;
    private const int PT_LOAD = 1;
    private const int PT_GNU_STACK = 0x6474E551;
    private const int PF_X = 1;
    private const int PF_W = 2;
    private const int PF_R = 4;
    private const int SHT_PROGBITS = 1;
    private const int SHT_STRTAB = 3;
    private const int SHF_ALLOC = 2;
    private const int SHF_EXECINSTR = 4;
    private const int PAGE_SIZE = 0x1000;

    private string $code = '';

    public function __construct(string $outputPath)
    {
        $fh = fopen($outputPath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open output: $outputPath");
        }
        $this->fh = $fh;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }

    public function write(): void
    {
        $codeSize = strlen($this->code);

        $baseVA = 0x400000;
        $textVA = $baseVA + self::PAGE_SIZE;
        $entryVA = $textVA;

        // Single LOAD segment for code+data
        $fileStart = self::PAGE_SIZE;
        $loadFileSize = self::alignUp($codeSize, self::PAGE_SIZE);
        $loadMemSize = self::alignUp($codeSize, self::PAGE_SIZE);

        $phdrCount = 2;
        $shdrCount = 3; // null + .text + .shstrtab

        $ehdrSize = 64;
        $phdrSize = 56;
        $shdrSize = 64;

        $phdrOffset = $ehdrSize;
        $shdrOffset = $fileStart + $loadFileSize;
        $totalFileSize = $shdrOffset + $shdrCount * $shdrSize;

        // Section string table
        $shstrtab = "\0.shstrtab\0.text\0";

        // ---- ELF Header ----
        $this->put8(0x7F); $this->put('E'); $this->put('L'); $this->put('F');
        $this->put8(self::ELFCLASS64);    // EI_CLASS
        $this->put8(self::ELFDATA2LSB);   // EI_DATA
        $this->put8(self::EV_CURRENT);    // EI_VERSION
        $this->put8(self::ELFOSABI_LINUX);// EI_OSABI
        $this->put8(0);                   // EI_ABIVERSION
        $this->put(str_repeat("\x00", 7)); // EI_PAD

        $this->put16(self::ET_EXEC);      // e_type
        $this->put16(self::EM_X86_64);    // e_machine
        $this->put32(self::EV_CURRENT);   // e_version
        $this->put64($entryVA);           // e_entry
        $this->put64($phdrOffset);        // e_phoff
        $this->put64($shdrOffset);        // e_shoff
        $this->put32(0);                  // e_flags
        $this->put16($ehdrSize);          // e_ehsize
        $this->put16($phdrSize);          // e_phentsize
        $this->put16($phdrCount);         // e_phnum
        $this->put16($shdrSize);          // e_shentsize
        $this->put16($shdrCount);         // e_shnum
        $this->put16($shdrCount - 1);     // e_shstrndx

        // ---- Program Headers ----
        // PT_LOAD: code + data (R+X)
        $this->put32(self::PT_LOAD);
        $this->put32(self::PF_R | self::PF_X);
        $this->put64($fileStart);
        $this->put64($textVA);
        $this->put64($textVA);
        $this->put64($loadFileSize);
        $this->put64($loadMemSize);
        $this->put64(self::PAGE_SIZE);

        // PT_GNU_STACK: RW, no-exec
        $this->put32(self::PT_GNU_STACK);
        $this->put32(self::PF_R | self::PF_W);
        $this->put64(0); $this->put64(0); $this->put64(0);
        $this->put64(0); $this->put64(0); $this->put64(0);

        // ---- Pad to page alignment ----
        $this->padTo($fileStart);

        // ---- .text section ----
        $this->put($this->code);
        $this->padTo($fileStart + $loadFileSize);

        // ---- Section Headers ----
        // [0] NULL
        $this->put(str_repeat("\x00", 64));

        // [1] .text
        $this->put32(11);                              // sh_name → ".text"
        $this->put32(self::SHT_PROGBITS);
        $this->put64(self::SHF_ALLOC | self::SHF_EXECINSTR);
        $this->put64($textVA);
        $this->put64($fileStart);
        $this->put64($codeSize);
        $this->put32(0); $this->put32(0);
        $this->put64(16); $this->put64(0);

        // [2] .shstrtab
        $shstrtabOffset = $this->fileOffset + 64;
        $this->put32(1);                               // sh_name
        $this->put32(self::SHT_STRTAB);
        $this->put64(0);
        $this->put64(0);
        $this->put64($shstrtabOffset);
        $this->put64(strlen($shstrtab));
        $this->put32(0); $this->put32(0);
        $this->put64(1); $this->put64(0);

        // Write .shstrtab
        $this->put($shstrtab);

        fclose($this->fh);
    }

    private function put(string $data): void
    {
        fwrite($this->fh, $data);
        $this->fileOffset += strlen($data);
    }

    private function put8(int $b): void
    {
        fwrite($this->fh, chr($b & 0xFF));
        $this->fileOffset++;
    }

    private function put16(int $v): void
    {
        fwrite($this->fh, pack('v', $v));
        $this->fileOffset += 2;
    }

    private function put32(int $v): void
    {
        fwrite($this->fh, pack('V', $v));
        $this->fileOffset += 4;
    }

    private function put64(int $v): void
    {
        fwrite($this->fh, pack('P', $v));
        $this->fileOffset += 8;
    }

    private function padTo(int $target): void
    {
        while ($this->fileOffset < $target) {
            $this->put8(0);
        }
    }

    private static function alignUp(int $v, int $a): int
    {
        return ($v + $a - 1) & ~($a - 1);
    }
}
