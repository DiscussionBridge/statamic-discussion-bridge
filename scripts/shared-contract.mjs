import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { preflightDirectory, preflightFile, statOrAbsent } from "./managed-output.mjs";

// Explicit maintainer refresh only. Customer installation uses committed bytes;
// neither Composer hooks nor the application fetch or generate dependencies.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const shared = "src/Cms/Shared";
const pins = Object.freeze({
  commit: "b90348f9f010ff58fbe52237eda3187c926334ec",
  candidate: "discussionbridge-v4-core-20261029-r7",
  artifacts: "76b65ee100c1c0157e34c06b00a3ff128cf06e7b2a76de900ac5c95288732224",
  normative: "808042688cbde88fac0acdf1187e4cb8c251e13025bfcf09cf3a3c361ca02003",
  codec: "9ff58c7e9547167bc8f546483c040daa0232cad171ddd48c15f8b9c821294ed7",
  schema: "e6a15a6d70f7bc8b8ee82aa22a0b9c2ff1bbf2da6277f7087118be9e026ce5c0",
  idna: "5f9b6bf922e65ed4fee1c7ebad807d266140a2fc262fdd452da607f0cfd40e1e",
});
const hash = value => createHash("sha256").update(value).digest("hex");
function safe(relative) {
  assert(typeof relative === "string" && /^[A-Za-z0-9_.\/-]+$/.test(relative));
  assert(!relative.startsWith("/") && !relative.split("/").some(p => !p || p === "." || p === ".."));
  return relative;
}
function read(base, relative) {
  safe(relative);
  let at = base;
  for (const part of relative.split("/")) {
    at = path.join(at, part);
    assert(!fs.lstatSync(at).isSymbolicLink(), `Symlink refused: ${relative}`);
  }
  assert(fs.lstatSync(at).isFile(), `Not a regular member: ${relative}`);
  return fs.readFileSync(at);
}
function inventory(base, relative) {
  const files = [];
  function walk(at, prefix) {
    for (const name of fs.readdirSync(at)) {
      const full = path.join(at, name), rel = prefix + name, stat = fs.lstatSync(full);
      assert(!stat.isSymbolicLink(), `Symlink refused: ${rel}`);
      if (stat.isDirectory()) walk(full, `${rel}/`);
      else { assert(stat.isFile(), `Non-file member: ${rel}`); files.push(rel); }
    }
  }
  walk(path.join(base, relative), "");
  return files.sort();
}
function check() {
  const ledger = JSON.parse(read(root, `${shared}/PROVENANCE.json`));
  assert.equal(ledger.source_commit, pins.commit);
  assert.equal(ledger.source_artifacts_sha256, pins.artifacts);
  assert.equal(ledger.candidate_id, pins.candidate);
  const seen = new Set();
  for (const member of ledger.members) {
    assert(!seen.has(member.target), `Duplicate member: ${member.target}`);
    seen.add(member.target);
    const bytes = read(root, member.target);
    assert.equal(bytes.length, member.bytes, member.target);
    assert.equal(hash(bytes), member.sha256, member.target);
  }
  for (const [relative, expected] of [[`${shared}/CmsCodec.php`, pins.codec], [`${shared}/cms-schema.json`, pins.schema], [`${shared}/idna/DEPENDENCIES.json`, pins.idna]]) {
    assert(seen.has(relative));
    assert.equal(hash(read(root, relative)), expected);
  }
  const bundle = JSON.parse(read(root, `${shared}/idna/DEPENDENCIES.json`));
  assert.equal(bundle.namespace, "DiscussionBridge\\CmsV4\\IdnaVendor");
  assert.equal(bundle.additional_mandatory_extensions.length, 0);
  for (const member of bundle.members) {
    const target = `${shared}/idna/${safe(member.path)}`;
    assert(seen.has(target), `Unbound dependency: ${target}`);
    const bytes = read(root, target);
    assert.equal(bytes.length, member.bytes, target);
    assert.equal(hash(bytes), member.sha256, target);
  }
  assert.deepEqual(inventory(root, `${shared}/idna`), [...bundle.members.map(m => m.path), "DEPENDENCIES.json"].sort());
  const schema = JSON.parse(read(root, `${shared}/cms-schema.json`));
  assert.equal(schema.source_identity.sha256, pins.normative);
  return { candidate: pins.candidate, source_commit: pins.commit, members: ledger.members.length, private_dependencies: bundle.members.length, result: "exact" };
}

