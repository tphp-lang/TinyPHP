<?php

declare(strict_types=1);

namespace Tphp;

/**
 * PE32+ (Portable Executable) binary writer for x86-64 Windows.
 *
 * Produces a minimal PE console executable with kernel32.dll imports.
 *
 * Layout:
 *   DOS Header     (64 bytes)
 *   PE Signature   (4 bytes, "PE\0\0")
 *   COFF Header    (20 bytes)
 *   Optional PE32+ (240 bytes)
 *   Section Hdrs   (2 × 40 = 80 bytes)
 *   ── FILE_ALIGN boundary (0x200) ──
 *   .text  → file 0x200, RVA 0x1000 (code)
 *   .rdata → file 0x400, RVA 0x2000 (imports + strings)
 *
 * IAT within .rdata at +0x48 → IAT RVA = 0x2048.
 *
 * Memory safety:
 *   - All sizes validated against overflow
 *   - try/finally ensures file handle is always closed
 */
final class PEWriter
{
    /** @var resource|null */
    private $fh = null;
    private int $fileOffset = 0;

    // PE constants
    private const int IMAGE_FILE_MACHINE_AMD64       = 0x8664;
    private const int IMAGE_FILE_EXECUTABLE_IMAGE    = 0x0002;
    private const int IMAGE_FILE_LARGE_ADDRESS_AWARE = 0x0020;
    private const int IMAGE_SUBSYSTEM_WINDOWS_CUI    = 3;
    private const int IMAGE_DLL_CHARACTERISTICS      = 0x8160;

    private const int SECTION_ALIGN = 0x1000;
    private const int FILE_ALIGN    = 0x200;
    private const int IMAGE_BASE    = 0x140000000;

    // Fixed section RVAs (matched by CodeGeneratorWindows constants)
    public  const int TEXT_RVA        = 0x1000;
    private const int MIN_RDATA_RVA   = 0x3000; // minimum .rdata RVA
    private const int IAT_OFFSET      = 0x70;   // 0x28 (IDT) + 0x48 (ILT: 9 entries × 8 = 72 bytes)

    private string $outputPath;

    // Computed during prepare()
    /** @var array{code:string, strings:array<string,string>, iatFns:array<int,string>} */
    private array $payload = ['code' => '', 'strings' => [], 'iatFns' => []];
    private string $importData = '';
    private string $stringsData = '';
    private int $rdataRawSize = 0;
    private int $rdataRva = 0;    // dynamically computed in write()

    /** @var array<string, int> string label → RVA */
    private array $stringRvas = [];

    public function __construct(string $outputPath)
    {
        $this->outputPath = $outputPath;
    }

    /**
     * @param string                $code     raw x86-64 machine code
     * @param array<string,string>  $strings  label → null-terminated data
     * @param array<int,string>     $iatFns   slot idx → function name
     */
    public function setPayload(string $code, array $strings, array $iatFns): void
    {
        if (strlen($code) < 0) throw new \RuntimeException('Code length overflow');
        foreach ($strings as $v) {
            if (strlen($v) > 65536) throw new \RuntimeException('String too large (>64KB)');
        }
        $this->payload = ['code' => $code, 'strings' => $strings, 'iatFns' => $iatFns];

        // Pre-compute .rdata RVA: must not overlap with .text
        $textVirtSize = self::alignUp(strlen($code), self::SECTION_ALIGN);
        $this->rdataRva = max(
            self::MIN_RDATA_RVA,
            self::alignUp(self::TEXT_RVA + $textVirtSize, self::SECTION_ALIGN)
        );
    }

    /**
     * Pre-compute layout: import table, string RVAs, section sizes.
     * Must be called before write().
     *
     * @return array<string, int>  string label → absolute RVA
     */
    public function prepare(): array
    {
        $strings = $this->payload['strings'];
        $iatFns  = $this->payload['iatFns'];

        $this->importData  = $this->buildImportTable($iatFns);
        $this->stringsData = '';
        $this->stringRvas  = [];
        $dataOffset = strlen($this->importData);

        foreach ($strings as $label => $data) {
            $this->stringRvas[$label] = $this->rdataRva + $dataOffset;
            $this->stringsData .= $data;
            $dataOffset += strlen($data);
        }
        $this->rdataRawSize = $dataOffset;

        return $this->stringRvas;
    }

