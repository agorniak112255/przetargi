<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forma oferty (06.10.2026): body — produkty w treści maila (jak dotąd), pdf — krótki mail z ofertą w załączniku PDF,
 * both — treść maila i PDF. Zapamiętana przy ofercie (offers.delivery); każda wysyłka zapisuje użytą formę i plik PDF,
 * który dostał klient (offer_sends.pdf_path, dysk local). Prośba „Otwórz w Thunderbirdzie” wskazuje ofertę i to, czy
 * dodatek ma dołączyć jej PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->string('delivery', 10)->default('body');
        });

        Schema::table('offer_sends', function (Blueprint $table): void {
            $table->string('delivery', 10)->default('body');
            // storage/app/offer-sends/{offer_id}/{send_id}.pdf — plik zostaje po usunięciu oferty
            $table->string('pdf_path', 255)->nullable();
        });

        Schema::table('offer_compose_requests', function (Blueprint $table): void {
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->boolean('attach_pdf')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('offer_compose_requests', function (Blueprint $table): void {
            $table->dropForeign(['offer_id']);
        });
        Schema::table('offer_compose_requests', function (Blueprint $table): void {
            $table->dropColumn(['offer_id', 'attach_pdf']);
        });
        Schema::table('offer_sends', function (Blueprint $table): void {
            $table->dropColumn(['delivery', 'pdf_path']);
        });
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn('delivery');
        });
    }
};
