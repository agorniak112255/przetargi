<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CampaignAsset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Obrazki do maili kampanii: wgranie z przekodowaniem przez GD i publiczny odczyt z bezpiecznymi nagłówkami. */
final class CampaignAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        config(['campaigns.public_url' => 'https://przetargi.example.pl']);
    }

    public function test_jpg_is_reencoded_and_scaled_to_max_width(): void
    {
        $user = User::factory()->withRole('handlowiec')->create();
        Sanctum::actingAs($user);

        $res = $this->post('/api/campaign-assets', ['file' => $this->upload('baner.jpg', $this->image(2400, 600), 'jpeg')], ['Accept' => 'application/json'])
            ->assertCreated();
        $uuid = $res->json('uuid');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid);
        $this->assertSame(['uuid' => $uuid, 'url' => 'https://przetargi.example.pl/api/campaign-assets/'.$uuid, 'width' => 1200, 'height' => 300], $res->json());

        $asset = CampaignAsset::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(['campaign-assets/'.$uuid.'.jpg', 'image/jpeg', $user->id], [$asset->path, $asset->mime, $asset->user_id]);
        $bytes = Storage::disk('local')->get($asset->path);
        $this->assertSame(strlen($bytes), $asset->size);
        $this->assertSame([1200, 300, IMAGETYPE_JPEG], array_slice(getimagesizefromstring($bytes), 0, 3));
    }

    public function test_png_with_alpha_stays_png_and_without_alpha_becomes_jpeg(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $res = $this->post('/api/campaign-assets', ['file' => $this->upload('logo.png', $this->image(300, 100, alpha: true), 'png')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('width', 300)->assertJsonPath('height', 100);
        $logo = CampaignAsset::query()->where('uuid', $res->json('uuid'))->firstOrFail();
        $this->assertSame('image/png', $logo->mime);
        $this->assertStringEndsWith('.png', $logo->path);
        // przezroczystość zachowana
        $out = imagecreatefromstring(Storage::disk('local')->get($logo->path));
        $this->assertSame(127, (imagecolorat($out, 1, 1) >> 24) & 0x7F);

        $res = $this->post('/api/campaign-assets', ['file' => $this->upload('zdjecie.png', $this->image(200, 100), 'png')], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('image/jpeg', CampaignAsset::query()->where('uuid', $res->json('uuid'))->value('mime'));

        $res = $this->post('/api/campaign-assets', ['file' => $this->upload('baner.webp', $this->image(1500, 500), 'webp')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('width', 1200)->assertJsonPath('height', 400);
        $this->assertSame('image/jpeg', CampaignAsset::query()->where('uuid', $res->json('uuid'))->value('mime'));
    }

    public function test_non_images_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        foreach (['logo.jpg' => $svg, 'shell.jpg' => '<?php system($_GET["c"]); ?>', 'notatka.jpg' => 'zwykły tekst', 'logo.svg' => $svg] as $name => $content) {
            $this->post('/api/campaign-assets', ['file' => UploadedFile::fake()->createWithContent($name, $content)], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonPath('errors.file.0', 'To nie jest obrazek JPG, PNG, GIF ani WEBP.');
        }
        // obrazek z nazwą .jpg, ale nie JPG/PNG/GIF/WEBP (BMP) — też nie
        ob_start();
        imagebmp($this->image(10, 10));
        $bmp = (string) ob_get_clean();
        $this->post('/api/campaign-assets', ['file' => UploadedFile::fake()->createWithContent('bitmapa.jpg', $bmp)], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $this->assertSame(0, CampaignAsset::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_upload_requires_campaigns_permission(): void
    {
        $this->post('/api/campaign-assets', [], ['Accept' => 'application/json'])->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->post('/api/campaign-assets', ['file' => $this->upload('a.jpg', $this->image(10, 10), 'jpeg')], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_public_get_with_safe_headers_and_404(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $uuid = $this->post('/api/campaign-assets', ['file' => $this->upload('a.jpg', $this->image(40, 20), 'jpeg')], ['Accept' => 'application/json'])->json('uuid');
        $this->app['auth']->forgetGuards();

        // bez logowania — klient otwiera obrazek z maila
        $res = $this->get('/api/campaign-assets/'.$uuid)->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'immutable'], array_map('trim', explode(',', (string) $res->headers->get('Cache-Control'))));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'", $res->headers->get('Content-Security-Policy'));
        $this->assertSame('inline', $res->headers->get('Content-Disposition'));
        $this->assertSame([40, 20], array_slice(getimagesizefromstring($res->getContent()), 0, 2));

        $this->get('/api/campaign-assets/9f9f9f9f-1111-4222-8333-444455556666')->assertNotFound();
        $this->get('/api/campaign-assets/..%2F..%2F.env')->assertNotFound();
        $this->get('/api/campaign-assets/'.strtoupper($uuid))->assertNotFound();

        // wiersz jest, pliku nie ma
        Storage::disk('local')->delete(CampaignAsset::query()->where('uuid', $uuid)->value('path'));
        $this->get('/api/campaign-assets/'.$uuid)->assertNotFound();
    }

    private function image(int $width, int $height, bool $alpha = false): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        if ($alpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagefilledrectangle($image, (int) ($width / 3), 0, (int) ($width / 2), $height - 1, imagecolorallocatealpha($image, 11, 125, 106, 0));
        } else {
            imagefill($image, 0, 0, imagecolorallocate($image, 11, 125, 106));
        }

        return $image;
    }

    private function upload(string $name, GdImage $image, string $format): UploadedFile
    {
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($image),
            'png' => imagepng($image),
            'webp' => imagewebp($image),
        };

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }
}
