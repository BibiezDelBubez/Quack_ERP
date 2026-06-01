/**
 * Login — interazioni UI condivise da tutte le skin.
 *
 *  · Ripple sul bottone di submit
 *
 * (L'animazione di onde al focus degli input è stata rimossa.)
 */
(function () {
    'use strict';

    var btn = document.querySelector('.qk-submit');
    if (btn) {
        btn.addEventListener('click', function (e) {
            var rect = this.getBoundingClientRect();
            var s    = Math.max(rect.width, rect.height);
            var el   = document.createElement('span');
            el.className   = 'qk-btn-ripple';
            el.style.cssText =
                'width:'  + s + 'px;' +
                'height:' + s + 'px;' +
                'left:'   + (e.clientX - rect.left - s / 2) + 'px;' +
                'top:'    + (e.clientY - rect.top  - s / 2) + 'px;';
            this.appendChild(el);
            el.addEventListener('animationend', function () { el.remove(); });
        });
    }

}());
