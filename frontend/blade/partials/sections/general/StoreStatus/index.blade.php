@php
    // An unconfigured shop has no opinion about its hours, so the banner stays silent rather
    // than claiming the kitchen is open.
    $render = $source !== 'unconfigured' && (!$isOpen || $showWhenOpen);
@endphp

@if($render)
<section class="saffron-store-status {{ $isOpen ? 'saffron-store-status--open' : 'saffron-store-status--closed' }}" {!! $motionAttrs !!}>
    <div class="saffron-container saffron-store-status__inner" data-saffron-reveal>
        <span class="saffron-store-status__dot" aria-hidden="true"></span>

        <p class="saffron-store-status__text mb-0">
            @if($isOpen)
                <strong>{{ __('Open now') }}</strong>
                @if($closesAt)
                    <span class="saffron-store-status__meta">{{ __('until :time', ['time' => $closesAt]) }}</span>
                @endif
                @if($openMessage !== '')
                    <span class="saffron-store-status__meta">{{ $openMessage }}</span>
                @endif
            @else
                <strong>{{ __('Closed right now') }}</strong>
                @if($reason)
                    <span class="saffron-store-status__meta">{{ $reason }}</span>
                @elseif($nextOpen && $nextDay)
                    <span class="saffron-store-status__meta">{{ __('Opens :day at :time', ['day' => $nextDay, 'time' => $nextOpen]) }}</span>
                @elseif($nextOpen)
                    <span class="saffron-store-status__meta">{{ __('Opens at :time', ['time' => $nextOpen]) }}</span>
                @endif
                @if($closedMessage !== '')
                    <span class="saffron-store-status__note">{{ $closedMessage }}</span>
                @endif
            @endif
        </p>
    </div>
</section>
@endif
