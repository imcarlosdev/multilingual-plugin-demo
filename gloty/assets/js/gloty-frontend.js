document.addEventListener('DOMContentLoaded', function() {
    const switchers = document.querySelectorAll('.gloty-language-switcher-container');

    switchers.forEach(function(container) {
        const trigger = container.querySelector('.gloty-switcher-trigger');
        const dropdown = container.querySelector('.gloty-switcher-dropdown');

        if (!trigger || !dropdown) return;

        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            container.classList.toggle('is-open');
        });

        // Close on click outside
        document.addEventListener('click', function(e) {
            if (!container.contains(e.target)) {
                container.classList.remove('is-open');
            }
        });

        // Prevention for keyboard navigation or multiple switchers
        dropdown.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    });
});
