#!/usr/bin/env python3
"""Exercise the deployed preview over real HTTP/WebSocket connections."""

import argparse
import base64
import concurrent.futures
from contextlib import contextmanager
import hashlib
import http.client
import json
import os
from pathlib import Path
import select
import shutil
import socket
import struct
import subprocess
import time
from urllib.parse import quote, urlsplit
import uuid


ADDRESS = "127.0.0.1"
PORT = int(os.environ.get("CDN_PORT", "8080"))
HOST = "demo.localhost"
CACHE = "no-store, no-cache, must-revalidate, max-age=0"


def request(path, *, host=HOST, method="GET", headers=None):
    connection = http.client.HTTPConnection(ADDRESS, PORT, timeout=10)
    try:
        connection.request(method, path, headers={"Host": host, **(headers or {})})
        response = connection.getresponse()
        return response.status, dict((k.lower(), v) for k, v in response.getheaders()), response.read()
    finally:
        connection.close()


def no_cache(headers):
    assert headers.get("cache-control") == CACHE, headers
    assert headers.get("pragma") == "no-cache", headers
    assert headers.get("expires") == "0", headers


@contextmanager
def site_directory():
    root = Path("data/target") / ("routing-" + uuid.uuid4().hex)
    root.mkdir()
    root.chmod(0o755)
    try:
        yield root
    finally:
        shutil.rmtree(root)


def check_routing():
    with site_directory() as root:
        host = root.name + ".localhost"
        files = {
            "index.html": "root-index",
            "flat.html": "flat-html",
            "flat.css": "flat-css",
            "flat/child.html": "flat-child",
            "collision.html": "collision-flat",
            "collision/index.html": "collision-index",
            "exact": "exact-file",
            "exact.html": "exact-html",
            "contact/index.html": "contact-index",
            "nested/page.html": "nested-html",
            "nested/page.css": "nested-css",
            "dot.name.html": "dotted-html",
            "version.v1/index.html": "dotted-directory",
            "htm-only/index.htm": "not-an-index",
            "assets/site.css": "body { color: red; }",
            "404.html": "custom-error-" + root.name,
            ".secret": "hidden",
            "nested/.secret": "hidden",
            ".private/file.html": "hidden",
        }
        for name, content in files.items():
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content, encoding="utf-8")
        (root / "empty").mkdir()
        (root / "link.html").symlink_to("index.html")
        (root / "linked-flat.html").symlink_to("index.html")
        (root / "link-dir").symlink_to("contact", target_is_directory=True)

        cases = [
            ("/", "root-index"),
            ("/flat", "flat-html"),
            ("/flat/", "flat-html"),
            ("/flat.html", "flat-html"),
            ("/flat.css", "flat-css"),
            ("/flat/child", "flat-child"),
            ("/collision", "collision-flat"),
            ("/collision/", "collision-index"),
            ("/exact", "exact-file"),
            ("/exact.html", "exact-html"),
            ("/contact/", "contact-index"),
            ("/nested/page", "nested-html"),
            ("/nested/page/", "nested-html"),
            ("/version.v1/", "dotted-directory"),
            ("/assets/site.css", "body { color: red; }"),
            ("/404.html", files["404.html"]),
        ]
        for path, expected in cases:
            status, headers, body = request(path, host=host, headers={"Accept": "text/css"})
            assert (status, body) == (200, expected.encode()), (path, status, body)
            no_cache(headers)

        # Redirects must retain the query and external origin, including its port.
        for path in ["/contact", "/version.v1", "/empty", "/htm-only"]:
            status, headers, _ = request(path + "?next=a%2Fb", host=f"{host}:{PORT}")
            assert status == 301, (path, status)
            location = urlsplit(headers["location"])
            assert location.path == path + "/" and location.query == "next=a%2Fb", headers
            assert not location.netloc or location.netloc == f"{host}:{PORT}", headers
            no_cache(headers)

        for path in ["/missing", "/nested/missing", "/dot.name", "/dot.name/"]:
            status, headers, body = request(path, host=host)
            assert (status, body) == (404, files["404.html"].encode()), (path, status, body)
            no_cache(headers)

        for path in ["/empty/", "/htm-only/", "/.secret", "/nested/.secret",
                     "/.private/file.html", "/link.html", "/link-dir/",
                     "/link-dir/index.html", "/linked-flat", "/linked-flat/"]:
            status, headers, _ = request(path, host=host)
            assert status == 403, (path, status)
            no_cache(headers)

        status, headers, body = request("/flat", host=host, method="HEAD")
        assert status == 200 and body == b"", (status, body)
        no_cache(headers)
        for kwargs in [{"host": "unknown.example"}, {"host": "-invalid.localhost"},
                       {"host": host, "method": "POST"}]:
            status, headers, _ = request("/", **kwargs)
            assert status == 403, (kwargs, status)
            no_cache(headers)

        # The first hostname label selects a site; custom errors stay site-local.
        with site_directory() as other:
            (other / "index.html").write_text("other-site", encoding="utf-8")
            (other / "404.html").write_text("other-error", encoding="utf-8")
            assert request("/", host=other.name + ".localhost")[2] == b"other-site"
            assert request("/missing", host=other.name + ".localhost")[2] == b"other-error"
        assert request("/", host=root.name + ".nested.localhost")[2] == b"root-index"
        assert request("/", host=host.upper())[2] == b"root-index"

        (root / "404.html").unlink()
        status, headers, _ = request("/missing", host=host)
        assert status == 404, status
        no_cache(headers)
    print("HTTP routing, host mapping, cache headers and access restrictions passed", flush=True)