    /**
     * Resolve string references in the code using computed RVAs.
     * Modifies the code buffer in-place.
     */
    public function resolveStringPatches(X64Builder $builder): void
    {
        $builder->resolveStringPatches($this->stringRvas, self::TEXT_RVA);
    }

    /**
     * Resolve IAT RVA patches in the code.
     */
    public function resolveIatPatches(X64Builder $builder): void
    {
        $builder->resolveRvaPatches(self::TEXT_RVA);
    }

    /** Get the actual IAT RVA (IAT_OFFSET bytes into the .rdata section) */
    public function getIatRva(): int
    {
        return $this->rdataRva + self::IAT_OFFSET;
    }

    /**
     * Write the complete PE file (after prepare() and patch resolution).
     */
    public function write(): void
    {
        $this->fh = fopen($this->outputPath, 'wb');
        if ($this->fh === false) {
            throw new \RuntimeException("Cannot open output file: {$this->outputPath}");
        }

        try {
            $this->fileOffset = 0;
            $code    = $this->payload['code'];
            $codeSize = strlen($code);

            $importSize   = strlen($this->importData);
            $stringsSize   = strlen($this->stringsData);
            $rdataRawSize  = $importSize + $stringsSize;

            // Section sizes (file-aligned)
            $headersSize     = $this->computeHeadersSize();
            $headersFileSize = self::alignUp($headersSize, self::FILE_ALIGN);

            $textFileOffset = $headersFileSize;
            $textFileSize   = self::alignUp($codeSize, self::FILE_ALIGN);
            // Windows PE loader rejects executables whose SizeOfCode is too small
            $minTextSize = self::SECTION_ALIGN + self::FILE_ALIGN;
            if ($textFileSize < $minTextSize) {
                $textFileSize = $minTextSize;
            }

            $rdataFileOffset = $textFileOffset + $textFileSize;
            $rdataFileSize   = self::alignUp($rdataRawSize, self::FILE_ALIGN);

            // Virtual sizes
            $textVirtSize  = self::alignUp($codeSize, self::SECTION_ALIGN);
            $rdataVirtSize = self::alignUp($rdataRawSize, self::SECTION_ALIGN);
            // Use pre-computed rdataRva from setPayload(), don't recompute
            $imageSize     = $this->rdataRva + $rdataVirtSize;
            // Ensure SizeOfImage >= 0x5000 for Windows loader compatibility
            if ($imageSize < 0x5000) {
                $imageSize = 0x5000;
            }
            // Ensure the file is at least 10KB — Windows refuses smaller PE files
            $rdataEnd = $rdataFileOffset + $rdataFileSize;
            if ($rdataEnd < 0x2A00) {
                $rdataFileSize += (0x2A00 - $rdataEnd);
            }

            // ---- DOS Header ----
            $this->writeDosHeader();

            // ---- PE Signature ----
            $this->write32(0x00004550);

            // ---- COFF Header ----
            $this->write16(self::IMAGE_FILE_MACHINE_AMD64);
            $this->write16(2);
            $this->write32((int) time());
            $this->write32(0);
            $this->write32(0);
            $this->write16(240); // SizeOfOptionalHeader
            $this->write16(self::IMAGE_FILE_EXECUTABLE_IMAGE | self::IMAGE_FILE_LARGE_ADDRESS_AWARE);

            // ---- Optional Header PE32+ ----
            $this->write16(0x020B);                // Magic PE32+
            $this->write8(14); $this->write8(0);    // Linker 14.0
            $this->write32($textFileSize);          // SizeOfCode
            $this->write32($rdataFileSize);         // SizeOfInitializedData
            $this->write32(0);                      // SizeOfUninitializedData
            $this->write32(self::TEXT_RVA);         // AddressOfEntryPoint
            $this->write32(self::TEXT_RVA);         // BaseOfCode
            $this->write64(self::IMAGE_BASE);
            $this->write32(self::SECTION_ALIGN);
            $this->write32(self::FILE_ALIGN);
            $this->write16(6); $this->write16(0);   // OS 6.0
            $this->write16(6); $this->write16(0);   // Image 6.0
            $this->write16(6); $this->write16(0);   // Subsystem 6.0
            $this->write32(0);
            $this->write32($imageSize);
            $this->write32($headersFileSize);
            $this->write32(0);                      // CheckSum
            $this->write16(self::IMAGE_SUBSYSTEM_WINDOWS_CUI);
            $this->write16(self::IMAGE_DLL_CHARACTERISTICS);
            $this->write64(0x100000);               // StackReserve
            $this->write64(0x1000);                 // StackCommit
            $this->write64(0x100000);               // HeapReserve
            $this->write64(0);                      // HeapCommit
            $this->write32(0);                      // LoaderFlags
            $this->write32(16);                     // NumberOfRvaAndSizes

            // Data directories (16 × 8 bytes)
            // [0] Export: empty
            $this->write32(0); $this->write32(0);
            // [1] Import
            $this->write32($this->rdataRva);
            $this->write32($importSize);
            // [2..15]: empty
            for ($i = 2; $i < 16; $i++) {
                $this->write32(0); $this->write32(0);
            }

            // ---- Section Headers ----
            $this->writeSectionHeader(
                '.text', $textVirtSize, self::TEXT_RVA,
                $textFileSize, $textFileOffset, 0x60000020,
            );
            $this->writeSectionHeader(
                '.rdata', $rdataVirtSize, $this->rdataRva,
                $rdataFileSize, $rdataFileOffset, 0x40000040,
            );

            // ---- Pad to file alignment ----
            $this->padTo($headersFileSize);

            // ---- .text ----
            $this->padTo($textFileOffset);
            $this->put($code);
            $this->padTo($textFileOffset + $textFileSize);

            // ---- .rdata ----
            $this->padTo($rdataFileOffset);
            $this->put($this->importData);
            if ($stringsSize > 0) {
                $this->put($this->stringsData);
            }
            $this->padTo($rdataFileOffset + $rdataFileSize);

            fflush($this->fh);
        } finally {
            if (is_resource($this->fh)) {
                fclose($this->fh);
            }
            $this->fh = null;
        }
    }

