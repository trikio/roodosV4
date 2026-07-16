<script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){ dataLayer.push(arguments); }
    gtag('js', new Date());
    gtag('config', 'G-802057L54G');
</script>
<script>
    (function () {
        var loaded = false;

        function loadGtag() {
            if (loaded) return;
            loaded = true;
            var script = document.createElement('script');
            script.async = true;
            script.src = 'https://www.googletagmanager.com/gtag/js?id=G-802057L54G';
            document.head.appendChild(script);
        }

        ['scroll', 'mousemove', 'touchstart', 'keydown', 'click'].forEach(function (evt) {
            window.addEventListener(evt, loadGtag, { once: true, passive: true });
        });

        window.addEventListener('load', function () {
            setTimeout(loadGtag, 3000);
        });
    })();
</script>
