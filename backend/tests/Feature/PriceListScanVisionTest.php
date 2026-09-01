<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\PriceListAiAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PriceListScanVisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'openai/gpt-4o',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
        ]);
    }

    public function test_scan_pdf_is_read_via_page_images(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"c":"PLN","m":"Maskpol","p":[["MT-212-2","Maska MT-212-2",199.0,"Maski"]]}'],
                    'finish_reason' => 'stop',
                ]],
                'model' => 'openai/gpt-4o',
            ], 200),
        ]);

        $path = $this->writeFlateDctScanPdf();
        try {
            $result = app(PriceListAiAnalyzer::class)->analyze(
                $path,
                'Maskpol',
                'Cennik BHP nowe produkty.pdf',
            );
        } finally {
            @unlink($path);
        }

        $this->assertSame('pdf-vision-images', $result['source']);
        $this->assertSame(1, $result['products_found']);
        $this->assertSame('MT-212-2', $result['products'][0]['sku'] ?? null);
        $this->assertSame('Maskpol', $result['meta']['manufacturer'] ?? null);
        Http::assertSentCount(1);
    }

    private function writeFlateDctScanPdf(): string
    {
        $im = imagecreatetruecolor(32, 32);
        $this->assertNotFalse($im);
        imagefilledrectangle($im, 0, 0, 31, 31, imagecolorallocate($im, 240, 240, 240));
        ob_start();
        imagejpeg($im, null, 80);
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);
        $flate = gzcompress($jpeg);
        $this->assertIsString($flate);
        $len = strlen($flate);
        $body = "%PDF-1.4\n1 0 obj\n<</Type/XObject/Subtype/Image/Width 1240/Height 1754"
            ."/ColorSpace/DeviceRGB/BitsPerComponent 8/Filter[/FlateDecode/DCTDecode]/Length {$len}>>\nstream\n"
            .$flate
            ."\nendstream\nendobj\n";
        $path = tempnam(sys_get_temp_dir(), 'scanvis').'.pdf';
        file_put_contents($path, $body);

        return $path;
    }
}
