#!/usr/bin/env python3
"""Enter Brevo credentials on the host without exposing them in shell history."""
import getpass
import os
from pathlib import Path
import re
import tempfile
from urllib.parse import quote

destination = Path("/etc/equo/staging.env")
current = destination.read_text()
sender = input("Verified sender email in Brevo: ").strip()
if not re.fullmatch(r"[A-Za-z0-9.!#$%&*+/=?^_`{|}~-]+@[A-Za-z0-9.-]+", sender):
    raise SystemExit("Enter a plain verified email address, without a display name.")
username = input("Brevo SMTP login (not the sender email): ").strip()
password = getpass.getpass("Brevo SMTP key (input hidden): ")
if not username or not password:
    raise SystemExit("SMTP login and key must not be empty.")
dsn = "smtps://" + quote(username, safe="") + ":" + quote(password, safe="") + "@smtp-relay.brevo.com:465"
updates = {"BACKEND_MAILER_DSN": dsn, "MAILER_FROM": sender}
lines = current.splitlines()
for name, value in updates.items():
    matches = [i for i, line in enumerate(lines) if line.startswith(name + "=")]
    if len(matches) != 1:
        raise SystemExit("Environment file has missing or duplicate mail settings; no changes written.")
    lines[matches[0]] = name + "='" + value + "'"
descriptor, temporary = tempfile.mkstemp(dir=destination.parent)
try:
    with os.fdopen(descriptor, "w") as output:
        output.write("\n".join(lines) + "\n")
    os.replace(temporary, destination)
finally:
    if os.path.exists(temporary):
        os.unlink(temporary)
print("SMTP settings saved privately. Recreate backend, worker and retention to apply them.")
