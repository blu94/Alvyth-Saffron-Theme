@if($address !== '' || $phone !== '' || !empty($days) || $note !== '')
<section class="saffron-outlet">
    <div class="saffron-container">
        <div class="row g-4 g-lg-5">
            <div class="col-12 col-lg-5">
                <h2 class="saffron-section-title">{{ $heading }}</h2>

                @if($address !== '')
                    <p class="saffron-outlet__address">{!! nl2br(e($address)) !!}</p>
                @endif

                <div class="saffron-outlet__contacts">
                    @if($phone !== '')
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="saffron-outlet__contact">{{ $phone }}</a>
                    @endif
                    @if($email !== '')
                        <a href="mailto:{{ $email }}" class="saffron-outlet__contact">{{ $email }}</a>
                    @endif
                </div>

                @if($mapUrl !== '')
                    <a href="{{ $mapUrl }}" target="_blank" rel="noopener"
                       class="saffron-btn saffron-btn--outline saffron-btn--sm mt-3">{{ __('Get directions') }}</a>
                @endif

                @if($note !== '')
                    <p class="saffron-outlet__note">{{ $note }}</p>
                @endif
            </div>

            @if(!empty($days))
                <div class="col-12 col-lg-7">
                    <h3 class="saffron-outlet__hours-title">{{ __('Opening hours') }}</h3>
                    <dl class="saffron-outlet__hours">
                        @foreach($days as $day)
                            <div class="saffron-outlet__hours-row">
                                <dt>{{ __($day['label']) }}</dt>
                                <dd>
                                    @if(empty($day['spans']))
                                        <span class="saffron-outlet__closed">{{ __('Closed') }}</span>
                                    @else
                                        {{ implode(', ', $day['spans']) }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>
    </div>
</section>
@endif
