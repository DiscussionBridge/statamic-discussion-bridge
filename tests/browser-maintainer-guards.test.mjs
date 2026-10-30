import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";
import test from "node:test";
import { bundledLicense } from "../scripts/bundled-license.mjs";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const scratch = path.join(root, ".tmp");
fs.mkdirSync(scratch, { recursive: true });
const batch = fs.mkdtempSync(path.join(scratch, "maintainer-guards-"));
const files = ["scripts/build-browser-assets.mjs", "scripts/shared-contract.mjs", "scripts/managed-output.mjs", "scripts/bundled-license.mjs", "resources/js/browser-simple.mjs", "resources/js/browser-rich-content.mjs", "package.json", "package-lock.json"];
const metadata = { name: "fastdom", version: "1.0.12", license: "MIT" };
function replica() {
  const directory = fs.mkdtempSync(path.join(batch, "replica-"));
  for (const relative of files) {
    fs.mkdirSync(path.dirname(path.join(directory, relative)), { recursive: true });
    fs.copyFileSync(path.join(root, relative), path.join(directory, relative));
  }
  return directory;
}
function snapshot(directory) {
  const result = {};
  function walk(at, prefix = "") {
    for (const name of fs.readdirSync(at).sort()) {
      const file = path.join(at, name), relative = prefix + name, stat = fs.lstatSync(file);
      if (stat.isSymbolicLink()) result[relative] = { link: fs.readlinkSync(file) };
      else if (stat.isDirectory()) { result[relative] = { directory: true }; walk(file, `${relative}/`); }
      else result[relative] = fs.readFileSync(file).toString("base64");
    }
  }
  walk(directory); return result;
}
function run(directory, script, args = []) {
  const result = spawnSync(process.execPath, [path.join(directory, script), ...args], {
    cwd: directory, encoding: "utf8", timeout: 90000, windowsHide: true,
    env: { ...process.env, DISCUSSIONBRIDGE_BUILD_DEPENDENCY_ROOT: root },
  });
  assert.equal(result.error, undefined, result.error?.message); return result;
}
function link(t, target, file, directory) {
  try { fs.symlinkSync(target, file, directory ? (process.platform === "win32" ? "junction" : "dir") : "file"); return true; }
  catch (error) {
    if (process.platform === "win32" && ["EPERM", "EACCES"].includes(error.code)) {
      t.skip(`Windows denied ${directory ? "directory" : "file"} link creation; Linux CI must execute this case`); return false;
    }
    throw error;
  }
}
test("actual FastDom README notice is complete and source-bound; metadata is not a license", () => {
  const actual = bundledLicense(path.join(root, "node_modules/fastdom"), metadata);
  assert.equal(actual.source.file, "README.md#license");
  assert.match(actual.text, /Copyright \(c\) 2016 Wilson Page/);
  assert.match(actual.text, /this permission notice shall be included/);
  assert.match(actual.text, /THE SOFTWARE IS PROVIDED/);
  const directory = fs.mkdtempSync(path.join(batch, "license-"));
  assert.throws(() => bundledLicense(directory, { name: "other", version: "1", license: "MIT" }), /Actual bundled license is unavailable/);
  assert.throws(() => bundledLicense(directory, metadata), /ENOENT/);
  fs.writeFileSync(path.join(directory, "README.md"), "## License\nMIT\n");
  assert.throws(() => bundledLicense(directory, metadata), /FastDom license source changed/);
  fs.copyFileSync(path.join(root, "node_modules/fastdom/README.md"), path.join(directory, "README.md"));
  assert.deepEqual(bundledLicense(directory, metadata), actual);
  assert.throws(() => bundledLicense(directory, { ...metadata, version: "1.0.13" }), /Actual bundled license is unavailable/);
  fs.writeFileSync(path.join(directory, "LICENSE"), "\n");
  assert.throws(() => bundledLicense(directory, { name: "other" }), /Empty dependency license/);
});
test("unowned notice refuses before any generated browser output changes", () => {
  const directory = replica(), file = path.join(directory, "resources/dist/THIRD_PARTY_LICENSES/fastdom_1.0.12.txt");
  fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, "Operator content; do not overwrite.\n");
  const before = snapshot(directory), result = run(directory, "scripts/build-browser-assets.mjs");
  assert.notEqual(result.status, 0); assert.match(result.stderr, /Unowned generated license target/);
  assert.deepEqual(snapshot(directory), before);
});
for (const dangling of [false, true]) {
  test(`first build rejects ${dangling ? "dangling" : "live"} notice-parent link before any mutation`, t => {
    const directory = replica(), outside = path.join(batch, `notices-${dangling}-${path.basename(directory)}`);
    if (!dangling) { fs.mkdirSync(outside); fs.writeFileSync(path.join(outside, "keep.txt"), "Retain.\n"); }
    fs.mkdirSync(path.join(directory, "resources/dist"), { recursive: true });
    if (!link(t, outside, path.join(directory, "resources/dist/THIRD_PARTY_LICENSES"), true)) return;
    const before = snapshot(directory), siblingBefore = dangling ? null : snapshot(outside), result = run(directory, "scripts/build-browser-assets.mjs");
    assert.notEqual(result.status, 0); assert.match(result.stderr, /Invalid managed output parent/);
    assert.deepEqual(snapshot(directory), before); assert.equal(fs.existsSync(outside), !dangling);
    if (!dangling) assert.deepEqual(snapshot(outside), siblingBefore);
  });
  for (const relative of ["resources/dist/discussionbridge-rich-content.js", "resources/dist/browser-assets-provenance.json", "resources/dist/THIRD_PARTY_LICENSES/fastdom_1.0.12.txt"]) {
    test(`build rejects ${dangling ? "dangling" : "live"} file link ${relative} before all writes`, t => {
      const directory = replica(), target = path.join(batch, `${path.basename(directory)}-outside.txt`), file = path.join(directory, relative);
      fs.mkdirSync(path.dirname(file), { recursive: true }); if (!dangling) fs.writeFileSync(target, "Retain target.\n");
      if (!link(t, target, file, false)) return;
      const before = snapshot(directory), result = run(directory, "scripts/build-browser-assets.mjs");
      assert.notEqual(result.status, 0); assert.match(result.stderr, /Invalid managed output file/);
      assert.deepEqual(snapshot(directory), before); assert.equal(fs.existsSync(target), !dangling);
      if (!dangling) assert.equal(fs.readFileSync(target, "utf8"), "Retain target.\n");
    });
  }
  for (const parent of [false, true]) {
    test(`refresh rejects ${dangling ? "dangling" : "live"} ${parent ? "parent" : "provenance"} link before source reads or writes`, t => {
      const directory = replica(), target = path.join(batch, `${path.basename(directory)}-shared-target`);
      if (!dangling) {
        if (parent) { fs.mkdirSync(target); fs.writeFileSync(path.join(target, "keep.txt"), "Retain.\n"); }
        else fs.writeFileSync(target, "Retain target.\n");
      }
      const file = path.join(directory, parent ? "src/Cms/Shared/idna" : "src/Cms/Shared/PROVENANCE.json");
      fs.mkdirSync(path.dirname(file), { recursive: true }); if (!link(t, target, file, parent)) return;
      const before = snapshot(directory), siblingBefore = !dangling && parent ? snapshot(target) : null;
      // Synthetic empty source proves link rejection occurs first, rather than
      // mistaking a missing ARTIFACTS.json error for destination protection.
      const result = run(directory, "scripts/shared-contract.mjs", ["--source", directory]);
      assert.notEqual(result.status, 0); assert.match(result.stderr, /Invalid managed output (?:parent|file)/); assert.doesNotMatch(result.stderr, /ARTIFACTS.json/);
      assert.deepEqual(snapshot(directory), before); assert.equal(fs.existsSync(target), !dangling);
      if (siblingBefore) assert.deepEqual(snapshot(target), siblingBefore);
      if (!dangling && !parent) assert.equal(fs.readFileSync(target, "utf8"), "Retain target.\n");
    });
  }
}
// Retain only this newly owned ignored batch for replay; do not recursively
// delete or traverse any fixture link target in this test.
