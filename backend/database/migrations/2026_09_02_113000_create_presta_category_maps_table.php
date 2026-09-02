<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presta_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('presta_id')->unique();
            $table->unsignedInteger('parent_presta_id')->default(0);
            $table->string('name', 255);
            $table->string('path', 500)->default('');
            $table->unsignedTinyInteger('level_depth')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('presta_category_maps', function (Blueprint $table): void {
            $table->id();
            $table->string('local_category', 255)->unique();
            $table->unsignedInteger('presta_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presta_category_maps');
        Schema::dropIfExists('presta_categories');
    }
};
