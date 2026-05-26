<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retainers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('client_id');
            $table->string('name', 255);
            $table->text('description')->nullable();

            $table->string('period_mode', 32);                   // calendar|anchor|explicit
            $table->string('period_unit', 32)->nullable();       // weekly|monthly|quarterly (null for explicit)
            $table->integer('seconds_per_period')->unsigned()->nullable();
            $table->date('anchor_date')->nullable();

            $table->date('starts_at');
            $table->date('ends_at')->nullable();

            $table->boolean('billable_only')->default(true);
            $table->boolean('hard_cap_enabled')->default(false);
            $table->string('hard_cap_scope', 32)->nullable();         // per_period|cumulative
            $table->string('hard_cap_enforcement', 32)->nullable();   // block|flag|approval
            $table->integer('hard_cap_cumulative_seconds')->unsigned()->nullable();
            $table->string('sub_cap_mode', 32)->default('soft');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();

            $table->index(['organization_id', 'client_id']);
            $table->index(['client_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retainers');
    }
};
