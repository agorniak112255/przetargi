import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

/**
 * Panel stoi raz pod „/” (serwer), raz pod „/Przetargi/” (XAMPP), więc zbudowane
 * ścieżki zasobów są względne, a prefiks dokładał skrypt w nagłówku strony.
 * Przeglądarka jednak zaczyna pobierać pliki z tagów, zanim ten skrypt się wykona:
 * pod adresem /inquiries/25 pytała o /inquiries/assets/… i dostawała stamtąd stronę
 * aplikacji, czyli HTML zamiast skryptu i arkusza („niedozwolony typ MIME”). Zasoby
 * wczytywały się dopiero za drugim razem, a konsola przy każdym wejściu pokazywała
 * dwa błędy.
 *
 * Dlatego tagi zasobów wstawiamy z końca strony, już z wyliczonym prefiksem —
 * skaner wstępny nie ma czego wystrzelić w złą stronę, a #root w tym miejscu
 * na pewno istnieje.
 */
function assetsWithPrefix(): Plugin {
  const script = /<script\b[^>]*\bsrc="\.\/([^"]+)"[^>]*><\/script>/g
  const stylesheet = /<link\b[^>]*\brel="stylesheet"[^>]*\bhref="\.\/([^"]+)"[^>]*>/g
  const icon = /<link\b[^>]*\brel="icon"[^>]*\bhref="\.\/([^"]+)"[^>]*>/g

  return {
    name: 'supon-assets-with-prefix',
    enforce: 'post',
    apply: 'build',
    transformIndexHtml(html) {
      const take = (pattern: RegExp): string[] =>
        [...html.matchAll(pattern)].map((match) => match[1])

      const scripts = take(script)
      const styles = take(stylesheet)
      const icons = take(icon)
      if (scripts.length === 0 && styles.length === 0) {
        return html
      }

      const loader = [
        '    <script>',
        '      (function () {',
        '        var p = location.pathname',
        "        var base = p === '/Przetargi' || p.indexOf('/Przetargi/') === 0 ? '/Przetargi/' : '/'",
        `        var styles = ${JSON.stringify(styles)}`,
        `        var icons = ${JSON.stringify(icons)}`,
        `        var scripts = ${JSON.stringify(scripts)}`,
        '        function head(tag, attrs) {',
        '          var el = document.createElement(tag)',
        '          for (var key in attrs) el.setAttribute(key, attrs[key])',
        '          document.head.appendChild(el)',
        '        }',
        '        for (var i = 0; i < styles.length; i++) {',
        "          head('link', { rel: 'stylesheet', crossorigin: '', href: base + styles[i] })",
        '        }',
        '        for (var j = 0; j < icons.length; j++) {',
        "          head('link', { rel: 'icon', type: 'image/svg+xml', href: base + icons[j] })",
        '        }',
        '        for (var k = 0; k < scripts.length; k++) {',
        "          var s = document.createElement('script')",
        "          s.type = 'module'",
        "          s.crossOrigin = ''",
        '          s.src = base + scripts[k]',
        '          document.body.appendChild(s)',
        '        }',
        '      })()',
        '    </script>',
      ].join('\n')

      return html
        .replace(script, '')
        .replace(stylesheet, '')
        .replace(icon, '')
        .replace('</body>', loader + '\n  </body>')
    },
  }
}

export default defineConfig({
  plugins: [react(), tailwindcss(), assetsWithPrefix()],
  base: './',
  build: {
    outDir: '../backend/public',
    emptyOutDir: false,
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
