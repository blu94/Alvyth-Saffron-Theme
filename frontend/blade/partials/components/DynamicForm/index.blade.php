<div id="{{ $uid }}" class="saffron-form {{ $variantClass }}" v-cloak>
    @if($intro !== '')
        <p class="saffron-form__intro">{{ $intro }}</p>
    @endif

    <p v-if="loading" class="saffron-form__state">@{{ labels.loading }}</p>

    <p v-else-if="loadError" class="saffron-form__state saffron-form__state--error">@{{ loadError }}</p>

    <form v-else-if="schema" class="saffron-form__body" @submit.prevent="submitForm" novalidate>
        <h3 v-if="showTitle && schema.title" class="saffron-form__title">@{{ t(schema.title) }}</h3>
        <p v-if="showTitle && schema.description" class="saffron-form__desc">@{{ t(schema.description) }}</p>

        <div v-for="field in schema.fields" :key="field.id" class="saffron-form__field">
            <template v-if="['text', 'email', 'number', 'date', 'url', 'tel'].includes(field.type)">
                <label class="saffron-form__label" :for="fieldId(field)">@{{ t(field.title) }}</label>
                <input :type="field.type" class="saffron-form__input" :id="fieldId(field)"
                       :placeholder="t(field.title)" :required="!!(field.data && field.data.required)"
                       v-model="formData[field.id]">
            </template>

            <template v-else-if="field.type === 'textarea'">
                <label class="saffron-form__label" :for="fieldId(field)">@{{ t(field.title) }}</label>
                <textarea class="saffron-form__input" :id="fieldId(field)" rows="3"
                          :placeholder="t(field.title)" :required="!!(field.data && field.data.required)"
                          v-model="formData[field.id]"></textarea>
            </template>

            <template v-else-if="field.type === 'select'">
                <label class="saffron-form__label" :for="fieldId(field)">@{{ t(field.title) }}</label>
                <select class="saffron-form__input" :id="fieldId(field)"
                        :required="!!(field.data && field.data.required)" v-model="formData[field.id]">
                    <option value="" disabled>@{{ labels.choose }}</option>
                    <option v-for="opt in options(field)" :key="opt" :value="opt">@{{ opt }}</option>
                </select>
            </template>

            <template v-else-if="field.type === 'link'">
                <a :href="(field.data && field.data.options) || '#'" class="saffron-form__link" target="_blank" rel="noopener">@{{ t(field.title) }}</a>
            </template>

            <template v-else-if="field.type === 'submit'">
                <button type="submit" class="saffron-btn saffron-btn--accent saffron-form__submit" :disabled="submitting">
                    @{{ submitting ? labels.submitting : (t(field.title) || labels.submit) }}
                </button>
            </template>
        </div>

        {{-- Forms built without an explicit submit field still need a way to send. --}}
        <div v-if="!hasSubmitField" class="saffron-form__field">
            <button type="submit" class="saffron-btn saffron-btn--accent saffron-form__submit" :disabled="submitting">
                @{{ submitting ? labels.submitting : labels.submit }}
            </button>
        </div>

        <p v-if="success" class="saffron-form__state saffron-form__state--success" role="status">@{{ success }}</p>
        <p v-if="submitError" class="saffron-form__state saffron-form__state--error" role="alert">@{{ submitError }}</p>
    </form>
</div>

<script>
(function () {
    const { createApp, ref, reactive, computed, onMounted } = Vue;
    const payload = @json($payload);

    createApp({
        setup() {
            const schema      = ref(null);
            const formData    = reactive({});
            const loading     = ref(true);
            const loadError   = ref(null);
            const submitError = ref(null);
            const success     = ref(null);
            const submitting  = ref(false);
            const labels      = payload.labels;
            const showTitle   = payload.showTitle !== false;

            const t = (val) => {
                if (!val) return '';
                if (typeof val === 'string') return val;
                return val[payload.locale] || val.en || Object.values(val)[0] || '';
            };

            const fieldId = (field) => (field.data && field.data.elementId) || (payload.slug + '-field-' + field.id);

            const options = (field) => {
                const raw = field.data && field.data.options;
                if (!raw) return [];
                return String(raw).split(',').map(s => s.trim()).filter(Boolean);
            };

            const hasSubmitField = computed(() =>
                !!(schema.value && schema.value.fields && schema.value.fields.some(f => f.type === 'submit')));

            const resetData = () => {
                (schema.value && schema.value.fields || []).forEach(f => { formData[f.id] = ''; });
            };

            const load = async () => {
                if (!window.ThemeApi || !window.ThemeApi.forms) {
                    loadError.value = labels.loadFailed;
                    loading.value = false;
                    return;
                }
                try {
                    schema.value = await window.ThemeApi.forms.get(payload.slug);
                    resetData();
                } catch (err) {
                    loadError.value = labels.loadFailed;
                } finally {
                    loading.value = false;
                }
            };

            const submitForm = async () => {
                submitting.value = true;
                submitError.value = null;
                success.value = null;
                try {
                    const res = await window.ThemeApi.forms.submit(payload.slug, formData);
                    success.value = (res && res.message) || labels.success;
                    resetData();
                    window.setTimeout(() => { success.value = null; }, 6000);
                } catch (err) {
                    submitError.value = (err && err.data && err.data.message) || labels.failed;
                } finally {
                    submitting.value = false;
                }
            };

            onMounted(load);

            return { schema, formData, loading, loadError, submitError, success, submitting, labels, showTitle, t, fieldId, options, hasSubmitField, submitForm };
        },
    }).mount('#{{ $uid }}');
})();
</script>
