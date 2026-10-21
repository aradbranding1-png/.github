// k6 load test — proposal feed (doc §105). Run on STAGING with seeded data:
//   BASE=https://staging.aradbranding.app COOKIE="__Host-sadt=..." k6 run tests/load/feed.js
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  scenarios: {
    feed: { executor: 'ramping-arrival-rate', startRate: 10, timeUnit: '1s', preAllocatedVUs: 100, maxVUs: 600,
      stages: [{ target: 170, duration: '2m' }, { target: 170, duration: '10m' }, { target: 0, duration: '1m' }] },
  },
  thresholds: { http_req_failed: ['rate<0.001'], 'http_req_duration{name:feed}': ['p(95)<250'], 'http_req_duration{name:more}': ['p(95)<250'] },
};

const BASE = __ENV.BASE;
const params = { headers: { Cookie: __ENV.COOKIE, Accept: 'text/html' } };

export default function () {
  const res = http.get(`${BASE}/proposals?tab=latest`, Object.assign({ tags: { name: 'feed' } }, params));
  check(res, { 'feed 200': (r) => r.status === 200 });
  const more = res.body && res.body.match(/data-more="([^"]+)"/);
  if (more) {
    const r2 = http.get(BASE + more[1].replace(/&amp;/g, '&'), { headers: { Cookie: __ENV.COOKIE, Accept: 'application/json' }, tags: { name: 'more' } });
    check(r2, { 'more 200': (r) => r.status === 200 });
  }
  sleep(1);
}
