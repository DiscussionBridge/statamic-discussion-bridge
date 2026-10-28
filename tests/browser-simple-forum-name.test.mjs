import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import test from "node:test";

const source = fs.readFileSync(new URL("../resources/js/browser-simple.mjs", import.meta.url), "utf8")
  .replace(/^import .*;\r?\n/gmu, "") + "\nglobalThis.naming = { refresh, render, validForumName };";

class Node {
  children = [];
  text = "";
  append(...nodes) { this.children.push(...nodes); }
  set textContent(value) { this.text = value; }
  get textContent() { return this.text + this.children.map(node => typeof node === "string" ? node : node.textContent).join(""); }
  replaceChildren(...nodes) { this.children = nodes; }
  setAttribute() {}
  querySelector() { return null; }
}

function fixture(name) {
  const requests = [];
  const root = new Node();
  root.dataset = { discourseOrigin: "https://forum.example", topicId: "53", topicUrl: "https://forum.example/t/source/53", forumName: name, discussionbridgeSimpleState: "snapshot" };
  const context = vm.createContext({ URL, TextEncoder, AbortSignal,
    document: { querySelectorAll: () => [], createElement: () => new Node(), createDocumentFragment: () => new Node() },
    fetch: async (url, options) => {
      requests.push({ url: String(url), options });
      return { ok: true, url: String(url), headers: { get: key => key === "content-type" ? "application/json" : null }, text: async () => JSON.stringify({ post_stream: { stream: [1], posts: [] } }) };
    },
  });
  vm.runInContext(source, context);
  return { root, requests, naming: context.naming };
}

test("live empty state preserves effective label as text and keeps public requests credential-free", async () => {
  const name = '  <Community & members onclick="bad()">  ';
  const f = fixture(name);
  await f.naming.refresh(f.root);
  assert.equal(f.root.dataset.discussionbridgeSimpleState, "live");
  assert.ok(f.root.textContent.includes(`Open discussion on ${name}`));
  assert.ok(f.root.textContent.includes(`Start the conversation on ${name}.`));
  assert.ok(!f.root.textContent.includes("The Bridge"));
  assert.equal(Object.hasOwn(f.root, "innerHTML"), false);
  assert.ok(f.requests.length >= 1);
  for (const request of f.requests) {
    assert.equal(request.options.credentials, "omit");
    assert.equal(request.options.redirect, "error");
    assert.ok(!Object.keys(request.options.headers).some(key => key.startsWith("X-DiscussionBridge")));
  }
});

test("bounded-discussion link uses the same preserved plain-text forum label", () => {
  const f = fixture('Native <forum> & members');
  f.naming.render(f.root, [], 'https://forum.example/t/source/53', 'https://forum.example', true, f.root.dataset.forumName);
  assert.ok(f.root.textContent.includes('View the complete discussion on Native <forum> & members'));
});

test("raw UTF8 byte bound, controls, whitespace and invalid Unicode reject without refreshing snapshot", async () => {
  for (const name of [undefined, '', '   ', '\u00a0\u2007\ufeff', 'Community\n', 'Community\u0085', '\ud800', ' '.repeat(1) + 'x'.repeat(200), 'é'.repeat(101)]) {
    const f = fixture(name);
    await assert.rejects(f.naming.refresh(f.root), /Invalid forum name/);
    assert.equal(f.root.dataset.discussionbridgeSimpleState, "snapshot");
    assert.equal(f.requests.length, 0);
  }
  for (const name of ['é'.repeat(100), '😀'.repeat(50), '  Forum  ']) {
    assert.equal(fixture(name).naming.validForumName(name), name);
  }
});
