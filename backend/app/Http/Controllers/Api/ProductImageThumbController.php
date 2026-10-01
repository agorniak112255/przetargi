<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductImage;
use App\Services\ProductImageThumbService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class ProductImageThumbController extends Controller
{
    public function __construct(
        private readonly ProductImageThumbService $thumbs,
    ) {}

    public function show(ProductImage $image): Response|RedirectResponse
    {
        return $this->respond($image, $this->thumbs->jpeg($image));
    }

    /** Kwadrat na białym tle do maili kampanii — publiczny jak miniatura (klient otwiera mail bez logowania). */
    public function square(ProductImage $image): Response|RedirectResponse
    {
        return $this->respond($image, $this->thumbs->squareJpeg($image));
    }

    private function respond(ProductImage $image, ?string $jpeg): Response|RedirectResponse
    {
        if ($jpeg === null) {
            return redirect()->away($image->url());
        }

        return response($jpeg, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=2592000',
        ]);
    }
}
