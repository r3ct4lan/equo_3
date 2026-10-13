import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const scriptDirectory = dirname(fileURLToPath(import.meta.url));
const allowlistPath = resolve(scriptDirectory, 'accepted-audit-advisories.json');
const allowlist = JSON.parse(readFileSync(allowlistPath, 'utf8'));
const acceptedIds = new Set(allowlist.advisories.map(({ id }) => id.toUpperCase()));
const npmCommand = process.platform === 'win32' ? 'npm.cmd' : 'npm';
const result = spawnSync(npmCommand, ['audit', '--audit-level=moderate', '--json'], {
  encoding: 'utf8',
  maxBuffer: 16 * 1024 * 1024,
});

function fail(message) {
  process.stderr.write(`${message}\n`);
  process.exit(1);
}

if (result.error) {
  fail(`Could not run npm audit: ${result.error.message}`);
}

let report;
try {
  report = JSON.parse(result.stdout);
} catch {
  const detail = result.stderr.trim();
  fail(`npm audit did not return a valid JSON report${detail ? `: ${detail}` : '.'}`);
}

if (!report.vulnerabilities || !report.metadata?.vulnerabilities) {
  fail('npm audit report is missing vulnerability metadata; failing closed.');
}

const blockingLevels = new Set(['moderate', 'high', 'critical']);
const vulnerabilities = report.vulnerabilities;
const resolvedIds = new Map();

function advisoryIdsFor(name, visiting = new Set()) {
  if (resolvedIds.has(name)) {
    return resolvedIds.get(name);
  }
  if (visiting.has(name)) {
    return new Set();
  }

  const vulnerability = vulnerabilities[name];
  if (!vulnerability) {
    return new Set();
  }

  const nextVisiting = new Set(visiting).add(name);
  const ids = new Set();
  for (const item of vulnerability.via ?? []) {
    if (typeof item === 'string') {
      for (const id of advisoryIdsFor(item, nextVisiting)) {
        ids.add(id);
      }
      continue;
    }

    const id = item.url?.match(/GHSA-[a-z0-9-]+/i)?.[0]?.toUpperCase();
    if (!id) {
      throw new Error(`Cannot identify advisory for vulnerable package ${name}; failing closed.`);
    }
    ids.add(id);
  }

  resolvedIds.set(name, ids);
  return ids;
}

const unexpected = new Map();
const observedAccepted = new Set();
try {
  for (const [name, vulnerability] of Object.entries(vulnerabilities)) {
    if (!blockingLevels.has(vulnerability.severity)) {
      continue;
    }

    const ids = advisoryIdsFor(name);
    if (ids.size === 0) {
      throw new Error(`Cannot trace ${vulnerability.severity} finding for ${name} to an advisory; failing closed.`);
    }

    for (const id of ids) {
      if (acceptedIds.has(id)) {
        observedAccepted.add(id);
      } else {
        unexpected.set(id, name);
      }
    }
  }
} catch (error) {
  fail(error.message);
}

if (unexpected.size > 0) {
  const findings = [...unexpected].map(([id, name]) => `${id} (${name})`).join(', ');
  fail(`npm audit found unaccepted moderate/high/critical advisories: ${findings}`);
}

if (result.status !== 0 && observedAccepted.size === 0) {
  fail(`npm audit exited with status ${result.status} without a recognized accepted advisory.`);
}

const missing = [...acceptedIds].filter((id) => !observedAccepted.has(id));
if (missing.length > 0 && observedAccepted.size > 0) {
  process.stdout.write(`Accepted advisories no longer reported: ${missing.join(', ')}\n`);
}

const counts = report.metadata.vulnerabilities;
const blockingTotal = ['moderate', 'high', 'critical'].reduce((sum, level) => sum + (counts[level] ?? 0), 0);
if (observedAccepted.size > 0) {
  process.stdout.write(
    `npm audit passed with ${blockingTotal} affected package findings covered by accepted advisories: ${[...observedAccepted].join(', ')}.\n`,
  );
} else {
  process.stdout.write('npm audit passed with no moderate, high, or critical advisories.\n');
}
