<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('presta_shop_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('host', 255)->nullable();
            $table->unsignedSmallInteger('port')->default(3306);
            $table->string('database_name', 128)->nullable();
            $table->string('username', 128)->nullable();
            $table->text('password')->nullable();
            $table->string('table_prefix', 16)->default('ps_');
            $table->unsignedTinyInteger('id_lang')->default(1);
            $table->string('shop_url', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presta_shop_settings');
    }
};
