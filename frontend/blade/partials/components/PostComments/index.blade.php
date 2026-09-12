<section id="{{ $uid }}" class="saffron-comments">
    <div class="saffron-container">
        <header class="saffron-comments__head">
            <div>
                <h2 class="saffron-comments__title">{{ __('Comments') }}</h2>
                <p class="saffron-comments__summary">
                    <span v-if="total > 0">@{{ countLabel }}</span>
                    <span v-else>{{ __('No comments yet') }}</span>
                </p>
            </div>

            <div class="saffron-comments__controls">
                @if($showLikes)
                    <button type="button" class="saffron-comments__like" :class="{ 'is-liked': liked }"
                            @click="toggleLike" :aria-pressed="liked ? 'true' : 'false'"
                            :aria-label="liked ? labels.unlike : labels.like" :title="liked ? labels.unlike : labels.like">
                        <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="1.8"
                             :fill="liked ? 'currentColor' : 'none'" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path>
                        </svg>
                        <span v-if="likes > 0">@{{ likes }}</span>
                    </button>
                @endif

                <button type="button" class="saffron-btn saffron-btn--dark" @click="toggleForm">
                    @{{ showForm ? labels.cancel : labels.write }}
                </button>
            </div>
        </header>

        {{-- Outside the form, because posting closes the form. Inside it, the thank-you
             would be set and hidden in the same tick, and the one thing a held comment's
             author needs is the sentence telling them it was not swallowed. --}}
        <p class="saffron-comments__success" v-if="formSuccess">@{{ formSuccess }}</p>

        <form class="saffron-comments__form" v-if="showForm" @submit.prevent="submit">
            {{-- A signed-in reader is not asked to retype what the session already knows, the
                 same rule the dish reviews follow three files away. --}}
            <div class="saffron-comments__row" v-if="!isLoggedIn">
                <label class="saffron-comments__field">
                    <span class="saffron-comments__label">{{ __('Your name') }}</span>
                    <input type="text" v-model="formName" class="saffron-comments__input"
                           placeholder="{{ __('e.g. Aisyah') }}" maxlength="120">
                </label>

                <label class="saffron-comments__field">
                    <span class="saffron-comments__label">{{ __('Your email') }}</span>
                    <input type="email" v-model="formEmail" class="saffron-comments__input"
                           placeholder="{{ __('e.g. you@example.com') }}" maxlength="180">
                    <span class="saffron-comments__hint">{{ __('Never shown publicly.') }}</span>
                </label>
            </div>

            <label class="saffron-comments__field">
                <span class="saffron-comments__label">{{ __('Your comment') }}</span>
                <textarea v-model="formBody" class="saffron-comments__input" rows="4" maxlength="2000"
                          placeholder="{{ __('What did you think of it?') }}"></textarea>
                <span class="saffron-comments__hint">@{{ formBody.length }} / 2000</span>
            </label>

            {{-- Said before submitting, not after. On a post there is no order to auto-approve
                 against, so a guest's comment is always held — an author who is told only
                 afterwards has already decided the site swallowed it. --}}
            <p class="saffron-comments__notice">{{ __('Comments are read before they appear.') }}</p>

            <p class="saffron-comments__error" v-if="formError">@{{ formError }}</p>

            <button type="submit" class="saffron-btn saffron-btn--accent" :disabled="submitting">
                @{{ submitting ? labels.sending : labels.submit }}
            </button>
        </form>

        <p class="saffron-comments__empty" v-if="loading">@{{ labels.loading }}</p>
        <p class="saffron-comments__empty" v-else-if="comments.length === 0">@{{ labels.empty }}</p>

        <ul class="saffron-comments__list" v-else>
            <li class="saffron-comments__item" v-for="comment in comments" :key="comment.id">
                <div class="saffron-comments__avatar" aria-hidden="true">
                    <img v-if="comment.author_avatar" :src="comment.author_avatar" alt="" loading="lazy">
                    <span v-else>@{{ comment.author_name ? comment.author_name[0].toUpperCase() : 'G' }}</span>
                </div>
                <div class="saffron-comments__body">
                    <p class="saffron-comments__byline">
                        <span class="saffron-comments__author">@{{ comment.author_name }}</span>
                        <span class="saffron-comments__date">@{{ comment.date }}</span>
                    </p>
                    <p class="saffron-comments__text">@{{ comment.body }}</p>
                </div>
            </li>
        </ul>

        <div class="saffron-comments__pager" v-if="lastPage > 1">
            <button type="button" class="saffron-btn saffron-btn--outline saffron-btn--sm"
                    :disabled="currentPage <= 1" @click="load(currentPage - 1)">{{ __('Previous') }}</button>
            <span>@{{ currentPage }} / @{{ lastPage }}</span>
            <button type="button" class="saffron-btn saffron-btn--outline saffron-btn--sm"
                    :disabled="currentPage >= lastPage" @click="load(currentPage + 1)">{{ __('Next') }}</button>
        </div>
    </div>
</section>

