/*
 * Trade globe for the home page hero: a real WebGL Earth (three.js) with day/night shading, atmosphere,
 * clouds, curved maritime and air routes, moving container ships and cargo aircraft, and DOM market cards
 * anchored to 3D positions. Built into public_html/assets/trade-globe.js (see build.mjs).
 *
 * Progressive enhancement: the server-rendered poster (.tg-poster) stays visible until the first frame is
 * drawn, and remains as the fallback when WebGL is unavailable or the context is lost.
 */
import {
  AdditiveBlending, BackSide, BoxGeometry, BufferAttribute, BufferGeometry, CatmullRomCurve3, Color,
  CylinderGeometry, DirectionalLight, Euler, ExtrudeGeometry, Group, HemisphereLight, MathUtils, Matrix4,
  Mesh, MeshBasicMaterial, MeshStandardMaterial, PerspectiveCamera, PlaneGeometry, Points, Quaternion,
  Raycaster, RingGeometry, Scene, Shape, ShaderMaterial, SphereGeometry, SRGBColorSpace, TextureLoader,
  TubeGeometry, Vector2, Vector3, WebGLRenderer, ACESFilmicToneMapping, DoubleSide,
} from 'three';
import { mergeGeometries } from 'three/examples/jsm/utils/BufferGeometryUtils.js';

const DEG = Math.PI / 180;
const FA = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);

/* ---------------------------------------------------------------- geography */

function ll(lat, lon, r = 1, out = new Vector3()) {
  const phi = (lon + 180) * DEG;
  const theta = (90 - lat) * DEG;
  return out.set(-r * Math.sin(theta) * Math.cos(phi), r * Math.cos(theta), r * Math.sin(theta) * Math.sin(phi));
}

/** Great-circle interpolation between waypoints, lifted to radius r, smoothed into an arc-length curve. */
function seaCurve(points, r) {
  const out = [];
  for (let i = 0; i < points.length - 1; i++) {
    const a = ll(points[i][0], points[i][1]);
    const b = ll(points[i + 1][0], points[i + 1][1]);
    const steps = Math.max(2, Math.ceil(a.angleTo(b) / (2.2 * DEG)));
    for (let s = 0; s < steps; s++) {
      const q = new Quaternion().setFromUnitVectors(a, b);
      const p = a.clone().applyQuaternion(new Quaternion().slerp(q, s / steps));
      out.push(p.multiplyScalar(r));
    }
  }
  const last = points[points.length - 1];
  out.push(ll(last[0], last[1], r));
  return new CatmullRomCurve3(out, false, 'centripetal', 0.5);
}

function airCurve(from, to) {
  const a = ll(from[0], from[1]);
  const b = ll(to[0], to[1]);
  const angle = a.angleTo(b);
  const lift = 0.05 + 0.2 * (angle / Math.PI);
  const q = new Quaternion().setFromUnitVectors(a, b);
  const pts = [];
  const n = 48;
  for (let i = 0; i <= n; i++) {
    const t = i / n;
    const p = a.clone().applyQuaternion(new Quaternion().slerp(q, t));
    pts.push(p.multiplyScalar(1.004 + lift * Math.sin(Math.PI * t)));
  }
  return new CatmullRomCurve3(pts, false, 'centripetal', 0.5);
}

const PORTS = {
  shanghai: [31.0, 122.6], singapore: [1.2, 104.0], malacca: [4.2, 99.6], srilanka: [5.6, 80.6],
  babelmandeb: [12.6, 43.3], suez: [30.0, 32.56], portsaid: [31.6, 32.3], gibraltar: [35.95, -5.6],
  rotterdam: [51.95, 4.0], jebelali: [25.0, 55.0], hormuz: [26.5, 56.4], mumbai: [18.9, 72.7],
  bandarabbas: [27.05, 56.25], newyork: [40.4, -73.7], losangeles: [33.6, -118.4], santos: [-24.1, -46.2],
  panama: [9.1, -79.7],
};

const ASIA_TO_SRILANKA = [PORTS.shanghai, [27.5, 122.0], [24.0, 119.6], [21.5, 116.5], [16.0, 112.8], [10.0, 109.5],
  [5.0, 106.0], [2.2, 105.0], PORTS.singapore, [2.6, 101.6], PORTS.malacca, [6.2, 95.2], [6.0, 88.0], PORTS.srilanka];
const SRILANKA_TO_SUEZ = [[8.5, 74.0], [12.0, 60.0], [12.2, 50.0], PORTS.babelmandeb, [16.5, 41.0], [21.0, 38.0],
  [26.0, 35.2], [28.3, 33.3], PORTS.suez, PORTS.portsaid];
const MED_TO_ROTTERDAM = [[33.3, 28.0], [35.0, 20.0], [36.8, 12.5], [37.6, 7.0], [36.6, 0.0], PORTS.gibraltar, [36.6, -9.6],
  [42.0, -10.2], [46.0, -7.5], [48.6, -5.3], [49.9, -2.0], [50.9, 1.4], [51.6, 2.8], PORTS.rotterdam];

const SEA_ROUTES = [
  { id: 'cn-eu', name: 'شانگهای ← روتردام', via: 'تنگه مالاکا · کانال سوئز', pts: [...ASIA_TO_SRILANKA, ...SRILANKA_TO_SUEZ, ...MED_TO_ROTTERDAM] },
  { id: 'cn-me', name: 'شانگهای ← جبل‌علی', via: 'تنگه مالاکا · تنگه هرمز', pts: [...ASIA_TO_SRILANKA, [10.0, 70.0], [17.5, 62.0], [23.0, 60.0], [25.6, 57.5], PORTS.hormuz, [26.1, 55.6], PORTS.jebelali] },
  { id: 'in-me', name: 'بمبئی ← بندرعباس', via: 'دریای عرب · تنگه هرمز', pts: [PORTS.mumbai, [19.5, 69.0], [21.8, 64.0], [24.4, 59.6], [25.9, 57.2], PORTS.bandarabbas] },
  { id: 'us-eu', name: 'نیویورک ← روتردام', via: 'اقیانوس اطلس شمالی', pts: [PORTS.newyork, [40.2, -69.0], [41.5, -55.0], [45.0, -38.0], [47.8, -20.0], [49.0, -8.0], [49.9, -2.0], [50.9, 1.4], [51.6, 2.8], PORTS.rotterdam] },
  { id: 'cn-us', name: 'شانگهای ← لس‌آنجلس', via: 'اقیانوس آرام', pts: [PORTS.shanghai, [31.5, 130.0], [33.5, 140.0], [38.0, 155.0], [42.0, 172.0], [43.0, -170.0], [41.0, -152.0], [37.0, -133.0], PORTS.losangeles] },
  { id: 'br-eu', name: 'سانتوس ← روتردام', via: 'اقیانوس اطلس', pts: [PORTS.santos, [-23.0, -41.0], [-14.0, -35.0], [-5.0, -32.5], [5.0, -26.0], [15.0, -22.0], [27.0, -17.0], [36.6, -11.0], [42.0, -10.2], [46.0, -7.5], [48.6, -5.3], [49.9, -2.0], [50.9, 1.4], [51.6, 2.8], PORTS.rotterdam] },
  { id: 'us-pa', name: 'نیویورک ← لس‌آنجلس', via: 'کانال پاناما', pts: [PORTS.newyork, [35.0, -73.5], [27.0, -75.5], [20.5, -73.8], [15.0, -76.5], [9.5, -79.9], PORTS.panama, [7.8, -80.5], [8.0, -84.0], [12.0, -92.0], [17.0, -102.0], [23.0, -110.5], [29.0, -116.0], PORTS.losangeles] },
];

// Road corridors (lat, lon waypoints over land): Iran → Iraq, Afghanistan, Turkey.
const LAND_ROUTES = [
  { id: 'ir-iq', name: 'تهران ← بغداد', via: 'مرز زمینی مهران / خسروی', pts: [[35.7, 51.4], [34.8, 48.5], [34.3, 47.1], [33.9, 46.0], [33.3, 44.4]] },
  { id: 'ir-af', name: 'مشهد ← هرات ← کابل', via: 'مرز زمینی دوغارون', pts: [[36.3, 59.6], [35.3, 60.6], [34.35, 62.2], [34.0, 64.5], [34.3, 67.0], [34.5, 69.2]] },
  { id: 'ir-tr', name: 'تهران ← آنکارا', via: 'مرز زمینی بازرگان', pts: [[35.7, 51.4], [36.7, 48.5], [38.1, 46.3], [39.4, 44.4], [39.9, 41.3], [39.75, 37.0], [39.9, 32.9]] },
];

