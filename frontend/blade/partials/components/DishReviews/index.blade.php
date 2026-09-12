@php
    // Presentation-only star geometry, kept out of the template body so the markup below reads
    // as markup. The path is Tabler's star; this theme ships no icon font, so every glyph is
    // inlined — the same reason the share panel inlines its brand icons.
    $star = 'M8.243 7.34l-6.38.925-.113.023a1 1 0 0 0-.44 1.684l4.622 4.499-1.09 6.355-.013.11a1 1 0 0 0 1.464.944l5.706-3 5.693 3 .1.046a1 1 0 0 0 1.352-1.1l-1.091-6.355 4.624-4.5.078-.085a1 1 0 0 0-.633-1.62l-6.38-.926-2.852-5.78a1 1 0 0 0-1.794 0z';
@endphp

<section id="{{ $uid }}" class="saffron-reviews">
    <div class="saffron-container">

        <div class="saffron-reviews__head">
            <div>
                <h2 class="saffron-reviews__title">{{ __('What people said') }}</h2>
                {{-- Shown at zero too, as empty stars and "No reviews yet". Hiding it until
                     somebody had written one made a shop with no reviews look like a shop with
                     no review feature — the opposite of an invitation to leave the first. --}}
                <p class="saffron-reviews__summary">
                    <span class="saffron-reviews__stars" aria-hidden="true">
                        <svg v-for="n in 5" :key="n" viewBox="0 0 24 24" width="16" height="16"
                             :fill="n <= Math.round(avgRating) ? 'currentColor' : 'none'"
                             stroke="currentColor" stroke-width="1.6">
                            <path d="{{ $star }}"></path>
                        </svg>
                    </span>
                    <template v-if="ratingCount > 0">
                        <span>@{{ avgRating }} {{ __('out of 5') }}</span>
                        <span class="saffron-reviews__count">@{{ countLabel }}</span>
                    </template>
                    <span v-else class="saffron-reviews__count">{{ __('No reviews yet') }}</span>
                </p>
            </div>

            <button type="button" class="saffron-btn saffron-btn--dark" @click="showForm = !showForm">
                @{{ showForm ? labels.cancel : labels.write }}
            </button>
        </div>

        {{-- Outside the form, because submitting closes the form. Inside it, the thank-you was
             set and unmounted in the same tick, so a held review told its author nothing — they
             looked for it in the list, could not find it, and wrote it again. `PostComments`
             has had it out here since it was written; this component kept the broken shape. --}}
        <p class="saffron-reviews__success" v-if="formSuccess">@{{ formSuccess }}</p>

        <form class="saffron-reviews__form" v-if="showForm" @submit.prevent="submitReview">
            <div class="saffron-reviews__field">
                <span class="saffron-reviews__label">{{ __('Your rating') }}</span>
                <div class="saffron-reviews__rate">
                    <button type="button" v-for="n in 5" :key="n" class="saffron-reviews__rate-star"
                            :class="{ 'is-lit': n <= (hoverRating || formRating) }"
                            @mouseover="hoverRating = n" @mouseleave="hoverRating = 0"
                            @click="formRating = n"
                            :aria-label="rateLabel.replace(':n', n)" :aria-pressed="formRating === n ? 'true' : 'false'">
                        <svg viewBox="0 0 24 24" width="26" height="26"
                             :fill="n <= (hoverRating || formRating) ? 'currentColor' : 'none'"
                             stroke="currentColor" stroke-width="1.5">
                            <path d="{{ $star }}"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="saffron-reviews__row" v-if="!isLoggedIn">
                <label class="saffron-reviews__field">
                    <span class="saffron-reviews__label">{{ __('Your name') }}</span>
                    <input type="text" v-model="formName" class="saffron-reviews__input" maxlength="80"
                           placeholder="{{ __('e.g. Aisyah') }}">
                </label>
                <label class="saffron-reviews__field">
                    <span class="saffron-reviews__label">{{ __('Your email') }}</span>
                    <input type="email" v-model="formEmail" class="saffron-reviews__input"
                           placeholder="{{ __('e.g. you@example.com') }}">
                    <span class="saffron-reviews__hint">{{ __('Never shown publicly.') }}</span>
                </label>
            </div>

            <label class="saffron-reviews__field">
                <span class="saffron-reviews__label">{{ __('Your review') }}</span>
                <textarea v-model="formBody" class="saffron-reviews__input" rows="4" maxlength="2000"
                          placeholder="{{ __('What did you think of it?') }}"></textarea>
                <span class="saffron-reviews__hint">@{{ formBody.length }} / 2000</span>
            </label>

            <p class="saffron-reviews__error" v-if="formError">@{{ formError }}</p>

            <button type="submit" class="saffron-btn saffron-btn--accent" :disabled="submitting">
                @{{ submitting ? labels.sending : labels.submit }}
            </button>
        </form>

        <p class="saffron-reviews__empty" v-if="loading">{{ __('Loading reviews…') }}</p>

        <p class="saffron-reviews__empty" v-else-if="reviews.length === 0">
            {{ __('No reviews yet. Be the first to leave one.') }}
        </p>

        <ul class="saffron-reviews__list" v-else>
            <li class="saffron-reviews__item" v-for="review in reviews" :key="review.id">
                <div class="saffron-reviews__avatar" aria-hidden="true">
                    <img v-if="review.author_avatar" :src="review.author_avatar" alt="" loading="lazy">
                    <span v-else>@{{ review.author_name ? review.author_name[0].toUpperCase() : 'G' }}</span>
                </div>
                <div class="saffron-reviews__body">
                    <p class="saffron-reviews__byline">
                        <span class="saffron-reviews__author">@{{ review.author_name }}</span>
                        {{-- Set only for a signed-in author whose order contains this dish; a
                             typed-in email never earns it. --}}
                        <span class="saffron-badge saffron-badge--verified" v-if="review.verified_buyer">
                            {{ __('Verified buyer') }}
                        </span>
                        <span class="saffron-reviews__date">@{{ review.date }}</span>
                    </p>
                    <span class="saffron-reviews__stars" v-if="review.rating" aria-hidden="true">
                        <svg v-for="n in 5" :key="n" viewBox="0 0 24 24" width="14" height="14"
                             :fill="n <= review.rating ? 'currentColor' : 'none'"
                             stroke="currentColor" stroke-width="1.6">
                            <path d="{{ $star }}"></path>
                        </svg>
                    </span>
                    <p class="saffron-reviews__text">@{{ review.body }}</p>
                </div>
            </li>
        </ul>

        <div class="saffron-reviews__pager" v-if="lastPage > 1">
            <button type="button" class="saffron-btn saffron-btn--outline saffron-btn--sm"
                    :disabled="currentPage <= 1" @click="loadReviews(currentPage - 1)">{{ __('Previous') }}</button>
            <span>@{{ currentPage }} / @{{ lastPage }}</span>
            <button type="button" class="saffron-btn saffron-btn--outline saffron-btn--sm"
                    :disabled="currentPage >= lastPage" @click="loadReviews(currentPage + 1)">{{ __('Next') }}</button>
        </div>
    </div>
