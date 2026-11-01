<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A client's own Meta (Facebook/Instagram) connection: a token *they*
     * generated from their own Meta app's System User, plus which ad
     * account and Page Pingly acts on. Bring-your-own-app on purpose — the
     * same reasoning as WhatsApp credentials (see ServiceController::
     * connectWhatsappCredentials): no Meta App Review on Pingly's side, and
     * every action is the client's own app acting on the client's own assets.
     */
    public function up(): void
    {
        Schema::create('meta_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('access_token');                  // encrypted cast
            $table->string('meta_user_id')->nullable();
            $table->string('meta_user_name')->nullable();
            $table->json('granted_permissions')->nullable();
            $table->timestamp('token_expires_at')->nullable(); // null = never expires (System User token)

            $table->string('ad_account_id')->nullable();   // act_123…
            $table->string('ad_account_name')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('page_id')->nullable();
            $table->string('page_name')->nullable();
            $table->string('instagram_account_id')->nullable();
            $table->string('instagram_username')->nullable();

            $table->string('status', 16)->default('connected'); // connected | invalid
            $table->text('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_connections');
    }
};
