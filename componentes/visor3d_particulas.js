/*
 * TechPCs - Visor 3D de imágenes v3 (three.js)  ·  "Red de partículas con tarjetitas"
 *
 * Es el ejemplo oficial  https://threejs.org/examples/#webgl_buffergeometry_drawrange
 * (autor: fernandojsg) con la MISMA lógica: cubo de 800 unidades, 500 partículas que rebotan
 * en las paredes, líneas entre las partículas cercanas (setDrawRange), BoxHelper gris y el
 * grupo girando sobre el eje Y, cámara a 1750 con FOV 45.
 *
 * Lo que se añadió para TechPCs:
 *   - La imagen del producto aparece como tarjetitas (12) que flotan dentro de la red. Son parte
 *     de la red: las líneas se conectan a ellas igual que a cualquier partícula.
 *   - Las tarjetitas siempre miran a la cámara, tienen marco, esquinas redondeadas, brillo
 *     y se oscurecen un poco al alejarse.
 *   - Al pasar el cursor (o tocar con el dedo) una tarjeta se detiene y crece para verla bien.
 *   - El grupo se inclina suavemente hacia el cursor (en el ejemplo esto lo hace OrbitControls).
 *
 * Uso (igual que antes):
 *        Visor3D.montar(contenedor, 'obtener_imagen.php?id=3');
 *        Visor3D.destruir();
 *
 * Los mismos valores del panel del ejemplo (lil-gui) están en  Visor3D.efecto  por si quieres
 * cambiarlos, por ejemplo:  Visor3D.efecto.distanciaMinima = 200;
 *
 * Si WebGL no está disponible, three.js no carga o el usuario prefiere menos movimiento,
 * no hace nada y se queda visible la <img> normal del contenedor.
 */
