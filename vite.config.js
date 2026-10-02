import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";

// Path under the app URL where the app's nginx proxies the dev server (VITE_ORIGIN mode).
const APP_PROXY_BASE = "/__vite/";

export default defineConfig(({ command, mode }) => {
    const env = loadEnv(mode, process.cwd(), "");
    // Prefer process.env so Docker Compose can override without writing .env.
    // Set VITE_HOST to a browser-reachable hostname/IP when accessing the app
    // from another device (LAN / Tailscale), e.g. VITE_HOST=100.75.155.70
    const viteHost = (process.env.VITE_HOST || env.VITE_HOST || "localhost").trim();
    const viteHmrHost = (
        process.env.VITE_HMR_HOST ||
        env.VITE_HMR_HOST ||
        viteHost
    ).trim();
    const vitePort = Number(process.env.VITE_PORT || env.VITE_PORT || 5173);
    const viteProtocol = (
        process.env.VITE_PROTOCOL ||
        env.VITE_PROTOCOL ||
        "http"
    ).trim();
    // Set VITE_ORIGIN to the app URL (./scripts/dev demo does) to load assets and hot reload
    // through the app port at /__vite/, so a single forwarded port (SSH tunnel) is enough.
    const appOrigin = (process.env.VITE_ORIGIN || env.VITE_ORIGIN || "")
        .trim()
        .replace(/\/$/, "");
    const proxied = command === "serve" && appOrigin !== "";
    const appUrl = proxied ? new URL(appOrigin) : null;

    return {
        base: proxied ? APP_PROXY_BASE : undefined,
        server: {
            watch: {
                ignored: ["**/dev_*_data/**", "**/storage/**", "**/vendor/**"],
            },
            // Listen on all interfaces so Docker / remote clients can reach the dev server
            host: "0.0.0.0",
            port: vitePort,
            strictPort: true,
            allowedHosts: true,
            // App (:8000) and Vite (:5173) are different origins; allow any host in dev
            cors: true,
            origin: proxied ? appOrigin : `${viteProtocol}://${viteHost}:${vitePort}`,
            hmr: proxied
                ? {
                      host: appUrl.hostname,
                      clientPort: Number(appUrl.port || (appUrl.protocol === "https:" ? 443 : 80)),
                      protocol: appUrl.protocol === "https:" ? "wss" : "ws",
                  }
                : {
                      host: viteHmrHost,
                      clientPort: vitePort,
                      protocol: viteProtocol === "https" ? "wss" : "ws",
                  },
        },
        plugins: [
            laravel({
                input: [
                    "resources/css/app.css",
                    "resources/js/app.js",
                ],
                refresh: true,
            }),
        ],
    };
});
