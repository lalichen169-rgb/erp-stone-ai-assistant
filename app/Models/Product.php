<?php

namespace App\Models;

use App\Support\ProductColorLexicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected static function booted(): void
    {
        static::saved(static function (): void {
            ProductColorLexicon::forgetCachedColors();
        });

        static::deleted(static function (): void {
            ProductColorLexicon::forgetCachedColors();
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'sku',
        'name',
        'material',
        'color',
        'thickness_cm',
        'slab_size',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'thickness_cm' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<Stock, $this>
     */
    public function stock(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /**
     * @return HasMany<SupplierProduct, $this>
     */
    public function supplierProducts(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }
}
