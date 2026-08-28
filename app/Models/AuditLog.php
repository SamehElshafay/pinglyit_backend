<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Who changed a margin, multiplier, or service toggle, and when" — every
 * admin-facing config write that isn't already its own dedicated log (wallet
 * adjustments have their own screen/table already) calls record() from the
 * controller that made the change. Never stores a secret's value — see the
 * call sites in AiConnectionController/PaymentConnectionController.
 */
class AuditLog extends Model
{
    protected $fillable = ['admin_user_id', 'action', 'description', 'company_id', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(AdminUser $admin, string $action, string $description, ?Company $company = null, array $meta = []): self
    {
        return static::create([
            'admin_user_id' => $admin->id,
            'action' => $action,
            'description' => $description,
            'company_id' => $company?->id,
            'meta' => $meta,
        ]);
    }
}
