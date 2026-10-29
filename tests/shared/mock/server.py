import json
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

payloads = []
lock = threading.Lock()


class Handler(BaseHTTPRequestHandler):
    def _reply(self, status, body=b''):
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        with lock:
            payloads.append(json.loads(raw))
        self._reply(200, b'{"success":true}')

    def do_GET(self):
        with lock:
            body = json.dumps(payloads).encode()
        self._reply(200, body)

    def do_DELETE(self):
        with lock:
            payloads.clear()
        self._reply(204)

    def log_message(self, *args):
        pass


ThreadingHTTPServer(('0.0.0.0', 8080), Handler).serve_forever()
