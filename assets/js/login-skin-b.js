/**
 * Login Skin B — "Onde orizzontali"
 *
 * Canvas fisso full-screen sotto tutto il viewport.
 * La pool (.qk-pool) ha background:transparent in login.css (Skin B override)
 * così le onde del canvas si vedono anche dentro il form.
 * Il contenuto (.qk-content, z-index:2) galleggia sopra le onde.
 */
(function () {
    'use strict';

    new p5(function (p) {

        var t = 0;

        p.setup = function () {
            p.pixelDensity(1);
            p.frameRate(30);
            var cnv = p.createCanvas(window.innerWidth, window.innerHeight);
            document.body.appendChild(cnv.elt);
            cnv.elt.style.cssText =
                'position:fixed;inset:0;z-index:0;pointer-events:none;';
        };

        p.draw = function () {
            p.background(0, 15, 25);
            p.strokeWeight(1.5);
            p.noFill();

            for (var i = 0; i < 15; i++) {
                var alpha = p.map(i, 0, 15, 55, 130);
                p.stroke(240, 248, 255, alpha);
                p.beginShape();
                var yBase = (i * (p.height / 12) + t * 50) % (p.height + 100) - 50;
                for (var x = -50; x <= p.width + 50; x += 25) {
                    var n = p.noise(x * 0.005, i * 10, t);
                    var y = yBase + n * 120;
                    p.curveVertex(x, y);
                }
                p.endShape();
            }

            t += 0.004;
        };

        p.windowResized = function () {
            p.resizeCanvas(window.innerWidth, window.innerHeight);
        };

    }); /* end p5 sketch B */

}());
