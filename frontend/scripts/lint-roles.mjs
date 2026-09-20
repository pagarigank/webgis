import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const SRC_DIR = path.join(__dirname, '../src');

// List of roles that are strictly forbidden as raw strings in the UI.
// This prevents developers from accidentally hardcoding roles like: if (user.role === 'SYS_ADMIN')
const FORBIDDEN_ROLES = [
  'SYS_ADMIN',
  'GIS_EDITOR',
  'GIS_VIEWER',
  'LGU_ASSESSOR',
  'LGU_CLERK',
  'SURVEYOR',
  'PUBLIC_USER',
  'ROLE_ME_TEST'
];

let hasError = false;

function scanDirectory(dir) {
  const files = fs.readdirSync(dir);
  
  for (const file of files) {
    const fullPath = path.join(dir, file);
    const stat = fs.statSync(fullPath);
    
    if (stat.isDirectory()) {
      scanDirectory(fullPath);
    } else if (fullPath.endsWith('.ts') || fullPath.endsWith('.tsx')) {
      // Exclude test files where mock roles might be used safely
      if (fullPath.endsWith('.test.ts') || fullPath.endsWith('.test.tsx')) {
        continue;
      }
      
      const content = fs.readFileSync(fullPath, 'utf8');
      
      for (const role of FORBIDDEN_ROLES) {
        // Regex matches the role string enclosed in single or double quotes
        const regex = new RegExp(`['"]${role}['"]`, 'g');
        let match;
        
        while ((match = regex.exec(content)) !== null) {
          // Calculate line number
          const lineNumber = content.substring(0, match.index).split('\n').length;
          console.error(`❌ [Lint Error] Raw role string found: ${match[0]} at ${fullPath}:${lineNumber}`);
          console.error(`   Fix: UI components must depend on Capabilities/Permissions, never on literal Roles.`);
          hasError = true;
        }
      }
    }
  }
}

console.log('Scanning for raw role strings...');
scanDirectory(SRC_DIR);

if (hasError) {
  process.exit(1);
} else {
  console.log('✅ No raw role strings found. Lint passed.');
  process.exit(0);
}
