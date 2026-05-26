<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retainer_project_caps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('retainer_id');
            $table->uuid('project_id');
            $table->integer('seconds_per_period')->unsigned()->nullable();
            $table->integer('seconds_cumulative')->unsigned()->nullable();
            $table->timestamps();

            $table->foreign('retainer_id')->references('id')->on('retainers')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['retainer_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retainer_project_caps');
    }
};
