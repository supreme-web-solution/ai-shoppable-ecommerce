import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    scenarios: {
        feed_readers: {
            executor: 'ramping-vus',
            startVUs: 5,
            stages: [
                { duration: '30s', target: 25 },
                { duration: '1m', target: 75 },
                { duration: '1m', target: 150 },
                { duration: '30s', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.02'],
        http_req_duration: ['p(95)<500', 'p(99)<1200'],
    },
};

const BASE_URL = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const EMBED_SLUG = __ENV.EMBED_SLUG || 'demo-feed';
const TEAM_ID = __ENV.TEAM_ID || '1';

export default function () {
    const sessionId = `k6-${__VU}-${__ITER}`;
    const feedResponse = http.get(`${BASE_URL}/api/v1/player/feed?embed_slug=${EMBED_SLUG}&per_page=10`, {
        headers: {
            Accept: 'application/json',
            Origin: 'http://localhost',
            'X-Embed-Slug': EMBED_SLUG,
        },
    });

    check(feedResponse, {
        'feed status 200': (r) => r.status === 200,
    });

    if (feedResponse.status !== 200) {
        sleep(1);
        return;
    }

    const parsed = feedResponse.json();
    const firstVideo = parsed?.data?.[0];

    if (firstVideo?.id && firstVideo?.team_id) {
        const viewerPing = http.post(
            `${BASE_URL}/api/v1/player/viewer-ping`,
            JSON.stringify({
                team_id: firstVideo.team_id ?? Number(TEAM_ID),
                video_id: firstVideo.id,
                session_key: sessionId,
            }),
            {
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    Origin: 'http://localhost',
                    'X-Embed-Slug': EMBED_SLUG,
                },
            },
        );

        check(viewerPing, {
            'viewer ping status 200': (r) => r.status === 200,
        });
    }

    sleep(0.7);
}
