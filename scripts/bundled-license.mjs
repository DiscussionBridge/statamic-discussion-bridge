import fs from "node:fs";
import path from "node:path";
import { createHash } from "node:crypto";

const hash = bytes => createHash("sha256").update(bytes).digest("hex");
function regularRead(directory, name) {
  const file = path.join(directory, name), stat = fs.lstatSync(file);
  if (!stat.isFile() || stat.isSymbolicLink()) throw new Error(`Non-regular dependency license: ${name}`);
  return fs.readFileSync(file);
}

export function bundledLicense(directory, metadata) {
  const standalone = fs.readdirSync(directory).find(name => /^licen[cs]e(?:\.(?:md|txt))?$/i.test(name));
  if (standalone) {
    const bytes = regularRead(directory, standalone);
    if (!bytes.toString("utf8").trim()) throw new Error(`Empty dependency license: ${metadata.name}`);
    return { text: bytes.toString("utf8"), source: { file: standalone, sha256: hash(bytes) } };
  }
  // This exact published package carries its MIT notice in README, not LICENSE.
  // Pin both the document and section rather than treating an SPDX label as terms.
  if (metadata.name === "fastdom" && metadata.version === "1.0.12" && metadata.license === "MIT") {
    const bytes = regularRead(directory, "README.md");
    if (hash(bytes) !== "cc5669ab6f7741bb061c8a7244addc8e3af1f84ec7d2445744b59d0169a87d11") throw new Error("FastDom license source changed; reconcile the published notice");
    const text = bytes.toString("utf8"), start = text.indexOf("## License\n"), notice = text.slice(start);
    if (start < 0 || hash(notice) !== "df2ae14f12f31864acef768195afe4584b08a257f25925f49884619279f02f37") throw new Error("FastDom license section is unavailable or changed");
    return { text: notice, source: { file: "README.md#license", sha256: hash(bytes) } };
  }
  throw new Error(`Actual bundled license is unavailable: ${metadata.name}@${metadata.version}`);
}