// Iran → Africa: by sea from Bandar Abbas to an African port, then by road (green, with trucks) inland.
// Plus Africa → North America by sea.
const IR_GULF_OUT = [PORTS.bandarabbas, [26.4, 56.7], [24.6, 58.9], [22.0, 60.6], [17.0, 57.5], [12.5, 53.0]];
const AFRICA_SEA = [
  { id: 'ir-ke', name: 'بندرعباس ← مومباسا (کنیا)', via: 'دریای عرب · اقیانوس هند', pts: [...IR_GULF_OUT, [6.0, 50.5], [0.0, 46.0], [-3.2, 41.0], [-4.05, 39.75]] },
  { id: 'ir-tz', name: 'بندرعباس ← دارالسلام (تانزانیا)', via: 'اقیانوس هند', pts: [...IR_GULF_OUT, [6.0, 51.5], [-1.0, 47.0], [-5.0, 41.5], [-6.8, 39.5]] },
  { id: 'ir-za', name: 'بندرعباس ← دوربان (آفریقای جنوبی)', via: 'کانال موزامبیک', pts: [...IR_GULF_OUT, [5.0, 52.0], [-4.0, 45.0], [-11.0, 42.2], [-16.0, 41.0], [-21.0, 37.8], [-26.0, 35.2], [-29.9, 31.3]] },
  { id: 'ir-ng', name: 'بندرعباس ← لاگوس (نیجریه)', via: 'کانال سوئز · تنگه جبل‌الطارق', pts: [...IR_GULF_OUT, PORTS.babelmandeb, [16.5, 41.0], [21.0, 38.0], [26.0, 35.2], [28.3, 33.3], PORTS.suez, PORTS.portsaid, [33.3, 28.0], [35.0, 20.0], [36.8, 12.5], [37.6, 7.0], [36.6, 0.0], PORTS.gibraltar, [33.0, -10.0], [25.0, -17.5], [15.0, -19.0], [8.0, -15.0], [4.4, -8.0], [4.2, -2.0], [5.8, 2.0], [6.35, 3.35]] },
  { id: 'ng-us', name: 'لاگوس ← نیویورک (آمریکا)', via: 'اقیانوس اطلس', pts: [[6.35, 3.35], [4.0, -2.0], [5.0, -15.0], [12.0, -30.0], [24.0, -50.0], [33.0, -64.0], [38.5, -71.0], PORTS.newyork] },
  { id: 'za-ca', name: 'دوربان ← هالیفاکس (کانادا)', via: 'دماغه امید نیک · اقیانوس اطلس', pts: [[-29.9, 31.3], [-34.0, 27.0], [-35.2, 20.0], [-33.0, 16.0], [-24.0, 6.0], [-8.0, -15.0], [10.0, -32.0], [28.0, -48.0], [40.0, -58.0], [44.6, -63.5]] },
];
const AFRICA_LAND = [
  { id: 'ke-land', name: 'مومباسا ← نایروبی ← کامپالا', via: 'حمل زمینی با تریلی (کنیا · اوگاندا)', pts: [[-4.05, 39.67], [-3.4, 38.6], [-2.5, 37.9], [-1.29, 36.82], [-0.3, 35.3], [0.35, 32.58]] },
  { id: 'tz-land', name: 'دارالسلام ← دودوما ← موانزا', via: 'حمل زمینی با تریلی (تانزانیا)', pts: [[-6.8, 39.28], [-6.6, 37.7], [-6.17, 35.74], [-4.9, 34.1], [-2.52, 32.9]] },
  { id: 'za-land', name: 'دوربان ← ژوهانسبورگ', via: 'حمل زمینی با تریلی (آفریقای جنوبی)', pts: [[-29.86, 31.02], [-29.6, 30.4], [-28.6, 29.6], [-27.4, 28.9], [-26.2, 28.05]] },
  { id: 'ng-land', name: 'لاگوس ← ابوجا ← کانو', via: 'حمل زمینی با تریلی (نیجریه)', pts: [[6.45, 3.39], [7.4, 3.9], [8.5, 4.55], [9.06, 7.49], [10.5, 7.44], [12.0, 8.52]] },
];

const CITIES = {
  tehran: [35.7, 51.4, 'تهران'], istanbul: [41.0, 28.9, 'استانبول'], dubai: [25.25, 55.3, 'دبی'], frankfurt: [50.1, 8.7, 'فرانکفورت'],
  beijing: [39.9, 116.4, 'پکن'], moscow: [55.75, 37.6, 'مسکو'], delhi: [28.6, 77.2, 'دهلی'], newyork: [40.7, -74.0, 'نیویورک'],
  london: [51.5, -0.1, 'لندن'], tokyo: [35.7, 139.7, 'توکیو'], losangeles: [34.0, -118.2, 'لس‌آنجلس'], saopaulo: [-23.5, -46.6, 'سائوپائولو'],
  madrid: [40.4, -3.7, 'مادرید'], shanghai: [31.2, 121.5, 'شانگهای'], mumbai: [19.0, 72.8, 'بمبئی'], singapore: [1.3, 103.8, 'سنگاپور'],
  rotterdam: [51.9, 4.5, 'روتردام'], santos: [-23.9, -46.3, 'سانتوس'], bandarabbas: [27.2, 56.3, 'بندرعباس'],
};

const AIR_ROUTES = [
  ['tehran', 'istanbul'], ['dubai', 'frankfurt'], ['beijing', 'moscow'], ['delhi', 'dubai'], ['newyork', 'london'],
  ['tokyo', 'losangeles'], ['saopaulo', 'madrid'], ['shanghai', 'tehran'], ['tehran', 'moscow'], ['istanbul', 'frankfurt'],
];

const CHOKEPOINTS = [
  { name: 'کانال سوئز', at: [30.4, 32.4] },
  { name: 'تنگه مالاکا', at: [3.2, 100.6] },
  { name: 'کانال پاناما', at: [9.1, -79.7] },
];

/* ------------------------------------------------------------------ shaders */

const EARTH_VS = /* glsl */`
varying vec2 vUv; varying vec3 vN; varying vec3 vP;
void main() {
  vUv = uv;
  vN = normalize(mat3(modelMatrix) * normal);
  vec4 wp = modelMatrix * vec4(position, 1.0);
  vP = wp.xyz;
  gl_Position = projectionMatrix * viewMatrix * wp;
}`;

const EARTH_FS = /* glsl */`
uniform sampler2D uDay; uniform sampler2D uNight; uniform sampler2D uWater; uniform vec3 uSun; uniform float uReveal;
varying vec2 vUv; varying vec3 vN; varying vec3 vP;
void main() {
  vec3 n = normalize(vN);
  vec3 v = normalize(cameraPosition - vP);
  float ndl = dot(n, uSun);
  float day = smoothstep(-0.25, 0.45, ndl);
  vec3 dayC = texture2D(uDay, vUv).rgb;
  float lum = dot(dayC, vec3(0.299, 0.587, 0.114));
  dayC = mix(vec3(lum), dayC, 0.95) * vec3(0.66, 0.8, 1.0);
  dayC = pow(dayC, vec3(1.35));
  float water = texture2D(uWater, vUv).r;
  dayC = mix(dayC, vec3(0.01, 0.075, 0.2), water * 0.6);
  vec3 h = normalize(uSun + v);
  float spec = pow(max(dot(n, h), 0.0), 90.0) * water;
  vec3 nightC = texture2D(uNight, vUv).rgb;
  nightC = pow(nightC, vec3(1.35)) * vec3(3.6, 2.4, 0.95);
  vec3 col = dayC * (0.1 + 0.85 * max(ndl, 0.0)) * mix(0.3, 1.0, day);
  col += spec * vec3(0.35, 0.6, 0.9) * day * 0.28;
  col += nightC * mix(1.0, 0.4, day);
  float fr = pow(1.0 - max(dot(n, v), 0.0), 2.6);
  col += vec3(0.05, 0.45, 1.0) * fr * (0.4 + 0.45 * day);
  gl_FragColor = vec4(col * uReveal, 1.0);
  #include <tonemapping_fragment>
  #include <colorspace_fragment>
}`;

const ATMO_VS = /* glsl */`
varying vec3 vN; varying vec3 vV;
void main() {
  vN = normalize(normalMatrix * normal);
  vec4 mv = modelViewMatrix * vec4(position, 1.0);
  vV = normalize(-mv.xyz);
  gl_Position = projectionMatrix * mv;
}`;

const ATMO_FS = /* glsl */`
uniform vec3 uColor; uniform float uPower; uniform float uStrength;
varying vec3 vN; varying vec3 vV;
void main() {
  float d = dot(normalize(vN), normalize(vV));
  float i = pow(clamp(-d * 1.95, 0.0, 1.0), uPower) * uStrength;
  gl_FragColor = vec4(uColor * i, i);
}`;

const CLOUD_FS = /* glsl */`
uniform sampler2D uClouds; uniform vec3 uSun;
varying vec2 vUv; varying vec3 vN; varying vec3 vP;
void main() {
  float c = texture2D(uClouds, vUv).r;
  float ndl = dot(normalize(vN), uSun);
  float lit = smoothstep(-0.2, 0.5, ndl);
  gl_FragColor = vec4(vec3(0.72, 0.84, 1.0) * (0.25 + 0.75 * lit), c * (0.12 + 0.3 * lit));
}`;

const ROUTE_VS = /* glsl */`
varying vec2 vUv;
void main() { vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }`;

