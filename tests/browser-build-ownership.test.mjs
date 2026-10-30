import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import test from "node:test";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
test("normal builder removes only exact obsolete owned notices and preserves modified or unknown files", () => {
  const scratch = path.join(root, ".tmp");
  fs.mkdirSync(scratch, { recursive: true });
  const fixture = fs.mkdtempSync(path.join(scratch, "browser-ownership-"));
  const file = relative => path.join(fixture, relative);
  const run = () => spawnSync(process.execPath, [file("scripts/build-browser-assets.mjs")], {
    cwd: fixture, encoding: "utf8", timeout: 90000, windowsHide: true,
    env: { ...process.env, DISCUSSIONBRIDGE_BUILD_DEPENDENCY_ROOT: root },
  });
  const hash = bytes => createHash("sha256").update(bytes).digest("hex");
  const ledger = "resources/dist/browser-assets-provenance.json";
  const stale = "resources/dist/THIRD_PARTY_LICENSES/owned_obsolete_0.0.0.txt";
  const unknown = "resources/dist/THIRD_PARTY_LICENSES/operator-note.txt";
  const addStale = () => {
    const bytes = Buffer.from("An obsolete generated notice.\n");
    fs.writeFileSync(file(stale), bytes);
    const value = JSON.parse(fs.readFileSync(file(ledger), "utf8"));
    value.bundledPackages.push({ name: "owned-obsolete", version: "0.0.0", file: stale, bytes: bytes.length, sha256: hash(bytes) });
    fs.writeFileSync(file(ledger), JSON.stringify(value) + "\n");
  };
  try {
    for (const relative of ["scripts/build-browser-assets.mjs", "resources/js/browser-simple.mjs", "resources/js/browser-rich-content.mjs", "package.json", "package-lock.json"]) {
      fs.mkdirSync(path.dirname(file(relative)), { recursive: true });
      fs.copyFileSync(path.join(root, relative), file(relative));
    }
    let result = run();
    assert.equal(result.error, undefined, result.error?.message);
    assert.equal(result.status, 0, result.stderr);
    fs.writeFileSync(file(unknown), "Keep this operator file.\n");
    addStale();
    result = run();
    assert.equal(result.status, 0, result.stderr);
    assert.equal(fs.existsSync(file(stale)), false);
    assert.equal(fs.readFileSync(file(unknown), "utf8"), "Keep this operator file.\n");
    addStale();
    fs.appendFileSync(file(stale), "Operator edit.\n");
    const before = fs.readFileSync(file(ledger));
    const bundle = fs.readFileSync(file("resources/dist/discussionbridge-rich-content.js"));
    result = run();
    assert.equal(result.error, undefined, result.error?.message);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Modified prior generated license/);
    assert.deepEqual(fs.readFileSync(file(ledger)), before);
    assert.deepEqual(fs.readFileSync(file("resources/dist/discussionbridge-rich-content.js")), bundle);
    assert.match(fs.readFileSync(file(stale), "utf8"), /Operator edit/);
    assert.equal(fs.readFileSync(file(unknown), "utf8"), "Keep this operator file.\n");
  } finally {
    const resolved = fs.realpathSync(fixture);
    assert.equal(path.dirname(resolved), fs.realpathSync(scratch));
    assert.ok(path.basename(resolved).startsWith("browser-ownership-"));
    fs.rmSync(resolved, { recursive: true });
  }
});
