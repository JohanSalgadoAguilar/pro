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

/* ==========================================================================
 * PARTE 2: Fondo 3D de la pantalla principal (se inicia solo en productos.php)
 * ========================================================================== */

/*
 * TechPCs - Fondo 3D de la pantalla principal (productos.php)
 * Ejemplo de three.js  https://threejs.org/examples/#webgl_buffergeometry_drawrange  (fernandojsg)
 * con la misma lógica: partículas que rebotan dentro de una caja, líneas entre las cercanas
 * (setDrawRange), BoxHelper gris, cámara en perspectiva FOV 45 y partículas/líneas aditivas.
 *
 * Aquí la caja se adapta a toda la pantalla y se queda de fondo detrás del catálogo.
 * Las tarjetitas son las imágenes REALES de los productos de la tabla:
 *   - Flotan dentro de la red (las líneas se conectan a ellas).
 *   - Pasa el cursor sobre una: se detiene, crece y muestra nombre y precio.
 *   - Haz click en una: abre el mismo detalle con QR que al hacer click en la fila de la tabla.
 *
 * No hay que llamar nada: se inicia solo al cargar la página, leyendo las filas .fila-producto.
 * Si WebGL no está disponible, three.js no carga o el usuario prefiere menos movimiento,
 * no hace nada y la página se ve como siempre.
 *
 * Ajustes del panel original (lil-gui) en  FondoRed.efecto,  p. ej.  FondoRed.efecto.distanciaMinima = 200;
 */
