const fs = require('fs');
const path = require('path');

const replacements = [
  { match: /(?<!dark:)bg-zinc-950/g, replace: 'bg-gray-50 dark:bg-zinc-950' },
  { match: /(?<!dark:)bg-zinc-900\/50/g, replace: 'bg-white dark:bg-zinc-900/50' },
  { match: /(?<!dark:)bg-zinc-900(?!\/)/g, replace: 'bg-white dark:bg-zinc-900' },
  { match: /(?<!dark:)bg-\\[#12121e\\]\\/60/g, replace: 'bg-white/80 dark:bg-[#12121e]/60' },
  { match: /(?<!dark:)text-zinc-100/g, replace: 'text-zinc-900 dark:text-zinc-100' },
  { match: /(?<!dark:)text-zinc-200/g, replace: 'text-zinc-800 dark:text-zinc-200' },
  { match: /(?<!dark:)text-zinc-300/g, replace: 'text-zinc-700 dark:text-zinc-300' },
  { match: /(?<!dark:)text-zinc-400/g, replace: 'text-zinc-500 dark:text-zinc-400' },
  { match: /(?<!dark:)text-zinc-500/g, replace: 'text-zinc-400 dark:text-zinc-500' },
  { match: /(?<!dark:)border-zinc-800/g, replace: 'border-zinc-200 dark:border-zinc-800' },
  { match: /(?<!dark:)border-white\/5(?!\d)/g, replace: 'border-zinc-200 dark:border-white/5' },
  { match: /(?<!dark:)border-white\/10/g, replace: 'border-zinc-300 dark:border-white/10' },
  { match: /(?<!dark:)bg-zinc-800\/50/g, replace: 'bg-zinc-100 dark:bg-zinc-800/50' },
  { match: /(?<!dark:)bg-zinc-800(?!\/)/g, replace: 'bg-zinc-100 dark:bg-zinc-800' },
  { match: /(?<!dark:)border-\\[#1a1a2e\\]/g, replace: 'border-zinc-200 dark:border-[#1a1a2e]' },
  { match: /(?<!dark:)bg-white\/5(?!\d)/g, replace: 'bg-zinc-100 dark:bg-white/5' },
  { match: /(?<!dark:)bg-white\/10/g, replace: 'bg-zinc-200 dark:bg-white/10' },
];

function processDirectory(dir) {
  const files = fs.readdirSync(dir);
  for (const file of files) {
    const fullPath = path.join(dir, file);
    if (fs.statSync(fullPath).isDirectory()) {
      processDirectory(fullPath);
    } else if (fullPath.endsWith('.tsx') || fullPath.endsWith('.ts')) {
      let content = fs.readFileSync(fullPath, 'utf8');
      let modified = false;
      
      for (const rule of replacements) {
        if (content.match(rule.match)) {
          content = content.replace(rule.match, rule.replace);
          modified = true;
        }
      }
      
      if (modified) {
        fs.writeFileSync(fullPath, content, 'utf8');
        console.log(`Updated: ${fullPath}`);
      }
    }
  }
}

processDirectory(path.join(__dirname, 'app'));
processDirectory(path.join(__dirname, 'components'));
console.log('Done!');
