import fs from "node:fs";
import path from "node:path";

export function statOrAbsent(file) {
  try { return fs.lstatSync(file); }
  catch (error) { if (error.code === "ENOENT") return null; throw error; }
}

function parts(relative) {
  if (typeof relative !== "string" || !/^[A-Za-z0-9_.\/-]+$/.test(relative)
    || relative.startsWith("/") || relative.split("/").some(part => ["", ".", ".."].includes(part))) {
    throw new Error(`Unsafe managed output: ${relative}`);
  }
  return relative.split("/");
}

export function preflightDirectory(root, relative) {
  let at = root;
  for (const part of parts(relative)) {
    at = path.join(at, part);
    const stat = statOrAbsent(at);
    if (stat && (!stat.isDirectory() || stat.isSymbolicLink())) throw new Error(`Invalid managed output parent: ${relative}`);
  }
}

export function preflightFile(root, relative) {
  const segments = parts(relative);
  if (segments.length > 1) preflightDirectory(root, segments.slice(0, -1).join("/"));
  const stat = statOrAbsent(path.join(root, relative));
  if (stat && (!stat.isFile() || stat.isSymbolicLink())) throw new Error(`Invalid managed output file: ${relative}`);
  return stat;
}
