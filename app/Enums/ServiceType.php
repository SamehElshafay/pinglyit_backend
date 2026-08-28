<?php

namespace App\Enums;

/**
 * Every billable service on the platform. This is the one place a new
 * service (docs §2: "Service C, D, ...") gets registered — everything else
 * (Wallet, UsageEvent, ServiceConfig) is keyed by this value and needs no
 * schema change when a case is added here.
 */
enum ServiceType: string
{
    case WhatsApp = 'whatsapp';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp Gateway',
            self::Ai => 'AI Gateway',
        };
    }
}
