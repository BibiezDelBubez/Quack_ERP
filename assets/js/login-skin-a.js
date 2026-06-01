/**
 * Login Skin A — Cerchi concentrici con Perlin noise.
 *
 * Canvas creato sulla base di clientWidth/clientHeight (area interna, senza
 * border) per evitare lo sfasamento tra risoluzione canvas e dimensione CSS.
 * z-index:1 (sotto il form); overflow:hidden del pool gestisce il clipping.
 */
(function () {
    'use strict';

    new p5(function (p) {

        var rings     = [];
        var recentPos = [];
        var pool      = document.getElementById('qk-pool');

        p.setup = function () {
            p.pixelDensity(1);
            p.frameRate(30);
            /* clientWidth/clientHeight escludono il bordo → nessuno sfasamento */
            var cnv = p.createCanvas(pool.clientWidth, pool.clientHeight);
            cnv.parent(pool);
            cnv.elt.style.cssText =
                'position:absolute;top:0;left:0;width:100%;height:100%;' +
                'pointer-events:none;z-index:1;';
            p.noFill();

            startStream(0);
            startStream(900);
            startStream(1800);
        };

        /* ── posizione casuale con margine sicuro ───────────────────── */
        function randomPos() {
            /*
             * margin = 28% del lato minore.
             * Garantisce che il centro stia nell'area centrale della vasca
             * e che nessuna parte dell'anello (maxR = 20%) raggiunga il bordo.
             * 28% > 20%, quindi c'è sempre almeno 8% di spazio tra il bordo
             * dell'anello e il bordo interno della vasca.
             */
            var minSide = Math.min(p.width, p.height);
            var maxR    = minSide * 0.38;   /* grande abbastanza da raggiungere i bordi */
            var margin  = minSide * 0.04;   /* centri anche quasi a bordo */
            var minD    = minSide * 0.30;

            var now = Date.now();
            recentPos = recentPos.filter(function (r) { return now - r.t < 7000; });

            var x, y, ok, tries = 0;
            do {
                x  = margin + Math.random() * (p.width  - 2 * margin);
                y  = margin + Math.random() * (p.height - 2 * margin);
                ok = true;
                for (var k = 0; k < recentPos.length; k++) {
                    var dx = x - recentPos[k].x,
                        dy = y - recentPos[k].y;
                    if (dx * dx + dy * dy < minD * minD) { ok = false; break; }
                }
            } while (!ok && ++tries < 30);

            recentPos.push({ x: x, y: y, t: now });
            return { x: x, y: y, maxR: maxR };
        }

        /* ── emissione gruppo di 4 anelli sfasati ───────────────────── */
        function emitGroup() {
            var pos      = randomPos();
            /* SPESSORE RIPPLES — modifica baseSW per cerchi più spessi/sottili */
            var baseSW   = 0.58 + Math.random() * 0.28;
            var noiseOff = Math.random() * 500;

            for (var i = 0; i < 4; i++) {
                (function (idx) {
                    setTimeout(function () {
                        rings.push({
                            x:        pos.x,
                            y:        pos.y,
                            maxR:     pos.maxR,
                            sw:       baseSW + (Math.random() - 0.5) * 0.10,
                            noiseOff: noiseOff + idx * 55,
                            noiseAmt: 4.5 + Math.random() * 5.5,
                            born:     p.millis(),
                            dur:      3400 + Math.random() * 400
                        });
                    }, idx * 500 + Math.random() * 50);
                }(i));
            }
        }

        function startStream(delay) {
            setTimeout(function loop() {
                emitGroup();
                setTimeout(loop, 3200 + Math.random() * 800);
            }, delay);
        }

        /* ── disegno frame ──────────────────────────────────────────── */
        p.draw = function () {
            p.clear();

            var now  = p.millis();
            var NPTS = 64;

            rings = rings.filter(function (ring) {
                var age      = now - ring.born;
                var progress = age / ring.dur;
                if (progress >= 1) return false;

                var r     = 4 + (ring.maxR - 4) * progress;
                var alpha = progress < 0.10
                    ? 210 * (progress / 0.10)
                    : 210 * (1 - progress);

                p.stroke(255, 255, 255, alpha);
                p.strokeWeight(ring.sw);

                var tNoise = age * 0.00025;
                var pts    = [];

                for (var j = 0; j < NPTS; j++) {
                    var angle = (j / NPTS) * p.TWO_PI;
                    var ca    = Math.cos(angle);
                    var sa    = Math.sin(angle);
                    var nx    = p.noise(ring.noiseOff + ca * 0.55,
                                       ring.noiseOff + sa * 0.55,
                                       tNoise);
                    var rr    = r + (nx - 0.5) * 2 * ring.noiseAmt;
                    pts.push({ x: ring.x + rr * ca, y: ring.y + rr * sa });
                }

                p.beginShape();
                p.curveVertex(pts[NPTS - 2].x, pts[NPTS - 2].y);
                p.curveVertex(pts[NPTS - 1].x, pts[NPTS - 1].y);
                for (var j = 0; j < NPTS; j++) {
                    p.curveVertex(pts[j].x, pts[j].y);
                }
                p.curveVertex(pts[0].x, pts[0].y);
                p.curveVertex(pts[1].x, pts[1].y);
                p.endShape();

                return true;
            });
        };

    }); /* end p5 sketch A */

}());
