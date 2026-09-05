<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['company_id', 'name', 'description', 'price', 'currency', 'sku', 'image_url', 'active'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * Offers that actually apply right now — store-wide (product_id null)
     * or scoped to this product, active, and inside their date window.
     * Checked live on every AI Commerce Assistant message
     * (AiCommerceAgentService's catalog context) so a lapsed promotion
     * stops applying itself the instant it expires.
     */
    public function activeOffers()
    {
        return Offer::where('company_id', $this->company_id)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $this->id))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * The price after the best currently-active offer, if any — never
     * below $0. This, not `price`, is what AiCommerceAgentService quotes
     * to a customer and what create_order snapshots into an order_item.
     */
    public function effectivePrice(): float
    {
        $best = $this->activeOffers()->get()->map(function (Offer $offer) {
            return $offer->discount_type === 'percentage'
                ? (float) $this->price * (1 - (float) $offer->discount_value / 100)
                : max(0, (float) $this->price - (float) $offer->discount_value);
        })->min();

        return round($best ?? (float) $this->price, 4);
    }
}
