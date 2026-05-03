document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const nav = document.querySelector('nav');
    
    if (menuToggle && nav) {
        menuToggle.addEventListener('click', function() {
            menuToggle.classList.toggle('active');
            nav.classList.toggle('open');
        });
        
        // Close menu when clicking on a nav link
        const navLinks = nav.querySelectorAll('a');
        navLinks.forEach(function(link) {
            link.addEventListener('click', function() {
                menuToggle.classList.remove('active');
                nav.classList.remove('open');
            });
        });
        
        // Close menu when clicking outside
        document.addEventListener('click', function(event) {
            if (!nav.contains(event.target) && !menuToggle.contains(event.target)) {
                menuToggle.classList.remove('active');
                nav.classList.remove('open');
            }
        });
    }
});
