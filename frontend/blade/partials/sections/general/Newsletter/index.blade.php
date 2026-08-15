@if($formSlug !== '')
<section class="saffron-newsletter saffron-newsletter--{{ $layout }} saffron-newsletter--{{ $tone }}" {!! $motionAttrs !!}>
    <div class="saffron-container">
        <div class="saffron-newsletter__inner" data-saffron-reveal-group>
            @if($heading !== '' || $subheading !== '')
                <div class="saffron-newsletter__copy" data-saffron-reveal>
                    @if($heading !== '')
                        <h2 class="saffron-newsletter__heading">{{ $heading }}</h2>
                    @endif
                    @if($subheading !== '')
                        <p class="saffron-newsletter__lede">{{ $subheading }}</p>
                    @endif
                </div>
            @endif

            <div class="saffron-newsletter__form" data-saffron-reveal>
                <x-theme.component name="DynamicForm" :data="['slug' => $formSlug, 'variant' => 'inline', 'show_title' => false]" />
                @if($note !== '')
                    <p class="saffron-newsletter__note">{{ $note }}</p>
                @endif
            </div>
        </div>
    </div>
</section>
@elseif(config('app.debug'))
    <div class="saffron-container">
        <div class="alert alert-warning my-3">
            <small>{{ __('Newsletter section: no form selected. Pick one under Forms, then choose it in this section.') }}</small>
        </div>
    </div>
@endif
