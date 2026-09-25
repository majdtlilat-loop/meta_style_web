<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform currency catalog: which currencies plans, invoices and
 * centers may use, with their symbol and decimal places. Created with the
 * two currencies the platform already used (IQD, the default, and USD) so
 * nothing that exists today loses its currency.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('platform_currencies', function (Blueprint $table): void {
            $table->id();
            $table->char('code', 3)->unique();
            $table->json('name');
            $table->string('symbol', 12);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::connection($this->connection)->table('platform_currencies')->insert([
            ['code' => 'IQD', 'name' => json_encode(['en' => 'Iraqi dinar', 'ar' => 'دينار عراقي', 'ckb' => 'دیناری عێراقی'], JSON_THROW_ON_ERROR), 'symbol' => 'IQD', 'decimals' => 0, 'is_enabled' => true, 'is_default' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'USD', 'name' => json_encode(['en' => 'US dollar', 'ar' => 'دولار أمريكي', 'ckb' => 'دۆلاری ئەمریکی'], JSON_THROW_ON_ERROR), 'symbol' => '$', 'decimals' => 2, 'is_enabled' => true, 'is_default' => false, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('platform_currencies');
    }
};
