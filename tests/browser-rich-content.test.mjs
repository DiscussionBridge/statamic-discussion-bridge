import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import test from "node:test";

const source = fs.readFileSync(new URL("../resources/js/browser-rich-content.mjs", import.meta.url), "utf8")
  .replace(/^import .*;\r?\n/gmu, "").replace("export { render };", "globalThis.renderContent = render;");

function fixture() {
  const calls = [];
  const replaced = [];
  const events = new Map();
  const diagrams = [];
  const math = [];
  const root = { querySelectorAll: selector => selector.includes("mermaid") ? diagrams : math };
  const document = {
    ...root, readyState: "loading",
    createElement: () => ({ isConnected: true, replaceWith: original => replaced.push({ restored: original }) }),
    head: { append() {} }, addEventListener: (name, handler) => events.set(name, handler),
  };
  const context = vm.createContext({ document,
    mermaid: { initialize: options => calls.push({ initialize: options }), run: async options => calls.push({ diagram: options }) },
    katex: { render: (text, container, options) => calls.push({ math: text, options }) },
  });
  const add = (kind, textContent) => {
    const list = kind === "mermaid" ? diagrams : math;
    const original = { isConnected: true, replaceWith: container => { replaced.push({ kind, container }); list.splice(list.indexOf(code), 1); } };
    const code = { textContent, parentElement: original };
    list.push(code);
  };
  vm.runInContext(source, context);
  return { context, document, root, calls, replaced, events, add };
}

test("selected renderer uses text and strict diagram and math security", async () => {
  const f = fixture();
  f.add("mermaid", 'graph TD; A-->B; <img src=x onerror="bad()">');
  f.add("math", "x^2");
  await f.events.get("DOMContentLoaded")();
  assert.equal(f.calls[0].initialize.securityLevel, "strict");
  assert.equal(f.calls[0].initialize.htmlLabels, false);
  assert.equal(f.calls[0].initialize.startOnLoad, false);
  assert.equal(Object.hasOwn(f.replaced[0].container, "innerHTML"), false);
  assert.match(f.replaced[0].container.textContent, /<img src=x/);
  const math = f.calls.find(call => call.math);
  assert.equal(math.options.trust, false);
  assert.equal(math.options.strict, "error");
  assert.equal(math.options.output, "htmlAndMathml");
});

test("ordinary articles do not initialize renderers and explicit subtrees can render later", async () => {
  const f = fixture();
  await f.events.get("DOMContentLoaded")();
  assert.equal(f.calls.length, 0);
  f.add("mermaid", "graph LR; Source-->Destination");
  await f.context.renderContent(f.root);
  assert.equal(f.calls.length, 2);
  assert.equal(f.replaced[0].container.textContent, "graph LR; Source-->Destination");
});

test("failed diagrams restore source and failed math leaves native source untouched", async () => {
  const f = fixture();
  f.add("mermaid", "bad diagram");
  f.add("math", "bad math");
  f.context.mermaid.run = async () => { throw new Error("Parse failed"); };
  f.context.katex.render = () => { throw new Error("Parse failed"); };
  await f.context.renderContent();
  assert.ok(f.replaced.some(item => item.restored));
  assert.equal(f.replaced.filter(item => item.kind === "math").length, 0);
});
