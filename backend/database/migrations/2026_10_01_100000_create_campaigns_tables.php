<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kampanie reklamowe (pierwsze wydanie, 01.10.2026): mailing z towarem zalegającym do klientów, wysyłany ze skrzynki
 * handlowca. Klienci i ich zakupy z ERP XL (erp:customers, co noc) — kopia dosłowna, XL tylko czytany.
 */
return new class extends Migration
{
    public function up(): void
    {
        // kontrahenci XL, którzy kupowali (FS/PA) — adresy e-mail z karty i z adresów kontrahenta
        Schema::create('erp_customers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('xl_gid')->unique();
            $table->string('acronym', 40);
            $table->string('name', 300)->nullable();
            $table->string('nip', 30)->nullable();
            $table->string('city', 100)->nullable();
            // znormalizowane adresy (małe litery, bez duplikatów): najpierw z karty, potem z adresów
            $table->json('emails')->nullable();
            $table->boolean('archived')->default(false);
            $table->date('last_sale_at')->nullable();
            $table->unsignedInteger('sale_documents_24m')->default(0);
            // Ope_Ident operatora XL, który wystawił temu klientowi najwięcej FS/PA w 24 mies. („mój klient”)
            $table->string('main_operator', 20)->nullable()->index();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        // co klient kupował (FS/PA z 24 mies.) — do grup „kupowali ten towar”
        Schema::create('erp_customer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('erp_customer_id')->constrained('erp_customers')->cascadeOnDelete();
            $table->foreignId('erp_item_id')->constrained('erp_items')->cascadeOnDelete();
            $table->date('last_sale_at')->nullable();
            $table->unsignedInteger('documents')->default(0);
            $table->decimal('quantity', 14, 3)->default(0);
            $table->timestamps();

            $table->unique(['erp_customer_id', 'erp_item_id']);
            $table->index('erp_item_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            // operator ERP XL (Ope_Ident) przypisany przez administratora — „moi klienci” w kampaniach
            $table->string('erp_operator_ident', 20)->nullable()->after('default_margin_percent');
        });

        // skrzynka SMTP użytkownika, z której wychodzą jego kampanie
        Schema::create('user_mail_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('from_name', 150);
            $table->string('from_address', 255);
            $table->string('host', 255);
            $table->unsignedSmallInteger('port')->default(587);
            // smtp = STARTTLS (587), smtps = SSL (465)
            $table->string('scheme', 10)->nullable();
            $table->string('username', 255);
            $table->text('password');
            $table->boolean('verify_peer')->default(true);
            $table->unsignedInteger('rate_per_hour')->default(150);
            $table->boolean('copy_to_self')->default(true);
            $table->text('signature')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('mailing_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255)->unique();
            $table->string('name', 200)->nullable();
            $table->string('company', 250)->nullable();
            $table->foreignId('erp_customer_id')->nullable()->constrained('erp_customers')->nullOnDelete();
            $table->timestamps();
        });

        // adres w grupie z podstawą wysyłki: customer = stały klient, consent = zgoda (skąd — basis_note)
        Schema::create('mailing_list_contact', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailing_list_id')->constrained('mailing_lists')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('basis', 20);
            $table->string('basis_note', 255)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['mailing_list_id', 'contact_id']);
        });

        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('code', 20)->nullable()->unique();
            $table->string('name', 200);
            $table->string('subject', 200)->default('');
            $table->string('preheader', 200)->nullable();
            $table->string('heading', 200)->nullable();
            $table->text('intro')->nullable();
            $table->string('layout', 10)->default('grid3');
            $table->date('valid_until')->nullable();
            $table->string('status', 12)->default('draft')->index();
            $table->json('audience')->nullable();
            $table->timestamp('sending_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('totals')->nullable();
            $table->foreignId('duplicated_from_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->timestamps();
        });

        // wypis działa dla wszystkich kampanii wszystkich nadawców
        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 255)->unique();
            $table->string('reason', 20);
            $table->foreignId('campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('campaign_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('erp_item_id')->nullable()->constrained('erp_items')->nullOnDelete();
            // karta wybrana dla tej kampanii; null = główna karta towaru XL
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('promo_price_net', 12, 2)->nullable();
            // tylko wpisana ręcznie — nigdy wyliczana
            $table->decimal('price_before_net', 12, 2)->nullable();
            $table->string('note', 300)->nullable();
            // co dostał klient: stan pozycji w chwili startu wysyłki
            $table->string('snap_name', 300)->nullable();
            $table->string('snap_code', 60)->nullable();
            $table->string('snap_unit', 20)->nullable();
            $table->decimal('snap_price', 12, 2)->nullable();
            $table->decimal('snap_stock', 14, 3)->nullable();
            $table->date('snap_stock_at')->nullable();
            $table->string('snap_image_url', 500)->nullable();
            $table->decimal('stock_after_7d', 14, 3)->nullable();
            $table->decimal('stock_after_30d', 14, 3)->nullable();
            $table->timestamps();

            $table->index(['erp_item_id', 'campaign_id']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('erp_customer_id')->nullable()->constrained('erp_customers')->nullOnDelete();
            $table->string('email', 255);
            $table->string('name', 200)->nullable();
            $table->string('source', 10);
            $table->string('token', 40)->unique();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('message_id', 255)->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'email']);
            $table->index(['campaign_id', 'status']);
            $table->index(['email', 'status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaign_items');
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('mailing_list_contact');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('mailing_lists');
        Schema::dropIfExists('user_mail_accounts');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('erp_operator_ident');
        });
        Schema::dropIfExists('erp_customer_items');
        Schema::dropIfExists('erp_customers');
    }
};