<script>
(function () {
    const { createApp, ref, computed, onMounted } = Vue;

    const postId = @json($postId);
    // Whether the reader is signed in, resolved exactly as `DishReviews` resolves it — the
    // shared store the whole storefront already reads. A signed-in commenter is not asked to
    // retype a name and an email the session is holding.
    const isLoggedIn = !!(window.AlvythStore && window.AlvythStore.user && window.AlvythStore.user.id);
    const labels = @json($labels);
    const showLikes = @json($showLikes);

    createApp({
        setup() {
            const loading = ref(true);
            const comments = ref([]);
            const total = ref(0);
            const currentPage = ref(1);
            const lastPage = ref(1);
            const likes = ref(0);
            const liked = ref(false);

            // Pluralised the way the server pluralises it — same choice string, same picker
            // semantics — rather than a JS either/or that flattens richer locales.
            const countLabel = computed(() =>
                window.ThemeApi.transChoice(labels.countChoice, total.value)
                    .replace(':count', total.value)
            );

            const showForm = ref(false);
            const formName = ref('');
            const formEmail = ref('');
            const formBody = ref('');
            const formError = ref('');
            const formSuccess = ref('');
            const submitting = ref(false);

            // Fetched in the browser rather than rendered server-side, the way the dish reviews
            // are: a post is read far more often than it is commented on, and paging through
            // comments should not reload the story above them.
            const load = async (page = 1) => {
                loading.value = true;
                try {
                    // Through the shared client rather than a bare fetch, so the signed-in
                    // reader's token rides along — without it, `has_liked` below is answered
                    // for an anonymous visitor and the heart never lights for its owner.
                    const data = await window.ThemeApi.interactions.getComments('posts', postId, page);
                    const list = data.comments ?? data.data ?? [];
                    comments.value  = Array.isArray(list) ? list : (list.data ?? []);
                    total.value     = data.total ?? comments.value.length;
                    currentPage.value = data.current_page ?? (list.current_page ?? page);
                    lastPage.value    = data.last_page ?? (list.last_page ?? 1);
                    // The like count and whether this visitor has liked come back on THIS
                    // response — `likes_count` and `has_liked`, measured against the live
                    // endpoint. The component first fetched `/reactions` separately, which was
                    // both a second request for data already in hand and a different payload
                    // shape to guess at.
                    if (showLikes) {
                        likes.value = data.likes_count ?? 0;
                        liked.value = !!data.has_liked;
                    }
                } catch (e) {
                    comments.value = [];
                } finally {
                    loading.value = false;
                }
            };

            const toggleLike = async () => {
                // Optimistic, and reverted if the server disagrees — the button must answer the
                // finger immediately or it reads as broken.
                const was = liked.value;
                liked.value = !was;
                likes.value = Math.max(0, likes.value + (was ? -1 : 1));
                try {
                    const data = await window.ThemeApi.interactions.toggleReaction('posts', postId);
                    if (data.action) liked.value = data.action !== 'removed';
                } catch (e) {
                    liked.value = was;
                    likes.value = Math.max(0, likes.value + (was ? 1 : -1));
                }
            };

            // Opening the form clears the last outcome: the thank-you belongs to the comment
            // that was posted, not to the one being written now.
            const toggleForm = () => {
                showForm.value = !showForm.value;
                formError.value = '';
                formSuccess.value = '';
            };

            const submit = async () => {
                formError.value = '';
                formSuccess.value = '';

                if (!formBody.value.trim())  { formError.value = labels.noBody;  return; }
                if (!isLoggedIn && !formName.value.trim())  { formError.value = labels.noName;  return; }
                if (!isLoggedIn && !formEmail.value.trim()) { formError.value = labels.noEmail; return; }

                submitting.value = true;
                try {
                    const data = await window.ThemeApi.interactions.postComment(
                        'posts', postId, formBody.value.trim(),
                        !isLoggedIn ? formEmail.value.trim() : null,
                        !isLoggedIn ? formName.value.trim() : null
                    );
                    // A held comment will not be in the list that reloads, which is exactly why
                    // the two outcomes say different things. Compared against the status the
                    // API actually returns — `pending` or `active`, never `published`, which
                    // is why every commenter used to be told their comment was held.
                    const held = (data.comment?.status ?? data.status) === 'pending';
                    formSuccess.value = held ? labels.held : labels.live;

                    // Back to how the section started: emptied and closed. Leaving the name and
                    // email behind reads as a form that did not go through, and invites the
                    // same person to press Post again.
                    formBody.value  = '';
                    formName.value  = '';
                    formEmail.value = '';
                    showForm.value  = false;

                    await load(1);
                } catch (e) {
                    // The client throws { data } for a refusal and a bare error when the
                    // request never reached the server.
                    formError.value = e && e.data ? labels.failed : labels.offline;
                } finally {
                    submitting.value = false;
                }
            };

            onMounted(() => { load(1); });

            return {
                loading, comments, total, countLabel, currentPage, lastPage, load,
                likes, liked, toggleLike,
                showForm, toggleForm, formName, formEmail, formBody, formError, formSuccess, submitting, submit,
                labels, isLoggedIn,
            };
        },
    }).mount('#{{ $uid }}');
})();
</script>
