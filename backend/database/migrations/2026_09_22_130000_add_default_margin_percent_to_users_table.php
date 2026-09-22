<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Marża, z którą startuje każda nowa odpowiedź na zapytanie. Każde konto
            // (także już istniejące) zaczyna od 18%, użytkownik zmienia ją w „Moje konto”.
            $table->decimal('default_margin_percent', 5, 2)->default(18)->after('ui_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('default_margin_percent');
        });
    }
};
