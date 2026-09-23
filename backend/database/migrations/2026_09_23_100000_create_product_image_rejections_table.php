<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zdjęcia usunięte z karty świadomie — ręcznie w panelu albo przez products:images-audit --apply (23.09.2026).
 *
 * Sam wiersz product_images nie wystarcza: synchronizacja B2B dokłada każde zdjęcie z galerii dostawcy, którego
 * karta nie ma, a wzbogacanie pobiera ponownie to, co znajdzie w sieci. Bez śladu odrzucenia usunięte zdjęcie
 * wracało przy następnym przebiegu. Tu zostaje klucz pliku (ProductImageDownloader::sameFileKey) i suma
 * kontrolna — oba sprawdza zapis zdjęcia, zanim cokolwiek pobierze.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_image_rejections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // sha256 klucza pliku — sam klucz bywa adresem do 2000 znaków, za długim na indeks
            $table->char('file_key_hash', 64);
            $table->text('file_key');
            $table->char('checksum', 64)->nullable();
            $table->text('source_url')->nullable();
            // manual = panel, audit = products:images-audit --apply
            $table->string('reason', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'file_key_hash']);
            $table->index(['product_id', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_image_rejections');
    }
};
