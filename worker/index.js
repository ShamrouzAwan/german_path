/**
 * GermanPath protected-media Worker.
 *
 * Bind R2 buckets as R2_PRIMARY and R2_SECONDARY. Set:
 * - WORKER_SECRET: shared server-to-server secret
 * - PUBLIC_URL: deployed Worker URL
 * - TOKEN_TTL_SECONDS: maximum signed-media lifetime
 *
 * This Worker intentionally does not expose R2 credentials or accept browser
 * signing requests. CORS can be added after the production domain is fixed.
 */

const encoder = new TextEncoder();

function base64Url(value) {
  const bytes = typeof value === "string" ? encoder.encode(value) : new Uint8Array(value);
  let binary = "";
  bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

function fromBase64Url(value) {
  const padded = value.replace(/-/g, "+").replace(/_/g, "/") + "===".slice((value.length + 3) % 4);
  const binary = atob(padded);
  return Uint8Array.from(binary, (character) => character.charCodeAt(0));
}

async function hmac(value, secret) {
  const key = await crypto.subtle.importKey(
    "raw",
    encoder.encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign", "verify"]
  );
  return crypto.subtle.sign("HMAC", key, encoder.encode(value));
}

async function signedToken(payload, secret) {
  const encoded = base64Url(JSON.stringify(payload));
  return `${encoded}.${base64Url(await hmac(encoded, secret))}`;
}

async function verifyToken(token, secret) {
  const [encoded, signature] = token.split(".");
  if (!encoded || !signature) return null;
  const key = await crypto.subtle.importKey(
    "raw",
    encoder.encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["verify"]
  );
  const valid = await crypto.subtle.verify("HMAC", key, fromBase64Url(signature), encoder.encode(encoded));
  if (!valid) return null;
  const payload = JSON.parse(new TextDecoder().decode(fromBase64Url(encoded)));
  if (!payload.exp || payload.exp < Math.floor(Date.now() / 1000)) return null;
  return payload;
}

function json(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { "Content-Type": "application/json; charset=UTF-8", "Cache-Control": "no-store" },
  });
}

function bucketFor(env, storage) {
  if (!/^[a-z0-9][a-z0-9_-]*$/.test(storage)) return null;
  return env[`R2_${storage.replace(/-/g, "_").toUpperCase()}`] || null;
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (url.pathname === "/sign" && request.method === "POST") {
      if (request.headers.get("X-Worker-Secret") !== env.WORKER_SECRET) {
        return json({ error: "Unauthorized" }, 401);
      }
      let body;
      try { body = await request.json(); } catch { return json({ error: "Invalid JSON" }, 400); }
      if (!body.media_id || !body.storage || !body.video || !bucketFor(env, body.storage)) {
        return json({ error: "Invalid media request" }, 422);
      }
      const ttl = Math.min(Math.max(Number(body.expires_in) || 300, 30), Number(env.TOKEN_TTL_SECONDS) || 900);
      const exp = Math.floor(Date.now() / 1000) + ttl;
      const common = { media_id: body.media_id, storage: body.storage, exp };
      const videoToken = await signedToken({ ...common, key: body.video, kind: "video" }, env.WORKER_SECRET);
      const response = {
        video_url: `${env.PUBLIC_URL}/media?token=${encodeURIComponent(videoToken)}`,
        expires_at: new Date(exp * 1000).toISOString(),
      };
      if (body.subtitle) {
        const subtitleToken = await signedToken({ ...common, key: body.subtitle, kind: "subtitle" }, env.WORKER_SECRET);
        response.subtitle_url = `${env.PUBLIC_URL}/media?token=${encodeURIComponent(subtitleToken)}`;
      }
      return json(response);
    }

    if (url.pathname === "/media" && request.method === "GET") {
      const payload = await verifyToken(url.searchParams.get("token") || "", env.WORKER_SECRET);
      if (!payload || !payload.key || !payload.storage) return json({ error: "Expired media URL" }, 401);
      const bucket = bucketFor(env, payload.storage);
      if (!bucket) return json({ error: "Unknown media storage" }, 404);
      const object = await bucket.get(payload.key);
      if (!object) return new Response("Media not found", { status: 404 });
      const headers = new Headers();
      object.writeHttpMetadata(headers);
      headers.set("Cache-Control", "private, max-age=60");
      headers.set("Content-Disposition", "inline");
      if (payload.kind === "subtitle") headers.set("Content-Type", "application/x-subrip; charset=UTF-8");
      return new Response(object.body, { headers });
    }

    return new Response("Not Found", { status: 404 });
  },
};