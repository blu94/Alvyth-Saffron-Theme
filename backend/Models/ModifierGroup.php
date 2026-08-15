<?php

namespace Theme\Backend\Models;

use App\Models\Product;
use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;
use Spatie\Translatable\HasTranslations;

/**
 * A reusable question asked about a dish.
 *
 * `slug` is a plain unique string rather than a translatable one: it is the key dishes are
 * attached by and the key the options bag is built from, so it must not vary by locale.
 * That is why this uses HasSlug and not HasTranslatableSlug.
 */
class ModifierGroup extends Model
{
    use HasTranslations, HasSlug, SoftDeletes, LogsSystemActivity;

    public $translatable = ['title', 'description'];

    protected $fillable = [
        'title',
        'slug',
        'description',
        'selection',
        'min_select',
        'max_select',
        'status',
        'orders',
        'data',
    ];

    protected $casts = [
        'title'      => 'json',
        'description' => 'json',
        'min_select' => 'integer',
        'max_select' => 'integer',
        'orders'     => 'integer',
        'data'       => 'array',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(fn (self $group) => $group->getTranslation('title', 'en', false) ?: 'group')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate();
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('orders')->orderBy('id');
    }

    public function dishes(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'dish_modifier_group')
            ->withPivot(['orders', 'required_override'])
            ->withTimestamps();
    }

    /**
     * Whether the customer must answer this group, honouring a per-dish override.
     */
    public function isRequired(?bool $override = null): bool
    {
        if ($override !== null) {
            return $override;
        }

        return $this->min_select >= 1;
    }

    /**
     * The effective ceiling on selections. Always 1 for a single-selection group — the
     * schema lets max_select be set either way, and honouring it there would render a radio
     * list that claims to accept two answers.
     */
    public function effectiveMaxSelect(): ?int
    {
        if ($this->selection === 'single') {
            return 1;
        }

        return $this->max_select;
    }
}
