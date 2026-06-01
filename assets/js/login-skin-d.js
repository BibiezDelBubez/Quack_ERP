/**
 * Login Skin D — "Mattonelle fondale distorte"
 * Griglia di piastrelle ceramica (blu piscina) deformate da Perlin noise,
 * con fughe luminose e riflesso shimmer dinamico.
 */
(function () {
    'use strict';

    var TILE_SIZE          = 38;
    var LINE_WEIGHT        = 2.2;
    var DISTORTION         = 22;
    var GRID_SUBDIV        = 6;   // punti intermedi per le fughe curve

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

            var t   = p.millis() * 0.0006;
            var cols = Math.ceil(p.width  / TILE_SIZE) + 2;
            var rows = Math.ceil(p.height / TILE_SIZE) + 2;

            /* ── 1. MATTONELLE ─────────────────────────────────────────── */
            /* i/j partono da -1 → una fila/colonna extra copre i bordi
               anche quando la distorsione sposta le mattonelle verso l'interno */
            p.noStroke();
            for (var i = -1; i < cols; i++) {
                for (var j = -1; j < rows; j++) {

                    /* Colore base fisso (seed da posizione) */
                    var seed  = p.noise(i * 12.5, j * 12.5);
                    var base;
                    if (seed < 0.3) {
                        base = [0, 155, 220];   // azzurro chiaro
                    } else if (seed < 0.6) {
                        base = [0, 118, 185];   // blu medio
                    } else {
                        base = [0,  88, 155];   // blu profondo
                    }

                    /* Shimmer dinamico: solo la luminosità oscilla */
                    var sh = p.noise(i * 0.12, j * 0.12, t) * 35;
                    p.fill(base[0] + sh, base[1] + sh, base[2] + sh, 215);

                    /* Quattro vertici della mattonella, distorta da noise */
                    p.beginShape();
                    dv(i * TILE_SIZE,       j * TILE_SIZE,       t);
                    dv((i+1) * TILE_SIZE,   j * TILE_SIZE,       t);
                    dv((i+1) * TILE_SIZE,   (j+1) * TILE_SIZE,   t);
                    dv(i * TILE_SIZE,       (j+1) * TILE_SIZE,   t);
                    p.endShape(p.CLOSE);
                }
            }

            /* ── 2. FUGHE ──────────────────────────────────────────────── */
            /* Bagliore esterno */
            p.stroke(180, 235, 255, 35);
            p.strokeWeight(LINE_WEIGHT * 2.2);
            p.noFill();
            drawGrid(t, cols, rows);

            /* Fuga netta */
            p.stroke(230, 248, 255, 150);
            p.strokeWeight(LINE_WEIGHT);
            drawGrid(t, cols, rows);
        };

        /* Disegna la griglia di fughe come polyline distorte */
        function drawGrid(t, cols, rows) {
            var step = TILE_SIZE / GRID_SUBDIV;

            /* Linee verticali */
            for (var i = 0; i <= cols; i++) {
                p.beginShape();
                for (var y = 0; y <= p.height + TILE_SIZE; y += step) {
                    var vx = dCoords(i * TILE_SIZE, y, t);
                    p.vertex(vx.x, vx.y);
                }
                p.endShape();
            }

            /* Linee orizzontali */
            for (var j = 0; j <= rows; j++) {
                p.beginShape();
                for (var x = 0; x <= p.width + TILE_SIZE; x += step) {
                    var hv = dCoords(x, j * TILE_SIZE, t);
                    p.vertex(hv.x, hv.y);
                }
                p.endShape();
            }
        }

        /* Calcola le coordinate distorte di un punto e le applica come vertex */
        function dv(x, y, t) {
            var c = dCoords(x, y, t);
            p.vertex(c.x, c.y);
        }

        function dCoords(x, y, t) {
            var nx   = x * 0.005;
            var ny   = y * 0.005;
            var offX = (p.noise(nx,      ny,      t) - 0.5) * DISTORTION;
            var offY = (p.noise(nx + 50, ny + 50, t) - 0.5) * DISTORTION;
            var wave = Math.sin(x * 0.012 + y * 0.012 + t * 1.2) * 4;
            return { x: x + offX + wave, y: y + offY + wave };
        }

        p.windowResized = function () {
            if (pool) p.resizeCanvas(pool.clientWidth, pool.clientHeight);
        };
    });
}());
