<?php

declare(strict_types=1);

use App\Interfaces\MigrationInterface;
use App\Services\DB;
use Illuminate\Database\Schema\Blueprint;

return new class() implements MigrationInterface {
    public function up(): int
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('client_sessions', static function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('id', 43)->primary();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('access_hash', 64)->unique();
            $table->string('password_hash', 64);
            $table->string('device_name', 120);
            $table->bigInteger('created_at');
            $table->bigInteger('access_expires');
            $table->bigInteger('expires_at')->index();
            $table->boolean('revoked')->default(false);
        });
        $schema->create('client_refresh_tokens', static function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('hash', 64)->primary();
            $table->string('session_id', 43)->index();
            $table->boolean('used')->default(false);
            $table->bigInteger('expires_at')->index();
        });
        $schema->create('client_challenges', static function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('id', 43)->primary();
            $table->string('purpose', 32);
            $table->string('email', 255)->default('');
            $table->string('secret_hash', 64);
            $table->text('payload');
            $table->integer('attempts')->default(0);
            $table->boolean('consumed')->default(false);
            $table->bigInteger('expires_at')->index();
        });
        $schema->create('client_rate_limits', static function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->string('id', 64)->primary();
            $table->integer('attempts');
            $table->bigInteger('expires_at')->index();
        });
        $schema->create('client_idempotency', static function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->string('operation', 120);
            $table->text('response')->nullable();
            $table->bigInteger('created_at');
            $table->unique(['user_id', 'key_hash']);
        });
        $schema->create('client_payments', static function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('paylist_id')->primary();
            $table->string('gateway', 16);
            $table->string('method', 16);
            $table->string('state', 24);
            $table->text('descriptor')->nullable();
            $table->bigInteger('expires_at');
        });
        return 2026100600;
    }

    public function down(): int
    {
        foreach (['client_payments', 'client_idempotency', 'client_rate_limits', 'client_challenges', 'client_refresh_tokens', 'client_sessions'] as $name) {
            DB::connection()->getSchemaBuilder()->dropIfExists($name);
        }
        return 2026091300;
    }
};
