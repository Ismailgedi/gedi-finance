import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [react()],
  server: {
    // Binds to 0.0.0.0 instead of just localhost, so a phone on the same
    // Wi-Fi network can reach the dev server at http://<this-PC's-LAN-IP>:5173.
    // API/session requests still go through the proxy below to the Laravel
    // backend on this same machine, so no CORS/Sanctum config changes are
    // needed - the phone's browser only ever talks to this Vite origin.
    host: true,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})