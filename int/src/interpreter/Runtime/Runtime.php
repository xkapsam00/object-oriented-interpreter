<?php

declare(strict_types=1);

namespace IPP\Interpreter\Runtime;

use SplFileObject;

/**
 * Minimal runtime IO adapter used by built-in methods.
 */
final class Runtime
{
    public function __construct(private readonly ?SplFileObject $inputIo = null)
    {
    }

    /**
     * Reads one input line (without line ending) or empty string on EOF/no input stream.
     */
    public function readLine(): string
    {
        if ($this->inputIo === null || $this->inputIo->eof()) {
            return '';
        }

        return rtrim($this->inputIo->fgets(), "\r\n");
    }

    /**
     * Writes text to standard output without adding line break.
     */
    public function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }
}
