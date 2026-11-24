/*
 * TechPCs - Visor 3D de imágenes (three.js)
 * Inspirado en ejemplos de Three.js (shaders interactivos, deformación por onda y partículas):
 *   - Entrada girando y escalando con suavizado cúbico,
 *   - Flotación continua e inclinación reactiva al cursor o dedo,
 *   - Shader con onda expansiva al mover el puntero, franja de brillo y resplandor focal,
 *   - Nube sutil de partículas/destellos en profundidad alrededor de la tarjeta.
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
        uniform float uTime;
        uniform vec2 uMouse;
        uniform float uHover;
        varying vec2 vUv;
        varying float vWave;

        void main() {
            vUv = uv;
            vec3 pos = position;

            // Onda suave y leve relieve reactivo al cursor (inspirado en ejemplos de deformación/ondas de Three.js)
            vec2 p = uv - uMouse;
            float dist = length(p);
            float onda = sin(dist * 22.0 - uTime * 5.0) * exp(-dist * 4.5);
            float abombamiento = exp(-dist * 5.0) * 0.045;
            float despl = (onda * 0.018 + abombamiento) * uHover;

            pos.z += despl;
            vWave = onda * uHover;

            gl_Position = projectionMatrix * modelViewMatrix * vec4(pos, 1.0);
        }
    `;

    const FRAG = `
        uniform sampler2D map;
        uniform float uTime;
        uniform vec2 uMouse;
        uniform float uHover;
        uniform vec3 uBg;
        varying vec2 vUv;
        varying float vWave;

        void main() {
            // Ligera refracción UV con la onda para dar efecto cristal/relieve
            vec2 uvRefract = clamp(vUv + normalize(vUv - uMouse + vec2(0.0001)) * vWave * 0.035, 0.001, 0.999);
            vec4 c = texture2D(map, uvRefract);
            vec3 col = mix(uBg, c.rgb, c.a);

            // Franja de brillo diagonal que recorre la tarjeta
            float t = fract(uTime * 0.16);
            float banda = vUv.x * 0.75 + vUv.y * 0.65;
            float d = abs(banda - (t * 2.1 - 0.35));
            float brillo = smoothstep(0.11, 0.0, d) * 0.24;

            // Resplandor especular que sigue al cursor
            float glow = smoothstep(0.55, 0.0, distance(vUv, uMouse)) * (0.14 + 0.16 * uHover);

            // Cresta luminosa de la onda
            float cresta = max(vWave, 0.0) * 0.35;

            col += vec3(brillo + glow + cresta);

            // Borde biselado suave tipo tarjeta tecnológica
            vec2 e = min(vUv, 1.0 - vUv);
            float borde = smoothstep(0.0, 0.015, min(e.x, e.y));
            float marcoLuz = (1.0 - smoothstep(0.0, 0.025, min(e.x, e.y))) * 0.18 * (0.4 + 0.6 * uHover);
            col = col * mix(0.84, 1.0, borde) + vec3(0.23, 0.51, 0.96) * marcoLuz;

            gl_FragColor = vec4(col, 1.0);
        }
    `;

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

    function crearParticulas() {
        const count = 42;
        const posiciones = new Float32Array(count * 3);
        const fases = new Float32Array(count);

        for (let i = 0; i < count; i++) {
            posiciones[i * 3] = (Math.random() - 0.5) * 2.2;
            posiciones[i * 3 + 1] = (Math.random() - 0.5) * 1.8;
            posiciones[i * 3 + 2] = (Math.random() - 0.5) * 0.9 - 0.15;
            fases[i] = Math.random() * Math.PI * 2;
        }

        const pGeo = new THREE.BufferGeometry();
        pGeo.setAttribute('position', new THREE.BufferAttribute(posiciones, 3));

        const pMat = new THREE.PointsMaterial({
            color: 0x3b82f6,
            size: 0.032,
            transparent: true,
            opacity: 0.55,
            blending: THREE.AdditiveBlending,
            depthWrite: false
        });

        return {
            points: new THREE.Points(pGeo, pMat),
            geo: pGeo,
            mat: pMat,
            basePos: posiciones,
            fases: fases
        };
    }

    function destruir() {
        token++;
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
        if (e.particulas) {
            e.particulas.geo.dispose();
            e.particulas.mat.dispose();
        }
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
        camera.position.z = 1 / Math.tan(THREE.MathUtils.degToRad(FOV / 2));

        // Subdivisión 32x32 para que la onda y el relieve en el vertex shader se vean suaves
        const geo = new THREE.PlaneGeometry(1, 1, 32, 32);
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

        const particulas = crearParticulas();
        scene.add(particulas.points);

        const imgAspecto = (tex.image && tex.image.height) ? tex.image.width / tex.image.height : 1;
        const base = { w: 1, h: 1 };

        function ajustar() {
            const w = contenedor.clientWidth, h = contenedor.clientHeight;
            if (!w || !h) return;
            renderer.setSize(w, h, false);
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
            let pw = 2 * camera.aspect * 0.88;
            let ph = pw / imgAspecto;
            if (ph > 2 * 0.88) {
                ph = 2 * 0.88;
                pw = ph * imgAspecto;
            }
            base.w = pw;
            base.h = ph;
        }

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
        const e = {
            contenedor: contenedor,
            canvas: canvas,
            renderer: renderer,
            geo: geo,
            mat: mat,
            tex: tex,
            particulas: particulas,
            ro: ro,
            onMove: onMove,
            onLeave: onLeave,
            img: img,
            raf: 0
        };
        estado = e;

        const t0 = performance.now();
        function frame(ahora) {
            e.raf = requestAnimationFrame(frame);
            const t = (ahora - t0) / 1000;

            const k = Math.min(t / 0.85, 1);
            const ease = 1 - Math.pow(1 - k, 3);

            hoverSuave += (hover - hoverSuave) * 0.1;
            const tx = hover ? my * 0.34 : Math.cos(t * 0.55) * 0.08;
            const ty = hover ? mx * 0.4 : Math.sin(t * 0.75) * 0.18;
            rx += (tx - rx) * 0.09;
            ry += (ty - ry) * 0.09;

            mesh.rotation.x = rx;
            mesh.rotation.y = ry + (1 - ease) * 1.4;
            mesh.rotation.z = (1 - ease) * -0.25;
            mesh.position.y = Math.sin(t * 1.35) * 0.028;

            const pulso = 1 + hoverSuave * 0.035;
            const s = (0.6 + 0.4 * ease) * pulso;
            mesh.scale.set(base.w * s, base.h * s, 1);

            // Animación sutil de partículas
            const attrPos = particulas.geo.getAttribute('position');
            for (let i = 0; i < particulas.fases.length; i++) {
                const fase = particulas.fases[i];
                attrPos.array[i * 3 + 1] = particulas.basePos[i * 3 + 1] + Math.sin(t * 1.4 + fase) * 0.04;
                attrPos.array[i * 3] = particulas.basePos[i * 3] + mx * 0.08 * (0.4 + (i % 5) * 0.15);
            }
            attrPos.needsUpdate = true;
            particulas.mat.opacity = 0.35 + 0.35 * hoverSuave;

            mat.uniforms.uTime.value = t;
            mat.uniforms.uMouse.value.lerp(mouse01, 0.16);
            mat.uniforms.uHover.value = hoverSuave;

            renderer.render(scene, camera);
        }
        e.raf = requestAnimationFrame(frame);

        if (img) img.style.visibility = 'hidden';
        return true;
    }

    return { montar: montar, destruir: destruir };
})();