/**
 * Login Skin E — "Caustiche"
 * Shader GLSL di luce rifratta sull'acqua (caustics),
 * montato dentro #qk-pool come canvas WebGL.
 *
 * Fix applicati:
 *  1. Hash sin()-free  — risultato identico su Intel/AMD/NVIDIA.
 *  2. Singolo triangolo fullscreen (tecnica standard fullscreen-triangle):
 *     elimina il seam diagonale che p.rect() produce dividendo lo schermo
 *     in 2 triangoli con interpolazione discontinua su GPU Intel.
 *  3. Shader compilato via WebGL raw (gl.createProgram) invece di
 *     p5.createShader: evita il deferred useProgram di p5 che causava
 *     la schermata piatta quando si bypassava p.rect().
 */
(function () {
    'use strict';

    var VERT = [
        'precision highp float;',
        'attribute vec2 aPos;',
        'varying vec2 vTexCoord;',
        'void main(){',
        '  vTexCoord   = aPos * 0.5 + 0.5;',
        '  gl_Position = vec4(aPos, 0.0, 1.0);',
        '}'
    ].join('\n');

    var FRAG = [
        'precision highp float;',
        'varying vec2 vTexCoord;',
        'uniform float iTime;',
        'uniform vec2  iResolution;',
        '',
        '/* Hash sin()-free: nessuna dipendenza dalla precisione GPU su sin() */',
        'float hash(vec2 p){',
        '  vec3 q = fract(vec3(p.xyx) * vec3(0.1031, 0.1030, 0.0973));',
        '  q += dot(q, q.yzx + 33.33);',
        '  return fract((q.x + q.y) * q.z);',
        '}',
        'float noise(vec2 p){',
        '  vec2 i = floor(p); vec2 f = fract(p);',
        '  float a = hash(i);',
        '  float b = hash(i + vec2(1.0, 0.0));',
        '  float c = hash(i + vec2(0.0, 1.0));',
        '  float d = hash(i + vec2(1.0, 1.0));',
        '  vec2 u = f * f * (3.0 - 2.0 * f);',
        '  return mix(a, b, u.x) + (c - a) * u.y * (1.0 - u.x) + (d - b) * u.x * u.y;',
        '}',
        '/* phase: differenzia i due layer senza traslare p nel tempo */',
        'float caustics(vec2 p, float threshold, float thickness, float phase){',
        '  float t = iTime * 0.13 + phase;',
        '  vec2 n = vec2(',
        '    noise(p * 0.78 + t),',
        '    noise(p * 0.78 - t)',
        '  );',
        '  vec2 q = p + n * 1.5;',
        '  mat2 rot = mat2(0.92, -0.38, 0.38, 0.92);',
        '  q = rot * q;',
        '  float w =',
        '    sin((q.x + q.y) * 1.95 + t) *',
        '    cos((q.x - q.y) * 1.65 - t);',
        '  w += sin((q.x + q.y) * 1.35 + noise(p + t));',
        '  float shape = 1.0 - abs(w);',
        '  return smoothstep(threshold - thickness, threshold + thickness, shape);',
        '}',
        'void main(){',
        '  vec2 uv = vTexCoord - 0.5;',
        '  uv.x *= iResolution.x / iResolution.y;',
        '  vec2 p = uv * 5.5;',
        '',
        '  float c1 = caustics(p,              0.73, 0.07,  0.0);',
        '  float c2 = caustics(p * 1.03 + 2.5, 0.76, 0.11,  1.7);',
        '  float c  = clamp(c1 + c2 * 0.55, 0.0, 1.0);',
        '',
        '  /* Palette: fondale più chiaro per far risaltare le linee */',
        '  vec3 bg    = vec3(0.008, 0.047, 0.071);',
        '  vec3 deep  = vec3(0.047, 0.259, 0.337);',
        '  vec3 ink   = vec3(0.290, 0.612, 0.647);',
        '  vec3 light = vec3(0.720, 0.890, 0.882);',
        '',
        '  vec3 col = bg;',
        '  /* uv.y ∈ [-0.5,+0.5] → shift a [0,1] per evitare extrapolation negativa */',
        '  col = mix(col, deep, uv.y + 0.5);',
        '  col = mix(col, ink,  c);',
        '  col += light * pow(c, 2.6) * 0.55;',
        '',
        '  gl_FragColor = vec4(col, 1.0);',
        '}'
    ].join('\n');

    function compileShader(gl, type, src) {
        var s = gl.createShader(type);
        gl.shaderSource(s, src);
        gl.compileShader(s);
        return s;
    }

    new p5(function (p) {
        var pool, gl, prog, triVbo, aPosLoc, iTimeLoc, iResLoc;

        p.setup = function () {
            pool = document.getElementById('qk-pool');
            p.pixelDensity(1);
            p.frameRate(30);
            var cnv = p.createCanvas(pool.clientWidth, pool.clientHeight, p.WEBGL);
            cnv.elt.style.cssText =
                'position:absolute;top:0;left:0;width:100%;height:100%;' +
                'z-index:1;pointer-events:none;';
            pool.appendChild(cnv.elt);

            gl = p.drawingContext;

            prog = gl.createProgram();
            gl.attachShader(prog, compileShader(gl, gl.VERTEX_SHADER,   VERT));
            gl.attachShader(prog, compileShader(gl, gl.FRAGMENT_SHADER, FRAG));
            gl.linkProgram(prog);

            aPosLoc  = gl.getAttribLocation(prog,  'aPos');
            iTimeLoc = gl.getUniformLocation(prog, 'iTime');
            iResLoc  = gl.getUniformLocation(prog, 'iResolution');

            /* VBO: singolo triangolo large, nessuna giuntura diagonale */
            triVbo = gl.createBuffer();
            gl.bindBuffer(gl.ARRAY_BUFFER, triVbo);
            gl.bufferData(gl.ARRAY_BUFFER,
                new Float32Array([-1, -1,  3, -1,  -1, 3]),
                gl.STATIC_DRAW);
        };

        p.draw = function () {
            gl.useProgram(prog);
            gl.uniform1f(iTimeLoc, p.millis() / 1000);
            gl.uniform2f(iResLoc,  p.width, p.height);
            gl.bindBuffer(gl.ARRAY_BUFFER, triVbo);
            gl.enableVertexAttribArray(aPosLoc);
            gl.vertexAttribPointer(aPosLoc, 2, gl.FLOAT, false, 0, 0);
            gl.drawArrays(gl.TRIANGLES, 0, 3);
        };

        p.windowResized = function () {
            if (pool) p.resizeCanvas(pool.clientWidth, pool.clientHeight);
        };
    });

}());
