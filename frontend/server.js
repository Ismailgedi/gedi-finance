#!/usr/bin/env node
// Production server for the built SPA (frontend/dist).
//
// This does two jobs:
//   1. Serves the static build with an SPA fallback (unchanged from before).
//   2. Reverse-proxies /api/* and /sanctum/* to the actual Laravel backend,
//      forwarding the request through byte-for-byte (method, headers, body)
//      and relaying the response back the same way - including Set-Cookie.
//
// Why a proxy and not a separate API base URL: the frontend calls the API
// with plain relative paths ("/api/...", credentials: 'include') and relies
// on Laravel's session-cookie auth (see backend/app/Http/Controllers/
// AuthController.php - Auth::guard('web')->login(), not a bearer token).
// That only works if the browser sees the API as the same origin as the
// frontend. Proxying here achieves that without touching the frontend code
// or Laravel's CORS/cookie config for a specific cross-site case - this is
// the same approach the Vite dev proxy (vite.config.ts) and the Render
// static-site rewrites already use for the same reason.
//
// Dependency-free on purpose: only Node's built-in http/fs/path, no new
// package for what's ~130 lines of well-understood plumbing.
//
// Replit's Autoscale deployments assign the listen port via $PORT at
// runtime and require the process to bind 0.0.0.0 (not 127.0.0.1) - both
// are handled below. 5000 is only a local-dev fallback for running this
// without Replit.

import http from 'node:http'
import https from 'node:https'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

const PORT = Number(process.env.PORT) || 5000
const HOST = '0.0.0.0'
const DIST_DIR = path.join(__dirname, 'dist')

// Where the real Laravel backend lives. Defaults to the standard
// `php artisan serve` address for local testing; in every real deployment
// (Replit, Render, etc.) this must be set to the backend's actual public
// URL via the platform's environment/secret configuration - never hardcode
// a production backend URL here.
const API_PROXY_TARGET = process.env.API_PROXY_TARGET || 'http://127.0.0.1:8000'
const apiTarget = new URL(API_PROXY_TARGET)
const apiClient = apiTarget.protocol === 'https:' ? https : http

const MIME_TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.ico': 'image/x-icon',
  '.webmanifest': 'application/manifest+json',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.txt': 'text/plain; charset=utf-8',
}

function isApiRequest(url) {
  return url.startsWith('/api/') || url === '/api' || url.startsWith('/sanctum/')
}

function proxyToBackend(req, res) {
  const upstreamUrl = new URL(req.url, apiTarget)
  const headers = { ...req.headers }
  delete headers.host // let Node set the correct Host for the backend

  const upstreamReq = apiClient.request(
    upstreamUrl,
    { method: req.method, headers },
    (upstreamRes) => {
      res.writeHead(upstreamRes.statusCode || 502, upstreamRes.headers)
      upstreamRes.pipe(res)
    },
  )

  upstreamReq.on('error', (err) => {
    console.error(`API proxy error (${req.method} ${req.url}):`, err.message)
    if (!res.headersSent) {
      res.writeHead(502, { 'Content-Type': 'application/json; charset=utf-8' })
    }
    res.end(JSON.stringify({ message: 'Backend unavailable.' }))
  })

  req.pipe(upstreamReq)
}

// Keeps every resolved static-file path inside DIST_DIR, no matter what a
// request's URL contains (e.g. "/../../etc/passwd").
function resolveRequestPath(url) {
  const decoded = decodeURIComponent(url.split('?')[0])
  const safeSuffix = path
    .normalize(decoded)
    .split(path.sep)
    .filter((segment) => segment !== '' && segment !== '..')
    .join(path.sep)

  return path.join(DIST_DIR, safeSuffix)
}

function send(res, method, filePath, status = 200) {
  const ext = path.extname(filePath).toLowerCase()

  fs.stat(filePath, (err, stats) => {
    if (err || !stats.isFile()) {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' })
      return res.end('Not found')
    }

    res.writeHead(status, {
      'Content-Type': MIME_TYPES[ext] || 'application/octet-stream',
      'Content-Length': stats.size,
      'Cache-Control': ext === '.html' ? 'no-cache' : 'public, max-age=3600',
    })

    if (method === 'HEAD') return res.end()

    fs.createReadStream(filePath).pipe(res)
  })
}

const server = http.createServer((req, res) => {
  if (isApiRequest(req.url)) {
    return proxyToBackend(req, res)
  }

  if (req.method !== 'GET' && req.method !== 'HEAD') {
    res.writeHead(405, { Allow: 'GET, HEAD' })
    return res.end('Method Not Allowed')
  }

  const requestedPath = req.url === '/' ? '/index.html' : req.url
  const filePath = resolveRequestPath(requestedPath)
  const hasExtension = path.extname(filePath) !== ''

  fs.stat(filePath, (err, stats) => {
    if (!err && stats.isFile()) {
      return send(res, req.method, filePath)
    }

    if (hasExtension) {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' })
      return res.end('Not found')
    }

    // No file matches and the URL has no extension - it's a
    // react-router-dom (BrowserRouter) client route such as /reports.
    // Hand back the SPA shell so a hard refresh still resolves instead
    // of 404ing at the server.
    send(res, req.method, path.join(DIST_DIR, 'index.html'))
  })
})

server.listen(PORT, HOST, () => {
  console.log(`Gedi Finance frontend listening on http://${HOST}:${PORT}`)
  console.log(`Proxying /api and /sanctum to ${API_PROXY_TARGET}`)
})
