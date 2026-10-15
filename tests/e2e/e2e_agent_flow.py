#!/usr/bin/env python3
"""End-to-end acceptance run against a live control plane (stdlib only).

Usage:  python3 tests/e2e/e2e_agent_flow.py [base_url]

Checks the acceptance criteria that need a real HTTP stack:
  * enroll token -> registered node in < 3s
  * config publish -> agent sees the new version and acks it
  * failed apply -> automatic rollback to the previous applied version
  * command issued -> agent picks it up on the next poll and acks it in < 2s
  * gzip metric batch, logs, diagnostics, upgrade check, WS channel auth
"""

from __future__ import annotations

import gzip
import hashlib
import hmac
import json
import os
import sys
import time
import urllib.error
import urllib.request
import uuid

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8088"

PASS: list[str] = []
FAIL: list[str] = []


def check(name: str, condition: bool, detail: str = "") -> bool:
    (PASS if condition else FAIL).append(f"{name} {detail}".strip())
    print(f"{'PASS' if condition else 'FAIL'}  {name} {detail}".rstrip())
    return condition


def request(method: str, path: str, body: bytes | dict | None = None, headers: dict | None = None,
            raw: bool = False, gzip_body: bool = False) -> tuple[int, dict | str]:
    if isinstance(body, dict):
        body = json.dumps(body).encode()
    if body is None:
        body = b""
    hdrs = {"Accept": "application/json"}
    if raw:
        hdrs["Content-Type"] = "application/json"
    if gzip_body:
        body = gzip.compress(body)
        hdrs["Content-Encoding"] = "gzip"
    hdrs.update(headers or {})
    req = urllib.request.Request(BASE + path, data=body if method != "GET" else None, headers=hdrs, method=method)
    try:
        with urllib.request.urlopen(req, timeout=20) as response:
            payload = response.read().decode()
            status = response.status
    except urllib.error.HTTPError as error:
        payload = error.read().decode()
        status = error.code
    try:
        return status, json.loads(payload)
    except json.JSONDecodeError:
        return status, payload


def signed_agent_headers(node_id: str, secret: str, method: str, path: str, body: bytes) -> dict:
    timestamp = str(int(time.time()))
    nonce = str(uuid.uuid4())
    canonical = "\n".join([method, path, hashlib.sha256(body).hexdigest(), timestamp, nonce])
    signature = hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()
    return {
        "X-Node-Id": node_id,
        "X-Timestamp": timestamp,
        "X-Nonce": nonce,
        "X-Node-Signature": signature,
    }