def publish(channel, body):
    result = subprocess.run(
        ["php", "tests/messages.php", channel], input=body,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60,
    )
    assert result.returncode == 0, result.stderr.decode(errors="replace")
    reply = json.loads(result.stdout)
    assert reply == {"channel": channel, "status": "published"}, reply


class WebSocket:
    def __init__(self, channel, host=HOST):
        self.socket = socket.create_connection((ADDRESS, PORT), timeout=10)
        key = base64.b64encode(os.urandom(16)).decode()
        self.socket.sendall((
            f"GET /__preview/events?channel={quote(channel)} HTTP/1.1\r\n"
            f"Host: {host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            f"Sec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n"
        ).encode())
        header = b""
        while not header.endswith(b"\r\n\r\n"):
            part = self.socket.recv(1)
            assert part and len(header) < 16384, "Incomplete WebSocket upgrade"
            header += part
        lines = header.decode().split("\r\n")
        if lines[0].split()[1] != "101":
            self.close()
            raise AssertionError(f"WebSocket upgrade failed: {lines[0]}")
        headers = dict(line.split(":", 1) for line in lines[1:] if ":" in line)
        headers = {name.lower(): value.strip() for name, value in headers.items()}
        expected = base64.b64encode(hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode()).digest()).decode()
        assert headers.get("sec-websocket-accept") == expected, headers

    def close(self):
        self.socket.close()

    def read_exact(self, length):
        result = b""
        while len(result) < length:
            part = self.socket.recv(length - len(result))
            assert part, "WebSocket closed before expected message"
            result += part
        return result

    def frame(self):
        first, second = self.read_exact(2)
        assert first & 0x80, "Unexpected fragmented server frame"
        assert not second & 0x80, "Server frames must not be masked"
        length = second & 127
        if length == 126:
            length = struct.unpack("!H", self.read_exact(2))[0]
        elif length == 127:
            length = struct.unpack("!Q", self.read_exact(8))[0]
        return first & 15, self.read_exact(length)

    def send_frame(self, opcode, payload):
        mask = os.urandom(4)
        assert len(payload) < 126, "Test client only needs short frames"
        encoded = bytes(byte ^ mask[index % 4] for index, byte in enumerate(payload))
        self.socket.sendall(bytes([0x80 | opcode, 0x80 | len(payload)]) + mask + encoded)

    def message(self):
        while True:
            opcode, payload = self.frame()
            if opcode == 9:
                self.send_frame(10, payload)
                continue
            assert opcode == 1, (opcode, payload)
            return payload

    def quiet(self):
        deadline = time.monotonic() + 0.2
        while select.select([self.socket], [], [], max(0, deadline - time.monotonic()))[0]:
            opcode, payload = self.frame()
            assert opcode == 9, ("Unexpected message on a different/new subscriber", opcode, payload)
            self.send_frame(10, payload)
            if time.monotonic() >= deadline:
                break


