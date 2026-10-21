// k6 — mixed traffic: public page teasers (anonymous, cacheable), mailbox, notifications counter, dashboard.
//   BASE=https://staging.aradbranding.app COOKIE="__Host-sadt=..." HANDLES=demo10,demo11,demo12 k6 run tests/load/core.js
import http from 'k6/http';
import { check } from 'k6';

const BASE = __ENV.BASE;
const HANDLES = (__ENV.HANDLES || 'demo10').split(',');
const auth = { headers: { Cookie: __ENV.COOKIE } };

export const options = {
  scenarios: {
    pages: { executor: 'constant-arrival-rate', rate: 100, timeUnit: '1s', duration: '10m', preAllocatedVUs: 100, exec: 'page' },
    inbox: { executor: 'constant-arrival-rate', rate: 200, timeUnit: '1s', duration: '10m', preAllocatedVUs: 150, exec: 'inbox' },
    dashboard: { executor: 'constant-arrival-rate', rate: 100, timeUnit: '1s', duration: '10m', preAllocatedVUs: 80, exec: 'dashboard' },
  },
  thresholds: {
    http_req_failed: ['rate<0.001'],
    'http_req_duration{scenario:pages}': ['p(95)<200'],
    'http_req_duration{scenario:inbox}': ['p(95)<250'],
    'http_req_duration{scenario:dashboard}': ['p(95)<250'],
  },
};

export function page() {
  const h = HANDLES[Math.floor(Math.random() * HANDLES.length)];
  check(http.get(`${BASE}/p/${h}`), { 'page 200': (r) => r.status === 200 });
}
export function inbox() { check(http.get(`${BASE}/letters`, auth), { 'inbox 200': (r) => r.status === 200 }); }
export function dashboard() { check(http.get(`${BASE}/dashboard`, auth), { 'dashboard 200': (r) => r.status === 200 }); }
