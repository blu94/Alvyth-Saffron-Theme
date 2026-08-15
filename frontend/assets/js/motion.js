/**
 * Saffron motion — scroll reveals and the dish marquee.
 *
 * Loaded by layout.blade.php only when the theme's "Enable Scroll Animations" setting is
 * on. The layout also sets `window.SaffronMotion` (default effect, speed, stagger, once,
 * offset) and adds `saffron-motion` to <html> unless the visitor prefers reduced motion,
 * so the hidden starting state in _motion.scss exists only when this script will run.
 *
 * Markup contract (see backend/Support/Motion.php):
 *   [data-saffron-motion="{effect|''}"]      — a section root; '' means the theme default
 *     [data-saffron-motion-delay="ms"]       — wait before the section starts
 *     [data-saffron-motion-duration="ms"]    — the section's own speed (absent = theme default)
 *     [data-saffron-motion-stagger="ms"]     — the section's own gap    (absent = theme default)
 *   [data-saffron-reveal]                    — an element to reveal
 *   [data-saffron-reveal-group]              — stagger its reveals
 *   [data-saffron-marquee]                   — a marquee viewport
 *
 * Every section picks its own effect, speed, stagger and delay under Styling → Animation;
 * the theme's Animation tab only supplies what a section leaves on "Theme default".
 *
 * Nothing here is Vue: the whole thing is class toggling driven by IntersectionObserver.
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var config = window.SaffronMotion || {};

    var EFFECTS = ['fade-up', 'fade-down', 'fade-in', 'fade-left', 'fade-right', 'zoom-in', 'fade-blur'];
    var defaultEffect = EFFECTS.indexOf(config.effect) !== -1 ? config.effect : 'fade-up';
    var duration = clamp(parseInt(config.duration, 10), 100, 4000, 700);
    var stagger = clamp(parseInt(config.stagger, 10), 0, 1000, 100);
    var offset = clamp(parseInt(config.offset, 10), 0, 45, 10);
    var once = config.once !== false && config.once !== 'false' && config.once !== 0 && config.once !== '0';

    // Bounds the batch stagger so a wall of cards revealed in one go still finishes within
    // a couple of seconds instead of trickling in for ten.
    var MAX_STAGGER_STEPS = 12;

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var canAnimate = root.classList.contains('saffron-motion') && !reduced && 'IntersectionObserver' in window;

    function clamp(value, min, max, fallback) {
        if (isNaN(value)) return fallback;
        return Math.min(max, Math.max(min, value));
    }

    // A section's own value for one of its Styling → Animation fields, or the theme
    // default when the section left it on "Theme default" (the attribute is then absent).
    function scopeNumber(scope, attr, min, max, fallback) {
        if (!scope || !scope.hasAttribute(attr)) return fallback;
        return clamp(parseInt(scope.getAttribute(attr), 10), min, max, fallback);
    }

    function revealAll() {
        var nodes = document.querySelectorAll('[data-saffron-reveal]');
        for (var i = 0; i < nodes.length; i++) nodes[i].classList.add('is-revealed');
        root.classList.remove('saffron-motion');
    }

    function domOrder(a, b) {
        if (a === b) return 0;
        return (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1;
    }

    function initReveals() {
        if (!canAnimate) {
            revealAll();
            return;
        }

        var nodes = document.querySelectorAll('[data-saffron-motion] [data-saffron-reveal]');
        if (!nodes.length) return;

        var i, el, scope, effect;

        // The effect class goes on before anything is observed so each element starts from
        // its own offset. Transitions are declared on the revealed state only, so this
        // assignment is instant even if the browser has already painted. Effect and speed
        // are the section's own where it chose them, the theme default where it did not.
        for (i = 0; i < nodes.length; i++) {
            el = nodes[i];
            scope = el.closest('[data-saffron-motion]');
            effect = scope && EFFECTS.indexOf(scope.getAttribute('data-saffron-motion')) !== -1
                ? scope.getAttribute('data-saffron-motion')
                : defaultEffect;
            el.classList.add('saffron-reveal--' + effect);
            el.style.setProperty('--saffron-reveal-duration', scopeNumber(scope, 'data-saffron-motion-duration', 100, 4000, duration) + 'ms');
        }

        var observer = new IntersectionObserver(function (entries) {
            var entering = [];
            var k, entry, target;

            for (k = 0; k < entries.length; k++) {
                entry = entries[k];
                target = entry.target;

                if (entry.isIntersecting) {
                    entering.push(target);
                } else if (!once && target.classList.contains('is-revealed')) {
                    target.classList.remove('is-revealed');
                    target.style.setProperty('--saffron-reveal-delay', '0ms');
                }
            }

            if (!entering.length) return;

            // Stagger is worked out per batch, not per section: the cards that arrive
            // together fan in one after another, while a card that scrolls in alone
            // later shows at once instead of waiting for a queue it was never in.
            entering.sort(domOrder);

            var counters = [];
            var groups = [];

            for (k = 0; k < entering.length; k++) {
                target = entering[k];

                var section = target.closest('[data-saffron-motion]');
                var group = target.closest('[data-saffron-reveal-group]');
                var base = scopeNumber(section, 'data-saffron-motion-delay', 0, 5000, 0);
                var gap = scopeNumber(section, 'data-saffron-motion-stagger', 0, 1000, stagger);
                var step = 0;

                if (group && gap > 0) {
                    var idx = groups.indexOf(group);
                    if (idx === -1) {
                        groups.push(group);
                        counters.push(0);
                        idx = groups.length - 1;
                    }
                    step = Math.min(counters[idx], MAX_STAGGER_STEPS);
                    counters[idx]++;
                }

                target.style.setProperty('--saffron-reveal-delay', (base + step * gap) + 'ms');
                target.classList.add('is-revealed');

                if (once) observer.unobserve(target);
            }
        }, {
            root: null,
            rootMargin: '0px 0px -' + offset + '% 0px',
            threshold: 0
        });

        for (i = 0; i < nodes.length; i++) observer.observe(nodes[i]);
    }

    /**
     * A marquee track is two identical lists translated by half its own width, so the
     * loop is seamless only while one list is at least as wide as the viewport. Short
     * menus get more clone pairs until it is; the clones are decorative and hidden from
     * assistive tech, and the copies' links drop out of the tab order.
     */
    function initMarquees() {
        var viewports = document.querySelectorAll('[data-saffron-marquee]');

        for (var i = 0; i < viewports.length; i++) {
            fillMarquee(viewports[i]);
        }
    }

    function fillMarquee(viewport) {
        var track = viewport.querySelector('.saffron-marquee__track');
        var lists = track ? track.querySelectorAll('.saffron-marquee__list') : [];
        if (!track || lists.length < 2) return;

        var source = lists[0];
        var need = viewport.clientWidth;
        var guard = 0;

        while (source.offsetWidth > 0 && source.offsetWidth * (track.children.length / 2) < need && guard < 6) {
            var a = source.cloneNode(true);
            var b = source.cloneNode(true);
            markClone(a);
            markClone(b);
            track.appendChild(a);
            track.appendChild(b);
            guard++;
        }

        // Re-run once when the viewport widens past the filled width.
        if (!viewport.__saffronMarqueeResize) {
            viewport.__saffronMarqueeResize = true;
            var timer = null;
            window.addEventListener('resize', function () {
                clearTimeout(timer);
                timer = setTimeout(function () { fillMarquee(viewport); }, 200);
            });
        }
    }

    function markClone(list) {
        list.setAttribute('aria-hidden', 'true');
        list.classList.add('saffron-marquee__list--clone');
        var links = list.querySelectorAll('a, button');
        for (var i = 0; i < links.length; i++) links[i].setAttribute('tabindex', '-1');
    }

    function boot() {
        initReveals();
        initMarquees();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
