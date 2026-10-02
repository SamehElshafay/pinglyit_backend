<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * One WhatsApp message, in or out. See the migration for why this exists
 * alongside WhatsappConversation.
 */
class WhatsappMessage extends Model
{
    public const DIRECTION_IN = 'inbound';

    public const DIRECTION_OUT = 'outbound';

    /**
     * WhatsApp only allows a free-form reply within 24 hours of the
     * customer's last inbound message. After that Meta refuses anything
     * but an approved template — so this window governs whether a reply
     * box should even be usable, and is the single most common surprise
     * for anyone new to the platform.
     */
    public const REPLY_WINDOW_HOURS = 24;

    protected $fillable = [
        'company_id', 'customer_phone', 'direction', 'type', 'body',
        'wa_message_id', 'status', 'error', 'sent_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeWithCustomer(Builder $query, string $phone): Builder
    {
        return $query->where('customer_phone', $phone);
    }

    /**
     * Record a message without ever letting the attempt break the caller.
     * Logging is a side effect of messaging — a failure to write history
     * must not fail the send itself, or turn Meta's webhook delivery into
     * an error it will retry.
     */
    public static function record(array $attributes): ?self
    {
        try {
            return static::create($attributes);
        } catch (\Throwable $e) {
            Log::warning('Could not record WhatsApp message', [
                'company_id' => $attributes['company_id'] ?? null,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
