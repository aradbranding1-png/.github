const THREE_CDNS = [
  'https://cdn.jsdelivr.net/npm/three@0.180.0/build/three.module.js',
  'https://unpkg.com/three@0.180.0/build/three.module.js'
];

async function loadThree() {
  let lastError;
  for (const url of THREE_CDNS) {
    try {
      const module = await import(url);
      window.__THREE_CDN_BASE__ = url.replace(/\/build\/three\.module\.js$/, '');
      return module;
    } catch (error) {
      lastError = error;
      console.warn('[trade-globe] Three.js CDN failed:', url, error);
    }
  }
  throw lastError || new Error('Three.js could not be loaded');
}

const THREE = await loadThree();
const THREE_CDN_BASE = window.__THREE_CDN_BASE__;

const stage = document.querySelector('[data-globe-stage]');
const canvas = document.getElementById('trade-globe');
if (!stage || !canvas) throw new Error('Trade globe stage not found');

const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const mobile = window.matchMedia('(max-width: 650px)').matches;

const scene = new THREE.Scene();
scene.fog = new THREE.FogExp2(0x020914, 0.016);

const camera = new THREE.PerspectiveCamera(32, 1, 0.1, 100);
camera.position.set(0, 0.08, mobile ? 7.7 : 6.55);

const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true, powerPreference: 'high-performance' });
renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, mobile ? 1.25 : 1.7));
renderer.outputColorSpace = THREE.SRGBColorSpace;
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.toneMappingExposure = 1.18;
renderer.setClearColor(0x000000, 0);

const group = new THREE.Group();
scene.add(group);
const earthGroup = new THREE.Group();
group.add(earthGroup);

const R = 2.34;
const loader = new THREE.TextureLoader();
loader.setCrossOrigin('anonymous');

function texture(url, color = false) {
  const t = loader.load(url, undefined, undefined, (err) => console.warn('[trade-globe] texture failed:', url, err));
  if (color) t.colorSpace = THREE.SRGBColorSpace;
  t.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
  return t;
}

// Real Earth textures: no flat CSS sphere or screenshot is used.
const earthMap = texture(`${THREE_CDN_BASE}/examples/textures/planets/earth_atmos_2048.jpg`, true);
const normalMap = texture(`${THREE_CDN_BASE}/examples/textures/planets/earth_normal_2048.jpg`);
const specularMap = texture(`${THREE_CDN_BASE}/examples/textures/planets/earth_specular_2048.jpg`);
const lightsMap = texture(`${THREE_CDN_BASE}/examples/textures/planets/earth_lights_2048.png`, true);
const cloudsMap = texture(`${THREE_CDN_BASE}/examples/textures/planets/earth_clouds_1024.png`, true);

// Cinematic lighting.
scene.add(new THREE.AmbientLight(0x83bde6, 0.42));
const sun = new THREE.DirectionalLight(0xffffff, 2.75);
sun.position.set(5.5, 3.2, 6.2);
scene.add(sun);
const blueRim = new THREE.PointLight(0x00a8ff, 17, 13, 2);
blueRim.position.set(-4.4, 1.6, -4.8);
scene.add(blueRim);
const goldFill = new THREE.PointLight(0xf5c65d, 5.5, 9, 2);
goldFill.position.set(3.5, -1.2, 4.5);
scene.add(goldFill);

const earth = new THREE.Mesh(
  new THREE.SphereGeometry(R, mobile ? 64 : 96, mobile ? 48 : 64),
  new THREE.MeshPhongMaterial({
    map: earthMap,
    normalMap,
    normalScale: new THREE.Vector2(0.58, 0.58),
    specularMap,
    specular: new THREE.Color(0x28506e),
    shininess: 9,
    emissiveMap: lightsMap,
    emissive: new THREE.Color(0x4f86a8),
    emissiveIntensity: 0.88,
  })
);
earthGroup.add(earth);

const clouds = new THREE.Mesh(
  new THREE.SphereGeometry(R * 1.012, mobile ? 48 : 72, mobile ? 32 : 48),
  new THREE.MeshPhongMaterial({ map: cloudsMap, transparent: true, opacity: 0.17, depthWrite: false })
);
earthGroup.add(clouds);

