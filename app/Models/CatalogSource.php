<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogSource extends Model
{
    /**
     * Sensible guesses for the field names a product feed uses, tried in
     * order when the client hasn't mapped a field explicitly. Covers the
     * shapes most storefronts and ERPs emit, so the common case needs no
     * mapping at all.
     *
     * @var array<string, array<int, string>>
     */
    public const DEFAULT_FIELD_MAP = [
        'name' => ['name', 'title', 'product_name', 'item_name'],
        'price' => ['price', 'unit_price', 'amount', 'sale_price'],
        'currency' => ['currency', 'currency_code'],
        'sku' => ['sku', 'code', 'item_code', 'barcode'],
        'description' => ['description', 'desc', 'details', 'summary'],
        'image_url' => ['image_url', 'image', 'thumbnail', 'picture'],
        'active' => ['active', 'is_active', 'enabled', 'available'],
    ];

    protected $fillable = [
        'company_id', 'url', 'method', 'auth_token', 'auth_header', 'auth_prefix',
        'query_params', 'items_path', 'field_map', 'auto_sync',
        'last_synced_at', 'last_status', 'last_error', 'last_imported_count',
    ];

    protected $hidden = ['auth_token'];

    protected function casts(): array
    {
        return [
            'auth_token' => 'encrypted',
            'query_params' => 'array',
            'field_map' => 'array',
            'auto_sync' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
