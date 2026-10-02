<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A client's own Meta access token, so their number sends through their
     * own WhatsApp Business Account rather than Pingly's.
     *
     * This is what lets the gateway work at all without Pingly holding Meta
     * Business Verification: an unverified business is capped at two phone
     * numbers across all its WABAs, which is not a product. Pointing each
     * client at their own WABA removes the cap entirely, and takes Pingly
     * out of the liability path for what any client sends.
     *
     * Nullable because the platform-wide token in config stays the fallback
     * for any number that was connected under Pingly's own account before
     * this existed — see WhatsAppGatewayService::tokenFor().
     *
     * `text` rather than `string`: Meta's System User tokens run past 255
     * characters, and encryption adds its own overhead on top.
     */
    public function up(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->text('access_token')->nullable()->after('registration_pin');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_accounts', function (Blueprint $table) {
            $table->dropColumn('access_token');
        });
    }
};
