{{-- One option is an answer, not a question: it still has to reach the server, so it goes in a
     hidden input rather than a control the customer cannot change. --}}
@if(count($methods) < 2)
    <input type="hidden" data-checkout-payment value="{{ $methods[0] }}">
    @if($methods[0] === 'cod' && $instructions !== '')
        <div class="saffron-payment-choice saffron-payment-choice--fixed">
            <p class="saffron-payment-choice__note mb-0">{{ $instructions }}</p>
        </div>
    @endif
@else
<div id="{{ $uid }}" class="saffron-payment-choice" v-cloak v-show="visible">
    <fieldset class="saffron-payment-choice__group">
        <legend class="saffron-payment-choice__legend">{{ __('How would you like to pay') }}</legend>

        <label class="saffron-payment-choice__option" :class="{ 'is-active': method === 'online' }">
            <input type="radio" name="saffron-payment-choice" value="online" data-checkout-payment
                   v-model="method" class="saffron-payment-choice__input">
            <span class="saffron-payment-choice__text">
                <span class="saffron-payment-choice__name">{{ __('Pay now') }}</span>
                <span class="saffron-payment-choice__hint">{{ __('Card or online banking') }}</span>
            </span>
        </label>

        <label class="saffron-payment-choice__option" :class="{ 'is-active': method === 'cod' }">
            <input type="radio" name="saffron-payment-choice" value="cod" data-checkout-payment
                   v-model="method" class="saffron-payment-choice__input">
            <span class="saffron-payment-choice__text">
                <span class="saffron-payment-choice__name">{{ $codLabel }}</span>
                <span class="saffron-payment-choice__hint">{{ __('Nothing is taken now') }}</span>
            </span>
        </label>

        @if($instructions !== '')
        <p v-if="method === 'cod'" class="saffron-payment-choice__note mb-0">{{ $instructions }}</p>
        @endif
    </fieldset>
</div>

<script>
(function () {
    const { createApp, ref, computed, onMounted } = Vue;

    const root = document.getElementById(@json($uid));

    // Seated immediately above the checkout button, and after everything that changes the
    // total: how to pay is the last decision, so it belongs last. The schedule block is the
    // anchor when it is present because it is itself seated above the button; otherwise the
    // button. Same retry loop as the other seated blocks — core re-renders this region as the
    // cart validates, so the target does not exist on the first pass.
    const seat = () => {
        const button = document.querySelector('[data-co-place]');

        if (!button || !button.parentNode || !root) return !!button;

        if (root.nextElementSibling === button) return true;

        button.parentNode.insertBefore(root, button);

        return true;
    };

    if (!seat()) {
        let tries = 0;
        const timer = setInterval(() => {
            if (seat() || ++tries > 40) clearInterval(timer);
        }, 100);
    }

    createApp({
        setup() {
            const mounted = ref(false);
            const method  = ref('online');

            // Hidden until there is something to pay for, like every other block in this
            // summary: asking how somebody wants to pay for an empty cart is noise.
            const cartCount = computed(() => {
                if (!window.OvyntStore) return 0;
                return window.OvyntStore.cartList.reduce((n, l) => n + (l.quantity || 0), 0);
            });

            onMounted(() => { mounted.value = true; });

            return {
                method,
                visible: computed(() => mounted.value && cartCount.value > 0),
            };
        },
    }).mount('#{{ $uid }}');
})();
</script>
@endif
