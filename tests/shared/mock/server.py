import json
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# What the Rocket.Chat webhook (POST /hook) and sonar (POST .../intake/v1/deployment) received. sonar is reachable
# under three prefixes: none (answers 201), /fail (500) and /slow (answers after 30 seconds, far longer than any timeout).
payloads = []
sonar_requests = []
lock = threading.Lock()

SLOW_SECONDS = 30


class Handler(BaseHTTPRequestHandler):
    def _reply(self, status, body=b''):
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        if self.path.endswith('/intake/v1/deployment'):
            with lock:
                sonar_requests.append({
                    'path': self.path,
                    'authorization': self.headers.get('Authorization'),
                    'body': json.loads(raw),
                })
            if self.path.startswith('/slow/'):
                time.sleep(SLOW_SECONDS)
            if self.path.startswith('/fail/'):
                return self._reply(500, b'{"title":"Internal Server Error","status":500}')
            return self._reply(201, b'{"status":"started"}')
        with lock:
            payloads.append(json.loads(raw))
        self._reply(200, b'{"success":true}')

    def do_GET(self):
        with lock:
            body = json.dumps(sonar_requests if self.path == '/sonar' else payloads).encode()
        self._reply(200, body)

    def do_DELETE(self):
        with lock:
            payloads.clear()
            sonar_requests.clear()
        self._reply(204)

    def log_message(self, *args):
        pass


ThreadingHTTPServer(('0.0.0.0', 8080), Handler).serve_forever()