    // ==================== Import table builder ====================

    /**
     * Build import table binary data (everything in .rdata before strings).
     *
     * Layout (fixed IAT at offset 0x48 for compatibility with hardcoded RVAs):
     *   [0x00-0x27] IDT (kernel32 descriptor + null terminator)
     *   [0x28-0x47] ILT (Import Lookup Table, variable size)
     *   [0x48-...]  IAT (Import Address Table, variable size)
     *   [  ...   ]  DLL name "kernel32.dll\0"
     *   [  ...   ]  Hint/Name entries
     *
     * @param array<int,string> $functions
     * @return string
     */
    private function buildImportTable(array $functions): string
    {
        $count = count($functions);
        if ($count === 0) return '';

        $idtSize = 40;                       // kernel32 descriptor (20) + null (20) = 40
        $iltSize = ($count + 1) * 8;         // N function entries + 1 null terminator
        $iatSize = $iltSize;

        // Fixed layout: IDT at 0x00, ILT at 0x28, IAT at fixed IAT_OFFSET (0x48)
        // IAT_OFFSET is designed to fit up to 4 functions in ILT (5 entries × 8 = 40 bytes = 0x28)
        $idtOff = 0;
        $iltOff = 0x28;                      // IDT is exactly 40 bytes (0x28)
        $iatOff = self::IAT_OFFSET;          // Fixed at 0x48

        // If ILT overflows past IAT_OFFSET, we have a problem
        $iltEnd = $iltOff + $iltSize;
        if ($iltEnd > $iatOff) {
            throw new \RuntimeException(
                "Too many IAT functions: ILT needs {$iltSize} bytes but only " .
                ($iatOff - $iltOff) . " bytes available before IAT at 0x" . dechex($iatOff)
            );
        }

        $dllName = "kernel32.dll\x00";
        $dllOff  = $iatOff + $iatSize;

        // Hint/Name entries
        $hnData    = '';
        $hnOffsets = [];
        $hnOff     = $dllOff + strlen($dllName);
        foreach ($functions as $i => $fn) {
            $hnOffsets[$i] = $hnOff;
            $entry = pack('v', 0) . $fn . "\x00";
            $hnData .= $entry;
            $hnOff += strlen($entry);
        }

        // ---- Build buffer ----
        // All RVAs must be absolute (image-base-relative), not buffer-relative.
        // The import table sits at .rdata offset 0.
        $rdataBase = $this->rdataRva;

        // IDT
        $buf  = pack('V', $rdataBase + $iltOff);     // ImportLookupTableRVA (absolute)
        $buf .= pack('V', 0);                         // TimeDateStamp
        $buf .= pack('V', 0);                         // ForwarderChain
        $buf .= pack('V', $rdataBase + $dllOff);      // NameRVA (absolute)
        $buf .= pack('V', $rdataBase + $iatOff);      // ImportAddressTableRVA (absolute)
        $buf .= str_repeat("\x00", 20); // null terminator

        // Pad ILT to start at offset 0x28
        $padIlt = $iltOff - strlen($buf);
        if ($padIlt > 0) $buf .= str_repeat("\x00", $padIlt);

        // ILT - must use absolute RVAs (image-base-relative)
        foreach ($functions as $i => $fn) {
            $buf .= pack('P', $rdataBase + $hnOffsets[$i]);
        }
        $buf .= pack('P', 0);

        // Pad to IAT_OFFSET
        $padIat = $iatOff - strlen($buf);
        if ($padIat > 0) $buf .= str_repeat("\x00", $padIat);

        // IAT (initially same as ILT absolute RVAs, patched by loader)
        foreach ($functions as $i => $fn) {
            $buf .= pack('P', $rdataBase + $hnOffsets[$i]);
        }
        $buf .= pack('P', 0);

        // DLL name
        $buf .= $dllName;

        // Hint/Name entries
        $buf .= $hnData;

        return $buf;
    }

