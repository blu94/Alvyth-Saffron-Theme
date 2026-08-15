{{-- One footer block. Expects $block (type, title, content, form_slug, form_intro) and the
     resolved content sources from footer.blade.php: $siteTitle, $tagline, $address, $phone,
     $hours, $copyright, $footerLinks, $socials, $socialIcon, $t. --}}
@php
    $blockType  = $block['type'] ?? 'links';
    $blockTitle = $t($block['title'] ?? '');
@endphp

<div class="saffron-footer__block saffron-footer__block--{{ $blockType }}">
    @if($blockTitle !== '' && $blockType !== 'brand')
        <div class="saffron-footer__block-title">{{ $blockTitle }}</div>
    @endif

    @switch($blockType)
        @case('brand')
            <div class="saffron-footer__brand">{{ $siteTitle }}</div>
            @if($tagline !== '')
                <p class="saffron-footer__tagline mt-2 mb-0">{{ $tagline }}</p>
            @endif
            @break

        @case('contact')
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
            @break

        @case('links')
            @if($footerLinks->isNotEmpty())
                <nav aria-label="{{ $blockTitle !== '' ? $blockTitle : __('Footer') }}">
                    @foreach($footerLinks as $link)
                        <a href="{{ $link['url'] ?: '#' }}" class="saffron-footer__link"
                           @if($link['target'] === '_blank') target="_blank" rel="noopener" @endif>{{ $link['label'] }}</a>
                    @endforeach
                </nav>
            @endif
            @break

        @case('socials')
            @if($socials->isNotEmpty())
                <div class="saffron-footer__socials">
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
            @break

        @case('text')
            @php $blockContent = $t($block['content'] ?? ''); @endphp
            @if($blockContent !== '')
                {{-- Richtext authored by the operator in the admin, so it is trusted markup —
                     the same trust core's Text Block section extends to its own field. --}}
                <div class="saffron-footer__text">{!! $blockContent !!}</div>
            @endif
            @break

        @case('form')
            @php
                $formSlug = is_array($block['form_slug'] ?? null)
                    ? (string) ($block['form_slug']['value'] ?? $block['form_slug']['slug'] ?? '')
                    : (string) ($block['form_slug'] ?? '');
            @endphp
            @if($formSlug !== '')
                <x-theme.component name="DynamicForm" :data="['slug' => $formSlug, 'intro' => $t($block['form_intro'] ?? '')]" />
            @endif
            @break

        @case('copyright')
            @if($copyright !== '')
                <div class="saffron-footer__copyright">{{ $copyright }}</div>
            @endif
            @break
    @endswitch
</div>
