<?php

namespace App\Services\Auth;

use App\Mail\WelcomeMail;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Mail\TransactionalMailer;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use UnexpectedValueException;

/**
 * "Sign in with Google" for user_website. Uses Google Identity Services'
 * client-side button (frontend), which hands back a signed ID token (a
 * JWT) — this class verifies that token server-side and never trusts
 * anything the browser claims about who signed in.
 *
 * No client *secret* involved anywhere, on purpose: this is Google's
 * "verify an ID token" flow, not the server-side authorization-code
 * exchange. Only a Client ID is needed, and it's not sensitive — Google
 * designs it to be embedded in public frontend JS — but it's still
 * admin-managed via PlatformSetting for the same reason every other
 * integration here is: no redeploy needed to change it. See
 * GoogleConnectionController.
 *
 * Verification reuses firebase/php-jwt (already a dependency for Pingly's
 * own tokens — see JwtService) with Google's published JWKS, rather than
 * pulling in Google's much heavier official SDK for one function.
 */
class GoogleAuthService
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const VALID_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(private readonly TransactionalMailer $mailer) {}

    public function clientId(): ?string
    {
        return PlatformSetting::get('google_oauth_client_id') ?: config('services.google.client_id');
    }

    public function isConfigured(): bool
    {
        return filled($this->clientId());
    }

    /**
     * Verifies a Google ID token and returns the matching (or newly
     * created) Pingly User. Throws RuntimeException with a message safe to
     * show the client on anything invalid — bad signature, wrong audience,
     * unverified email, expired token, etc.
     */
    public function resolveUser(string $idToken): User
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Google sign-in is not configured — add the Client ID in the admin dashboard first.');
        }

        $claims = $this->verify($idToken);

        if (($claims->email_verified ?? false) !== true || blank($claims->email ?? null)) {
            throw new RuntimeException('This Google account has no verified email address.');
        }

        // Matched by email first, always — a Google sign-in for an email
        // that already has a password-based Pingly account just logs into
        // that same account (standard "social login as an alternate way
        // into the same account" behavior), and links google_id to it if
        // it wasn't already. google_id is only the lookup key for a
        // *returning* Google-first user, never the primary match.
        $user = User::where('email', $claims->email)->first();

        if ($user) {
            if (blank($user->google_id)) {
                $user->update(['google_id' => $claims->sub]);
            }

            // Refreshed on every Google sign-in, not just the first —
            // Google's own `picture` URL can change (a new profile photo),
            // and this is the only source avatar_url ever comes from.
            if (filled($claims->picture ?? null) && $user->avatar_url !== $claims->picture) {
                $user->update(['avatar_url' => $claims->picture]);
            }

            // A password signup that never finished email verification
            // (see email_otps) — Google re-proving ownership of this same
            // inbox completes it just as well as entering the OTP would
            // have, so this is treated as the same "signup finally
            // completes" moment: mark verified, send the same welcome
            // email a completed OTP flow would have sent.
            if (blank($user->email_verified_at)) {
                $user->update(['email_verified_at' => now()]);
                $this->mailer->attempt($user->email, new WelcomeMail($user));
            }

            return $user;
        }

        return $this->createFromGoogle($claims);
    }

    /**
     * @throws RuntimeException on an invalid/expired/wrong-audience token
     */
    private function verify(string $idToken): object
    {
        try {
            $jwks = Cache::remember('google_jwks', 3600, function () {
                $response = Http::timeout(15)->get(self::JWKS_URL);

                if ($response->failed()) {
                    throw new RuntimeException("Couldn't fetch Google's public keys.");
                }

                return $response->json();
            });

            $claims = JWT::decode($idToken, JWK::parseKeySet($jwks));
        } catch (RuntimeException $e) {
            throw $e;
        } catch (UnexpectedValueException|\Throwable $e) {
            // Covers every way firebase/php-jwt rejects a token — expired,
            // bad signature, malformed, wrong algorithm — same "just say
            // it's invalid" approach as AuthenticateJwt for Pingly's own
            // tokens.
            throw new RuntimeException('Invalid Google sign-in token.');
        }

        if (! in_array($claims->iss ?? null, self::VALID_ISSUERS, true)) {
            throw new RuntimeException('Invalid Google sign-in token issuer.');
        }

        if (($claims->aud ?? null) !== $this->clientId()) {
            throw new RuntimeException('This Google sign-in token was issued for a different application.');
        }

        return $claims;
    }

    /**
     * A brand-new Google sign-in with no matching Pingly account yet —
     * this *is* the signup, since asking someone to fill out a form after
     * they already picked "Sign in with Google" would defeat the point.
     * Creates the Company + Wallet + User in one transaction, exactly like
     * RegisterController::store() does for a password signup.
     */
    private function createFromGoogle(object $claims): User
    {
        $companyName = filled($claims->name ?? null) ? "{$claims->name}'s Company" : 'My Company';

        $user = DB::transaction(function () use ($claims, $companyName) {
            $company = Company::create([
                'name' => $companyName,
                'contact_email' => $claims->email,
            ]);

            $company->wallet()->create(['balance' => 0, 'currency' => 'USD']);

            $user = $company->users()->create([
                'name' => $claims->name ?? $claims->email,
                'email' => $claims->email,
                'google_id' => $claims->sub,
                'avatar_url' => $claims->picture ?? null,
                // Already checked in verify() above — no OTP step needed,
                // Google itself is the verification.
                'email_verified_at' => now(),
                // Never used to log in — Google is this account's only way
                // in unless the client sets a real password later from
                // Account settings. A random value keeps `password` NOT
                // NULL without a schema change or special-casing password
                // checks anywhere else in the app.
                'password' => Hash::make(Str::random(40)),
            ]);

            $user->setRelation('company', $company); // avoids an extra query — WelcomeMail greets them by company name

            return $user;
        });

        // Same welcome email a password signup gets (RegisterController::store())
        // — this Google sign-in *is* the signup, so it deserves the same one.
        // Best-effort: the account is already created and about to be handed
        // a token, so a mailer outage must not fail the sign-in.
        $this->mailer->attempt($user->email, new WelcomeMail($user));

        return $user;
    }
}
