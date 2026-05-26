<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retainer_periods', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('retainer_id');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->integer('seconds_allocated')->unsigned();
            $table->timestamps();

            $table->foreign('retainer_id')->references('id')->on('retainers')->cascadeOnDelete();
            $table->unique(['retainer_id', 'starts_at']);
            $table->index(['retainer_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retainer_periods');
    }
};