const atmosphere = new THREE.Mesh(
  new THREE.SphereGeometry(R * 1.085, mobile ? 48 : 72, mobile ? 36 : 48),
  new THREE.ShaderMaterial({
    transparent: true,
    side: THREE.BackSide,
    blending: THREE.AdditiveBlending,
    depthWrite: false,
    uniforms: { glowColor: { value: new THREE.Color(0x22bfff) } },
    vertexShader: `varying vec3 vNormal; void main(){ vNormal=normalize(normalMatrix*normal); gl_Position=projectionMatrix*modelViewMatrix*vec4(position,1.0); }`,
    fragmentShader: `uniform vec3 glowColor; varying vec3 vNormal; void main(){ float rim=pow(0.76-dot(vNormal,vec3(0.,0.,1.)),2.35); gl_FragColor=vec4(glowColor,rim*.66); }`,
  })
);
earthGroup.add(atmosphere);

function latLon(lat, lon, radius = R) {
  const phi = THREE.MathUtils.degToRad(90 - lat);
  const theta = THREE.MathUtils.degToRad(lon + 180);
  return new THREE.Vector3(
    -radius * Math.sin(phi) * Math.cos(theta),
    radius * Math.cos(phi),
    radius * Math.sin(phi) * Math.sin(theta)
  );
}

function slerpOnSphere(a, b, t, radius = R) {
  const v1 = a.clone().normalize();
  const v2 = b.clone().normalize();
  let dot = THREE.MathUtils.clamp(v1.dot(v2), -1, 1);
  const omega = Math.acos(dot);
  if (omega < 1e-5) return v1.multiplyScalar(radius);
  const s = Math.sin(omega);
  return v1.multiplyScalar(Math.sin((1 - t) * omega) / s)
    .add(v2.multiplyScalar(Math.sin(t * omega) / s))
    .normalize().multiplyScalar(radius);
}

function routeCurve(from, to, lift = 0.19) {
  const a = latLon(from[0], from[1], R * 1.005);
  const b = latLon(to[0], to[1], R * 1.005);
  const points = [];
  for (let i = 0; i <= 72; i += 1) {
    const t = i / 72;
    const p = slerpOnSphere(a, b, t, R * (1.01 + lift * Math.sin(Math.PI * t)));
    points.push(p);
  }
  return new THREE.CatmullRomCurve3(points, false, 'centripetal', 0.5);
}

const nodePoints = [
  [31.2, 121.5], [25.2, 55.3], [41.0, 29.0], [51.5, 0], [35.7, 139.7],
  [23.0, 72.6], [1.3, 103.8], [40.7, 116.4], [30.0, 31.2], [-23.5, -46.6],
  [-33.9, 18.4], [37.8, -122.4], [52.2, 13.4], [9.0, -79.5]
];
const nodeGeo = new THREE.SphereGeometry(0.035, 10, 10);
const nodeMat = new THREE.MeshBasicMaterial({ color: 0xffd978 });
nodePoints.forEach(([lat, lon]) => {
  const node = new THREE.Mesh(nodeGeo, nodeMat);
  node.position.copy(latLon(lat, lon, R * 1.025));
  earthGroup.add(node);
});

const routeDefs = [
  { from: [31.2, 121.5], to: [25.2, 55.3], kind: 'sea', speed: 0.034 },
  { from: [31.2, 121.5], to: [51.5, 0], kind: 'sea', speed: 0.028 },
  { from: [23.0, 72.6], to: [25.2, 55.3], kind: 'sea', speed: 0.043 },
  { from: [25.2, 55.3], to: [51.5, 0], kind: 'sea', speed: 0.031 },
  { from: [1.3, 103.8], to: [25.2, 55.3], kind: 'sea', speed: 0.037 },
  { from: [37.8, -122.4], to: [51.5, 0], kind: 'air', speed: 0.048 },
  { from: [31.2, 121.5], to: [37.8, -122.4], kind: 'air', speed: 0.041 },
  { from: [41.0, 29.0], to: [51.5, 0], kind: 'air', speed: 0.054 },
  { from: [-23.5, -46.6], to: [51.5, 0], kind: 'sea', speed: 0.026 },
];

