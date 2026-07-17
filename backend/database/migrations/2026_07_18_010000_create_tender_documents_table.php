<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tender_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained('tenders')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('disk_path')->nullable();
            $table->string('mime', 128)->nullable();
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('mode', 16)->default('simple');
            $table->json('targets')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->json('analysis_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tender_documents');
    }
};
