"""Browser-facing integration checks against a fresh CI database."""
import http.cookiejar
import re
import subprocess
import urllib.parse
import urllib.request


BASE = "http://127.0.0.1:8080"


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )

    def request(self, path, data=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        with self.opener.open(BASE + path, body, timeout=10) as response:
            return response.geturl(), response.read().decode()

    def post(self, path, **values):
        _, page = self.request(path)
        values["csrf"] = re.search(r'name="csrf" value="([0-9a-f]{64})"', page)[1]
        return self.request(path, values)[1]

    def register(self, name, email):
        result = self.post("/account.php", action="register", name=name, email=email,
                           password="test-only-password-2026")
        assert "Mein Profil" in result


alice, bob = Client(), Client()
assert alice.request("/archive.php")[0].endswith("/account.php")
_, guest_shop = alice.request("/")
assert 'class="nav-button" href="/archive.php">Bestellarchiv' in guest_shop
assert 'href="/account.php#login">Anmelden' in guest_shop
assert 'href="/account.php#register">Registrieren' in guest_shop
_, guest_account = alice.request("/account.php")
assert 'id="login"' in guest_account and 'id="register"' in guest_account

alice.register("Archive Test Alice", "alice@example.test")
bob.register("Archive Test Bob", "bob@example.test")
alice.post("/", action="quantity", id="1", quantity="1")
receipt = alice.post("/", action="checkout")
order_id = re.search(r'/archive.php\?order=(\d+)', receipt)[1]
path = f"/archive.php?order={order_id}"
_, own = alice.request(path)
assert "Archive Test Alice" in own and "Elbe Espresso" in own
assert 'href="/archive.php" aria-current="page">Meine Bestellungen' in own
assert 'href="/account.php#profile">Meine Kundendaten' in own
assert 'href="/#cart">Warenkorb (0)' in own
assert re.search(r"\d{2}\.\d{2}\.\d{4}, \d{2}:\d{2}:\d{2}", own)
assert "Keine passenden Bestellungen." in bob.request(path + "&customer=1")[1]
assert "Archive Test Alice" not in bob.request(path)[1]

edited = alice.post("/account.php", action="profile", name="Alice Updated", email="updated@example.test")
assert 'value="updated@example.test"' in edited
assert 'href="/account.php#profile" aria-current="page"' in edited
assert "Archive Test Alice" in alice.request(path)[1]

subprocess.run(["docker", "compose", "exec", "-T", "web", "php", "bin/grant-staff.php", "bob@example.test"], check=True)
_, staff = bob.request(path)
assert "Alle Bestellungen" in staff and "Archive Test Alice" in staff
assert 'href="/archive.php" aria-current="page">Bestellarchiv · Alle Bestellungen' in staff
assert "Bestellarchiv · Alle Bestellungen" not in own
assert "Konto erstellen" in alice.post("/account.php", action="logout")
assert alice.request("/archive.php")[0].endswith("/account.php")
print("PASS: navigation, registration, checkout, profile, private history, staff access and logout")
