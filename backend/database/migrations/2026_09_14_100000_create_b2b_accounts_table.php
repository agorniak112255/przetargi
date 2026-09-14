<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            // Szyfrowane kluczem aplikacji (cast `encrypted`), nigdy nie wraca w liście.
            $table->text('password')->nullable();
            $table->json('sites');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_accounts');
    }
};
