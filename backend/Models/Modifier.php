<?php

namespace Theme\Backend\Models;

use App\Models\Product;
use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * One answer within a modifier group.
 */
class Modifier extends Model
{
    use HasTranslations, SoftDeletes, LogsSystemActivity;

    public $translatable = ['title'];

    protected $fillable = [
        'modifier_group_id',
        'title',
        'price_delta',
        'product_id',
        'is_default',
        'status',
        'orders',
        'data',
    ];

    protected $casts = [
        'title'       => 'json',
        'price_delta' => 'decimal:2',
        'is_default'  => 'boolean',
        'orders'      => 'integer',
        'data'        => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    /**
     * Set when this modifier is itself a sellable Product — the seam §2.3 approach A needs
     * in order to put a paid add-on on its own cart line.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * True when this modifier carries a surcharge that nothing currently charges for.
     *
     * Kept as an explicit predicate so the dish sheet can label the delta honestly rather
     * than quietly folding it into a total the server will not agree with. See the note on
     * the `price_delta` column and RESTAURANT-THEME-SPEC.md §2.3.
     */
    public function isPaid(): bool
    {
        return (float) $this->price_delta !== 0.0;
    }
}
