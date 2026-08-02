import json
import urllib.request
import urllib.error

url = 'http://127.0.0.1:8000/api/register'
payload = {
    'first_name': 'Test',
    'last_name': 'User',
    'middle_name': 'Q',
    'email': 'testuser12345@example.com',
    'password': 'password123',
    'password_confirmation': 'password123',
}
data = json.dumps(payload).encode('utf-8')
request = urllib.request.Request(url, data=data, headers={'Content-Type': 'application/json'})

try:
    with urllib.request.urlopen(request) as response:
        print('STATUS', response.status)
        print(response.read().decode('utf-8'))
except urllib.error.HTTPError as error:
    print('STATUS', error.code)
    print(error.read().decode('utf-8'))
