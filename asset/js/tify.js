/**
 * Mounts a TIFY viewer in every element with class "tify-viewer".
 *
 * The TIFY options are read from the element's data-tify-options attribute,
 * written by the Tify view helper. A viewer is only created once its element
 * is near the viewport and has a size, so hidden admin tabs and viewers far
 * down a page cost nothing until they are needed.
 *
 * Loaded as an ES module after vendor/tify/tify.js, which defines window.Tify.
 */
const mounted = new WeakSet();

function mount(element) {
    if (mounted.has(element) || typeof window.Tify !== 'function') {
        return;
    }
    mounted.add(element);

    let options = {};
    try {
        options = JSON.parse(element.dataset.tifyOptions || '{}');
    } catch (error) {
        console.error('Tify: invalid options', error);
        return;
    }

    // TIFY replaces the container's content; drop the noscript fallback.
    element.replaceChildren();

    try {
        element.tify = new window.Tify({ ...options, container: element });
    } catch (error) {
        console.error('Tify: cannot start the viewer', error);
    }
}

function isVisible(element) {
    return element.offsetWidth > 0 && element.offsetHeight > 0;
}

function init() {
    const elements = document.querySelectorAll('.tify-viewer');
    if (!elements.length) {
        return;
    }

    if (!('IntersectionObserver' in window)) {
        elements.forEach(mount);
        return;
    }

    // An element in a hidden tab has no size and never intersects; the
    // observer fires again when the tab is shown.
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting && isVisible(entry.target)) {
                observer.unobserve(entry.target);
                mount(entry.target);
            }
        });
    }, { rootMargin: '200px' });

    elements.forEach((element) => observer.observe(element));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