const routes = [];
routeDefs.forEach((def, index) => {
  const curve = routeCurve(def.from, def.to, def.kind === 'sea' ? 0.12 : 0.25);
  const tube = new THREE.TubeGeometry(curve, 96, def.kind === 'sea' ? 0.009 : 0.0065, 6, false);
  const material = new THREE.MeshBasicMaterial({
    color: def.kind === 'sea' ? 0x008cff : 0x27c7ff,
    transparent: true,
    opacity: def.kind === 'sea' ? 0.7 : 0.58,
    blending: THREE.AdditiveBlending,
  });
  earthGroup.add(new THREE.Mesh(tube, material));

  const particle = new THREE.Mesh(
    new THREE.SphereGeometry(def.kind === 'sea' ? 0.026 : 0.018, 8, 8),
    new THREE.MeshBasicMaterial({ color: def.kind === 'sea' ? 0x7ae7ff : 0xffffff })
  );
  earthGroup.add(particle);

  // Additional dim particles make routes feel continuously active.
  const secondary = [];
  if (!mobile) {
    for (let j = 1; j < 4; j += 1) {
      const p = particle.clone();
      p.scale.setScalar(0.55);
      earthGroup.add(p);
      secondary.push(p);
    }
  }
  routes.push({ ...def, curve, particle, secondary, t: (index * 0.113) % 1 });
});

function makeShip(scale = 1) {
  const g = new THREE.Group();
  const dark = new THREE.MeshStandardMaterial({ color: 0x102a3b, metalness: 0.48, roughness: 0.34 });
  const hull = new THREE.Mesh(new THREE.BoxGeometry(0.14 * scale, 0.105 * scale, 0.62 * scale), dark);
  hull.position.y = -0.02;
  g.add(hull);
  const bow = new THREE.Mesh(new THREE.ConeGeometry(0.105 * scale, 0.30 * scale, 4), dark);
  bow.rotation.x = Math.PI / 2;
  bow.position.set(0, -0.02 * scale, 0.43 * scale);
  g.add(bow);
  const colors = [0xe8b94f, 0x1b5874, 0x9e4c3d];
  for (let row = 0; row < 3; row += 1) {
    for (let col = 0; col < 3; col += 1) {
      const box = new THREE.Mesh(
        new THREE.BoxGeometry(0.095 * scale, 0.072 * scale, 0.115 * scale),
        new THREE.MeshStandardMaterial({ color: colors[(row + col) % colors.length], roughness: 0.48 })
      );
      box.position.set((col - 1) * 0.048 * scale, 0.083 * scale, row * 0.125 * scale - 0.055 * scale);
      g.add(box);
    }
  }
  const bridge = new THREE.Mesh(new THREE.BoxGeometry(0.115 * scale, 0.16 * scale, 0.13 * scale), new THREE.MeshStandardMaterial({ color: 0xdce7ed, roughness: 0.28 }));
  bridge.position.set(0, 0.14 * scale, -0.24 * scale);
  g.add(bridge);
  const mast = new THREE.Mesh(new THREE.CylinderGeometry(0.008 * scale, 0.008 * scale, 0.25 * scale, 6), new THREE.MeshBasicMaterial({ color: 0xf5c65d }));
  mast.position.set(0, 0.30 * scale, -0.24 * scale);
  g.add(mast);
  const light = new THREE.Mesh(new THREE.SphereGeometry(0.012 * scale, 8, 8), new THREE.MeshBasicMaterial({ color: 0xffd978 }));
  light.position.set(0, 0.43 * scale, -0.24 * scale);
  g.add(light);
  return g;
}

function makeWake(scale = 1) {
  const wake = new THREE.Mesh(
    new THREE.PlaneGeometry(0.11 * scale, 0.5 * scale),
    new THREE.MeshBasicMaterial({ color: 0x8ee9ff, transparent: true, opacity: 0.2, blending: THREE.AdditiveBlending, depthWrite: false })
  );
  wake.rotation.x = -Math.PI / 2;
  wake.position.y = -0.06 * scale;
  return wake;
}

