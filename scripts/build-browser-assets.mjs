import { createRequire } from "node:module";
import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const dependencyRoot = process.env.DISCUSSIONBRIDGE_BUILD_DEPENDENCY_ROOT ?? root;
const require = createRequire(import.meta.url);
const { build } = require(require.resolve("esbuild", { paths: [dependencyRoot] }));

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
for (const input of Object.keys(result.metafile.inputs)) {
  let directory = path.dirname(path.resolve(root, input));
  while (directory !== path.dirname(directory)) {
    const manifest = path.join(directory, "package.json");
    if (fs.existsSync(manifest)) {
      const metadata = JSON.parse(fs.readFileSync(manifest, "utf8"));
      if (directory !== root && typeof metadata.name === "string" && typeof metadata.version === "string") {
        const key = `${metadata.name}@${metadata.version}`;
        if (!notices.has(key)) {
          const license = fs.readdirSync(directory).find(file => /^licen[cs]e(?:\.(?:md|txt))?$/i.test(file));
          const text = license ? fs.readFileSync(path.join(directory, license), "utf8") : `License declared by package: ${JSON.stringify(metadata.license)}\nSource: ${JSON.stringify(metadata.repository)}\n`;
          const relative = `resources/dist/THIRD_PARTY_LICENSES/${key.replace(/[^a-zA-Z0-9_.-]/g, "_")}.txt`;
          fs.mkdirSync(path.dirname(path.join(root, relative)), { recursive: true });
          fs.writeFileSync(path.join(root, relative), text);
          notices.set(key, { name: metadata.name, version: metadata.version, license: metadata.license, file: relative, ...digest(relative) });
        }
      }
      break;
    }
    directory = path.dirname(directory);
  }
}
const provenance = {
  format: 1,
  build: "npm ci && npm run build",
  dependencies,
  bundledPackages: [...notices.entries()].sort(([left], [right]) => left < right ? -1 : left > right ? 1 : 0).map(([, value]) => value),
  inputs: Object.fromEntries(["package-lock.json", "scripts/build-browser-assets.mjs", "resources/js/browser-simple.mjs", "resources/js/browser-rich-content.mjs"].map((file) => [file, digest(file)])),
  outputs: Object.fromEntries(["resources/dist/discussionbridge-simple.js", "resources/dist/discussionbridge-rich-content.js", "resources/dist/discussionbridge-rich-content.css"].map((file) => [file, digest(file)])),
};
fs.writeFileSync(path.join(root, "resources/dist/browser-assets-provenance.json"), `${JSON.stringify(provenance, null, 2)}\n`);
