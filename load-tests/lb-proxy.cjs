// Tiny round-robin reverse proxy so JMeter hits ONE origin (127.0.0.1:8000)
// while 4 single-threaded `php -S` workers (8001-8004) share the load.
// Local load-testing only — in staging/production use php-fpm/Apache/Nginx.
const http = require('http');

const backends = (process.env.BACKEND_PORTS || '8001,8002,8003,8004')
  .split(',').map((p) => ({ host: '127.0.0.1', port: Number(p), fails: 0 }));
const listenPort = Number(process.env.PROXY_PORT || 8000);
let idx = 0;

function pick() {
  for (let i = 0; i < backends.length; i++) {
    const b = backends[(idx + i) % backends.length];
    if (b.fails < 3) { idx = (idx + i + 1) % backends.length; return b; }
  }
  const b = backends[idx % backends.length]; idx++; return b;
}

const server = http.createServer((req, res) => {
  const body = [];
  req.on('data', (c) => body.push(c));
  req.on('end', () => {
    const payload = Buffer.concat(body);
    const attempt = (tries) => {
      const b = pick();
      const headers = { ...req.headers, host: `${b.host}:${b.port}` };
      const upstream = http.request(
        { host: b.host, port: b.port, path: req.url, method: req.method, headers, timeout: 60000 },
        (up) => { b.fails = 0; res.writeHead(up.statusCode, up.headers); up.pipe(res); }
      );
      upstream.on('timeout', () => upstream.destroy(new Error('upstream timeout')));
      upstream.on('error', (err) => {
        b.fails++;
        if (tries > 0) return attempt(tries - 1);
        if (!res.headersSent) res.writeHead(502, { 'content-type': 'application/json' });
        res.end(JSON.stringify({ success: false, message: `proxy: ${err.message}` }));
      });
      if (payload.length) upstream.write(payload);
      upstream.end();
    };
    attempt(backends.length - 1);
  });
});
server.listen(listenPort, '127.0.0.1', () =>
  console.log(`[lb] 127.0.0.1:${listenPort} -> ${backends.map((b) => b.port).join(', ')}`));
