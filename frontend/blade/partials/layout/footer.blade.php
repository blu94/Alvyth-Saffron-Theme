@php
    // See the note in header.blade.php — $locale and $settings are absent on the
    // hard-404 / hard-maintenance render paths.
    $loc = $locale ?? app()->getLocale();

    $t = function ($value) use ($loc) {
        if (is_array($value)) return $value[$loc] ?? $value['en'] ?? (count($value) ? current($value) : '');
        return $value ?? '';
    };

    $linkUrl = function ($value) {
        if (is_array($value)) return $value['url'] ?? '';
        return (string) ($value ?? '');
    };

    $linkTarget = function ($value) {
        if (is_array($value)) return $value['target'] ?? '_self';
        return '_self';
    };

    $siteTitle = $appSettings['site_title'] ?? 'Ovynt';

    $tagline   = $t($settings['footer_tagline'] ?? '');
    $address   = $t($settings['footer_address'] ?? '');
    $phone     = (string) ($settings['footer_phone'] ?? '');
    $hours     = $t($settings['footer_hours'] ?? '');
    $copyright = $t($settings['footer_copyright'] ?? '');

    $footerLinks = collect($settings['footer_links'] ?? [])
        ->map(fn ($l) => [
            'label'  => $t($l['label'] ?? ''),
            'url'    => $linkUrl($l['url'] ?? ''),
            'target' => $linkTarget($l['url'] ?? ''),
        ])
        ->filter(fn ($l) => $l['label'] !== '')
        ->values();

    $socials = collect($settings['footer_socials'] ?? [])
        ->map(fn ($s) => [
            'icon'   => (string) ($s['icon'] ?? ''),
            'url'    => $linkUrl($s['url'] ?? ''),
            'target' => $linkTarget($s['url'] ?? ''),
        ])
        ->filter(fn ($s) => $s['url'] !== '')
        ->values();
@endphp

<footer class="saffron-footer">
    <div class="saffron-container">
        <div class="row gy-4">
            <div class="col-12 col-lg-4">
                <div class="saffron-footer__brand">{{ $siteTitle }}</div>
                @if($tagline !== '')
                    <p class="saffron-footer__tagline mt-2 mb-0">{{ $tagline }}</p>
                @endif
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                <div class="saffron-footer__heading">{{ __('Find us') }}</div>
                @if($address !== '')
                    <p class="saffron-footer__meta mb-2">{!! nl2br(e($address)) !!}</p>
                @endif
                @if($phone !== '')
                    <p class="saffron-footer__meta mb-2">
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}" class="saffron-footer__link d-inline p-0">{{ $phone }}</a>
                    </p>
                @endif
                @if($hours !== '')
                    <p class="saffron-footer__meta mb-0">{{ $hours }}</p>
                @endif
            </div>

            <div class="col-12 col-sm-6 col-lg-4">
                @if($footerLinks->isNotEmpty())
                    <div class="saffron-footer__heading">{{ __('More') }}</div>
                    <nav aria-label="{{ __('Footer') }}">
                        @foreach($footerLinks as $link)
                            <a href="{{ $link['url'] ?: '#' }}" class="saffron-footer__link"
                               @if($link['target'] === '_blank') target="_blank" rel="noopener" @endif>{{ $link['label'] }}</a>
                        @endforeach
                    </nav>
                @endif

                @if($socials->isNotEmpty())
                    <div class="saffron-footer__socials {{ $footerLinks->isNotEmpty() ? 'mt-3' : '' }}">
                        @foreach($socials as $social)
                            <a href="{{ $social['url'] }}" class="saffron-footer__social"
                               @if($social['target'] === '_blank') target="_blank" rel="noopener" @endif
                               aria-label="{{ Str::headline(Str::after($social['icon'], 'tabler-brand-')) ?: __('Social') }}">
                                <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="1.8"
                                     fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9"></circle>
                                    <line x1="12" y1="8" x2="12" y2="8"></line>
                                    <path d="M12 12v4"></path>
                                </svg>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        @if($copyright !== '')
            <div class="saffron-footer__bar">{{ $copyright }}</div>
        @endif
    </div>
</footer>