const FondoRed = (function () {
    const THREE_URL = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
    const MAX_PARTICULAS = 1200;
    const MAX_PARES = 90000;
    const TARJETAS_POR_PRODUCTO = 4;
    const MAX_TARJETAS = 18;
    const FUERA = '.panel, .navbar, .modal-prod, .modal-confirm, .barra-acciones, dialog, .fondo-ui';

    const efecto = {
        mostrarPuntos: true,
        mostrarLineas: true,
        distanciaMinima: 160,
        limitarConexiones: false,
        maxConexiones: 20
    };

    let estado = null;

    /* ---------- Shader de las tarjetitas ---------- */
    const VERT_T = `
        varying vec2 vUv;
        void main() {
            vUv = uv;
            gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
        }
    `;
    const FRAG_T = `
        uniform sampler2D map;
        uniform float uAsp;
        uniform float uHover;
        uniform float uShade;
        uniform float uTime;
        uniform float uOff;
        varying vec2 vUv;
        void main() {
            // Rectángulo con esquinas redondeadas
            vec2 p = (vUv - 0.5) * vec2(uAsp, 1.0);
            vec2 mitad = vec2(uAsp, 1.0) * 0.5;
            float r = 0.09;
            vec2 q = abs(p) - (mitad - vec2(r));
            float d = length(max(q, vec2(0.0))) + min(max(q.x, q.y), 0.0) - r;
            if (d > 0.0) discard;

            vec4 c = texture2D(map, vUv);
            vec3 col = mix(vec3(1.0), c.rgb, c.a);

            // Franja de brillo
            float t = fract(uTime * 0.25 + uOff);
            float banda = vUv.x * 0.8 + vUv.y * 0.6;
            col += (1.0 - smoothstep(0.0, 0.1, abs(banda - (t * 2.0 - 0.3)))) * 0.18;

            // Más oscura cuanto más lejos
            col *= uShade;

            // Marco (blanco; azul al seleccionar)
            vec3 borde = mix(vec3(0.82, 0.88, 1.0), vec3(0.35, 0.65, 1.0), uHover);
            col = mix(col, borde, smoothstep(-0.032, -0.026, d));

            gl_FragColor = vec4(col, 1.0);
        }
    `;

    function cargarThree() {
        if (window.THREE) return Promise.resolve();
        return new Promise(function (ok, fallo) {
            const s = document.createElement('script');
            s.src = THREE_URL;
            s.onload = ok;
            s.onerror = function () { fallo(new Error('three.js no cargó')); };
            document.head.appendChild(s);
        });
    }

    function cargarTextura(url) {
        return new Promise(function (ok) {
            new THREE.TextureLoader().load(url, ok, undefined, function () { ok(null); });
        });
    }

    function destruir() {
        if (!estado) return;
        const e = estado;
        estado = null;
        cancelAnimationFrame(e.raf);
        window.removeEventListener('resize', e.onResize);
        window.removeEventListener('pointermove', e.onMove);
        window.removeEventListener('pointerdown', e.onDown);
        window.removeEventListener('click', e.onClick);
        document.documentElement.removeEventListener('pointerleave', e.onLeave);
        e.liberar.forEach(function (o) { o.dispose(); });
        e.renderer.dispose();
        if (e.renderer.forceContextLoss) e.renderer.forceContextLoss();
        if (e.canvas.parentNode) e.canvas.parentNode.removeChild(e.canvas);
        if (e.etiqueta.parentNode) e.etiqueta.parentNode.removeChild(e.etiqueta);
        if (e.ui.parentNode) e.ui.parentNode.removeChild(e.ui);
        e.paneles.forEach(function (p) { p.style.display = ''; });
        if (e.extraAdmin && e.extraAdmin.parentNode) e.extraAdmin.parentNode.removeChild(e.extraAdmin);
        document.body.style.cursor = '';
    }

    async function iniciar() {
        destruir();
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const productos = Array.prototype.slice.call(document.querySelectorAll('.fila-producto')).map(function (f) {
            return { id: f.dataset.id, nombre: f.dataset.nombre, precio: parseFloat(f.dataset.precio), fila: f };
        });
        if (!productos.length) return;

        try { await cargarThree(); } catch (err) { return; }

        // Imagen de cada producto (los que no carguen se omiten)
        const texturas = await Promise.all(productos.map(function (p) {
            return cargarTextura('obtener_imagen.php?id=' + encodeURIComponent(p.id));
        }));
        const items = [];
        productos.forEach(function (p, i) { if (texturas[i]) { p.tex = texturas[i]; items.push(p); } });
        if (!items.length) return;

        let renderer;
        try {
            renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
        } catch (err) {
            items.forEach(function (p) { p.tex.dispose(); });
            return;
        }

        // El canvas va fijo detrás de toda la página y trae su propio fondo oscuro:
        // no se cambia ningún estilo de la página (la tabla se ve igual que siempre).
        const canvas = renderer.domElement;
        canvas.className = 'fondo-red';
        canvas.style.cssText = 'position:fixed;left:0;top:0;width:100%;height:100%;z-index:-1;pointer-events:none;display:block;';
        document.body.insertBefore(canvas, document.body.firstChild);

        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setClearColor(0x05070d, 1);

        const etiqueta = document.createElement('div');
        etiqueta.style.cssText = 'position:fixed;z-index:6;pointer-events:none;display:none;background:rgba(15,23,42,.92);color:#fff;padding:8px 12px;border-radius:8px;font:600 13px -apple-system,"Segoe UI",Roboto,Arial,sans-serif;border:1px solid #2563eb;white-space:nowrap;box-shadow:0 6px 18px rgba(0,0,0,.4);';
        document.body.appendChild(etiqueta);

        // Se oculta la tabla: las tarjetas flotando son ahora el catálogo.
        // (Las filas siguen en la página, ocultas: de ahí salen los datos y el detalle con QR.)
        const paneles = Array.prototype.slice.call(document.querySelectorAll('.panel'));
        paneles.forEach(function (p) { p.style.display = 'none'; });

        const ui = document.createElement('div');
        ui.className = 'fondo-ui';
        ui.style.cssText = 'position:fixed;left:0;right:0;bottom:18px;z-index:6;display:flex;justify-content:center;align-items:center;gap:10px;flex-wrap:wrap;padding:0 12px;pointer-events:none;font-family:-apple-system,"Segoe UI",Roboto,Arial,sans-serif;';
        const aviso = document.createElement('div');
        aviso.textContent = 'Haz click en una tarjeta para ver su detalle y su QR';
        aviso.style.cssText = 'background:rgba(15,23,42,.85);color:#cbd5e1;font-size:13px;padding:9px 16px;border-radius:20px;border:1px solid rgba(148,163,184,.25);';
        // Buscador: oculta las tarjetas que no coinciden con el nombre
        let filtro = '';
        const buscador = document.createElement('input');
        buscador.type = 'search';
        buscador.placeholder = 'Buscar producto...';
        buscador.style.cssText = 'pointer-events:auto;background:rgba(15,23,42,.9);color:#fff;border:1px solid rgba(148,163,184,.35);border-radius:20px;padding:9px 16px;font-size:13px;font-family:inherit;outline:none;width:210px;';
        buscador.addEventListener('input', function () { filtro = buscador.value.trim().toLowerCase(); });
        ui.appendChild(aviso);
        ui.appendChild(buscador);

        // Acciones de administrador (antes estaban en la tabla): se detectan solas por los botones de la tabla
        const esAdmin = !!document.querySelector('.fila-producto .btn-editar');
        let extraAdmin = null;
        if (esAdmin) {
            const enlaceNuevo = document.querySelector('a[href*="crear_producto.php"]');
            if (enlaceNuevo) {
                const nuevo = document.createElement('a');
                nuevo.href = enlaceNuevo.getAttribute('href');
                nuevo.textContent = '+ Nuevo producto';
                nuevo.style.cssText = 'pointer-events:auto;background:#16a34a;color:#fff;text-decoration:none;font-weight:600;font-size:13px;padding:9px 16px;border-radius:20px;';
                ui.appendChild(nuevo);
            }
            // Dentro del detalle de la tarjeta: Actualizar y Eliminar el producto abierto
            const info = document.querySelector('#modalProducto .modal-prod-info');
            if (info) {
                extraAdmin = document.createElement('div');
                extraAdmin.style.cssText = 'display:flex;gap:8px;margin-top:10px;';
                const idActual = function () { const i = document.getElementById('mpId'); return i ? i.value : ''; };
                const bEd = document.createElement('button');
                bEd.type = 'button';
                bEd.textContent = 'Actualizar';
                bEd.style.cssText = 'flex:1;padding:11px;border:none;border-radius:10px;background:#f59e0b;color:#1e293b;font-size:15px;font-weight:700;cursor:pointer;';
                bEd.addEventListener('click', function () { window.location.href = 'editar_producto.php?id=' + encodeURIComponent(idActual()); });
                const bEl = document.createElement('button');
                bEl.type = 'button';
                bEl.textContent = 'Eliminar';
                bEl.style.cssText = 'flex:1;padding:11px;border:none;border-radius:10px;background:#dc2626;color:#fff;font-size:15px;font-weight:700;cursor:pointer;';
                bEl.addEventListener('click', function () { if (typeof mostrarModal === 'function') mostrarModal(parseInt(idActual(), 10)); });
                extraAdmin.appendChild(bEd);
                extraAdmin.appendChild(bEl);
                info.appendChild(extraAdmin);
            }
        }
        document.body.appendChild(ui);

        const liberar = [];
        items.forEach(function (p) { liberar.push(p.tex); });

        /* ====== Medidas: la caja se adapta a la pantalla ====== */
        const TAN = Math.tan(22.5 * Math.PI / 180); // FOV 45
        const dim = { W: 800, H: 800, D: 700, Hv: 1100, z: 1328, y: 0 };
        function calcular() {
            const asp = window.innerWidth / window.innerHeight;
            const nav = document.querySelector('.navbar');
            const navH = nav ? nav.offsetHeight : 0;       // la barra superior tapa esa franja
            dim.Hv = Math.max(1100, 900 / asp);          // alto visible en z = 0
            dim.z = (dim.Hv / 2) / TAN;                    // distancia de la cámara
            dim.H = dim.Hv * (1 - navH / window.innerHeight) * 0.85;
            dim.W = Math.max(800, dim.Hv * asp * 0.95);
            dim.D = 700;
            dim.y = -(navH / 2) * (dim.Hv / window.innerHeight); // centra la caja en la parte libre
        }
        calcular();

        const camera = new THREE.PerspectiveCamera(45, window.innerWidth / window.innerHeight, 1, 6000);
        camera.position.z = dim.z;

        const scene = new THREE.Scene();
        const group = new THREE.Group();
        scene.add(group);

        const helper = new THREE.BoxHelper(new THREE.Mesh(new THREE.BoxGeometry(1, 1, 1)));
        helper.material.color.setHex(0x474747);
        helper.material.blending = THREE.AdditiveBlending;
        helper.material.transparent = true;
        helper.scale.set(dim.W, dim.H, dim.D);
        group.add(helper);
        liberar.push(helper.geometry, helper.material);

        // Partículas proporcionales al volumen (el original: 500 en una caja de 800³), menos en pantallas chicas
        const volumen = dim.W * dim.H * dim.D;
        let particleCount = Math.round(500 * volumen / (800 * 800 * 800));
        particleCount = Math.max(160, items.length, Math.min(particleCount, window.innerWidth < 700 ? 400 : 900, MAX_PARTICULAS));

        const nTarjetas = Math.min(particleCount, Math.max(items.length, Math.min(MAX_TARJETAS, Math.max(items.length * TARJETAS_POR_PRODUCTO, 6))));

        const positions = new Float32Array(MAX_PARES * 2 * 3);
        const colors = new Float32Array(MAX_PARES * 2 * 3);

        const pMaterial = new THREE.PointsMaterial({
            color: 0xFFFFFF,
            size: 3,
            blending: THREE.AdditiveBlending,
            transparent: true,
            sizeAttenuation: false
        });

        const particles = new THREE.BufferGeometry();
        const particlePositions = new Float32Array(particleCount * 3);
        const particlesData = [];
        for (let i = 0; i < particleCount; i++) {
            particlePositions[i * 3] = Math.random() * dim.W - dim.W / 2;
            particlePositions[i * 3 + 1] = Math.random() * dim.H - dim.H / 2;
            particlePositions[i * 3 + 2] = Math.random() * dim.D - dim.D / 2;

            const v = new THREE.Vector3(-1 + Math.random() * 2, -1 + Math.random() * 2, -1 + Math.random() * 2);
            if (i < nTarjetas) v.multiplyScalar(0.55); // las tarjetitas van más despacio
            particlesData.push({ velocity: v, numConnections: 0 });
        }
        particles.setDrawRange(0, particleCount);
        particles.setAttribute('position', new THREE.BufferAttribute(particlePositions, 3).setUsage(THREE.DynamicDrawUsage));

        const pointCloud = new THREE.Points(particles, pMaterial);
        group.add(pointCloud);
        liberar.push(particles, pMaterial);

        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3).setUsage(THREE.DynamicDrawUsage));
        geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3).setUsage(THREE.DynamicDrawUsage));
        geometry.computeBoundingSphere();
        geometry.setDrawRange(0, 0);

        const material = new THREE.LineBasicMaterial({
            vertexColors: true,
            blending: THREE.AdditiveBlending,
            transparent: true
        });
        const linesMesh = new THREE.LineSegments(geometry, material);
        linesMesh.frustumCulled = false;
        group.add(linesMesh);
        liberar.push(geometry, material);

        /* ====== Tarjetitas: una imagen de producto por tarjeta, repartidas por turnos ====== */
        const geoPlano = new THREE.PlaneGeometry(1, 1);
        liberar.push(geoPlano);
        const tarjetas = [];
        for (let k = 0; k < nTarjetas; k++) {
            const prod = items[k % items.length];
            const img = prod.tex.image;
            const asp = (img && img.height) ? img.width / img.height : 1;
            const mat = new THREE.ShaderMaterial({
                uniforms: {
                    map: { value: prod.tex },
                    uAsp: { value: asp },
                    uHover: { value: 0 },
                    uShade: { value: 1 },
                    uTime: { value: 0 },
                    uOff: { value: Math.random() }
                },
                vertexShader: VERT_T,
                fragmentShader: FRAG_T
            });
            const m = new THREE.Mesh(geoPlano, mat);
            m.userData = { i: k, prod: prod, asp: asp, sel: 0 };
            group.add(m);
            tarjetas.push(m);
            liberar.push(mat);
        }

        function tamTarjeta() { return 210 * (dim.Hv / 1100); }

        /* ====== Tamaño ====== */
        function ajustar() {
            renderer.setSize(window.innerWidth, window.innerHeight, false);
            camera.aspect = window.innerWidth / window.innerHeight;
            calcular();
            camera.position.z = dim.z;
            camera.updateProjectionMatrix();
            helper.scale.set(dim.W, dim.H, dim.D);
            group.position.y = dim.y;
        }
        ajustar();

        /* ====== Interacción (el canvas no recibe eventos: se escucha en la ventana) ====== */
        let hover = 0, mx = 0, my = 0, rx = 0, ry = 0, elegida = -1;
        const raycaster = new THREE.Raycaster();
        const ndc = new THREE.Vector2();

        function mostrarEtiqueta(ev) {
            if (elegida < 0) { etiqueta.style.display = 'none'; document.body.style.cursor = ''; return; }
            const p = tarjetas[elegida].userData.prod;
            etiqueta.textContent = p.nombre + '  ·  $' + p.precio.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '  ·  click para ver detalle';
            etiqueta.style.left = Math.min(ev.clientX + 16, window.innerWidth - etiqueta.offsetWidth - 8) + 'px';
            etiqueta.style.top = (ev.clientY + 16) + 'px';
            etiqueta.style.display = 'block';
            document.body.style.cursor = 'pointer';
        }
        function elegir(ev) {
            // Si el cursor está sobre el catálogo, navbar o ventanas, no se selecciona nada
            if (ev.target && ev.target.closest && ev.target.closest(FUERA)) {
                elegida = -1;
            } else {
                ndc.set((ev.clientX / window.innerWidth) * 2 - 1, -(ev.clientY / window.innerHeight) * 2 + 1);
                raycaster.setFromCamera(ndc, camera);
                const golpes = raycaster.intersectObjects(tarjetas.filter(function (t) { return t.visible; }), false);
                elegida = golpes.length ? golpes[0].object.userData.i : -1;
            }
            mostrarEtiqueta(ev);
        }
        const onMove = function (ev) {
            mx = (ev.clientX / window.innerWidth) * 2 - 1;
            my = (ev.clientY / window.innerHeight) * 2 - 1;
            hover = 1;
            elegir(ev);
        };
        const onDown = function (ev) { elegir(ev); }; // pantallas táctiles
        const onLeave = function () { hover = 0; elegida = -1; etiqueta.style.display = 'none'; document.body.style.cursor = ''; };
        const onClick = function (ev) {
            if (elegida < 0) return;
            if (ev.target && ev.target.closest && ev.target.closest(FUERA)) return;
            // Abre el mismo detalle (con su QR) que al hacer click en la fila
            tarjetas[elegida].userData.prod.fila.click();
        };
        window.addEventListener('resize', ajustar);
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerdown', onDown);
        window.addEventListener('click', onClick);
        document.documentElement.addEventListener('pointerleave', onLeave);

        const e = {
            canvas: canvas, renderer: renderer, etiqueta: etiqueta, ui: ui, paneles: paneles, extraAdmin: extraAdmin, liberar: liberar, raf: 0,
            onResize: ajustar, onMove: onMove, onDown: onDown, onClick: onClick, onLeave: onLeave
        };
        estado = e;

        /* ====== Animación (misma lógica que animate() del ejemplo) ====== */
        const inicio = performance.now();
        let ultimo = inicio;
        const qCarta = new THREE.Quaternion();
        const vTmp = new THREE.Vector3();

        function frame(ahora) {
            e.raf = requestAnimationFrame(frame);
            const tg = (ahora - inicio) / 1000;
            const dt = Math.min((ahora - ultimo) / 16.667, 3); // 1 = un cuadro a 60 fps
            ultimo = ahora;

            pointCloud.visible = efecto.mostrarPuntos;
            linesMesh.visible = efecto.mostrarLineas;

            const hx = dim.W / 2, hy = dim.H / 2, hz = dim.D / 2;
            let vertexpos = 0, colorpos = 0, numConnected = 0;

            for (let i = 0; i < particleCount; i++) particlesData[i].numConnections = 0;

            for (let i = 0; i < particleCount; i++) {
                const particleData = particlesData[i];

                // La tarjeta seleccionada se queda quieta
                if (i !== elegida) {
                    particlePositions[i * 3] += particleData.velocity.x * dt;
                    particlePositions[i * 3 + 1] += particleData.velocity.y * dt;
                    particlePositions[i * 3 + 2] += particleData.velocity.z * dt;
                }

                if (particlePositions[i * 3 + 1] < -hy || particlePositions[i * 3 + 1] > hy)
                    particleData.velocity.y = -particleData.velocity.y;
                if (particlePositions[i * 3] < -hx || particlePositions[i * 3] > hx)
                    particleData.velocity.x = -particleData.velocity.x;
                if (particlePositions[i * 3 + 2] < -hz || particlePositions[i * 3 + 2] > hz)
                    particleData.velocity.z = -particleData.velocity.z;

                if (efecto.limitarConexiones && particleData.numConnections >= efecto.maxConexiones)
                    continue;

                for (let j = i + 1; j < particleCount; j++) {
                    if (numConnected >= MAX_PARES) break;

                    const particleDataB = particlesData[j];
                    if (efecto.limitarConexiones && particleDataB.numConnections >= efecto.maxConexiones)
                        continue;

                    const dx = particlePositions[i * 3] - particlePositions[j * 3];
                    const dy = particlePositions[i * 3 + 1] - particlePositions[j * 3 + 1];
                    const dz = particlePositions[i * 3 + 2] - particlePositions[j * 3 + 2];
                    const dist = Math.sqrt(dx * dx + dy * dy + dz * dz);

                    if (dist < efecto.distanciaMinima) {
                        particleData.numConnections++;
                        particleDataB.numConnections++;

                        const alpha = 1.0 - dist / efecto.distanciaMinima;

                        positions[vertexpos++] = particlePositions[i * 3];
                        positions[vertexpos++] = particlePositions[i * 3 + 1];
                        positions[vertexpos++] = particlePositions[i * 3 + 2];
                        positions[vertexpos++] = particlePositions[j * 3];
                        positions[vertexpos++] = particlePositions[j * 3 + 1];
                        positions[vertexpos++] = particlePositions[j * 3 + 2];

                        colors[colorpos++] = alpha;
                        colors[colorpos++] = alpha;
                        colors[colorpos++] = alpha;
                        colors[colorpos++] = alpha;
                        colors[colorpos++] = alpha;
                        colors[colorpos++] = alpha;

                        numConnected++;
                    }
                }
            }

            linesMesh.geometry.setDrawRange(0, numConnected * 2);
            linesMesh.geometry.attributes.position.needsUpdate = true;
            linesMesh.geometry.attributes.color.needsUpdate = true;
            pointCloud.geometry.attributes.position.needsUpdate = true;

            // El original gira todo el cubo (rotation.y = time * 0.1). Como aquí la caja es tan ancha
            // como la pantalla, se mece suavemente en lugar de dar vueltas completas.
            const tx = hover ? my * 0.12 : 0;
            const ty = hover ? mx * 0.18 : 0;
            rx += (tx - rx) * 0.05;
            ry += (ty - ry) * 0.05;
            group.rotation.x = rx + Math.sin(tg * 0.17) * 0.04;
            group.rotation.y = ry + Math.sin(tg * 0.1) * 0.22;
            group.updateMatrixWorld();

            // Las tarjetitas siempre miran a la cámara
            qCarta.copy(group.quaternion).invert().multiply(camera.quaternion);
            const base = tamTarjeta();

            for (let k = 0; k < tarjetas.length; k++) {
                const t = tarjetas[k];
                const ud = t.userData;
                t.visible = !filtro || ud.prod.nombre.toLowerCase().indexOf(filtro) !== -1;
                if (!t.visible) { if (k === elegida) elegida = -1; continue; }
                t.position.set(particlePositions[k * 3], particlePositions[k * 3 + 1], particlePositions[k * 3 + 2]);
                t.quaternion.copy(qCarta);

                ud.sel += ((k === elegida ? 1 : 0) - ud.sel) * 0.12;
                const s = 1 + ud.sel * 1.1;
                const w = (ud.asp >= 1 ? base : base * ud.asp) * s;
                const h = (ud.asp >= 1 ? base / ud.asp : base) * s;
                t.scale.set(w, h, 1);

                // Sombreado por profundidad
                vTmp.copy(t.position).applyMatrix4(group.matrixWorld);
                const prof = Math.min(Math.max((vTmp.z + hz) / dim.D, 0), 1);

                const u = t.material.uniforms;
                u.uShade.value = (0.55 + 0.45 * prof) + ud.sel * 0.2;
                u.uHover.value = ud.sel;
                u.uTime.value = tg;
            }

            renderer.render(scene, camera);
        }
        e.raf = requestAnimationFrame(frame);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    return { iniciar: iniciar, destruir: destruir, efecto: efecto };
})();