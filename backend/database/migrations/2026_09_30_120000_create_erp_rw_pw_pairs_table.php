<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pary RW → PW z ERP XL (erp:rw-pw, co noc): ten sam towar wydany RW i przyjęty z powrotem PW w tej samej ilości,
 * PW najwyżej 30 dni po RW. Nowa partia z PW „odmładza” towar, który nie rotował. Dokumenty dosłownie z XL (numer,
 * data, magazyn, ilość, wartość księgowa, akronim operatora); tabela przeliczana w całości przy każdym odczycie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('erp_rw_pw_pairs', function (Blueprint $table): void {
            $table->id();
            // towar XL; erp_item_id może być pusty, gdy towaru nie ma jeszcze w kopii erp_items
            $table->unsignedInteger('xl_gid');
            $table->foreignId('erp_item_id')->nullable()->constrained('erp_items')->nullOnDelete();
            foreach (['rw', 'pw'] as $doc) {
                $table->unsignedInteger($doc.'_document_id');
                $table->string($doc.'_number', 40);
                $table->date($doc.'_date');
                $table->string($doc.'_warehouse', 10)->nullable();
                $table->decimal($doc.'_quantity', 14, 4);
                $table->decimal($doc.'_value', 14, 2);
                // akronim z CDN.OpeKarty: kto wystawił / kto zatwierdził
                $table->string($doc.'_operator', 20)->nullable();
                $table->string($doc.'_approver', 20)->nullable();
            }
            $table->unsignedSmallInteger('gap_days');
            $table->boolean('same_value');
            $table->boolean('same_warehouse');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['rw_document_id', 'xl_gid']);
            $table->unique(['pw_document_id', 'xl_gid']);
            $table->index('rw_date');
            $table->index('erp_item_id');
            $table->index('rw_operator');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_rw_pw_pairs');
    }
};
