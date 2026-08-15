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

    // The inner SVG for each network the icon picker offers, keyed by tabler slug. Three
    // channels all rendering one generic circle read as broken — the operator picked
    // Facebook and got the same dot as Instagram. Paths are Tabler's own 24x24 strokes;
    // anything unmapped keeps the generic mark rather than rendering nothing.
    $socialIcon = function (string $slug): string {
        return match ($slug) {
            'tabler-brand-facebook'  => '<path d="M7 10v4h3v7h4v-7h3l1 -4h-4v-2a1 1 0 0 1 1 -1h3v-4h-3a5 5 0 0 0 -5 5v2h-3" />',
            'tabler-brand-instagram' => '<rect x="4" y="4" width="16" height="16" rx="4" /><circle cx="12" cy="12" r="3" /><line x1="16.5" y1="7.5" x2="16.5" y2="7.501" />',
            'tabler-brand-x',
            'tabler-brand-twitter'   => '<path d="M4 4l11.733 16h4.267l-11.733 -16z" /><path d="M4 20l6.768 -6.768m2.46 -2.46l6.772 -6.772" />',
            'tabler-brand-youtube'   => '<rect x="3" y="5" width="18" height="14" rx="4" /><path d="M10 9l5 3l-5 3z" />',
            'tabler-brand-tiktok'    => '<path d="M21 7.917v4.034a9.948 9.948 0 0 1 -5 -1.951v4.5a6.5 6.5 0 1 1 -8 -6.326v4.326a2.5 2.5 0 1 0 4 2v-11.5h4.083a6.005 6.005 0 0 0 4.917 4.917" />',
            'tabler-brand-whatsapp'  => '<path d="M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9" /><path d="M9 10a.5 .5 0 0 0 1 0v-1a.5 .5 0 0 0 -1 0v1a5 5 0 0 0 5 5h1a.5 .5 0 0 0 0 -1h-1a.5 .5 0 0 0 0 1" />',
            default                  => '<circle cx="12" cy="12" r="9"></circle><line x1="12" y1="8" x2="12" y2="8"></line><path d="M12 12v4"></path>',
        };
    };
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
                                     fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $socialIcon($social['icon']) !!}</svg>
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
