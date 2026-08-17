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

    /**
     * How many dish names the group screen's read-only list prints before it summarises.
     *
     * A question attached to three hundred dishes would otherwise render three hundred chips
     * and bury the rest of the form. The exact figure is on the list screen's Dishes column
     * and in the count beside this list, so nothing is hidden — only the wall of names.
     */
    public const SUMMARY_LIMIT = 50;

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
     * The dishes that ask this question, as plain titles for the group screen's read-only list.
     *
     * Appended by {@see \Theme\Backend\Repositories\ModifierGroupRepository::find()} and never
     * through `$appends`: the list screen serialises every row, and an accessor that resolved
     * `dishes` there would turn one query into one per row for a column the list already gets
     * from `withCount`. `find()` eager-loads the relation, so this reads what is in memory.
     *
     * Sorted by name rather than by pivot order, because the pivot's `orders` is the question's
     * position *on each dish* — it says nothing about how the dishes relate to each other, and
     * sorting a reach list by it would look arbitrary. Alphabetical is what a reader scans.
     *
     * @return array<int, string>
     */
    public function getDishesSummaryAttribute(): array
    {
        $titles = $this->dishes
            ->map(fn (Product $dish) => $this->dishTitle($dish))
            ->filter(fn (string $title) => $title !== '')
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        if ($titles->count() <= self::SUMMARY_LIMIT) {
            return $titles->all();
        }

        $extra = $titles->count() - self::SUMMARY_LIMIT;

        return $titles->take(self::SUMMARY_LIMIT)
            ->push(sprintf('… and %d more', $extra))
            ->all();
    }

    /**
     * A dish's name in the admin's locale, falling back to English and then to its SKU.
     *
     * A product with no title in either locale still has to be nameable, or it appears in the
     * reach list as a blank chip the operator cannot act on.
     */
    protected function dishTitle(Product $dish): string
    {
        $locale = app()->getLocale();

        return (string) ($dish->getTranslation('title', $locale, false)
            ?: $dish->getTranslation('title', 'en', false)
            ?: $dish->sku
            ?: '');
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
