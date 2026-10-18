#!/usr/bin/env python3
"""Disposable prod-image operational checks. Run on the staging host with sudo.

Never loads staging.env. Prints only assertions and aggregate results. SMTP has
no external route. A finally block removes only this script's Compose project.
"""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parent.parent
PROJECT = "equo-ops-check"


def call(args, *, allowed=(0,), timeout=180):
    result = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=timeout)
    if result.returncode not in allowed:
        # Raw command output can include credentials or exception details.
        raise RuntimeError(f"Command failed (exit {result.returncode}); raw output withheld")
    return result


def report(message):
    print(message, flush=True)


def check(condition, label):
    if not condition:
        raise RuntimeError(label)


def main():
    existing = call(["docker", "ps", "-aq", "--filter", f"label=com.docker.compose.project={PROJECT}"]).stdout.strip()
    network = call(["docker", "network", "ls", "-q", "--filter", f"label=com.docker.compose.project={PROJECT}"]).stdout.strip()
    check(not existing and not network, "Refusing to reuse an existing operations project")
    check(os.geteuid() == 0, "Run with sudo on the staging host")
    with tempfile.TemporaryDirectory(prefix="equo-operations-") as folder:
        work = Path(folder)
        state = work / "state"
        state.mkdir(mode=0o700)
        env = work / "test.env"
        call(["python3", str(ROOT / "scripts/init-staging-env.py"), "--host", "equo-ops.test", "--output", str(env)])
        values = env.read_text().replace("POSTGRES_DB='equo'", "POSTGRES_DB='equo_ops_test'")
        values = values.replace("@postgres:5432/equo?", "@postgres:5432/equo_ops_test?")
        values = values.replace("smtp://mailpit:1025", "smtp://smtp:1025")
        env.write_text(values + f"OPS_STATE_DIR='{state}'\n")
        (state / "smtp-mode").write_text("accept")
        base = ["docker", "compose", "--env-file", str(env), "-p", PROJECT, "-f", str(ROOT / "compose.operations.yaml")]

        def dc(*args, **kwargs):
            return call(base + list(args), **kwargs)

        def probe(action, label=""):
            return json.loads(dc("exec", "-T", "backend", "php", "/probe/probe.php", action, label).stdout)

        def console(*args, **kwargs):
            return dc("exec", "-T", "backend", "php", "bin/console", *args, "--no-interaction", **kwargs)

        def count(name):
            path = state / name
            return int(path.read_text()) if path.exists() else 0

        def until(predicate, label, timeout=120):
            end = time.monotonic() + timeout
            progress = time.monotonic() + 30
            while time.monotonic() < end:
                if predicate():
                    return
                if time.monotonic() >= progress:
                    report("Waiting: " + label)
                    progress += 30
                time.sleep(2)
            raise RuntimeError("Timed out: " + label)

        def sent(label):
            row = probe("status", label)
            return row["status"] == "SENT" and row["cleared"] is True

        config = json.loads(dc("config", "--format", "json").stdout)
        check(config["networks"]["equo"]["internal"] is True, "Internal network required")
        for name, service in config["services"].items():
            check(not service.get("ports"), "No host ports allowed")
            check(str(service.get("mem_limit", "0")) not in ("0", ""), "Memory limit required")
            for volume in service.get("volumes", []):
                check(volume["type"] == "bind", "No existing named volumes allowed")
                source = Path(volume["source"]).resolve()
                check(source == state.resolve() or source == (ROOT / "scripts/operations").resolve(), "Unexpected mount")
            if name in ("backend", "worker", "retention"):
                settings = service["environment"]
                check("/equo_ops_test?" in settings["DATABASE_URL"], "Test DB required")
                check(settings["MAILER_DSN"] == "smtp://smtp:1025", "Internal SMTP required")
        report("PASS isolation: separate DB/keys, internal network, no host ports, memory/CPU limits")

        try:
            dc("up", "-d", "--no-build", "--pull", "never", "--wait", "postgres", "redis", "rabbitmq", "smtp", "backend")
            console("doctrine:migrations:migrate")
            console("doctrine:migrations:up-to-date")
            live = json.loads(call(["docker", "inspect", "equo-staging-backend-1"]).stdout)[0]["Image"]
            test_id = dc("ps", "-q", "backend").stdout.strip()
            test = json.loads(call(["docker", "inspect", test_id]).stdout)[0]["Image"]
            check(live == test, "Test and staging backend images differ")
            report("PASS migrations and exact staging backend image identity")

            probe("retry-schedule")
            (state / "smtp-mode").write_text("fail")
            probe("register", "retry")
            dc("up", "-d", "--no-build", "--pull", "never", "worker")
            until(lambda: count("rejected") >= 1, "initial SMTP failure")
            first_failure = time.monotonic()
            row = probe("status", "retry")
            check(row["status"] == "PUBLISHED" and row["cleared"] is False, "Failed handoff must retain durable intent")
            (state / "smtp-mode").write_text("accept")
            until(lambda: sent("retry"), "real 60-second SMTP retry", 100)
            elapsed = time.monotonic() - first_failure
            check(elapsed >= 50 and count("accepted") == 1, "Retry timing or delivery count")
            report(f"PASS temporary SMTP failure: real retry after ~{elapsed:.0f}s; SENT; payload cleared")

            dc("stop", "worker")
            (state / "smtp-mode").write_text("fail")
            probe("register", "terminal")
            console("app:email-outbox:relay")
            probe("seed-final-attempt", "terminal")
            dc("start", "worker")
            until(lambda: probe("status", "terminal")["status"] == "FAILED", "final SMTP attempt")
            check(probe("status", "terminal")["cleared"] is True, "Terminal secret clearing")
            metrics = json.loads(console("app:email-outbox:status").stdout)
            report("Terminal failure aggregate diagnostics: " + json.dumps(metrics, sort_keys=True))
            failed = console("app:email-outbox:failed", "--limit=20").stdout
            check(probe("id", "terminal")["id"] in failed, "Failure queue must expose safe delivery ID")
            refused = console("app:email-outbox:replay", probe("id", "terminal")["id"], allowed=(0, 1, 2))
            check(refused.returncode != 0, "Terminal replay must be refused")
            report("PASS terminal failure, cleared payload, observable failure queue, refused terminal replay")
            report("LIMIT: final-attempt fixture uses retry count 5; full 7h21m delay chain was not waited")

            dc("stop", "worker")
            (state / "smtp-mode").write_text("accept")
            probe("register", "replay")
            console("app:email-outbox:relay")
            before = probe("business-counts")
            console("app:email-outbox:replay", probe("id", "replay")["id"])
            dc("start", "worker")
            until(lambda: sent("replay"), "safe replay delivery")
            # Stop waits for the active consumer; allow duplicate envelope handling first.
            time.sleep(6)
            dc("stop", "worker")
            check(before == probe("business-counts") and count("accepted") == 2, "Replay duplicated business state or message handoff")
            report("PASS safe replay of deliverable intent; duplicate queue envelope does not repeat SMTP handoff")

            (state / "smtp-mode").write_text("delay")
            probe("register", "shutdown")
            dc("start", "worker")
            until(lambda: count("received") >= 3, "SMTP handoff in progress")
            started = time.monotonic()
            dc("stop", "worker", timeout=100)
            elapsed = time.monotonic() - started
            worker_id = dc("ps", "-aq", "worker").stdout.strip()
            worker_state = json.loads(call(["docker", "inspect", worker_id]).stdout)[0]["State"]
            check(worker_state["ExitCode"] == 0 and not worker_state["OOMKilled"], "Graceful exit required")
            check(sent("shutdown") and count("accepted") == 3 and elapsed >= 5, "In-flight SMTP handoff was not completed")
            (state / "smtp-mode").write_text("accept")
            dc("start", "worker")
            time.sleep(6)
            check(count("accepted") == 3, "Restart repeated completed handoff")
            dc("stop", "worker")
            report(f"PASS SIGTERM during SMTP handoff: waited ~{elapsed:.0f}s, exit 0, SENT, no repeat on restart")

            probe("seed-retention")
            dc("up", "-d", "--no-build", "--pull", "never", "retention")
            def retention_done():
                try:
                    probe("check-retention")
                    return True
                except RuntimeError:
                    return False
            until(retention_done, "scheduled retention cleanup", 60)
            dc("stop", "retention")
            report("PASS scheduler: old token/outbox/session/invitation/idempotency removed; recent/active preserved; expired intent FAILED and secret cleared")
            dc("start", "retention")
            time.sleep(6)
            probe("check-retention")
            report("PASS second scheduler execution preserves retention invariants")
            report("PASS all operational acceptance scenarios")
        finally:
            dc("down", "--remove-orphans", timeout=120)
            check(not dc("ps", "-aq").stdout.strip(), "Test containers remain")
            report("CLEANUP: test containers/network removed; temporary fixtures/keys removed on exit")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        # All intentional errors above use fixed, non-sensitive labels.
        if isinstance(error, RuntimeError):
            report("FAIL: " + str(error))
        else:
            report("FAIL: " + type(error).__name__)
        raise SystemExit(1)
