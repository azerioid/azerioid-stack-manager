#!/usr/bin/env python3
"""Verify /setup and /login via HTTP (Livewire), mirroring browser UI flow."""
from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import html
import json
import re
import ssl
import struct
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar
from typing import Any


def totp_code(secret: str, when: float | None = None) -> str:
    """RFC 6238 TOTP (SHA1, 30s step) for Google Authenticator-compatible secrets."""
    pad = "=" * ((8 - len(secret) % 8) % 8)
    key = base64.b32decode(secret.upper() + pad, casefold=True)
    counter = int((when or time.time()) // 30)
    msg = struct.pack(">Q", counter)
    digest = hmac.new(key, msg, hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    code = struct.unpack(">I", digest[offset : offset + 4])[0] & 0x7FFFFFFF
    return str(code % 1_000_000).zfill(6)


def snapshot_data(snapshot: str) -> dict[str, Any]:
    payload = json.loads(snapshot)
    data = payload.get("data")
    if isinstance(data, dict):
        return data
    memo = payload.get("memo", {})
    inner = memo.get("data")
    return inner if isinstance(inner, dict) else {}


def _component_redirect(result: dict[str, Any]) -> str:
    for comp in result.get("components", []):
        redirect = comp.get("effects", {}).get("redirect")
        if redirect:
            return str(redirect)
    return str(result.get("effects", {}).get("redirect") or "")


def extract_snapshot(page: str) -> tuple[str, str]:
    m = re.search(r'wire:snapshot="([^"]+)"', page)
    if not m:
        raise RuntimeError("wire:snapshot not found on page")
    snapshot = html.unescape(m.group(1))
    csrf = re.search(r'<meta name="csrf-token" content="([^"]+)"', page)
    if not csrf:
        raise RuntimeError("csrf-token meta not found")
    return snapshot, csrf.group(1)


class PanelClient:
    def __init__(self, base: str) -> None:
        self.base = base.rstrip("/")
        self.jar = CookieJar()
        ctx = ssl.create_default_context()
        if self.base.startswith("https://"):
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar),
            urllib.request.HTTPSHandler(context=ctx),
        )

    def get(self, path: str) -> tuple[int, str, dict[str, str]]:
        req = urllib.request.Request(f"{self.base}{path}", method="GET")
        with self.opener.open(req, timeout=30) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            return resp.status, body, dict(resp.headers)

    def livewire_call(
        self,
        path: str,
        snapshot: str,
        csrf: str,
        updates: dict[str, Any],
        method: str,
    ) -> dict[str, Any]:
        payload = {
            "_token": csrf,
            "components": [
                {
                    "snapshot": snapshot,
                    "updates": updates,
                    "calls": [{"path": "", "method": method, "params": []}],
                }
            ],
        }
        data = json.dumps(payload).encode("utf-8")
        req = urllib.request.Request(
            f"{self.base}/livewire/update",
            data=data,
            method="POST",
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-Livewire": "true",
                "X-CSRF-TOKEN": csrf,
                "Referer": f"{self.base}{path}",
            },
        )
        with self.opener.open(req, timeout=30) as resp:
            return json.loads(resp.read().decode("utf-8"))

    def post(self, path: str, csrf: str) -> tuple[int, str]:
        data = urllib.parse.urlencode({"_token": csrf}).encode("utf-8")
        req = urllib.request.Request(
            f"{self.base}{path}",
            data=data,
            method="POST",
            headers={"Referer": f"{self.base}/"},
        )
        with self.opener.open(req, timeout=30) as resp:
            return resp.status, resp.read().decode("utf-8", errors="replace")


