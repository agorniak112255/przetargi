<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Klienci z Comarch ERP XL (erp:clients): numer kontrahenta, pełna karta, osoby kontaktowe, opiekun i zakupy w roku.
 * Klienci dopisani ręcznie zostają bez zmian (source = manual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // NIP z prefiksem i myślnikami bywa dłuższy niż 20 znaków
            $table->string('nip', 40)->nullable()->change();
            $table->string('source', 20)->default('manual')->after('owner_id');
            $table->unsignedInteger('xl_gid')->nullable()->unique()->after('source');
            $table->string('acronym', 40)->nullable()->after('name');
            $table->string('nip_prefix', 5)->nullable()->after('nip');
            $table->string('regon', 30)->nullable()->after('nip_prefix');
            $table->string('street', 200)->nullable()->after('regon');
            $table->string('address_line2', 200)->nullable()->after('street');
            $table->string('postal_code', 20)->nullable()->after('address_line2');
            $table->string('county', 100)->nullable()->after('city');
            $table->string('commune', 100)->nullable()->after('county');
            $table->string('voivodeship', 100)->nullable()->after('commune');
            $table->string('country', 100)->nullable()->after('voivodeship');
            $table->string('phone', 100)->nullable()->after('country');
            $table->string('phone2', 100)->nullable()->after('phone');
            $table->string('fax', 100)->nullable()->after('phone2');
            $table->json('emails')->nullable()->after('fax');
            $table->string('website', 255)->nullable()->after('emails');
            $table->json('contacts')->nullable()->after('website');
            $table->string('account_manager', 150)->nullable()->after('contacts');
            $table->string('account_manager_email', 150)->nullable()->after('account_manager');
            $table->boolean('xl_archived')->default(false)->after('xl_gid');
            $table->unsignedSmallInteger('sales_year')->nullable()->after('xl_archived');
            $table->decimal('sales_net', 14, 2)->nullable()->after('sales_year');
            $table->unsignedInteger('sale_documents')->nullable()->after('sales_net');
            $table->date('last_sale_at')->nullable()->after('sale_documents');
            $table->timestamp('xl_synced_at')->nullable()->after('last_sale_at');
        });
    }

    public function down(): void
    {
        // NIP zostaje 40 znaków — powrót do 20 obciąłby zapisane numery
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['xl_gid']);
            $table->dropColumn([
                'source', 'xl_gid', 'acronym', 'nip_prefix', 'regon', 'street', 'address_line2', 'postal_code', 'county',
                'commune', 'voivodeship', 'country', 'phone', 'phone2', 'fax', 'emails', 'website', 'contacts',
                'account_manager', 'account_manager_email', 'xl_archived', 'sales_year', 'sales_net', 'sale_documents',
                'last_sale_at', 'xl_synced_at',
            ]);
        });
    }
};
