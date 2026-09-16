<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rabaty konta B2B dla witryn, które podają tylko cenę katalogową (np. protekt.pl).
        // Rabat leży tutaj, nie w assortment_groups: tamtą tabelą zarządza import arkuszy
        // (AssortmentGroupService::upsertGroup nadpisuje discount_percent bezwarunkowo), więc
        // import cennika tego samego producenta wyzerowałby rabaty konta bez śladu.
        Schema::create('b2b_discount_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('b2b_account_id')->constrained('b2b_accounts')->cascadeOnDelete();
            // Kolejność sprawdzania — pierwsza pasująca reguła wygrywa.
            $table->unsignedInteger('position')->default(0);
            $table->string('name', 150);
            // Pole karty dostawcy, do którego przykładamy wzorzec.
            $table->string('match_field', 20);
            $table->string('match_type', 20);
            $table->string('pattern', 255);
            $table->decimal('discount_percent', 5, 2)->default(0);
            // Tylko do oznaczenia karty grupą asortymentową; rabat bierzemy z discount_percent powyżej.
            $table->foreignId('assortment_group_id')->nullable()
                ->constrained('assortment_groups')->nullOnDelete();
            // Ile kart trafiło w regułę w ostatnim przebiegu — puste reguły widać w panelu.
            $table->unsignedInteger('last_matched_count')->default(0);
            $table->timestamp('last_matched_at')->nullable();
            $table->timestamps();

            $table->index(['b2b_account_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_discount_rules');
    }
};