const args = process.argv.slice(2);
if (args.length === 2 && args[0] === "--source") {
  // Reject fixed output links even on an initial refresh with no prior ledger.
  preflightDirectory(root, `${shared}/idna`);
  for (const relative of [`${shared}/PROVENANCE.json`, `${shared}/CmsCodec.php`, `${shared}/cms-schema.json`]) preflightFile(root, relative);
  const source = path.resolve(args[1]);
  const artifactBytes = read(source, "ARTIFACTS.json");
  assert.equal(hash(artifactBytes), pins.artifacts, "Wrong shared artifact set");
  const artifacts = JSON.parse(artifactBytes);
  assert.equal(artifacts.candidate_id, pins.candidate);
  assert.equal(artifacts.normative.sha256, pins.normative);
  const staged = [];
  function add(from, target, expected) {
    const bytes = read(source, from);
    if (expected) { assert.equal(bytes.length, expected.bytes, from); assert.equal(hash(bytes), expected.sha256, from); }
    staged.push({ source: from, target, bytes, record: { source: from, target, bytes: bytes.length, sha256: hash(bytes) } });
  }
  for (const [from, target] of [["src/php/CmsCodec.php", `${shared}/CmsCodec.php`], ["src/cms-schema.json", `${shared}/cms-schema.json`]]) {
    const member = artifacts.members.find(m => m.path === from);
    assert(member, `Missing artifact declaration: ${from}`);
    add(from, target, member);
  }
  add(artifacts.php_dependencies.path, `${shared}/idna/DEPENDENCIES.json`, artifacts.php_dependencies);
  const bundle = JSON.parse(staged.at(-1).bytes);
  const expected = new Set(["DEPENDENCIES.json"]);
  for (const member of bundle.members) { safe(member.path); assert(!expected.has(member.path)); expected.add(member.path); add(`src/php/idna/${member.path}`, `${shared}/idna/${member.path}`, member); }
  assert.deepEqual(inventory(source, "src/php/idna"), [...expected].sort());
  const fixtures = {
    "url-domain-policy.json": "e6fa88694fe1142f8754c2e50cbee752a9755d63324247aaa9db465450d5672e",
    "url-contextj.json": "5cf9aee605cd002a75d929da35fe0f6bcb99b2dd83a78a71751dc24b95adadd8",
    "positive-examples.json": "b3ea942e9fe6b824ab2a0af5f4eecf46b672d544856fea53929d60a359225b30",
  };
  for (const [name, expected] of Object.entries(fixtures)) {
    const from = `fixtures/${name}`;
    assert.equal(hash(read(source, from)), expected, from);
    add(from, `tests/fixtures/v4/${name}`);
  }
  const prior = new Map();
  const provenance = `${shared}/PROVENANCE.json`;
  const provenanceStat = preflightFile(root, provenance);
  if (provenanceStat) {
    const old = JSON.parse(read(root, provenance));
    for (const member of old.members) {
      assert(!prior.has(member.target));
      const bytes = read(root, member.target);
      assert.equal(bytes.length, member.bytes, `Modified owned output: ${member.target}`);
      assert.equal(hash(bytes), member.sha256, `Modified owned output: ${member.target}`);
      prior.set(member.target, member.sha256);
    }
  } else {
    // Exact initial R6 preimages only; never adopt an edited user runtime.
    prior.set(`${shared}/CmsCodec.php`, "6eece074e1268d2d90890afd0215da80c06c23961b497a4df190f7876af0fc19");
    prior.set(`${shared}/cms-schema.json`, "8563a9b1346e3bf82635bd65a46bb1e25415a1003d7432f8fcb8c1c6e4f03c14");
  }
  // Preflight every output before writing anything; never sweep or delete files.
  for (const item of staged) {
    if (preflightFile(root, item.target)) {
      const current = read(root, item.target);
      assert(current.equals(item.bytes) || hash(current) === prior.get(item.target), `Unowned/modified output: ${item.target}`);
    }
  }
  preflightDirectory(root, `${shared}/idna`);
  if (statOrAbsent(path.join(root, `${shared}/idna`))) assert.deepEqual(inventory(root, `${shared}/idna`), [...expected].sort(), "Unexpected existing dependency member; no automatic cleanup");
  for (const item of staged) { fs.mkdirSync(path.dirname(path.join(root, item.target)), { recursive: true }); fs.writeFileSync(path.join(root, item.target), item.bytes); }
  const ledger = { format: 1, source_repository: "https://github.com/DiscussionBridge/discussionbridge-adapter-contract", source_commit: pins.commit, source_artifacts_sha256: pins.artifacts, candidate_id: pins.candidate, normative: artifacts.normative, members: staged.map(m => m.record) };
  fs.writeFileSync(path.join(root, shared, "PROVENANCE.json"), JSON.stringify(ledger, null, 2) + "\n");
} else assert(args.length === 1 && args[0] === "--check", "Use --check, or explicit maintainer --source SHARED_ARTIFACT_DIRECTORY");
console.log(JSON.stringify(check()));
