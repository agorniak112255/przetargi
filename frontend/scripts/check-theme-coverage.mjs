/*
 * Sprawdza, czy wspólny tryb ciemny (src/themes/dark-scheme.css) obsługuje wszystkie
 * klasy kolorów użyte w komponentach.
 *
 * Po co: tryb ciemny przemapowuje zmienne palety Tailwinda, ale części klas nie da się
 * obsłużyć zmienną (ta sama zmienna jest tłem i tekstem). Takie klasy — ciemny tekst
 * text-{barwa|slate}-600…950, jasny tekst 50…300, bg-white(/NN), hover:bg-white,
 * via-white — muszą mieć własny selektor w dark-scheme.css. Bez niego po dodaniu
 * np. `text-teal-700 /80` w nowym widoku tekst w trybie ciemnym byłby nieczytelny.
 *
 * Skrypt skanuje src/**\/*.{ts,tsx}, wypisuje brakujące selektory i kończy się kodem 1.
 * Klas budowanych dynamicznie (`text-${kolor}-700`) nie wykryje — nie składaj nazw klas.
 *
 * Uruchomienie: npm run check:themes
 */
import { readdirSync, readFileSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const srcDir = join(root, "src");
const cssPath = join(srcDir, "themes", "dark-scheme.css");

const HUES = "slate|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose|gray|zinc|neutral|stone";
const TEXT_SHADES = new Set(["50", "100", "200", "300", "600", "700", "800", "900", "950"]);
const LOW_SPECIFICITY = /^(group-hover|peer-hover|group-focus|peer-focus|sm|md|lg|xl|2xl)$/;

// Wariant (hover:, sm:, …) + klasa koloru, która może wymagać nadpisania.
const CLASS_RE = new RegExp(
  String.raw`(?<![\w\-/.:])((?:[a-z0-9-]+:)*)(text-(?:${HUES})-(\d{2,3})(?:\/\d+)?|bg-white(?:\/\d+)?|via-white)(?![\w\-/])`,
  "g",
);

function walk(dir) {
  const files = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) files.push(...walk(full));
    else if (/\.(ts|tsx)$/.test(entry.name)) files.push(full);
  }
  return files;
}

const css = readFileSync(cssPath, "utf8").replace(/\/\*[\s\S]*?\*\//g, "");

function hasSelector(selector) {
  let from = 0;
  for (;;) {
    const i = css.indexOf(selector, from);
    if (i < 0) return false;
    const next = css[i + selector.length] ?? "";
    if (!/[\w\-\\]/.test(next)) return true;
    from = i + 1;
  }
}

const escapeClass = (cls) => cls.replace(/[:/]/g, (c) => "\\" + c);

const missing = new Map(); // selektor -> Set(plików)
const lowSpecificity = new Map(); // klasa -> Set(plików)
const add = (map, key, file) => {
  if (!map.has(key)) map.set(key, new Set());
  map.get(key).add(file);
};

for (const file of walk(srcDir)) {
  const rel = relative(root, file).replace(/\\/g, "/");
  const text = readFileSync(file, "utf8");
  for (const m of text.matchAll(CLASS_RE)) {
    const variants = m[1] ? m[1].slice(0, -1).split(":") : [];
    const utility = m[2];
    const isText = utility.startsWith("text-");
    if (isText && !TEXT_SHADES.has(m[3])) continue; // 400/500 obsługuje sama zmienna

    if (variants.length === 0) {
      const selector = "." + escapeClass(utility);
      if (!hasSelector(selector)) add(missing, selector, rel);
      continue;
    }

    if (variants.length === 1 && variants[0] === "hover") {
      if (!isText && utility !== "bg-white") continue; // hover:bg-white/NN — półprzezroczysty rozbłysk, zostaje
      const selector = ".hover\\:" + escapeClass(utility) + ":hover";
      if (!hasSelector(selector)) add(missing, selector, rel);
      continue;
    }

    if (isText && variants.some((v) => LOW_SPECIFICITY.test(v))) {
      add(lowSpecificity, m[1] + utility, rel);
      continue;
    }

    // Inne warianty (focus:, disabled:, …) — selektor z pseudoklasą trzeba dopisać ręcznie.
    const selector = "." + variants.map((v) => v + "\\:").join("") + escapeClass(utility);
    if (!hasSelector(selector)) add(missing, selector, rel);
  }
}

let failed = false;
const list = (map) =>
  [...map.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([key, files]) => `  ${key}   (${[...files].join(", ")})`)
    .join("\n");

if (missing.size > 0) {
  failed = true;
  console.error(`Brak nadpisań w src/themes/dark-scheme.css dla ${missing.size} selektorów:\n${list(missing)}`);
  console.error(
    "\nDopisz je w dark-scheme.css (w @layer utilities, prefiks :where(:root[data-scheme=\"dark\"]); " +
      "warianty hover: w bloku @media (hover: hover)).",
  );
}

if (lowSpecificity.size > 0) {
  failed = true;
  console.error(`\nKolory tekstu z wariantem responsywnym lub group-/peer- (${lowSpecificity.size}):\n${list(lowSpecificity)}`);
  console.error(
    "\nTailwind generuje je jako @media (...) { .sm\\:text-… } albo .group:hover .group-hover\\:text-…, " +
      "a zwykłe nadpisanie trybu ciemnego tego nie obejmie: w ciemnym motywie zostanie ciemny tekst " +
      "na ciemnym tle albo nadpisanie bazowej klasy przykryje wariant. Użyj koloru bez wariantu " +
      "(np. hover: na tym samym elemencie) albo dopisz w dark-scheme.css regułę z tym samym @media/selektorem " +
      "i rozszerz ten skrypt.",
  );
}

if (failed) process.exit(1);
console.log("check:themes OK — dark-scheme.css obsługuje wszystkie klasy kolorów z src/.");
