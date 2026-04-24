/**
 * TalentTrack — RatingInputComponent dot-track sync
 * #0019 Sprint 1 session 3
 *
 * Mirrors the numeric <input type="number"> value onto the dot track
 * that sits next to it. Pure display. Clicking a dot is a nice
 * stretch goal but isn't required for MVP.
 */
(function(){
    'use strict';

    function paint(rating) {
        var input = rating.querySelector('input[type="number"]');
        if (!input) return;
        var val = parseFloat(input.value);
        rating.querySelectorAll('.tt-rating-dot').forEach(function(dot) {
            var step = parseFloat(dot.getAttribute('data-step'));
            if (!isNaN(val) && !isNaN(step) && step <= val) dot.classList.add('is-on');
            else dot.classList.remove('is-on');
        });
    }

    function wire(rating) {
        var input = rating.querySelector('input[type="number"]');
        if (!input) return;
        paint(rating);
        input.addEventListener('input', function() { paint(rating); });
        input.addEventListener('change', function() { paint(rating); });
        // Clicking a dot sets the input to that step.
        rating.querySelectorAll('.tt-rating-dot').forEach(function(dot) {
            dot.addEventListener('click', function() {
                var step = dot.getAttribute('data-step');
                if (step == null) return;
                input.value = step;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            dot.style.cursor = 'pointer';
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.tt-dashboard .tt-rating').forEach(wire);
    });
})();
