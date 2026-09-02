<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presta_shop_settings', function (Blueprint $table): void {
            $table->text('webservice_key')->nullable()->after('password');
            $table->unsignedInteger('id_category_default')->nullable()->after('shop_url');
            $table->string('delivery_label', 120)->nullable()->after('id_category_default');
        });
    }

    public function down(): void
    {
        Schema::table('presta_shop_settings', function (Blueprint $table): void {
            $table->dropColumn(['webservice_key', 'id_category_default', 'delivery_label']);
        });
    }
};
