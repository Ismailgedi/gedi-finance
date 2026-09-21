#!/usr/bin/env node
// Production static server for the built SPA (frontend/dist).
//
// Dependency-free on purpose: this is a production candidate, and the
// only job here is "serve some static files with an SPA fallback", so a
// ~90-line file using only Node's built-in http/fs/path is easier to
// audit than pulling in a package for it.
//
// Replit's Autoscale deployments assign the listen port via $PORT at
// runtime and require the process to bind 0.0.0.0 (not 127.0.0.1) - both
// are handled below. 5000 is only a local-dev fallback for running this
// without Replit.

import http from 'node:http'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

const PORT = Number(process.env.PORT) || 5000
const HOST = '0.0.0.0'
const DIST_DIR = path.join(__dirname, 'dist')

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

// Keeps every resolved path inside DIST_DIR, no matter what a request's
// URL contains (e.g. "/../../etc/passwd").
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
      // index.html must always be revalidated so a new deploy is picked
      // up; Vite's other build output is content-hashed in its filename,
      // so it's safe to cache, but the brand assets under /brand aren't
      // hashed - keep this modest rather than "immutable".
      'Cache-Control': ext === '.html' ? 'no-cache' : 'public, max-age=3600',
    })

    if (method === 'HEAD') return res.end()

    fs.createReadStream(filePath).pipe(res)
  })
}

const server = http.createServer((req, res) => {
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
})
