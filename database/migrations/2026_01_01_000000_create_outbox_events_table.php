<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection(config('outbox.connection'))->create('outbox_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB'; // Ignored by SQLite/PostgreSQL grammars.
            $table->uuid('id')->primary();
            $table->string('type', 191);
            $table->json('payload');
            $table->json('metadata');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            // Epoch seconds, independent of the database session timezone.
            $table->bigInteger('available_at');
            $table->uuid('claim_token')->nullable();
            $table->bigInteger('lease_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('outbox.connection'))->dropIfExists('outbox_events');
    }
};
