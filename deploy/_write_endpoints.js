const fs = require('fs');
const path = require('path');

const SRC = "C:\\Users\\Lucia\\AppData\\Local\\Temp\\claude\\C--Users-Lucia\\cfc3ca08-0c1e-40f4-b132-ee191c9dd827\\tasks\\w194q3ssu.output";
const API = "C:\\Users\\Lucia\\Documents\\Panel-Minutos-App\\public\\api";

function decodeIfNeeded(s) {
  if (s.startsWith('&lt;?php') || s.includes('&lt;?php')) {
    return s.replace(/&lt;/g, '<').replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&amp;/g, '&');
  }
  return s;
}

const data = JSON.parse(fs.readFileSync(SRC, 'utf8'));
const items = data.result || [];
console.log("endpoints: " + items.length);
for (const it of items) {
  if (!it || !it.file || !it.code) { console.log("  SKIP (vacío)"); continue; }
  let code = decodeIfNeeded(it.code);
  const dest = path.join(API, it.file);
  fs.writeFileSync(dest, code, 'utf8');
  const firstLine = code.split('\n')[0];
  console.log("  WROTE " + it.file + "  (" + code.length + " ch)  ->  " + firstLine.slice(0, 40));
}