const Visor3D = (function () {
    const THREE_URL = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';

    // Constantes del ejemplo original
    const R = 800;
    const R_HALF = R / 2;
    const MAX_PARTICULAS = 1000;

    // Añadidos para las tarjetitas
    const N_TARJETAS = 12;
    const TAM_TARJETA = 300;     // tamaño del lado mayor, en unidades del cubo
    const MAX_PARES = 80000;     // tope de líneas (el original reserva 1,000,000, aquí basta con menos)

    // Mismos parámetros que el panel "Controls" del ejemplo
    const efecto = {
        mostrarPuntos: true,      // showDots
        mostrarLineas: true,      // showLines
        distanciaMinima: 150,     // minDistance
        limitarConexiones: false, // limitConnections
        maxConexiones: 20,        // maxConnections
        particulas: 500           // particleCount (incluye las tarjetitas)
    };

    let cargaPromesa = null;
    let estado = null;
    let token = 0;

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
            // Rectángulo con esquinas redondeadas (sin deformarse con la proporción de la imagen)
            vec2 p = (vUv - 0.5) * vec2(uAsp, 1.0);
            vec2 mitad = vec2(uAsp, 1.0) * 0.5;
            float r = 0.09;
            vec2 q = abs(p) - (mitad - vec2(r));
            float d = length(max(q, vec2(0.0))) + min(max(q.x, q.y), 0.0) - r;
            if (d > 0.0) discard;

            vec4 c = texture2D(map, vUv);
            vec3 col = mix(vec3(1.0), c.rgb, c.a);

            // Franja de brillo que recorre la tarjeta
            float t = fract(uTime * 0.25 + uOff);
            float banda = vUv.x * 0.8 + vUv.y * 0.6;
            col += (1.0 - smoothstep(0.0, 0.1, abs(banda - (t * 2.0 - 0.3)))) * 0.18;

            // Más oscura cuanto más lejos esté de la cámara
            col *= uShade;

            // Marco (blanco; azul cuando está seleccionada)
            vec3 borde = mix(vec3(0.82, 0.88, 1.0), vec3(0.35, 0.65, 1.0), uHover);
            col = mix(col, borde, smoothstep(-0.032, -0.026, d));

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
        contenedor.style.background = '#000';
        contenedor.appendChild(canvas);

        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setClearColor(0x000000, 0);

        const liberar = [tex];

        /* ====== Escena (igual que el ejemplo) ====== */
        const camera = new THREE.PerspectiveCamera(45, 1, 1, 4000);
        camera.position.z = 1750;

        const scene = new THREE.Scene();
        const group = new THREE.Group();
        scene.add(group);

        const helper = new THREE.BoxHelper(new THREE.Mesh(new THREE.BoxGeometry(R, R, R)));
        helper.material.color.setHex(0x474747);
        helper.material.blending = THREE.AdditiveBlending;
        helper.material.transparent = true;
        group.add(helper);
        liberar.push(helper.geometry, helper.material);

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
        const particlePositions = new Float32Array(MAX_PARTICULAS * 3);
        const particlesData = [];

        const nT = Math.min(N_TARJETAS, MAX_PARTICULAS);
        for (let i = 0; i < MAX_PARTICULAS; i++) {
            particlePositions[i * 3] = Math.random() * R - R / 2;
            particlePositions[i * 3 + 1] = Math.random() * R - R / 2;
            particlePositions[i * 3 + 2] = Math.random() * R - R / 2;

            const v = new THREE.Vector3(-1 + Math.random() * 2, -1 + Math.random() * 2, -1 + Math.random() * 2);
            if (i < nT) v.multiplyScalar(0.55); // las tarjetitas van más despacio
            particlesData.push({ velocity: v, numConnections: 0 });
        }

        particles.setDrawRange(0, efecto.particulas);
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
        linesMesh.frustumCulled = false; // las líneas cambian cada cuadro
        group.add(linesMesh);
        liberar.push(geometry, material);

        /* ====== Tarjetitas con la imagen del producto ====== */
        const imgAspecto = (tex.image && tex.image.height) ? tex.image.width / tex.image.height : 1;
        const cw = imgAspecto >= 1 ? TAM_TARJETA : TAM_TARJETA * imgAspecto;
        const ch = imgAspecto >= 1 ? TAM_TARJETA / imgAspecto : TAM_TARJETA;

        const geoPlano = new THREE.PlaneGeometry(1, 1);
        liberar.push(geoPlano);

        const tarjetas = [];
        for (let k = 0; k < nT; k++) {
            const mat = new THREE.ShaderMaterial({
                uniforms: {
                    map: { value: tex },
                    uAsp: { value: cw / ch },
                    uHover: { value: 0 },
                    uShade: { value: 1 },
                    uTime: { value: 0 },
                    uOff: { value: Math.random() }
                },
                vertexShader: VERT_T,
                fragmentShader: FRAG_T
            });
            const m = new THREE.Mesh(geoPlano, mat);
            m.scale.set(cw, ch, 1);
            m.userData = { i: k, sel: 0 };
            group.add(m);
            tarjetas.push(m);
            liberar.push(mat);
        }

        /* ====== Tamaño y cámara ====== */
        function ajustar() {
            const w = contenedor.clientWidth, h = contenedor.clientHeight;
            if (!w || !h) return;
            renderer.setSize(w, h, false);
            camera.aspect = w / h;
            camera.updateProjectionMatrix();
            // En recuadros angostos se aleja la cámara para que el cubo quepa completo
            camera.position.z = 1750 * Math.max(1, 1.05 / camera.aspect);
        }

        /* ====== Interacción ====== */
        let hover = 0, mx = 0, my = 0, rx = 0, ry = 0;
        const raycaster = new THREE.Raycaster();
        const ndc = new THREE.Vector2();
        let elegida = -1;

        function elegir(ev) {
            const r = canvas.getBoundingClientRect();
            if (!r.width || !r.height) return;
            ndc.set(((ev.clientX - r.left) / r.width) * 2 - 1, -((ev.clientY - r.top) / r.height) * 2 + 1);
            raycaster.setFromCamera(ndc, camera);
            const visibles = tarjetas.filter(function (t) { return t.visible; });
            const golpes = raycaster.intersectObjects(visibles, false);
            elegida = golpes.length ? golpes[0].object.userData.i : -1;
        }
        const onMove = function (ev) {
            const r = contenedor.getBoundingClientRect();
            if (!r.width || !r.height) return;
            mx = ((ev.clientX - r.left) / r.width) * 2 - 1;
            my = ((ev.clientY - r.top) / r.height) * 2 - 1;
            hover = 1;
            elegir(ev);
        };
        const onLeave = function () { hover = 0; elegida = -1; };
        const onDown = function (ev) { elegir(ev); }; // para pantallas táctiles
        contenedor.addEventListener('pointermove', onMove);
        contenedor.addEventListener('pointerleave', onLeave);
        contenedor.addEventListener('pointerdown', onDown);

        const ro = new ResizeObserver(ajustar);
        ro.observe(contenedor);
        ajustar();

        const img = contenedor.querySelector('img');
        const e = {
            contenedor: contenedor, canvas: canvas, renderer: renderer, ro: ro,
            onMove: onMove, onLeave: onLeave, onDown: onDown, img: img, raf: 0,
            liberar: liberar, bgPrevio: bgPrevio
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

            const particleCount = Math.min(efecto.particulas, MAX_PARTICULAS);
            particles.setDrawRange(0, particleCount);
            pointCloud.visible = efecto.mostrarPuntos;
            linesMesh.visible = efecto.mostrarLineas;

            let vertexpos = 0;
            let colorpos = 0;
            let numConnected = 0;

            for (let i = 0; i < particleCount; i++) particlesData[i].numConnections = 0;

            for (let i = 0; i < particleCount; i++) {
                const particleData = particlesData[i];

                // La tarjeta seleccionada se queda quieta para poder verla
                if (i !== elegida) {
                    particlePositions[i * 3] += particleData.velocity.x * dt;
                    particlePositions[i * 3 + 1] += particleData.velocity.y * dt;
                    particlePositions[i * 3 + 2] += particleData.velocity.z * dt;
                }

                if (particlePositions[i * 3 + 1] < -R_HALF || particlePositions[i * 3 + 1] > R_HALF)
                    particleData.velocity.y = -particleData.velocity.y;

                if (particlePositions[i * 3] < -R_HALF || particlePositions[i * 3] > R_HALF)
                    particleData.velocity.x = -particleData.velocity.x;

                if (particlePositions[i * 3 + 2] < -R_HALF || particlePositions[i * 3 + 2] > R_HALF)
                    particleData.velocity.z = -particleData.velocity.z;

                if (efecto.limitarConexiones && particleData.numConnections >= efecto.maxConexiones)
                    continue;

                // Revisa las partículas cercanas
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

            // Giro del grupo (en el ejemplo: group.rotation.y = time * 0.1) + inclinación hacia el cursor
            const tx = hover ? my * 0.35 : 0;
            const ty = hover ? mx * 0.5 : 0;
            rx += (tx - rx) * 0.06;
            ry += (ty - ry) * 0.06;
            group.rotation.x = rx;
            group.rotation.y = tg * 0.1 + ry;
            group.updateMatrixWorld();

            // Las tarjetitas siempre miran a la cámara
            qCarta.copy(group.quaternion).invert().multiply(camera.quaternion);

            for (let k = 0; k < tarjetas.length; k++) {
                const t = tarjetas[k];
                t.visible = k < particleCount;
                if (!t.visible) continue;

                t.position.set(particlePositions[k * 3], particlePositions[k * 3 + 1], particlePositions[k * 3 + 2]);
                t.quaternion.copy(qCarta);

                const ud = t.userData;
                ud.sel += ((k === elegida ? 1 : 0) - ud.sel) * 0.12;
                const s = 1 + ud.sel * 1.1;
                t.scale.set(cw * s, ch * s, 1);

                // Sombreado por profundidad (más lejos = más oscura)
                vTmp.copy(t.position).applyMatrix4(group.matrixWorld);
                const prof = Math.min(Math.max((vTmp.z + 566) / 1132, 0), 1);

                const u = t.material.uniforms;
                u.uShade.value = (0.55 + 0.45 * prof) + ud.sel * 0.2;
                u.uHover.value = ud.sel;
                u.uTime.value = tg;
            }

            renderer.render(scene, camera);
        }
        e.raf = requestAnimationFrame(frame);

        // Se oculta la imagen normal solo cuando el 3D ya está listo
        if (img) img.style.visibility = 'hidden';
        return true;
    }

    return { montar: montar, destruir: destruir, efecto: efecto };
})();