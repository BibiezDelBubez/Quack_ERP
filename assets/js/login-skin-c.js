/**
 * Login Skin C — "Onde Fluide Orizzontali INVERTITE"
 */
(function () {
    'use strict';

    var REF_W = 1440;
    var REF_H = 900;

    // Palette invertita: partiamo dal colore scurissimo per finire con quello chiaro
    var WAVES = [
        { baseY: -20,  amp: 50, period: 5.0, sa: 40, sf: 0.15, col: [  1,   8,  12] },
        { baseY: 110,  amp: 55, period: 5.5, sa: 45, sf: 0.14, col: [  2,  16,  25] },
        { baseY: 240,  amp: 60, period: 6.0, sa: 50, sf: 0.13, col: [  5,  32,  49] },
        { baseY: 370,  amp: 65, period: 6.5, sa: 55, sf: 0.12, col: [  8,  55,  74] },
        { baseY: 500,  amp: 70, period: 7.0, sa: 60, sf: 0.11, col: [ 16,  82, 102] },
        { baseY: 630,  amp: 75, period: 7.5, sa: 65, sf: 0.10, col: [ 32, 113, 128] },
        { baseY: 760,  amp: 80, period: 8.0, sa: 70, sf: 0.09, col: [ 65, 148, 157] },
        { baseY: 890,  amp: 85, period: 8.5, sa: 75, sf: 0.08, col: [116, 183, 185] },
        { baseY: 1020, amp: 90, period: 9.0, sa: 80, sf: 0.07, col: [167, 214, 212] }
    ];

    var N_CP     = 10; 
    var CR_STEPS = 30; 

    new p5(function (p) {
        var pool;

        p.setup = function () {
            pool = document.getElementById('qk-pool');
            p.pixelDensity(1);
            p.frameRate(30);
            var cnv = p.createCanvas(pool.clientWidth, pool.clientHeight);
            cnv.elt.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;z-index:1;pointer-events:none;';
            pool.appendChild(cnv.elt);
            p.noStroke();
        };

        p.draw = function () {
            p.clear();

            var t = p.millis() * 0.001;
            var sx = p.width / REF_W;
            var sy = p.height / REF_H;

            // Disegniamo in ordine: la prima onda dell'array (scura) copre tutto,
            // la successiva sovrascrive la parte inferiore, e così via.
            for (var wi = 0; wi < WAVES.length; wi++) {
                var w = WAVES[wi];
                p.fill(w.col[0], w.col[1], w.col[2]);

                var cps = [];
                for (var i = 0; i < N_CP; i++) {
                    var xRef = -100 + (REF_W + 200) * i / (N_CP - 1);
                    
                    // Lieve sfasamento per dare l'effetto di movimento laterale
                    var horizontalShift = (i * 0.4); 
                    var waveSpeed = t * 1.1;
                    
                    var mainOsc = Math.sin(waveSpeed + horizontalShift + (wi * 0.5)) * w.amp;
                    var detail = Math.sin(t * w.sf + i * 0.8) * w.sa;

                    cps.push({ 
                        x: xRef * sx, 
                        y: (w.baseY + mainOsc + detail) * sy 
                    });
                }

                var pts = catmullRomPoints(cps);

                p.beginShape();
                // Importante: riempiamo sempre verso il basso (p.height)
                p.vertex(pts[0].x, p.height + 100);
                for (var j = 0; j < pts.length; j++) {
                    p.vertex(pts[j].x, pts[j].y);
                }
                p.vertex(pts[pts.length - 1].x, p.height + 100);
                p.endShape(p.CLOSE);
            }
        };

        function catmullRomPoints(pts) {
            var result = [];
            for (var i = 0; i < pts.length - 1; i++) {
                var p0 = pts[Math.max(i - 1, 0)];
                var p1 = pts[i];
                var p2 = pts[i + 1];
                var p3 = pts[Math.min(i + 2, pts.length - 1)];
                for (var s = 0; s < CR_STEPS; s++) {
                    var u = s / CR_STEPS;
                    var u2 = u * u;
                    var u3 = u2 * u;
                    result.push({
                        x: 0.5 * (2*p1.x + (-p0.x+p2.x)*u + (2*p0.x-5*p1.x+4*p2.x-p3.x)*u2 + (-p0.x+3*p1.x-3*p2.x+p3.x)*u3),
                        y: 0.5 * (2*p1.y + (-p0.y+p2.y)*u + (2*p0.y-5*p1.y+4*p2.y-p3.y)*u2 + (-p0.y+3*p1.y-3*p2.y+p3.y)*u3)
                    });
                }
            }
            result.push(pts[pts.length - 1]);
            return result;
        }

        p.windowResized = function () {
            if (pool) p.resizeCanvas(pool.clientWidth, pool.clientHeight);
        };
    });
}());