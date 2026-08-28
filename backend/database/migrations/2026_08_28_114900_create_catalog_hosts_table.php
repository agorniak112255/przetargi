<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_hosts', function (Blueprint $table): void {
            $table->id();
            $table->string('host', 190)->unique();
            $table->unsignedInteger('pages_count')->default(0);
            $table->unsignedInteger('off_host_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_hosts');
    }
};
