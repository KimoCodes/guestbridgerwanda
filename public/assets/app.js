/**
 * GuestBridge app shell behaviors
 */
(function () {
    function initSidebar() {
        var toggle = document.getElementById('sidebarToggle');
        var sidebar = document.getElementById('appSidebar');
        var overlay = document.getElementById('sidebarOverlay');
        if (!sidebar) return;

        function open() {
            sidebar.classList.add('open');
            if (overlay) overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function close() {
            sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
        if (toggle) toggle.addEventListener('click', function () {
            sidebar.classList.contains('open') ? close() : open();
        });
        if (overlay) overlay.addEventListener('click', close);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
    }

    function initLucide() {
        if (typeof lucide !== 'undefined' && lucide.createIcons) {
            lucide.createIcons();
        }
    }

    function initSearchHint() {
        var input = document.querySelector('[data-gb-search]');
        if (!input) return;
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var q = input.value.trim();
                if (q) {
                    window.location.href = 'history.php?q=' + encodeURIComponent(q);
                }
            }
        });
    }

    function initCardHover() {
        document.querySelectorAll('.stat-card, .gb-stat-card').forEach(function (el) {
            el.addEventListener('mouseenter', function () {
                el.style.willChange = 'transform';
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initSidebar();
            initLucide();
            initSearchHint();
            initCardHover();
        });
    } else {
        initSidebar();
        initLucide();
        initSearchHint();
        initCardHover();
    }
})();
