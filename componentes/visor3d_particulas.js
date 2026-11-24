/*
 * TechPCs - Visor 3D de imágenes v2 (three.js)  ·  "Ensamblado de partículas"
 * Inspirado en los ejemplos de three.js: webgl_points_sprites / webgl_buffergeometry_points
 * (partículas) y en las tarjetas con shader de webgl_shader.
 *
 * Qué hace:
 *   1. La imagen del producto se descompone en miles de partículas (una por "píxel")
 *      que están dispersas por el espacio y se ensamblan en remolino hasta formar la imagen.
 *   2. Las partículas se funden con una tarjeta 3D nítida que flota, se inclina hacia el
 *      cursor / dedo, tiene franja de brillo, resplandor azul pulsante y polvo de estrellas.
 *   3. Al tocar o hacer click sobre la imagen, se vuelve a deshacer y ensamblar.
 *
 * Uso (igual que el visor anterior):
 *        Visor3D.montar(contenedor, 'obtener_imagen.php?id=3');
 *        Visor3D.destruir();
 *
 * Si WebGL no está disponible, three.js no carga o el usuario prefiere menos
 * movimiento, no hace nada y se queda visible la <img> normal del contenedor.
 */
const Visor3D = (function () {
    const THREE_URL = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
    const FOV = 35;
    const FILL = 0.8;        // cuánto del recuadro ocupa la imagen (deja espacio al resplandor)
    const COLS = 110;        // partículas a lo ancho
    const T_ENSAMBLE = 1.8;  // segundos que tardan en ensamblarse
    const FONDO = 'radial-gradient(circle at 50% 42%, #1e3a8a 0%, #0f1b3d 55%, #070d1c 100%)';

    let cargaPromesa = null;
    let estado = null;
    let token = 0;

    /* ---------- Shaders de las partículas de la imagen ---------- */
    const VERT_P = `
        attribute vec3 aStart;
        attribute vec3 aColor;
        attribute float aRand;
        uniform float uAssemble;
        uniform float uTime;
        uniform float uWorld;
        uniform float uScale;
        uniform float uAlpha;
        varying vec3 vColor;
        varying float vA;
        void main() {
            float delay = aRand * 0.4;
            float k = clamp((uAssemble - delay) / 0.6, 0.0, 1.0);
            float e = 1.0 - pow(1.0 - k, 3.0);
            vec3 p = mix(aStart, position, e);
            float sw = 1.0 - e;
            p.x += sin(uTime * 2.2 + aRand * 40.0) * 0.07 * sw;
            p.y += cos(uTime * 1.9 + aRand * 30.0) * 0.07 * sw;
            vec4 mv = modelViewMatrix * vec4(p, 1.0);
            gl_Position = projectionMatrix * mv;
            gl_PointSize = max(uWorld * mix(0.5, 1.0, e) * uScale / (-mv.z), 1.0);
            vColor = aColor;
            vA = uAlpha * mix(0.6, 1.0, e);
        }
    `;
    const FRAG_P = `
        varying vec3 vColor;
        varying float vA;
        void main() {
            gl_FragColor = vec4(vColor, vA);
        }
    `;

    /* ---------- Shaders de la tarjeta (imagen nítida) ---------- */
    const VERT_C = `
        varying vec2 vUv;
        void main() {
            vUv = uv;
            gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
        }
    `;
    const FRAG_C = `
        uniform sampler2D map;
        uniform float uTime;
        uniform vec2 uMouse;
        uniform float uHover;
        uniform float uCard;
        varying vec2 vUv;
        void main() {
            vec4 c = texture2D(map, vUv);
            vec3 col = mix(vec3(1.0), c.rgb, c.a);

            // Franja de brillo que recorre la imagen
            float t = fract(uTime * 0.18);
            float banda = vUv.x * 0.8 + vUv.y * 0.6;
            float d = abs(banda - (t * 2.0 - 0.3));
            float brillo = (1.0 - smoothstep(0.0, 0.12, d)) * 0.22;

            // Resplandor que sigue al cursor
            float glow = (1.0 - smoothstep(0.0, 0.55, distance(vUv, uMouse))) * 0.16 * uHover;

            col += vec3(brillo + glow);

            // Borde suave para dar sensación de tarjeta
            vec2 e = min(vUv, 1.0 - vUv);
            float borde = smoothstep(0.0, 0.012, min(e.x, e.y));
            col *= mix(0.85, 1.0, borde);

            gl_FragColor = vec4(col, uCard);
        }
    `;

    /* ---------- Shader del halo azul detrás de la tarjeta ---------- */
    const FRAG_G = `
        uniform float uTime;
        uniform float uA;
        varying vec2 vUv;
        void main() {
            vec2 d = abs(vUv - 0.5) * 2.0;
            float r = max(d.x, d.y);
            float a = 1.0 - smoothstep(0.78, 1.0, r);
            a *= a;
            a *= (0.5 + 0.12 * sin(uTime * 1.6)) * uA;
            gl_FragColor = vec4(0.25, 0.55, 1.0, a);
        }
    `;

    function sstep(a, b, x) {
        const t = Math.min(Math.max((x - a) / (b - a), 0), 1);
        return t * t * (3 - 2 * t);
    }

    // Carga three.js solo la primera vez que se necesita
    function cargarThree() {
        if (window.THREE) return Promise.resolve();
        if (!cargaPromesa) {
            cargaPromesa = new Promise(function (ok, fallo) {
                const s = document.createElement('script');
                s.src = THREE_URL;
                s.onload = ok;
                s.onerror = function () { cargaPromesa = null; fallo(new Error('three.js no cargó')); };
                document.head.appendChild(s);
            });
        }
        return cargaPromesa;
    }

    function cargarTextura(src) {
        return new Promise(function (ok, fallo) {
            new THREE.TextureLoader().load(src, ok, undefined, fallo);
        });
    }

    // Lee los colores de la imagen en una malla de cols x rows
    function muestrear(imagen, cols, rows) {
        try {
            const c = document.createElement('canvas');
            c.width = cols;
            c.height = rows;
            const g = c.getContext('2d');
            g.drawImage(imagen, 0, 0, cols, rows);
            return g.getImageData(0, 0, cols, rows).data;
        } catch (err) {
            return null; // si el navegador bloquea la lectura, se omiten las partículas
        }
    }

    function destruir() {
        token++; // cancela cualquier carga pendiente
        if (!estado) return;
        const e = estado;
        estado = null;
        cancelAnimationFrame(e.raf);
        e.ro.disconnect();
        e.contenedor.removeEventListener('pointermove', e.onMove);
        e.contenedor.removeEventListener('pointerleave', e.onLeave);
        e.contenedor.removeEventListener('pointerdown', e.onDown);
        e.liberar.forEach(function (o) { o.dispose(); });
        e.renderer.dispose();
        if (e.renderer.forceContextLoss) e.renderer.forceContextLoss();
        if (e.canvas.parentNode) e.canvas.parentNode.removeChild(e.canvas);
        e.contenedor.style.background = e.bgPrevio;
        if (e.img) e.img.style.visibility = '';
    }

    async function montar(contenedor, src) {
        destruir();
        const mi = ++token;
        if (!contenedor) return false;
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false;

        try { await cargarThree(); } catch (err) { return false; }
        if (mi !== token) return false;

        let tex;
        try { tex = await cargarTextura(src); } catch (err) { return false; }
        if (mi !== token) { tex.dispose(); return false; }

        let renderer;
        try {
            renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
        } catch (err) {
            tex.dispose();
            return false;
        }

        tex.minFilter = THREE.LinearFilter;
        tex.generateMipmaps = false;

        const canvas = renderer.domElement;
        canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;display:block;touch-action:pan-y;';
        if (getComputedStyle(contenedor).position === 'static') contenedor.style.position = 'relative';
        const bgPrevio = contenedor.style.background;
        contenedor.style.background = FONDO;
        contenedor.appendChild(canvas);

        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setClearColor(0x000000, 0);

        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(FOV, 1, 0.1, 100);
        camera.position.z = 1 / Math.tan(THREE.MathUtils.degToRad(FOV / 2)); // alto visible = 2

        const liberar = [tex];
        const imgAspecto = (tex.image && tex.image.height) ? tex.image.width / tex.image.height : 1;

        /* --- Estrellas de fondo (como webgl_points_sprites) --- */
        const NE = 350;
        const posE = new Float32Array(NE * 3);
        for (let i = 0; i < NE; i++) {
            posE[i * 3] = (Math.random() - 0.5) * 6;
            posE[i * 3 + 1] = (Math.random() - 0.5) * 4;
            posE[i * 3 + 2] = -2 + Math.random() * 3.5;
        }
        const geoE = new THREE.BufferGeometry();
        geoE.setAttribute('position', new THREE.BufferAttribute(posE, 3));
        const matE = new THREE.PointsMaterial({
            size: 0.03, color: 0x9cc3ff, transparent: true, opacity: 0.7,
            depthWrite: false, depthTest: false, blending: THREE.AdditiveBlending
        });
        const estrellas = new THREE.Points(geoE, matE);
        estrellas.renderOrder = 0;
        estrellas.frustumCulled = false;
        scene.add(estrellas);
        liberar.push(geoE, matE);

        /* --- Grupo principal: halo + tarjeta + partículas --- */
        const grupo = new THREE.Group();
        scene.add(grupo);

        const geoPlano = new THREE.PlaneGeometry(1, 1);
        liberar.push(geoPlano);

        const matG = new THREE.ShaderMaterial({
            uniforms: { uTime: { value: 0 }, uA: { value: 0 } },
            vertexShader: VERT_C, fragmentShader: FRAG_G,
            transparent: true, depthWrite: false, depthTest: false
        });
        const halo = new THREE.Mesh(geoPlano, matG);
        halo.scale.set(1.28, 1.28, 1);
        halo.renderOrder = 1;
        grupo.add(halo);
        liberar.push(matG);

        const matC = new THREE.ShaderMaterial({
            uniforms: {
                map: { value: tex },
                uTime: { value: 0 },
                uMouse: { value: new THREE.Vector2(0.5, 0.5) },
                uHover: { value: 0 },
                uCard: { value: 0 }
            },
            vertexShader: VERT_C, fragmentShader: FRAG_C,
            transparent: true, depthWrite: false, depthTest: false
        });
        const tarjeta = new THREE.Mesh(geoPlano, matC);
        tarjeta.renderOrder = 2;
        grupo.add(tarjeta);
        liberar.push(matC);

        // Partículas de la imagen
        const rows = Math.max(16, Math.min(220, Math.round(COLS / imgAspecto)));
        const datos = muestrear(tex.image, COLS, rows);
        let matP = null;
        let puntos = null;
        if (datos) {
            const n = COLS * rows;
            const pos = new Float32Array(n * 3);
            const ini = new Float32Array(n * 3);
            const col = new Float32Array(n * 3);
            const rnd = new Float32Array(n);
            for (let j = 0; j < rows; j++) {
                for (let i = 0; i < COLS; i++) {
                    const k = j * COLS + i;
                    pos[k * 3] = (i + 0.5) / COLS - 0.5;
                    pos[k * 3 + 1] = 0.5 - (j + 0.5) / rows;
                    pos[k * 3 + 2] = 0;

                    const th = Math.random() * Math.PI * 2;
                    const rad = 0.6 + Math.sqrt(Math.random()) * 1.1;
                    ini[k * 3] = Math.cos(th) * rad * 1.1;
                    ini[k * 3 + 1] = Math.sin(th) * rad * 0.9;
                    ini[k * 3 + 2] = (Math.random() - 0.5) * 2.4;

                    const a = datos[k * 4 + 3] / 255;
                    col[k * 3] = (datos[k * 4] / 255) * a + (1 - a);
                    col[k * 3 + 1] = (datos[k * 4 + 1] / 255) * a + (1 - a);
                    col[k * 3 + 2] = (datos[k * 4 + 2] / 255) * a + (1 - a);
                    rnd[k] = Math.random();
                }
            }
            const geoP = new THREE.BufferGeometry();
            geoP.setAttribute('position', new THREE.BufferAttribute(pos, 3));
            geoP.setAttribute('aStart', new THREE.BufferAttribute(ini, 3));
            geoP.setAttribute('aColor', new THREE.BufferAttribute(col, 3));
            geoP.setAttribute('aRand', new THREE.BufferAttribute(rnd, 1));
            matP = new THREE.ShaderMaterial({
                uniforms: {
                    uAssemble: { value: 0 }, uTime: { value: 0 },
                    uWorld: { value: 0.02 }, uScale: { value: 1000 }, uAlpha: { value: 1 }
                },
                vertexShader: VERT_P, fragmentShader: FRAG_P,
                transparent: true, depthWrite: false, depthTest: false
            });
            puntos = new THREE.Points(geoP, matP);
            puntos.frustumCulled = false;
            puntos.renderOrder = 3;
            grupo.add(puntos);
            liberar.push(geoP, matP);
        }

        const base = { w: 1, h: 1 };
        function ajustar() {
            const w = contenedor.clientWidth, h = contenedor.clientHeight;
            if (!w || !h) return;
            renderer.setSize(w, h, false);
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
            // Ajusta la imagen dentro del recuadro sin deformarla
            let pw = 2 * camera.aspect * FILL;
            let ph = pw / imgAspecto;
            if (ph > 2 * FILL) {
                ph = 2 * FILL;
                pw = ph * imgAspecto;
            }
            base.w = pw;
            base.h = ph;
            if (matP) {
                matP.uniforms.uScale.value = (h * renderer.getPixelRatio()) / (2 * Math.tan(THREE.MathUtils.degToRad(FOV / 2)));
                matP.uniforms.uWorld.value = Math.max(pw / COLS, ph / rows) * 1.2;
            }
        }

        // Interacción
        let hover = 0, hoverSuave = 0, mx = 0, my = 0, rx = 0, ry = 0;
        const mouse01 = new THREE.Vector2(0.5, 0.5);
        const onMove = function (ev) {
            const r = contenedor.getBoundingClientRect();
            if (!r.width || !r.height) return;
            const nx = (ev.clientX - r.left) / r.width;
            const ny = (ev.clientY - r.top) / r.height;
            mx = nx * 2 - 1;
            my = ny * 2 - 1;
            mouse01.set(nx, 1 - ny);
            hover = 1;
        };
        const onLeave = function () { hover = 0; };
        // Tocar / click: la imagen se deshace y se vuelve a ensamblar
        const onDown = function () { if (estado) estado.t0 = performance.now(); };
        contenedor.addEventListener('pointermove', onMove);
        contenedor.addEventListener('pointerleave', onLeave);
        contenedor.addEventListener('pointerdown', onDown);

        const ro = new ResizeObserver(ajustar);
        ro.observe(contenedor);
        ajustar();

        const img = contenedor.querySelector('img');
        const inicio = performance.now();
        const e = {
            contenedor: contenedor, canvas: canvas, renderer: renderer, ro: ro,
            onMove: onMove, onLeave: onLeave, onDown: onDown, img: img, raf: 0,
            liberar: liberar, bgPrevio: bgPrevio, t0: inicio
        };
        estado = e;

        function frame(ahora) {
            e.raf = requestAnimationFrame(frame);
            const tg = (ahora - inicio) / 1000;   // tiempo continuo
            const t = (ahora - e.t0) / 1000;      // tiempo desde el último ensamblado

            const entrada = 1 - Math.pow(1 - Math.min(t / 2.2, 1), 3);

            // Partículas -> tarjeta nítida
            let uCard;
            if (matP) {
                matP.uniforms.uAssemble.value = t / T_ENSAMBLE;
                matP.uniforms.uTime.value = tg;
                matP.uniforms.uAlpha.value = 1 - sstep(1.95, 2.4, t);
                puntos.visible = t < 2.5;
                uCard = sstep(1.55, 2.1, t);
            } else {
                uCard = 1 - Math.pow(1 - Math.min(t / 0.9, 1), 3);
            }
            matC.uniforms.uCard.value = uCard;
            matC.uniforms.uTime.value = tg;
            matC.uniforms.uHover.value = hoverSuave;
            matC.uniforms.uMouse.value.lerp(mouse01, 0.15);
            matG.uniforms.uTime.value = tg;
            matG.uniforms.uA.value = uCard;

            hoverSuave += (hover - hoverSuave) * 0.1;
            const tx = hover ? my * 0.3 : Math.cos(tg * 0.5) * 0.07;
            const ty = hover ? mx * 0.35 : Math.sin(tg * 0.7) * 0.16;
            rx += (tx - rx) * 0.08;
            ry += (ty - ry) * 0.08;

            grupo.rotation.x = rx;
            grupo.rotation.y = ry + (1 - entrada) * 0.9;
            grupo.rotation.z = (1 - entrada) * -0.15;
            grupo.position.y = Math.sin(tg * 1.3) * 0.025; // flotación
            const s = 0.85 + 0.15 * entrada;
            grupo.scale.set(base.w * s, base.h * s, 1);

            // Polvo de estrellas con paralaje
            estrellas.rotation.y = tg * 0.04 - ry * 0.3;
            estrellas.rotation.x = Math.sin(tg * 0.1) * 0.05 + rx * 0.2;

            renderer.render(scene, camera);
        }
        e.raf = requestAnimationFrame(frame);

        // Se oculta la imagen normal solo cuando el 3D ya está listo
        if (img) img.style.visibility = 'hidden';
        return true;
    }

    return { montar: montar, destruir: destruir };
})();