const ROUTE_FS = /* glsl */`
uniform vec3 uColor; uniform float uTime; uniform float uSpeed; uniform float uRepeat; uniform float uBase; uniform float uHi; uniform float uReveal;
varying vec2 vUv;
void main() {
  float f = fract(vUv.x * uRepeat - uTime * uSpeed);
  float dash = smoothstep(0.0, 0.08, f) * (1.0 - smoothstep(0.32, 0.55, f));
  float ends = smoothstep(0.0, 0.03, vUv.x) * (1.0 - smoothstep(0.97, 1.0, vUv.x));
  float a = (uBase + dash * 0.6) * ends * (1.0 + uHi * 1.6) * uReveal;
  gl_FragColor = vec4(uColor * a, a);
}`;

const POINT_VS = /* glsl */`
attribute vec3 color; attribute float size; attribute float phase;
uniform float uTime; uniform float uScale;
varying vec3 vColor; varying float vPulse;
void main() {
  vColor = color;
  vPulse = 0.75 + 0.25 * sin(uTime * 2.2 + phase);
  vec4 mv = modelViewMatrix * vec4(position, 1.0);
  gl_PointSize = size * vPulse * uScale / -mv.z;
  gl_Position = projectionMatrix * mv;
}`;

const POINT_FS = /* glsl */`
uniform float uReveal;
varying vec3 vColor; varying float vPulse;
void main() {
  vec2 c = gl_PointCoord - 0.5;
  float d = length(c) * 2.0;
  float core = 1.0 - smoothstep(0.0, 0.28, d);
  float glow = pow(1.0 - clamp(d, 0.0, 1.0), 2.2);
  float a = (core + glow * 0.6) * uReveal;
  gl_FragColor = vec4(mix(vColor, vec3(1.0), core * 0.6) * a, a);
}`;

const WAKE_FS = /* glsl */`
uniform float uTime; uniform float uAlpha;
varying vec2 vUv;
void main() {
  float along = vUv.y;
  float edge = abs(vUv.x - 0.5) * 2.0;
  float w = 0.22 + 0.78 * along;
  float body = 1.0 - smoothstep(w * 0.55, w, edge);
  float rim = smoothstep(w * 0.45, w * 0.8, edge) * body;
  float foam = 0.75 + 0.25 * sin(along * 38.0 - uTime * 7.0);
  float a = pow(1.0 - along, 1.6) * (body * 0.35 + rim * 0.9) * foam * uAlpha;
  gl_FragColor = vec4(vec3(0.7, 0.93, 1.0) * a, a);
}`;

/* ------------------------------------------------------------------ models */

function boxAt(w, h, d, x, y, z, color) {
  const g = new BoxGeometry(w, h, d).toNonIndexed();
  g.translate(x, y, z);
  g.deleteAttribute('uv');
  const c = new Color(color);
  const n = g.attributes.position.count;
  const arr = new Float32Array(n * 3);
  for (let i = 0; i < n; i++) { arr[i * 3] = c.r; arr[i * 3 + 1] = c.g; arr[i * 3 + 2] = c.b; }
  g.setAttribute('color', new BufferAttribute(arr, 3));
  return g;
}

function hullGeometry(width, length, depth, y0, color) {
  const s = new Shape();
  const hw = width / 2;
  const hl = length / 2;
  s.moveTo(-hw * 0.92, -hl);
  s.lineTo(hw * 0.92, -hl);
  s.quadraticCurveTo(hw, -hl, hw, -hl + 0.05);
  s.lineTo(hw, hl * 0.42);
  s.quadraticCurveTo(hw * 0.95, hl * 0.86, 0, hl);
  s.quadraticCurveTo(-hw * 0.95, hl * 0.86, -hw, hl * 0.42);
  s.lineTo(-hw, -hl + 0.05);
  s.quadraticCurveTo(-hw, -hl, -hw * 0.92, -hl);
  const g = new ExtrudeGeometry(s, { depth, bevelEnabled: false, curveSegments: 6 });
  g.rotateX(Math.PI / 2); // shape y → +z (bow forward), extrusion → -y
  g.translate(0, y0 + depth, 0);
  g.deleteAttribute('uv');
  const c = new Color(color);
  const n = g.attributes.position.count;
  const arr = new Float32Array(n * 3);
  for (let i = 0; i < n; i++) { arr[i * 3] = c.r; arr[i * 3 + 1] = c.g; arr[i * 3 + 2] = c.b; }
  g.setAttribute('color', new BufferAttribute(arr, 3));
  return g;
}

const CONTAINER_COLORS = ['#C99A3E', '#1E7FC4', '#A8383A', '#D9DEE6', '#2D7A5A', '#D0702E', '#3B4F8C', '#E3B85A'];

/** Container ship, length 1 along +z, up +y, waterline at y = 0. */
function makeShip(seed) {
  const rand = mulberry(seed);
  const parts = [
    hullGeometry(0.17, 1.0, 0.045, -0.04, '#7E2A2C'),
    hullGeometry(0.172, 0.99, 0.06, 0.005, '#14243F'),
    boxAt(0.15, 0.006, 0.86, 0, 0.066, -0.02, '#2A3550'),
  ];
  const bays = 8;
  for (let b = 0; b < bays; b++) {
    const z = 0.31 - b * 0.078;
    for (let col = 0; col < 3; col++) {
      const tiers = 1 + Math.floor(rand() * 3) + (b > 0 && b < bays - 1 ? 1 : 0);
      for (let t = 0; t < tiers; t++) {
        const color = CONTAINER_COLORS[Math.floor(rand() * CONTAINER_COLORS.length)];
        parts.push(boxAt(0.045, 0.026, 0.07, (col - 1) * 0.048, 0.083 + t * 0.028, z, color));
      }
    }
  }
  // Superstructure (bridge) at the stern, funnel and masts
  parts.push(boxAt(0.15, 0.1, 0.07, 0, 0.12, -0.38, '#E8ECF2'));
  parts.push(boxAt(0.19, 0.012, 0.05, 0, 0.165, -0.365, '#D5DAE3'));
  parts.push(boxAt(0.152, 0.018, 0.072, 0, 0.145, -0.38, '#0B1426'));
  parts.push(boxAt(0.045, 0.07, 0.04, 0, 0.15, -0.445, '#1B2438'));
  parts.push(boxAt(0.047, 0.014, 0.042, 0, 0.17, -0.445, '#B43A33'));
  parts.push(boxAt(0.006, 0.09, 0.006, 0, 0.215, -0.37, '#C9D2DE'));
  parts.push(boxAt(0.006, 0.06, 0.006, 0, 0.1, 0.42, '#C9D2DE'));
  const geo = mergeGeometries(parts);
  const mesh = new Mesh(geo, new MeshStandardMaterial({ vertexColors: true, roughness: 0.55, metalness: 0.25, emissive: new Color('#1a3355'), emissiveIntensity: 0.5 }));
  const group = new Group();
  group.add(mesh);

  // Lit bridge windows
  const windows = new Mesh(new BoxGeometry(0.154, 0.008, 0.074), new MeshBasicMaterial({ color: '#FFD978' }));
  windows.position.set(0, 0.145, -0.38);
  group.add(windows);

  // Navigation lights: port red, starboard green, masthead white
  const lights = pointCloud([
    [-0.1, 0.17, -0.36, '#FF3B3B', 30], [0.1, 0.17, -0.36, '#36F58A', 30],
    [0, 0.26, -0.37, '#FFFFFF', 34], [0, 0.13, 0.42, '#FFFFFF', 26],
  ]);
  group.add(lights);

  // Wake trailing behind the stern
  const wake = new Mesh(new PlaneGeometry(0.34, 1.5), new ShaderMaterial({
    vertexShader: ROUTE_VS, fragmentShader: WAKE_FS, transparent: true, depthWrite: false, blending: AdditiveBlending,
    uniforms: { uTime: { value: 0 }, uAlpha: { value: 1 } }, side: DoubleSide,
  }));
  wake.geometry.rotateX(-Math.PI / 2);
  wake.geometry.translate(0, 0.004, -0.5 - 0.72);
  // flip uv.y so 0 is at the stern
  const uv = wake.geometry.attributes.uv;
  for (let i = 0; i < uv.count; i++) uv.setY(i, 1 - uv.getY(i));
  group.add(wake);

  // Soft cyan glow under the hull
  const glow = new Mesh(new PlaneGeometry(0.7, 1.7), new ShaderMaterial({
    vertexShader: ROUTE_VS, transparent: true, depthWrite: false, blending: AdditiveBlending,
    fragmentShader: `varying vec2 vUv; uniform float uAlpha; void main(){ vec2 c=(vUv-0.5)*2.0; float a=pow(max(1.0-dot(c,c),0.0),1.6)*0.55*uAlpha; gl_FragColor=vec4(vec3(0.15,0.6,1.0)*a,a);}`,
    uniforms: { uAlpha: { value: 1 } },
  }));
  glow.geometry.rotateX(-Math.PI / 2);
  glow.geometry.translate(0, 0.002, -0.05);
  group.add(glow);

  // Invisible hit volume for hover
  const hit = new Mesh(new SphereGeometry(0.75, 8, 6), new MeshBasicMaterial({ visible: false }));
  group.add(hit);

  return { group, wake, glow, lights, hit };
}