function makePlane(scale = 0.72) {
  const g = new THREE.Group();
  const mat = new THREE.MeshStandardMaterial({ color: 0xe8f0f5, metalness: 0.35, roughness: 0.3 });
  const body = new THREE.Mesh(new THREE.CylinderGeometry(0.024 * scale, 0.024 * scale, 0.58 * scale, 10), mat);
  body.rotation.z = Math.PI / 2;
  g.add(body);
  const wings = new THREE.Mesh(new THREE.BoxGeometry(0.38 * scale, 0.014 * scale, 0.09 * scale), mat);
  g.add(wings);
  const tail = new THREE.Mesh(new THREE.BoxGeometry(0.13 * scale, 0.012 * scale, 0.07 * scale), mat);
  tail.position.x = -0.22 * scale;
  g.add(tail);
  const light = new THREE.Mesh(new THREE.SphereGeometry(0.019 * scale, 8, 8), new THREE.MeshBasicMaterial({ color: 0x27c7ff }));
  light.position.x = 0.31 * scale;
  g.add(light);
  return g;
}

const movers = [];
routes.filter(r => r.kind === 'sea').slice(0, 4).forEach((route, i) => {
  const ship = makeShip(0.95 + (i % 2) * 0.12);
  const wake = makeWake(0.95 + (i % 2) * 0.12);
  const carrier = new THREE.Group();
  carrier.add(ship, wake);
  earthGroup.add(carrier);
  movers.push({ object: carrier, wake, route, offset: i * 0.19, kind: 'ship' });
});
routes.filter(r => r.kind === 'air').slice(0, 4).forEach((route, i) => {
  const plane = makePlane(0.68 + (i % 2) * 0.08);
  earthGroup.add(plane);
  movers.push({ object: plane, route, offset: i * 0.23, kind: 'plane' });
});

function orientMover(mover, t) {
  const p = mover.route.curve.getPointAt(t);
  const n = mover.route.curve.getPointAt(Math.min(0.999, t + 0.006));
  mover.object.position.copy(p);
  const direction = n.clone().sub(p).normalize();
  const outward = p.clone().normalize();
  mover.object.up.copy(outward);
  mover.object.quaternion.setFromUnitVectors(new THREE.Vector3(0, 0, 1), direction);
  if (mover.kind === 'ship') {
    mover.object.rotateY(Math.PI);
    mover.wake.position.set(0, -0.06, -0.22);
  }
}

// Slow cinematic rotation + drag interaction.
let pointerDown = false;
let lastX = 0;
let lastY = 0;
let targetRotX = 0;
let targetRotY = 0;
let zoomTarget = camera.position.z;

canvas.addEventListener('pointerdown', (event) => {
  pointerDown = true;
  lastX = event.clientX;
  lastY = event.clientY;
  canvas.setPointerCapture?.(event.pointerId);
});
canvas.addEventListener('pointermove', (event) => {
  if (!pointerDown) return;
  const dx = event.clientX - lastX;
  const dy = event.clientY - lastY;
  lastX = event.clientX;
  lastY = event.clientY;
  targetRotY += dx * 0.004;
  targetRotX += dy * 0.0024;
  targetRotX = THREE.MathUtils.clamp(targetRotX, -0.42, 0.42);
});
canvas.addEventListener('pointerup', (event) => {
  pointerDown = false;
  canvas.releasePointerCapture?.(event.pointerId);
});
canvas.addEventListener('pointercancel', () => { pointerDown = false; });
canvas.addEventListener('wheel', (event) => {
  event.preventDefault();
  zoomTarget = THREE.MathUtils.clamp(zoomTarget + event.deltaY * 0.0025, mobile ? 7.0 : 5.85, mobile ? 8.6 : 7.8);
}, { passive: false });

function updateCameraInteraction() {
  if (!pointerDown && !prefersReduced) targetRotY += 0.00052;
  group.rotation.y += (targetRotY - group.rotation.y) * 0.035;
  group.rotation.x += (targetRotX - group.rotation.x) * 0.035;
  camera.position.z += (zoomTarget - camera.position.z) * 0.045;
  camera.lookAt(0, 0, 0);
}

function resize() {
  const width = Math.max(1, stage.clientWidth);
  const height = Math.max(1, stage.clientHeight);
  const dpr = Math.min(window.devicePixelRatio || 1, mobile ? 1.25 : 1.7);
  renderer.setPixelRatio(dpr);
  renderer.setSize(width, height, false);
  camera.aspect = width / height;
  camera.updateProjectionMatrix();
}
window.addEventListener('resize', resize, { passive: true });
resize();

