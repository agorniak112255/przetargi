<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('mailer', 30)->default('smtp');
            $table->string('host', 255)->nullable();
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('username', 255)->nullable();
            $table->text('password')->nullable();
            $table->string('scheme', 20)->nullable();
            $table->string('from_address', 255)->nullable();
            $table->string('from_name', 120)->nullable();
            $table->boolean('verify_peer')->default(true);
            $table->timestamps();
        });

        DB::table('mail_settings')->insert([
            'mailer' => (string) env('MAIL_MAILER', 'smtp'),
            'host' => env('MAIL_HOST'),
            'port' => (int) env('MAIL_PORT', 587),
            'username' => env('MAIL_USERNAME'),
            'password' => null,
            'scheme' => env('MAIL_SCHEME'),
            'from_address' => env('MAIL_FROM_ADDRESS'),
            'from_name' => env('MAIL_FROM_NAME', 'Przetargi Supon'),
            'verify_peer' => filter_var(env('MAIL_VERIFY_PEER', true), FILTER_VALIDATE_BOOL),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