def check_messages():
    channel = "events-" + uuid.uuid4().hex
    payload = ' {"type":"reload","html":"</body>","label":"Grüße","literal":"$x & ?"} '.encode()
    readers = []
    try:
        # Completed upgrades establish subscriptions before the RabbitMQ publish.
        readers = [WebSocket(channel), WebSocket(channel), WebSocket(channel + "-other")]
        publish(channel, payload)
        assert readers[0].message() == payload
        assert readers[1].message() == payload
        readers[2].quiet()
        second = b'{"type":"navigate","path":"/contact/"}'
        publish(channel, second)
        assert readers[0].message() == second
        assert readers[1].message() == second
        readers[2].quiet()

        # A valid, masked client frame must not turn a subscriber into a publisher.
        readers[0].send_frame(1, b"unauthorized-browser-publish")
        opcode, reason = readers[0].frame()
        assert opcode == 8 and b"Publishing not allowed" in reason, (opcode, reason)
        readers[1].quiet()
    finally:
        for reader in readers:
            reader.close()

    # With newest, opening a subscription must not replay an earlier message.
    fresh = "newest-" + uuid.uuid4().hex
    publish(fresh, b"already-published")
    late = WebSocket(fresh)
    try:
        late.quiet()
        publish(fresh, b"new-message")
        assert late.message() == b"new-message"
    finally:
        late.close()

    # EventSource is a long-lived read transport on the same endpoint.
    sse_channel = "sse-" + uuid.uuid4().hex
    connection = http.client.HTTPConnection(ADDRESS, PORT, timeout=10)
    response = None
    try:
        connection.request("GET", "/__preview/events?channel=" + sse_channel,
                           headers={"Host": HOST, "Accept": "text/event-stream"})
        response = connection.getresponse()
        assert response.status == 200, response.status
        assert response.getheader("Content-Type").startswith("text/event-stream")
        for body in [payload, b'{"type":"select","id":"section-2"}']:
            publish(sse_channel, body)
            data = []
            while True:
                line = response.readline()
                assert line, "SSE stream closed unexpectedly"
                line = line.rstrip(b"\r\n")
                if line.startswith(b"data:"):
                    value = line[5:]
                    data.append(value[1:] if value.startswith(b" ") else value)
                elif not line and data:
                    assert b"\n".join(data) == body, data
                    break
    finally:
        if response is not None:
            response.close()
        connection.close()

    # A long-poll must wait, then return the unmodified body and finish.
    lp_channel = "longpoll-" + uuid.uuid4().hex
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
        waiting = executor.submit(request, "/__preview/events?channel=" + lp_channel)
        try:
            waiting.result(timeout=0.2)
        except concurrent.futures.TimeoutError:
            pass
        else:
            raise AssertionError("Long-poll completed before publication")
        # HTTP long-poll has no subscription handshake; retry the identical test
        # signal briefly if it raced registration. Do not add a production route.
        for _ in range(8):
            publish(lp_channel, payload)
            try:
                status, _, body = waiting.result(timeout=0.2)
                break
            except concurrent.futures.TimeoutError:
                continue
        else:
            raise AssertionError("Long-poll did not receive the published message")
        assert (status, body) == (200, payload), (status, body)

    # The limit is shared across hostnames and does not limit static requests.
    readers = []
    try:
        for _ in range(5):
            readers.append(WebSocket("limit-" + uuid.uuid4().hex))
        assert request("/__preview/events?channel=sixth", host="other.localhost")[0] == 429
        assert request("/")[0] == 200
    finally:
        for reader in readers:
            reader.close()

    for query in ["", "?channel=", "?channel=a,b", "?channel=a%2Fb", "?channel=" + "a" * 129]:
        assert request("/__preview/events" + query)[0] == 400, query
    assert request("/__preview/events?channel=valid", method="POST")[0] == 403
    assert request("/publish?channel=valid", method="POST")[0] == 403
    print("RabbitMQ to Nchan: broadcast, channel isolation, raw bodies, WS/SSE/long-poll and five-IP limit passed", flush=True)


def check_publisher_unavailable():
    deadline = time.monotonic() + 5
    while True:
        try:
            with socket.create_connection((ADDRESS, PORT), timeout=0.2):
                pass
        except OSError:
            break
        assert time.monotonic() < deadline, "Nginx did not stop for publisher-failure test"
        time.sleep(0.05)
    result = subprocess.run(
        ["php", "tests/messages.php", "unavailable-publisher"], input=b"must-not-succeed",
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=60,
    )
    assert result.returncode != 0 and b"PUBLISH_ERROR" in result.stderr, (result.returncode, result.stdout, result.stderr)
    print("Unavailable Nchan publisher returns a RabbitMQ publication error", flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["routing", "messages", "publisher-unavailable"])
    args = parser.parse_args()
    if args.mode == "routing":
        check_routing()
    elif args.mode == "messages":
        check_messages()
    else:
        check_publisher_unavailable()
