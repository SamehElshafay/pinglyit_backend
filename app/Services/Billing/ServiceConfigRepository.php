<?php

namespace App\Services\Billing;

use App\Enums\ServiceType;
use App\Models\Company;
use App\Models\ServiceConfig;

/**
 * Resolves "what config applies here" the same way for every service:
 * a client's own override if they have one, otherwise the platform default.
 * WhatsAppGatewayService and AiGatewayService both call this instead of
 * querying service_configs themselves — this is the one place that lookup
 * logic lives.
 */
class ServiceConfigRepository
{
    /**
     * The client's override layered over the platform default: any key the
     * override sets wins, every key it doesn't set comes from the platform.
     *
     * This used to return the override *instead of* the default, which
     * meant any per-client row — even one holding nothing but an
     * "enabled" flag from the on/off toggle — silently threw away every
     * platform setting it didn't repeat: AI's per-model price list (so the
     * client's model picker went empty) and WhatsApp's base-cost table (so
     * every send failed with "no base cost configured").
     *
     * @return array<string, mixed> the effective pricing_config for this company + service
     */
    public function resolve(Company $company, ServiceType $service): array
    {
        // A cleared field is stored as null ("no override") — it must fall
        // through to the platform value, never override it with nothing
        // (a null multiplier would otherwise price a request at 0×).
        $override = array_filter(
            $this->clientOverride($company, $service)?->pricing_config ?? [],
            fn ($v) => $v !== null,
        );

        return array_merge($this->platformDefault($service), $override);
    }

    public function platformDefault(ServiceType $service): array
    {
        return ServiceConfig::query()
            ->platformDefault()
            ->where('service_type', $service)
            ->first()
            ?->pricing_config ?? [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function setPlatformDefault(ServiceType $service, array $config): ServiceConfig
    {
        // updateOrCreate keeps this the single source of truth for "one
        // platform-default row per service" — the DB's unique index can't
        // enforce that on its own since company_id NULL isn't unique-checked.
        return ServiceConfig::query()->updateOrCreate(
            ['company_id' => null, 'service_type' => $service],
            ['pricing_config' => $config],
        );
    }

    /**
     * The client's own override only (not merged with the platform default) —
     * used for per-client flags like "enabled" that never inherit.
     */
    public function clientOverride(Company $company, ServiceType $service): ?ServiceConfig
    {
        return ServiceConfig::query()
            ->where('company_id', $company->id)
            ->where('service_type', $service)
            ->first();
    }

    /**
     * Merge one key into a client's override without touching the rest of
     * it (e.g. flipping "enabled" shouldn't clobber a margin override).
     */
    public function setClientFlag(Company $company, ServiceType $service, string $key, mixed $value): void
    {
        $existing = $this->clientOverride($company, $service)?->pricing_config ?? [];
        $existing[$key] = $value;

        ServiceConfig::query()->updateOrCreate(
            ['company_id' => $company->id, 'service_type' => $service],
            ['pricing_config' => $existing],
        );
    }

    /**
     * @param  array<string, mixed>|null  $config  null clears the override, falling back to the platform default
     */
    public function setClientOverride(Company $company, ServiceType $service, ?array $config): void
    {
        if ($config === null) {
            $enabled = $this->clientOverride($company, $service)?->pricing_config['enabled'] ?? null;

            ServiceConfig::query()
                ->where('company_id', $company->id)
                ->where('service_type', $service)
                ->delete();

            if ($enabled !== null) {
                $this->setClientFlag($company, $service, 'enabled', $enabled);
            }

            return;
        }

        // The on/off flag lives in the same row but is set separately (see
        // setClientFlag) — saving pricing must not quietly re-enable a
        // service the admin switched off for this client.
        $existing = $this->clientOverride($company, $service)?->pricing_config ?? [];
        if (array_key_exists('enabled', $existing) && ! array_key_exists('enabled', $config)) {
            $config['enabled'] = $existing['enabled'];
        }

        ServiceConfig::query()->updateOrCreate(
            ['company_id' => $company->id, 'service_type' => $service],
            ['pricing_config' => $config],
        );
    }
}
