<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adresy e-mail klientów XL znalezione poza ERP XL (prośba właściciela 06.10.2026: 271 z 331 klientów z terminami
 * przeglądów nie ma adresu na karcie). Źródła: strona WWW firmy i katalogi firm (najpierw), potem rejestry REGON/CEIDG.
 * Każdy adres to propozycja z dowodem (adres strony, czy na niej jest NIP klienta) — do oferty trafia dopiero po
 * zatwierdzeniu przez człowieka („Użyj”). ERP XL jest tylko czytany — kartę w XL uzupełnia się ręcznie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_email_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('customer_xl_gid');
            $table->string('email', 254);
            // website (strona firmy) | directory (katalog firm) | regon | ceidg
            $table->string('source', 20);
            $table->string('source_url', 1000)->nullable();
            $table->string('source_host', 255)->nullable();
            // nip — na stronie źródłowej jest NIP klienta; name — strona firmy o nazwie podobnej do klienta, bez NIP-u
            $table->string('evidence', 10);
            // pending | accepted | rejected
            $table->string('status', 10)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            // useCurrent: MariaDB z explicit_defaults_for_timestamp=0 nie przyjmie drugiej kolumny czasu NOT NULL bez domyślnej
            $table->timestamp('found_at')->useCurrent();
            $table->timestamps();

            $table->unique(['customer_xl_gid', 'email'], 'cust_email_sugg_customer_email_unique');
            $table->index(['customer_xl_gid', 'status'], 'cust_email_sugg_customer_status_index');
        });

        Schema::create('customer_email_lookups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('customer_xl_gid')->unique();
            // ostatnie szukanie w sieci: kiedy, ile propozycji, błąd (null = bez błędu)
            $table->timestamp('checked_at')->useCurrent();
            $table->unsignedSmallInteger('found')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_email_lookups');
        Schema::dropIfExists('customer_email_suggestions');
    }
};
