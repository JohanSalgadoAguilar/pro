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
    const FUERA = '.panel, .navbar, .modal-prod, .modal-confirm, .barra-acciones, dialog';

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
        if (e.estilo.parentNode) e.estilo.parentNode.removeChild(e.estilo);
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

        // Estilo: fondo oscuro, canvas detrás de todo y panel un poco translúcido
        const estilo = document.createElement('style');
        estilo.textContent =
            'body{background:#05070d !important;}' +
            'canvas.fondo-red{position:fixed;left:0;top:0;width:100%;height:100%;z-index:-1;pointer-events:none;display:block;}' +
            '.panel{background:rgba(255,255,255,.9) !important;-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);}' +
            'thead th{background:rgba(248,250,252,.55) !important;}';
        document.head.appendChild(estilo);

        const canvas = renderer.domElement;
        canvas.className = 'fondo-red';
        document.body.insertBefore(canvas, document.body.firstChild);

        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.setClearColor(0x000000, 0);

        const etiqueta = document.createElement('div');
        etiqueta.style.cssText = 'position:fixed;z-index:6;pointer-events:none;display:none;background:rgba(15,23,42,.92);color:#fff;padding:8px 12px;border-radius:8px;font:600 13px -apple-system,"Segoe UI",Roboto,Arial,sans-serif;border:1px solid #2563eb;white-space:nowrap;box-shadow:0 6px 18px rgba(0,0,0,.4);';
        document.body.appendChild(etiqueta);

        const liberar = [];
        items.forEach(function (p) { liberar.push(p.tex); });

        /* ====== Medidas: la caja se adapta a la pantalla ====== */
        const TAN = Math.tan(22.5 * Math.PI / 180); // FOV 45
        const dim = { W: 800, H: 800, D: 700, Hv: 1100, z: 1328 };
        function calcular() {
            const asp = window.innerWidth / window.innerHeight;
            dim.Hv = Math.max(1100, 900 / asp);          // alto visible en z = 0
            dim.z = (dim.Hv / 2) / TAN;                    // distancia de la cámara
            dim.H = dim.Hv * 0.75;
            dim.W = Math.max(800, dim.Hv * asp * 0.95);
            dim.D = 700;
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
        particleCount = Math.max(160, Math.min(particleCount, window.innerWidth < 700 ? 400 : 900, MAX_PARTICULAS));

        const nTarjetas = Math.min(MAX_TARJETAS, Math.max(items.length * TARJETAS_POR_PRODUCTO, 6), particleCount);

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

        function tamTarjeta() { return 170 * (dim.Hv / 1100); }

        /* ====== Tamaño ====== */
        function ajustar() {
            renderer.setSize(window.innerWidth, window.innerHeight, false);
            camera.aspect = window.innerWidth / window.innerHeight;
            calcular();
            camera.position.z = dim.z;
            camera.updateProjectionMatrix();
            helper.scale.set(dim.W, dim.H, dim.D);
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
                const golpes = raycaster.intersectObjects(tarjetas, false);
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
            canvas: canvas, renderer: renderer, etiqueta: etiqueta, estilo: estilo, liberar: liberar, raf: 0,
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