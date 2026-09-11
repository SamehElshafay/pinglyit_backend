<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\MailConnectionTestMail;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Services\Mail\MailProviderService;
use App\Services\Mail\TransactionalMailer;
use Illuminate\Http\Request;

/**
 * Which mail provider is live (Resend or plain SMTP) and its credentials —
 * entered here, not .env, same PlatformSetting pattern as every other
 * connection screen. Both providers' fields are kept and saved
 * independently of which one is currently active, so switching back is
 * just flipping `provider` rather than re-entering everything.
 */
class MailConnectionController extends Controller
{
    private const KEYS = [
        'provider' => 'mail_provider',
        'from_address' => 'mail_from_address',
        'from_name' => 'mail_from_name',
        'resend_api_key' => 'mail_resend_api_key',
        'smtp_host' => 'mail_smtp_host',
        'smtp_port' => 'mail_smtp_port',
        'smtp_username' => 'mail_smtp_username',
        'smtp_password' => 'mail_smtp_password',
        'smtp_encryption' => 'mail_smtp_encryption',
    ];

    public function __construct(
        private readonly MailProviderService $mail,
        private readonly TransactionalMailer $mailer,
    ) {}

    public function show()
    {
        $smtp = $this->mail->smtpConfig();
        $resendKey = $this->mail->resendApiKey();

        return response()->json([
            'provider' => $this->mail->provider(),
            'configured' => $this->mail->isConfigured(),
            'from_address' => $this->mail->fromAddress(),
            'from_name' => $this->mail->fromName(),
            'resend' => [
                'configured' => filled($resendKey),
                'preview' => $resendKey ? '••••'.substr($resendKey, -4) : null,
            ],
            'smtp' => [
                'configured' => filled($smtp['host']) && filled($smtp['username']) && filled($smtp['password']),
                'host' => $smtp['host'],
                'port' => $smtp['port'],
                'username' => $smtp['username'],
                'password_set' => filled($smtp['password']),
                'encryption' => $smtp['encryption'],
            ],
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'provider' => ['sometimes', 'in:resend,smtp'],
            'from_address' => ['sometimes', 'email'],
            'from_name' => ['sometimes', 'string', 'max:255'],
            'resend_api_key' => ['sometimes', 'string', 'min:10'],
            'smtp_host' => ['sometimes', 'string', 'max:255'],
            'smtp_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'smtp_username' => ['sometimes', 'string', 'max:255'],
            'smtp_password' => ['sometimes', 'string', 'max:255'],
            'smtp_encryption' => ['sometimes', 'in:tls,ssl'],
        ]);

        if ($data === []) {
            return response()->json(['message' => 'Nothing to save.'], 422);
        }

        foreach ($data as $field => $value) {
            PlatformSetting::set(self::KEYS[$field], (string) $value);
        }

        AuditLog::record($request->user(), 'mail_connection.update', 'Updated mail settings: '.implode(', ', array_keys($data)));

        return $this->show();
    }

    public function destroy(Request $request)
    {
        foreach (self::KEYS as $key) {
            PlatformSetting::set($key, null);
        }

        AuditLog::record($request->user(), 'mail_connection.destroy', 'Cleared all mail settings (falls back to .env)');

        return $this->show();
    }

    /**
     * Sends a real email through whichever provider is currently active, to
     * an address the admin picks — the fast way to confirm a saved
     * configuration actually works before a real user's password reset or
     * OTP depends on it.
     */
    public function test(Request $request)
    {
        $data = $request->validate(['to' => ['required', 'email']]);

        if (! $this->mail->isConfigured()) {
            return response()->json(['message' => 'The active provider ('.$this->mail->provider().') is missing required fields — save them first.'], 422);
        }

        $sent = $this->mailer->attempt($data['to'], new MailConnectionTestMail($this->mail->provider()));

        AuditLog::record($request->user(), 'mail_connection.test', "Sent a test email via {$this->mail->provider()} to {$data['to']} — ".($sent ? 'succeeded' : 'failed'));

        if (! $sent) {
            return response()->json(['message' => 'Could not send — check storage/logs/laravel.log for the transport error.'], 502);
        }

        return response()->json(['message' => 'Test email sent — check the inbox (and spam folder).']);
    }
}
