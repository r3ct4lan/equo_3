#!/usr/bin/env python3
"""Generate staging-only credentials on the host, never print their values."""
import argparse
import base64
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument("--host", required=True)
parser.add_argument("--output", default="/etc/equo/staging.env")
args = parser.parse_args()
if not re.fullmatch(r"[a-z0-9]+(?:[.-][a-z0-9]+)*", args.host):
    parser.error("host must be a DNS hostname without scheme or path")
destination = Path(args.output)
if destination.exists():
    parser.error("refusing to overwrite existing credentials")

def ring():
    return json.dumps({"v1": base64.b64encode(secrets.token_bytes(32)).decode()})

with tempfile.TemporaryDirectory() as temporary:
    key = Path(temporary) / "jwt.pem"
    subprocess.run(["openssl", "genpkey", "-algorithm", "RSA", "-pkeyopt",
                    "rsa_keygen_bits:2048", "-out", str(key)],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    public = subprocess.check_output(["openssl", "pkey", "-in", str(key), "-pubout"],
                                     stderr=subprocess.DEVNULL)
    private_encoded = base64.b64encode(key.read_bytes()).decode()
    public_encoded = base64.b64encode(public).decode()

database_password = secrets.token_hex(32)
broker_password = secrets.token_hex(32)
origin = "https://" + args.host
values = {
    "APP_HOST": args.host,
    "APP_SECRET": secrets.token_hex(32),
    "POSTGRES_DB": "equo",
    "POSTGRES_USER": "equo",
    "POSTGRES_PASSWORD": database_password,
    "BACKEND_DATABASE_URL": f"postgresql://equo:{database_password}@postgres:5432/equo?serverVersion=17&charset=utf8",
    "RABBITMQ_DEFAULT_USER": "equo",
    "RABBITMQ_DEFAULT_PASS": broker_password,
    "BACKEND_MESSENGER_TRANSPORT_DSN": f"amqp://equo:{broker_password}@rabbitmq:5672/%2f/messages",
    "BACKEND_REDIS_URL": "redis://redis:6379",
    # Capture is deliberate until the owner supplies an external SMTP account.
    "BACKEND_MAILER_DSN": "smtp://mailpit:1025",
    "MAILER_FROM": "no-reply@equo.test",
    "DEFAULT_URI": origin,
    "FRONTEND_BASE_URL": origin,
    "EQUO_APPLICATION_ORIGIN": origin,
    "EQUO_INVITATION_SIGNING_VERSION": "v1",
    "EQUO_INVITATION_SIGNING_KEYS": ring(),
    "ACTION_TOKEN_ACTIVE_VERSION": "v1",
    "ACTION_TOKEN_HMAC_KEYS": ring(),
    "IDEMPOTENCY_FINGERPRINT_ACTIVE_VERSION": "v1",
    "IDEMPOTENCY_FINGERPRINT_HMAC_KEYS": ring(),
    "EMAIL_PAYLOAD_KEY": base64.b64encode(secrets.token_bytes(32)).decode(),
    "EQUO_JWT_SIGNING_KEY_VERSION": "v1",
    "EQUO_JWT_SIGNING_PRIVATE_KEY": private_encoded,
    "EQUO_JWT_PUBLIC_KEY_RING": json.dumps({"v1": public_encoded}),
    "EQUO_JWT_ISSUER": origin,
    "EQUO_JWT_AUDIENCE": "equo-api",
    "EQUO_CSRF_SIGNING_KEY_VERSION": "v1",
    "EQUO_CSRF_SIGNING_KEY_RING": ring(),
}
destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(descriptor, "w") as output:
    output.write("# Staging credentials. Never commit or print this file.\n")
    for name, value in values.items():
        output.write(f"{name}='{value}'\n")
print("Created private staging environment; mail is captured locally until SMTP is configured.")