const cards = [...stage.querySelectorAll('[data-country-card]')];
const nodes = [...stage.querySelectorAll('[data-globe-node]')];
const svg = stage.querySelector('[data-callout-lines]');

function projectLatLon(lat, lon) {
  const local = latLon(Number(lat), Number(lon), R * 1.035);
  const world = earthGroup.localToWorld(local.clone());
  const vector = world.project(camera);
  return {
    x: (vector.x * 0.5 + 0.5) * stage.clientWidth,
    y: (-0.5 * vector.y + 0.5) * stage.clientHeight,
    z: vector.z,
    visible: vector.z < 0.96,
  };
}

function updateOverlays() {
  const lines = [];
  cards.forEach((card) => {
    const p = projectLatLon(card.dataset.lat, card.dataset.lon);
    card.style.left = `${p.x}px`;
    card.style.top = `${p.y}px`;
    card.style.opacity = p.visible ? '1' : '0';
    card.style.pointerEvents = p.visible ? 'auto' : 'none';
    lines.push(p);
  });
  nodes.forEach((node) => {
    const p = projectLatLon(node.dataset.lat, node.dataset.lon);
    node.style.left = `${p.x}px`;
    node.style.top = `${p.y}px`;
    node.style.opacity = p.visible ? '1' : '0';
  });
  if (svg) {
    svg.innerHTML = lines.map((p, i) => {
      const card = cards[i];
      if (!card || !p.visible) return '';
      const box = card.getBoundingClientRect();
      const sr = stage.getBoundingClientRect();
      const cx = box.left - sr.left + box.width / 2;
      const cy = box.top - sr.top + box.height / 2;
      return `<line x1="${p.x.toFixed(1)}" y1="${p.y.toFixed(1)}" x2="${cx.toFixed(1)}" y2="${cy.toFixed(1)}"/>`;
    }).join('');
  }
}

const clock = new THREE.Clock();
function animate() {
  requestAnimationFrame(animate);
  const dt = Math.min(clock.getDelta(), 0.05);
  updateCameraInteraction();
  if (!prefersReduced) clouds.rotation.y += dt * 0.0035;
  routes.forEach((route) => {
    route.t = (route.t + dt * route.speed) % 1;
    route.particle.position.copy(route.curve.getPointAt(route.t));
    route.secondary.forEach((particle, index) => {
      particle.position.copy(route.curve.getPointAt((route.t + (index + 1) * 0.18) % 1));
    });
  });
  movers.forEach((mover) => {
    const t = (clock.elapsedTime * mover.route.speed * 0.8 + mover.offset) % 1;
    orientMover(mover, t);
  });
  renderer.render(scene, camera);
  updateOverlays();
}

try {
  animate();
} catch (error) {
  console.error('[trade-globe] render failed:', error);
  console.error('[trade-globe] initialization failed', error);
}

const top = document.querySelector('.l-top');
window.addEventListener('scroll', () => top?.classList.toggle('is-scrolled', window.scrollY > 20), { passive: true });

const counters = [...document.querySelectorAll('[data-count]')].filter((el) => el.dataset.count);
const digits = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
const animateCount = (el) => {
  const target = Number(el.dataset.count || 0);
  const start = performance.now();
  const duration = 1100;
  const tick = (now) => {
    const p = Math.min(1, (now - start) / duration);
    const eased = 1 - Math.pow(1 - p, 3);
    el.textContent = digits(Math.round(target * eased).toLocaleString('en-US'));
    if (p < 1) requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);
};
if ('IntersectionObserver' in window) {
  const io = new IntersectionObserver((entries) => entries.forEach((entry) => {
    if (entry.isIntersecting) {
      animateCount(entry.target);
      io.unobserve(entry.target);
    }
  }), { threshold: 0.4 });
  counters.forEach((counter) => io.observe(counter));
} else counters.forEach(animateCount);

const homeSearch = document.querySelector('[data-home-search]');
homeSearch?.addEventListener('click', () => {
  const input = document.querySelector('.market-search input[name="q"]');
  document.getElementById('discover')?.scrollIntoView({ behavior: prefersReduced ? 'auto' : 'smooth', block: 'center' });
  window.setTimeout(() => input?.focus(), prefersReduced ? 0 : 450);
});
