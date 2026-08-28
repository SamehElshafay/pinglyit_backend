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
     * @return array<string, mixed> the effective pricing_config for this company + service
     */
    public function resolve(Company $company, ServiceType $service): array
    {
        $override = ServiceConfig::query()
            ->where('company_id', $company->id)
            ->where('service_type', $service)
            ->first();

        if ($override) {
            return $override->pricing_config;
        }

        return $this->platformDefault($service);
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
            ServiceConfig::query()
                ->where('company_id', $company->id)
                ->where('service_type', $service)
                ->delete();

            return;
        }

        ServiceConfig::query()->updateOrCreate(
            ['company_id' => $company->id, 'service_type' => $service],
            ['pricing_config' => $config],
        );
    }
}
