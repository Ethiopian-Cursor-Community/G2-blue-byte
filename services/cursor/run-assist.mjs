#!/usr/bin/env node
import { Agent, CursorAgentError } from "@cursor/sdk";
import { fileURLToPath } from "url";
import path from "path";

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");

function readStdin() {
  return new Promise((resolve) => {
    const chunks = [];
    process.stdin.setEncoding("utf8");
    process.stdin.on("data", (c) => chunks.push(c));
    process.stdin.on("end", () => resolve(chunks.join("")));
  });
}

function out(payload, code = 0) {
  process.stdout.write(JSON.stringify(payload));
  process.exit(code);
}

const raw = await readStdin();
let input = {};
try {
  input = raw ? JSON.parse(raw) : {};
} catch {
  out({ ok: false, error: "Invalid JSON input" }, 1);
}

const apiKey = (process.env.CURSOR_API_KEY || "").trim();
if (!apiKey) out({ ok: false, error: "CURSOR_API_KEY not set" }, 1);

const prompt = String(input.prompt || "").trim();
if (!prompt) out({ ok: false, error: "Empty prompt" }, 1);

try {
  const result = await Agent.prompt(prompt, {
    apiKey,
    model: { id: String(input.model || "composer-2") },
    local: { cwd: String(input.cwd || projectRoot), settingSources: [] },
  });
  if (result.status === "error") out({ ok: false, error: "Agent run failed" }, 2);
  out({ ok: true, reply: String(result.result ?? "").trim() || "No reply generated." });
} catch (err) {
  out({
    ok: false,
    error: err instanceof CursorAgentError ? err.message : String(err?.message || err),
    retryable: err instanceof CursorAgentError ? !!err.isRetryable : false,
  }, 1);
}