def main() -> int:
    parser = argparse.ArgumentParser(description="UI-flow setup + first-login verification")
    parser.add_argument("--base", default="http://127.0.0.1:3169")
    parser.add_argument("--email", default="azerioid@gmail.com")
    parser.add_argument("--password", default="AdminPassw0rd!")
    parser.add_argument("--name", default="Admin")
    parser.add_argument("--skip-setup", action="store_true")
    parser.add_argument("--typo-test", action="store_true", help="Try two wrong passwords before real login")
    parser.add_argument(
        "--require-totp-setup",
        action="store_true",
        help="After login, require redirect to /two-factor/setup",
    )
    parser.add_argument(
        "--complete-totp",
        action="store_true",
        help="With --require-totp-setup, complete 2FA enrollment and verify dashboard login",
    )
    parser.add_argument(
        "--verify-second-login",
        action="store_true",
        help="With --complete-totp, log out and confirm second login uses TOTP verify (not setup)",
    )
    args = parser.parse_args()

    client = PanelClient(args.base)
    evidence: list[str] = []

    if not args.skip_setup:
        status, page, _ = client.get("/setup")
        if status != 200:
            print(f"FAIL setup page HTTP {status}", file=sys.stderr)
            return 1
        snapshot, csrf = extract_snapshot(page)
        result = client.livewire_call(
            "/setup",
            snapshot,
            csrf,
            {
                "name": args.name,
                "email": args.email,
                "password": args.password,
                "password_confirmation": args.password,
            },
            "createAccount",
        )
        redirect = _component_redirect(result)
        if not redirect:
            print(f"FAIL setup createAccount: {json.dumps(result)[:500]}", file=sys.stderr)
            return 1
        evidence.append(f"setup redirect={redirect}")

        # Log out to prove fresh login (POST /logout)
        status, page, _ = client.get("/")
        if status not in (200, 302):
            print(f"FAIL dashboard after setup HTTP {status}", file=sys.stderr)
            return 1
        _, _, headers = client.get("/")
        # refresh csrf from any page
        status, page, _ = client.get("/settings")
        snapshot, csrf = extract_snapshot(page) if "wire:snapshot" in page else ("", "")
        if not csrf:
            m = re.search(r'<meta name="csrf-token" content="([^"]+)"', page)
            csrf = m.group(1) if m else ""
        try:
            client.post("/logout", csrf)
            evidence.append("logout ok")
        except urllib.error.HTTPError as e:
            if e.code not in (302, 303):
                print(f"FAIL logout HTTP {e.code}", file=sys.stderr)
                return 1
            evidence.append("logout redirected")

    # Fresh login session
    login_client = PanelClient(args.base)
    status, page, _ = login_client.get("/login")
    if status != 200:
        print(f"FAIL login page HTTP {status}", file=sys.stderr)
        return 1
    if "LACMP Panel" in page or "LCMP Panel" in page:
        print("FAIL login page still shows legacy LACMP/LCMP Panel branding", file=sys.stderr)
        return 1
    if "AZERIOID Stack Manager" not in page:
        print("FAIL login page missing AZERIOID Stack Manager branding", file=sys.stderr)
        return 1
    snapshot, csrf = extract_snapshot(page)

    if args.typo_test:
        for wrong in ("wrong-password-1!", "wrong-password-2!"):
            bad = login_client.livewire_call(
                "/login",
                snapshot,
                csrf,
                {"email": args.email, "password": wrong},
                "authenticate",
            )
            snap = bad.get("components", [{}])[0].get("snapshot")
            if snap:
                snapshot = snap
            errs = bad.get("components", [{}])[0].get("snapshot", "")
            if "Those credentials do not match" not in errs and "errors" not in json.dumps(bad):
                print(f"WARN typo did not surface expected error: {json.dumps(bad)[:300]}")
        evidence.append("typo attempts did not lock out")

    result = login_client.livewire_call(
        "/login",
        snapshot,
        csrf,
        {"email": args.email, "password": args.password},
        "authenticate",
    )
    redirect = _component_redirect(result)
    if "/login" in redirect or not redirect:
        print(f"FAIL first-attempt login: {json.dumps(result)[:500]}", file=sys.stderr)
        return 1
    evidence.append(f"login redirect={redirect}")

    if args.require_totp_setup:
        if "two-factor/setup" not in redirect:
            print(f"FAIL expected /two-factor/setup redirect, got {redirect}", file=sys.stderr)
            return 1
        evidence.append("totp enrollment required")

        if args.complete_totp:
            status, setup_page, _ = login_client.get("/two-factor/setup")
            if status != 200:
                print(f"FAIL TOTP setup page HTTP {status}", file=sys.stderr)
                return 1
            if "LACMP Panel" in setup_page or "LCMP Panel" in setup_page:
                print("FAIL TOTP setup page still shows legacy LACMP/LCMP Panel branding", file=sys.stderr)
                return 1
            if "AZERIOID Stack Manager" not in setup_page:
                print("FAIL TOTP setup page missing AZERIOID Stack Manager branding", file=sys.stderr)
                return 1
            if "Authenticator code" in setup_page and "Enable 2FA" not in setup_page:
                print("FAIL landed on verify screen instead of enroll/setup", file=sys.stderr)
                return 1
            setup_snapshot, setup_csrf = extract_snapshot(setup_page)
            secret = str(snapshot_data(setup_snapshot).get("secret") or "")
            if len(secret) < 8:
                print("FAIL could not read TOTP secret from setup page snapshot", file=sys.stderr)
                return 1
            code = totp_code(secret)
            confirm = login_client.livewire_call(
                "/two-factor/setup",
                setup_snapshot,
                setup_csrf,
                {"code": code},
                "confirm",
            )
            confirm_redirect = _component_redirect(confirm)
            if not confirm_redirect or "login" in confirm_redirect:
                print(f"FAIL TOTP confirm: {json.dumps(confirm)[:500]}", file=sys.stderr)
                return 1
            evidence.append(f"totp confirm redirect={confirm_redirect}")

            status, dash, _ = login_client.get("/")
            if status != 200 or "Sign in" in dash:
                print("FAIL dashboard not authenticated after TOTP enrollment", file=sys.stderr)
                return 1
            evidence.append("dashboard authenticated after totp enrollment")

            if args.verify_second_login:
                status, settings_page, _ = login_client.get("/settings")
                _, logout_csrf = extract_snapshot(settings_page) if "wire:snapshot" in settings_page else ("", "")
                if not logout_csrf:
                    m = re.search(r'<meta name="csrf-token" content="([^"]+)"', settings_page)
                    logout_csrf = m.group(1) if m else csrf
                try:
                    login_client.post("/logout", logout_csrf)
                    evidence.append("logout after enrollment ok")
                except urllib.error.HTTPError as e:
                    if e.code not in (302, 303):
                        print(f"FAIL logout HTTP {e.code}", file=sys.stderr)
                        return 1
                    evidence.append("logout after enrollment redirected")

                verify_client = PanelClient(args.base)
                status, login_page, _ = verify_client.get("/login")
                snap2, csrf2 = extract_snapshot(login_page)
                relogin = verify_client.livewire_call(
                    "/login",
                    snap2,
                    csrf2,
                    {"email": args.email, "password": args.password},
                    "authenticate",
                )
                relogin_redirect = _component_redirect(relogin)
                if "two-factor/challenge" not in relogin_redirect:
                    print(
                        f"FAIL second login expected /two-factor/challenge, got {relogin_redirect}",
                        file=sys.stderr,
                    )
                    return 1
                evidence.append(f"second login redirect={relogin_redirect}")

                status, challenge_page, _ = verify_client.get("/two-factor/challenge")
                if "Authenticator code" not in challenge_page:
                    print("FAIL second login did not show verify/challenge screen", file=sys.stderr)
                    return 1
                if "Enable 2FA" in challenge_page:
                    print("FAIL second login incorrectly showed enroll screen", file=sys.stderr)
                    return 1
                ch_snap, ch_csrf = extract_snapshot(challenge_page)
                verify = verify_client.livewire_call(
                    "/two-factor/challenge",
                    ch_snap,
                    ch_csrf,
                    {"code": totp_code(secret)},
                    "verify",
                )
                verify_redirect = _component_redirect(verify)
                if not verify_redirect or "login" in verify_redirect:
                    print(f"FAIL TOTP verify on second login: {json.dumps(verify)[:500]}", file=sys.stderr)
                    return 1
                evidence.append(f"second login totp verify redirect={verify_redirect}")

                status, dash2, _ = verify_client.get("/")
                if status != 200 or "Sign in" in dash2:
                    print("FAIL dashboard not authenticated after second-login TOTP verify", file=sys.stderr)
                    return 1
                evidence.append("dashboard authenticated after second-login verify")
        else:
            status, setup_page, _ = login_client.get("/two-factor/setup")
            if status != 200 or "wire:snapshot" not in setup_page:
                print("FAIL TOTP setup page not reachable after login", file=sys.stderr)
                return 1
            evidence.append("totp setup page reachable")

        print("PASS ui-flow-verify")
        for line in evidence:
            print(f"  {line}")
        return 0

    status, dash, _ = login_client.get("/")
    if status != 200 or "Sign in" in dash:
        print("FAIL dashboard not authenticated after login", file=sys.stderr)
        return 1
    evidence.append("dashboard authenticated")

    print("PASS ui-flow-verify")
    for line in evidence:
        print(f"  {line}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
