<?php

namespace Tests\Unit\Services\Mail;

use App\Models\PlatformSetting;
use App\Services\Mail\MailProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailProviderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_runtime_config_is_a_no_op_until_the_admin_picks_a_provider(): void
    {
        config(['mail.default' => 'array']);

        app(MailProviderService::class)->applyRuntimeConfig();

        // Nothing saved in platform_settings — an unrelated test elsewhere
        // (or a production request under plain .env) must see its own
        // mail.default untouched, not silently routed to Resend or SMTP.
        $this->assertSame('array', config('mail.default'));
    }

    public function test_apply_runtime_config_switches_to_smtp_and_carries_only_saved_fields(): void
    {
        config(['mail.default' => 'resend', 'mail.mailers.smtp.port' => 2525]);

        PlatformSetting::set('mail_provider', 'smtp');
        PlatformSetting::set('mail_smtp_host', 'smtp.gmail.com');
        PlatformSetting::set('mail_smtp_username', 'pingly514@gmail.com');
        PlatformSetting::set('mail_smtp_password', 'an-app-password');
        // No mail_smtp_port saved — the previously configured 2525 must survive.

        app(MailProviderService::class)->applyRuntimeConfig();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.gmail.com', config('mail.mailers.smtp.host'));
        $this->assertSame('pingly514@gmail.com', config('mail.mailers.smtp.username'));
        $this->assertSame(2525, config('mail.mailers.smtp.port'));
    }

    public function test_ssl_encryption_forces_the_smtps_scheme(): void
    {
        PlatformSetting::set('mail_provider', 'smtp');
        PlatformSetting::set('mail_smtp_encryption', 'ssl');

        app(MailProviderService::class)->applyRuntimeConfig();

        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
    }
}
