<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Cumplimiento estricto de data-model.md y ADR-001 / ADR-003.
     */
    public function up(): void
    {
        // 1. Tabla: category
        Schema::create('category', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120)->unique();
        });

        // 2. Tabla: user
        Schema::create('user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('username', 120)->unique();
            $table->string('password_hash', 512);
            $table->string('role', 40);
        });

        // Restricción de rol
        DB::statement("ALTER TABLE \"user\" ADD CONSTRAINT ck_user_role CHECK (role IN ('admin', 'librarian'));");

        // 3. Tabla: member
        Schema::create('member', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name', 200);
            $table->string('document_number', 30)->unique();
        });

        // 4. Tabla: book
        Schema::create('book', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title', 250);
            $table->string('author', 200);
            $table->char('isbn', 13);
            $table->uuid('category_id');
            $table->integer('total_copies');
            $table->integer('available_copies');
            $table->string('cover_key', 512)->nullable();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('category_id')->references('id')->on('category')->onDelete('restrict');
        });

        // Restricciones de motor para book
        DB::statement("ALTER TABLE book ADD CONSTRAINT ck_book_total_positive CHECK (total_copies >= 1);");
        DB::statement("ALTER TABLE book ADD CONSTRAINT ck_book_available_range CHECK (available_copies >= 0 AND available_copies <= total_copies);");
        
        // Índice único parcial para ISBN entre libros activos
        DB::statement("CREATE UNIQUE INDEX idx_book_isbn_active ON book (isbn) WHERE deleted_at IS NULL;");
        DB::statement("CREATE INDEX idx_book_category_title_active ON book (category_id, title) WHERE deleted_at IS NULL;");

        // 5. Tabla: loan
        Schema::create('loan', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('member_id');
            $table->uuid('loaned_by_user_id');
            $table->timestampTz('loaned_at');

            $table->foreign('member_id')->references('id')->on('member')->onDelete('restrict');
            $table->foreign('loaned_by_user_id')->references('id')->on('user')->onDelete('restrict');
            $table->index('loaned_at');
        });

        // 6. Tabla: loan_item
        Schema::create('loan_item', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('loan_id');
            $table->uuid('book_id');
            $table->string('book_title', 250);
            $table->char('isbn', 13);
            $table->string('category_name', 120); // Congelado; sin FK a propósito (ADR-004)
            $table->timestampTz('due_at');
            $table->timestampTz('returned_at')->nullable();

            $table->foreign('loan_id')->references('id')->on('loan')->onDelete('cascade');
            $table->foreign('book_id')->references('id')->on('book')->onDelete('restrict');
            $table->unique(['loan_id', 'book_id']);
            $table->index('book_id');
        });

        DB::statement("CREATE INDEX idx_loan_item_due_unreturned ON loan_item (due_at) WHERE returned_at IS NULL;");

        // Semilla de las 5 categorías fijas con identificadores literales (data-model.md §9)
        DB::table('category')->insert([
            ['id' => '11111111-1111-4111-8111-111111111111', 'name' => 'Novela'],
            ['id' => '22222222-2222-4222-8222-222222222222', 'name' => 'Ciencia'],
            ['id' => '33333333-3333-4333-8333-333333333333', 'name' => 'Tecnología'],
            ['id' => '44444444-4444-4444-8444-444444444444', 'name' => 'Historia'],
            ['id' => '55555555-5555-4555-8555-555555555555', 'name' => 'Infantil'],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_item');
        Schema::dropIfExists('loan');
        Schema::dropIfExists('book');
        Schema::dropIfExists('member');
        Schema::dropIfExists('user');
        Schema::dropIfExists('category');
    }
};
