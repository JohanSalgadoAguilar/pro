/*
 * TechPCs - Visor 3D de imágenes (three.js)
 * Muestra la imagen del producto como una tarjeta 3D que:
 *   - entra girando al abrirse,
 *   - flota suavemente,
 *   - se inclina hacia el cursor (o el dedo en celular),
 *   - tiene una franja de brillo que la recorre y un resplandor que sigue al cursor.
 *
 * Uso:   Visor3D.montar(contenedor, 'obtener_imagen.php?id=3');
 *        Visor3D.destruir();
 *
 * Si WebGL no está disponible, three.js no carga o el usuario prefiere menos
 * movimiento, no hace nada y se queda visible la <img> normal del contenedor.
 */
const Visor3D = (function () {
    const THREE_URL = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
    let cargaPromesa = null;
    let estado = null;
    let token = 0;

    const VERT = `
        varying vec2 vUv;
        void main() {
            vUv = uv;
            gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
        }
    `;

    const FRAG = `
        uniform sampler2D map;
        uniform float uTime;
        uniform vec2 uMouse;
        uniform float uHover;
        uniform vec3 uBg;
        varying vec2 vUv;
        void main() {
            vec4 c = texture2D(map, vUv);
            vec3 col = mix(uBg, c.rgb, c.a);

            // Franja de brillo que recorre la imagen
            float t = fract(uTime * 0.18);
            float banda = vUv.x * 0.8 + vUv.y * 0.6;
            float d = abs(banda - (t * 2.0 - 0.3));
            float brillo = smoothstep(0.12, 0.0, d) * 0.22;

            // Resplandor que sigue al cursor
            float glow = smoothstep(0.55, 0.0, distance(vUv, uMouse)) * 0.16 * uHover;

            col += vec3(brillo + glow);

            // Borde suave para dar sensación de tarjeta
            vec2 e = min(vUv, 1.0 - vUv);
            float borde = smoothstep(0.0, 0.012, min(e.x, e.y));
            col *= mix(0.85, 1.0, borde);

            gl_FragColor = vec4(col, 1.0);
        }
    `;

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

    function destruir() {
        token++; // cancela cualquier carga pendiente
        if (!estado) return;
        const e = estado;
        estado = null;
        cancelAnimationFrame(e.raf);
        e.ro.disconnect();
        e.contenedor.removeEventListener('pointermove', e.onMove);
        e.contenedor.removeEventListener('pointerleave', e.onLeave);
        e.geo.dispose();
        e.mat.dispose();
        e.tex.dispose();
        e.renderer.dispose();
        if (e.renderer.forceContextLoss) e.renderer.forceContextLoss();
        if (e.canvas.parentNode) e.canvas.parentNode.removeChild(e.canvas);
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
        contenedor.appendChild(canvas);

        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setClearColor(0x000000, 0);

        const scene = new THREE.Scene();
        const FOV = 35;
        const camera = new THREE.PerspectiveCamera(FOV, 1, 0.1, 100);
        camera.position.z = 1 / Math.tan(THREE.MathUtils.degToRad(FOV / 2)); // alto visible = 2

        const geo = new THREE.PlaneGeometry(1, 1);
        const mat = new THREE.ShaderMaterial({
            uniforms: {
                map: { value: tex },
                uTime: { value: 0 },
                uMouse: { value: new THREE.Vector2(0.5, 0.5) },
                uHover: { value: 0 },
                uBg: { value: new THREE.Color(0xffffff) }
            },
            vertexShader: VERT,
            fragmentShader: FRAG
        });
        const mesh = new THREE.Mesh(geo, mat);
        scene.add(mesh);

        const imgAspecto = (tex.image && tex.image.height) ? tex.image.width / tex.image.height : 1;
        const base = { w: 1, h: 1 };

        function ajustar() {
            const w = contenedor.clientWidth, h = contenedor.clientHeight;
            if (!w || !h) return;
            renderer.setSize(w, h, false);
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
            // Ajusta la imagen dentro del recuadro sin deformarla
            let pw = 2 * camera.aspect * 0.92;
            let ph = pw / imgAspecto;
            if (ph > 2 * 0.92) {
                ph = 2 * 0.92;
                pw = ph * imgAspecto;
            }
            base.w = pw;
            base.h = ph;
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
        contenedor.addEventListener('pointermove', onMove);
        contenedor.addEventListener('pointerleave', onLeave);

        const ro = new ResizeObserver(ajustar);
        ro.observe(contenedor);
        ajustar();

        const img = contenedor.querySelector('img');
        const e = { contenedor: contenedor, canvas: canvas, renderer: renderer, geo: geo, mat: mat, tex: tex, ro: ro, onMove: onMove, onLeave: onLeave, img: img, raf: 0 };
        estado = e;

        const t0 = performance.now();
        function frame(ahora) {
            e.raf = requestAnimationFrame(frame);
            const t = (ahora - t0) / 1000;

            // Entrada: aparece girando y creciendo
            const k = Math.min(t / 0.9, 1);
            const ease = 1 - Math.pow(1 - k, 3);

            hoverSuave += (hover - hoverSuave) * 0.1;
            const tx = hover ? my * 0.3 : Math.cos(t * 0.5) * 0.07;
            const ty = hover ? mx * 0.35 : Math.sin(t * 0.7) * 0.16;
            rx += (tx - rx) * 0.08;
            ry += (ty - ry) * 0.08;

            mesh.rotation.x = rx;
            mesh.rotation.y = ry + (1 - ease) * 1.3;
            mesh.rotation.z = (1 - ease) * -0.25;
            mesh.position.y = Math.sin(t * 1.3) * 0.025; // flotación
            const s = 0.6 + 0.4 * ease;
            mesh.scale.set(base.w * s, base.h * s, 1);

            mat.uniforms.uTime.value = t;
            mat.uniforms.uMouse.value.lerp(mouse01, 0.15);
            mat.uniforms.uHover.value = hoverSuave;

            renderer.render(scene, camera);
        }
        e.raf = requestAnimationFrame(frame);

        // Se oculta la imagen normal solo cuando el 3D ya está listo
        if (img) img.style.visibility = 'hidden';
        return true;
    }

    return { montar: montar, destruir: destruir };
})();
