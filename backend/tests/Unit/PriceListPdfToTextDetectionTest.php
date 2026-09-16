<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListPdfTextExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Produkcja to Linux — składnia cmd.exe (`where`, `2>NUL`) sprawiała, że pdftotext
 * nigdy nie był tam znajdowany i cały import PDF leciał fallbackiem smalot.
 */
final class PriceListPdfToTextDetectionTest extends TestCase
{
    private function call(string $method, string $osFamily): mixed
    {
        $m = new ReflectionMethod(PriceListPdfTextExtractor::class, $method);
        $m->setAccessible(true);

        return $m->invoke(null, $osFamily);
    }

    public function test_posix_uses_sh_syntax(): void
    {
        $this->assertSame('command -v pdftotext', $this->call('whichCommand', 'Linux'));
        $this->assertSame('2>/dev/null', $this->call('redirectStderr', 'Linux'));
        $this->assertContains('/usr/bin/pdftotext', $this->call('binaryCandidates', 'Linux'));
    }

    public function test_windows_keeps_cmd_syntax(): void
    {
        $this->assertSame('where pdftotext', $this->call('whichCommand', 'Windows'));
        $this->assertSame('2>NUL', $this->call('redirectStderr', 'Windows'));
        foreach ($this->call('binaryCandidates', 'Windows') as $candidate) {
            $this->assertStringEndsWith('pdftotext.exe', $candidate);
        }
    }

    public function test_no_cmd_syntax_leaks_into_posix_commands(): void
    {
        foreach (['Linux', 'Darwin', 'BSD', 'Solaris'] as $family) {
            $this->assertStringNotContainsString('NUL', $this->call('redirectStderr', $family));
            $this->assertStringNotContainsString('where', $this->call('whichCommand', $family));
            foreach ($this->call('binaryCandidates', $family) as $candidate) {
                $this->assertStringStartsWith('/', $candidate);
            }
        }
    }

    public function test_finds_binary_when_present_on_this_machine(): void
    {
        $find = new ReflectionMethod(PriceListPdfTextExtractor::class, 'findPdfToText');
        $find->setAccessible(true);
        $bin = $find->invoke(new PriceListPdfTextExtractor);

        if ($bin === null) {
            $this->markTestSkipped('pdftotext niedostępny na tej maszynie.');
        }

        $this->assertFileExists($bin);
    }
}