/** Semi-trailer truck, length 1 along +z (cab forward), wheels on y = 0. */
function makeTruck() {
  const parts = [
    boxAt(0.2, 0.2, 0.66, 0, 0.17, -0.12, '#E9EEF5'),
    boxAt(0.204, 0.04, 0.6, 0, 0.13, -0.12, '#16C784'),
    boxAt(0.2, 0.2, 0.22, 0, 0.17, 0.36, '#0F7A52'),
    boxAt(0.18, 0.07, 0.04, 0, 0.22, 0.47, '#9fe6c6'),
    boxAt(0.22, 0.05, 0.92, 0, 0.06, 0.02, '#1B2438'),
  ];
  [-0.36, -0.24, 0.1, 0.38].forEach((z) => {
    parts.push(boxAt(0.04, 0.07, 0.08, -0.11, 0.035, z, '#0B0F18'));
    parts.push(boxAt(0.04, 0.07, 0.08, 0.11, 0.035, z, '#0B0F18'));
  });
  const mesh = new Mesh(mergeGeometries(parts), new MeshStandardMaterial({ vertexColors: true, roughness: 0.5, metalness: 0.2, emissive: new Color('#12324a'), emissiveIntensity: 0.6 }));
  const group = new Group();
  group.add(mesh);
  group.add(pointCloud([[-0.07, 0.12, 0.48, '#FFFFFF', 26], [0.07, 0.12, 0.48, '#FFFFFF', 26], [-0.09, 0.1, -0.46, '#FF3B3B', 22], [0.09, 0.1, -0.46, '#FF3B3B', 22]]));
  const hit = new Mesh(new SphereGeometry(0.7, 8, 6), new MeshBasicMaterial({ visible: false }));
  group.add(hit);
  return { group, hit };
}

/** Cargo aircraft, nose along +z. */
function makePlane() {
  const parts = [];
  const body = new CylinderGeometry(0.05, 0.04, 0.8, 10);
  body.rotateX(Math.PI / 2);
  parts.push(colorize(body, '#E9EEF5'));
  const nose = new SphereGeometry(0.05, 10, 8);
  nose.scale(1, 1, 1.8);
  nose.translate(0, 0, 0.4);
  parts.push(colorize(nose, '#E9EEF5'));
  parts.push(boxAt(0.95, 0.018, 0.16, 0, 0, 0.02, '#D3DAE5'));
  parts.push(boxAt(0.34, 0.014, 0.09, 0, 0.01, -0.36, '#D3DAE5'));
  parts.push(boxAt(0.014, 0.16, 0.12, 0, 0.08, -0.35, '#C99A3E'));
  parts.push(boxAt(0.07, 0.06, 0.14, 0.2, -0.04, 0.06, '#9AA6B8'));
  parts.push(boxAt(0.07, 0.06, 0.14, -0.2, -0.04, 0.06, '#9AA6B8'));
  const mesh = new Mesh(mergeGeometries(parts), new MeshStandardMaterial({ vertexColors: true, roughness: 0.4, metalness: 0.35, emissive: new Color('#1b3050'), emissiveIntensity: 0.8 }));
  const group = new Group();
  group.add(mesh);
  const lights = pointCloud([[-0.48, 0, 0.02, '#FF3B3B', 30], [0.48, 0, 0.02, '#36F58A', 30], [0, 0.17, -0.4, '#FFFFFF', 30]]);
  group.add(lights);
  const hit = new Mesh(new SphereGeometry(0.7, 8, 6), new MeshBasicMaterial({ visible: false }));
  group.add(hit);
  return { group, lights, hit };
}

function colorize(g, color) {
  if (g.index) g = g.toNonIndexed();
  const c = new Color(color);
  const n = g.attributes.position.count;
  const arr = new Float32Array(n * 3);
  for (let i = 0; i < n; i++) { arr[i * 3] = c.r; arr[i * 3 + 1] = c.g; arr[i * 3 + 2] = c.b; }
  g.setAttribute('color', new BufferAttribute(arr, 3));
  g.deleteAttribute('uv');
  return g;
}

const pointMaterials = [];
let PX = 1;
function pointMaterial(scale = 1) {
  const m = new ShaderMaterial({
    vertexShader: POINT_VS, fragmentShader: POINT_FS, transparent: true, depthWrite: false, blending: AdditiveBlending,
    uniforms: { uTime: { value: 0 }, uScale: { value: scale * PX }, uReveal: { value: 0 } },
  });
  pointMaterials.push(m);
  return m;
}

function pointCloud(list, scale = 0.5) {
  const g = new BufferGeometry();
  const pos = new Float32Array(list.length * 3);
  const col = new Float32Array(list.length * 3);
  const size = new Float32Array(list.length);
  const phase = new Float32Array(list.length);
  list.forEach(([x, y, z, c, s], i) => {
    pos.set([x, y, z], i * 3);
    const cc = new Color(c);
    col.set([cc.r, cc.g, cc.b], i * 3);
    size[i] = s;
    phase[i] = i * 1.7;
  });
  g.setAttribute('position', new BufferAttribute(pos, 3));
  g.setAttribute('color', new BufferAttribute(col, 3));
  g.setAttribute('size', new BufferAttribute(size, 1));
  g.setAttribute('phase', new BufferAttribute(phase, 1));
  return new Points(g, pointMaterial(scale));
}

