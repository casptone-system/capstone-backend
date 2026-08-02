const http = require('http');
const payload = JSON.stringify({
  first_name: 'Test',
  last_name: 'User',
  middle_name: 'Q',
  email: `testuser${Date.now()}@example.com`,
  password: 'password123',
  password_confirmation: 'password123',
});

const options = {
  hostname: '127.0.0.1',
  port: 8000,
  path: '/api/register',
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(payload),
  },
};

const req = http.request(options, (res) => {
  let data = '';
  res.on('data', (chunk) => (data += chunk));
  res.on('end', () => {
    console.log('statusCode', res.statusCode);
    console.log(data);
  });
});

req.on('error', (error) => {
  console.error(error);
});

req.write(payload);
req.end();