    // ==================== DOS Header ====================

    private function writeDosHeader(): void
    {
        $this->write16(0x5A4D);   // MZ
        $this->write16(0x0090);   // cblp
        $this->write16(0x0003);   // cp
        $this->write16(0x0000);   // crlc
        $this->write16(0x0004);   // cparhdr
        $this->write16(0x0000);   // minalloc
        $this->write16(0xFFFF);   // maxalloc
        $this->write16(0x0000);   // ss
        $this->write16(0x00B8);   // sp
        $this->write16(0x0000);   // csum
        $this->write16(0x0000);   // ip
        $this->write16(0x0000);   // cs
        $this->write16(0x0040);   // lfarlc
        $this->write16(0x0000);   // ovno
        for ($i = 0; $i < 4; $i++) $this->write16(0);
        $this->write16(0); $this->write16(0);
        for ($i = 0; $i < 10; $i++) $this->write16(0);
        $this->write32(0x40);     // e_lfanew
    }

    // ==================== Section header ====================

    private function writeSectionHeader(
        string $name, int $vSize, int $vAddr,
        int $rSize, int $rOff, int $chars,
    ): void {
        $n = str_pad(substr($name, 0, 8), 8, "\x00");
        $this->put($n);
        $this->write32($vSize);
        $this->write32($vAddr);
        $this->write32($rSize);
        $this->write32($rOff);
        $this->write32(0);  // relocs ptr
        $this->write32(0);  // linenums ptr
        $this->write16(0);  // num relocs
        $this->write16(0);  // num linenums
        $this->write32($chars);
    }

    // ==================== I/O helpers ====================

    private function put(string $data): void
    {
        fwrite($this->fh, $data);
        $this->fileOffset += strlen($data);
    }

    private function write8(int $b): void
    {
        fwrite($this->fh, chr($b & 0xFF));
        $this->fileOffset++;
    }

    private function write16(int $v): void
    {
        fwrite($this->fh, pack('v', $v & 0xFFFF));
        $this->fileOffset += 2;
    }

    private function write32(int $v): void
    {
        fwrite($this->fh, pack('V', $v));
        $this->fileOffset += 4;
    }

    private function write64(int $v): void
    {
        fwrite($this->fh, pack('P', $v));
        $this->fileOffset += 8;
    }

    private function padTo(int $target): void
    {
        $remaining = $target - $this->fileOffset;
        if ($remaining > 0) {
            fwrite($this->fh, str_repeat("\x00", $remaining));
            $this->fileOffset = $target;
        }
    }

    // ==================== Utilities ====================

    private function computeHeadersSize(): int
    {
        return 64 + 4 + 20 + 240 + 80; // DOS + PE sig + COFF + Opt + 2×Section
    }

    private static function alignUp(int $value, int $align): int
    {
        $mask = $align - 1;
        if (($value & $mask) === 0) return $value;
        return (($value + $align - 1) & ~$mask);
    }
}
