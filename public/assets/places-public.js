/**
 * Partner Places — gallery lightbox, video modal, UI polish
 */
(function () {
    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

    function openLightbox(images, startIndex) {
        var idx = startIndex || 0;
        var overlay = document.createElement('div');
        overlay.className = 'places-lightbox open';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.innerHTML =
            '<div class="places-lightbox-backdrop"></div>' +
            '<div class="places-lightbox-inner">' +
            '  <button type="button" class="places-lightbox-close" aria-label="Close">&times;</button>' +
            '  <button type="button" class="places-lightbox-nav places-lightbox-prev" aria-label="Previous">&lsaquo;</button>' +
            '  <figure class="places-lightbox-figure">' +
            '    <img src="" alt="">' +
            '    <figcaption></figcaption>' +
            '  </figure>' +
            '  <button type="button" class="places-lightbox-nav places-lightbox-next" aria-label="Next">&rsaquo;</button>' +
            '  <div class="places-lightbox-counter"></div>' +
            '</div>';
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        var img = qs('.places-lightbox-figure img', overlay);
        var cap = qs('.places-lightbox-figure figcaption', overlay);
        var counter = qs('.places-lightbox-counter', overlay);

        function render() {
            var item = images[idx];
            img.src = item.src;
            img.alt = item.caption || '';
            cap.textContent = item.caption || '';
            counter.textContent = (idx + 1) + ' / ' + images.length;
            qs('.places-lightbox-prev', overlay).style.display = images.length > 1 ? '' : 'none';
            qs('.places-lightbox-next', overlay).style.display = images.length > 1 ? '' : 'none';
        }

        function close() {
            overlay.classList.remove('open');
            document.body.style.overflow = '';
            setTimeout(function () { overlay.remove(); }, 200);
        }

        function prev() { idx = (idx - 1 + images.length) % images.length; render(); }
        function next() { idx = (idx + 1) % images.length; render(); }

        qs('.places-lightbox-close', overlay).addEventListener('click', close);
        qs('.places-lightbox-backdrop', overlay).addEventListener('click', close);
        qs('.places-lightbox-prev', overlay).addEventListener('click', prev);
        qs('.places-lightbox-next', overlay).addEventListener('click', next);

        overlay.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
            if (e.key === 'ArrowLeft') prev();
            if (e.key === 'ArrowRight') next();
        });

        render();
        qs('.places-lightbox-close', overlay).focus();
    }

    function openVideoModal(playUrl, title, mode) {
        var overlay = document.createElement('div');
        overlay.className = 'places-video-modal open';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        var isFile = mode === 'file';
        var mediaHtml = isFile
            ? '<video src="" controls playsinline autoplay></video>'
            : '<iframe src="" title="" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
        overlay.innerHTML =
            '<div class="places-video-modal-backdrop"></div>' +
            '<div class="places-video-modal-inner">' +
            '  <button type="button" class="places-lightbox-close" aria-label="Close">&times;</button>' +
            '  <div class="places-video-modal-frame' + (isFile ? ' places-video-modal-frame--file' : '') + '">' +
            '    ' + mediaHtml +
            '  </div>' +
            '</div>';
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        var frame = qs('.places-video-modal-frame', overlay);
        if (isFile) {
            var video = qs('video', frame);
            video.src = playUrl;
            video.title = title || 'Video';
        } else {
            var iframe = qs('iframe', frame);
            iframe.src = playUrl + (playUrl.indexOf('?') >= 0 ? '&' : '?') + 'autoplay=1';
            iframe.title = title || 'Video';
        }

        function close() {
            overlay.classList.remove('open');
            document.body.style.overflow = '';
            setTimeout(function () { overlay.remove(); }, 200);
        }

        qs('.places-lightbox-close', overlay).addEventListener('click', close);
        qs('.places-video-modal-backdrop', overlay).addEventListener('click', close);
        document.addEventListener('keydown', function onKey(e) {
            if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); }
        });
    }

    function initGallery() {
        var triggers = qsa('[data-gallery-index][data-gallery-src]');
        if (!triggers.length) return;

        var images = triggers
            .slice()
            .sort(function (a, b) {
                return parseInt(a.getAttribute('data-gallery-index'), 10) - parseInt(b.getAttribute('data-gallery-index'), 10);
            })
            .map(function (el) {
                return {
                    src: el.getAttribute('data-gallery-src'),
                    caption: el.getAttribute('data-gallery-caption') || '',
                };
            });

        triggers.forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                var i = parseInt(el.getAttribute('data-gallery-index'), 10) || 0;
                openLightbox(images, i);
            });
        });
    }

    function initVideoTriggers() {
        qsa('[data-video-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-video-open');
                var title = btn.getAttribute('data-video-title') || 'Place video';
                var mode = btn.getAttribute('data-video-mode') || 'embed';
                if (url) openVideoModal(url, title, mode);
            });
        });
    }

    function initSmoothAnchors() {
        qsa('a[href^="#"]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                var id = a.getAttribute('href').slice(1);
                var target = document.getElementById(id);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initGallery();
            initVideoTriggers();
            initSmoothAnchors();
        });
    } else {
        initGallery();
        initVideoTriggers();
        initSmoothAnchors();
    }
})();
