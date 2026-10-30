import { createRequire } from "node:module";
import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { preflightDirectory, preflightFile, statOrAbsent } from "./managed-output.mjs";
import { bundledLicense } from "./bundled-license.mjs";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const dependencyRoot = process.env.DISCUSSIONBRIDGE_BUILD_DEPENDENCY_ROOT ?? root;
const require = createRequire(import.meta.url);
const { build } = require(require.resolve("esbuild", { paths: [dependencyRoot] }));

const sha256 = bytes => createHash("sha256").update(bytes).digest("hex");
const priorPath = path.join(root, "resources/dist/browser-assets-provenance.json");
preflightDirectory(root, "resources/dist/THIRD_PARTY_LICENSES");
for (const relative of ["resources/dist/discussionbridge-simple.js", "resources/dist/discussionbridge-rich-content.js", "resources/dist/discussionbridge-rich-content.css", "resources/dist/browser-assets-provenance.json"]) {
  preflightFile(root, relative);
}
const priorNotices = statOrAbsent(priorPath)
  ? JSON.parse(fs.readFileSync(priorPath, "utf8")).bundledPackages ?? [] : [];
function ownedNotice(item) {
  if (typeof item.file !== "string" || !/^resources\/dist\/THIRD_PARTY_LICENSES\/[A-Za-z0-9_.-]+\.txt$/.test(item.file)) throw new Error("Unsafe prior generated license path");
  const file = path.join(root, item.file);
  const stat = preflightFile(root, item.file);
  if (!stat) return file;
  if (!stat.isFile() || stat.isSymbolicLink()) throw new Error(`Invalid prior generated license: ${item.file}`);
  const bytes = fs.readFileSync(file);
  if (bytes.length !== item.bytes || sha256(bytes) !== item.sha256) throw new Error(`Modified prior generated license: ${item.file}`);
  return file;
}
// Validate declared ownership before changing any generated output. Unlisted
// files are never swept; changed prior notices are preserved by failing closed.
for (const item of priorNotices) ownedNotice(item);

const result = await build({
  absWorkingDir: root,
  entryPoints: {
    "discussionbridge-simple": "resources/js/browser-simple.mjs",
    "discussionbridge-rich-content": "resources/js/browser-rich-content.mjs",
  },
  outdir: "resources/dist",
  entryNames: "[name]",
  outExtension: { ".js": ".js" },
  bundle: true,
  format: "iife",
  minify: true,
  nodePaths: [path.join(dependencyRoot, "node_modules")],
  loader: { ".woff2": "dataurl", ".woff": "dataurl", ".ttf": "dataurl" },
  metafile: true,
  write: false,
});

const digest = (relative) => {
  const bytes = fs.readFileSync(path.join(root, relative));
  return { bytes: bytes.length, sha256: createHash("sha256").update(bytes).digest("hex") };
};
const dependencies = Object.fromEntries(["esbuild", "mermaid", "katex", "dompurify"].map((name) => {
  const pkg = JSON.parse(fs.readFileSync(path.join(dependencyRoot, "node_modules", name, "package.json"), "utf8"));
  return [name, { version: pkg.version, license: pkg.license }];
}));
const notices = new Map();
const noticeWrites = new Map();
for (const input of Object.keys(result.metafile.inputs)) {
  let directory = path.dirname(path.resolve(root, input));
  while (directory !== path.dirname(directory)) {
    const manifest = path.join(directory, "package.json");
    if (fs.existsSync(manifest)) {
      const metadata = JSON.parse(fs.readFileSync(manifest, "utf8"));
      if (directory !== root && typeof metadata.name === "string" && typeof metadata.version === "string") {
        const key = `${metadata.name}@${metadata.version}`;
        if (!notices.has(key)) {
          const { text, source } = bundledLicense(directory, metadata);
          const relative = `resources/dist/THIRD_PARTY_LICENSES/${key.replace(/[^a-zA-Z0-9_.-]/g, "_")}.txt`;
          const targetStat = preflightFile(root, relative);
          if (targetStat && !priorNotices.some(item => item.file === relative) && !fs.readFileSync(path.join(root, relative)).equals(Buffer.from(text))) throw new Error(`Unowned generated license target: ${relative}`);
          const bytes = Buffer.from(text);
          noticeWrites.set(relative, bytes);
          notices.set(key, { name: metadata.name, version: metadata.version, license: metadata.license, file: relative, licenseSource: source, bytes: bytes.length, sha256: sha256(bytes) });
        }
      }
      break;
    }
    directory = path.dirname(directory);
  }
}
const outputWrites = new Map(result.outputFiles.map(file => [path.relative(root, file.path).split(path.sep).join("/"), Buffer.from(file.contents)]));
const expectedOutputs = ["resources/dist/discussionbridge-simple.js", "resources/dist/discussionbridge-rich-content.js", "resources/dist/discussionbridge-rich-content.css"];
if (outputWrites.size !== expectedOutputs.length || expectedOutputs.some(file => !outputWrites.has(file))) throw new Error("Unexpected generated browser output set");
for (const relative of outputWrites.keys()) preflightFile(root, relative);
const provenance = {
  format: 1,
  build: "npm ci && npm run build",
  dependencies,
  bundledPackages: [...notices.entries()].sort(([left], [right]) => left < right ? -1 : left > right ? 1 : 0).map(([, value]) => value),
  inputs: Object.fromEntries(["package-lock.json", "scripts/build-browser-assets.mjs", "scripts/managed-output.mjs", "scripts/bundled-license.mjs", "resources/js/browser-simple.mjs", "resources/js/browser-rich-content.mjs"].map((file) => [file, digest(file)])),
  outputs: Object.fromEntries([...outputWrites].map(([file, bytes]) => [file, { bytes: bytes.length, sha256: sha256(bytes) }])),
};
const currentNotices = new Set([...notices.values()].map(item => item.file));
// Only mutate after all notice sources, destinations and output bytes are known.
preflightDirectory(root, "resources/dist/THIRD_PARTY_LICENSES");
preflightFile(root, "resources/dist/browser-assets-provenance.json");
for (const [relative, bytes] of [...outputWrites, ...noticeWrites]) {
  fs.mkdirSync(path.dirname(path.join(root, relative)), { recursive: true });
  fs.writeFileSync(path.join(root, relative), bytes);
}
for (const item of priorNotices) {
  if (currentNotices.has(item.file)) continue;
  const file = ownedNotice(item);
  if (fs.existsSync(file)) fs.unlinkSync(file);
}
fs.writeFileSync(path.join(root, "resources/dist/browser-assets-provenance.json"), `${JSON.stringify(provenance, null, 2)}\n`);
