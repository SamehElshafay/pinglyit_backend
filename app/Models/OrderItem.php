<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    // product_name/unit_price are a snapshot at order time — see the
    // create_order_items_table migration's docblock.
    protected $fillable = ['order_id', 'product_id', 'product_name', 'unit_price', 'quantity', 'line_total'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