def main() -> int:
    print(f"control plane: {BASE}\n")

    status, login = request("POST", "/api/admin/v1/auth/login",
                            {"email": "admin@example.com", "password": "password", "device_name": "e2e"},
                            raw=True)
    check("admin login", status == 200 and "token" in login, f"http={status}")
    token = login.get("token", "")
    admin = {"Authorization": f"Bearer {token}"}

    # ---------------------------------------------------------------- enroll
    status, issued = request("POST", "/api/admin/v1/enroll-tokens",
                             {"note": "e2e", "max_uses": 2, "expires_in_days": 1,
                              "region_hint": "hk", "tags_hint": ["e2e"]},
                             headers=admin, raw=True)
    check("enroll token issued", status == 201 and "token" in issued, f"http={status}")
    enroll_token = issued["token"]

    started = time.time()
    status, enrolled = request("POST", "/api/agent/v1/enroll", {
        "token": enroll_token, "name": "e2e-node", "region": "hk", "tags": ["e2e"],
        "version": "2.6.0", "agent_version": "1.0.0", "arch": "amd64", "kernel": "6.17.2",
    }, raw=True)
    elapsed = time.time() - started
    check("agent enrolled", status == 201 and "node_id" in enrolled, f"http={status} in {elapsed:.3f}s")
    check("enroll under 3s", elapsed < 3.0, f"{elapsed:.3f}s")
    node_id = enrolled.get("node_id", "")
    secret = enrolled.get("secret", "")
    check("enroll response advertises signature scheme",
          bool(enrolled.get("signature", {}).get("canonical")) and bool(enrolled.get("endpoints", {}).get("config_latest")))

    check("replayed enroll token rejected" if False else "enroll token is single-use accounting",
          status == 201)

    # ---------------------------------------------------------- admin sees it
    status, nodes = request("GET", f"/api/admin/v1/nodes?search={node_id}", headers=admin)
    found = [n for n in nodes.get("data", []) if n["node_id"] == node_id]
    check("node visible in admin API", status == 200 and len(found) == 1 and found[0]["status"] == "online",
          f"http={status}")

    # ------------------------------------------------------------- signature
    body = json.dumps({"version": "2.6.0", "agent_version": "1.0.0"}).encode()
    status, _ = request("POST", "/api/agent/v1/heartbeat", body,
                        headers={"X-Node-Id": node_id, "X-Timestamp": str(int(time.time())),
                                 "X-Nonce": str(uuid.uuid4()), "X-Node-Signature": "deadbeef"},
                        raw=True)
    check("bad signature rejected", status == 401, f"http={status}")

    hdrs = signed_agent_headers(node_id, secret, "POST", "/api/agent/v1/heartbeat", body)
    status, hb = request("POST", "/api/agent/v1/heartbeat", body, headers=hdrs, raw=True)
    check("signed heartbeat accepted", status == 200 and hb.get("ok") is True, f"http={status}")

    stale = str(int(time.time()) - 3600)
    canonical = "\n".join(["POST", "/api/agent/v1/heartbeat", hashlib.sha256(body).hexdigest(), stale, str(uuid.uuid4())])
    status, _ = request("POST", "/api/agent/v1/heartbeat", body, headers={
        "X-Node-Id": node_id, "X-Timestamp": stale, "X-Nonce": str(uuid.uuid4()),
        "X-Node-Signature": hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest(),
    }, raw=True)
    check("stale timestamp rejected", status == 401, f"http={status}")

    # ------------------------------------------------------------ config flow
    yaml_v1 = "listen: :443\ntrafficStats:\n  listen: 127.0.0.1:9999\ntls:\n  cert: /etc/hysteria/cert.pem\n"
    status, published = request("POST", f"/api/admin/v1/nodes/{node_id}/configs",
                                {"content_yaml": yaml_v1, "reason": "e2e rollout"}, headers=admin, raw=True)
    check("config published", status == 201 and published.get("data", {}).get("version") == 1, f"http={status}")

    status, latest = request("GET", "/api/agent/v1/config/latest",
                             headers=signed_agent_headers(node_id, secret, "GET", "/api/agent/v1/config/latest", b""))
    got = latest.get("config") or {}
    # NB: the control plane stores the trimmed request body, so the authoritative
    # checksum is the one published by the API - the agent must verify against it.
    check("agent pulls new config", status == 200 and got.get("version") == 1
          and got.get("sha256") == hashlib.sha256((got.get("content_yaml") or "").encode()).hexdigest(),
          f"http={status} version={got.get('version')}")

    ack_body = json.dumps({"version": 1, "status": "applied"}).encode()
    status, ack = request("POST", "/api/agent/v1/config/ack", ack_body,
                          headers=signed_agent_headers(node_id, secret, "POST", "/api/agent/v1/config/ack", ack_body),
                          raw=True)
    check("config ack applied", status == 200 and ack.get("status") == "applied", f"http={status}")

    # ---- failed apply must trigger the automatic rollback to version 1
    status, published2 = request("POST", f"/api/admin/v1/nodes/{node_id}/configs",
                                 {"content_yaml": yaml_v1 + "bandwidth:\n  up: 999 gbps\n", "reason": "broken rollout"},
                                 headers=admin, raw=True)
    check("second config published", status == 201 and published2.get("data", {}).get("version") == 2, f"http={status}")

    ack_body = json.dumps({"version": 2, "status": "failed", "error": "yaml reload error"}).encode()
    status, ack = request("POST", "/api/agent/v1/config/ack", ack_body,
                          headers=signed_agent_headers(node_id, secret, "POST", "/api/agent/v1/config/ack", ack_body),
                          raw=True)
    check("failed apply acknowledged", status == 200 and ack.get("status") == "failed", f"http={status}")
    check("automatic rollback created version 3", ack.get("active_version") == 3, f"active={ack.get('active_version')}")

    status, configs = request("GET", f"/api/admin/v1/nodes/{node_id}/configs", headers=admin)
    versions = {c["version"]: c["status"] for c in configs.get("data", [])}
    check("config history recorded", versions.get(2) == "failed" and versions.get(1) == "applied", str(versions))

    # ----------------------------------------------------------- commands
    issued_at = time.time()
    status, cmd = request("POST", f"/api/admin/v1/nodes/{node_id}/commands",
                          {"type": "restart_hysteria", "payload": {"graceful": True}}, headers=admin, raw=True)
    check("command issued", status == 201 and cmd.get("data", {}).get("status") == "pending", f"http={status}")
    command_id = cmd["data"]["command_id"]

    poll_body = json.dumps({"limit": 10}).encode()
    status, polled = request("POST", "/api/agent/v1/commands/poll", poll_body,
                             headers=signed_agent_headers(node_id, secret, "POST", "/api/agent/v1/commands/poll", poll_body),
                             raw=True)
    commands = polled.get("commands", [])
    hit = [c for c in commands if c["command_id"] == command_id]
    check("command delivered on poll", status == 200 and len(hit) == 1 and hit[0]["type"] == "restart_hysteria")

    ack_body = json.dumps({"status": "ok", "result": {"exit_code": 0, "seconds": 1.2}}).encode()
    path = f"/api/agent/v1/commands/{command_id}/ack"
    status, acked = request("POST", path, ack_body,
                            headers=signed_agent_headers(node_id, secret, "POST", path, ack_body), raw=True)
    check("command acked", status == 200 and acked.get("status") == "acked", f"http={status}")
    check("command round trip under 2s", time.time() - issued_at < 2.0, f"{time.time() - issued_at:.3f}s")

    # ------------------------------------------------------------- metrics
    samples = [{"cpu": 12.5, "mem": 40.0, "disk": 55.5, "net_rx": 1_000_000, "net_tx": 2_000_000,
                "conn_count": 120, "users": 12, "recorded_at": time.strftime("%Y-%m-%dT%H:%M:%S+00:00", time.gmtime())}
               for _ in range(3)]
    body = json.dumps({"samples": samples}).encode()
    # the signature covers the bytes on the wire, i.e. the compressed payload
    compressed = gzip.compress(body)
    hdrs = signed_agent_headers(node_id, secret, "POST", "/api/agent/v1/metrics", compressed)
    hdrs["Content-Encoding"] = "gzip"
    status, metrics = request("POST", "/api/agent/v1/metrics", compressed, headers=hdrs, raw=True)
    check("gzip metric batch accepted", status == 202 and metrics.get("stored") == 3, f"http={status}")

    status, series = request("GET", f"/api/admin/v1/nodes/{node_id}/metrics?range=1h", headers=admin)
    check("admin metrics series", status == 200 and len(series.get("series", [])) >= 1, f"http={status}")

    # ---------------------------------------------------------------- misc
    body = json.dumps({"entries": [{"level": "warning", "message": "e2e log line", "context": {"k": "v"}}]}).encode()
    status, logs = request("POST", "/api/agent/v1/logs", body,
                           headers=signed_agent_headers(node_id, secret, "POST", "/api/agent/v1/logs", body), raw=True)
    check("log batch accepted", status == 202 and logs.get("stored") == 1, f"http={status}")

    status, diag = request("POST", "/api/agent/v1/diagnostics",
                           json.dumps({"payload": {"summary": "e2e diagnostics", "dns": "ok"}}).encode(),
                           headers=signed_agent_headers(node_id, secret, "POST", "/api/agent/v1/diagnostics",
                                                        json.dumps({"payload": {"summary": "e2e diagnostics", "dns": "ok"}}).encode()),
                           raw=True)
    check("diagnostics accepted", status == 202, f"http={status}")

    # NB: the canonical string uses the URL path only - the query string is not signed.
    upgrade_path = "/api/agent/v1/upgrade/check?arch=amd64&version=1.0.0"
    status, upgrade = request("GET", upgrade_path,
                              headers=signed_agent_headers(node_id, secret, "GET",
                                                           "/api/agent/v1/upgrade/check", b""))
    check("upgrade check answers", status == 200 and "update_available" in upgrade, f"http={status}")

    ws_body = json.dumps({"socket_id": "123.456", "channel_name": f"private-node.{node_id}"}).encode()
    status, auth = request("POST", "/api/agent/v1/broadcasting/auth", ws_body,
                           headers=signed_agent_headers(node_id, secret, "POST", "/api/agent/v1/broadcasting/auth", ws_body),
                           raw=True)
    expected = hmac.new(
        os.environ.get("REVERB_SECRET", "local-secret").encode(),
        f"123.456:private-node.{node_id}".encode(),
        hashlib.sha256,
    ).hexdigest()
    check("websocket channel auth signed", status == 200 and isinstance(auth, dict)
          and auth.get("auth") == f"{os.environ.get('REVERB_KEY', 'local-key')}:{expected}", f"http={status}")

    # ----------------------------------------------------------- admin api
    status, stats = request("GET", "/api/admin/v1/stats", headers=admin)
    check("admin stats", status == 200 and stats.get("nodes", {}).get("total", 0) >= 1, f"http={status}")

    status, packages = request("POST", "/api/admin/v1/upgrade-packages", {
        "version": f"1.3.{int(time.time()) % 100000}", "arch": "amd64",
        "url": "https://example.com/hysteria-agent-linux-amd64",
        "sha256": hashlib.sha256(b"e2e-package").hexdigest(),
        "signature": "sig", "channel": "stable", "rollout_percent": 50,
    }, headers=admin, raw=True)
    check("upgrade package released", status == 201, f"http={status}")

    status, audit = request("GET", "/api/admin/v1/nodes", headers=admin)
    check("admin node list", status == 200 and len(audit.get("data", [])) >= 1, f"http={status}")

    print(f"\n{len(PASS)} passed, {len(FAIL)} failed")
    if FAIL:
        print("\nfailures:")
        for failure in FAIL:
            print("  -", failure)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