function mulberry(a) {
  return function () {
    a |= 0; a = (a + 0x6D2B79F5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/* ------------------------------------------------------------------- scene */

function webglAvailable() {
  try {
    // three.js r163+ renders with WebGL 2 only. Many TV / set-top-box GPUs (and their browsers) expose WebGL 1
    // alone; they get the static poster globe instead of a black stage.
    const c = document.createElement('canvas');
    return !!(window.WebGL2RenderingContext && c.getContext('webgl2'));
  } catch (e) {
    return false;
  }
}

function init(root) {
  const stage = root.querySelector('.tg-stage');
  if (!stage || !webglAvailable()) {
    root.classList.add('tg-fallback');
    return;
  }
  const texBase = root.getAttribute('data-tex') || '/assets/globe/';
  const coarse = window.matchMedia('(pointer: coarse)').matches;
  const small = Math.min(window.innerWidth, window.innerHeight) < 700;
  const weak = small || (navigator.hardwareConcurrency || 8) <= 4 || (navigator.deviceMemory || 8) <= 4;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // data-mode="login": a calmer scene (fewer routes, 2–3 ships, 1–2 planes) for the sign-in page.
  const lite = root.getAttribute('data-mode') === 'login';
  const Q = weak
    ? { seg: 72, dpr: 1.5, clouds: false, tex: '1k', air: 4, ships: 5, planes: 3, tubeSeg: 220 }
    : { seg: 128, dpr: 2, clouds: true, tex: '2k', air: AIR_ROUTES.length, ships: 13, planes: 6, tubeSeg: 420 };
  if (lite) {
    Q.ships = weak ? 2 : 3;
    Q.planes = weak ? 1 : 2;
  }
  const SEA_LIST = lite ? SEA_ROUTES.filter((r) => ['cn-eu', 'cn-me', 'in-me'].includes(r.id)) : [...SEA_ROUTES, ...(weak ? AFRICA_SEA.slice(0, 3) : AFRICA_SEA)];
  const LAND_LIST = lite ? LAND_ROUTES : [...LAND_ROUTES, ...AFRICA_LAND];
  const AIR_LIST = lite ? [['tehran', 'dubai'], ['dubai', 'delhi'], ['istanbul', 'frankfurt']] : AIR_ROUTES.slice(0, Q.air);

  let renderer;
  try {
    renderer = new WebGLRenderer({ antialias: !weak, alpha: true, powerPreference: 'high-performance' });
  } catch (e) {
    root.classList.add('tg-fallback');
    return;
  }
  // Any failure after this point (shader that does not compile on this GPU, a first frame that draws nothing,
  // a lost context) drops back to the poster globe — never a black hero.
  let dead = false;
  const fail = (why) => {
    if (dead) return;
    dead = true;
    root.classList.remove('tg-ready');
    root.classList.add('tg-fallback');
    try { renderer.domElement.remove(); renderer.dispose(); } catch (e) { /* ignore */ }
    window.__tgFail = why;
    if (window.console) console.warn('trade-globe: falling back to the poster:', why);
  };
  renderer.debug.onShaderError = () => fail('shader compile error');
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, Q.dpr));
  PX = renderer.getPixelRatio();
  renderer.outputColorSpace = SRGBColorSpace;
  renderer.toneMapping = ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.0;
  renderer.setClearColor(0x000000, 0);
  const canvas = renderer.domElement;
  canvas.className = 'tg-canvas';
  canvas.setAttribute('aria-hidden', 'true');
  stage.appendChild(canvas);

  const scene = new Scene();
  const camera = new PerspectiveCamera(30, 1, 0.1, 100);
  const sun = new Vector3(-0.78, 0.38, 0.5).normalize();

  scene.add(new HemisphereLight(0xbfe2ff, 0x1a2a44, 2.4));
  const dir = new DirectionalLight(0xfff1d6, 2.6);
  dir.position.copy(sun).multiplyScalar(10);
  scene.add(dir);

  // Background stars (fixed to the camera space, not to the globe)
  const starList = [];
  const sr = mulberry(7);
  for (let i = 0; i < (weak ? 260 : 520); i++) {
    const v = new Vector3(sr() * 2 - 1, sr() * 2 - 1, sr() * 2 - 1).normalize().multiplyScalar(30 + sr() * 10);
    if (v.z > 8) v.z = -v.z;
    starList.push([v.x, v.y, v.z, sr() > 0.85 ? '#FFD978' : '#9CCBFF', 18 + sr() * 30]);
  }
  const stars = pointCloud(starList, 2.6);
  scene.add(stars);

  const world = new Group();
  world.rotation.order = 'XYZ';
  scene.add(world);

  const loader = new TextureLoader();
  const tex = (name, onLoad) => {
    const t = loader.load(texBase + name, onLoad);
    t.anisotropy = Math.min(8, renderer.capabilities.getMaxAnisotropy());
    return t;
  };
  let started = false;
  const start = () => { if (!started) { started = true; setRunning(true); } };
  const dayTex = tex(`earth-day-${Q.tex}.webp`, () => start());
  dayTex.colorSpace = SRGBColorSpace;
  const nightTex = tex(`earth-night-${Q.tex}.webp`);
  nightTex.colorSpace = SRGBColorSpace;
  const waterTex = tex('earth-water-1k.webp');

  const reveal = { value: 0 };
  const earthMat = new ShaderMaterial({
    vertexShader: EARTH_VS, fragmentShader: EARTH_FS,
    uniforms: { uDay: { value: dayTex }, uNight: { value: nightTex }, uWater: { value: waterTex }, uSun: { value: sun }, uReveal: reveal },
  });
  const earth = new Mesh(new SphereGeometry(1, Q.seg, Q.seg / 2), earthMat);
  world.add(earth);

  let clouds = null;
  if (Q.clouds) {
    const ct = tex('earth-clouds-1k.webp');
    clouds = new Mesh(new SphereGeometry(1.012, Q.seg, Q.seg / 2), new ShaderMaterial({
      vertexShader: EARTH_VS, fragmentShader: CLOUD_FS, transparent: true, depthWrite: false,
      uniforms: { uClouds: { value: ct }, uSun: { value: sun } },
    }));
    world.add(clouds);
  }

  const atmoMat = (color, power, strength, side) => new ShaderMaterial({
    vertexShader: ATMO_VS, fragmentShader: ATMO_FS, transparent: true, depthWrite: false, blending: AdditiveBlending, side,
    uniforms: { uColor: { value: new Color(color) }, uPower: { value: power }, uStrength: { value: strength } },
  });
  const halo = new Mesh(new SphereGeometry(1.16, 64, 32), atmoMat('#1E9BFF', 3.2, 0.75, BackSide));
  scene.add(halo);

  // ---- routes
  const routeMeshes = [];
  const hitTargets = [];
  const seaColor = new Color('#1FB8FF');
  const airColor = new Color('#7FE3FF');
  const landColor = new Color('#16C784');
  const goldColor = new Color('#F5C65D');

  function addRoute(curve, kind, info) {
    const radius = kind === 'air' ? 0.0015 : kind === 'land' ? 0.003 : 0.0022;
    const segs = kind === 'air' ? Math.round(Q.tubeSeg * 0.35) : kind === 'land' ? Math.round(Q.tubeSeg * 0.5) : Q.tubeSeg;
    const len = curve.getLength();
    const mat = new ShaderMaterial({
      vertexShader: ROUTE_VS, fragmentShader: ROUTE_FS, transparent: true, depthWrite: false, blending: AdditiveBlending,
      uniforms: {
        uColor: { value: kind === 'sea' ? seaColor : kind === 'land' ? landColor : airColor }, uTime: { value: 0 },
        uSpeed: { value: kind === 'air' ? 0.6 : 0.35 }, uRepeat: { value: Math.max(4, len * (kind === 'air' ? 10 : kind === 'land' ? 40 : 16)) },
        uBase: { value: kind === 'air' ? 0.32 : kind === 'land' ? 0.8 : 0.55 }, uHi: { value: 0 }, uReveal: reveal,
      },
    });
    const line = new Mesh(new TubeGeometry(curve, segs, radius, 5, false), mat);
    world.add(line);
    const glowMat = mat.clone();
    glowMat.uniforms = { ...mat.uniforms, uBase: { value: 0.1 }, uRepeat: mat.uniforms.uRepeat, uTime: mat.uniforms.uTime, uHi: mat.uniforms.uHi };
    const glow = new Mesh(new TubeGeometry(curve, Math.round(segs / 2), radius * 2.6, 5, false), glowMat);
    world.add(glow);
    const hit = new Mesh(new TubeGeometry(curve, Math.round(segs / 3), 0.016, 4, false), new MeshBasicMaterial({ visible: false }));
    hit.userData = { type: 'route', kind, info, uniforms: mat.uniforms };
    world.add(hit);
    hitTargets.push(hit);
    const r = { curve, kind, info, uniforms: mat.uniforms, len };
    routeMeshes.push(r);
    return r;
  }

  const seaRoutes = SEA_LIST.map((r) => addRoute(seaCurve(r.pts, 1.0035), 'sea', r));
  const airRoutes = AIR_LIST.map(([a, b]) => {
    const A = CITIES[a];
    const B = CITIES[b];
    return addRoute(airCurve(A, B), 'air', { name: `${A[2]} ← ${B[2]}`, via: 'مسیر هوایی باری' });
  });
  // Land corridors from Iran to its neighbours (sign-in globe only), drawn in green with trucks on them.
  const landRoutes = LAND_LIST.map((r) => addRoute(seaCurve(r.pts, 1.003), 'land', r));

  // ---- nodes (ports, hubs) and moving trade particles
  const nodeList = Object.values(CITIES).map(([la, lo]) => {
    const p = ll(la, lo, 1.006);
    return [p.x, p.y, p.z, '#F5C65D', 34];
  });
  CHOKEPOINTS.forEach((c) => {
    const p = ll(c.at[0], c.at[1], 1.006);
    nodeList.push([p.x, p.y, p.z, '#27C7FF', 40]);
  });
  LAND_LIST.forEach((r) => {
    [r.pts[0], r.pts[r.pts.length - 1]].forEach(([la, lo]) => {
      const p = ll(la, lo, 1.006);
      nodeList.push([p.x, p.y, p.z, '#16C784', 36]);
    });
  });
  const nodes = pointCloud(nodeList, 1);
  world.add(nodes);

  // Pulsing rings at hubs
  const rings = [];
  ['tehran', 'dubai', 'shanghai', 'rotterdam', 'singapore', 'mumbai', 'istanbul', 'newyork'].forEach((k, i) => {
    const [la, lo] = CITIES[k];
    const m = new Mesh(new RingGeometry(0.75, 1, 40), new MeshBasicMaterial({ color: goldColor, transparent: true, depthWrite: false, blending: AdditiveBlending, side: DoubleSide }));
    const p = ll(la, lo, 1.004);
    m.position.copy(p);
    m.lookAt(p.clone().multiplyScalar(2));
    m.userData.phase = i * 0.37;
    world.add(m);
    rings.push(m);
  });

  const particleRoutes = [];
  routeMeshes.forEach((r, i) => {
    const count = r.kind === 'sea' ? 3 : 2;
    for (let k = 0; k < count; k++) particleRoutes.push({ r, t: (k / count + i * 0.13) % 1, v: (r.kind === 'air' ? 0.11 : r.kind === 'land' ? 0.03 : 0.05) / r.len });
  });
  const pg = new BufferGeometry();
  const pPos = new Float32Array(particleRoutes.length * 3);
  const pCol = new Float32Array(particleRoutes.length * 3);
  const pSize = new Float32Array(particleRoutes.length);
  const pPhase = new Float32Array(particleRoutes.length);
  particleRoutes.forEach((p, i) => {
    const c = p.r.kind === 'sea' ? new Color('#7FD8FF') : p.r.kind === 'land' ? new Color('#7CF2B8') : new Color('#FFE2A0');
    pCol.set([c.r, c.g, c.b], i * 3);
    pSize[i] = p.r.kind === 'sea' ? 48 : 40;
    pPhase[i] = i;
  });
  pg.setAttribute('position', new BufferAttribute(pPos, 3));
  pg.setAttribute('color', new BufferAttribute(pCol, 3));
  pg.setAttribute('size', new BufferAttribute(pSize, 1));
  pg.setAttribute('phase', new BufferAttribute(pPhase, 1));
  const particles = new Points(pg, pointMaterial(1));
  world.add(particles);

  // ---- ships
  const SHIP_PLAN = [
    { route: 0, t: 0.08, speed: 1.0, name: 'کشتی کانتینربر' },
    { route: 1, t: 0.55, speed: 1.25, name: 'کشتی کانتینربر' },
    { route: 2, t: 0.3, speed: 0.8, name: 'کشتی فله‌بر' },
    { route: 3, t: 0.4, speed: 1.1, name: 'کشتی کانتینربر' },
    { route: 5, t: 0.62, speed: 0.95, name: 'کشتی کانتینربر' },
    { route: 0, t: 0.7, speed: 1.15, name: 'کشتی کانتینربر' },
    { route: 4, t: 0.35, speed: 1.05, name: 'کشتی کانتینربر' },
    { route: 6, t: 0.5, speed: 0.9, name: 'کشتی کانتینربر' },
    { route: 7, t: 0.45, speed: 1.0, name: 'کشتی کانتینربر' },
    { route: 8, t: 0.7, speed: 0.9, name: 'کشتی فله‌بر' },
    { route: 9, t: 0.25, speed: 1.1, name: 'کشتی کانتینربر' },
    { route: 10, t: 0.55, speed: 1.2, name: 'کشتی کانتینربر' },
    { route: 11, t: 0.4, speed: 1.0, name: 'کشتی کانتینربر' },
    { route: 12, t: 0.6, speed: 0.95, name: 'کشتی کانتینربر' },
  ].filter((s) => s.route < seaRoutes.length).slice(0, Q.ships);
  const SHIP_SCALE = weak ? 0.095 : 0.085;
  const ships = SHIP_PLAN.map((s, i) => {
    const m = makeShip(101 + i * 17);
    m.group.scale.setScalar(SHIP_SCALE);
    world.add(m.group);
    const route = seaRoutes[s.route];
    m.hit.userData = { type: 'ship', info: { name: s.name, route: route.info.name, via: route.info.via, speed: FA(14 + (i * 3) % 9) } };
    hitTargets.push(m.hit);
    return { ...m, route, t: s.t, v: (0.012 * s.speed) / route.len };
  });

  const TRUCK_SCALE = weak ? 0.07 : 0.06;
  const trucks = landRoutes.map((route, i) => {
    const m = makeTruck();
    m.group.scale.setScalar(TRUCK_SCALE);
    world.add(m.group);
    m.hit.userData = { type: 'truck', info: { name: 'کامیون باری', route: route.info.name, via: route.info.via } };
    hitTargets.push(m.hit);
    return { ...m, route, t: (0.15 + i * 0.3) % 1, v: (0.006 + (i % 2) * 0.0015) / route.len };
  });

  const planes = airRoutes.slice(0, Q.planes).map((route, i) => {
    const m = makePlane();
    m.group.scale.setScalar(weak ? 0.055 : 0.048);
    world.add(m.group);
    m.hit.userData = { type: 'plane', info: { name: 'هواپیمای باری', route: route.info.name } };
    hitTargets.push(m.hit);
    return { ...m, route, t: (i * 0.29) % 1, v: (0.05 + (i % 3) * 0.008) / route.len };
  });

  // ---- DOM overlays: market cards, chokepoint labels, connector lines
  const cards = Array.from(root.querySelectorAll('.tg-card[data-lat]')).map((el) => {
    const lat = parseFloat(el.getAttribute('data-lat'));
    const lon = parseFloat(el.getAttribute('data-lon'));
    const p = ll(lat, lon, 1.006);
    const hit = new Mesh(new SphereGeometry(0.045, 8, 6), new MeshBasicMaterial({ visible: false }));
    hit.position.copy(p);
    hit.userData = { type: 'country', el };
    world.add(hit);
    hitTargets.push(hit);
    return { el, local: p, w: 0, h: 0, hover: false, priority: el.dataset.priority !== undefined ? +el.dataset.priority || 0 : el.classList.contains('is-home') ? 0 : el.classList.contains('is-secondary') ? 0.6 : 0.3, on: false, alpha: 0 };
  });
  cards.forEach((c) => {
    c.el.addEventListener('pointerenter', () => { c.hover = true; c.el.classList.add('is-hot'); });
    c.el.addEventListener('pointerleave', () => { c.hover = false; c.el.classList.remove('is-hot'); });
  });

  const labelLayer = root.querySelector('.tg-labels');
  const labels = CHOKEPOINTS.map((c) => {
    const el = document.createElement('span');
    el.className = 'tg-label';
    const icon = document.createElement('i');
    icon.setAttribute('aria-hidden', 'true');
    el.appendChild(icon);
    el.appendChild(document.createTextNode(c.name));
    if (labelLayer) labelLayer.appendChild(el);
    return { el, local: ll(c.at[0], c.at[1], 1.01) };
  });

  const svg = root.querySelector('.tg-lines');
  const lines = cards.map(() => {
    const l = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    const d = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    d.setAttribute('r', '3');
    if (svg) { svg.appendChild(l); svg.appendChild(d); }
    return { l, d };
  });

  // Live counters describing the visualisation itself
  const setLive = (key, n) => root.querySelectorAll(`[data-live="${key}"]`).forEach((el) => { el.textContent = FA(n); });
  setLive('sea', seaRoutes.length);
  setLive('air', airRoutes.length);
  setLive('ships', ships.length + planes.length);
  setLive('nodes', Object.keys(CITIES).length);

  const tip = root.querySelector('.tg-tip');

  // ---- layout
  let W = 1;
  let H = 1;
  let gx = 0.5;
  let gy = 0.5;
  let rPx = 300;
  let safeTop = 6;
  const view = { zoom: 1, zoomTarget: 1, scroll: 0 };

  function readLayout() {
    const cs = getComputedStyle(stage);
    const num = (v, d) => { const n = parseFloat(cs.getPropertyValue(v)); return Number.isFinite(n) ? n : d; };
    gx = num('--gx', 0.5);
    gy = num('--gy', 0.5);
    const rh = num('--grh', 0.4);
    const rw = num('--grw', 0.4);
    rPx = Math.min(H * rh, W * rw);
    safeTop = num('--safe-top', 6);
  }

  // Page elements the cards must not cover (the hero copy, the side rail), in stage coordinates.
  let obstacles = [];
  function readObstacles() {
    const base = stage.getBoundingClientRect();
    obstacles = [];
    document.querySelectorAll('[data-tg-avoid] > *').forEach((el) => {
      const r = el.getBoundingClientRect();
      if (!r.width || !r.height) return;
      obstacles.push({ x: r.left - base.left, y: r.top - base.top, w: r.width, h: r.height });
    });
  }
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => readObstacles());

  function resize() {
    const rect = stage.getBoundingClientRect();
    W = Math.max(1, Math.round(rect.width));
    H = Math.max(1, Math.round(rect.height));
    renderer.setSize(W, H, false);
    camera.aspect = W / H;
    readLayout();
    cards.forEach((c) => { c.w = c.el.offsetWidth; c.h = c.el.offsetHeight; });
    readObstacles();
    if (svg) svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    placeCamera();
  }

  function placeCamera() {
    const r = rPx * view.zoom;
    const half = Math.tan((camera.fov * DEG) / 2);
    const alpha = Math.atan((r / (H / 2)) * half);
    const dist = 1 / Math.sin(alpha);
    const cy = gy * H - view.scroll * H * 0.12;
    camera.position.set(0, 0, dist);
    camera.lookAt(0, 0, 0);
    camera.setViewOffset(W, H, W / 2 - gx * W, H / 2 - cy, W, H);
    camera.updateProjectionMatrix();
  }

  new ResizeObserver(resize).observe(stage);
  resize();

  // ---- interaction: drag to rotate (pan-y stays native on touch), pinch / double-click to zoom
  const rot = { x: 0.32, y: Math.PI / 2 - (52 + 180) * DEG, vx: 0, vy: 0 };
  const auto = reduced ? 0 : 0.045;
  let dragging = false;
  let last = null;
  let idleUntil = 0;
  const pointers = new Map();
  let pinch0 = 0;
  let zoom0 = 1;
  const mouse = new Vector2(2, 2);
  let mouseClient = null;

  canvas.addEventListener('pointerdown', (e) => {
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pointers.size === 2) {
      const [a, b] = [...pointers.values()];
      pinch0 = Math.hypot(a.x - b.x, a.y - b.y);
      zoom0 = view.zoomTarget;
    }
    dragging = true;
    last = { x: e.clientX, y: e.clientY, t: performance.now() };
    canvas.setPointerCapture(e.pointerId);
    root.classList.add('is-dragging');
  });
  canvas.addEventListener('pointermove', (e) => {
    const rect = canvas.getBoundingClientRect();
    mouse.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
    mouseClient = { x: e.clientX - rect.left, y: e.clientY - rect.top };
    if (pointers.has(e.pointerId)) pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pointers.size === 2) {
      const [a, b] = [...pointers.values()];
      const d = Math.hypot(a.x - b.x, a.y - b.y);
      if (pinch0 > 0) view.zoomTarget = MathUtils.clamp(zoom0 * (d / pinch0), 0.85, 1.4);
      return;
    }
    if (!dragging || !last) return;
    const dx = e.clientX - last.x;
    const dy = e.clientY - last.y;
    const k = 1 / (rPx * view.zoom);
    rot.y += dx * k;
    rot.vy = dx * k * 60;
    if (e.pointerType !== 'touch') {
      rot.x = MathUtils.clamp(rot.x + dy * k, -0.55, 0.9);
      rot.vx = dy * k * 60;
    }
    last = { x: e.clientX, y: e.clientY, t: performance.now() };
    idleUntil = performance.now() + 2500;
  });
  const end = (e) => {
    pointers.delete(e.pointerId);
    if (pointers.size === 0) { dragging = false; root.classList.remove('is-dragging'); }
    pinch0 = 0;
  };
  canvas.addEventListener('pointerup', end);
  canvas.addEventListener('pointercancel', end);
  canvas.addEventListener('pointerleave', () => { mouse.set(2, 2); mouseClient = null; });
  canvas.addEventListener('dblclick', () => { view.zoomTarget = view.zoomTarget > 1.05 ? 1 : 1.3; });
  canvas.addEventListener('click', () => {
    if (hovered && hovered.userData.type === 'country') {
      const a = hovered.userData.el.querySelector('a[href]') || (hovered.userData.el.matches('a[href]') ? hovered.userData.el : null);
      if (a) window.location.href = a.href;
    }
  });

  const onScroll = () => {
    const rect = root.getBoundingClientRect();
    view.scroll = MathUtils.clamp(-rect.top / Math.max(1, rect.height), 0, 1);
  };
  window.addEventListener('scroll', onScroll, { passive: true });

  // ---- hover / tooltips
  const raycaster = new Raycaster();
  let hovered = null;
  let hoverFrame = 0;

  function setTip(obj) {
    if (!tip) return;
    if (!obj) { tip.hidden = true; return; }
    const d = obj.userData;
    tip.replaceChildren();
    const add = (cls, text) => { const s = document.createElement('span'); s.className = cls; s.textContent = text; tip.appendChild(s); };
    if (d.type === 'ship') {
      add('tg-tip-k', 'کشتی تجاری');
      add('tg-tip-t', d.info.name);
      add('tg-tip-s', d.info.route);
      add('tg-tip-s', d.info.via);
      add('tg-tip-m', `سرعت نمایشی ${d.info.speed} گره دریایی`);
    } else if (d.type === 'truck') {
      add('tg-tip-k', 'حمل زمینی');
      add('tg-tip-t', d.info.name);
      add('tg-tip-s', d.info.route);
      add('tg-tip-s', d.info.via);
    } else if (d.type === 'plane') {
      add('tg-tip-k', 'پرواز باری');
      add('tg-tip-t', d.info.name);
      add('tg-tip-s', d.info.route);
    } else if (d.type === 'route') {
      add('tg-tip-k', d.kind === 'sea' ? 'مسیر دریایی' : d.kind === 'land' ? 'مسیر زمینی' : 'مسیر هوایی');
      add('tg-tip-t', d.info.name);
      add('tg-tip-s', d.info.via);
    } else if (d.type === 'country') {
      const el = d.el;
      add('tg-tip-k', 'بازار هدف');
      add('tg-tip-t', el.getAttribute('data-name') || '');
      const n = el.getAttribute('data-note');
      if (n) add('tg-tip-s', n);
      if (el.tagName === 'A') add('tg-tip-m', 'برای مشاهده تجار این کشور کلیک کنید');
    }
    tip.hidden = false;
  }

  function updateHover() {
    if (dragging || !mouseClient) {
      if (hovered) { unhover(); }
      return;
    }
    raycaster.setFromCamera(mouse, camera);
    const hits = raycaster.intersectObjects([earth, ...hitTargets], false);
    let target = null;
    for (const h of hits) {
      if (h.object === earth) {
        // Anything on the surface can still be picked if it is within a hair of the earth hit.
        const next = hits.find((x) => x.object !== earth);
        if (next && next.distance - h.distance < 0.03) target = next.object;
        break;
      }
      target = h.object;
      break;
    }
    if (target !== hovered) {
      unhover();
      hovered = target;
      if (hovered) {
        if (hovered.userData.type === 'route') hovered.userData.uniforms.uHi.value = 1;
        if (hovered.userData.type === 'country') hovered.userData.el.classList.add('is-hot');
        canvas.classList.add('is-pointer');
      }
      setTip(hovered);
    }
    if (tip && hovered) {
      tip.style.transform = `translate3d(${Math.round(mouseClient.x)}px, ${Math.round(mouseClient.y)}px, 0)`;
    }
  }
  function unhover() {
    if (!hovered) return;
    if (hovered.userData.type === 'route') hovered.userData.uniforms.uHi.value = 0;
    if (hovered.userData.type === 'country') hovered.userData.el.classList.remove('is-hot');
    canvas.classList.remove('is-pointer');
    hovered = null;
    setTip(null);
  }

  // ---- animation
  const tmp = new Vector3();
  const tmp2 = new Vector3();
  const up = new Vector3();
  const fwd = new Vector3();
  const right = new Vector3();
  const basis = new Matrix4();
  const camDir = new Vector3();
  const center = new Vector3();
  let time = 0;
  let prev = performance.now();
  let running = false;
  let visible = true;
  let firstFrame = true;
  let raf = 0;

  function orient(obj, pos, tangent, normal) {
    up.copy(normal).normalize();
    fwd.copy(tangent).addScaledVector(up, -tangent.dot(up)).normalize();
    right.crossVectors(up, fwd).normalize();
    basis.makeBasis(right, up, fwd);
    obj.quaternion.setFromRotationMatrix(basis);
    obj.position.copy(pos);
  }

  function edgeFade(t) {
    return MathUtils.smoothstep(t, 0, 0.025) * (1 - MathUtils.smoothstep(t, 0.975, 1));
  }

  function project(local, out) {
    tmp.copy(local).applyMatrix4(world.matrixWorld);
    camDir.copy(camera.position).sub(tmp).normalize();
    const facing = tmp2.copy(tmp).normalize().dot(camDir);
    tmp.project(camera);
    out.x = (tmp.x * 0.5 + 0.5) * W;
    out.y = (-tmp.y * 0.5 + 0.5) * H;
    out.f = facing;
    return out;
  }

  const scr = { x: 0, y: 0, f: 0 };
  const cScr = { x: 0, y: 0, f: 0 };
  const compact = () => W < 720;

  // Candidate slots (in card widths/heights) tried in order when a card's preferred spot is taken.
  // Card layout: only a few countries at a time — the ones facing the viewer most, far enough apart on screen that
  // their cards cannot overlap (each other, the hero text or the side rail). The choice is re-made a few times a
  // second with a bonus for cards already showing, so as the globe turns, countries hand over smoothly instead of
  // jostling; cards never jump to another slot, they just fade in or out where they belong.
  const maxCards = () => (W < 720 ? 3 : W < 1100 ? 4 : 6);
  const minGap = () => (W < 720 ? 70 : 96);
  let pickedAt = 0;
  const overlaps = (a, b, pad) => a.x < b.x + b.w + pad && a.x + a.w + pad > b.x && a.y < b.y + b.h + pad && a.y + a.h + pad > b.y;

  function pickCards() {
    // A card that is still fading out keeps its space, so a newcomer never appears on top of it.
    const boxes = obstacles.concat(cards.filter((c) => !c.on && c.alpha > 0.08).map((c) => ({ x: c.px, y: c.py, w: c.w, h: c.h })));
    const anchors = [];
    const score = (c) => (c.hover ? 10 : 0) + c.f + (c.on ? 0.15 : 0) - c.priority * 0.15;
    cards.forEach((c) => { c.next = false; });
    let n = 0;
    cards.filter((c) => c.w && (c.f > 0.32 || c.hover)).sort((a, b) => score(b) - score(a)).forEach((c) => {
      if (n >= maxCards() && !c.hover) return;
      const box = { x: c.px, y: c.py, w: c.w, h: c.h };
      if (!c.hover && boxes.some((b) => overlaps(box, b, 10))) return;
      if (!c.hover && anchors.some((a) => Math.hypot(a.x - c.sx, a.y - c.sy) < minGap())) return;
      c.next = true;
      boxes.push(box);
      anchors.push({ x: c.sx, y: c.sy });
      n++;
    });
    cards.forEach((c) => { c.on = c.next; });
  }

  function updateOverlays() {
    center.set(0, 0, 0).project(camera);
    cScr.x = (center.x * 0.5 + 0.5) * W;
    cScr.y = (-center.y * 0.5 + 0.5) * H;
    const r = rPx * view.zoom;
    // Pass 1: anchor and the card's own box (placed outward from the globe centre).
    cards.forEach((c) => {
      project(c.local, scr);
      let dx = scr.x - cScr.x;
      let dy = scr.y - cScr.y;
      const dl = Math.hypot(dx, dy) || 1;
      dx /= dl; dy /= dl;
      const push = compact() ? 26 : 42 + (1 - Math.min(1, dl / r)) * 26;
      const cx = scr.x + dx * (push + c.w * 0.5);
      const cy = scr.y + dy * (push * 0.6 + c.h * 0.5) - (compact() ? 10 : 18);
      c.sx = scr.x; c.sy = scr.y; c.f = scr.f;
      c.o = MathUtils.smoothstep(scr.f, 0.2, 0.45);
      c.px = MathUtils.clamp(cx - c.w / 2, 6, W - c.w - 6);
      c.py = MathUtils.clamp(cy - c.h / 2, safeTop, H - c.h - 6);
    });
    // Pass 2: who is shown (a few times a second, or at once when the pointer is on a card).
    const now = performance.now();
    const hovering = cards.some((c) => c.hover && !c.on);
    if (now - pickedAt > 300 || hovering) {
      pickedAt = now;
      pickCards();
    }
    // Between choices the globe keeps turning: if two shown cards drift into each other, the weaker one gives way now.
    const kept = [];
    cards.filter((c) => c.on).sort((a, b) => (b.hover - a.hover) || (b.f - a.f)).forEach((c) => {
      const box = { x: c.px, y: c.py, w: c.w, h: c.h };
      if (!c.hover && kept.some((k) => overlaps(box, k, 2))) { c.on = false; return; }
      kept.push(box);
    });
    cards.forEach((c, i) => {
      c.alpha = (c.alpha || 0) + ((c.on ? c.o : 0) - (c.alpha || 0)) * 0.14;
      let o = c.alpha < 0.01 ? 0 : c.alpha;
      if (c.hover) o = Math.max(o, 0.95);
      scr.x = c.sx; scr.y = c.sy;
      const x = c.px;
      const y = c.py;
      c.el.style.transform = `translate3d(${x.toFixed(1)}px, ${y.toFixed(1)}px, 0) scale(${(0.9 + 0.1 * o).toFixed(3)})`;
      c.el.style.opacity = o.toFixed(3);
      c.el.style.visibility = o < 0.02 ? 'hidden' : 'visible';
      const ln = lines[i];
      const ex = MathUtils.clamp(scr.x, x, x + c.w);
      const ey = MathUtils.clamp(scr.y, y, y + c.h);
      ln.l.setAttribute('x1', scr.x.toFixed(1));
      ln.l.setAttribute('y1', scr.y.toFixed(1));
      ln.l.setAttribute('x2', ex.toFixed(1));
      ln.l.setAttribute('y2', ey.toFixed(1));
      ln.l.setAttribute('opacity', (o * 0.85).toFixed(3));
      ln.d.setAttribute('cx', scr.x.toFixed(1));
      ln.d.setAttribute('cy', scr.y.toFixed(1));
      ln.d.setAttribute('opacity', o.toFixed(3));
    });
    labels.forEach((lb) => {
      project(lb.local, scr);
      const o = MathUtils.smoothstep(scr.f, 0.15, 0.45);
      lb.el.style.transform = `translate3d(${scr.x.toFixed(1)}px, ${scr.y.toFixed(1)}px, 0)`;
      lb.el.style.opacity = o.toFixed(3);
    });
  }

  // Sign-in transition: light every route, spin a little faster and zoom in while the form is submitted.
  let launched = false;
  window.addEventListener('tg:launch', () => {
    launched = true;
    view.zoomTarget = 1.5;
    routeMeshes.forEach((r) => { r.uniforms.uHi.value = 1; });
    root.classList.add('tg-launch');
  });

  function frame(now) {
    raf = 0;
    if (!running) return;
    const dt = Math.max(0, Math.min(0.05, (now - prev) / 1000));
    prev = now;
    time += dt;
    const motion = reduced ? 0.35 : 1;

    // rotation with inertia and slow auto-spin
    if (!dragging) {
      rot.y += rot.vy * dt;
      rot.x = MathUtils.clamp(rot.x + rot.vx * dt, -0.55, 0.9);
      rot.vy *= Math.pow(0.04, dt);
      rot.vx *= Math.pow(0.04, dt);
      if (now > idleUntil) {
        rot.y += (launched ? 0.35 : auto) * dt;
        rot.x += (0.32 - rot.x) * Math.min(1, dt * 0.4);
      }
    }
    world.rotation.set(rot.x, rot.y + view.scroll * 0.5, 0);
    if (clouds) clouds.rotation.y += dt * 0.008;
    stars.rotation.y = rot.y * 0.04;

    view.zoom += (view.zoomTarget - view.zoom) * Math.min(1, dt * 5);
    placeCamera();

    reveal.value = Math.min(1, reveal.value + dt * 0.9);
    pointMaterials.forEach((m) => { m.uniforms.uTime.value = time; m.uniforms.uReveal.value = reveal.value; });
    routeMeshes.forEach((r) => { r.uniforms.uTime.value = time * motion; });
    rings.forEach((m) => {
      const k = (time * 0.45 + m.userData.phase) % 1;
      m.scale.setScalar(0.01 + k * 0.05);
      m.material.opacity = (1 - k) * 0.7 * reveal.value;
    });

    particleRoutes.forEach((p, i) => {
      p.t = (p.t + p.v * dt * motion) % 1;
      p.r.curve.getPointAt(p.t, tmp);
      pPos[i * 3] = tmp.x; pPos[i * 3 + 1] = tmp.y; pPos[i * 3 + 2] = tmp.z;
    });
    pg.attributes.position.needsUpdate = true;

    ships.forEach((s) => {
      s.t = (s.t + s.v * dt * motion) % 1;
      s.route.curve.getPointAt(s.t, tmp);
      s.route.curve.getTangentAt(s.t, tmp2);
      const n = tmp.clone().normalize();
      tmp.copy(n).multiplyScalar(1.0028);
      orient(s.group, tmp, tmp2, n);
      const f = edgeFade(s.t);
      s.group.scale.setScalar(SHIP_SCALE * (0.2 + 0.8 * f));
      s.wake.material.uniforms.uTime.value = time;
      s.wake.material.uniforms.uAlpha.value = f;
      s.glow.material.uniforms.uAlpha.value = f;
    });

    trucks.forEach((k) => {
      k.t = (k.t + k.v * dt * motion) % 1;
      k.route.curve.getPointAt(k.t, tmp);
      k.route.curve.getTangentAt(k.t, tmp2);
      const n = tmp.clone().normalize();
      orient(k.group, tmp.copy(n).multiplyScalar(1.0026), tmp2, n);
      const f = edgeFade(k.t);
      k.group.visible = f > 0.02;
      k.group.scale.setScalar(TRUCK_SCALE * (0.3 + 0.7 * f));
    });

    planes.forEach((p) => {
      p.t = (p.t + p.v * dt * motion) % 1;
      p.route.curve.getPointAt(p.t, tmp);
      p.route.curve.getTangentAt(p.t, tmp2);
      orient(p.group, tmp, tmp2, tmp.clone().normalize());
      const f = edgeFade(p.t);
      p.group.visible = f > 0.02;
      p.group.scale.setScalar((weak ? 0.055 : 0.048) * (0.3 + 0.7 * f));
    });

    if (dead) return;
    world.updateMatrixWorld();
    if (++hoverFrame % 2 === 0) updateHover();
    renderer.render(scene, camera);
    updateOverlays();

    if (firstFrame) {
      firstFrame = false;
      if (!drewGlobe(false)) {
        fail('first frame is empty');
        return;
      }
      root.classList.add('tg-ready');
    }
    // The launch animation starts dark, so "is it visibly lit?" is asked once the globe has settled (~90 frames).
    if (++litCheck === 90 && !drewGlobe(true)) {
      fail('globe renders black on this GPU');
      return;
    }
    schedule();
  }

  /** Reads a few pixels around the globe's centre right after the first render: all transparent = nothing drawn. */
  function drewGlobe(needBright) {
    try {
      const gl = renderer.getContext();
      const cs = getComputedStyle(stage);
      const gx = parseFloat(cs.getPropertyValue('--gx')) || 0.6;
      const gy = parseFloat(cs.getPropertyValue('--gy')) || 0.45;
      const w = gl.drawingBufferWidth, h = gl.drawingBufferHeight;
      const px = new Uint8Array(4);
      // Sample a grid over the globe's disc. Something must be drawn (alpha) AND visibly lit: some TV GPUs run the
      // shaders but output pure black, which would leave an invisible globe on the dark hero.
      let drawn = 0, bright = 0;
      const r = Math.min(w, h) * 0.18;
      for (let i = -2; i <= 2; i++) {
        for (let j = -2; j <= 2; j++) {
          gl.readPixels(Math.round(w * gx + i * r / 2), Math.round(h * (1 - gy) + j * r / 2), 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, px);
          if (px[3] > 0) drawn++;
          if (px[0] + px[1] + px[2] > 60) bright++;
        }
      }
      window.__tgProbe = { drawn, bright };
      if (gl.getError() === gl.CONTEXT_LOST_WEBGL) return true;
      return needBright ? bright > 0 : drawn > 0;
    } catch (e) {
      return true; // cannot tell: keep the globe
    }
  }

  let litCheck = 0;
  function schedule() {
    if (running && !raf) raf = requestAnimationFrame(frame);
  }
  function setRunning(on) {
    const next = on && visible && !document.hidden;
    if (next === running) return;
    running = next;
    if (running) { prev = performance.now(); schedule(); }
  }

  new IntersectionObserver((entries) => {
    visible = entries.some((e) => e.isIntersecting);
    if (started) setRunning(true);
  }, { rootMargin: '80px' }).observe(root);
  document.addEventListener('visibilitychange', () => { if (started) setRunning(true); });

  canvas.addEventListener('webglcontextlost', (e) => {
    e.preventDefault();
    running = false;
    root.classList.remove('tg-ready');
    root.classList.add('tg-fallback');
  });
  canvas.addEventListener('webglcontextrestored', () => {
    if (dead) return;
    root.classList.remove('tg-fallback');
    firstFrame = true;
    setRunning(true);
  });

  // Start once the earth texture is decoded so the first visible frame is the real planet.
  setTimeout(start, 5000);
  onScroll();
}

const root = document.querySelector('[data-trade-globe]');
if (root) {
  try {
    init(root);
  } catch (e) {
    root.classList.add('tg-fallback');
    if (window.console) console.warn('trade-globe:', e);
  }
}