</section>

<script>
(function () {
    const { createApp, ref, computed, onMounted } = Vue;

    createApp({
        setup() {
            const dishId = {{ (int) $dishId }};
            {{-- ONE variable, deliberately. Blade's json directive splits its argument on
                 top-level commas to find its optional options and depth parameters, so a
                 translated string that takes a replacements array compiles to a two-argument
                 encode and is a PHP parse error — which took this whole page to a 500. The
                 driver builds the array; this line only carries it across.

                 And this note is a Blade comment rather than a JS one, because Blade compiles
                 its directives inside `//` lines too: written as JS, the example in this very
                 comment re-created the error it describes. --}}
            const labels = @json($labels);
            // `AlvythStore.user` is set by core for a signed-in customer. A guest gives a name
            // and an email instead; core requires both and will 422 without them.
            const isLoggedIn = !!(window.AlvythStore && window.AlvythStore.user && window.AlvythStore.user.id);

            const loading     = ref(true);
            const reviews     = ref([]);
            const avgRating   = ref(null);
            const ratingCount = ref(0);
            const currentPage = ref(1);
            const lastPage    = ref(1);

            // Pluralised the way the server pluralises it — same choice string, same picker
            // semantics — rather than a JS either/or that flattens richer locales.
            const countLabel = computed(() =>
                window.ThemeApi.transChoice(labels.countChoice, ratingCount.value)
                    .replace(':count', ratingCount.value)
            );

            const showForm    = ref(false);
            const formRating  = ref(0);
            const hoverRating = ref(0);
            const formName    = ref('');
            const formEmail   = ref('');
            const formBody    = ref('');
            const formError   = ref('');
            const formSuccess = ref('');
            const submitting  = ref(false);

            async function loadReviews(page = 1) {
                loading.value = true;
                try {
                    // Through the shared client rather than a bare fetch, so the signed-in
                    // customer's token rides along — without it, verified_buyer is decided
                    // for an anonymous reader and never shows the author their own badge.
                    const data = await window.ThemeApi.interactions.getComments('products', dishId, page);
                    reviews.value     = data.comments || [];
                    avgRating.value   = data.avg_rating ?? null;
                    ratingCount.value = data.rating_count ?? 0;
                    currentPage.value = data.current_page ?? 1;
                    lastPage.value    = data.last_page ?? 1;
                } catch (e) {
                    console.error('Could not load reviews', e);
                } finally {
                    loading.value = false;
                }
            }

            async function submitReview() {
                formError.value = '';
                formSuccess.value = '';

                if (!formRating.value)                      { formError.value = labels.noRating; return; }
                if (!formBody.value.trim())                 { formError.value = labels.noBody;   return; }
                if (!isLoggedIn && !formName.value.trim())  { formError.value = labels.noName;   return; }
                if (!isLoggedIn && !formEmail.value.trim()) { formError.value = labels.noEmail;  return; }

                submitting.value = true;

                try {
                    const data = await window.ThemeApi.interactions.postComment(
                        'products', dishId, formBody.value.trim(),
                        !isLoggedIn ? formEmail.value.trim() : null,
                        !isLoggedIn ? formName.value.trim() : null,
                        null,
                        formRating.value
                    );

                    // A held review is not in the list that is about to reload, so say so.
                    // Otherwise the author looks for their own review, cannot find it, and
                    // writes it again.
                    formSuccess.value = data?.comment?.status === 'pending'
                        ? labels.held
                        : labels.live;

                    formBody.value = '';
                    formRating.value = 0;
                    formName.value = '';
                    formEmail.value = '';
                    showForm.value = false;
                    await loadReviews(1);
                } catch (e) {
                    // The client throws { data } for a refusal and a bare error when the
                    // request never reached the server; only the first carries a message.
                    if (e && e.data) {
                        const messages = e.data.errors ? Object.values(e.data.errors).flat() : [];
                        formError.value = messages[0] || e.data.message || labels.failed;
                    } else {
                        formError.value = labels.offline;
                    }
                } finally {
                    submitting.value = false;
                }
            }

            onMounted(() => loadReviews(1));

            return {
                loading, reviews, avgRating, ratingCount, countLabel, currentPage, lastPage, loadReviews,
                showForm, formRating, hoverRating, formName, formEmail, formBody,
                formError, formSuccess, submitting, submitReview, isLoggedIn,
                rateLabel: labels.rate,
                labels,
            };
        },
    }).mount('#{{ $uid }}');
})();
</script>
